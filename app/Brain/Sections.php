<?php

namespace App\Brain;

/**
 * Todas as abas do Cérebro, os campos de cada uma e como aparecem na tela.
 * Para criar uma aba ou um campo novo, mexa só aqui (e no PromptBuilder, se a IA precisar ler).
 *
 * type "settings" = formulário único; type "items" = lista de cartões.
 * Tipos de campo: text, textarea, url, number, select, checkbox.
 */
final class Sections
{
    public static function all(): array
    {
        return [
            'negocio' => [
                'label' => 'Negócio',
                'icon' => '🏢',
                'type' => 'settings',
                'intro' => 'Quem é a empresa e onde ela atua. O robô usa isso para se situar e responder o básico.',
                'fields' => [
                    ['key' => 'company_name', 'label' => 'Nome da empresa', 'type' => 'text', 'required' => true, 'placeholder' => 'BS Imóveis'],
                    ['key' => 'segment', 'label' => 'Segmento', 'type' => 'text', 'placeholder' => 'Aluguel de imóveis residenciais'],
                    ['key' => 'about', 'label' => 'Sobre o negócio', 'type' => 'textarea', 'placeholder' => 'Imobiliária familiar em Esteio, trabalha só com aluguel residencial...', 'help' => 'Explique como explicaria para um atendente novo no primeiro dia.'],
                    ['key' => 'address', 'label' => 'Endereço', 'type' => 'text', 'placeholder' => 'Rua Exemplo, 123 - Centro - Esteio/RS'],
                    ['key' => 'service_area', 'label' => 'Região atendida', 'type' => 'text', 'placeholder' => 'Esteio, Sapucaia do Sul e Canoas'],
                    ['key' => 'website', 'label' => 'Site', 'type' => 'url', 'placeholder' => 'https://...'],
                ],
            ],

            'personalidade' => [
                'label' => 'Personalidade',
                'icon' => '🙂',
                'type' => 'settings',
                'intro' => 'Como o robô se apresenta e fala. É o "jeito" do atendimento.',
                'fields' => [
                    ['key' => 'bot_name', 'label' => 'Nome que o robô usa', 'type' => 'text', 'required' => true, 'placeholder' => 'William'],
                    ['key' => 'presentation', 'label' => 'Como se apresenta', 'type' => 'textarea', 'placeholder' => 'Se apresenta só pelo nome. Não diz que é robô, a não ser que o cliente pergunte.'],
                    ['key' => 'tone_of_voice', 'label' => 'Tom de voz', 'type' => 'text', 'required' => true, 'placeholder' => 'Educado, simpático e objetivo'],
                    ['key' => 'personality', 'label' => 'Personalidade (detalhes)', 'type' => 'textarea', 'placeholder' => 'Fala como gaúcho, trata por "você", nunca é insistente...', 'help' => 'Opcional. Jeito de falar, expressões, o que evitar.'],
                    ['key' => 'emojis', 'label' => 'Emojis', 'type' => 'select', 'options' => ['nunca' => 'Nunca usar', 'moderacao' => 'Com moderação', 'vontade' => 'À vontade']],
                    ['key' => 'max_sentences', 'label' => 'Máximo de frases por resposta', 'type' => 'number', 'min' => 1, 'max' => 10, 'placeholder' => '3'],
                    ['key' => 'greeting', 'label' => 'Saudação (primeira mensagem)', 'type' => 'textarea', 'placeholder' => 'Olá! 😊 Aqui é o William, da BS Imóveis. Está procurando imóvel para alugar?'],
                ],
            ],

            'atendimento' => [
                'label' => 'Objetivo e passagem',
                'icon' => '🎯',
                'type' => 'settings',
                'intro' => 'Aonde o robô precisa levar a conversa e quando ele passa para uma pessoa.',
                'fields' => [
                    ['key' => 'objective', 'label' => 'Objetivo do atendimento', 'type' => 'textarea', 'required' => true, 'placeholder' => 'Entender o que o cliente procura, fazer as perguntas de qualificação e marcar a visita.'],
                    ['key' => 'max_bot_replies', 'label' => 'Respostas do robô antes de passar para humano', 'type' => 'number', 'min' => 1, 'max' => 50, 'required' => true, 'placeholder' => '4', 'help' => 'Ao chegar nesse número, o robô para e o atendente assume no Chatwoot.'],
                    ['key' => 'handoff_message', 'label' => 'Mensagem ao passar para humano', 'type' => 'text', 'placeholder' => 'Vou chamar um atendente, só um instante.'],
                    ['key' => 'after_hours_message', 'label' => 'O que dizer fora do horário', 'type' => 'textarea', 'placeholder' => 'Nosso escritório já fechou, mas eu sigo te ajudando por aqui. Amanhã um corretor confirma com você.'],
                ],
            ],

            'perguntas' => [
                'label' => 'Perguntas ao cliente',
                'icon' => '❓',
                'type' => 'items',
                'singular' => 'pergunta',
                'ordered' => true,
                'intro' => 'O que o robô precisa descobrir para qualificar o cliente. Ele faz uma por vez, nesta ordem.',
                'title_field' => 'question',
                'badge_field' => 'required',
                'badge_text' => 'Obrigatória',
                'fields' => [
                    ['key' => 'question', 'label' => 'Pergunta', 'type' => 'text', 'required' => true, 'placeholder' => 'Quantas pessoas vão morar no imóvel?'],
                    ['key' => 'why', 'label' => 'Para que serve', 'type' => 'text', 'placeholder' => 'Para indicar o tamanho certo de imóvel'],
                    ['key' => 'required', 'label' => 'Obrigatória antes de marcar a visita', 'type' => 'checkbox', 'default' => true],
                ],
            ],

            'regras' => [
                'label' => 'Regras',
                'icon' => '🚦',
                'type' => 'items',
                'singular' => 'regra',
                'intro' => 'O que o robô nunca faz, sempre faz e quando chama uma pessoa.',
                'title_field' => 'rule',
                'badge_field' => 'kind',
                'fields' => [
                    ['key' => 'kind', 'label' => 'Tipo', 'type' => 'select', 'required' => true, 'options' => ['nunca' => 'Nunca', 'sempre' => 'Sempre', 'humano' => 'Passar para humano']],
                    ['key' => 'rule', 'label' => 'Regra', 'type' => 'textarea', 'required' => true, 'placeholder' => 'Nunca prometer aprovação de cadastro, desconto ou negociação de valor.'],
                    ['key' => 'example', 'label' => 'Exemplo do que dizer', 'type' => 'text', 'placeholder' => '"Isso o corretor vê com você."'],
                ],
            ],

            'faq' => [
                'label' => 'Perguntas frequentes',
                'icon' => '💬',
                'type' => 'items',
                'singular' => 'pergunta frequente',
                'intro' => 'Dúvidas que os clientes sempre mandam, com a resposta do jeito que você quer que o robô dê.',
                'title_field' => 'question',
                'fields' => [
                    ['key' => 'question', 'label' => 'Pergunta do cliente', 'type' => 'text', 'required' => true, 'placeholder' => 'Quais documentos preciso para alugar?'],
                    ['key' => 'answer', 'label' => 'Resposta', 'type' => 'textarea', 'required' => true, 'placeholder' => 'RG, CPF, comprovante de renda e de residência. O corretor confirma a lista completa.'],
                    ['key' => 'keywords', 'label' => 'Palavras parecidas', 'type' => 'text', 'placeholder' => 'documentos, papelada, o que levar'],
                ],
            ],

            'servicos' => [
                'label' => 'Serviços e taxas',
                'icon' => '💲',
                'type' => 'items',
                'singular' => 'serviço',
                'intro' => 'Taxas e serviços com preço fixo. Preço de imóvel não entra aqui: vem do cadastro de imóveis.',
                'title_field' => 'name',
                'badge_field' => 'price',
                'fields' => [
                    ['key' => 'name', 'label' => 'Serviço ou taxa', 'type' => 'text', 'required' => true, 'placeholder' => 'Taxa de cadastro'],
                    ['key' => 'description', 'label' => 'O que é', 'type' => 'text', 'placeholder' => 'Análise de cadastro do inquilino'],
                    ['key' => 'price', 'label' => 'Preço', 'type' => 'text', 'placeholder' => 'R$ 0,00 ou "Sob consulta"'],
                    ['key' => 'unit', 'label' => 'Cobrado', 'type' => 'text', 'placeholder' => 'por cadastro, mensal...'],
                    ['key' => 'conditions', 'label' => 'Condições', 'type' => 'text', 'placeholder' => 'Não cobramos taxa de cadastro'],
                ],
            ],

            'horarios' => [
                'label' => 'Horários e contato',
                'icon' => '🕒',
                'type' => 'items',
                'singular' => 'horário ou contato',
                'ordered' => true,
                'intro' => 'Quando a empresa atende e por onde o cliente fala com ela.',
                'title_field' => 'label',
                'badge_field' => 'kind',
                'fields' => [
                    ['key' => 'kind', 'label' => 'Tipo', 'type' => 'select', 'required' => true, 'options' => ['horario' => 'Horário', 'contato' => 'Contato', 'endereco' => 'Endereço']],
                    ['key' => 'label', 'label' => 'Item', 'type' => 'text', 'required' => true, 'placeholder' => 'Segunda a sexta'],
                    ['key' => 'value', 'label' => 'Valor', 'type' => 'text', 'required' => true, 'placeholder' => '08:00 às 18:00'],
                    ['key' => 'note', 'label' => 'Observação', 'type' => 'text', 'placeholder' => 'Domingo e feriado fechado'],
                ],
            ],

            'politicas' => [
                'label' => 'Garantias e políticas',
                'icon' => '📋',
                'type' => 'items',
                'singular' => 'política',
                'intro' => 'Garantias aceitas, pet, caução, multa... As regras do negócio que o cliente costuma perguntar.',
                'title_field' => 'topic',
                'fields' => [
                    ['key' => 'topic', 'label' => 'Tema', 'type' => 'text', 'required' => true, 'placeholder' => 'Garantias aceitas'],
                    ['key' => 'rule', 'label' => 'Regra', 'type' => 'textarea', 'required' => true, 'placeholder' => 'Aceitamos fiador, caução ou seguro fiança.'],
                    ['key' => 'details', 'label' => 'Detalhes', 'type' => 'textarea', 'placeholder' => 'Caução = 3 aluguéis. Fiador precisa ter imóvel quitado na cidade.'],
                ],
            ],

            'contratar' => [
                'label' => 'Como contratar',
                'icon' => '🪜',
                'type' => 'items',
                'singular' => 'passo',
                'ordered' => true,
                'intro' => 'O passo a passo do cliente até fechar. O robô usa para explicar o processo.',
                'title_field' => 'title',
                'fields' => [
                    ['key' => 'title', 'label' => 'Passo', 'type' => 'text', 'required' => true, 'placeholder' => 'Escolha o imóvel'],
                    ['key' => 'description', 'label' => 'Descrição', 'type' => 'textarea', 'required' => true, 'placeholder' => 'Veja os imóveis no site e chame no WhatsApp o que gostou.'],
                ],
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** Todos os campos das abas de formulário (negócio, personalidade, atendimento). */
    public static function settingsFields(): array
    {
        $fields = [];

        foreach (self::all() as $section) {
            if ($section['type'] === 'settings') {
                foreach ($section['fields'] as $field) {
                    $fields[$field['key']] = $field;
                }
            }
        }

        return $fields;
    }

    /** Texto mostrado no cartão para um campo (ex.: "Nunca" em vez de "nunca"). */
    public static function display(array $field, mixed $value): string
    {
        return match ($field['type']) {
            'select' => $field['options'][$value] ?? (string) $value,
            'checkbox' => $value ? 'Sim' : 'Não',
            default => trim((string) $value),
        };
    }

    /** Nome curto do item, usado no histórico ("FAQ: editou “Quais documentos...”"). */
    public static function itemTitle(array $section, array $data): string
    {
        $title = trim((string) ($data[$section['title_field']] ?? ''));

        return mb_strimwidth($title, 0, 60, '…');
    }
}
