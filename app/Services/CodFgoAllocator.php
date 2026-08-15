<?php

namespace App\Services;

use App\Models\SecventaCodFgo;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CodFgoAllocator
{
    public function aloca(): string
    {
        return DB::transaction(function (): string {
            $secventa = SecventaCodFgo::query()->lockForUpdate()->find(1);

            if ($secventa === null) {
                throw new RuntimeException('Secvența pentru codurile FGO nu există.');
            }

            if ($secventa->urmatorul_cod > $secventa->cod_maxim) {
                throw new RuntimeException('Intervalul codurilor FGO este epuizat.');
            }

            $codNumeric = (int) $secventa->urmatorul_cod;
            $secventa->urmatorul_cod = $codNumeric + 1;
            $secventa->save();

            return str_pad((string) $codNumeric, 8, '0', STR_PAD_LEFT);
        });
    }
}
