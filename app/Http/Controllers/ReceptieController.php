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

        $this->gestiuneFirma();

        return view('receptii.create', compact('factura'));
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
                    'tip' => 'intrare_receptie',
                    'cantitate' => $linieFactura->cantitate,
                    'cost_unitar' => $costUnitar,
                    'receptie_linie_id' => $linieReceptie->id,
                    'referinta_tip' => 'factura_furnizor',
                    'referinta_id' => $facturaBlocata->id,
                    'explicatie' => "Recepție integrală factura {$facturaBlocata->numar_original}",
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
                        'cantitate_fizica' => $linieFactura->cantitate,
                        'cantitate_rezervata' => 0,
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('solduri_stoc')
                        ->where('gestiune_id', $gestiune->id)
                        ->where('produs_id', $linieFactura->produs_id)
                        ->update([
                            'cantitate_fizica' => (int) $sold->cantitate_fizica + $linieFactura->cantitate,
                            'updated_at' => now(),
                        ]);
                }

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
        });

        return redirect()->route('facturi-furnizori.show', $factura)
            ->with('status', "Recepția integrală a facturii {$factura->numar_original} a fost finalizată definitiv.");
    }

    private function gestiuneFirma(): Gestiune
    {
        return Gestiune::query()
            ->where('cod', 'FIRMA')
            ->whereHas('firma', fn (Builder $query) => $query->where('cod_fiscal', 'RO20548513'))
            ->sole();
    }
}
