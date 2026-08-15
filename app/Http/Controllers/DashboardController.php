<?php

namespace App\Http\Controllers;

use App\Models\Produs;
use App\Models\SoldStoc;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $schemaDisponibila = Schema::hasTable('produse') && Schema::hasTable('solduri_stoc');

        return view('dashboard', [
            'schemaDisponibila' => $schemaDisponibila,
            'produseActive' => $schemaDisponibila ? Produs::query()->where('activ', true)->count() : 0,
            'produseInStoc' => $schemaDisponibila ? SoldStoc::query()->where('cantitate_fizica', '>', 0)->count() : 0,
            'unitatiInStoc' => $schemaDisponibila ? SoldStoc::query()->sum('cantitate_fizica') : 0,
        ]);
    }
}
