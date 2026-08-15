<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExportSaga extends Model
{
    public const UPDATED_AT = null;
    protected $table = 'exporturi_saga';
    protected $guarded = ['id'];
    protected function casts(): array { return ['confirmat_la' => 'datetime']; }

    public function factura() { return $this->belongsTo(FacturaFurnizor::class); }
}
