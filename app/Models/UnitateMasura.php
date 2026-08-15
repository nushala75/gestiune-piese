<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnitateMasura extends Model
{
    public $timestamps = false;
    protected $table = 'unitati_masura';
    protected $guarded = ['id'];
    protected function casts(): array { return ['activa' => 'boolean']; }

    public function produse() { return $this->hasMany(Produs::class); }
}
