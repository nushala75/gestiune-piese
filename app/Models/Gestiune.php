<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Gestiune extends Model
{
    protected $table = 'gestiuni';
    protected $guarded = ['id'];
    protected function casts(): array { return ['activa' => 'boolean']; }

    public function firma() { return $this->belongsTo(Firma::class); }
    public function receptii() { return $this->hasMany(Receptie::class); }
    public function miscariStoc() { return $this->hasMany(MiscareStoc::class); }
    public function solduriStoc() { return $this->hasMany(SoldStoc::class); }
}
