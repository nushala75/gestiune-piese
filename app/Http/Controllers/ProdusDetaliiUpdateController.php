<?php

namespace App\Http\Controllers;

use App\Models\Gestiune;
use App\Models\Produs;
use App\Services\NecesarAprovizionareService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProdusDetaliiUpdateController extends Controller
{
    public function __invoke(Request $request, Produs $produs, NecesarAprovizionareService $necesarAprovizionare): RedirectResponse
    {
        $mapareFurnizor = $produs->furnizori()
            ->orderByDesc('data_ultimei_achizitii')
            ->orderByDesc('id')
            ->first();

        $date = $request->validate([
            'cod_fgo' => [
                'required',
                'digits:8',
                Rule::unique('produse', 'cod_fgo')->ignore($produs),
            ],
            'cod_produs' => ['required', 'string', 'max:64'],
            'denumire_engleza' => ['required', 'string', 'max:255'],
            'descriere_romana' => ['nullable', 'string'],
            'categorie_id' => ['required', Rule::exists('categorii', 'id')->where('activa', true)],
            'unitate_masura_id' => ['required', Rule::exists('unitati_masura', 'id')->where('activa', true)],
            'marca' => ['nullable', 'string', 'max:100'],
            'stoc_minim' => ['required', 'integer', 'min:0'],
            'stoc' => ['required', 'integer', 'min:0'],
            'cantitate_de_comandat' => ['required', 'integer', 'min:0'],
            'furnizor_comanda_id' => [
                'nullable',
                'integer',
                Rule::exists('produse_furnizori', 'furnizor_id')->where('produs_id', $produs->id),
            ],
            'pret_intrare' => $mapareFurnizor === null
                ? ['nullable']
                : ['required', 'decimal:0,4', 'min:0'],
            'pret_vanzare_cu_tva' => ['required', 'decimal:0,2', 'min:0'],
            'cota_tva' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
            'greutate_kg' => ['nullable', 'decimal:0,3', 'min:0'],
            'voluminos' => ['required', 'boolean'],
            'lungime_cm' => ['nullable', 'required_if:voluminos,1', 'decimal:0,2', 'min:0'],
            'latime_cm' => ['nullable', 'required_if:voluminos,1', 'decimal:0,2', 'min:0'],
            'inaltime_cm' => ['nullable', 'required_if:voluminos,1', 'decimal:0,2', 'min:0'],
            'activ' => ['required', 'boolean'],
        ], [
            'cod_fgo.required' => 'Codul FGO este obligatoriu.',
            'cod_fgo.digits' => 'Codul FGO trebuie să conțină exact 8 cifre.',
            'cod_fgo.unique' => 'Codul FGO este deja folosit de alt produs.',
            'denumire_engleza.required' => 'Description of Goods este obligatorie pentru salvarea produsului.',
            'furnizor_comanda_id.exists' => 'Furnizorul selectat nu este mapat acestui produs.',
        ]);

        $gestiune = Gestiune::query()
            ->where('cod', 'FIRMA')
            ->whereHas('firma', fn (Builder $query) => $query->where('cod_fiscal', 'RO20548513'))
            ->sole();

        $pretFaraTva = BigDecimal::of($date['pret_vanzare_cu_tva'])
            ->dividedBy('1.21', 4, RoundingMode::HalfUp)
            ->__toString();

        DB::transaction(function () use ($date, $gestiune, $mapareFurnizor, $necesarAprovizionare, $pretFaraTva, $produs): void {
            $produs->update([
                'cod_fgo' => trim($date['cod_fgo']),
                'cod_produs' => mb_strtoupper(trim($date['cod_produs'])),
                'denumire_engleza' => mb_strtoupper(trim($date['denumire_engleza'])),
                'descriere_romana' => filled($date['descriere_romana'] ?? null) ? trim($date['descriere_romana']) : null,
                'categorie_id' => $date['categorie_id'],
                'unitate_masura_id' => $date['unitate_masura_id'],
                'marca' => filled($date['marca'] ?? null) ? mb_strtoupper(trim($date['marca'])) : null,
                'stoc_minim' => $date['stoc_minim'],
                'cantitate_de_comandat' => $date['cantitate_de_comandat'],
                'furnizor_comanda_id' => $date['furnizor_comanda_id'] ?? null,
                'furnizor_comanda_manual' => ($date['furnizor_comanda_id'] ?? null) !== null,
                'pret_vanzare_fara_tva' => $pretFaraTva,
                'pret_vanzare_cu_tva' => $date['pret_vanzare_cu_tva'],
                'cota_tva' => $date['cota_tva'],
                'greutate_kg' => $date['greutate_kg'],
                'voluminos' => $date['voluminos'],
                'lungime_cm' => $date['lungime_cm'],
                'latime_cm' => $date['latime_cm'],
                'inaltime_cm' => $date['inaltime_cm'],
                'activ' => $date['activ'],
            ]);

            if ($mapareFurnizor !== null) {
                $mapareFurnizor->update(['pret_achizitie_ultim' => $date['pret_intrare']]);
            }

            DB::table('solduri_stoc')->updateOrInsert(
                ['gestiune_id' => $gestiune->id, 'produs_id' => $produs->id],
                [
                    'cantitate_fizica' => $date['stoc'],
                    'updated_at' => now(),
                ],
            );

            $necesarAprovizionare->sincronizeaza($produs, $gestiune);
        });

        return redirect()
            ->route('produse.edit-detalii', $produs)
            ->with('status', "Detaliile produsului {$produs->cod_produs} au fost actualizate.");
    }
}
