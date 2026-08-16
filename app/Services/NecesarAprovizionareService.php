<?php

namespace App\Services;

use App\Models\Gestiune;
use App\Models\Produs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class NecesarAprovizionareService
{
    public function sincronizeaza(Produs $produs, ?Gestiune $gestiune = null): void
    {
        $gestiune ??= Gestiune::query()
            ->where('cod', 'FIRMA')
            ->whereHas('firma', fn (Builder $query) => $query->where('cod_fiscal', 'RO20548513'))
            ->sole();

        $stoc = (int) (DB::table('solduri_stoc')
            ->where('gestiune_id', $gestiune->id)
            ->where('produs_id', $produs->id)
            ->value('cantitate_fizica') ?? 0);

        // Cantitatea de comandat este administrata exclusiv manual si nu se modifica aici.
        if ($stoc >= (int) $produs->stoc_minim) {
            return;
        }

        $furnizorManualValid = $produs->furnizor_comanda_manual
            && $produs->furnizor_comanda_id !== null
            && $produs->furnizori()->where('furnizor_id', $produs->furnizor_comanda_id)->exists();

        if ($furnizorManualValid) {
            return;
        }

        $produs->update([
            'furnizor_comanda_id' => $produs->furnizori()
                ->orderByDesc('data_ultimei_achizitii')
                ->orderByDesc('id')
                ->value('furnizor_id'),
            'furnizor_comanda_manual' => false,
        ]);
    }
}
