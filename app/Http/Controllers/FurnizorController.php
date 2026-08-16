<?php

namespace App\Http\Controllers;

use App\Models\Furnizor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FurnizorController extends Controller
{
    public function index(): View
    {
        return view('furnizori.index', [
            'furnizori' => Furnizor::query()
                ->withCount(['produse', 'facturi'])
                ->orderByDesc('activ')
                ->orderBy('denumire')
                ->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('furnizori.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $furnizor = Furnizor::query()->create($this->validatedData($request));

        return redirect()->route('furnizori.index')
            ->with('status', "Furnizorul {$furnizor->denumire} a fost adăugat.");
    }

    public function edit(Furnizor $furnizor): View
    {
        return view('furnizori.edit', compact('furnizor'));
    }

    public function update(Request $request, Furnizor $furnizor): RedirectResponse
    {
        $furnizor->update($this->validatedData($request, $furnizor));

        return redirect()->route('furnizori.index')
            ->with('status', "Furnizorul {$furnizor->denumire} a fost actualizat.");
    }

    /** @return array<string, mixed> */
    private function validatedData(Request $request, ?Furnizor $furnizor = null): array
    {
        $data = $request->validate([
            'denumire' => ['required', 'string', 'max:190'],
            'cod_fiscal' => [
                'required',
                'string',
                'max:32',
                Rule::unique('furnizori', 'cod_fiscal')->ignore($furnizor),
            ],
            'tara' => ['required', 'string', 'size:2'],
            'adresa' => ['nullable', 'string', 'max:500'],
            'moneda_implicita' => ['required', 'string', 'size:3'],
            'activ' => ['required', 'boolean'],
        ]);

        return [
            'denumire' => trim($data['denumire']),
            'cod_fiscal' => mb_strtoupper(trim($data['cod_fiscal'])),
            'tara' => mb_strtoupper(trim($data['tara'])),
            'adresa' => filled($data['adresa'] ?? null) ? trim($data['adresa']) : null,
            'moneda_implicita' => mb_strtoupper(trim($data['moneda_implicita'])),
            'activ' => (bool) $data['activ'],
        ];
    }
}
