<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Tenant extends Model
{
    public const ACTIVE = 'ativo';

    public const SUSPENDED = 'suspenso';

    protected $fillable = ['name', 'slug', 'status', 'plan', 'template_id', 'template_version', 'modules', 'vitrine'];

    protected $hidden = ['api_token_hash'];

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'vitrine' => 'array',
            'template_version' => 'integer',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(BrainTemplate::class, 'template_id');
    }

    /** O modelo mudou depois da última vez que foi aplicado (ou dispensado) neste cliente. */
    public function hasPendingUpdate(): bool
    {
        $template = $this->template;

        return $template && (int) $this->template_version < (int) $template->version;
    }

    /** Módulos ligados: ['catalogo' => bool, 'agenda' => bool]. */
    public function moduleFlags(): array
    {
        $flags = [];
        foreach (\App\Brain\Modules::ALL as $key => $label) {
            $flags[$key] = (bool) ($this->modules[$key] ?? false);
        }

        return $flags;
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** A vitrine pública só existe com o módulo Catálogo ligado e o cliente ativo. */
    public function hasVitrine(): bool
    {
        return $this->isActive() && $this->moduleFlags()['catalogo'];
    }

    /** Endereço público da vitrine (ou de um produto). */
    public function vitrineUrl(?string $productSlug = null): string
    {
        $host = config('cerebro.vitrine_host');
        $base = $host
            ? rtrim((str_contains($host, '://') ? '' : 'https://').$host, '/').'/'.$this->slug
            : rtrim(config('app.url'), '/').'/v/'.$this->slug;

        return $productSlug ? $base.'/'.$productSlug : $base;
    }

    /** Configuração visual da vitrine, com padrões. */
    public function vitrineSettings(): array
    {
        return array_merge([
            'color' => '#4f46e5', 'logo' => null, 'about' => '', 'phone' => '', 'whatsapp' => '',
            'email' => '', 'address' => '', 'instagram' => '',
            // Vitrine nova nasce fora do Google até o cliente aprovar.
            'building' => true,
        ], $this->vitrine ?? []);
    }

    /** Gera um token novo para o n8n. Devolve o token em texto (só aparece uma vez). */
    public function generateApiToken(): string
    {
        $plain = 'cer_'.Str::random(40);

        $this->forceFill([
            'api_token_hash' => hash('sha256', $plain),
            'api_token_hint' => substr($plain, -4),
        ])->save();

        return $plain;
    }

    public static function findByApiToken(?string $plain): ?self
    {
        if (! $plain) {
            return null;
        }

        return static::where('api_token_hash', hash('sha256', $plain))->first();
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'cliente';
        // Nomes que viram caminho na vitrine e não podem colidir com rotas do sistema.
        if (in_array($base, ['v', 'api', 'admin', 'login', 'logout', 'cerebro', 'storage', 'css', 'js', 'up', 'robots-txt', 'favicon-ico'], true)) {
            $base .= '-cliente';
        }
        $slug = $base;
        $i = 2;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
