<?php

namespace App\Http\Controllers;

use App\Models\Gestiune;
use App\Models\JurnalAudit;
use App\Models\MiscareStoc;
use App\Models\Produs;
use App\Services\BnrExchangeRateService;
use App\Services\NecesarAprovizionareService;
use App\Services\StockRegisterXlsxParser;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class StockUpdateController extends Controller
{
    private const SESSION_KEY = 'stock_update_import_preview';

    public function index(): View
    {
        return view('stock-update.index');
    }

    public function prepare(Request $request, StockRegisterXlsxParser $parser, BnrExchangeRateService $bnr): RedirectResponse
    {
        $this->clearDraft($request);
        $sourcePath = (string) config('stock-register.path');
        if (! is_file($sourcePath)) {
            return back()->withErrors([
                'registru' => 'Fișierul prestabilit nu există: '.$sourcePath,
            ]);
        }

        return $this->preparePreview($request, $parser, $bnr, $sourcePath, basename($sourcePath));
    }

    public function upload(Request $request, StockRegisterXlsxParser $parser, BnrExchangeRateService $bnr): RedirectResponse
    {
        $request->validate([
            'registru' => ['required', 'file', 'extensions:xlsx', 'max:20480'],
        ]);
        $this->clearDraft($request);

        $file = $request->file('registru');
        $token = (string) Str::uuid();
        $temporaryPath = "importuri/stoc/temporare/{$token}.xlsx";
        if (! Storage::disk('local')->putFileAs('importuri/stoc/temporare', $file, "{$token}.xlsx")) {
            return back()->withErrors(['registru' => 'Fișierul selectat nu a putut fi copiat pentru verificare.']);
        }

        return $this->preparePreview(
            $request,
            $parser,
            $bnr,
            Storage::disk('local')->path($temporaryPath),
            $file->getClientOriginalName(),
            $temporaryPath,
        );
    }

    private function preparePreview(
        Request $request,
        StockRegisterXlsxParser $parser,
        BnrExchangeRateService $bnr,
        string $sourcePath,
        string $originalName,
        ?string $temporaryPath = null,
    ): RedirectResponse {
        $hash = hash_file('sha256', $sourcePath);
        if ($hash === false) {
            if ($temporaryPath !== null) {
                Storage::disk('local')->delete($temporaryPath);
            }

            return back()->withErrors(['registru' => 'Amprenta fișierului nu a putut fi calculată.']);
        }

        try {
            $parsed = $parser->parse($sourcePath);
            $exchangeRate = $bnr->latestEuroRate();
            $preview = $this->buildPreview($parsed['rows'], $this->companyWarehouse(), $exchangeRate['value']);
        } catch (Throwable $exception) {
            if ($temporaryPath !== null) {
                Storage::disk('local')->delete($temporaryPath);
            }

            return back()->withErrors(['registru' => $exception->getMessage()]);
        }

        if ($preview['summary']['matched'] === 0) {
            if ($temporaryPath !== null) {
                Storage::disk('local')->delete($temporaryPath);
            }

            return back()->withErrors(['registru' => 'Niciun cod din registru nu există în catalogul local.']);
        }

        $request->session()->put(self::SESSION_KEY, [
            'token' => (string) Str::uuid(),
            'source_path' => $sourcePath,
            'temporary_path' => $temporaryPath,
            'original_name' => $originalName,
            'hash' => $hash,
            'sheet' => $parsed['sheet'],
            'exchange_rate' => $exchangeRate,
            'preview' => $preview,
        ]);

        return redirect()->route('stock-update.preview');
    }

    public function preview(Request $request): View|RedirectResponse
    {
        $draft = $request->session()->get(self::SESSION_KEY);
        if (! is_array($draft)) {
            return redirect()->route('stock-update.index')
                ->withErrors(['registru' => 'Previzualizarea a expirat. Încarcă din nou registrul.']);
        }

        return view('stock-update.preview', compact('draft'));
    }

    public function apply(Request $request, StockRegisterXlsxParser $parser, NecesarAprovizionareService $reorder): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'uuid'],
            'confirmare' => ['accepted'],
        ], [
            'confirmare.accepted' => 'Bifează confirmarea înainte de aplicarea stocului.',
        ]);
        $draft = $request->session()->get(self::SESSION_KEY);
        if (! is_array($draft) || ! hash_equals((string) ($draft['token'] ?? ''), $validated['token'])) {
            return redirect()->route('stock-update.index')
                ->withErrors(['registru' => 'Previzualizarea a expirat. Încarcă din nou registrul.']);
        }

        $sourcePath = (string) ($draft['source_path'] ?? '');
        if (! is_file($sourcePath)) {
            return redirect()->route('stock-update.index')
                ->withErrors(['registru' => 'Fișierul prestabilit nu mai există. Verifică registrul și încearcă din nou.']);
        }
        $currentHash = hash_file('sha256', $sourcePath);
        if ($currentHash === false || ! hash_equals((string) $draft['hash'], $currentHash)) {
            throw new RuntimeException('Fișierul prestabilit a fost modificat după generarea previzualizării.');
        }

        try {
            $parsed = $parser->parse($sourcePath);
            $warehouse = $this->companyWarehouse();
            $exchangeRate = (string) ($draft['exchange_rate']['value'] ?? '');
            if (! preg_match('/^\d+(?:\.\d+)?$/', $exchangeRate)) {
                throw new RuntimeException('Cursul BNR salvat în previzualizare nu este valid.');
            }
            $freshPreview = $this->buildPreview($parsed['rows'], $warehouse, $exchangeRate);
            if ($freshPreview['summary']['ambiguous'] > 0) {
                return back()->withErrors(['registru' => 'Aplicarea a fost oprită: există coduri care corespund mai multor produse.']);
            }

            $archivePath = 'importuri/stoc/'.date('Y').'/'.$draft['hash'].'.xlsx';
            $archiveCreated = false;
            if (! Storage::disk('local')->exists($archivePath)) {
                $contents = file_get_contents($sourcePath);
                if ($contents === false || ! Storage::disk('local')->put($archivePath, $contents)) {
                    throw new RuntimeException('Registrul nu a putut fi arhivat înainte de actualizare.');
                }
                $archiveCreated = true;
            }

            try {
                $result = DB::transaction(function () use ($draft, $exchangeRate, $parsed, $reorder, $warehouse): array {
                    $preview = $this->buildPreview($parsed['rows'], $warehouse, $exchangeRate, true);
                    if ($preview['summary']['ambiguous'] > 0) {
                        throw new RuntimeException('Există coduri care corespund mai multor produse. Stocul nu a fost modificat.');
                    }

                    $stockUpdated = 0;
                    $priceUpdated = 0;
                    foreach ($preview['changed'] as $line) {
                        if ($line['stock_changed']) {
                            DB::table('solduri_stoc')->updateOrInsert(
                                ['gestiune_id' => $warehouse->id, 'produs_id' => $line['product_id']],
                                [
                                    'cantitate_fizica' => $line['new_stock'],
                                    'updated_at' => now(),
                                ],
                            );
                            $stockUpdated++;
                        }
                        if ($line['stock_changed'] && Schema::hasTable('miscari_stoc') && $line['delta'] !== 0) {
                            MiscareStoc::query()->create([
                                'gestiune_id' => $warehouse->id,
                                'produs_id' => $line['product_id'],
                                'tip' => 'ajustare_inventar',
                                'cantitate' => $line['delta'],
                                'cost_unitar' => null,
                                'receptie_linie_id' => null,
                                'referinta_tip' => 'import_stoc_xlsx',
                                'referinta_id' => null,
                                'explicatie' => 'Actualizare din registrul '.$draft['original_name'],
                            ]);
                        }
                        $product = Produs::query()->findOrFail($line['product_id']);
                        if ($line['stock_changed']) {
                            $reorder->sincronizeaza($product, $warehouse);
                        }
                        if ($line['price_changed']) {
                            $netPrice = BigDecimal::of($line['new_price'])
                                ->dividedBy('1.21', 4, RoundingMode::HalfUp)
                                ->__toString();
                            $product->update([
                                'pret_vanzare_fara_tva' => $netPrice,
                                'pret_vanzare_cu_tva' => $line['new_price'],
                            ]);
                            $priceUpdated++;
                        }
                    }

                    if (Schema::hasTable('jurnal_audit')) {
                        JurnalAudit::query()->create([
                            'actor_tip' => 'user',
                            'actor_id' => request()->user()?->id,
                            'actiune' => 'actualizare_stoc_xlsx',
                            'entitate_tip' => 'gestiune',
                            'entitate_id' => $warehouse->id,
                            'date_inainte' => ['fisier' => $draft['original_name'], 'hash_sha256' => $draft['hash']],
                            'date_dupa' => $preview['summary'] + [
                                'stoc_aplicat' => $stockUpdated,
                                'pret_aplicat' => $priceUpdated,
                                'curs_bnr_eur' => $exchangeRate,
                                'data_curs_bnr' => $draft['exchange_rate']['published_on'] ?? null,
                            ],
                        ]);
                    }

                    return $preview['summary'] + ['stock_applied' => $stockUpdated, 'price_applied' => $priceUpdated];
                });
            } catch (Throwable $exception) {
                if ($archiveCreated) {
                    Storage::disk('local')->delete($archivePath);
                }
                throw $exception;
            }
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['registru' => 'Stocul nu a fost actualizat: '.$exception->getMessage()]);
        }

        $request->session()->forget(self::SESSION_KEY);
        if (! empty($draft['temporary_path'])) {
            Storage::disk('local')->delete((string) $draft['temporary_path']);
        }

        return redirect()->route('stock-update.index')->with(
            'status',
            "Actualizarea s-a încheiat: stoc modificat la {$result['stock_applied']} produse, preț final modificat la {$result['price_applied']} produse, {$result['missing']} coduri inexistente ignorate.",
        );
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->clearDraft($request);

        return redirect()->route('stock-update.index');
    }

    private function clearDraft(Request $request): void
    {
        $draft = $request->session()->pull(self::SESSION_KEY);
        if (is_array($draft) && ! empty($draft['temporary_path'])) {
            Storage::disk('local')->delete((string) $draft['temporary_path']);
        }
    }

    private function companyWarehouse(): Gestiune
    {
        return Gestiune::query()
            ->where('cod', 'FIRMA')
            ->whereHas('firma', fn (Builder $query) => $query->where('cod_fiscal', 'RO20548513'))
            ->sole();
    }

    /**
     * @param  list<array{row: int, code: string, stock: int, price_with_vat: string}>  $rows
     * @return array{summary: array<string, int>, changed: array, unchanged: array, missing: array, ambiguous: array}
     */
    private function buildPreview(array $rows, Gestiune $warehouse, string $exchangeRate, bool $lock = false): array
    {
        $codes = collect($rows)->pluck('code')->unique()->values();
        $productQuery = Produs::query()->whereIn('cod_produs', $codes);
        if ($lock) {
            $productQuery->lockForUpdate();
        }
        $products = $productQuery->get()->groupBy(fn (Produs $product): string => mb_strtoupper(trim($product->cod_produs)));
        $productIds = $products->flatten()->pluck('id');
        $stockQuery = DB::table('solduri_stoc')
            ->where('gestiune_id', $warehouse->id)
            ->whereIn('produs_id', $productIds);
        if ($lock) {
            $stockQuery->lockForUpdate();
        }
        $stocks = $stockQuery->pluck('cantitate_fizica', 'produs_id');

        $result = ['changed' => [], 'unchanged' => [], 'missing' => [], 'ambiguous' => []];
        foreach ($rows as $row) {
            $matches = $products->get($row['code'], collect());
            if ($matches->isEmpty()) {
                $result['missing'][] = $row;

                continue;
            }
            if ($matches->count() !== 1) {
                $result['ambiguous'][] = $row + ['matches' => $matches->count()];

                continue;
            }

            $product = $matches->first();
            $oldStock = (int) ($stocks[$product->id] ?? 0);
            $newPrice = BigDecimal::of($row['price_with_vat'])
                ->multipliedBy($exchangeRate)
                ->toScale(2, RoundingMode::HalfUp)
                ->__toString();
            $oldPrice = $product->pret_vanzare_cu_tva;
            $stockChanged = $oldStock !== $row['stock'];
            $priceChanged = $oldPrice === null || ! BigDecimal::of($oldPrice)->isEqualTo($newPrice);
            $line = $row + [
                'product_id' => $product->id,
                'product_name' => $product->denumire_engleza,
                'old_stock' => $oldStock,
                'new_stock' => $row['stock'],
                'delta' => $row['stock'] - $oldStock,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'stock_changed' => $stockChanged,
                'price_changed' => $priceChanged,
            ];
            $result[$stockChanged || $priceChanged ? 'changed' : 'unchanged'][] = $line;
        }

        $catalogCodes = Produs::query()->pluck('cod_produs')->map(fn ($code) => mb_strtoupper(trim((string) $code)))->unique();
        $fileCodes = $codes->flip();
        $result['summary'] = [
            'rows' => count($rows),
            'matched' => count($result['changed']) + count($result['unchanged']),
            'changed' => count($result['changed']),
            'unchanged' => count($result['unchanged']),
            'stock_changed' => collect($result['changed'])->where('stock_changed', true)->count(),
            'price_changed' => collect($result['changed'])->where('price_changed', true)->count(),
            'missing' => count($result['missing']),
            'ambiguous' => count($result['ambiguous']),
            'catalog_not_in_file' => $catalogCodes->reject(fn ($code) => $fileCodes->has($code))->count(),
        ];

        return $result;
    }
}
