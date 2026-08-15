<?php

namespace App\Http\Controllers;

use App\Models\FacturaFurnizor;
use App\Models\FacturaFurnizorLinie;
use App\Models\Furnizor;
use App\Models\ImportFisier;
use App\Models\Produs;
use App\Services\MotoTrendInvoiceParser;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\View\View;
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
            ->with(['furnizor', 'linii'])
            ->latest('id')
            ->paginate(20);

        return view('facturi-furnizori.index', compact('facturi'));
    }

    public function upload(Request $request, MotoTrendInvoiceParser $parser): RedirectResponse
    {
        $request->validate([
            'factura_pdf' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        $file = $request->file('factura_pdf');
        $hash = hash_file('sha256', $file->getRealPath());
        if ($hash === false) {
            return back()->withErrors(['factura_pdf' => 'Amprenta fișierului PDF nu a putut fi calculată.']);
        }

        if (ImportFisier::query()->where('tip', 'factura_furnizor_moto_trend')->where('hash_sha256', $hash)->exists()) {
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

        foreach ($invoice['lines'] as &$line) {
            $mapping = $mappings->get(mb_strtoupper((string) $line['supplier_code']));
            $line['product_id'] = $mapping?->produs_id;
            $line['product_label'] = $mapping?->produs
                ? $mapping->produs->cod_produs.' '.$mapping->produs->denumire_engleza
                : null;
            $line['current_sale_price'] = $mapping?->produs?->pret_vanzare_cu_tva;
            $line['proposed_sale_price'] = $line['unit_price'] !== ''
                ? BigDecimal::of($line['unit_price'])->multipliedBy(self::SALE_PRICE_MULTIPLIER)->toScale(2, RoundingMode::HalfUp)->__toString()
                : null;
            $line['price_warning'] = $mapping?->produs !== null
                && ($line['current_sale_price'] === null
                    || BigDecimal::of($line['proposed_sale_price'])->isGreaterThan($line['current_sale_price']));
        }
        unset($line);

        $draft = [
            'token' => $token,
            'temporary_path' => $temporaryPath,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'hash' => $hash,
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

        return view('facturi-furnizori.preview', [
            'draft' => $draft,
            'produse' => Produs::query()->orderBy('cod_produs')->get(['id', 'cod_produs', 'denumire_engleza']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $draft = $request->session()->get(self::SESSION_KEY);

        if (! is_array($draft) || ! hash_equals((string) ($draft['token'] ?? ''), (string) $request->input('token'))) {
            return redirect()->route('facturi-furnizori.index')
                ->withErrors(['factura_pdf' => 'Previzualizarea a expirat. Încarcă din nou factura.']);
        }

        $validated = $request->validate([
            'token' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.supplier_code' => ['required', 'string', 'max:100', 'regex:/^[A-Z0-9]+(?:-[A-Z0-9]+)+$/i'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.amount' => ['required', 'decimal:0,2', 'min:0.01'],
            'lines.*.product_id' => ['nullable', 'integer', Rule::exists('produse', 'id')],
        ], [
            'lines.*.supplier_code.regex' => 'Un cod de produs nu respectă structura MOTO TREND.',
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

        $finalPath = 'importuri/facturi-furnizori/'.date('Y').'/'.$draft['hash'].'.pdf';

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
                    'tip' => 'factura_furnizor_moto_trend',
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

                $hasUnmapped = collect($validated['lines'])->contains(fn (array $line): bool => empty($line['product_id']));
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
                    'status' => $hasUnmapped ? 'necesita_mapare' : 'importata',
                ]);

                foreach (array_values($validated['lines']) as $index => $line) {
                    $unitPrice = BigDecimal::of($line['amount'])
                        ->dividedBy((int) $line['quantity'], 4, RoundingMode::HalfUp)
                        ->__toString();
                    $priceObservation = null;
                    if ($line['product_id']) {
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
                        'produs_id' => $line['product_id'] ?: null,
                        'cod_furnizor' => mb_strtoupper(trim($line['supplier_code'])),
                        'descriere_originala' => trim($line['description']),
                        'cantitate' => (int) $line['quantity'],
                        'unitate_masura_originala' => 'BUC',
                        'amount_sursa' => $line['amount'],
                        'pret_unitar_calculat' => $unitPrice,
                        'cota_tva' => '0.00',
                        'valoare_tva' => '0.00',
                        'status_mapare' => $line['product_id'] ? 'mapat' : 'necesita_mapare',
                        'observatii' => $line['product_id'] ? $priceObservation : 'Produsul necesită mapare manuală.',
                    ]);
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
            ->with('status', "Factura {$factura->numar_original} a fost importată cu {$factura->linii()->count()} poziții.");
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
