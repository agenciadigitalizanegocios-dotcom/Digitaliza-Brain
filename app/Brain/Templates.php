<?php

namespace App\Brain;

/** Os arquivos dos modelos de nicho em database/templates/*.json. */
final class Templates
{
    public const FILES = [
        'imobiliaria' => ['name' => 'Imobiliária', 'description' => 'Aluguel e venda de imóveis: perguntas de qualificação, garantias, visitas.'],
        'escola-tecnica' => ['name' => 'Escola técnica', 'description' => 'Cursos, unidades, matrícula e regras de não prometer emprego nem renda.'],
        'em-branco' => ['name' => 'Em branco', 'description' => 'Só o núcleo (negócio, personalidade, objetivo, regras e FAQ), para começar um nicho novo.'],
    ];

    private static array $cache = [];

    public static function path(string $slug): string
    {
        return dirname(__DIR__, 2).'/database/templates/'.$slug.'.json';
    }

    /** Estrutura do arquivo de um modelo. */
    public static function file(string $slug): array
    {
        if (! isset(self::$cache[$slug])) {
            $json = @file_get_contents(self::path($slug));
            self::$cache[$slug] = $json ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : ['tabs' => [], 'closing' => [], 'starter_items' => []];
        }

        return self::$cache[$slug];
    }

    /** Estrutura usada quando um cliente não tem estrutura gravada: a Imobiliária (o comportamento de antes). */
    public static function fallback(): array
    {
        return self::file('imobiliaria');
    }
}
