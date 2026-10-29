<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Rastro de un registro borrado, para que /api/sync avise a los demás dispositivos.
 */
#[Fillable(['tenant_id', 'tabla', 'uuid'])]
class Eliminado extends Model
{
    use BelongsToTenant;

    protected $table = 'eliminados';

    public const UPDATED_AT = null;
}
