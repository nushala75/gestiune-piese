<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacturaFurnizorLinie extends Model
{
    public $timestamps = false;
    protected $table = 'facturi_furnizor_linii';
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return [
            'cantitate' => 'decimal:3', 'amount_sursa' => 'decimal:2',
            'pret_unitar_calculat' => 'decimal:12', 'cota_tva' => 'decimal:2',
            'valoare_tva' => 'decimal:2',
        ];
    }

    public function factura() { return $this->belongsTo(FacturaFurnizor::class, 'factura_id'); }
    public function produs() { return $this->belongsTo(Produs::class); }
    public function liniiReceptie() { return $this->hasMany(ReceptieLinie::class, 'factura_linie_id'); }
}
