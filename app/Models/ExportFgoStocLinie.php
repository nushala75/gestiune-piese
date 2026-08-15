<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExportFgoStocLinie extends Model
{
    public $timestamps = false;

    protected $table = 'exporturi_fgo_stoc_linii';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cantitate' => 'integer', 'pret_ponderat' => 'decimal:4', 'valoare_stoc' => 'decimal:2'];
    }

    public function export()
    {
        return $this->belongsTo(ExportFgoStoc::class, 'export_id');
    }

    public function produs()
    {
        return $this->belongsTo(Produs::class);
    }
}
