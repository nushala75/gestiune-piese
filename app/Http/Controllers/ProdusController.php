<?php

namespace App\Http\Controllers;

use App\Models\Categorie;
use App\Models\Gestiune;
use App\Models\Produs;
use App\Models\UnitateMasura;
use App\Services\CodFgoAllocator;
use App\Services\NecesarAprovizionareService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class ProdusController extends Controller
{
    public function create(): View
    {
        return view('produse.create', [
            'categorii' => Categorie::query()->where('activa', true)->orderBy('denumire')->get(),
            'unitatiMasura' => UnitateMasura::query()->where('activa', true)->orderBy('cod')->get(),
            'categorieImplicita' => Categorie::query()->where('denumire', 'Pe comanda')->value('id'),
        ]);
    }

    public function store(Request $request, CodFgoAllocator $codFgoAllocator, NecesarAprovizionareService $necesarAprovizionare): RedirectResponse
    {
        $date = $request->validate([
            'cod_produs' => ['required', 'string', 'max:64'],
            'denumire_engleza' => ['required', 'string', 'max:255'],
            'descriere_romana' => ['nullable', 'required_if:activ,1', 'string'],
            'categorie_id' => ['required', Rule::exists('categorii', 'id')->where('activa', true)],
            'unitate_masura_id' => ['required', Rule::exists('unitati_masura', 'id')->where('activa', true)],
            'marca' => ['nullable', 'string', 'max:100'],
            'stoc_minim' => ['required', 'integer', 'min:0'],
            'stoc' => ['required', 'integer', 'min:0'],
            'pret_vanzare_cu_tva' => ['nullable', 'required_if:activ,1', 'decimal:0,2', 'min:0'],
            'activ' => ['required', 'boolean'],
        ], [
            'denumire_engleza.required' => 'Description of Goods este obligatorie pentru salvarea produsului.',
            'descriere_romana.required_if' => 'Descrierea în română este obligatorie pentru activarea produsului.',
            'pret_vanzare_cu_tva.required_if' => 'Prețul de vânzare cu TVA este obligatoriu pentru activarea produsului.',
        ]);

        $gestiune = Gestiune::query()
            ->where('cod', 'FIRMA')
            ->whereHas('firma', fn (Builder $query) => $query->where('cod_fiscal', 'RO20548513'))
            ->sole();
        $pretCuTva = filled($date['pret_vanzare_cu_tva'] ?? null) ? $date['pret_vanzare_cu_tva'] : null;
        $pretFaraTva = $pretCuTva === null
            ? null
            : BigDecimal::of($pretCuTva)->dividedBy('1.21', 4, RoundingMode::HalfUp)->__toString();

        $produs = DB::transaction(function () use ($codFgoAllocator, $date, $gestiune, $necesarAprovizionare, $pretCuTva, $pretFaraTva): Produs {
            $produs = Produs::query()->create([
                'cod_fgo' => $codFgoAllocator->aloca(),
                'cod_produs' => mb_strtoupper(trim($date['cod_produs'])),
                'denumire_engleza' => mb_strtoupper(trim($date['denumire_engleza'])),
                'descriere_romana' => filled($date['descriere_romana'] ?? null) ? trim($date['descriere_romana']) : null,
                'categorie_id' => $date['categorie_id'],
                'unitate_masura_id' => $date['unitate_masura_id'],
                'marca' => filled($date['marca'] ?? null) ? mb_strtoupper(trim($date['marca'])) : 'KYMCO',
                'stoc_minim' => $date['stoc_minim'],
                'cantitate_de_comandat' => 0,
                'furnizor_comanda_id' => null,
                'furnizor_comanda_manual' => false,
                'pret_vanzare_fara_tva' => $pretFaraTva,
                'pret_vanzare_cu_tva' => $pretCuTva,
                'cota_tva' => '21.00',
                'voluminos' => false,
                'activ' => $date['activ'],
                'sursa' => 'manual',
            ]);

            DB::table('solduri_stoc')->insert([
                'gestiune_id' => $gestiune->id,
                'produs_id' => $produs->id,
                'cantitate_fizica' => $date['stoc'],
                'cantitate_rezervata' => 0,
                'updated_at' => now(),
            ]);
            $necesarAprovizionare->sincronizeaza($produs, $gestiune);

            return $produs;
        });

        return redirect()->route('produse.edit-detalii', $produs)
            ->with('status', "Produsul {$produs->cod_produs} a fost adăugat cu codul FGO {$produs->cod_fgo}.");
    }

    public function index(Request $request): View
    {
        $cautare = trim((string) $request->query('q', ''));
        $filtre = $request->validate([
            'categorie' => ['nullable', 'integer', Rule::exists('categorii', 'id')],
            'stoc' => ['nullable', Rule::in(['toate', 'pozitiv', 'zero', 'negativ'])],
        ]);
        $categorieSelectata = isset($filtre['categorie']) ? (int) $filtre['categorie'] : null;
        $filtruStoc = (string) ($filtre['stoc'] ?? 'toate');
        $gestiune = Gestiune::query()
            ->where('cod', 'FIRMA')
            ->whereHas('firma', fn (Builder $query) => $query->where('cod_fiscal', 'RO20548513'))
            ->sole();

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
            ->when($categorieSelectata !== null, fn (Builder $query) => $query->where('categorie_id', $categorieSelectata))
            ->when($filtruStoc === 'pozitiv', fn (Builder $query) => $query->whereHas(
                'solduriStoc',
                fn (Builder $soldQuery) => $soldQuery->where('gestiune_id', $gestiune->id)->where('cantitate_fizica', '>', 0),
            ))
            ->when($filtruStoc === 'negativ', fn (Builder $query) => $query->whereHas(
                'solduriStoc',
                fn (Builder $soldQuery) => $soldQuery->where('gestiune_id', $gestiune->id)->where('cantitate_fizica', '<', 0),
            ))
            ->when($filtruStoc === 'zero', fn (Builder $query) => $query->where(function (Builder $stockQuery) use ($gestiune): void {
                $stockQuery
                    ->whereHas('solduriStoc', fn (Builder $soldQuery) => $soldQuery
                        ->where('gestiune_id', $gestiune->id)
                        ->where('cantitate_fizica', 0))
                    ->orWhereDoesntHave('solduriStoc', fn (Builder $soldQuery) => $soldQuery
                        ->where('gestiune_id', $gestiune->id));
            }))
            ->orderBy('cod_produs')
            ->paginate(25)
            ->withQueryString();

        $categorii = Categorie::query()->orderBy('denumire')->get(['id', 'denumire']);

        return view('produse.index', compact('produse', 'cautare', 'categorii', 'categorieSelectata', 'filtruStoc'));
    }

    public function updateRapid(Request $request, Produs $produs, NecesarAprovizionareService $necesarAprovizionare): RedirectResponse
    {
        $date = $request->validate([
            'stoc' => ['required', 'integer', 'min:0'],
            'pret_vanzare_cu_tva' => ['required', 'decimal:0,2', 'min:0'],
            'cantitate_de_comandat' => ['nullable', 'integer', 'min:0'],
            'furnizor_comanda_id' => [
                'nullable',
                'integer',
                Rule::exists('produse_furnizori', 'furnizor_id')->where('produs_id', $produs->id),
            ],
        ]);

        $gestiune = Gestiune::query()
            ->where('cod', 'FIRMA')
            ->whereHas('firma', fn (Builder $query) => $query->where('cod_fiscal', 'RO20548513'))
            ->sole();

        $pretFaraTva = BigDecimal::of($date['pret_vanzare_cu_tva'])
            ->dividedBy('1.21', 4, RoundingMode::HalfUp)
            ->__toString();

        DB::transaction(function () use ($date, $gestiune, $necesarAprovizionare, $pretFaraTva, $produs): void {
            $actualizariProdus = [
                'pret_vanzare_cu_tva' => $date['pret_vanzare_cu_tva'],
                'pret_vanzare_fara_tva' => $pretFaraTva,
            ];
            if (array_key_exists('cantitate_de_comandat', $date)) {
                $actualizariProdus['cantitate_de_comandat'] = $date['cantitate_de_comandat'];
            }
            if (array_key_exists('furnizor_comanda_id', $date)) {
                $actualizariProdus['furnizor_comanda_id'] = $date['furnizor_comanda_id'];
                $actualizariProdus['furnizor_comanda_manual'] = $date['furnizor_comanda_id'] !== null;
            }
            $produs->update($actualizariProdus);

            DB::table('solduri_stoc')->updateOrInsert(
                ['gestiune_id' => $gestiune->id, 'produs_id' => $produs->id],
                [
                    'cantitate_fizica' => $date['stoc'],
                    'updated_at' => now(),
                ],
            );

            $necesarAprovizionare->sincronizeaza($produs, $gestiune);
        });

        return back()->with('status', "Produsul {$produs->cod_produs} a fost actualizat.");
    }

    public function editDetalii(Produs $produs): View
    {
        $produs->load(['furnizori.furnizor', 'solduriStoc.gestiune']);
        $mapareFurnizor = $produs->furnizori
            ->sortByDesc(fn ($mapare) => ($mapare->data_ultimei_achizitii?->format('Y-m-d') ?? '').'-'.str_pad((string) $mapare->id, 20, '0', STR_PAD_LEFT))
            ->first();
        $soldFirma = $produs->solduriStoc->first(fn ($sold) => $sold->gestiune?->cod === 'FIRMA');

        return view('produse.edit-detalii', [
            'produs' => $produs,
            'mapareFurnizor' => $mapareFurnizor,
            'stoc' => (int) ($soldFirma?->cantitate_fizica ?? 0),
            'categorii' => Categorie::query()->where('activa', true)->orderBy('denumire')->get(),
            'unitatiMasura' => UnitateMasura::query()->where('activa', true)->orderBy('cod')->get(),
        ]);
    }

    public function updateDetalii(Request $request, Produs $produs, NecesarAprovizionareService $necesarAprovizionare): RedirectResponse
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
            'cod_produs' => [
                'required',
                'string',
                'max:64',
            ],
            'denumire_engleza' => ['required', 'string', 'max:255'],
            'descriere_romana' => ['nullable', 'string'],
            'categorie_id' => ['required', Rule::exists('categorii', 'id')->where('activa', true)],
            'unitate_masura_id' => ['required', Rule::exists('unitati_masura', 'id')->where('activa', true)],
            'marca' => ['nullable', 'string', 'max:100'],
            'stoc_minim' => ['required', 'integer', 'min:0'],
            'stoc' => ['required', 'integer', 'min:0'],
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
                'descriere_romana' => filled($date['descriere_romana'] ?? null)
                    ? trim($date['descriere_romana'])
                    : null,
                'categorie_id' => $date['categorie_id'],
                'unitate_masura_id' => $date['unitate_masura_id'],
                'marca' => filled($date['marca'] ?? null) ? mb_strtoupper(trim($date['marca'])) : null,
                'stoc_minim' => $date['stoc_minim'],
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

    public function destroy(Produs $produs): RedirectResponse
    {
        $transactionalTables = [
            'facturi_furnizor_linii',
            'receptii_linii',
            'miscari_stoc',
            'exporturi_fgo_stoc_linii',
        ];

        foreach ($transactionalTables as $table) {
            if (Schema::hasTable($table) && DB::table($table)->where('produs_id', $produs->id)->exists()) {
                return back()->withErrors([
                    'produs' => "Produsul {$produs->cod_produs} nu poate fi șters deoarece are istoric tranzacțional.",
                ]);
            }
        }

        $code = $produs->cod_produs;
        DB::transaction(function () use ($produs): void {
            $produs->solduriStoc()->delete();
            $produs->furnizori()->delete();
            $produs->delete();
        });

        return back()->with('status', "Produsul {$code} a fost șters definitiv.");
    }
}
