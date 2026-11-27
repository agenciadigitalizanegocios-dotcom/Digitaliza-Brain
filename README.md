# Digitaliza Brain — Etapa 1 + Parte 1 (abas e campos por cliente)

Painel onde cada imobiliária preenche o "cérebro" do robô de WhatsApp (substitui a planilha `base-conhecimento-cliente-x`).
O n8n lê o cérebro pronto pela API `GET /api/v1/brain`.

Laravel 12 · Blade + JavaScript puro (sem build) · SQLite para começar.

## O que tem

**Painel do cliente** (`/cerebro`) — cada imobiliária só vê o próprio cérebro:

| Aba | Tipo | Veio da planilha |
| --- | --- | --- |
| Negócio | formulário | `config` (nome, segmento, site) + endereço |
| Personalidade | formulário | `config` (tom de voz, saudação) + nome do robô, emojis, tamanho da resposta |
| Objetivo e passagem | formulário | objetivo, limite de respostas, mensagem de passagem, fora do horário |
| Perguntas ao cliente | cartões em ordem | `como-contratar` passo 2 |
| Regras | cartões | `regras-negocio` (Nunca / Sempre / Passar para humano) |
| Perguntas frequentes | cartões | `faq` |
| Serviços e taxas | cartões | `servicos-precos` |
| Horários e contato | cartões em ordem | `horarios-endereco` |
| Garantias e políticas | cartões | `politicas-troca-garantia` |
| Como contratar | cartões em ordem | `como-contratar` |
| Histórico de versões | lista | novo |

- Cada cartão: editar, pausar/ativar (igual à coluna `ativo` da planilha), subir/descer, excluir.
- Toda alteração vira uma versão com o texto exato que a IA recebe. Dá para ver e restaurar qualquer versão.
- Menu lateral mostra o que já foi preenchido.

**Painel admin da Digitaliza** (`/admin/clientes`): criar cliente (com o dono e o token do n8n), abrir o cérebro de qualquer cliente, gerar token novo, suspender/reativar.

**Multi-cliente**: `tenant_id` em toda tabela + global scope (`app/Models/Concerns/BelongsToTenant.php`). Sem cliente definido numa requisição web, a consulta não devolve nada.


## Parte 1: abas e campos por cliente

Agora as abas e os campos não ficam mais no código: cada cliente tem a **estrutura** dele (um JSON em `brain_settings.structure`), copiada de um **modelo de nicho**.

- **Modelos** (`/admin/modelos`): Imobiliária, Escola técnica e Em branco (só o núcleo). Os arquivos de origem ficam em `database/templates/*.json`. Dá para duplicar um modelo para criar um nicho novo.
- **Editor de estrutura**: o mesmo editor serve para modelo (`/admin/modelos/{id}/estrutura`) e para cliente (`/admin/clientes/{id}/estrutura`). Abas (formulário ou lista), campos, ordem, arquivar/desarquivar, como a IA lê cada aba (formato, título, instrução antes/depois, modelo de linha, ordem no prompt) e o fechamento do prompt. Só o admin vê.
- **Núcleo** (🔒): Negócio, Personalidade, Objetivo e passagem, Regras e Perguntas frequentes. Não podem ser arquivados nem trocar de formato, porque o prompt e o n8n dependem deles.
- **Nada é excluído de verdade**: excluir = arquivar. Os valores ficam guardados e voltam ao desarquivar. A chave de um campo nunca muda; um campo novo com o nome de um arquivado ganha `_2`.
- **Atualização do modelo**: mudar um modelo não mexe nos clientes. Eles ganham o selo **"Atualização pendente"** na lista de clientes; em "Ver atualização" você escolhe o que aplicar (o que o cliente personalizou vem desmarcado; conflitos de chave não podem ser aplicados) ou dispensa o aviso.
- **Tipos de campo**: texto curto, texto longo, link, número, **dinheiro** (guarda centavos, mostra "R$ 1.234,56") e **imagem** (jpg, png ou webp até 5 MB), além de lista de opções e sim/não. O motor (`app/Fields/FieldTypes.php` + `resources/views/brain/partials/field.blade.php`) é o mesmo que o Catálogo vai usar na Parte 2.
- **Imagens**: ficam em `storage/app/public/tenants/{id}/` com nome aleatório. A URL é pública para quem tiver o link (o n8n precisa dela para mandar a foto no WhatsApp). Ao trocar ou remover, o arquivo antigo fica, porque o histórico pode apontar para ele: `php artisan cerebro:limpar-imagens` lista o que nenhuma versão usa, e `--apagar` apaga. O `APP_URL` precisa ser a URL pública do túnel, e precisa do `php artisan storage:link` (o Docker já faz).
- **Módulos**: Catálogo e Agenda aparecem com liga/desliga em cada cliente ("em breve"). A API devolve `"modules": {"catalogo": false, "agenda": false}`.
- **Histórico**: cada versão guarda também a estrutura. Restaurar volta só o conteúdo; a estrutura continua a atual.
- **API**: as chaves de topo e o formato continuam os mesmos; só entrou `modules`.

### Atualizar um sistema que já está rodando

1. Backup do banco (no servidor): pare o container uns segundos e copie o SQLite.
   ```bash
   docker ps --format '{{.Names}}'
   docker stop <container>
   docker cp <container>:/app/database/data/database.sqlite ./cerebro-backup.sqlite
   docker start <container>
   ```
2. (Recomendado) Teste numa cópia no PC antes:
   ```bash
   php artisan cerebro:foto storage/app/foto-antes.json --banco=database/data/prod-copia.sqlite   # com o código ANTIGO
   php artisan cerebro:migrar-copia --banco=database/data/prod-copia.sqlite                       # com o código NOVO
   php artisan cerebro:foto storage/app/foto-depois.json --banco=database/data/prod-copia.sqlite
   ```
   Compare os dois arquivos: a única diferença esperada é a chave `modules`.
3. Rebuild do container: `docker compose up -d --build cerebro`. A migração roda sozinha e liga todos os clientes ao modelo Imobiliária, sem mexer no conteúdo.
4. Para voltar: restaure o arquivo SQLite do backup e a imagem anterior do container.

## Parte 2: catálogo de produtos e vitrine

Módulo **Catálogo** (ligar em Admin → Clientes → Módulos). Serve para imóvel, óculos, roupa, qualquer coisa: cada **tipo de produto** tem seus campos (texto, número, dinheiro, opções, sim/não, imagem).

- **Tipos e campos**: só a Digitaliza edita, em Admin → Clientes → **Catálogo**. Para cada campo dá para escolher se aparece na vitrine (`público`) e se vai para o robô. O modelo Imobiliária já cria o tipo **Imóvel** (aluguel, condomínio, IPTU, quartos, vagas, bairro, aceita pet...). O endereço completo não sai na vitrine nem para o robô.
- **Produtos**: o cliente cadastra em **Produtos** (até 20 fotos, arrastar para ordenar; a primeira é a capa). Situação: Disponível / Reservado / Alugado (indisponível some da vitrine). Corretor só visualiza.
- **Vitrine**: `APP_URL/v/{cliente}` (ex.: `/v/bs-imoveis`), com busca, filtros, galeria e botão "Tenho interesse" no WhatsApp. Logo, cor, WhatsApp e textos em **Vitrine** no painel.
- **Domínio próprio**: coloque `VITRINE_HOST=vitrine.digitalizanegocio.com.br` no `.env` e crie no Cloudflare Tunnel o hostname público `vitrine.digitalizanegocio.com.br` → `http://cerebro:8000`. Endereço fica `vitrine.digitalizanegocio.com.br/bs-imoveis`. Depois rode `php artisan config:cache` (o `start.sh` já faz ao reiniciar).

### API de busca para o robô (n8n)

Mesmo token Bearer do `/brain`, só pela rede interna (`http://cerebro:8000/api/v1`):

| Rota | Para quê |
|---|---|
| `GET /products/types` | Tipos e filtros de cada um (para montar a ferramenta da IA) |
| `GET /products?produto=imovel&bairro=Centro&aluguel_max=1500&quartos_min=2&aceita_pet=1&limite=5` | Busca |
| `GET /products/{id}` | Um produto |

Regra dos filtros: texto/opção = `campo=valor`; número e dinheiro = `campo_min` / `campo_max` (dinheiro em reais); sim/não = `campo=1`; `q` = texto livre. Cada item volta com `summary` (resumo pronto para a IA), `price_text` e `url` (link da vitrine para mandar ao cliente). Detalhes no Swagger (`docs/openapi.yaml`).

### Trocar o site antigo da BS

1. Ligar o módulo Catálogo na BS.
2. Testar a importação sem gravar (o importador lê a API do site atual, segue a paginação e baixa as fotos):
   ```bash
   docker compose exec cerebro php artisan cerebro:importar bs-imoveis http://digitaliza-imoveis-1:PORTA/api/ROTA-DOS-IMOVEIS --token=TOKEN_DO_SITE --dry-run
   ```
   Ele mostra quantos itens achou e como ficou o primeiro. Se algum nome de campo for diferente, passe `--mapa=mapa.json` (veja `DEFAULT_MAP` em `app/Catalog/ProductImporter.php`).
3. Rodar sem `--dry-run`. Pode rodar de novo: ele atualiza pelo id do site antigo, não duplica (fotos só baixam de novo com `--fotos-de-novo`).
4. Conferir a vitrine e os preços.
5. No n8n, trocar a ferramenta de busca de imóveis para `GET http://cerebro:8000/api/v1/products?produto=imovel...`.
6. Só depois de o robô estar usando a API nova, desligar o container `digitaliza-imoveis-1`.

## Segurança do catálogo (revisão de 26/11/2026)

- Campo interno (ex.: endereço) não pode ser título, preço, filtro nem aparecer no cartão. O importador nunca usa o endereço como título.
- Chaves reservadas: `q`, `produto`, `ordem`, `limite`, `page` e nada terminando em `_min`/`_max`. O tipo de produto na vitrine e na API é `?produto=` (o `tipo` é campo do Imóvel).
- Limites: 25 campos ativos (40 no total), 4 no cartão, 6 filtros, 30 opções por lista, 5 tipos de produto, 20 fotos por produto e 6 por envio (servidor aceita 40 MB por envio).
- Importador: o token só vai para o endereço informado; não segue redirecionamento; foto até 5 MB e nunca da rede interna (a não ser o próprio servidor da API).
- Vitrine nova fica fora do Google (`noindex`) até desmarcar "Vitrine em montagem" na tela Vitrine.
- Registro: cadastrar, publicar/tirar e excluir produto e mudanças na vitrine.

## Segurança (revisão da Parte 1)

| O quê | Como ficou |
| --- | --- |
| IP do visitante | Só o cloudflared (rede interna do Docker) pode informar o IP real (`TRUSTED_PROXIES`). Nunca `*`. |
| Porta | O container não publica porta. No túnel, aponte o hostname para `http://cerebro:8000`. |
| Login | 5 tentativas por e-mail + IP (1 min) e 10 por e-mail (15 min), mesmo trocando de IP. Tudo vai para o Registro. |
| API do n8n | Só responde por dentro do Docker. O que chega pelo túnel (cabeçalho `Cf-Connecting-Ip`) recebe 404. |
| Cookie e debug | Em produção o cookie de login só vai por HTTPS. Com `APP_DEBUG=true` em produção, o admin vê um aviso no topo. |
| Custo de IA | Máximo de 200 itens por aba e 60 mil caracteres no prompt. Se passar, a alteração inteira é desfeita. |
| Imagens | Cota de 200 MB por cliente, até 6000 px. Ao salvar, a imagem é regravada sem EXIF (GPS), com a rotação certa e reduzida para 2000 px. Precisa da extensão GD (o Docker instala). |
| Cabeçalhos | `X-Frame-Options`, `nosniff`, `Referrer-Policy`, CSP só com arquivos do próprio sistema. |
| Corretor | Só visualiza o cérebro. Salvar, excluir e restaurar dá 403. |
| Registro | `/admin/registro`: logins, senhas erradas, tokens, suspensões, módulos, estrutura e modelos. |

Os limites ficam em `config/cerebro.php` e podem mudar pelo `.env`.

### docker-compose com o túnel

```yaml
  cloudflared:
    image: cloudflare/cloudflared:latest
    command: tunnel --no-autoupdate run --token ${CLOUDFLARE_TUNNEL_TOKEN}
    restart: unless-stopped
```

No painel do túnel, o hostname público do Digitaliza Brain aponta para `http://cerebro:8000` (o nome do serviço). O n8n continua usando `http://cerebro:8000/api/v1/brain`.

### Proteger o /admin com Cloudflare Access (código por e-mail)

Isso coloca uma segunda trava no painel da Digitaliza: além da senha, quem abrir `/admin` precisa de um código enviado para um e-mail autorizado. Os clientes não são afetados, porque nunca acessam `/admin`.

1. Cloudflare → **Zero Trust** → **Integrations** → **Identity providers** → **Add new identity provider** → **One-time PIN**.
2. **Zero Trust** → **Access controls** → **Applications** → **Create new application** → **Self-hosted and private** → **Add public hostname**: o domínio do Digitaliza Brain com o caminho `admin/*`.
3. Política **Allow**, regra **Include → Emails**: o e-mail do Allan e o do William.
4. Salve e teste numa janela anônima: abrir `/admin/clientes` deve pedir o código antes da tela de login.

## Rodar no PC

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite        # no Windows: type nul > database\database.sqlite
php artisan migrate --seed
php artisan serve
```

O seed cria o admin, a BS Imóveis já preenchida com o conteúdo da planilha e mostra no terminal as senhas e o **token do n8n** (só aparece uma vez).
Revise na BS: endereço e WhatsApp ainda são os de exemplo da planilha.

Testes (isolamento entre clientes, API, versões):

```bash
php artisan test
```

## Rodar no servidor (Docker)

No `docker-compose.yml` que já tem o n8n:

```yaml
  cerebro:
    build: ./cerebro
    restart: unless-stopped
    env_file: ./cerebro/.env
    environment:
      DB_DATABASE: /app/database/data/database.sqlite
      APP_ENV: production
      APP_DEBUG: "false"
    volumes:
      - cerebro_db:/app/database/data
      - cerebro_storage:/app/storage
    # SEM "ports": o cloudflared e o n8n acessam por dentro do Docker (http://cerebro:8000).
    # Porta aberta no servidor = qualquer um na rede fala direto com o sistema, sem passar pelo Cloudflare.

volumes:
  cerebro_db:
  cerebro_storage:
```

Depois: `docker compose up -d --build cerebro` e, só na primeira vez, `docker compose exec cerebro php artisan db:seed --force`.
No `.env`, coloque `APP_URL` com o endereço do túnel (https).

## Ligar no n8n

Nó **HTTP Request**:

- Método: `GET`
- URL (dentro do Docker): `http://cerebro:8000/api/v1/brain`
- Header: `Authorization: Bearer <token da imobiliária>`

Resposta:

```json
{
  "tenant": { "id": 1, "name": "BS Imóveis", "slug": "bs-imoveis", "status": "ativo" },
  "active": true,
  "version": 7,
  "updated_at": "2026-11-23T22:40:00-03:00",
  "bot_name": "William",
  "greeting": "Olá! 😊 Aqui é o William...",
  "max_bot_replies": 4,
  "handoff_message": "Vou chamar um atendente, só um instante.",
  "prompt": "# IDENTIDADE E PERSONALIDADE\nVocê é William...",
  "settings": { "...": "campos das abas de formulário" },
  "sections": { "faq": [ { "question": "...", "answer": "..." } ], "regras": [ ] }
}
```

- Use `prompt` direto como *System Message* do nó de IA.
- Se `active` for `false`, o fluxo para (cliente suspenso).
- Fica em cache por 5 minutos, mas qualquer alteração no painel limpa o cache na hora: o robô usa a versão nova na próxima mensagem.
- No lugar do workflow que lia a planilha a cada 30 min, basta esse nó no começo do atendimento.

## Onde mexer

- **Criar aba ou campo**: pela tela, no editor de estrutura (admin). Os modelos de origem ficam em `database/templates/*.json`.
- **Mudar o texto que a IA recebe**: os formatos de bloco ficam em `app/Brain/PromptFormats.php`; a ordem e os textos de cada aba, no editor.
- **Regras da estrutura** (chaves, núcleo, troca de tipo): `app/Brain/StructureValidator.php`.
- **Atualização do modelo** (comparação em três vias): `app/Brain/TemplateDiff.php` e `app/Brain/TemplateApplier.php`.
- **Histórico**: `app/Brain/Versioner.php` (guarda as últimas 300 versões por cliente).
- **Catálogo**: busca e filtros em `app/Catalog/Catalog.php`, regras dos tipos em `app/Catalog/ProductTypeValidator.php`, importador em `app/Catalog/ProductImporter.php`.

## Próximas etapas (do plano)

Identificar o cliente pelo `phone_number_id` (`GET /tenants/by-phone/...`), agenda de visitas (Parte 3), "Testar meu robô", passagem para humano no Chatwoot. Para mais de 2 clientes, trocar SQLite por PostgreSQL/MySQL no `.env`.
