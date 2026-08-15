<?php

namespace App\Http\Controllers;

use App\Models\FacturaFurnizor;
use App\Models\Gestiune;
use App\Models\MiscareStoc;
use App\Models\ProdusFurnizor;
use App\Models\Receptie;
use App\Models\ReceptieLinie;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ReceptieController extends Controller
{
    public function create(FacturaFurnizor $factura): View|RedirectResponse
    {
        $factura->load(['furnizor', 'linii.produs', 'receptie']);

        if ($factura->receptie !== null) {
            return redirect()->route('facturi-furnizori.show', $factura)
                ->withErrors(['receptie' => 'Factura are deja o recepție definitivă.']);
        }
        if ($factura->status !== 'import_finalizat') {
            return redirect()->route('facturi-furnizori.show', $factura)
                ->withErrors(['receptie' => 'Finalizează mai întâi importul și maparea tuturor produselor.']);
        }
        if ($factura->linii->contains(fn ($linie): bool => $linie->produs_id === null)) {
            return redirect()->route('facturi-furnizori.show', $factura)
                ->withErrors(['receptie' => 'Recepția integrală nu poate fi creată: există poziții fără produs mapat.']);
        }

        $gestiune = $this->gestiuneFirma();
        $avertismenteStoc = collect();
        if ($factura->tip_document === 'storno') {
            $cantitatiPeProdus = $factura->linii
                ->groupBy('produs_id')
                ->map(fn ($linii): int => (int) $linii->sum('cantitate'));
            $solduri = DB::table('solduri_stoc')
                ->where('gestiune_id', $gestiune->id)
                ->whereIn('produs_id', $cantitatiPeProdus->keys())
                ->pluck('cantitate_fizica', 'produs_id');

            $avertismenteStoc = $cantitatiPeProdus
                ->map(function (int $cantitate, int|string $produsId) use ($factura, $solduri): ?array {
                    $stocCurent = (int) ($solduri[$produsId] ?? 0);
                    $stocDupa = $stocCurent - $cantitate;
                    if ($stocDupa >= 0) {
                        return null;
                    }

                    $produs = $factura->linii->firstWhere('produs_id', (int) $produsId)?->produs;

                    return [
                        'produs' => $produs?->cod_produs.' '.$produs?->denumire_engleza,
                        'stoc_curent' => $stocCurent,
                        'cantitate_storno' => $cantitate,
                        'stoc_dupa' => $stocDupa,
                    ];
                })
                ->filter()
                ->values();
        }

        return view('receptii.create', compact('factura', 'avertismenteStoc'));
    }

    public function store(Request $request, FacturaFurnizor $factura): RedirectResponse
    {
        $date = $request->validate([
            'data_receptie' => ['required', 'date'],
            'confirmare_saga' => ['accepted'],
        ], [
            'confirmare_saga.accepted' => 'Confirmarea manuală a importului în SAGA este obligatorie.',
        ]);

        DB::transaction(function () use ($date, $factura): void {
            $facturaBlocata = FacturaFurnizor::query()
                ->lockForUpdate()
                ->findOrFail($factura->id);
            $facturaBlocata->load('linii');

            if ($facturaBlocata->receptie()->exists()) {
                throw ValidationException::withMessages([
                    'receptie' => 'Factura are deja o recepție definitivă. Stocul nu a fost modificat din nou.',
                ]);
            }
            if ($facturaBlocata->status !== 'import_finalizat') {
                throw ValidationException::withMessages([
                    'receptie' => 'Recepția poate fi făcută numai după finalizarea importului.',
                ]);
            }
            if ($facturaBlocata->linii->isEmpty() || $facturaBlocata->linii->contains(fn ($linie): bool => $linie->produs_id === null)) {
                throw ValidationException::withMessages([
                    'receptie' => 'Recepția integrală necesită cel puțin o poziție și toate produsele mapate.',
                ]);
            }

            $gestiune = $this->gestiuneFirma();
            $esteStorno = $facturaBlocata->tip_document === 'storno';
            $receptie = Receptie::query()->create([
                'factura_id' => $facturaBlocata->id,
                'gestiune_id' => $gestiune->id,
                'data_receptie' => $date['data_receptie'].' 00:00:00',
                'status' => 'finalizata',
            ]);

            foreach ($facturaBlocata->linii as $linieFactura) {
                $costUnitar = BigDecimal::of($linieFactura->pret_unitar_calculat)
                    ->toScale(4, RoundingMode::HalfUp)
                    ->__toString();

                $cantitateMiscare = $esteStorno ? -$linieFactura->cantitate : $linieFactura->cantitate;
                $linieReceptie = ReceptieLinie::query()->create([
                    'receptie_id' => $receptie->id,
                    'factura_linie_id' => $linieFactura->id,
                    'produs_id' => $linieFactura->produs_id,
                    'cantitate' => $linieFactura->cantitate,
                    'cost_unitar' => $costUnitar,
                    'valoare' => $linieFactura->amount_sursa,
                ]);

                MiscareStoc::query()->create([
                    'gestiune_id' => $gestiune->id,
                    'produs_id' => $linieFactura->produs_id,
                    'tip' => $esteStorno ? 'iesire_storno' : 'intrare_receptie',
                    'cantitate' => $cantitateMiscare,
                    'cost_unitar' => $costUnitar,
                    'receptie_linie_id' => $linieReceptie->id,
                    'referinta_tip' => 'factura_furnizor',
                    'referinta_id' => $facturaBlocata->id,
                    'explicatie' => ($esteStorno ? 'Recepție storno' : 'Recepție integrală')." factura {$facturaBlocata->numar_original}",
                ]);

                $sold = DB::table('solduri_stoc')
                    ->where('gestiune_id', $gestiune->id)
                    ->where('produs_id', $linieFactura->produs_id)
                    ->lockForUpdate()
                    ->first();

                if ($sold === null) {
                    DB::table('solduri_stoc')->insert([
                        'gestiune_id' => $gestiune->id,
                        'produs_id' => $linieFactura->produs_id,
                        'cantitate_fizica' => $cantitateMiscare,
                        'cantitate_rezervata' => 0,
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('solduri_stoc')
                        ->where('gestiune_id', $gestiune->id)
                        ->where('produs_id', $linieFactura->produs_id)
                        ->update([
                            'cantitate_fizica' => (int) $sold->cantitate_fizica + $cantitateMiscare,
                            'updated_at' => now(),
                        ]);
                }

                if (! $esteStorno) {
                    ProdusFurnizor::query()->updateOrCreate(
                        [
                            'furnizor_id' => $facturaBlocata->furnizor_id,
                            'cod_furnizor' => $linieFactura->cod_furnizor,
                        ],
                        [
                            'produs_id' => $linieFactura->produs_id,
                            'denumire_furnizor' => $linieFactura->descriere_originala,
                            'pret_achizitie_ultim' => $costUnitar,
                            'moneda' => $facturaBlocata->moneda,
                            'data_ultimei_achizitii' => $facturaBlocata->data_factura,
                        ]
                    );
                }
            }
        });

        return redirect()->route('facturi-furnizori.show', $factura)
            ->with('status', ($factura->tip_document === 'storno' ? 'Recepția storno' : 'Recepția integrală')." a facturii {$factura->numar_original} a fost finalizată definitiv.");
    }

    private function gestiuneFirma(): Gestiune
    {
        return Gestiune::query()
            ->where('cod', 'FIRMA')
            ->whereHas('firma', fn (Builder $query) => $query->where('cod_fiscal', 'RO20548513'))
            ->sole();
    }
}
