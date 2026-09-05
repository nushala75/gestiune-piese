<?php

namespace App\Http\Controllers;

use App\Models\Categorie;
use App\Models\Gestiune;
use App\Models\JurnalAudit;
use App\Models\MiscareStoc;
use App\Models\Produs;
use App\Models\UnitateMasura;
use App\Services\CodFgoAllocator;
use App\Services\NecesarAprovizionareService;
use App\Services\StockRegisterExchangeRate;
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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class StockUpdateController extends Controller
{
    private const SESSION_KEY = 'stock_update_import_preview';

    private const MAX_MANUAL_PRODUCTS = 10;

    public function index(StockRegisterExchangeRate $exchangeRate): View
    {
        return view('stock-update.index', [
            'defaultExchangeRate' => $exchangeRate->current(),
        ]);
    }

    public function prepare(
        Request $request,
        StockRegisterXlsxParser $parser,
        StockRegisterExchangeRate $exchangeRateState,
    ): RedirectResponse {
        $exchangeRate = $this->validatedExchangeRate($request);
        $this->clearDraft($request);
        $sourcePath = (string) config('stock-register.path');
        if (! is_file($sourcePath)) {
            return back()->withInput()->withErrors([
                'registru' => 'Fișierul prestabilit nu există: '.$sourcePath,
            ]);
        }

        return $this->preparePreview($request, $parser, $exchangeRateState, $sourcePath, basename($sourcePath), $exchangeRate);
    }

    public function upload(
        Request $request,
        StockRegisterXlsxParser $parser,
        StockRegisterExchangeRate $exchangeRateState,
    ): RedirectResponse {
        $request->validate([
            'registru' => ['required', 'file', 'extensions:xlsx', 'max:20480'],
        ]);
        $exchangeRate = $this->validatedExchangeRate($request);
        $this->clearDraft($request);

        $file = $request->file('registru');
        $token = (string) Str::uuid();
        $temporaryPath = "importuri/stoc/temporare/{$token}.xlsx";
        if (! Storage::disk('local')->putFileAs('importuri/stoc/temporare', $file, "{$token}.xlsx")) {
            return back()->withInput()->withErrors(['registru' => 'Fișierul selectat nu a putut fi copiat pentru verificare.']);
        }

        return $this->preparePreview(
            $request,
            $parser,
            $exchangeRateState,
            Storage::disk('local')->path($temporaryPath),
            $file->getClientOriginalName(),
            $exchangeRate,
            $temporaryPath,
        );
    }

    private function preparePreview(
        Request $request,
        StockRegisterXlsxParser $parser,
        StockRegisterExchangeRate $exchangeRateState,
        string $sourcePath,
        string $originalName,
        string $exchangeRate,
        ?string $temporaryPath = null,
    ): RedirectResponse {
        $hash = hash_file('sha256', $sourcePath);
        if ($hash === false) {
            if ($temporaryPath !== null) {
                Storage::disk('local')->delete($temporaryPath);
            }

            return back()->withInput()->withErrors(['registru' => 'Amprenta fișierului nu a putut fi calculată.']);
        }

        try {
            $parsed = $parser->parse($sourcePath);
            $preview = $this->buildPreview($parsed['rows'], $this->companyWarehouse(), $exchangeRate);
        } catch (Throwable $exception) {
            if ($temporaryPath !== null) {
                Storage::disk('local')->delete($temporaryPath);
            }

            return back()->withInput()->withErrors(['registru' => $exception->getMessage()]);
        }

        if ($preview['summary']['matched'] === 0 && $preview['summary']['ambiguous'] === 0) {
            if ($temporaryPath !== null) {
                Storage::disk('local')->delete($temporaryPath);
            }

            return back()->withInput()->withErrors(['registru' => 'Niciun cod din registru nu există în catalogul local.']);
        }

        try {
            $exchangeRateState->remember($exchangeRate);
        } catch (Throwable $exception) {
            if ($temporaryPath !== null) {
                Storage::disk('local')->delete($temporaryPath);
            }

            return back()->withInput()->withErrors(['registru' => $exception->getMessage()]);
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
            'created_products' => [],
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

    public function newProduct(Request $request, int $row): View|RedirectResponse
    {
        $draft = $request->session()->get(self::SESSION_KEY);
        if (! is_array($draft)) {
            return redirect()->route('stock-update.index')
                ->withErrors(['registru' => 'Previzualizarea a expirat. Încarcă din nou registrul.']);
        }
        if (count($draft['created_products'] ?? []) >= self::MAX_MANUAL_PRODUCTS) {
            return redirect()->route('stock-update.preview')
                ->withErrors(['registru' => 'Ai atins limita de 10 produse create în această sesiune de import.']);
        }

        $line = collect($draft['preview']['missing'] ?? [])->firstWhere('row', $row);
        if (! is_array($line)) {
            return redirect()->route('stock-update.preview')
                ->withErrors(['registru' => 'Poziția nu mai este disponibilă pentru creare.']);
        }

        $categorii = Categorie::query()->where('activa', true)->orderBy('denumire')->get();
        $unitatiMasura = UnitateMasura::query()->where('activa', true)->orderBy('cod')->get();
        $categorieImplicita = $categorii->firstWhere('denumire', 'Pe comanda');
        $unitateImplicita = $unitatiMasura->firstWhere('cod', 'BUC');
        if ($categorii->isEmpty() || $unitatiMasura->isEmpty()) {
            return redirect()->route('stock-update.preview')->withErrors([
                'registru' => 'Produsul nu poate fi creat: nu există categorii sau unități de măsură active.',
            ]);
        }

        $priceRon = BigDecimal::of($line['price_with_vat_eur'])
            ->multipliedBy((string) $draft['exchange_rate'])
            ->toScale(2, RoundingMode::HalfUp)
            ->__toString();

        return view('stock-update.create-product', compact(
            'draft',
            'line',
            'categorii',
            'unitatiMasura',
            'categorieImplicita',
            'unitateImplicita',
            'priceRon',
        ));
    }

    public function storeNewProduct(
        Request $request,
        int $row,
        CodFgoAllocator $allocator,
        StockRegisterXlsxParser $parser,
    ): RedirectResponse {
        $draft = $request->session()->get(self::SESSION_KEY);
        if (! is_array($draft)
            || ! hash_equals((string) ($draft['token'] ?? ''), (string) $request->input('token'))) {
            return redirect()->route('stock-update.index')
                ->withErrors(['registru' => 'Previzualizarea a expirat. Încarcă din nou registrul.']);
        }
        if (count($draft['created_products'] ?? []) >= self::MAX_MANUAL_PRODUCTS) {
            return redirect()->route('stock-update.preview')
                ->withErrors(['registru' => 'Ai atins limita de 10 produse create în această sesiune de import.']);
        }

        $line = collect($draft['preview']['missing'] ?? [])->firstWhere('row', $row);
        if (! is_array($line)) {
            return redirect()->route('stock-update.preview')
                ->withErrors(['registru' => 'Poziția nu mai este disponibilă pentru creare.']);
        }

        $data = $request->validate([
            'token' => ['required', 'uuid'],
            'categorie_id' => ['required', Rule::exists('categorii', 'id')->where('activa', true)],
            'unitate_masura_id' => ['required', Rule::exists('unitati_masura', 'id')->where('activa', true)],
            'marca' => ['nullable', 'string', 'max:100'],
            'stoc_minim' => ['required', 'integer', 'min:0'],
            'activ' => ['required', 'boolean'],
        ]);

        if (Produs::query()->where('cod_produs', $line['code'])->exists()) {
            return redirect()->route('stock-update.preview')
                ->withErrors(['registru' => "Codul {$line['code']} există deja. Reîncarcă previzualizarea."]);
        }

        $warehouse = $this->companyWarehouse();
        $priceRon = BigDecimal::of($line['price_with_vat_eur'])
            ->multipliedBy((string) $draft['exchange_rate'])
            ->toScale(2, RoundingMode::HalfUp)
            ->__toString();
        $priceNet = BigDecimal::of($priceRon)
            ->dividedBy('1.21', 4, RoundingMode::HalfUp)
            ->__toString();

        $product = DB::transaction(function () use ($allocator, $data, $line, $priceNet, $priceRon, $warehouse): Produs {
            $product = Produs::query()->create([
                'cod_fgo' => $allocator->aloca(),
                'cod_produs' => $line['code'],
                'denumire_engleza' => $line['english_name'],
                'descriere_romana' => $line['romanian_name'],
                'categorie_id' => $data['categorie_id'],
                'unitate_masura_id' => $data['unitate_masura_id'],
                'marca' => filled($data['marca'] ?? null) ? mb_strtoupper(trim($data['marca'])) : 'KYMCO',
                'stoc_minim' => $data['stoc_minim'],
                'cantitate_de_comandat' => $line['reorder_quantity'],
                'furnizor_comanda_id' => null,
                'furnizor_comanda_manual' => false,
                'pret_vanzare_fara_tva' => $priceNet,
                'pret_vanzare_cu_tva' => $priceRon,
                'cota_tva' => '21.00',
                'greutate_kg' => $line['weight_kg'],
                'voluminos' => false,
                'activ' => $data['activ'],
                'sursa' => 'registru_xlsx',
            ]);

            DB::table('solduri_stoc')->insert([
                'gestiune_id' => $warehouse->id,
                'produs_id' => $product->id,
                'cantitate_fizica' => $line['stock'],
                'cantitate_rezervata' => 0,
                'updated_at' => now(),
            ]);

            return $product;
        });

        $sourcePath = (string) $draft['source_path'];
        $parsed = $parser->parse($sourcePath);
        $draft['created_products'][] = ['id' => $product->id, 'code' => $product->cod_produs];
        $draft['preview'] = $this->buildPreview($parsed['rows'], $warehouse, (string) $draft['exchange_rate']);
        $request->session()->put(self::SESSION_KEY, $draft);

        return redirect()->route('stock-update.preview')->with(
            'status',
            "Produsul {$product->cod_produs} a fost creat. Mai poți crea ".(self::MAX_MANUAL_PRODUCTS - count($draft['created_products'])).' produse în această sesiune.',
        );
    }

    public function apply(Request $request, StockRegisterXlsxParser $parser, NecesarAprovizionareService $reorder): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'uuid'],
            'confirmare' => ['accepted'],
        ], [
            'confirmare.accepted' => 'Bifează confirmarea înainte de aplicarea actualizării.',
        ]);
        $draft = $request->session()->get(self::SESSION_KEY);
        if (! is_array($draft) || ! hash_equals((string) ($draft['token'] ?? ''), $validated['token'])) {
            return redirect()->route('stock-update.index')
                ->withErrors(['registru' => 'Previzualizarea a expirat. Încarcă din nou registrul.']);
        }

        $sourcePath = (string) ($draft['source_path'] ?? '');
        if (! is_file($sourcePath)) {
            return redirect()->route('stock-update.index')
                ->withErrors(['registru' => 'Fișierul folosit la previzualizare nu mai există.']);
        }
        $currentHash = hash_file('sha256', $sourcePath);
        if ($currentHash === false || ! hash_equals((string) $draft['hash'], $currentHash)) {
            throw new RuntimeException('Fișierul a fost modificat după generarea previzualizării.');
        }

        try {
            $parsed = $parser->parse($sourcePath);
            $warehouse = $this->companyWarehouse();
            $exchangeRate = (string) ($draft['exchange_rate'] ?? '');
            if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $exchangeRate) || (float) $exchangeRate <= 0) {
                throw new RuntimeException('Cursul EUR salvat în previzualizare nu este valid.');
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
                        throw new RuntimeException('Există coduri care corespund mai multor produse. Datele nu au fost modificate.');
                    }

                    $applied = [
                        'products_applied' => 0,
                        'stock_applied' => 0,
                        'price_applied' => 0,
                        'weight_applied' => 0,
                        'english_name_applied' => 0,
                        'romanian_name_applied' => 0,
                        'reorder_applied' => 0,
                    ];

                    foreach ($preview['changed'] as $line) {
                        $product = Produs::query()->findOrFail($line['product_id']);
                        $productUpdates = [];

                        if ($line['price_changed']) {
                            $vatPercent = BigDecimal::of((string) ($product->cota_tva ?? '21'));
                            $vatFactor = BigDecimal::of('1')->plus(
                                $vatPercent->dividedBy('100', 6, RoundingMode::HalfUp),
                            );
                            $productUpdates['pret_vanzare_fara_tva'] = BigDecimal::of($line['new_price'])
                                ->dividedBy($vatFactor, 4, RoundingMode::HalfUp)
                                ->__toString();
                            $productUpdates['pret_vanzare_cu_tva'] = $line['new_price'];
                            $applied['price_applied']++;
                        }
                        if ($line['weight_changed']) {
                            $productUpdates['greutate_kg'] = $line['new_weight'];
                            $applied['weight_applied']++;
                        }
                        if ($line['english_name_changed']) {
                            $productUpdates['denumire_engleza'] = $line['new_english_name'];
                            $applied['english_name_applied']++;
                        }
                        if ($line['romanian_name_changed']) {
                            $productUpdates['descriere_romana'] = $line['new_romanian_name'];
                            $applied['romanian_name_applied']++;
                        }
                        if ($line['reorder_changed']) {
                            $productUpdates['cantitate_de_comandat'] = $line['new_reorder_quantity'];
                            $applied['reorder_applied']++;
                        }
                        if ($productUpdates !== []) {
                            $product->update($productUpdates);
                        }

                        if ($line['stock_changed']) {
                            DB::table('solduri_stoc')->updateOrInsert(
                                ['gestiune_id' => $warehouse->id, 'produs_id' => $line['product_id']],
                                ['cantitate_fizica' => $line['new_stock'], 'updated_at' => now()],
                            );
                            $applied['stock_applied']++;

                            if (Schema::hasTable('miscari_stoc') && $line['delta'] !== 0) {
                                MiscareStoc::query()->create([
                                    'gestiune_id' => $warehouse->id,
                                    'produs_id' => $line['product_id'],
                                    'tip' => 'ajustare_inventar',
                                    'cantitate' => $line['delta'],
                                    'cost_unitar' => null,
                                    'receptie_linie_id' => null,
                                    'referinta_tip' => 'import_registru_xlsx',
                                    'referinta_id' => null,
                                    'explicatie' => 'Actualizare din registrul '.$draft['original_name'],
                                ]);
                            }

                            $reorder->sincronizeaza($product->fresh(), $warehouse);
                        }

                        $applied['products_applied']++;
                    }

                    if (Schema::hasTable('jurnal_audit')) {
                        JurnalAudit::query()->create([
                            'actor_tip' => 'user',
                            'actor_id' => request()->user()?->id,
                            'actiune' => 'actualizare_produse_xlsx',
                            'entitate_tip' => 'gestiune',
                            'entitate_id' => $warehouse->id,
                            'date_inainte' => ['fisier' => $draft['original_name'], 'hash_sha256' => $draft['hash']],
                            'date_dupa' => $preview['summary'] + $applied + ['curs_eur_ron' => $exchangeRate],
                        ]);
                    }

                    return $preview['summary'] + $applied;
                });
            } catch (Throwable $exception) {
                if ($archiveCreated) {
                    Storage::disk('local')->delete($archivePath);
                }
                throw $exception;
            }
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['registru' => 'Datele nu au fost actualizate: '.$exception->getMessage()]);
        }

        $request->session()->forget(self::SESSION_KEY);
        if (! empty($draft['temporary_path'])) {
            Storage::disk('local')->delete((string) $draft['temporary_path']);
        }

        return redirect()->route('stock-update.index')->with(
            'status',
            "Actualizarea s-a încheiat: {$result['products_applied']} produse modificate, {$result['missing']} coduri inexistente ignorate.",
        );
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->clearDraft($request);

        return redirect()->route('stock-update.index');
    }

    private function validatedExchangeRate(Request $request): string
    {
        $validated = $request->validate([
            'exchange_rate' => ['required', 'string', 'regex:/^\d+(?:[\.,]\d{1,4})?$/'],
        ], [
            'exchange_rate.required' => 'Completează cursul EUR/RON.',
            'exchange_rate.regex' => 'Cursul EUR/RON trebuie să fie un număr pozitiv cu maximum 4 zecimale.',
        ]);
        $rate = str_replace(',', '.', $validated['exchange_rate']);
        if ((float) $rate <= 0) {
            throw ValidationException::withMessages([
                'exchange_rate' => 'Cursul EUR/RON trebuie să fie mai mare decât zero.',
            ]);
        }

        return BigDecimal::of($rate)->__toString();
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
     * @param  list<array{row: int, code: string, stock: int, english_name: string, reorder_quantity: int, weight_kg: string, price_with_vat_eur: string, romanian_name: string}>  $rows
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
            $newPrice = BigDecimal::of($row['price_with_vat_eur'])
                ->multipliedBy($exchangeRate)
                ->toScale(2, RoundingMode::HalfUp)
                ->__toString();
            $oldPrice = $product->pret_vanzare_cu_tva;
            $oldWeight = $product->greutate_kg;
            $changes = [
                'stock_changed' => $oldStock !== $row['stock'],
                'price_changed' => $this->decimalChanged($oldPrice, $newPrice),
                'weight_changed' => $this->decimalChanged($oldWeight, $row['weight_kg']),
                'english_name_changed' => trim((string) $product->denumire_engleza) !== $row['english_name'],
                'romanian_name_changed' => trim((string) $product->descriere_romana) !== $row['romanian_name'],
                'reorder_changed' => (int) $product->cantitate_de_comandat !== $row['reorder_quantity'],
            ];
            $line = $row + [
                'product_id' => $product->id,
                'product_name' => $product->denumire_engleza,
                'old_stock' => $oldStock,
                'new_stock' => $row['stock'],
                'delta' => $row['stock'] - $oldStock,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'old_weight' => $oldWeight,
                'new_weight' => $row['weight_kg'],
                'old_english_name' => $product->denumire_engleza,
                'new_english_name' => $row['english_name'],
                'old_romanian_name' => $product->descriere_romana,
                'new_romanian_name' => $row['romanian_name'],
                'old_reorder_quantity' => (int) $product->cantitate_de_comandat,
                'new_reorder_quantity' => $row['reorder_quantity'],
            ] + $changes;
            $result[in_array(true, $changes, true) ? 'changed' : 'unchanged'][] = $line;
        }

        $catalogCodes = Produs::query()->pluck('cod_produs')
            ->map(fn ($code) => mb_strtoupper(trim((string) $code)))
            ->filter()
            ->unique();
        $fileCodes = $codes->flip();
        $changed = collect($result['changed']);
        $result['summary'] = [
            'rows' => count($rows),
            'matched' => count($result['changed']) + count($result['unchanged']),
            'changed' => count($result['changed']),
            'unchanged' => count($result['unchanged']),
            'stock_changed' => $changed->where('stock_changed', true)->count(),
            'price_changed' => $changed->where('price_changed', true)->count(),
            'weight_changed' => $changed->where('weight_changed', true)->count(),
            'english_name_changed' => $changed->where('english_name_changed', true)->count(),
            'romanian_name_changed' => $changed->where('romanian_name_changed', true)->count(),
            'reorder_changed' => $changed->where('reorder_changed', true)->count(),
            'missing' => count($result['missing']),
            'ambiguous' => count($result['ambiguous']),
            'catalog_not_in_file' => $catalogCodes->reject(fn ($code) => $fileCodes->has($code))->count(),
        ];

        return $result;
    }

    private function decimalChanged(mixed $oldValue, string $newValue): bool
    {
        return $oldValue === null || ! BigDecimal::of((string) $oldValue)->isEqualTo($newValue);
    }
}
