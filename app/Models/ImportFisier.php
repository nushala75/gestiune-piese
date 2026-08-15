<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportFisier extends Model
{
    public const UPDATED_AT = null;
    protected $table = 'importuri_fisiere';
    protected $guarded = ['id'];
    protected function casts(): array { return ['rezultat' => 'array']; }

    public function facturi() { return $this->hasMany(FacturaFurnizor::class); }
}
