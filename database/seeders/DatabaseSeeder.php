<?php

namespace Database\Seeders;

use App\Brain\TemplateApplier;
use App\Brain\Versioner;
use App\Models\BrainItem;
use App\Models\BrainSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Cria o admin da Digitaliza e a BS Imóveis (tenant 1) com o cérebro
 * já preenchido a partir da planilha "base-conhecimento-cliente-x".
 * Revise os textos marcados como exemplo antes de ligar no WhatsApp.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $adminPassword = env('ADMIN_PASSWORD') ?: Str::password(12, symbols: false);
        $ownerPassword = env('BS_OWNER_PASSWORD') ?: Str::password(12, symbols: false);

        $admin = User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@digitaliza.com.br')],
            ['name' => env('ADMIN_NAME', 'Admin Digitaliza'), 'password' => $adminPassword, 'role' => User::ROLE_ADMIN],
        );

        $tenant = Tenant::firstOrCreate(
            ['slug' => 'bs-imoveis'],
            ['name' => 'BS Imóveis', 'status' => Tenant::ACTIVE, 'plan' => 'Essencial'],
        );

        $owner = User::firstOrCreate(
            ['email' => env('BS_OWNER_EMAIL', 'bs@digitaliza.com.br')],
            ['tenant_id' => $tenant->id, 'name' => env('BS_OWNER_NAME', 'BS Imóveis'), 'password' => $ownerPassword, 'role' => User::ROLE_OWNER],
        );

        app(CurrentTenant::class)->set($tenant);

        $token = null;
        if (! BrainSetting::exists()) {
            $this->seedBrain();
            // Depois do seedBrain (a linha já existe) e antes do record (a 1ª versão já guarda a estrutura).
            TemplateApplier::provision($tenant, 'imobiliaria');
            Versioner::record($admin, 'Cérebro inicial (importado da planilha)');
            $token = $tenant->generateApiToken();
        }

        app(CurrentTenant::class)->set(null);

        $this->command?->newLine();
        $this->command?->info('Acessos criados:');
        if ($admin->wasRecentlyCreated) {
            $this->command?->line("  Admin Digitaliza: {$admin->email} / {$adminPassword}");
        }
        if ($owner->wasRecentlyCreated) {
            $this->command?->line("  Dono BS Imóveis:  {$owner->email} / {$ownerPassword}");
        }
        if ($token) {
            $this->command?->line("  Token do n8n (BS Imóveis): {$token}");
            $this->command?->warn('  Guarde o token agora: ele não aparece de novo (dá para gerar outro no painel admin).');
        }
    }

    private function seedBrain(): void
    {
        BrainSetting::create([
            'version' => 0,
            'data' => [
                'company_name' => 'BS Imóveis',
                'segment' => 'Aluguel de imóveis residenciais',
                'about' => '',
                'address' => 'Rua Exemplo, 123 - Centro - Esteio/RS',
                'service_area' => 'Esteio/RS',
                'website' => 'https://imoveis.digitalizanegocio.com.br',
                'bot_name' => 'William',
                'presentation' => 'Se apresenta como William. Não diz que é robô nem que é uma imobiliária, a não ser que o cliente pergunte.',
                'tone_of_voice' => 'Educado, simpático e objetivo',
                'personality' => '',
                'emojis' => 'moderacao',
                'max_sentences' => 3,
                'greeting' => 'Olá! 😊 Aqui é o William, da BS Imóveis. Está procurando imóvel para alugar?',
                'objective' => 'Entender o que o cliente procura, fazer as perguntas de qualificação e marcar a visita ao imóvel.',
                'max_bot_replies' => 4,
                'handoff_message' => 'Vou chamar um atendente, só um instante.',
                'after_hours_message' => '',
            ],
        ]);

        $items = [
            'perguntas' => [
                ['question' => 'Qual imóvel te interessou, ou o que você procura (bairro, quartos, valor)?', 'why' => 'Para buscar os imóveis certos', 'required' => true],
                ['question' => 'Qual é o seu nome?', 'why' => '', 'required' => true],
                ['question' => 'Quantas pessoas vão morar no imóvel?', 'why' => 'Para indicar o tamanho certo', 'required' => true],
                ['question' => 'Tem animal de estimação?', 'why' => 'Nem todo imóvel aceita pet', 'required' => true],
                ['question' => 'Qual é a renda aproximada de quem vai alugar?', 'why' => 'Para a análise de cadastro', 'required' => false],
                ['question' => 'Que garantia pretende usar (fiador, caução ou seguro fiança)?', 'why' => 'Para adiantar o cadastro', 'required' => false],
            ],
            'regras' => [
                ['kind' => 'nunca', 'rule' => 'Nunca inventar imóvel, valor, endereço ou característica.', 'example' => 'Se não estiver na base ou no catálogo, dizer que vai confirmar.'],
                ['kind' => 'nunca', 'rule' => 'Nunca prometer aprovação de cadastro, desconto ou negociação de valor.', 'example' => '"Isso o corretor vê com você."'],
                ['kind' => 'nunca', 'rule' => 'Nunca prometer que o imóvel aceita pet; isso depende do imóvel e o corretor confirma.', 'example' => ''],
                ['kind' => 'sempre', 'rule' => 'Mandar o link do site só quando o cliente pedir ou quando ele chegar sem imóvel definido.', 'example' => ''],
                ['kind' => 'humano', 'rule' => 'Quando o cliente pedir uma pessoa, reclamar ou falar de contrato.', 'example' => '"Vou chamar um atendente, só um instante."'],
            ],
            'faq' => [
                ['question' => 'Quais documentos preciso para alugar?', 'answer' => 'RG, CPF, comprovante de renda e comprovante de residência. O corretor confirma a lista completa no atendimento.', 'keywords' => 'documentos, papelada, o que levar'],
                ['question' => 'Posso visitar o imóvel antes?', 'answer' => 'Sim! Depois que você escolher o imóvel, a gente agenda a visita com você.', 'keywords' => 'visita, ver o imóvel, conhecer'],
            ],
            'servicos' => [
                ['name' => 'Taxa de cadastro', 'description' => 'Análise de cadastro do inquilino', 'price' => 'R$ 0,00', 'unit' => 'por cadastro', 'conditions' => 'Não cobramos taxa de cadastro'],
                ['name' => 'Seguro fiança', 'description' => 'Garantia de aluguel por seguradora', 'price' => 'Sob consulta', 'unit' => 'mensal', 'conditions' => 'Valor depende da análise da seguradora'],
            ],
            'horarios' => [
                ['kind' => 'horario', 'label' => 'Segunda a sexta', 'value' => '08:00 às 18:00', 'note' => ''],
                ['kind' => 'horario', 'label' => 'Sábado', 'value' => '08:00 às 12:00', 'note' => 'Domingo e feriado fechado'],
                ['kind' => 'contato', 'label' => 'WhatsApp', 'value' => '(00) 00000-0000', 'note' => 'Trocar pelo número real'],
            ],
            'politicas' => [
                ['topic' => 'Garantias aceitas', 'rule' => 'Aceitamos fiador, caução ou seguro fiança.', 'details' => 'Caução = 3 aluguéis. Fiador precisa ter imóvel quitado na cidade.'],
                ['topic' => 'Animais de estimação', 'rule' => 'Depende do imóvel; o corretor confirma.', 'details' => ''],
            ],
            'contratar' => [
                ['title' => 'Escolha o imóvel', 'description' => 'Veja os imóveis no site e chame no WhatsApp o que gostou.'],
                ['title' => 'Envie seus dados', 'description' => 'O atendente pergunta nome, quantas pessoas vão morar, pet, renda e garantia.'],
                ['title' => 'Visita e cadastro', 'description' => 'A visita é agendada e o cadastro é analisado.'],
                ['title' => 'Contrato', 'description' => 'Aprovado o cadastro, o contrato é assinado e as chaves são entregues.'],
            ],
        ];

        foreach ($items as $section => $rows) {
            foreach ($rows as $i => $data) {
                BrainItem::create([
                    'section' => $section,
                    'data' => $data,
                    'position' => $i + 1,
                    'active' => true,
                ]);
            }
        }
    }
}
