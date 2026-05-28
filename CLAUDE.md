# EmagreSer — Documentação do Projeto

## Visão Geral

Plataforma de marketing e automação de comunicação para o **Programa EmagreSer** (Psicóloga Daniely + Nutricionista Ira). O sistema capta leads via landing pages, identifica o perfil sabotador do lead via quiz, e executa sequências automáticas de e-mail e WhatsApp.

---

## Stack Tecnológica

| Camada | Tecnologia |
|--------|-----------|
| Hospedagem | Hostinger (PHP 8.x) |
| Banco de dados | Supabase (PostgreSQL) |
| E-mail transacional | Resend.com (SMTP via API) |
| WhatsApp | Z-API (instância própria) |
| Admin | SPA HTML/JS puro (sem framework) |
| Landing pages | HTML/CSS/JS puro |

---

## Arquivos Principais

```
/
├── index.html              # LP principal (tráfego pago / orgânico)
├── ig.html                 # LP específica para Instagram
├── track.php               # Endpoint público de rastreamento de eventos de funil
├── descadastro.php         # Página de descadastro de e-mail (link nos rodapés)
├── email_trigger.php       # Enfileira sequência ao novo lead orgânico
├── email_worker.php        # Worker cron: processa email_queue + whatsapp_queue
├── import_leads.php        # Importação de CSV com sequência configurável
├── cron.php                # Auto-enrola leads importados (cron-job.org, horário)
├── wpp_receive.php         # Webhook Z-API: processa respostas dos leads
├── wpp_status.php          # Webhook Z-API: callback de status de entrega (RECEIVED/READ)
├── painel.php              # Painel PHP de manutenção de filas (fallback)
├── _env.php                # Variáveis de ambiente (não versionado)
│
└── admin/
    ├── index.html          # SPA Admin (toda a interface)
    └── admin_proxy.php     # Proxy PHP autenticado (todas as escritas no Supabase)
```

---

## Banco de Dados (Supabase)

### Tabelas principais

#### `leads`
| Coluna | Tipo | Descrição |
|--------|------|-----------|
| `id` | uuid PK | |
| `name`, `email`, `phone` | text | |
| `sabotador` | text | Perfil: A/B/C/D ou nome |
| `funnel_stage` | text | novo / engajado / interessado / quente / convertido / frio |
| `sequence_queued_at` | timestamptz | Quando a sequência foi enfileirada (null = ainda não) |
| `sequence_paused` | boolean | Pausa temporária da automação |
| `email_optout` | boolean | Clicou no link de descadastro |
| `email_blocked` | boolean | Bloqueio manual pelo admin |
| `wpp_optout` | boolean | Respondeu palavra de opt-out no WPP |
| `optin_wpp`, `optin_email` | boolean | Consentimento formal |
| `automation_enrolled` | boolean | Lead importado já enrolado pelo cron |
| `faixa_etaria`, `faixa_renda` | text | Dados demográficos |
| `cidade`, `estado` | text | |
| `source`, `source_campaign` | text | Origem do lead |
| `notes` | text | Anotações do admin |
| `created_at` | timestamptz | |

#### `email_queue`
| Coluna | Tipo | Descrição |
|--------|------|-----------|
| `id` | uuid PK | |
| `lead_id` | uuid FK → leads | |
| `template_slug` | text | Slug do template de e-mail |
| `to_email`, `to_name` | text | |
| `extra_vars` | jsonb | Variáveis extras para substituição |
| `scheduled_at` | timestamptz | Quando enviar |
| `status` | text | pending → processing → sent / failed / cancelled |
| `attempts` | int | Tentativas (máx 3) |
| `sent_at` | timestamptz | |
| `error_msg` | text | Mensagem de erro |

#### `whatsapp_queue`
| Coluna | Tipo | Descrição |
|--------|------|-----------|
| `id` | uuid PK | |
| `lead_id` | uuid FK → leads | |
| `to_phone`, `to_name` | text | |
| `message` | text | Corpo da mensagem |
| `scheduled_at` | timestamptz | |
| `status` | text | pending → processing → sent / failed / cancelled |
| `attempts` | int | |
| `delivery_status` | text | sent → received → read (callback Z-API) |
| `zapi_message_id` | text | |
| `sent_at`, `delivered_at`, `read_at` | timestamptz | |
| `error_msg` | text | |

#### `email_templates`
Slugs dos templates HTML de e-mail. Variáveis: `{{nome}}`, `{{link_descadastro}}`, `{{link_wpp}}`, `{{link_site}}`, `{{emoji}}`.

#### `wpp_templates`
Templates de mensagem WhatsApp com variáveis.

#### `sequences`
Sequências reutilizáveis (itens com `type`, `template_slug`, `delay_hours`, `fixed_date`). Usadas no painel de importação.

#### `site_config`
Configurações da landing page: textos, URLs de vídeo, datas, pixel IDs, etc.

#### `testimonials`, `depoimentos_prints`
Depoimentos em vídeo e capturas de tela exibidos na LP.

#### `page_events`
Eventos de funil gravados pelas landing pages via `track.php`.
| Coluna | Tipo | Descrição |
|--------|------|-----------|
| `id` | uuid PK | |
| `session_id` | text | ID único de sessão (gerado no browser, persiste em sessionStorage) |
| `event` | text | page_view / quiz_opened / quiz_q1-q4 / quiz_completed / form_opened / form_submitted / vip_click |
| `page` | text | index ou ig |
| `step` | int | Número da pergunta (1–4) para eventos quiz_qN |
| `sabotador` | text | Perfil A/B/C/D (preenchido em quiz_completed e form_submitted) |
| `source` | text | utm_source do visitante |
| `source_campaign` | text | utm_campaign |
| `referrer` | text | document.referrer (máx 200 chars) |
| `city` | text | Cidade do visitante (geolocalização por IP, só em page_view) |
| `region` | text | Estado/região (geolocalização por IP) |
| `country` | text | País (geolocalização por IP) |
| `created_at` | timestamptz | |

**RLS:** INSERT permitido para anon (landing pages gravam sem auth); SELECT restrito ao service_role (proxy admin).

---

## Fluxo de um Lead Orgânico

```
Visitante acessa ig.html ou index.html
  ↓  track('page_view') gravado em page_events
ig.html  → Preenche formulário VIP na Hero (nome/email/phone)
           → track('form_opened') ao focar o campo Nome
           → track('form_submitted') + track('vip_click') ao salvar com sucesso
           → POST email_trigger.php → 7 e-mails + 13 WPPs enfileirados
           → sequence_queued_at = now
  OU
ig.html  → Clica botão "DESCOBRIR MEU PERFIL" → track('quiz_opened')
           → Faz quiz → track('quiz_q1..q4') por pergunta
           → track('quiz_completed', {sabotador}) ao terminar
           → Preenche formulário resultado → track('form_submitted')

index.html → Clica botão quiz → track('form_opened') ao abrir lead-modal
           → Preenche lead-modal → openQuizModal → track('quiz_opened')
           → Faz quiz (4 perguntas) → track('quiz_q1..q4')
           → Resultado → track('quiz_completed', {sabotador})
           → Preenche formulário resultado → track('form_submitted')
           → POST email_trigger.php → sequência enfileirada
  ↓
email_worker.php (cron a cada 10 min)
  → Lê email_queue WHERE status=pending AND scheduled_at<=now AND attempts<3
  → Verifica email_blocked / email_optout → pula se true
  → Envia via Resend.com → status=sent
  → Lê whatsapp_queue WHERE status=pending AND scheduled_at<=now
  → Verifica wpp_optout → cancela se true
  → Envia via Z-API → status=sent
```

---

## Descadastro / Opt-out

### E-mail (automático)
Todo e-mail contém `{{link_descadastro}}` no rodapé:
```
https://www.oficialemagreser.com/descadastro.php?email=xxx@yyy.com
```
→ Seta `email_optout=true` + cancela `email_queue` pending do lead
→ Exibe página de confirmação

### WhatsApp (automático via wpp_receive.php)
Webhook Z-API detecta as seguintes palavras/frases (case-insensitive, uppercase):
```
NÃO, NAO, N, PARAR, PARA, STOP, CANCELAR, CANCELA, CANCEL,
SAIR, SAIO, REMOVER, REMOVE, DESCADASTRAR, DESCADASTRE, DESCADASTRO,
CHEGA, NAO QUERO, NÃO QUERO, NAO QUERO MAIS, NÃO QUERO MAIS,
PODE PARAR, PODE CANCELAR, ME REMOVE, ME REMOVA,
SAIR DA LISTA, REMOVER DA LISTA, EXCLUIR, EXCLUI,
DESINSCREVER, DESINSCRITO, UNSUBSCRIBE
```
→ Seta `wpp_optout=true` + cancela `whatsapp_queue` pending + envia confirmação

### Manual (admin)
No perfil de cada lead → card "Jornada na automação":
- **🚫 Cancelar fila e-mail** — `email_optout=true` + muda pending → cancelled
- **🚫 Cancelar fila WPP** — `wpp_optout=true` + muda pending → cancelled
- **🗑 Excluir histórico e-mail** — DELETE todas as linhas de `email_queue`
- **🗑 Excluir histórico WPP** — DELETE todas as linhas de `whatsapp_queue`
- **🗑 Excluir tudo das filas** — DELETE em ambas

---

## Painel Admin (`admin/index.html`)

SPA single-page autenticada via sessão PHP. Navegação por grupos:

### Grupo: Visão Geral
| Página | Função | Descrição |
|--------|--------|-----------|
| Dashboard | `dashboard()` | Stats de filas + últimos envios + leads recentes |
| Leads | `leadsPage()` | Lista/busca/filtro de leads com exportação CSV |
| Funil de Vendas | `funilPage()` | Cards por estágio + accordion com leads + mover estágio |
| Funil de Conversão | `funilConversaoPage()` | Rastreamento de visitantes: onde desistem no quiz/formulário |

### Grupo: Gestão
| Página | Função | Descrição |
|--------|--------|-----------|
| Perguntas Quiz | `quizPage()` | CRUD das perguntas e opções do quiz |
| Resultados/Perfis | `resultadosPage()` | Textos dos perfis sabotadores |
| Config Geral | `configPage()` | Editor de site_config (textos, vídeos, datas) |

### Grupo: Configuração
| Página | Função | Descrição |
|--------|--------|-----------|
| Templates E-mail | `emailTemplatesPage()` | CRUD de templates HTML |
| Templates WPP | `wppTemplatesPage()` | CRUD de templates de mensagem |
| Sequências | `sequenciasPage()` | CRUD de sequências de envio |

### Grupo: Importação
| Página | Função | Descrição |
|--------|--------|-----------|
| Funções Importação | `importFuncoesPage()` | Upload CSV + seleção de sequência + canal |
| Lista Importados | `leadsImportadosPage()` | Leads importados com filtros |
| Dashboard | `importDashboardPage()` | Stats de importações |
| Gestão | `importGestaoPage()` | Gerenciar lotes de importação |

### Grupo: Comunicação
| Página | Função | Descrição |
|--------|--------|-----------|
| Fila E-mail | `painelFilaEmailPage()` | Aguardando / Enviados por lead (accordion) |
| Fila WPP | `painelFilaWppPage()` | Aguardando / Enviados por lead (accordion) |

### Grupo: Configurações (Admin)
| Página | Função | Descrição |
|--------|--------|-----------|
| Depoimentos | `depoimentosPage()` | CRUD vídeos de depoimento |
| Depoimentos Prints | `depoimentosPrintsPage()` | CRUD capturas de tela |
| Usuários | `usersPage()` | CRUD usuários do admin |

---

## Perfil do Lead (leadDetailPage)

Acessível clicando em qualquer lead. Exibe:
- **Dados pessoais**: sabotador, faixa etária, renda, cidade, origem, data
- **Jornada na automação**: contagem enviados/pendentes, próximos agendamentos, botões de ação
- **Timeline unificada**: todos os e-mails e WPPs em ordem cronológica

**Ações disponíveis:**
- Mover estágio do funil (select inline)
- ⏸ Pausar / ▶ Retomar automação
- 📧 Enviar e-mail agora (select template)
- 💬 Enviar WPP agora (select template)
- 🚫 Cancelar fila e-mail / WPP (opt-out + cancelar pending)
- 🗑 Excluir histórico e-mail / WPP / ambos (DELETE físico)

---

## Rastreamento de Funil de Conversão

### Como funciona

Cada landing page (`index.html`, `ig.html`) gera um `session_id` único por aba/sessão (salvo em `sessionStorage`) e dispara eventos via `fetch('track.php', ...)` de forma assíncrona (fire-and-forget, sem impacto na UX).

### Eventos rastreados

| Evento | Quando dispara |
|--------|---------------|
| `page_view` | Ao carregar a página (fim de `init()`) |
| `quiz_opened` | Ao abrir o modal do quiz (`openQuizModal()`) |
| `quiz_q1` | Ao exibir a pergunta 1 pela primeira vez (`renderQ(0)`) |
| `quiz_q2` | Ao exibir a pergunta 2 pela primeira vez (`renderQ(1)`) |
| `quiz_q3` | Ao exibir a pergunta 3 pela primeira vez (`renderQ(2)`) |
| `quiz_q4` | Ao exibir a pergunta 4 pela primeira vez (`renderQ(3)`) |
| `quiz_completed` | Ao finalizar o quiz (`finishQuiz()`), inclui `sabotador` |
| `form_opened` | Ao abrir o formulário de captura de lead |
| `form_submitted` | Ao salvar o lead com sucesso (`saveLead()`), inclui `sabotador` |
| `vip_click` | Ao clicar em botão WPP/VIP (delegado via `.btn-wpp`) |

> **ig.html específico:** `form_opened` dispara no primeiro foco dos campos do hero form; `form_submitted` + `vip_click` disparam juntos ao salvar via hero form com sucesso.

### Arquivo `track.php`

- Endpoint público (sem autenticação)
- Rate limit: 120 req/min por IP via APCu (skip silencioso sem APCu)
- Valida `event` contra allowlist de 10 eventos
- Sanitiza todos os campos antes de gravar
- Grava via Supabase REST API com `SUPABASE_SERVICE_KEY`

### Página "Funil de Conversão" no admin

Filtros: período (7 / 14 / 30 / 90 dias / tudo) e página (todas / index / ig).

Exibe:
- **5 cards de resumo**: visitantes únicos, quiz abertos, quiz concluídos, leads salvos, taxa de conversão
- **Barras horizontais por etapa**: sessões únicas por evento, % do anterior em verde (≥80%) / amarelo (≥50%) / vermelho (<50%)
- **Tabela de origens** (utm_source) com contagem e % do total de eventos
- **Tabela de perfis sabotadores** concluídos com distribuição %

A agregação é feita em PHP (`funnel_stats()` em `admin_proxy.php`) — busca até 200k linhas de `page_events` e conta sessões únicas por evento com `array_fill_keys` + sets de session_id.

---

## Funil de Vendas

Estágios (em ordem):
1. **novo** — lead recém cadastrado
2. **engajado** — abriu e-mails / interagiu
3. **interessado** — demonstrou interesse
4. **quente** — pronto para compra
5. **convertido** — comprou
6. **frio** — sem engajamento

---

## Variáveis de Ambiente (`_env.php`)

```php
putenv('SUPABASE_SERVICE_KEY=...');   // service_role key
putenv('ADMIN_PASSWORD=...');         // senha do admin
putenv('RESEND_API_KEY=...');         // chave Resend.com
putenv('ZAPI_INSTANCE=...');          // ID da instância Z-API
putenv('ZAPI_TOKEN=...');             // token Z-API
putenv('ZAPI_CLIENT_TOKEN=...');      // client-token Z-API (validação webhook)
putenv('WORKER_SECRET=...');          // secret para chamar email_worker.php
putenv('CRON_SECRET=...');            // secret para chamar cron.php
```

---

## Configurações de Cron (cron-job.org)

| URL | Frequência | Função |
|-----|-----------|--------|
| `/email_worker.php?secret=XXX` | A cada 10 min | Processa filas de e-mail e WPP |
| `/cron.php?token=XXX` | A cada 1 hora | Auto-enrola leads importados |

---

## Admin — Responsividade Mobile

O painel admin é responsivo com sidebar em modo drawer para telas ≤768px:
- **Topbar mobile** (`#mobile-topbar`): logo + botão hamburguer (☰) e botão fechar (✕)
- **Sidebar overlay** (`#sidebar-overlay`): fundo escuro semitransparente — clique fecha o drawer
- Funções: `toggleSidebar()` e `closeSidebar()`
- `goPage()` chama `closeSidebar()` automaticamente ao navegar
- CSS: `@media(max-width:768px)` transforma a sidebar em drawer com `transform: translateX(-100%)`

---

## Janela de Envio (v25)

`email_worker.php` só envia mensagens entre **08h–21h BRT** (`America/Sao_Paulo`). Regra:
- A primeira mensagem de cada lead (sem histórico `sent`) é sempre enviada imediatamente, em qualquer horário.
- As mensagens subsequentes são puladas fora da janela e reprocessadas na próxima execução do cron dentro do horário.
- Constantes: `TZ_BRT='America/Sao_Paulo'`, `SEND_HOUR_START=8`, `SEND_HOUR_END=21`.

---

## Status de Entrega WPP (v25)

### wpp_status.php (endpoint dedicado)
Configure no painel Z-API: **Webhooks → "Na entrega"** → `https://www.oficialemagreser.com/wpp_status.php`

Valida `ZAPI_CLIENT_TOKEN` via header `Client-Token`. Mapeamento:
- `RECEIVED` / `DELIVERED` / `RECEIVEDCALLBACK` / `DELIVERYCALLBACK` → `received` + grava `delivered_at`
- `READ` / `PLAYED` / `READCALLBACK` → `read` + grava `read_at`
- Status nunca regride (ordem: `sent=0 < received=1 < read=2`)

### wpp_receive.php (inline, fallback)
Se a Z-API enviar callbacks de status pelo mesmo webhook de recebimento, `wpp_receive.php` os detecta primeiro (antes de processar respostas de texto) e atualiza `delivery_status` da mesma forma.

---

## Geolocalização de Visitantes (v25)

`track.php` consulta `http://ip-api.com/json/{ip}` **somente em eventos `page_view`**:
- IPs privados (127.x, 192.168.x, 10.x) não são consultados.
- Resultado cacheado por 24h via APCu (`geo_{md5(ip)}`). Sem APCu: consulta a cada page_view.
- Timeout de 2s; falhas silenciosas (grava `null` nos campos).
- Campos gravados em `page_events`: `city`, `region` (estado), `country`.

O admin exibe **Top 15 Cidades** e **Top 10 Estados** no Funil de Conversão, agregados a partir de eventos `page_view`.

> **Migração Supabase necessária:** `ALTER TABLE page_events ADD COLUMN city TEXT, ADD COLUMN region TEXT, ADD COLUMN country TEXT;`

---

## Histórico de Versões

### v25 — Janela de envio, status de entrega WPP e geolocalização
- **`email_worker.php`**: janela de envio 08h–21h BRT — primeiro envio por lead sempre imediato; subsequentes adiados fora da janela
- **`wpp_status.php`** (novo): endpoint dedicado para callback de status Z-API (RECEIVED/READ); valida Client-Token; nunca regride status; loga em `wpp_status.log`
- **`wpp_receive.php`**: detecção inline de callbacks de status antes de processar respostas de texto
- **`track.php`**: geolocalização por IP via ip-api.com com cache APCu 24h; somente em `page_view`; grava `city`/`region`/`country` em `page_events`
- **`admin/admin_proxy.php`**: `funnel_stats()` agora seleciona e agrega `city`/`region`/`country`; retorna top 15 cidades e top 10 estados
- **`admin/index.html`**: tabelas "Top Cidades" e "Top Estados" na página Funil de Conversão
- **Supabase**: migração necessária para adicionar colunas `city`, `region`, `country` em `page_events`

### v24 — Funil de Conversão Nativo (rastreamento de eventos)
- **`track.php`** (novo): endpoint público com rate limit por IP, valida 10 eventos e grava em `page_events`
- **`index.html`** + **`ig.html`**: helper `track()` com `session_id` único por sessão; eventos `page_view`, `quiz_opened`, `quiz_q1–q4`, `quiz_completed`, `form_opened`, `form_submitted`, `vip_click` wired nos pontos corretos
- **`admin_proxy.php`**: ação `funnel_stats` — agrega `page_events` por período/página, retorna sessões únicas por etapa, origens e perfis sabotadores
- **`admin/index.html`**: página "📉 Funil de Conversão" no sidebar com cards de resumo, barras de funil com drop-off colorido e tabelas de origens/perfis
- **Supabase**: tabela `page_events` com RLS — INSERT anon (landing pages) + SELECT service_role (admin)

### v23 — Admin responsivo mobile
- Sidebar em drawer com overlay para telas ≤768px
- Topbar mobile com hamburguer e botão fechar
- `toggleSidebar()` e `closeSidebar()` — drawer fecha ao navegar
- Grid `.g2` colapsa de 2 para 1 coluna em mobile

### v22 — Dashboard redesenhado + CLAUDE.md
- Dashboard com 8 stats de filas, accordions de itens falhos, tabelas de últimos envios e leads recentes
- `dashQueueAction(op)`: manutenção de filas (reset stuck, retry failed, cancel pending) por canal
- `CLAUDE.md`: documentação completa do projeto (criado do zero)

### v20 — Descadastro automático e manual
- **`descadastro.php`** (novo): landing page para o link de descadastro dos e-mails
- **`wpp_receive.php`**: opt-out expandido de 3 para +20 palavras-chave
- **`admin_proxy.php`**: ações `lead_unsubscribe_email` e `lead_unsubscribe_wpp`
- **`admin/index.html`**: botões 🚫 Cancelar fila no perfil do lead

### v21 — Excluir filas completas por lead
- **`admin_proxy.php`**: ação `lead_purge_queues` (DELETE físico, canal email/wpp/both)
- **`admin/index.html`**: botões 🗑 Excluir histórico (e-mail / WPP / tudo)

### v19 — Correção botões ig.html (type=button)
- Todos os 7 botões de quiz abaixo da Hero com `type="button"` explícito
- Evita comportamento de submit padrão do browser

### v18 — Fluxo ig.html: botões abaixo da Hero → Quiz
- Topbar "ENTRAR NO GRUPO VIP" mantida → `scrollToIgForm()`
- 7 botões abaixo da Hero alterados: `scrollToIgForm()` → `goToQuiz()`
- Textos dos botões atualizados para refletir o quiz

### v17 — Funil de Vendas (admin)
- Nova página "Funil de Vendas" no sidebar (grupo Visão Geral)
- 6 cards por estágio com contagem, barra de progresso e % do total
- Accordion por estágio com tabela de leads
- Botões de mover estágio (avançar/recuar) com atualização instantânea

### v16 e anteriores
- Setup inicial das landing pages (index.html, ig.html)
- Sistema de quiz sabotadores com 4 perfis (A/B/C/D)
- Painel admin completo (leads, templates, sequências, importação)
- Integração Supabase + Resend.com + Z-API
- Filas de e-mail e WhatsApp com worker cron
- Webhook wpp_receive.php para respostas interativas
