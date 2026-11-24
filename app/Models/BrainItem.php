<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Item de lista do cérebro: uma pergunta, uma regra, uma FAQ, um serviço... */
class BrainItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'section', 'data', 'position', 'active'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'position' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function value(string $key): string
    {
        $value = $this->data[$key] ?? '';

        return is_bool($value) ? ($value ? '1' : '') : trim((string) $value);
    }
}
