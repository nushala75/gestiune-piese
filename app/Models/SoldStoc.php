<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SoldStoc extends Model
{
    public $incrementing = false;
    public $timestamps = false;
    protected $table = 'solduri_stoc';
    protected $guarded = [];
    protected function casts(): array { return ['cantitate_fizica' => 'decimal:3', 'cantitate_rezervata' => 'decimal:3']; }

    public function gestiune() { return $this->belongsTo(Gestiune::class); }
    public function produs() { return $this->belongsTo(Produs::class); }
}
