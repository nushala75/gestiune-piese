<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacturaFurnizor extends Model
{
    protected $table = 'facturi_furnizor';
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return [
            'data_factura' => 'date', 'data_scadenta' => 'date',
            'total_fara_tva' => 'decimal:2', 'total_tva' => 'decimal:2',
            'total_factura' => 'decimal:2', 'taxare_inversa' => 'boolean',
        ];
    }

    public function furnizor() { return $this->belongsTo(Furnizor::class); }
    public function importFisier() { return $this->belongsTo(ImportFisier::class); }
    public function linii() { return $this->hasMany(FacturaFurnizorLinie::class, 'factura_id'); }
    public function receptie() { return $this->hasOne(Receptie::class, 'factura_id'); }
    public function exporturiSaga() { return $this->hasMany(ExportSaga::class, 'factura_id'); }
}
