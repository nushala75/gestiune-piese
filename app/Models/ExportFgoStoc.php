<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExportFgoStoc extends Model
{
    public const UPDATED_AT = null;
    protected $table = 'exporturi_fgo_stoc';
    protected $guarded = ['id'];
    protected function casts(): array { return ['confirmat_la' => 'datetime']; }

    public function receptie() { return $this->belongsTo(Receptie::class); }
    public function linii() { return $this->hasMany(ExportFgoStocLinie::class, 'export_id'); }
}
