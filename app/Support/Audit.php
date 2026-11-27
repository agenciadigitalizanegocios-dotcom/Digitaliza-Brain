<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Grava uma ação no registro. Nunca derruba a requisição se o registro falhar. */
final class Audit
{
    public const LABELS = [
        'login' => 'Entrou',
        'login_falhou' => 'Senha errada',
        'login_bloqueado' => 'Login bloqueado (muitas tentativas)',
        'cliente_criado' => 'Criou cliente',
        'cliente_aberto' => 'Abriu o cérebro do cliente',
        'token_gerado' => 'Gerou token novo do n8n',
        'senha_trocada' => 'Trocou a senha de um usuário (pelo terminal)',
        'cliente_suspenso' => 'Suspendeu cliente',
        'cliente_reativado' => 'Reativou cliente',
        'modulos' => 'Alterou módulos',
        'estrutura_cliente' => 'Alterou a estrutura do cliente',
        'estrutura_modelo' => 'Alterou a estrutura do modelo',
        'modelo' => 'Alterou modelo',
        'atualizacao_aplicada' => 'Aplicou atualização do modelo',
        'atualizacao_dispensada' => 'Dispensou atualização do modelo',
        'catalogo_tipo' => 'Alterou tipo de produto do catálogo',
        'produto' => 'Mexeu num produto do catálogo',
        'vitrine' => 'Alterou a vitrine',
    ];

    public static function log(string $action, ?int $tenantId = null, array $details = []): void
    {
        try {
            AuditLog::create([
                'user_id' => auth()->id(),
                'tenant_id' => $tenantId,
                'action' => $action,
                'details' => $details ?: null,
                'ip' => request()?->ip(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Falha ao gravar o registro de auditoria: '.$e->getMessage());
        }
    }
}
