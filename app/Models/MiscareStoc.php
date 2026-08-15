<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MiscareStoc extends Model
{
    public const UPDATED_AT = null;
    protected $table = 'miscari_stoc';
    protected $guarded = ['id'];
    protected function casts(): array { return ['cantitate' => 'decimal:3', 'cost_unitar' => 'decimal:12']; }

    public function gestiune() { return $this->belongsTo(Gestiune::class); }
    public function produs() { return $this->belongsTo(Produs::class); }
    public function receptieLinie() { return $this->belongsTo(ReceptieLinie::class); }
}
