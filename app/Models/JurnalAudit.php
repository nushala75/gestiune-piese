<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JurnalAudit extends Model
{
    public const UPDATED_AT = null;
    protected $table = 'jurnal_audit';
    protected $guarded = ['id'];
    protected function casts(): array { return ['date_inainte' => 'array', 'date_dupa' => 'array']; }
}
