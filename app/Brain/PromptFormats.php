<?php

namespace App\Brain;

use App\Fields\FieldTypes;

/**
 * Formatos de bloco do prompt. Os específicos são o texto que o PromptBuilder já gerava,
 * movido para cá com a mesma saída. "campos" e "lista" são genéricos, para abas novas.
 */
final class PromptFormats
{
    /** Abas com estes formatos são o núcleo: não podem ser arquivadas nem trocar de formato. */
    public const CORE = ['negocio', 'identidade', 'objetivo', 'regras', 'faq'];

    public const GENERIC = ['campos', 'lista'];

    /** Campos que cada formato usa (ficam travados) e os tipos aceitos. */
    public const USES = [
        'identidade' => ['type' => 'settings', 'fields' => [
            'bot_name' => ['text'], 'presentation' => ['text', 'textarea'], 'tone_of_voice' => ['text', 'textarea'],
            'personality' => ['text', 'textarea'], 'emojis' => ['select'], 'max_sentences' => ['number'],
            'greeting' => ['text', 'textarea'],
        ]],
        'negocio' => ['type' => 'settings', 'fields' => [
            'company_name' => ['text'], 'segment' => ['text', 'textarea'], 'about' => ['text', 'textarea'],
            'address' => ['text', 'textarea'], 'service_area' => ['text', 'textarea'], 'website' => ['url'],
        ]],
        'objetivo' => ['type' => 'settings', 'fields' => [
            'objective' => ['text', 'textarea'], 'max_bot_replies' => ['number'],
            'handoff_message' => ['text', 'textarea'], 'after_hours_message' => ['text', 'textarea'],
        ]],
        'perguntas' => ['type' => 'items', 'fields' => ['question' => ['text', 'textarea'], 'why' => ['text', 'textarea'], 'required' => ['checkbox']]],
        'regras' => ['type' => 'items', 'fields' => ['kind' => ['select'], 'rule' => ['text', 'textarea'], 'example' => ['text', 'textarea']]],
        'horarios' => ['type' => 'items', 'fields' => ['kind' => ['select'], 'label' => ['text', 'textarea'], 'value' => ['text', 'textarea'], 'note' => ['text', 'textarea']]],
        'servicos' => ['type' => 'items', 'fields' => ['name' => ['text', 'textarea'], 'description' => ['text', 'textarea'], 'price' => ['text', 'textarea'], 'unit' => ['text', 'textarea'], 'conditions' => ['text', 'textarea']]],
        'politicas' => ['type' => 'items', 'fields' => ['topic' => ['text', 'textarea'], 'rule' => ['text', 'textarea'], 'details' => ['text', 'textarea']]],
        'passos' => ['type' => 'items', 'fields' => ['title' => ['text', 'textarea'], 'description' => ['text', 'textarea']]],
        'faq' => ['type' => 'items', 'fields' => ['question' => ['text', 'textarea'], 'answer' => ['text', 'textarea'], 'keywords' => ['text', 'textarea']]],
    ];

    /** Chaves de opção de select que o formato lê (não podem ser mexidas). */
    public const LOCKED_OPTIONS = [
        'identidade' => ['emojis' => ['nunca', 'moderacao', 'vontade']],
        'regras' => ['kind' => ['nunca', 'sempre', 'humano']],
        'horarios' => ['kind' => ['horario', 'contato', 'endereco']],
    ];

    public const LABELS = [
        'campos' => 'Genérico — formulário (Rótulo: valor)',
        'lista' => 'Genérico — lista de itens',
        'identidade' => 'Identidade e personalidade',
        'negocio' => 'Sobre o negócio',
        'objetivo' => 'Objetivo do atendimento',
        'perguntas' => 'Perguntas de qualificação',
        'regras' => 'Regras (Nunca / Sempre / Humano)',
        'horarios' => 'Horários e contato',
        'servicos' => 'Serviços e taxas',
        'politicas' => 'Políticas',
        'passos' => 'Passo a passo',
        'faq' => 'Perguntas frequentes',
    ];

    public static function isCore(?string $format): bool
    {
        return in_array($format, self::CORE, true);
    }

    public static function usedKeys(?string $format): array
    {
        return array_keys(self::USES[$format]['fields'] ?? []);
    }

    /**
     * Monta o bloco de uma aba.
     *
     * @param  array  $tab  definição da aba
     * @param  array  $settings  valores das abas de formulário (planos)
     * @param  array  $items  itens ativos da aba, na ordem
     * @param  array  $ctx  ['has_horarios' => bool]
     */
    public static function render(array $tab, array $settings, array $items, array $ctx = []): string
    {
        $format = $tab['ai']['format'] ?? ($tab['type'] === 'items' ? 'lista' : 'campos');
        $fields = [];
        foreach ($tab['fields'] as $f) {
            if (empty($f['archived'])) {
                $fields[$f['key']] = $f;
            }
        }

        // Valor de um campo usado pelo formato: vazio se o campo sumiu ou não vai para a IA.
        $sendable = fn (string $key) => isset($fields[$key]) && ($fields[$key]['send_to_ai'] ?? true) !== false;
        $s = fn (string $key) => $sendable($key) ? trim((string) ($settings[$key] ?? '')) : '';
        $d = fn (array $item, string $key) => $sendable($key) ? trim((string) ($item['data'][$key] ?? '')) : '';

        $used = self::usedKeys($format);
        $extraFields = array_filter($fields, fn ($f) => ! in_array($f['key'], $used, true)
            && ($f['send_to_ai'] ?? true) !== false && $f['type'] !== 'image');

        // Campos extras: "Rótulo: valor".
        $extraSettings = function () use ($extraFields, $settings) {
            $lines = [];
            foreach ($extraFields as $f) {
                $v = FieldTypes::aiText($f, $settings[$f['key']] ?? null);
                if ($v !== '') {
                    $lines[] = $f['label'].': '.$v;
                }
            }

            return $lines;
        };
        $extraItem = function (array $item) use ($extraFields) {
            $out = '';
            foreach ($extraFields as $f) {
                $v = FieldTypes::aiText($f, $item['data'][$f['key']] ?? null);
                if ($v !== '') {
                    $out .= ' '.$f['label'].': '.$v.'.';
                }
            }

            return $out;
        };

        $separator = "\n";
        $lines = [];

        switch ($format) {
            case 'identidade':
                $company = trim((string) ($settings['company_name'] ?? '')) ?: 'a empresa';
                $segment = trim((string) ($settings['segment'] ?? ''));
                $intro = 'Você é '.($s('bot_name') ?: 'o atendente virtual').', atendente de WhatsApp de '.$company;
                $intro .= $segment !== '' ? ' ('.$segment.').' : '.';
                $emojis = ['nunca' => 'Não use emojis.', 'moderacao' => 'Use emojis com moderação.', 'vontade' => 'Pode usar emojis à vontade.'][$s('emojis')] ?? '';
                $lines = [
                    $intro,
                    $s('presentation') ? 'Como se apresentar: '.$s('presentation') : '',
                    $s('tone_of_voice') ? 'Tom de voz: '.$s('tone_of_voice').'.' : '',
                    $s('personality') ? 'Jeito de falar: '.$s('personality') : '',
                    $emojis,
                    $s('max_sentences') ? 'Responda em no máximo '.$s('max_sentences').' frases por mensagem.' : '',
                    $s('greeting') ? 'Na primeira mensagem, cumprimente assim: "'.$s('greeting').'"' : '',
                    ...$extraSettings(),
                ];
                break;

            case 'negocio':
                $lines = [
                    $s('about'),
                    $s('address') ? 'Endereço: '.$s('address') : '',
                    $s('service_area') ? 'Região atendida: '.$s('service_area') : '',
                    $s('website') ? 'Site: '.$s('website') : '',
                    ...$extraSettings(),
                ];
                break;

            case 'objetivo':
                $lines = [$s('objective'), ...$extraSettings()];
                if (empty($ctx['has_horarios']) && $s('after_hours_message')) {
                    $lines[] = 'Fora do horário de atendimento: '.$s('after_hours_message');
                }
                break;

            case 'perguntas':
                foreach (array_values($items) as $i => $item) {
                    $line = ($i + 1).'. '.$d($item, 'question');
                    if ($sendable('required')) {
                        $line .= ! empty($item['data']['required']) ? ' (obrigatória)' : ' (opcional)';
                    }
                    $line .= $d($item, 'why') ? ' — '.$d($item, 'why') : '';
                    $lines[] = $line.$extraItem($item);
                }
                break;

            case 'regras':
                $kinds = ['nunca' => 'NUNCA', 'sempre' => 'SEMPRE', 'humano' => 'PASSE PARA UM ATENDENTE HUMANO'];
                foreach ($kinds as $kind => $title) {
                    $group = [];
                    foreach ($items as $item) {
                        if (($item['data']['kind'] ?? '') === $kind) {
                            $line = '- '.$d($item, 'rule');
                            $line .= $d($item, 'example') ? ' Exemplo: '.$d($item, 'example') : '';
                            $group[] = $line.$extraItem($item);
                        }
                    }
                    if ($group) {
                        $lines[] = '## '.$title;
                        array_push($lines, ...$group);
                    }
                }
                $handoff = [];
                $handoffMsg = trim((string) ($settings['handoff_message'] ?? ''));
                $maxReplies = trim((string) ($settings['max_bot_replies'] ?? ''));
                if ($handoffMsg !== '') {
                    $handoff[] = 'Ao passar para um atendente, diga: "'.$handoffMsg.'"';
                }
                if ($maxReplies !== '') {
                    $handoff[] = 'Depois de '.$maxReplies.' respostas suas, um atendente assume a conversa.';
                }
                if ($handoff) {
                    $lines[] = '## PASSAGEM PARA HUMANO';
                    array_push($lines, ...$handoff);
                }
                break;

            case 'horarios':
                $kindLabels = ['horario' => 'Horário', 'contato' => 'Contato', 'endereco' => 'Endereço'];
                foreach ($items as $item) {
                    $line = '- '.($kindLabels[$item['data']['kind'] ?? ''] ?? 'Info').' — '.$d($item, 'label').': '.$d($item, 'value');
                    $line .= $d($item, 'note') ? ' ('.$d($item, 'note').')' : '';
                    $lines[] = $line.$extraItem($item);
                }
                $afterHours = trim((string) ($settings['after_hours_message'] ?? ''));
                if ($afterHours !== '') {
                    $lines[] = 'Fora do horário de atendimento: '.$afterHours;
                }
                break;

            case 'servicos':
                foreach ($items as $item) {
                    $line = '- '.$d($item, 'name');
                    $price = trim($d($item, 'price').' '.$d($item, 'unit'));
                    $line .= $price !== '' ? ': '.$price.'.' : '.';
                    $line .= $d($item, 'description') ? ' '.$d($item, 'description').'.' : '';
                    $line .= $d($item, 'conditions') ? ' Condições: '.$d($item, 'conditions') : '';
                    $lines[] = $line.$extraItem($item);
                }
                break;

            case 'politicas':
                foreach ($items as $item) {
                    $line = '- '.$d($item, 'topic').': '.$d($item, 'rule');
                    $line .= $d($item, 'details') ? ' Detalhes: '.$d($item, 'details') : '';
                    $lines[] = $line.$extraItem($item);
                }
                break;

            case 'passos':
                foreach (array_values($items) as $i => $item) {
                    $lines[] = ($i + 1).'. '.$d($item, 'title').': '.$d($item, 'description').$extraItem($item);
                }
                break;

            case 'faq':
                foreach ($items as $item) {
                    $lines[] = 'P: '.$d($item, 'question').($d($item, 'keywords') ? ' (também: '.$d($item, 'keywords').')' : '')
                        ."\nR: ".$d($item, 'answer').$extraItem($item);
                }
                $separator = "\n\n";
                break;

            case 'lista':
                $lines = self::genericList($tab, $fields, $items);
                break;

            case 'campos':
            default:
                foreach ($fields as $f) {
                    if (($f['send_to_ai'] ?? true) === false || $f['type'] === 'image') {
                        continue;
                    }
                    $v = FieldTypes::aiText($f, $settings[$f['key']] ?? null);
                    if ($v !== '') {
                        $lines[] = $f['label'].': '.$v;
                    }
                }
                break;
        }

        $lines = array_values(array_filter($lines, fn ($l) => trim((string) $l) !== ''));

        if (! $lines) {
            return '';
        }

        $before = trim((string) ($tab['ai']['before'] ?? ''));
        $after = trim((string) ($tab['ai']['after'] ?? ''));
        if ($before !== '') {
            array_unshift($lines, $before);
        }
        if ($after !== '') {
            $lines[] = $after;
        }

        $title = trim((string) ($tab['ai']['title'] ?? '')) ?: mb_strtoupper($tab['label']);

        return '# '.$title."\n".implode($separator, $lines);
    }

    /** Formato genérico de lista: modelo de linha ou "- Título. Rótulo: valor; Rótulo: valor". */
    private static function genericList(array $tab, array $fields, array $items): array
    {
        $lines = [];
        $template = trim((string) ($tab['ai']['line'] ?? ''));
        $titleKey = $tab['title_field'] ?? null;
        $sendable = array_filter($fields, fn ($f) => ($f['send_to_ai'] ?? true) !== false && $f['type'] !== 'image');

        foreach (array_values($items) as $i => $item) {
            if ($template !== '') {
                $line = preg_replace_callback('/\{([a-z][a-z0-9_]*)\}/', function ($m) use ($sendable, $item) {
                    $f = $sendable[$m[1]] ?? null;

                    return $f ? FieldTypes::aiText($f, $item['data'][$f['key']] ?? null) : '';
                }, $template);
                // Limpa sobras de campos vazios: "()", " ,", espaços duplos.
                $line = preg_replace(['/\(\s*\)/', '/\s+([,.;:)])/', '/\s{2,}/'], ['', '$1', ' '], $line);
                $line = trim($line);
            } else {
                $title = $titleKey && isset($sendable[$titleKey]) ? FieldTypes::aiText($sendable[$titleKey], $item['data'][$titleKey] ?? null) : '';
                $parts = [];
                foreach ($sendable as $f) {
                    if ($f['key'] === $titleKey) {
                        continue;
                    }
                    $v = FieldTypes::aiText($f, $item['data'][$f['key']] ?? null);
                    if ($v !== '') {
                        $parts[] = $f['label'].': '.$v;
                    }
                }
                $line = '- '.$title.($title !== '' ? '.' : '').($parts ? ' '.implode('; ', $parts) : '');
                $line = trim($line);
            }

            if (! empty($tab['ordered'])) {
                $line = ($i + 1).'. '.preg_replace('/^-\s*/', '', $line);
            }

            if (trim($line, " -.") !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
