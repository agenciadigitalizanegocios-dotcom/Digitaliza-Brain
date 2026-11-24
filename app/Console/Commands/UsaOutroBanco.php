<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Aponta a conexão sqlite para outro arquivo só durante o comando (opção --banco).
 * Funciona igual no Windows e no Linux, sem mexer no .env.
 */
final class UsaOutroBanco
{
    public static function aplicar(Command $command, ?string $banco): bool
    {
        if (! $banco) {
            return true;
        }

        $arquivo = self::caminho($banco);

        if (! is_file($arquivo)) {
            $command->error("Arquivo do banco não encontrado: {$arquivo}");

            return false;
        }

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $arquivo,
        ]);
        DB::purge('sqlite');

        $command->line("Usando o banco {$arquivo}");

        return true;
    }

    /** Caminho relativo é contado a partir da raiz do projeto. */
    public static function caminho(string $path): string
    {
        $absoluto = preg_match('#^([a-zA-Z]:[\\\\/]|/|\\\\)#', $path) === 1;

        return $absoluto ? $path : base_path($path);
    }
}
