<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReceptieLinie extends Model
{
    public $timestamps = false;

    protected $table = 'receptii_linii';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cantitate' => 'integer', 'cost_unitar' => 'decimal:12', 'valoare' => 'decimal:2'];
    }

    public function receptie()
    {
        return $this->belongsTo(Receptie::class);
    }

    public function facturaLinie()
    {
        return $this->belongsTo(FacturaFurnizorLinie::class);
    }

    public function produs()
    {
        return $this->belongsTo(Produs::class);
    }

    public function miscariStoc()
    {
        return $this->hasMany(MiscareStoc::class);
    }
}
