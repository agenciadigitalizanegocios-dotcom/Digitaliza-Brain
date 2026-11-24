<?php

namespace App\Providers;

use App\Support\CurrentTenant;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Uma instância por requisição.
        $this->app->scoped(CurrentTenant::class);
    }

    public function boot(): void
    {
        // "há 5 minutos" em vez de "5 minutes ago".
        Carbon::setLocale(config('app.locale'));

        // Só o cloudflared (rede interna do Docker) pode informar o IP real do visitante.
        TrustProxies::at(array_values(array_filter(array_map('trim', explode(',', (string) config('cerebro.trusted_proxies'))))));

        if ($this->app->isProduction()) {
            // Cookie de login só trafega por HTTPS, salvo se o .env disser o contrário de propósito.
            if (env('SESSION_SECURE_COOKIE') === null) {
                config(['session.secure' => true]);
            }
            if (str_starts_with((string) config('app.url'), 'https://')) {
                URL::forceScheme('https');
            }
            // Debug ligado em produção mostra configuração e senhas na tela de erro.
            if (config('app.debug')) {
                Log::critical('APP_DEBUG=true em produção. Desligue no .env.');
                View::share('debugInProduction', true);
            }
        }
    }
}
