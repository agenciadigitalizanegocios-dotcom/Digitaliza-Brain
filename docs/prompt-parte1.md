# Prompt: Cérebro do Robô, Parte 1 (abas e campos por cliente)

**Como usar:** abra o Claude Code dentro da pasta do projeto `cerebro` e cole tudo o que está abaixo da linha "COPIE DAQUI". Ele vai ler o código, fazer 5 perguntas, mostrar o plano e só depois programar.

**Premissas que adotei (mude no prompt se não concordar):**
- O banco continua SQLite. A troca para PostgreSQL fica para depois, num passo separado, a não ser que você responda "sim" na pergunta 1.
- O "plano" do cliente continua sendo só um texto. Os módulos são ligados à mão pelo admin.
- A BS Imóveis não pode notar nenhuma diferença: o prompt e o JSON que o n8n recebe ficam iguais, caractere por caractere. Só entra uma chave nova, `modules`.

---

COPIE DAQUI

---

# Tarefa: Cérebro do Robô, Parte 1: abas e campos personalizáveis por cliente

Você vai evoluir este projeto Laravel 12 (Blade + JavaScript puro, sem build). Hoje ele é o painel onde cada cliente da Digitaliza preenche o "cérebro" do robô de WhatsApp (n8n + IA + Chatwoot). As abas e os campos estão fixos em `app/Brain/Sections.php` e foram pensados só para imobiliária. O objetivo é que **nós (admin da Digitaliza) montemos as abas e os campos de cada cliente pela tela**, partindo de um modelo por nicho. O cliente só preenche o conteúdo.

O projeto vai ser feito por partes. **Faça só a Parte 1.**
- **Parte 1 (agora):** estrutura (abas e campos) por cliente, modelos por nicho, alerta de "atualização do modelo pendente", tipos de campo novos (imagem e dinheiro) e o registro de módulos (só liga/desliga).
- Parte 2 (depois, NÃO fazer): módulo Catálogo, um gerenciador genérico de produtos (imóveis, óculos, roupas...). No fim, tudo nele é campo: texto, número, dinheiro, imagem.
- Parte 3 (depois, NÃO fazer): módulo Agenda de visitas.

A Parte 1 precisa deixar o "motor de campos" (tipos, validação, formulário, cartão) pronto para o Catálogo reaproveitar sem reescrever.

## 0. Antes de escrever qualquer código

1. Leia o projeto inteiro: `app/Brain/*`, `app/Models/*` (com `Concerns/BelongsToTenant.php` e `Scopes/TenantScope.php`), `app/Support/CurrentTenant.php`, `app/Http/**`, `routes/*`, `database/**`, `resources/views/**`, `public/js/app.js`, `public/css/app.css`, `tests/Feature/BrainTest.php`, `README.md`, `Dockerfile` e `docker/start.sh`.
2. Me faça **estas perguntas, todas de uma vez**, e espere a resposta. Se eu responder só "ok", use o padrão indicado em cada uma:
   1. Trocar o banco para PostgreSQL agora? *(Padrão: não. Continua SQLite, mas com migrations que funcionem nos dois bancos. Se eu disser sim, a troca vira um passo separado, depois que a Parte 1 estiver passando nos testes.)*
   2. O "plano" do cliente passa a controlar módulos ou limites? *(Padrão: não. Continua texto livre, e os módulos são ligados à mão.)*
   3. O valor da mensalidade dos cursos (modelo Escola técnica) pode ir para a IA falar? *(Padrão: o campo existe, mas com "vai para a IA" desligado.)*
   4. Qual é a URL pública do painel no túnel da Cloudflare (para o `APP_URL` e o link das imagens)? *(Padrão: deixar como está no `.env` e me avisar no relatório final.)*
   5. Os textos de exemplo do modelo Escola técnica (seção 9) podem ficar como estão? *(Padrão: sim.)*
3. Mostre o plano (migrations, classes novas e alteradas, rotas, telas, testes) e **espere meu ok**.
4. Ainda antes de mexer no código atual, faça a "foto de antes" (seção 11, passos 1 e 2).

## 1. O que não pode quebrar (restrições duras)

1. **Stack:** Laravel 12, Blade e JavaScript puro em `public/js/app.js`. Nada de Vite, npm, Inertia, React, Tailwind, Livewire ou pacote JS de CDN. Pacote Composer novo, só se for indispensável e com a minha aprovação no plano.
2. **Isolamento entre clientes:** todo dado de cliente continua com `tenant_id` + `BelongsToTenant`. Nenhuma tela, rota, arquivo ou API pode mostrar dado de outro cliente.
   - Atenção: o `TenantScope` devolve **zero linhas** na web quando não há cliente selecionado. Tabelas que não são de cliente (os modelos de nicho) **não** podem usar `BelongsToTenant`.
   - Nas telas admin que mexem num cliente, chame `app(CurrentTenant::class)->set($tenant)` explicitamente.
3. **API do n8n (`GET /api/v1/brain`):** as chaves de topo que existem hoje continuam com o **mesmo nome e o mesmo formato**: `tenant`, `active`, `version`, `updated_at`, `bot_name`, `greeting`, `max_bot_replies`, `handoff_message`, `prompt`, `settings` e `sections`.
   - `settings` continua sendo um objeto plano `{chave_do_campo: valor}`.
   - `sections` continua sendo `{chave_da_aba: [ {dados do item}, ... ]}`, só com itens ativos e na ordem.
   - É permitido só **acrescentar** a chave de topo `modules` (seção 8).
4. **Conteúdo da BS Imóveis:** nada se perde e nada muda de chave.
   - O conteúdo continua onde está: `brain_settings.data` e `brain_items` (`section` = chave da aba). A migração **não mexe no conteúdo**, só cria a estrutura ao lado.
   - Para a BS, o `prompt` e o JSON da API depois da migração ficam **idênticos ao de antes**, caractere por caractere, com exceção da chave nova `modules`.
5. **Deploy:** o `docker/start.sh` roda `php artisan migrate --force` toda vez que o container sobe. Então a migração roda sozinha em produção. Ela precisa ser segura, idempotente e ter um `down()` que desfaz só o que ela criou.
6. **Português do Brasil** em todos os textos da tela, nas mensagens de validação, nos comentários e no README.

## 2. Decisões já tomadas (não reabra)

1. Só o admin (papel `admin`) cria e edita a estrutura (abas, campos, modelos). Dono e corretor só preenchem conteúdo e nunca veem telas de estrutura.
2. Todo cliente nasce de um modelo de nicho, e a estrutura é **copiada** para ele. Depois disso, a cópia é independente.
3. Quando um modelo muda, **o cliente fica parado**: estrutura, prompt e API continuam iguais. O cliente só ganha o selo "Atualização do modelo pendente", e o admin vê o que mudou e decide o que aplicar.
4. Nichos agora: **Imobiliária** e **Escola técnica** (pensado para a Unipacs). Existe também o modelo **"Em branco"**, que tem só o núcleo e serve para começar um nicho novo.
5. Tipos de campo: os de hoje (`text`, `textarea`, `url`, `number`, `select`, `checkbox`) + `image` + `money`.
6. **Abas** (conhecimento que a IA lê, vira prompt) são uma coisa separada de **módulos** (ferramentas que a IA usa, ligadas por cliente). Nesta parte, os módulos são só registro e liga/desliga.

## 3. Modelo de dados (o mais simples possível)

A estrutura é um **documento JSON** no mesmo formato que `Sections::all()` tem hoje, com alguns atributos a mais. Não crie tabelas de abas e de campos. Assim o resto do código continua recebendo arrays como hoje.

**Migrations:**
- `brain_templates` (sem tenant): `id`, `name`, `slug` único, `description`, `version` (int, começa em 1 e sobe a cada alteração salva), `structure` (json), `timestamps`.
- `brain_settings` ganha:
  - `structure` (json): a estrutura do cliente;
  - `template_base` (json): a cópia do modelo no momento em que foi aplicado pela última vez, usada para comparar.
- `tenants` ganha:
  - `template_id` (nullable, FK para `brain_templates`);
  - `template_version` (int): a versão do modelo aplicada;
  - `modules` (json): ex.: `{"catalogo": false, "agenda": false}`.

**Formato da estrutura:**

```json
{
  "tabs": [
    {
      "key": "perguntas", "label": "Perguntas ao cliente", "icon": "❓",
      "type": "items", "intro": "...", "singular": "pergunta", "ordered": true,
      "title_field": "question", "badge_field": "required", "badge_text": "Obrigatória",
      "archived": false,
      "ai": { "format": "perguntas", "title": "PERGUNTAS PARA QUALIFICAR O CLIENTE",
              "before": "Descubra estas informações...", "after": "Só ofereça marcar a visita...",
              "line": "", "order": 4 },
      "fields": [
        { "key": "question", "label": "Pergunta", "type": "text", "required": true,
          "placeholder": "...", "help": "", "options": null, "default": null,
          "min": null, "max": null, "send_to_ai": true, "archived": false }
      ]
    }
  ],
  "closing": ["Use só as informações acima e as que vierem das ferramentas (imóveis e horários de visita).",
              "Se não souber algo, diga que vai confirmar com a equipe. Nunca invente."],
  "starter_items": []
}
```

- A ordem do array `tabs` é a ordem do menu. `ai.order` é a ordem no prompt. As duas são separadas porque, na BS, o prompt já tem uma ordem diferente da do menu.
- `starter_items` (opcional, só em modelos) são itens de conteúdo copiados **uma única vez**, na criação do cliente. Nunca são copiados de novo ao aplicar uma atualização.

**Arquivos dos modelos:** `database/templates/imobiliaria.json`, `escola-tecnica.json` e `em-branco.json`. A migração de dados insere os três (se o slug ainda não existir) e liga **todos** os clientes que já existem ao modelo Imobiliária:
- `structure` = `template_base` = estrutura da Imobiliária;
- `template_version` = 1;
- atenção: hoje o `TenantController@store` não cria `brain_settings`; a linha só nasce no primeiro `BrainSetting::current()`. Um cliente criado e nunca aberto **não tem linha**. Para esses, a migração cria a linha com `data = []` e `version = 0` (o mesmo que o `current()` criaria) e já com `structure` e `template_base`. Percorra a tabela `tenants`, não a `brain_settings`;
- faça isso direto no query builder (`DB::table`), sem os models, para não depender do `TenantScope`;
- o `down()` remove as colunas e a tabela `brain_templates`. As linhas de `brain_settings` que ela criou ficam (são iguais às que o `current()` criaria na primeira tela aberta, sem conteúdo nenhum).

O `imobiliaria.json` é a tradução **exata** do `Sections.php` de hoje: mesmas chaves, rótulos, ícones, placeholders, ajudas, opções, ordem, `title_field`, `badge_field` e `badge_text`. Depois disso, o `Sections.php` deixa de existir como fonte. Troque-o por uma classe `App\Brain\Structure`, que lê a estrutura do cliente atual e mantém os métodos que o código já usa (`all`, `get`, `settingsFields`, `display`, `itemTitle`), ignorando o que está arquivado.

**Um único caminho para dar estrutura a um cliente.** Crie `App\Brain\TemplateApplier::provision(Tenant $tenant, string $slugDoModelo)`:
- pega a linha de `brain_settings` do cliente com `firstOrCreate` (com `data = []` e `version = 0`, igual ao `BrainSetting::current()` de hoje), ou seja, funciona com ou sem a linha já existir e **nunca cria uma segunda** (`brain_settings.tenant_id` é `unique`);
- grava nela `structure` e `template_base`, e grava no tenant `template_id` e `template_version`;
- copia os `starter_items` (só se o cliente ainda não tiver nenhum item);
- não mexe em `data` nem na versão.

Use esse mesmo método em **todos** os lugares que criam cliente:
- no `Admin\TenantController@store` (dentro da transação que já existe);
- no `DatabaseSeeder`, para a BS Imóveis, com o modelo `imobiliaria`, **dentro do `if (! BrainSetting::exists())`, logo depois de `$this->seedBrain()` e antes de `Versioner::record`**. A ordem importa:
  - se vier antes do `if`, ele cria a linha, o `exists()` passa a dar verdadeiro e a BS fica sem conteúdo, sem versão e sem token;
  - se vier antes do `seedBrain()`, o `BrainSetting::create` dele estoura a chave única de `tenant_id`;
  - depois do `seedBrain()`, ele só completa a linha que já existe, e o `Versioner::record` já guarda a estrutura na primeira versão.
  - Isso é obrigatório: no `migrate:fresh --seed` a migração de dados roda com o banco vazio e não liga ninguém, então sem isso a BS do seed ficaria sem estrutura. Não mude mais nada no seeder (ele continua imprimindo os acessos e o token);
- no `setUp` de `tests/Feature/BrainTest.php`, para os clientes A e B (`imobiliaria`), e em todo teste novo que crie cliente.

**Cliente sem estrutura gravada** (dado antigo, cliente criado por outro caminho): use a estrutura do modelo **Imobiliária**, que é exatamente o comportamento de hoje, e registre um aviso no log. Nunca caia no "Em branco" de forma silenciosa, porque isso tiraria abas do prompt.

## 4. Tipos de campo (motor reaproveitável)

Crie `app/Fields/FieldTypes.php`, um registro central. Cada tipo sabe:
- as regras de validação;
- como ler o valor do request (mantendo o valor antigo quando for o caso);
- como mostrar no cartão;
- como vira texto para a IA;
- como sai na API.

O `FieldValidator`, o partial `resources/views/brain/partials/field.blade.php` e o cartão da lista passam a usar esse registro. Os dois recebem só a definição do campo, sem nada de "aba" ou de "cérebro", para o Catálogo usar igual na Parte 2.

| Tipo | Na tela | Guarda | No prompt | Na API |
|---|---|---|---|---|
| text | input | texto, máx. 500 | texto | texto |
| textarea | textarea que cresce | texto, máx. 5000 | texto | texto |
| url | input url | texto, máx. 255 | texto | texto |
| number | input numérico, min/max | inteiro | número | inteiro |
| select | opções chave → rótulo | chave | rótulo | chave |
| checkbox | sim/não | bool | "Sim"/"Não" | bool |
| money | input com máscara em JS ("1.234,56") | **inteiro em centavos** | "R$ 1.234,56" | inteiro em centavos |
| image | upload com prévia, trocar e remover | caminho relativo no disco `public` | **não entra** | URL absoluta |

**Detalhes de `money`:**
- Conversão no servidor (um método só, `FieldTypes::parseMoney`, que o Catálogo vai reaproveitar). Antes, tire "R$" e espaços.
  - Tem vírgula: a vírgula é o decimal e os pontos são milhar. "1.234,56" → 123456; "1234,5" → 123450.
  - Não tem vírgula e tem **um** ponto seguido de 1 ou 2 dígitos no fim: o ponto é decimal. "12.50" → 1250; "12.5" → 1250.
  - Não tem vírgula e os pontos separam grupos de 3 dígitos: são milhar. "1.234" → 123400; "1.234.567" → 123456700.
  - Só dígitos: reais inteiros. "1234" → 123400.
  - Recuse, com mensagem em português: valor negativo, mais de 2 casas decimais ("12,345"), letras ou formato misturado ("1,234.56"), e valor acima de R$ 99.999.999,99.
- A máscara em JS formata no padrão brasileiro enquanto digita, mas a regra que vale é a do servidor.
- Valor vazio é `null`.

**Detalhes de `image`:**
- Aceita só jpg, jpeg, png e webp, até 5 MB. SVG não é aceito.
- Salve em `storage/app/public/tenants/{tenant_id}/{nome aleatório de 40 caracteres}.{ext}`. O nome aleatório existe porque a URL pública é acessível a quem tiver o link, e o n8n precisa disso para mandar a foto no WhatsApp. Escreva isso no README.
- Os formulários com imagem passam a ter `enctype="multipart/form-data"`.
- Editar sem enviar arquivo novo mantém a imagem atual. O checkbox "remover imagem" limpa o valor.
- **Não apague o arquivo ao trocar ou remover**, porque o histórico de versões ainda pode apontar para ele. Crie o comando `php artisan cerebro:limpar-imagens`:
  - por padrão, só lista os arquivos que não são usados pelo conteúdo atual nem por nenhuma versão guardada;
  - com `--apagar`, apaga esses arquivos.
- O PHP padrão aceita só 2 MB de upload. Ajuste no `Dockerfile` (ex.: `upload_max_filesize=6M` e `post_max_size=8M` num `.ini`).
- Coloque `php artisan storage:link --force` no `docker/start.sh`.
- A URL da imagem usa o `APP_URL`, que tem que ser a URL pública do túnel.

**Regras gerais dos valores:**
- Campo arquivado não é validado, não aparece na tela nem no prompt, e **o valor dele é preservado**.
- Cuidado: hoje o `BrainItemController@update` substitui o `data` inteiro. Passe a fazer um merge (valores novos por cima do `data` antigo), senão o valor dos campos arquivados se perde na primeira edição.
- Campo obrigatório novo vale só para os próximos salvamentos. Itens antigos sem o valor continuam funcionando, e o prompt só pula o que está vazio.

## 5. Regras da estrutura (validar no servidor, em `App\Brain\StructureValidator`)

1. Chaves de aba e de campo:
   - seguem o padrão `^[a-z][a-z0-9_]{0,39}$`, são geradas a partir do nome e **nunca mudam** depois de salvas;
   - chave de aba não pode ser `versoes`, `itens`, `estrutura`, `modulos` nem `admin` (a aba vira URL em `/cerebro/{tab}`);
   - **chave arquivada continua ocupada para sempre** (o valor antigo dela ainda está guardado). Se a chave gerada a partir do nome já existir, ativa ou arquivada, acrescente `_2`, `_3`... até ficar livre, **cortando a base antes** para que a chave final continue com no máximo 40 caracteres (o limite da regex e de `brain_items.section`, que é `string(40)`). Exemplo: base de 40 caracteres + `_2` → os 38 primeiros + `_2`. Exemplo: arquivar "Preço" (`preco`, text) e criar outro "Preço" do tipo money gera `preco_2`, e o valor antigo nunca aparece no campo novo.
2. Chaves de campo:
   - nas abas de formulário, são **únicas entre todas as abas de formulário**, porque `brain_settings.data` é um JSON plano só;
   - nas abas de lista, são únicas dentro da aba.
3. Nada é excluído de verdade. "Excluir" aba, campo ou opção de select = **arquivar**, e dá para desarquivar.
4. Mudar o tipo de um campo só é permitido entre `text` e `textarea`. Os outros tipos ficam bloqueados, com uma mensagem clara.
   - `text` → `textarea`: sempre permitido.
   - `textarea` → `text`: bloqueado se algum valor guardado (conteúdo atual, itens pausados incluídos) passar de 500 caracteres. A mensagem diz quantos itens passam do limite, ex.: "3 itens têm mais de 500 caracteres; encurte-os antes de trocar".
5. As chaves das opções de select não mudam, só os rótulos. Arquivar uma opção que está em uso mostra quantos itens a usam e pede confirmação. A opção continua aparecendo nesses itens.
6. `title_field` tem que ser um campo ativo da aba, do tipo `text`, `textarea` ou `select`. `badge_field` (opcional) tem que ser um campo ativo do tipo `text`, `number`, `select`, `checkbox` ou `money`. O `text` precisa estar na lista porque a Imobiliária de hoje usa `badge_field = price`, e `price` é `text` (aceita "Sob consulta"); a view atual já mostra selo de campo text.
   - Os três modelos (`imobiliaria.json`, `escola-tecnica.json`, `em-branco.json`) **precisam passar** no `StructureValidator` sem nenhum ajuste. Isso vira teste (12.14). Se algum atributo de hoje for recusado por uma regra desta seção, a regra está errada: pare e me avise, não mude o modelo.
7. **Núcleo** (toda estrutura tem): as abas com formato `negocio`, `identidade`, `objetivo`, `regras` e `faq` (seção 6).
   - Não podem ser arquivadas.
   - **O formato delas não pode ser trocado** (nem para `lista`/`campos`, nem para outro formato), e o tipo formulário/lista também não. Isso garante que "Você é {bot_name}...", o "## PASSAGEM PARA HUMANO" e as chaves de topo da API (`bot_name`, `greeting`, `max_bot_replies`, `handoff_message`) nunca somem.
   - Os campos que o formato usa ficam travados: sem arquivar, sem mudar o tipo; nos selects `emojis` e `kind`, sem mexer nas chaves das opções.
   - Rótulo, ajuda, placeholder, ícone, textos da IA e ordem continuam editáveis, e dá para **acrescentar** campos a essas abas.
   - Na tela, os itens travados aparecem com 🔒 e sem os botões proibidos.
8. Precisa haver pelo menos uma aba ativa.

## 6. Montagem do prompt (`PromptBuilder` lê a estrutura)

Para a BS sair idêntica, **o código de hoje vira "formatos de bloco"**. Não reescreva o texto do zero. Crie `App\Brain\PromptFormats`, onde cada formato é o trecho atual do `PromptBuilder` movido para lá, com a mesma saída:

| Formato | Usado em (Imobiliária) | Campos que usa (ficam travados) |
|---|---|---|
| `identidade` | Personalidade | bot_name, presentation, tone_of_voice, personality, emojis, max_sentences, greeting (+ company_name e segment da aba de formato `negocio`) |
| `negocio` | Negócio | company_name, segment, about, address, service_area, website |
| `objetivo` | Objetivo e passagem | objective, max_bot_replies, handoff_message, after_hours_message |
| `perguntas` | Perguntas ao cliente | question, why, required |
| `regras` | Regras | kind (nunca/sempre/humano), rule, example. Inclui o "## PASSAGEM PARA HUMANO", como hoje |
| `horarios` | Horários e contato | kind, label, value, note. Inclui a linha "Fora do horário...", como hoje |
| `servicos` | Serviços e taxas | name, description, price, unit, conditions |
| `politicas` | Garantias e políticas | topic, rule, details |
| `passos` | Como contratar | title, description |
| `faq` | Perguntas frequentes | question, answer, keywords |
| `campos` | (genérico, abas de formulário) | uma linha `Rótulo: valor` por campo preenchido |
| `lista` | (genérico, abas de lista) | ver abaixo |

**Regras da montagem:**
- Os blocos saem na ordem de `ai.order`. Cada bloco usa `ai.title` como título (`# TÍTULO`).
- As frases fixas que hoje citam imóvel ou visita viram texto editável da aba, gravado com o texto atual:
  - em `perguntas`, a frase de abertura vai para `ai.before` e a frase final vai para `ai.after`;
  - o bloco `IMPORTANTE` vem de `closing`.
- `ai.before` e `ai.after` também valem nos outros formatos: se estiverem preenchidos, entram antes e depois das linhas. Na Imobiliária, ficam vazios em todas as abas menos em `perguntas`.
- Campos extras (acrescentados pelo admin) numa aba com formato específico entram no fim do bloco como `Rótulo: valor`. Nas listas, entram no fim da linha de cada item.
- Formato `lista`:
  - se `ai.line` estiver preenchido (ex.: `- {nome} ({turnos}): {sobre}`), troque cada `{chave}` pelo valor formatado para a IA e limpe os espaços que sobrarem;
  - se não estiver, use `- {valor do title_field}. Rótulo: valor; Rótulo: valor`;
  - se a aba for `ordered`, numere `1.`, `2.`...
- Se não existir uma aba ativa de formato `horarios`, a linha "Fora do horário de atendimento: ..." vai para o fim do bloco `objetivo`.
- Não entram no prompt: imagens, campos com `send_to_ai = false`, itens pausados, abas e campos arquivados, e blocos vazios.
- Um formato específico só pode ser escolhido numa aba se os campos que ele usa existirem com o tipo certo. Nas abas **fora do núcleo** (ex.: Serviços, Horários), o admin pode trocar o formato específico para `lista` ou `campos` a qualquer momento, e aí os campos destravam. Nas abas do núcleo isso é proibido (seção 5.7).

## 7. Versões, cache e histórico

- O `Versioner::record` passa a guardar também `structure` dentro do `snapshot`.
- Toda alteração de estrutura de um cliente (aba, campo, aplicar atualização) gera uma versão com descrição clara (ex.: `Estrutura: adicionou o campo "Turnos" em Cursos`) e limpa o cache da API.
- Alterar um modelo não gera versão em nenhum cliente. Só sobe `brain_templates.version`.
- **Restaurar uma versão restaura só o conteúdo.** A estrutura continua a atual, para o dono não conseguir mudar a estrutura pelo histórico. Isso funciona porque as chaves nunca mudam e nada é excluído: o conteúdo de campos arquivados fica guardado e escondido.
- A tela "ver versão" mostra o conteúdo com a estrutura daquela foto. Fotos antigas, sem `structure`, usam a estrutura atual.
- A migração **não cria versão nova**: a API continua servindo a última foto da BS, sem mudança.
- Na API, `sections`, `settings` e `prompt` saem da última foto, como hoje. Para formatar imagem (URL) e omitir o que está arquivado, use a estrutura da própria foto, ou a atual se a foto não tiver estrutura.

## 8. Módulos

- Crie `App\Brain\Modules`, um registro fixo em código: `catalogo` ("Catálogo de produtos") e `agenda` ("Agenda de visitas"). Os dois aparecem com o selo "em breve".
- O admin liga e desliga os módulos de cada cliente. O valor é gravado em `tenants.modules`.
- Não existe tela de módulo para o cliente nesta parte.
- A API acrescenta a chave de topo `"modules": {"catalogo": false, "agenda": false}`, para o n8n saber quais ferramentas pode chamar.
- Não crie nada do Catálogo nem da Agenda além disso.

## 9. Modelos iniciais

**Imobiliária:** exatamente o `Sections.php` de hoje (seção 3), com os formatos da tabela da seção 6.
- `ai.order` igual à ordem atual do prompt: identidade, negócio, objetivo, perguntas, regras, horários, serviços, políticas, como contratar, faq.
- Títulos, `before`, `after` e `closing` com o texto atual.

**Em branco:** só as abas do núcleo (Negócio, Personalidade, Objetivo e passagem, Regras, Perguntas frequentes), com os mesmos campos da Imobiliária e placeholders neutros.
- `closing`: "Use só as informações acima." e "Se não souber algo, diga que vai confirmar com a equipe. Nunca invente."

**Escola técnica** (são textos de exemplo, todos editáveis depois). Use o núcleo com placeholders de escola, mais estas abas. Na lista, o formato de cada aba vem entre parênteses e `*` marca os campos obrigatórios.
- **A escola** (núcleo, chave `negocio`). Placeholder do "Sobre": "Escola técnica com 2 unidades, cursos por semestre, três turnos...".
- **Personalidade** (núcleo).
- **Objetivo e passagem** (núcleo). Placeholder do objetivo: "Entender o curso e o turno que a pessoa procura e levar até a matrícula. Meta: encher as turmas."
- **Perguntas ao interessado** (`perguntas`):
  - placeholder: "Qual curso você tem interesse?";
  - rótulo do checkbox: "Obrigatória antes de oferecer a matrícula";
  - `ai.after`: "Só ofereça a matrícula depois de ter as obrigatórias.";
  - `ai.before`: igual ao da Imobiliária.
- **Cursos** (`lista`, chave `cursos`, `title_field` = `nome`):
  - nome* (text);
  - duração em semestres (number, de 1 a 12);
  - turnos (text, ex.: "manhã, tarde e noite");
  - unidades onde tem (text);
  - requisitos para entrar (textarea);
  - sobre o curso (textarea);
  - foto (image);
  - mensalidade (money, `send_to_ai` conforme a pergunta 3).
  - `ai.before`: "Apresente o curso que combina com o interesse da pessoa, falando da profissão. Não despeje a ficha técnica inteira."
- **Unidades, horários e contato** (`horarios`). Use os tipos que já existem (horário, contato, endereço). O rótulo indica a unidade.
- **Matrícula e documentos** (`passos`, `ordered`).
- **Pagamento e condições** (`politicas`).
- **Regras** (núcleo). Placeholder: "Nunca prometer emprego, salário ou renda."
- **Perguntas frequentes** (núcleo).
- `starter_items` com estas regras:
  - nunca: "Prometer emprego, salário ou renda. Nunca dizer \"você vai ganhar\".";
  - sempre: "Pode citar fatos verdadeiros da escola, como ter ex-alunos contratados na área, sem transformar isso em promessa.".
- `closing`: "Use só as informações acima." e "Se não souber algo, diga que vai confirmar com a equipe. Nunca invente."

## 10. Telas (admin)

Use o mesmo layout, CSS e `<dialog>` de hoje. Tudo fica sob o middleware `admin`.

1. **Modelos** (`/admin/modelos`):
   - lista com nome, número de abas, versão e quantos clientes usam;
   - botões: editar estrutura, duplicar (nicho novo a partir de outro, versão 1) e renomear;
   - excluir só se nenhum cliente usar o modelo.
2. **Editor de estrutura**: o mesmo editor serve para modelo (`/admin/modelos/{id}/estrutura`) e para cliente (`/admin/clientes/{id}/estrutura`).
   - Coluna de abas: adicionar, editar (rótulo, ícone, tipo formulário/lista, ajuda ao cliente, singular, em ordem sim/não, campo título, campo selo e texto do selo, formato e textos da IA, ordem no prompt), ↑↓ no menu, arquivar e desarquivar.
   - Lista de campos da aba: adicionar, editar (rótulo, tipo, obrigatório, placeholder, ajuda, opções do select com adicionar e arquivar linha, padrão, min/max, vai para a IA), ↑↓, arquivar e desarquivar.
   - Bloco **"Fechamento do prompt"** (modelo e cliente): um textarea com as frases de `closing`, uma por linha. Ele gera o `# IMPORTANTE` do fim do prompt.
   - Bloco **"Itens iniciais"** (só no editor de modelo): para cada aba de lista, os `starter_items` daquela aba, com adicionar, editar e excluir, usando o mesmo partial de campo do cliente. Mudar itens iniciais não sobe a versão do modelo e não gera pendência (eles só valem na criação de cliente).
   - Ao **duplicar** um modelo, mostre um aviso no topo do novo: "Revise o fechamento do prompt e os itens iniciais: eles vieram do modelo de origem." Exemplo: duplicar Imobiliária para criar Ótica traz a frase "(imóveis e horários de visita)", que o admin precisa trocar.
   - Embaixo, uma **prévia somente leitura**, renderizada com o mesmo partial de campo que o cliente vê.
   - Cada ação é um formulário normal com POST e redirect, com o JavaScript só para máscaras, autosize e modais (como hoje). Não faça uma tela que salva tudo de uma vez via JS.
3. **Clientes** (tela que já existe):
   - ao criar um cliente, o admin escolhe o modelo (obrigatório), e o `TemplateApplier::provision` da seção 3 faz a cópia da estrutura, grava `template_base` e `template_version` e copia os `starter_items`;
   - na lista, aparecem a coluna "Modelo", o selo **"Atualização pendente"** quando `template_version` < `brain_templates.version`, e os links "Estrutura", "Módulos" e "Ver atualização".
4. **Ver atualização do modelo** (`/admin/clientes/{id}/atualizacao`): comparação em três vias, por chave, entre `template_base` (o que o cliente recebeu), o modelo atual e a estrutura atual do cliente. A tela lista cada mudança com um checkbox:
   - aba nova ou campo novo: marcado;
   - atributo alterado no modelo e não mexido no cliente: marcado;
   - atributo alterado no modelo **e** personalizado no cliente: desmarcado, mostrando "no cliente: X / no modelo: Y";
   - arquivado no modelo: desmarcado, com o aviso "isso tira o conteúdo do prompt";
   - abas e campos criados só no cliente: nunca aparecem e nunca são tocados;
   - **conflito de chave** (o modelo ganhou uma aba ou campo com uma chave que o cliente já usa, ativa ou arquivada, criada só nele): aparece desmarcado e **sem poder marcar**, com o aviso "o cliente já tem `turnos` (tipo X); renomeie a chave no modelo ou resolva à mão". Aplicar nunca sobrescreve nem duplica essa chave. Para evitar isso, o editor de modelo também gera a chave com sufixo quando ela já existir no próprio modelo.
   - O `closing` conta como um atributo só, com a mesma regra (mudou no modelo e não no cliente: marcado; mudou nos dois: desmarcado, mostrando os dois textos). `starter_items` nunca entram na comparação.
   - Botão **Aplicar selecionadas**: passa pelo `StructureValidator`, grava a estrutura, atualiza `template_base` e `template_version`, gera versão e limpa o cache.
   - Botão **Dispensar**: atualiza `template_base` e `template_version` sem mudar nada.
   - Aplicar **nunca** apaga conteúdo.

**Painel do cliente:** continua igual na aparência, mas montado pela estrutura.
- `/cerebro` abre a primeira aba ativa (hoje está fixo em `negocio`).
- Abas arquivadas dão 404 para o cliente.
- O dono não vê nenhum link de estrutura, modelo ou módulo. Todas essas rotas devolvem 403 para ele.

## 11. Ordem de trabalho

1. **Cópia do banco de produção.** Você roda no meu PC e a produção roda num container no servidor de casa, então você não alcança o servidor. Me passe estes comandos para eu rodar no servidor e **espere eu dizer que a cópia está no lugar**:
   ```bash
   docker ps --format '{{.Names}}'                       # achar o nome do container do cérebro
   docker exec <container> printenv DB_DATABASE          # vazio = caminho padrão do docker/start.sh
   docker stop <container>                               # parar uns segundos, para a cópia sair consistente
   docker cp <container>:/app/database/data/database.sqlite ./cerebro-prod-copia.sqlite
   docker start <container>
   ```
   - Se o `DB_DATABASE` vier preenchido, troque o caminho do `docker cp` por ele.
   - Eu trago o arquivo para o PC e ponho em `database/data/prod-copia.sqlite` (a pasta `database/data` já está no `.gitignore`). Guarde também uma cópia intocada, `database/data/prod-original.sqlite`, que serve de backup.
   - Nunca rode nada contra o banco do servidor.
2. **Foto de antes**, com o código atual, antes de mudar qualquer coisa:
   - crie `php artisan cerebro:foto {arquivo} {--banco=}`, que grava num JSON, para cada cliente, a resposta da API (sem `updated_at`) e o prompt remontado a partir do conteúdo atual. A opção `--banco` aponta a conexão `sqlite` para outro arquivo só durante o comando (troque `database.connections.sqlite.database` no config e chame `DB::purge('sqlite')`). Assim funciona igual no Windows e no Linux, sem mexer no `.env`;
   - rode `php artisan cerebro:foto storage/app/foto-antes.json --banco=database/data/prod-copia.sqlite`;
   - rode também `migrate:fresh --seed` num banco de teste e guarde o prompt e o JSON da BS do seed em `tests/fixtures/parte1/bs_prompt.txt` e `tests/fixtures/parte1/bs_api.json`. No JSON, **remova `updated_at`** antes de gravar (ele é o horário em que o seed rodou e muda a cada execução). O teste 12.1 remove `updated_at` e `modules` da resposta atual e compara todo o resto, sem afrouxar mais nada.
3. Motor de campos (`FieldTypes`, partial, validador, money, image). Os testes atuais continuam passando.
4. Estrutura JSON, migrations, os três modelos, `Structure` no lugar de `Sections`, `PromptFormats` e `TemplateApplier` (já chamado no `DatabaseSeeder`, no `TenantController@store` e no `setUp` dos testes). O teste de prompt idêntico (fixture) passa.
5. Editor de estrutura, modelos, criação de cliente com modelo, atualização pendente.
6. Módulos e a chave `modules` na API.
7. Rode as migrations novas na cópia com `php artisan cerebro:migrar-copia --banco=database/data/prod-copia.sqlite` (comando pequeno que aponta a conexão `sqlite` para o arquivo, do mesmo jeito do `cerebro:foto`, e chama `migrate`; assim não depende de variável de ambiente). Depois rode `php artisan cerebro:foto storage/app/foto-depois.json --banco=database/data/prod-copia.sqlite` e compare com a foto de antes. A única diferença aceita é a chave `modules`. Se aparecer qualquer outra, pare e me mostre o diff.
8. README atualizado: como criar um modelo, como aplicar uma atualização, tipos de campo, limpeza de imagens, backup.

Rode `php artisan test` ao fim de cada passo. Não avance com teste quebrado.

## 12. Testes obrigatórios (somar aos que existem e adaptar estes)

1. O prompt e a API da BS do seed são idênticos às fixtures de antes (tirando só `updated_at` e `modules` da comparação). A BS do seed e os clientes A e B do `setUp` têm estrutura da Imobiliária (todas as 10 abas abrem).
2. A migração liga os clientes existentes à Imobiliária, sem alterar `brain_settings.data` nem `brain_items`. Um cliente criado e nunca aberto (sem linha em `brain_settings`) ganha a linha com `data = []`, `version = 0` e a estrutura. O `down()` remove as colunas e a tabela que ela criou. O `migrate:fresh --seed` termina sem erro, e a BS do seed tem conteúdo, versão 1, token impresso e estrutura.
3. Um cliente criado a partir de um modelo recebe uma cópia, e editar o modelo não muda o cliente: o prompt e a API continuam iguais.
4. Editar o modelo liga o selo de pendente. Aplicar adiciona o que é novo, não apaga conteúdo, não desfaz uma personalização do cliente (que vem desmarcada) e gera versão. Dispensar tira o selo sem mudar a estrutura.
5. Não dá para arquivar uma aba do núcleo, nem trocar o formato dela (para `lista`, `campos` ou outro), nem arquivar ou mudar o tipo de um campo travado. `textarea` → `text` é recusado quando há valor com mais de 500 caracteres.
6. Renomear não muda a `key`. Chave repetida entre abas de formulário é recusada. Chave reservada é recusada. Criar um campo com o mesmo nome de um campo arquivado gera `_2`, e o valor antigo não aparece nele. Uma chave de 40 caracteres repetida gera `_2` com a base cortada e continua com 40 caracteres. Na atualização do modelo, uma chave nova que já existe só no cliente aparece como conflito, não pode ser aplicada e não muda o campo do cliente.
7. Arquivar um campo com conteúdo: some do prompt e da tela, editar o item não apaga o valor, e desarquivar traz o valor de volta.
8. Money: "1.234,56" → `123456` e aparece como "R$ 1.234,56" no prompt; "12.50" → `1250`; "1.234" → `123400`; "1234" → `123400`; "-5", "12,345" e "1,234.56" são recusados.
9. Image:
   - recusa svg, pdf e arquivo acima de 5 MB;
   - grava o arquivo na pasta do cliente;
   - não entra no prompt;
   - sai na API como URL;
   - editar sem arquivo mantém a imagem;
   - `cerebro:limpar-imagens` não apaga imagem usada por uma versão guardada.
10. Formato `lista` com `ai.line`. `send_to_ai = false` e itens pausados não entram no prompt.
11. Restaurar uma versão antiga volta o conteúdo e mantém a estrutura atual.
12. O dono recebe 403 em todas as rotas de modelos, estrutura, atualização e módulos. O admin editando o cliente A nunca lê nem grava nada do cliente B.
13. A API traz `modules` e continua com todas as chaves de topo antigas, no mesmo formato.
14. Os três modelos passam no `StructureValidator` sem ajuste (inclusive o `badge_field = price`, do tipo text, da Imobiliária), e uma edição qualquer de estrutura da BS (ex.: mudar o rótulo de uma aba) é aceita.
15. O `closing` editado pela tela muda o bloco `# IMPORTANTE` do prompt do cliente. Os itens iniciais editados no modelo são copiados para um cliente novo e não geram pendência nos clientes antigos.

## 13. Fora do escopo (não faça)

Não faça nesta parte:
- Catálogo e Agenda;
- troca de banco (a não ser que eu responda "sim" na pergunta 1, e mesmo assim em passo separado);
- limites por plano;
- novo papel de usuário;
- exclusão definitiva de aba ou campo;
- qualquer mudança no formato das chaves que a API já devolve;
- reescrever o texto do prompt da Imobiliária.

## 14. Relatório final (o que me entregar)

1. A lista de arquivos criados e alterados, com uma linha sobre cada um.
2. A saída de `php artisan migrate:fresh --seed` e de `php artisan test`.
3. O resultado da comparação da foto de antes com a de depois, na cópia do banco de produção.
4. O que eu preciso conferir à mão: o `APP_URL`, o limite de upload no Docker, o `storage:link` e os textos de exemplo do modelo Escola técnica.
5. Os passos de deploy: backup, rebuild do container (a migração roda sozinha) e como voltar se der problema (restaurar o arquivo SQLite e a imagem anterior).
6. As dúvidas que ficaram em aberto.

---

ATÉ AQUI

---

## Onde cada pedido está atendido

| # | Pedido | Onde está no prompt |
|---|---|---|
| 1 | Abas e campos diferentes por cliente, criados por nós | Seções 2.1, 3 e 10.2 |
| 2 | Só a Digitaliza mexe na estrutura | Seções 2.1, 10 e 12.12 |
| 3 | Modelo de nicho; ao mudar, cliente parado e alerta de pendente | Seções 2.3, 10.3, 10.4 e 12.3–4 |
| 4 | Nichos imobiliária e Unipacs | Seção 9 (inclui as regras de não prometer emprego nem renda) |
| 5 | Todos os tipos de hoje + imagem (+ dinheiro para a Parte 2) | Seção 4 |
| 6 | Abas separadas de módulos | Seção 8 |
| 7 | Só a Parte 1, com caminho para Catálogo e Agenda | Introdução, seções 4 e 13 |
| 8 | Stack sem build | Seção 1.1 |
| 9 | Não quebrar isolamento nem a API do n8n | Seções 1.2, 1.3 e 12.1, 12.12, 12.13 |
| 10 | Não perder conteúdo da BS | Seções 1.4, 3, 11.2, 11.7 e 12.1–2 |
| 11 | Pontos não decididos (plano, PostgreSQL) | Perguntas 1 e 2 da seção 0, com padrão seguro |
| 12 | Perguntar antes e entregar revisado | Seções 0, 11 e 14 |
