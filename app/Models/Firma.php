<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Firma extends Model
{
    protected $table = 'firme';
    protected $guarded = ['id'];

    public function gestiuni() { return $this->hasMany(Gestiune::class); }
}
