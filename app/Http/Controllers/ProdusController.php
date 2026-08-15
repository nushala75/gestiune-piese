<?php

namespace App\Http\Controllers;

use App\Models\Produs;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ProdusController extends Controller
{
    public function index(Request $request): View
    {
        $cautare = trim((string) $request->query('q', ''));

        $produse = Produs::query()
            ->with(['categorie', 'unitateMasura', 'furnizori.furnizor', 'solduriStoc'])
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
}
