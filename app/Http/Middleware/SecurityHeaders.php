<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Cabeçalhos de segurança em todas as respostas do Laravel. */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            // Ninguém abre o painel dentro de outro site (clickjacking).
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            // Só scripts e estilos do próprio sistema. "unsafe-inline" em estilo é por causa dos style="" das barras.
            // blob: é a prévia da imagem escolhida antes de enviar.
            'Content-Security-Policy' => "default-src 'self'; script-src 'self' https://challenges.cloudflare.com; style-src 'self' 'unsafe-inline'; "
                ."img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-src https://challenges.cloudflare.com; object-src 'none'; "
                ."frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
        ];

        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
