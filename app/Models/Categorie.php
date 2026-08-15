<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Categorie extends Model
{
    public $timestamps = false;
    protected $table = 'categorii';
    protected $guarded = ['id'];
    protected function casts(): array { return ['activa' => 'boolean']; }

    public function produse() { return $this->hasMany(Produs::class); }
}
