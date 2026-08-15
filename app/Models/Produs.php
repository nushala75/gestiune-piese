<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Produs extends Model
{
    protected $table = 'produse';
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return [
            'stoc_minim' => 'decimal:3',
            'pret_vanzare_fara_tva' => 'decimal:4',
            'pret_vanzare_cu_tva' => 'decimal:2',
            'cota_tva' => 'decimal:2',
            'greutate_kg' => 'decimal:3',
            'voluminos' => 'boolean',
            'lungime_cm' => 'decimal:2',
            'latime_cm' => 'decimal:2',
            'inaltime_cm' => 'decimal:2',
            'activ' => 'boolean',
        ];
    }

    public function categorie() { return $this->belongsTo(Categorie::class); }
    public function unitateMasura() { return $this->belongsTo(UnitateMasura::class); }
    public function furnizori() { return $this->hasMany(ProdusFurnizor::class); }
    public function liniiFactura() { return $this->hasMany(FacturaFurnizorLinie::class); }
    public function miscariStoc() { return $this->hasMany(MiscareStoc::class); }
    public function solduriStoc() { return $this->hasMany(SoldStoc::class); }
}
