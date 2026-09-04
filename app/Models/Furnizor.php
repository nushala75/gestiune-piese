<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Furnizor extends Model
{
    protected $table = 'furnizori';

    protected $fillable = [
        'denumire',
        'cod_fiscal',
        'tara',
        'adresa',
        'moneda_implicita',
        'configuratie_parser',
        'activ',
    ];

    protected function casts(): array
    {
        return ['configuratie_parser' => 'array', 'activ' => 'boolean'];
    }

    public function produse()
    {
        return $this->hasMany(ProdusFurnizor::class);
    }

    public function facturi()
    {
        return $this->hasMany(FacturaFurnizor::class);
    }
}
