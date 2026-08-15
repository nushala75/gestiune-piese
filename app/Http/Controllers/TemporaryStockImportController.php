<?php

namespace App\Http\Controllers;

use App\Models\Gestiune;
use App\Models\Produs;
use App\Services\NecesarAprovizionareService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class TemporaryStockImportController extends Controller
{
    private const SESSION_KEY = 'temporary_stock_import_preview';

    private const LOCK_PATH = 'importuri/actualizare-stoc-unica.finalizat';

    /** @var array<string, int> */
    private const FORCED_STOCKS = [
        '91202-KNBN-92A' => 1,
        '91201-KNBN-92A' => 1,
    ];

    public function show(Request $request): View
    {
        return view('stoc-import-temporar', [
            'finalizat' => $this->isCompleted(),
            'preview' => $request->session()->get(self::SESSION_KEY),
        ]);
    }

    public function preview(Request $request): RedirectResponse
    {
        if ($this->isCompleted()) {
            return back()->withErrors(['stoc_csv' => 'Importul unic de stoc a fost deja finalizat și este blocat.']);
        }

        $request->validate([
            'stoc_csv' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        try {
            $parsed = $this->parseCsv($request->file('stoc_csv')->getRealPath());
            $analysis = $this->analyse($parsed);
        } catch (Throwable $exception) {
            return back()->withErrors(['stoc_csv' => $exception->getMessage()]);
        }

        $request->session()->put(self::SESSION_KEY, [
            'stocks' => $parsed,
            'rows' => count($parsed),
            'matched_codes' => $analysis['matched_codes'],
            'unmatched_codes' => $analysis['unmatched_codes'],
            'positive_codes' => $analysis['positive_codes'],
            'zero_codes' => $analysis['zero_codes'],
            'ambiguous_codes' => $analysis['ambiguous_codes'],
        ]);

        return redirect()->route('stoc-import-temporar.show');
    }

    public function apply(Request $request, NecesarAprovizionareService $necesarAprovizionare): RedirectResponse
    {
        if ($this->isCompleted()) {
            return back()->withErrors(['stoc_csv' => 'Importul unic de stoc a fost deja finalizat și este blocat.']);
        }

        $request->validate([
            'confirmare' => ['accepted'],
        ], [
            'confirmare.accepted' => 'Confirmă explicit înlocuirea stocurilor înainte de aplicare.',
        ]);

        $preview = $request->session()->get(self::SESSION_KEY);
        if (! is_array($preview) || ! isset($preview['stocks']) || ! is_array($preview['stocks'])) {
            return back()->withErrors(['stoc_csv' => 'Previzualizarea nu mai este disponibilă. Încarcă din nou fișierul.']);
        }

        if (($preview['ambiguous_codes'] ?? []) !== []) {
            return back()->withErrors([
                'stoc_csv' => 'Importul nu poate fi aplicat: există coduri cu stoc pozitiv care corespund mai multor produse în catalog.',
            ]);
        }

        /** @var array<string, int> $stocks */
        $stocks = $preview['stocks'];

        try {
            $result = DB::transaction(function () use ($stocks, $necesarAprovizionare): array {
                $gestiune = Gestiune::query()
                    ->where('cod', 'FIRMA')
                    ->whereHas('firma', fn (Builder $query) => $query->where('cod_fiscal', 'RO20548513'))
                    ->sole();

                $products = Produs::query()->orderBy('id')->get(['id', 'cod_produs']);
                $productIdsByCode = $products->groupBy(fn (Produs $produs) => mb_strtoupper(trim($produs->cod_produs)));

                $rows = [];
                $updatedPositive = 0;
                foreach ($products as $product) {
                    $code = mb_strtoupper(trim($product->cod_produs));
                    $stock = max(0, (int) ($stocks[$code] ?? 0));

                    if ($stock > 0 && ($productIdsByCode->get($code)?->count() ?? 0) > 1) {
                        throw new RuntimeException("Codul {$code} este ambiguu în catalog și are stoc pozitiv.");
                    }

                    if ($stock > 0) {
                        $updatedPositive++;
                    }

                    $rows[] = [
                        'gestiune_id' => $gestiune->id,
                        'produs_id' => $product->id,
                        'cantitate_fizica' => $stock,
                    ];
                }

                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('solduri_stoc')->upsert(
                        $chunk,
                        ['gestiune_id', 'produs_id'],
                        ['cantitate_fizica'],
                    );
                }

                Produs::query()->orderBy('id')->chunkById(250, function ($chunk) use ($necesarAprovizionare, $gestiune): void {
                    foreach ($chunk as $produs) {
                        $necesarAprovizionare->sincronizeaza($produs, $gestiune);
                    }
                });

                return [
                    'products_total' => $products->count(),
                    'products_positive' => $updatedPositive,
                    'products_zero' => $products->count() - $updatedPositive,
                ];
            });
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['stoc_csv' => 'Stocurile nu au fost modificate: '.$exception->getMessage()]);
        }

        Storage::disk('local')->put(
            self::LOCK_PATH,
            json_encode([
                'completed_at' => now()->toIso8601String(),
                'result' => $result,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );

        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('stoc-import-temporar.show')->with('status', sprintf(
            'Actualizarea stocului a fost finalizată: %d produse, %d cu stoc pozitiv, %d cu stoc 0. Importul a fost blocat pentru reutilizare.',
            $result['products_total'],
            $result['products_positive'],
            $result['products_zero'],
        ));
    }

    /** @return array<string, int> */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Fișierul CSV nu a putut fi deschis.');
        }

        try {
            $header = fgetcsv($handle);
            if ($header === false || count($header) < 2) {
                throw new RuntimeException('CSV-ul trebuie să conțină minimum două coloane.');
            }

            $firstHeader = mb_strtolower(trim((string) $header[0]));
            $secondHeader = mb_strtolower(trim((string) $header[1]));
            if ($firstHeader !== 'cod' || $secondHeader !== 'quantity') {
                throw new RuntimeException('Primele două coloane trebuie să fie exact Cod și Quantity.');
            }

            $stocks = [];
            $line = 1;
            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                if ($row === [] || count($row) < 2) {
                    continue;
                }

                $code = mb_strtoupper(trim((string) $row[0]));
                $quantityRaw = trim((string) $row[1]);
                if ($code === '' && $quantityRaw === '') {
                    continue;
                }
                if ($code === '') {
                    throw new RuntimeException("Linia {$line}: codul produsului lipsește.");
                }
                if (preg_match('/^-?\d+$/', $quantityRaw) !== 1) {
                    throw new RuntimeException("Linia {$line}: stocul pentru {$code} nu este un număr întreg valid.");
                }

                $quantity = max(0, (int) $quantityRaw);
                if (array_key_exists($code, $stocks) && $stocks[$code] !== $quantity && ! array_key_exists($code, self::FORCED_STOCKS)) {
                    throw new RuntimeException("Codul {$code} apare de mai multe ori în CSV cu stocuri diferite.");
                }

                $stocks[$code] = $quantity;
            }
        } finally {
            fclose($handle);
        }

        foreach (self::FORCED_STOCKS as $code => $quantity) {
            $stocks[$code] = $quantity;
        }

        if ($stocks === []) {
            throw new RuntimeException('CSV-ul nu conține nicio poziție de stoc.');
        }

        return $stocks;
    }

    /**
     * @param array<string, int> $stocks
     * @return array{matched_codes:int, unmatched_codes:array<int,string>, positive_codes:int, zero_codes:int, ambiguous_codes:array<int,string>}
     */
    private function analyse(array $stocks): array
    {
        $catalog = Produs::query()
            ->select(['cod_produs'])
            ->get()
            ->groupBy(fn (Produs $produs) => mb_strtoupper(trim($produs->cod_produs)));

        $unmatched = [];
        $ambiguous = [];
        $matched = 0;
        $positive = 0;
        $zero = 0;

        foreach ($stocks as $code => $quantity) {
            $matches = $catalog->get($code);
            if ($matches === null || $matches->isEmpty()) {
                $unmatched[] = $code;
            } else {
                $matched++;
                if ($quantity > 0 && $matches->count() > 1) {
                    $ambiguous[] = $code;
                }
            }

            if ($quantity > 0) {
                $positive++;
            } else {
                $zero++;
            }
        }

        sort($unmatched);
        sort($ambiguous);

        return [
            'matched_codes' => $matched,
            'unmatched_codes' => $unmatched,
            'positive_codes' => $positive,
            'zero_codes' => $zero,
            'ambiguous_codes' => $ambiguous,
        ];
    }

    private function isCompleted(): bool
    {
        return Storage::disk('local')->exists(self::LOCK_PATH);
    }
}
