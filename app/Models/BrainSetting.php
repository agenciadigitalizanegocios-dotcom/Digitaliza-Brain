<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Campos únicos do cérebro (negócio, personalidade, atendimento). Uma linha por imobiliária. */
class BrainSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'data', 'version', 'structure', 'template_base'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'version' => 'integer',
            'structure' => 'array',
            'template_base' => 'array',
        ];
    }

    public static function current(): self
    {
        return static::firstOrCreate([], ['data' => [], 'version' => 0]);
    }
}
