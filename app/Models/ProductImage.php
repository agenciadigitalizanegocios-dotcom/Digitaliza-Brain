<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Foto da galeria de um produto. A primeira (menor posição) é a capa. */
class ProductImage extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'product_id', 'path', 'position'];

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }
}
