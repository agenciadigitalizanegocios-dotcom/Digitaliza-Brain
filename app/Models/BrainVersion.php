<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Foto completa do cérebro a cada alteração, com o prompt já montado. */
class BrainVersion extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'version', 'description', 'user_id', 'snapshot', 'prompt'];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'version' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
