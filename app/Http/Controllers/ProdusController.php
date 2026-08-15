<?php

namespace App\Http\Controllers;

use App\Models\Categorie;
use App\Models\Gestiune;
use App\Models\Produs;
use App\Models\UnitateMasura;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProdusController extends Controller
{
    public function index(Request $request): View
    {
        $cautare = trim((string) $request->query('q', ''));

        $produse = Produs::query()
            ->with(['categorie', 'unitateMasura', 'furnizori.furnizor', 'solduriStoc.gestiune'])
            ->when($cautare !== '', function (Builder $query) use ($cautare): void {
                $query->where(function (Builder $query) use ($cautare): void {
                    $query
                        ->where('cod_fgo', 'like', "%{$cautare}%")
                        ->orWhere('cod_produs', 'like', "%{$cautare}%")
                        ->orWhere('denumire_engleza', 'like', "%{$cautare}%")
                        ->orWhere('descriere_romana', 'like', "%{$cautare}%");
                });
            })
            ->orderBy('cod_produs')
            ->paginate(25)
            ->withQueryString();

        return view('produse.index', compact('produse', 'cautare'));
    }

    public function updateRapid(Request $request, Produs $produs): RedirectResponse
    {
        $mapareFurnizor = $produs->furnizori()
            ->orderByDesc('data_ultimei_achizitii')
            ->orderByDesc('id')
            ->first();

        $reguliPretIntrare = $mapareFurnizor === null
            ? ['nullable']
            : ['required', 'decimal:0,4', 'min:0'];

        $date = $request->validate([
            'denumire_engleza' => ['required', 'string', 'max:255'],
            'descriere_romana' => ['nullable', 'string'],
            'stoc' => ['required', 'integer', 'min:0'],
            'pret_intrare' => $reguliPretIntrare,
            'pret_vanzare_cu_tva' => ['required', 'decimal:0,2', 'min:0'],
        ]);

        $gestiune = Gestiune::query()
            ->where('cod', 'FIRMA')
            ->whereHas('firma', fn (Builder $query) => $query->where('cod_fiscal', 'RO20548513'))
            ->sole();

        $pretFaraTva = BigDecimal::of($date['pret_vanzare_cu_tva'])
            ->dividedBy('1.21', 4, RoundingMode::HalfUp)
            ->__toString();

        DB::transaction(function () use ($date, $gestiune, $mapareFurnizor, $pretFaraTva, $produs): void {
            $produs->update([
                'denumire_engleza' => mb_strtoupper(trim($date['denumire_engleza'])),
                'descriere_romana' => filled($date['descriere_romana'] ?? null)
                    ? trim($date['descriere_romana'])
                    : null,
                'pret_vanzare_cu_tva' => $date['pret_vanzare_cu_tva'],
                'pret_vanzare_fara_tva' => $pretFaraTva,
            ]);

            if ($mapareFurnizor !== null) {
                $mapareFurnizor->update(['pret_achizitie_ultim' => $date['pret_intrare']]);
            }

            DB::table('solduri_stoc')->updateOrInsert(
                ['gestiune_id' => $gestiune->id, 'produs_id' => $produs->id],
                [
                    'cantitate_fizica' => $date['stoc'],
                    'cantitate_rezervata' => 0,
                    'updated_at' => now(),
                ],
            );
        });

        return back()->with('status', "Produsul {$produs->cod_produs} a fost actualizat.");
    }

    public function editDetalii(Produs $produs): View
    {
        return view('produse.edit-detalii', [
            'produs' => $produs,
            'categorii' => Categorie::query()->where('activa', true)->orderBy('denumire')->get(),
            'unitatiMasura' => UnitateMasura::query()->where('activa', true)->orderBy('cod')->get(),
        ]);
    }

    public function updateDetalii(Request $request, Produs $produs): RedirectResponse
    {
        $date = $request->validate([
            'cod_produs' => [
                'required',
                'string',
                'max:64',
                Rule::unique('produse', 'cod_produs')->ignore($produs->id),
            ],
            'categorie_id' => ['required', Rule::exists('categorii', 'id')->where('activa', true)],
            'unitate_masura_id' => ['required', Rule::exists('unitati_masura', 'id')->where('activa', true)],
            'marca' => ['nullable', 'string', 'max:100'],
            'stoc_minim' => ['required', 'integer', 'min:0'],
            'cota_tva' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
            'greutate_kg' => ['nullable', 'decimal:0,3', 'min:0'],
            'voluminos' => ['required', 'boolean'],
            'lungime_cm' => ['nullable', 'required_if:voluminos,1', 'decimal:0,2', 'min:0'],
            'latime_cm' => ['nullable', 'required_if:voluminos,1', 'decimal:0,2', 'min:0'],
            'inaltime_cm' => ['nullable', 'required_if:voluminos,1', 'decimal:0,2', 'min:0'],
            'activ' => ['required', 'boolean'],
        ]);

        $produs->update([
            ...$date,
            'cod_produs' => mb_strtoupper(trim($date['cod_produs'])),
            'marca' => filled($date['marca'] ?? null) ? mb_strtoupper(trim($date['marca'])) : null,
        ]);

        return redirect()
            ->route('produse.edit-detalii', $produs)
            ->with('status', "Detaliile produsului {$produs->cod_produs} au fost actualizate.");
    }
}
