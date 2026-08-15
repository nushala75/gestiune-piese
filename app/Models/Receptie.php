<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Receptie extends Model
{
    public const UPDATED_AT = null;
    protected $table = 'receptii';
    protected $guarded = ['id'];
    protected function casts(): array { return ['data_receptie' => 'datetime']; }

    public function factura() { return $this->belongsTo(FacturaFurnizor::class, 'factura_id'); }
    public function gestiune() { return $this->belongsTo(Gestiune::class); }
    public function linii() { return $this->hasMany(ReceptieLinie::class); }
    public function exporturiFgo() { return $this->hasMany(ExportFgoStoc::class); }
}
