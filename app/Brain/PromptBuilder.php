<?php

namespace App\Brain;

/**
 * Transforma o cérebro (estrutura + campos + listas) no texto que a IA recebe.
 * Os blocos saem na ordem de "ai.order" de cada aba. Só entram itens ativos,
 * abas e campos não arquivados e campos com "vai para a IA" ligado.
 */
final class PromptBuilder
{
    /**
     * @param  array  $settings  valores das abas de formulário
     * @param  iterable  $items  itens no formato ['section', 'data', 'position', 'active']
     * @param  array|null  $structure  estrutura do cliente; nula = modelo Imobiliária
     */
    public static function build(array $settings, iterable $items, ?array $structure = null): string
    {
        $structure ??= Templates::fallback();
        $by = self::groupActive($items);

        $tabs = array_values(array_filter($structure['tabs'] ?? [], fn ($t) => empty($t['archived'])));
        // Ordem do prompt (ai.order); empate mantém a ordem do menu.
        $indexed = [];
        foreach ($tabs as $i => $tab) {
            $indexed[] = [$tab['ai']['order'] ?? 999, $i, $tab];
        }
        usort($indexed, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $hasHorarios = (bool) array_filter($tabs, fn ($t) => ($t['ai']['format'] ?? '') === 'horarios');

        $blocks = [];
        foreach ($indexed as [, , $tab]) {
            $blocks[] = PromptFormats::render($tab, $settings, $by[$tab['key']] ?? [], ['has_horarios' => $hasHorarios]);
        }

        $closing = array_values(array_filter(array_map('trim', $structure['closing'] ?? []), fn ($l) => $l !== ''));
        if ($closing) {
            $blocks[] = "# IMPORTANTE\n".implode("\n", $closing);
        }

        return implode("\n\n", array_filter($blocks));
    }

    /** Agrupa por seção, só itens ativos, na ordem. */
    public static function groupActive(iterable $items): array
    {
        $by = [];

        foreach ($items as $item) {
            $item = is_array($item) ? $item : $item->only(['section', 'data', 'position', 'active']);

            if (empty($item['active'])) {
                continue;
            }

            $by[$item['section']][] = $item;
        }

        foreach ($by as &$list) {
            usort($list, fn ($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));
        }

        return $by;
    }
}
