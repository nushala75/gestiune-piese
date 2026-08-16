<?php

namespace App\Http\Controllers;

use App\Models\Categorie;
use App\Models\FacturaFurnizor;
use App\Models\FacturaFurnizorLinie;
use App\Models\Furnizor;
use App\Models\ImportFisier;
use App\Models\Produs;
use App\Models\ProdusFurnizor;
use App\Models\UnitateMasura;
use App\Services\CodFgoAllocator;
use App\Services\MotoTrendInvoiceParser;
use App\Services\NecesarAprovizionareService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class FacturaFurnizorImportController extends Controller
{
    private const SESSION_KEY = 'factura_furnizor_import_preview';

    private const SALE_PRICE_MULTIPLIER = '11.5';

    public function index(): View
    {
        $facturi = FacturaFurnizor::query()
            ->with(['furnizor', 'linii', 'receptie'])
            ->latest('id')
            ->paginate(20);

        return view('facturi-furnizori.index', compact('facturi'));
    }

    public function upload(Request $request, MotoTrendInvoiceParser $parser): RedirectResponse
    {
        return $this->uploadDocument($request, $parser, 'factura');
    }

    public function uploadStorno(Request $request, MotoTrendInvoiceParser $parser): RedirectResponse
    {
        return $this->uploadDocument($request, $parser, 'storno');
    }

    private function uploadDocument(Request $request, MotoTrendInvoiceParser $parser, string $tipDocument): RedirectResponse
    {
        $request->validate([
            'factura_pdf' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        $file = $request->file('factura_pdf');
        $hash = hash_file('sha256', $file->getRealPath());
        if ($hash === false) {
            return back()->withErrors(['factura_pdf' => 'Amprenta fișierului PDF nu a putut fi calculată.']);
        }

        $tipImport = $tipDocument === 'storno' ? 'storno_furnizor_moto_trend' : 'factura_furnizor_moto_trend';
        if (ImportFisier::query()->where('tip', $tipImport)->where('hash_sha256', $hash)->exists()) {
            return back()->withErrors(['factura_pdf' => 'Acest fișier a fost deja importat.']);
        }

        $previousDraft = $request->session()->pull(self::SESSION_KEY);
        if (is_array($previousDraft) && isset($previousDraft['temporary_path'])) {
            Storage::disk('local')->delete((string) $previousDraft['temporary_path']);
        }

        $token = (string) Str::uuid();
        $temporaryPath = "importuri/facturi-furnizori/temporare/{$token}.pdf";
        Storage::disk('local')->putFileAs(
            'importuri/facturi-furnizori/temporare',
            $file,
            "{$token}.pdf",
        );

        try {
            $invoice = $parser->parse(Storage::disk('local')->path($temporaryPath));
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($temporaryPath);

            return back()->withErrors(['factura_pdf' => $exception->getMessage()]);
        }

        if (FacturaFurnizor::query()
            ->whereHas('furnizor', fn ($query) => $query->where('cod_fiscal', $invoice['supplier_vat']))
            ->where('numar_original', $invoice['invoice_number'])
            ->exists()) {
            Storage::disk('local')->delete($temporaryPath);

            return back()->withErrors(['factura_pdf' => 'Numărul acestei facturi MOTO TREND există deja.']);
        }

        $supplier = Furnizor::query()->where('cod_fiscal', $invoice['supplier_vat'])->first();
        $mappings = $supplier?->produse()
            ->with('produs')
            ->get()
            ->keyBy(fn ($mapping) => mb_strtoupper($mapping->cod_furnizor)) ?? collect();

        $supplierCodes = collect($invoice['lines'])
            ->pluck('supplier_code')
            ->map(fn ($code): string => mb_strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->values();
        $catalogProducts = $tipDocument === 'storno'
            ? collect()
            : Produs::query()
                ->whereIn('cod_produs', $supplierCodes)
                ->get()
                ->groupBy(fn (Produs $product): string => mb_strtoupper(trim($product->cod_produs)))
                ->filter(fn ($products): bool => $products->count() === 1)
                ->map(fn ($products): Produs => $products->first());

        foreach ($invoice['lines'] as &$line) {
            $normalizedCode = mb_strtoupper(trim((string) $line['supplier_code']));
            $mapping = $mappings->get($normalizedCode);
            $catalogProduct = $mapping === null ? $catalogProducts->get($normalizedCode) : null;
            $matchedProduct = $mapping?->produs ?? $catalogProduct;
            $line['product_id'] = $matchedProduct?->id;
            $line['product_label'] = $matchedProduct
                ? $matchedProduct->cod_produs.' '.$matchedProduct->denumire_engleza
                : null;
            $line['auto_catalog_match'] = $mapping === null && $catalogProduct !== null;
            $line['current_sale_price'] = $tipDocument === 'storno' ? null : $matchedProduct?->pret_vanzare_cu_tva;
            $line['proposed_sale_price'] = $tipDocument !== 'storno' && $line['unit_price'] !== ''
                ? BigDecimal::of($line['unit_price'])->multipliedBy(self::SALE_PRICE_MULTIPLIER)->toScale(2, RoundingMode::HalfUp)->__toString()
                : null;
            $line['price_warning'] = $tipDocument !== 'storno'
                && $matchedProduct !== null
                && $line['proposed_sale_price'] !== null
                && ($line['current_sale_price'] === null
                    || BigDecimal::of($line['proposed_sale_price'])->isGreaterThan($line['current_sale_price']));
        }
        unset($line);

        $draft = [
            'token' => $token,
            'temporary_path' => $temporaryPath,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'hash' => $hash,
            'tip_document' => $tipDocument,
            'invoice' => $invoice,
        ];
        $request->session()->put(self::SESSION_KEY, $draft);

        return redirect()->route('facturi-furnizori.preview');
    }

    public function preview(Request $request): View|RedirectResponse
    {
        $draft = $request->session()->get(self::SESSION_KEY);
        if (! is_array($draft)) {
            return redirect()->route('facturi-furnizori.index')
                ->withErrors(['factura_pdf' => 'Nu există o factură în curs de verificare.']);
        }

        $produse = Produs::query()
            ->when(($draft['tip_document'] ?? 'factura') === 'storno', function (Builder $query) use ($draft): void {
                $query->whereHas('furnizori.furnizor', fn (Builder $supplierQuery) => $supplierQuery
                    ->where('cod_fiscal', $draft['invoice']['supplier_vat']));
            })
            ->orderBy('cod_produs')
            ->get(['id', 'cod_produs', 'denumire_engleza']);

        return view('facturi-furnizori.preview', [
            'draft' => $draft,
            'produse' => $produse,
        ]);
    }

    public function newProduct(Request $request, int $line): View|RedirectResponse
    {
        $draft = $request->session()->get(self::SESSION_KEY);
        if (! is_array($draft) || ! isset($draft['invoice']['lines'][$line])) {
            return redirect()->route('facturi-furnizori.preview')
                ->withErrors(['lines' => 'Poziția facturii nu mai este disponibilă.']);
        }
        if (($draft['tip_document'] ?? 'factura') === 'storno') {
            return redirect()->route('facturi-furnizori.preview')
                ->withErrors(['lines' => 'Într-o factură storno nu se pot crea produse noi. Selectează un produs deja mapat la furnizor.']);
        }

        $invoiceLine = $draft['invoice']['lines'][$line];
        if (! empty($invoiceLine['product_id'])) {
            return redirect()->route('facturi-furnizori.preview')
                ->withErrors(['lines' => 'Poziția este deja mapată la un produs.']);
        }

        $categorii = Categorie::query()->where('activa', true)->orderBy('denumire')->get();
        $unitatiMasura = UnitateMasura::query()->where('activa', true)->orderBy('cod')->get();
        $categorieImplicita = $categorii->firstWhere('denumire', 'Pe comanda');
        $unitateImplicita = $unitatiMasura->firstWhere('cod', 'BUC');

        if ($categorieImplicita === null || $unitateImplicita === null) {
            return redirect()->route('facturi-furnizori.preview')->withErrors([
                'lines' => 'Produsul nou nu poate fi creat: lipsesc categoria activă „Pe comanda” sau unitatea activă „BUC”.',
            ]);
        }

        return view('facturi-furnizori.produs-nou', [
            'draft' => $draft,
            'lineIndex' => $line,
            'line' => $invoiceLine,
            'categorii' => $categorii,
            'unitatiMasura' => $unitatiMasura,
            'categorieImplicita' => $categorieImplicita,
            'unitateImplicita' => $unitateImplicita,
        ]);
    }

    public function storeNewProduct(
        Request $request,
        int $line,
        CodFgoAllocator $allocator,
        NecesarAprovizionareService $necesarAprovizionare,
    ): RedirectResponse {
        $draft = $request->session()->get(self::SESSION_KEY);
        if (! is_array($draft)
            || ! hash_equals((string) ($draft['token'] ?? ''), (string) $request->input('token'))
            || ! isset($draft['invoice']['lines'][$line])) {
            return redirect()->route('facturi-furnizori.index')
                ->withErrors(['factura_pdf' => 'Previzualizarea a expirat. Încarcă din nou factura.']);
        }
        if (($draft['tip_document'] ?? 'factura') === 'storno') {
            return redirect()->route('facturi-furnizori.preview')
                ->withErrors(['lines' => 'Într-o factură storno nu se pot crea produse noi.']);
        }

        $invoiceLine = $draft['invoice']['lines'][$line];
        if (! empty($invoiceLine['product_id'])) {
            return redirect()->route('facturi-furnizori.preview')
                ->withErrors(['lines' => 'Poziția este deja mapată la un produs.']);
        }

        $data = $request->validate([
            'token' => ['required', 'uuid'],
            'cod_produs' => ['required', 'string', 'max:64'],
            'denumire_engleza' => ['required', 'string', 'max:255'],
            'descriere_romana' => ['nullable', 'string'],
            'categorie_id' => ['required', Rule::exists('categorii', 'id')->where('activa', true)],
            'unitate_masura_id' => ['required', Rule::exists('unitati_masura', 'id')->where('activa', true)],
            'marca' => ['nullable', 'string', 'max:100'],
            'stoc_minim' => ['required', 'integer', 'min:0'],
            'pret_intrare' => ['required', 'decimal:0,4', 'min:0'],
            'pret_vanzare_cu_tva' => ['required', 'decimal:0,2', 'min:0'],
            'greutate_kg' => ['nullable', 'decimal:0,3', 'min:0'],
            'voluminos' => ['required', 'boolean'],
            'lungime_cm' => ['nullable', 'required_if:voluminos,1', 'decimal:0,2', 'min:0'],
            'latime_cm' => ['nullable', 'required_if:voluminos,1', 'decimal:0,2', 'min:0'],
            'inaltime_cm' => ['nullable', 'required_if:voluminos,1', 'decimal:0,2', 'min:0'],
            'activ' => ['required', 'boolean'],
        ], [
            'denumire_engleza.required' => 'Description of Goods este obligatorie pentru salvarea produsului.',
        ]);

        $pretFaraTva = BigDecimal::of($data['pret_vanzare_cu_tva'])
            ->dividedBy('1.21', 4, RoundingMode::HalfUp)
            ->__toString();

        try {
            $product = DB::transaction(function () use ($allocator, $data, $draft, $invoiceLine, $necesarAprovizionare, $pretFaraTva): Produs {
                $supplier = Furnizor::query()->firstOrCreate(
                    ['cod_fiscal' => $draft['invoice']['supplier_vat']],
                    [
                        'denumire' => 'MOTO TREND S.A',
                        'tara' => 'GR',
                        'moneda_implicita' => 'EUR',
                        'configuratie_parser' => ['format' => 'moto_trend_pdf_v1'],
                        'activ' => true,
                    ],
                );

                $product = Produs::query()->create([
                    'cod_fgo' => $allocator->aloca(),
                    'cod_produs' => mb_strtoupper(trim($data['cod_produs'])),
                    'denumire_engleza' => mb_strtoupper(trim($data['denumire_engleza'])),
                    'descriere_romana' => filled($data['descriere_romana'] ?? null) ? trim($data['descriere_romana']) : null,
                    'categorie_id' => $data['categorie_id'],
                    'unitate_masura_id' => $data['unitate_masura_id'],
                    'marca' => filled($data['marca'] ?? null) ? mb_strtoupper(trim($data['marca'])) : null,
                    'stoc_minim' => $data['stoc_minim'],
                    'pret_vanzare_fara_tva' => $pretFaraTva,
                    'pret_vanzare_cu_tva' => $data['pret_vanzare_cu_tva'],
                    'cota_tva' => '21.00',
                    'greutate_kg' => $data['greutate_kg'] ?? null,
                    'voluminos' => $data['voluminos'],
                    'lungime_cm' => $data['lungime_cm'] ?? null,
                    'latime_cm' => $data['latime_cm'] ?? null,
                    'inaltime_cm' => $data['inaltime_cm'] ?? null,
                    'activ' => $data['activ'],
                    'sursa' => 'factura_moto_trend',
                ]);

                ProdusFurnizor::query()->create([
                    'produs_id' => $product->id,
                    'furnizor_id' => $supplier->id,
                    'cod_furnizor' => mb_strtoupper(trim((string) $invoiceLine['supplier_code'])),
                    'denumire_furnizor' => trim((string) $invoiceLine['description']),
                    'pret_achizitie_ultim' => $data['pret_intrare'],
                    'moneda' => $draft['invoice']['currency'],
                    'data_ultimei_achizitii' => $draft['invoice']['invoice_date'],
                    'confirmata_manual' => true,
                ]);

                $necesarAprovizionare->sincronizeaza($product);

                return $product;
            });
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors([
                'produs' => 'Produsul nu a putut fi salvat: '.$exception->getMessage(),
            ]);
        }

        $draft['invoice']['lines'][$line]['product_id'] = $product->id;
        $draft['invoice']['lines'][$line]['product_label'] = $product->cod_produs.' '.$product->denumire_engleza;
        $draft['invoice']['lines'][$line]['current_sale_price'] = $product->pret_vanzare_cu_tva;
        $draft['invoice']['lines'][$line]['proposed_sale_price'] = $product->pret_vanzare_cu_tva;
        $draft['invoice']['lines'][$line]['price_warning'] = false;
        $request->session()->put(self::SESSION_KEY, $draft);

        return redirect()->route('facturi-furnizori.preview')
            ->with('status', "Produsul {$product->cod_produs} a fost creat și mapat pe poziția ".($line + 1).'.');
    }

    public function confirmPrice(Request $request, int $line): RedirectResponse
    {
        $draft = $request->session()->get(self::SESSION_KEY);
        if (! is_array($draft)
            || ! hash_equals((string) ($draft['token'] ?? ''), (string) $request->input('token'))
            || ! isset($draft['invoice']['lines'][$line])) {
            return redirect()->route('facturi-furnizori.index')
                ->withErrors(['factura_pdf' => 'Previzualizarea a expirat. Încarcă din nou factura.']);
        }
        if (($draft['tip_document'] ?? 'factura') === 'storno') {
            return redirect()->route('facturi-furnizori.preview')
                ->withErrors(['lines' => 'Factura storno nu modifică prețurile produselor.']);
        }

        $invoiceLine = $draft['invoice']['lines'][$line];
        if (empty($invoiceLine['product_id'])) {
            return redirect()->route('facturi-furnizori.preview')
                ->withErrors(['lines' => 'Poziția nu este mapată la un produs existent.']);
        }

        $data = $request->validate([
            'token' => ['required', 'uuid'],
            'pret_vanzare_cu_tva' => ['required', 'decimal:0,2', 'min:0'],
        ]);

        $pretFaraTva = BigDecimal::of($data['pret_vanzare_cu_tva'])
            ->dividedBy('1.21', 4, RoundingMode::HalfUp)
            ->__toString();
        $product = Produs::query()->find($invoiceLine['product_id']);
        if ($product === null) {
            return redirect()->route('facturi-furnizori.preview')
                ->withErrors(['lines' => 'Produsul mapat nu mai există.']);
        }

        $product->update([
            'pret_vanzare_cu_tva' => $data['pret_vanzare_cu_tva'],
            'pret_vanzare_fara_tva' => $pretFaraTva,
        ]);

        $draft['invoice']['lines'][$line]['current_sale_price'] = $product->pret_vanzare_cu_tva;
        $draft['invoice']['lines'][$line]['proposed_sale_price'] = $product->pret_vanzare_cu_tva;
        $draft['invoice']['lines'][$line]['price_warning'] = false;
        $request->session()->put(self::SESSION_KEY, $draft);

        return redirect()->route('facturi-furnizori.preview')
            ->with('status', "Prețul produsului {$product->cod_produs} a fost actualizat.");
    }

    public function show(FacturaFurnizor $factura): View
    {
        $factura->load(['furnizor', 'linii.produs', 'receptie']);

        $produse = Produs::query()
            ->when($factura->tip_document === 'storno', fn (Builder $query) => $query
                ->whereHas('furnizori', fn (Builder $mappingQuery) => $mappingQuery->where('furnizor_id', $factura->furnizor_id)))
            ->orderBy('cod_produs')
            ->get(['id', 'cod_produs', 'denumire_engleza']);

        return view('facturi-furnizori.show', [
            'factura' => $factura,
            'produse' => $produse,
        ]);
    }

    public function newProductFromImported(FacturaFurnizor $factura, FacturaFurnizorLinie $linie): View|RedirectResponse
    {
        if ($factura->tip_document === 'storno') {
            return redirect()->route('facturi-furnizori.show', $factura)
                ->withErrors(['factura' => 'Într-o factură storno nu se pot crea produse noi.']);
        }
        if ($linie->tip_linie === 'cost') {
            return redirect()->route('facturi-furnizori.show', $factura)
                ->withErrors(['factura' => 'Pozițiile de cost nu se mapează la produse.']);
        }
        if ($linie->factura_id !== $factura->id || $linie->produs_id !== null || $factura->status !== 'import_partial') {
            return redirect()->route('facturi-furnizori.show', $factura)
                ->withErrors(['factura' => 'Poziția nu este disponibilă pentru crearea unui produs nou.']);
        }
        if ($factura->receptie()->exists()) {
            return redirect()->route('facturi-furnizori.show', $factura)
                ->withErrors(['factura' => 'Produsele nu mai pot fi create după începerea recepției.']);
        }

        $categorii = Categorie::query()->where('activa', true)->orderBy('denumire')->get();
        $unitatiMasura = UnitateMasura::query()->where('activa', true)->orderBy('cod')->get();
        $categorieImplicita = $categorii->firstWhere('denumire', 'Pe comanda');
        $unitateImplicita = $unitatiMasura->firstWhere('cod', 'BUC');
        if ($categorieImplicita === null || $unitateImplicita === null) {
            return redirect()->route('facturi-furnizori.show', $factura)->withErrors([
                'factura' => 'Produsul nou nu poate fi creat: lipsesc categoria activă „Pe comanda” sau unitatea activă „BUC”.',
            ]);
        }

        return view('facturi-furnizori.produs-nou-importat', [
            'factura' => $factura,
            'linie' => $linie,
            'pretPropus' => BigDecimal::of($linie->pret_unitar_calculat)
                ->multipliedBy(self::SALE_PRICE_MULTIPLIER)
                ->toScale(2, RoundingMode::HalfUp)
                ->__toString(),
            'categorii' => $categorii,
            'unitatiMasura' => $unitatiMasura,
            'categorieImplicita' => $categorieImplicita,
            'unitateImplicita' => $unitateImplicita,
        ]);
    }

    public function storeNewProductFromImported(
        Request $request,
        FacturaFurnizor $factura,
        FacturaFurnizorLinie $linie,
        CodFgoAllocator $allocator,
        NecesarAprovizionareService $necesarAprovizionare,
    ): RedirectResponse {
        if ($factura->tip_document === 'storno') {
            return redirect()->route('facturi-furnizori.show', $factura)
                ->withErrors(['factura' => 'Într-o factură storno nu se pot crea produse noi.']);
        }
        if ($linie->tip_linie === 'cost') {
            return redirect()->route('facturi-furnizori.show', $factura)
                ->withErrors(['factura' => 'Pozițiile de cost nu se mapează la produse.']);
        }
        if ($linie->factura_id !== $factura->id
            || $linie->produs_id !== null
            || $factura->status !== 'import_partial'
            || $factura->receptie()->exists()) {
            return redirect()->route('facturi-furnizori.show', $factura)
                ->withErrors(['factura' => 'Poziția nu mai este disponibilă pentru crearea produsului.']);
        }

        $data = $request->validate([
            'cod_produs' => ['required', 'string', 'max:64'],
            'denumire_engleza' => ['required', 'string', 'max:255'],
            'descriere_romana' => ['nullable', 'string'],
            'categorie_id' => ['required', Rule::exists('categorii', 'id')->where('activa', true)],
            'unitate_masura_id' => ['required', Rule::exists('unitati_masura', 'id')->where('activa', true)],
            'marca' => ['nullable', 'string', 'max:100'],
            'stoc_minim' => ['required', 'integer', 'min:0'],
            'pret_intrare' => ['required', 'decimal:0,4', 'min:0'],
            'pret_vanzare_cu_tva' => ['required', 'decimal:0,2', 'min:0'],
            'greutate_kg' => ['nullable', 'decimal:0,3', 'min:0'],
            'voluminos' => ['required', 'boolean'],
            'lungime_cm' => ['nullable', 'required_if:voluminos,1', 'decimal:0,2', 'min:0'],
            'latime_cm' => ['nullable', 'required_if:voluminos,1', 'decimal:0,2', 'min:0'],
            'inaltime_cm' => ['nullable', 'required_if:voluminos,1', 'decimal:0,2', 'min:0'],
            'activ' => ['required', 'boolean'],
        ], [
            'denumire_engleza.required' => 'Description of Goods este obligatorie pentru salvarea produsului.',
        ]);

        $pretFaraTva = BigDecimal::of($data['pret_vanzare_cu_tva'])
            ->dividedBy('1.21', 4, RoundingMode::HalfUp)
            ->__toString();

        try {
            $product = DB::transaction(function () use ($allocator, $data, $factura, $linie, $necesarAprovizionare, $pretFaraTva): Produs {
                $product = Produs::query()->create([
                    'cod_fgo' => $allocator->aloca(),
                    'cod_produs' => mb_strtoupper(trim($data['cod_produs'])),
                    'denumire_engleza' => mb_strtoupper(trim($data['denumire_engleza'])),
                    'descriere_romana' => filled($data['descriere_romana'] ?? null) ? trim($data['descriere_romana']) : null,
                    'categorie_id' => $data['categorie_id'],
                    'unitate_masura_id' => $data['unitate_masura_id'],
                    'marca' => filled($data['marca'] ?? null) ? mb_strtoupper(trim($data['marca'])) : null,
                    'stoc_minim' => $data['stoc_minim'],
                    'pret_vanzare_fara_tva' => $pretFaraTva,
                    'pret_vanzare_cu_tva' => $data['pret_vanzare_cu_tva'],
                    'cota_tva' => '21.00',
                    'greutate_kg' => $data['greutate_kg'] ?? null,
                    'voluminos' => $data['voluminos'],
                    'lungime_cm' => $data['lungime_cm'] ?? null,
                    'latime_cm' => $data['latime_cm'] ?? null,
                    'inaltime_cm' => $data['inaltime_cm'] ?? null,
                    'activ' => $data['activ'],
                    'sursa' => 'factura_moto_trend',
                ]);

                ProdusFurnizor::query()->updateOrCreate(
                    ['furnizor_id' => $factura->furnizor_id, 'cod_furnizor' => $linie->cod_furnizor],
                    [
                        'produs_id' => $product->id,
                        'denumire_furnizor' => $linie->descriere_originala,
                        'pret_achizitie_ultim' => $data['pret_intrare'],
                        'moneda' => $factura->moneda,
                        'data_ultimei_achizitii' => $factura->data_factura,
                        'confirmata_manual' => true,
                    ],
                );

                $necesarAprovizionare->sincronizeaza($product);

                $linie->update(['produs_id' => $product->id, 'status_mapare' => 'mapat', 'observatii' => null]);
                $factura->update(['status' => 'import_partial']);

                return $product;
            });
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors(['produs' => 'Produsul nu a putut fi salvat: '.$exception->getMessage()]);
        }

        return redirect()->route('facturi-furnizori.show', $factura)
            ->with('status', "Produsul {$product->cod_produs} a fost creat și mapat.");
    }

    public function updateMappings(Request $request, FacturaFurnizor $factura): RedirectResponse
    {
        if ($factura->status !== 'import_partial') {
            return back()->withErrors(['factura' => 'Importul este deja finalizat. Pentru refacere, șterge factura și reimportă PDF-ul.']);
        }
        if ($factura->receptie()->exists()) {
            return back()->withErrors(['factura' => 'Mapările nu mai pot fi modificate după crearea recepției.']);
        }

        $productRule = $factura->tip_document === 'storno'
            ? Rule::exists('produse_furnizori', 'produs_id')->where('furnizor_id', $factura->furnizor_id)
            : Rule::exists('produse', 'id');
        $data = $request->validate([
            'lines' => ['required', 'array'],
            'lines.*.product_id' => ['nullable', 'integer', $productRule],
        ]);

        $factura->load('linii');
        DB::transaction(function () use ($data, $factura): void {
            foreach ($factura->linii as $line) {
                if ($line->tip_linie === 'cost') {
                    continue;
                }
                $productId = $data['lines'][$line->id]['product_id'] ?? null;
                $line->update([
                    'produs_id' => $productId,
                    'status_mapare' => $productId ? 'mapat' : 'necesita_mapare',
                    'observatii' => $productId ? $line->observatii : 'Produsul necesită mapare manuală.',
                ]);

                if ($productId && $factura->tip_document !== 'storno') {
                    ProdusFurnizor::query()->updateOrCreate(
                        [
                            'furnizor_id' => $factura->furnizor_id,
                            'cod_furnizor' => $line->cod_furnizor,
                        ],
                        [
                            'produs_id' => $productId,
                            'denumire_furnizor' => $line->descriere_originala,
                            'moneda' => $factura->moneda,
                            'confirmata_manual' => true,
                        ],
                    );
                }
            }

            $factura->update(['status' => 'import_partial']);
        });

        return back()->with('status', 'Mapările au fost salvate. Folosește „Finalizează importul” după completarea tuturor pozițiilor.');
    }

    public function finalizeImport(FacturaFurnizor $factura): RedirectResponse
    {
        if ($factura->receptie()->exists()) {
            return back()->withErrors(['factura' => 'Importul nu mai poate fi modificat după crearea recepției.']);
        }
        if ($factura->linii()->where('tip_linie', 'produs')->whereNull('produs_id')->exists()) {
            return back()->withErrors(['factura' => 'Importul nu poate fi finalizat: există poziții fără produs mapat.']);
        }

        $factura->update(['status' => 'import_finalizat']);

        return back()->with('status', "Importul facturii {$factura->numar_original} a fost finalizat.");
    }

    public function destroy(FacturaFurnizor $factura): RedirectResponse
    {
        if ($factura->receptie()->exists()) {
            return back()->withErrors(['factura' => 'Factura nu poate fi ștearsă deoarece are recepție.']);
        }

        $factura->load(['importFisier', 'exporturiSaga']);
        $filePaths = $factura->exporturiSaga
            ->pluck('cale_stocare')
            ->filter()
            ->push($factura->importFisier?->cale_stocare)
            ->filter()
            ->values()
            ->all();
        $invoiceNumber = $factura->numar_original;
        $import = $factura->importFisier;

        DB::transaction(function () use ($factura, $import): void {
            $factura->exporturiSaga()->delete();
            $factura->linii()->delete();
            $factura->delete();

            if ($import !== null && ! $import->facturi()->exists()) {
                $import->delete();
            }
        });

        Storage::disk('local')->delete($filePaths);

        return redirect()->route('facturi-furnizori.index')
            ->with('status', "Factura {$invoiceNumber} a fost ștearsă definitiv și poate fi reimportată.");
    }

    public function store(Request $request): RedirectResponse
    {
        $draft = $request->session()->get(self::SESSION_KEY);

        if (! is_array($draft) || ! hash_equals((string) ($draft['token'] ?? ''), (string) $request->input('token'))) {
            return redirect()->route('facturi-furnizori.index')
                ->withErrors(['factura_pdf' => 'Previzualizarea a expirat. Încarcă din nou factura.']);
        }

        $supplier = Furnizor::query()->where('cod_fiscal', $draft['invoice']['supplier_vat'])->first();
        $productRule = ($draft['tip_document'] ?? 'factura') === 'storno'
            ? Rule::exists('produse_furnizori', 'produs_id')->where('furnizor_id', $supplier?->id ?? 0)
            : Rule::exists('produse', 'id');
        $validated = $request->validate([
            'token' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.supplier_code' => ['required', 'string', 'max:100'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.amount' => ['required', 'decimal:0,2', 'min:0.01'],
            'lines.*.product_id' => ['nullable', 'integer', $productRule],
        ], [
            'lines.*.description.required' => 'Description of Goods este obligatorie pentru fiecare poziție.',
        ]);

        $invoice = $draft['invoice'];
        $submittedAmount = BigDecimal::zero();
        $submittedQuantity = 0;
        foreach ($validated['lines'] as $line) {
            $submittedAmount = $submittedAmount->plus($line['amount']);
            $submittedQuantity += (int) $line['quantity'];
        }

        if (! $submittedAmount->isEqualTo($invoice['total_amount']) || $submittedQuantity !== (int) $invoice['total_quantity']) {
            return back()->withInput()->withErrors([
                'lines' => 'Totalul liniilor trebuie să rămână '.number_format((float) $invoice['total_amount'], 2, ',', '.').' EUR și '.(int) $invoice['total_quantity'].' bucăți.',
            ]);
        }

        $temporaryPath = (string) $draft['temporary_path'];
        if (! Storage::disk('local')->exists($temporaryPath)) {
            return redirect()->route('facturi-furnizori.index')
                ->withErrors(['factura_pdf' => 'Fișierul temporar nu mai există. Încarcă din nou factura.']);
        }
        if (! hash_equals((string) $draft['hash'], hash_file('sha256', Storage::disk('local')->path($temporaryPath)))) {
            throw new RuntimeException('Fișierul temporar al facturii a fost modificat.');
        }

        $folderDocument = ($draft['tip_document'] ?? 'factura') === 'storno' ? 'storno' : 'facturi';
        $finalPath = "importuri/facturi-furnizori/{$folderDocument}/".date('Y').'/'.$draft['hash'].'.pdf';

        try {
            $factura = DB::transaction(function () use ($draft, $finalPath, $invoice, $temporaryPath, $validated): FacturaFurnizor {
                $supplier = Furnizor::query()->firstOrCreate(
                    ['cod_fiscal' => $invoice['supplier_vat']],
                    [
                        'denumire' => 'MOTO TREND S.A',
                        'tara' => 'GR',
                        'moneda_implicita' => 'EUR',
                        'configuratie_parser' => ['format' => 'moto_trend_pdf_v1'],
                        'activ' => true,
                    ],
                );

                $import = ImportFisier::query()->create([
                    'tip' => ($draft['tip_document'] ?? 'factura') === 'storno' ? 'storno_furnizor_moto_trend' : 'factura_furnizor_moto_trend',
                    'nume_fisier' => $draft['original_name'],
                    'hash_sha256' => $draft['hash'],
                    'cale_stocare' => $finalPath,
                    'status' => 'finalizat',
                    'rezultat' => [
                        'numar_linii' => count($validated['lines']),
                        'cantitate_totala' => $invoice['total_quantity'],
                        'valoare_totala' => $invoice['total_amount'],
                    ],
                ]);

                $hasUnmapped = collect($validated['lines'])->contains(
                    fn (array $line, int $index): bool => ($invoice['lines'][$index]['tip_linie'] ?? 'produs') === 'produs'
                        && empty($line['product_id'])
                );
                $factura = FacturaFurnizor::query()->create([
                    'furnizor_id' => $supplier->id,
                    'import_fisier_id' => $import->id,
                    'numar_original' => $invoice['invoice_number'],
                    'numar_normalizat' => $invoice['invoice_number'],
                    'data_factura' => $invoice['invoice_date'],
                    'data_scadenta' => null,
                    'moneda' => $invoice['currency'],
                    'total_fara_tva' => $invoice['total_amount'],
                    'total_tva' => '0.00',
                    'total_factura' => $invoice['total_amount'],
                    'taxare_inversa' => true,
                    'tip_document' => $draft['tip_document'] ?? 'factura',
                    'status' => $hasUnmapped ? 'import_partial' : 'import_finalizat',
                ]);

                foreach (array_values($validated['lines']) as $index => $line) {
                    $tipLinie = ($invoice['lines'][$index]['tip_linie'] ?? 'produs') === 'cost' ? 'cost' : 'produs';
                    $unitPrice = BigDecimal::of($line['amount'])
                        ->dividedBy((int) $line['quantity'], 4, RoundingMode::HalfUp)
                        ->__toString();
                    $priceObservation = null;
                    if ($tipLinie === 'produs' && $line['product_id'] && ($draft['tip_document'] ?? 'factura') !== 'storno') {
                        $product = Produs::query()->findOrFail($line['product_id']);
                        $proposedSalePrice = BigDecimal::of($unitPrice)
                            ->multipliedBy(self::SALE_PRICE_MULTIPLIER)
                            ->toScale(2, RoundingMode::HalfUp);
                        if ($product->pret_vanzare_cu_tva === null || $proposedSalePrice->isGreaterThan($product->pret_vanzare_cu_tva)) {
                            $priceObservation = 'Atenție la recepție: prețul de vânzare cu TVA trebuie actualizat la '.$proposedSalePrice->__toString().' RON.';
                        }
                    }

                    FacturaFurnizorLinie::query()->create([
                        'factura_id' => $factura->id,
                        'numar_linie' => $index + 1,
                        'tip_linie' => $tipLinie,
                        'produs_id' => $tipLinie === 'produs' ? ($line['product_id'] ?: null) : null,
                        'cod_furnizor' => mb_strtoupper(trim($line['supplier_code'])),
                        'descriere_originala' => trim($line['description']),
                        'cantitate' => (int) $line['quantity'],
                        'unitate_masura_originala' => 'BUC',
                        'amount_sursa' => $line['amount'],
                        'pret_unitar_calculat' => $unitPrice,
                        'cota_tva' => '0.00',
                        'valoare_tva' => '0.00',
                        'status_mapare' => $tipLinie === 'cost' ? 'cost' : ($line['product_id'] ? 'mapat' : 'necesita_mapare'),
                        'observatii' => $tipLinie === 'cost' ? 'Poziție de cost fără mișcare de stoc.' : ($line['product_id'] ? $priceObservation : (($draft['tip_document'] ?? 'factura') === 'storno'
                            ? 'Selectează un produs existent, deja mapat la furnizor.'
                            : 'Produsul necesită mapare manuală.')),
                    ]);

                    if ($tipLinie === 'produs' && $line['product_id'] && ($draft['tip_document'] ?? 'factura') !== 'storno') {
                        $mapping = ProdusFurnizor::query()->firstOrNew([
                            'furnizor_id' => $supplier->id,
                            'cod_furnizor' => mb_strtoupper(trim($line['supplier_code'])),
                        ]);
                        $draftProductId = $invoice['lines'][$index]['product_id'] ?? null;
                        $automaticCatalogMatch = (bool) ($invoice['lines'][$index]['auto_catalog_match'] ?? false)
                            && (int) $draftProductId === (int) $line['product_id'];
                        if (! $mapping->exists || (int) $mapping->produs_id !== (int) $line['product_id']) {
                            $mapping->produs_id = $line['product_id'];
                            $mapping->confirmata_manual = ! $automaticCatalogMatch;
                        }
                        $mapping->denumire_furnizor = trim($line['description']);
                        $mapping->moneda = $invoice['currency'];
                        $mapping->save();
                    }
                }

                if (! Storage::disk('local')->move($temporaryPath, $finalPath)) {
                    throw new RuntimeException('Factura nu a putut fi mutată în arhiva importurilor.');
                }

                return $factura;
            });
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors(['lines' => 'Importul nu a fost salvat: '.$exception->getMessage()]);
        }

        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('facturi-furnizori.index')
            ->with('status', ($factura->tip_document === 'storno' ? 'Factura storno' : 'Factura')." {$factura->numar_original} a fost importată cu {$factura->linii()->count()} poziții.");
    }

    public function cancel(Request $request): RedirectResponse
    {
        $draft = $request->session()->pull(self::SESSION_KEY);
        if (is_array($draft) && isset($draft['temporary_path'])) {
            Storage::disk('local')->delete((string) $draft['temporary_path']);
        }

        return redirect()->route('facturi-furnizori.index');
    }
}
