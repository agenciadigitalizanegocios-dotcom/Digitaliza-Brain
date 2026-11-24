<?php

// Limites e regras de segurança do Digitaliza Brain. Todos podem ser ajustados pelo .env.
return [

    // Proxies confiáveis: só a rede interna do Docker (onde roda o cloudflared).
    // Nunca use "*": com a porta aberta, qualquer um falsifica o IP e burla o limite de login.
    'trusted_proxies' => env('TRUSTED_PROXIES', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1'),

    // A API do n8n só responde pela rede interna (http://cerebro:8000).
    // Pedidos que chegam pelo Cloudflare (têm o cabeçalho Cf-Connecting-Ip) são recusados.
    'api_allow_public' => (bool) env('API_ALLOW_PUBLIC', false),

    'limits' => [
        // Itens por aba de lista.
        'items_per_tab' => (int) env('CEREBRO_MAX_ITENS_POR_ABA', 200),
        // Tamanho máximo do prompt montado (caracteres). ~60 mil ≈ 15 mil tokens.
        'prompt_chars' => (int) env('CEREBRO_MAX_PROMPT', 60000),
        // Espaço de imagens por cliente (MB).
        'images_mb' => (int) env('CEREBRO_COTA_IMAGENS_MB', 200),
        // Maior largura/altura aceita numa imagem (px).
        'image_max_px' => (int) env('CEREBRO_IMAGEM_MAX_PX', 6000),
        // Lado maior depois de reprocessar (px). As fotos maiores são reduzidas.
        'image_resize_px' => (int) env('CEREBRO_IMAGEM_REDUZIR_PX', 2000),
    ],

    'login' => [
        // Por e-mail + IP (bloqueio curto) e só por e-mail (protege mesmo se o IP mudar).
        'per_ip_attempts' => 5,
        'per_email_attempts' => 10,
        'per_email_lock_seconds' => 900,
    ],

    // Cloudflare Turnstile no login: só aparece depois de N falhas (por IP ou por e-mail).
    'turnstile' => [
        'enabled' => (bool) env('TURNSTILE_ENABLED', false),
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'after_failures' => (int) env('TURNSTILE_AFTER_FAILURES', 2),
    ],
];
