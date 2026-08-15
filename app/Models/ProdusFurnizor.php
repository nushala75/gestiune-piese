<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProdusFurnizor extends Model
{
    protected $table = 'produse_furnizori';
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return ['pret_achizitie_ultim' => 'decimal:4', 'data_ultimei_achizitii' => 'date', 'confirmata_manual' => 'boolean'];
    }

    public function produs() { return $this->belongsTo(Produs::class); }
    public function furnizor() { return $this->belongsTo(Furnizor::class); }
}
