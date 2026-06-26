# EmagreSer — Documentação do Projeto

## Visão Geral

Plataforma de marketing e automação de comunicação para o **Programa EmagreSer** (Psicóloga Daniely + Nutricionista Ira). O sistema capta leads via landing pages, identifica o perfil sabotador do lead via quiz, e executa sequências automáticas de e-mail e WhatsApp. Possui também um fluxo completo de importação de leads externos com opt-in ativo via WhatsApp.

---

## Stack Tecnológica

| Camada | Tecnologia |
|--------|-----------|
| Hospedagem | Hostinger (PHP 8.x) |
| Banco de dados | Supabase (PostgreSQL) |
| E-mail transacional | Resend.com (API REST) |
| WhatsApp | Z-API (instância própria) |
| Admin | SPA HTML/JS puro (sem framework) |
| Landing pages | HTML/CSS/JS puro |

---

## Arquivos Principais

```
/
├── index.html              # LP principal (tráfego pago / orgânico)
├── ig.html                 # LP específica para Instagram
├── programa.html           # Página de vendas do Programa (funil — noindex)
├── track.php               # Endpoint público de rastreamento de eventos de funil
├── descadastro.php         # Página de descadastro de e-mail (link nos rodapés)
├── email_trigger.php       # Enfileira sequência ao novo lead orgânico
├── email_worker.php        # Worker cron: processa email_queue + whatsapp_queue
├── import_leads.php        # Importação de CSV com sequência configurável (legacy)
├── cron.php                # Auto-enrola leads importados (cron-job.org, horário)
├── wpp_receive.php         # Webhook Z-API: processa respostas + opt-in SIM/NÃO
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
| `sabotador` | text | Perfil: A/B/C/D |
| `sabotador_score` | jsonb | Pontuação por perfil |
| `funnel_stage` | text | novo / engajado / interessado / quente / convertido / frio |
| `sequence_queued_at` | timestamptz | Quando a sequência foi enfileirada (null = ainda não) |
| `sequence_paused` | boolean | Pausa temporária da automação |
| `automation_enrolled` | boolean | Lead importado já enrolado pelo cron |
| `email_optout` | boolean | Clicou no link de descadastro |
| `email_blocked` | boolean | Bloqueio manual pelo admin |
| `wpp_optout` | boolean | Respondeu palavra de opt-out no WPP |
| `optin_status` | text | Status do opt-in: pending / confirmed / declined |
| `faixa_etaria`, `faixa_renda` | text | Dados demográficos |
| `cidade`, `estado` | text | Localização |
| `source` | text | Origem: organic / import / etc |
| `source_campaign` | text | Campanha de origem / batch de importação |
| `utm_medium` | text | Tipo de canal (preenchido na importação) |
| `created_at` | timestamptz | |

> **Atenção:** As colunas `optin_wpp`, `optin_email` e `notes` **não existem** na tabela. Use `optin_status`, `wpp_optout` e `email_optout` respectivamente.

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
Templates HTML de e-mail. Variáveis disponíveis:
`{{nome}}`, `{{nome_lead}}`, `{{nome_perfil}}`, `{{link_descadastro}}`, `{{link_wpp}}`, `{{link_vip}}`, `{{link_site}}`, `{{link_hotmart}}`, `{{link_video_ira}}`, `{{emoji}}`, `{{titulo}}`, `{{descricao}}`

Templates ativos relevantes:
| Slug | Descrição |
|------|-----------|
| `ira_optin_importados` | E-mail HTML de opt-in para leads importados (vídeo Ira + botão VIP + instrução SIM/NÃO) |
| `mc_boas_vindas` | Boas-vindas Masterclass 2026 |
| `mc_conteudo_perfil` | Conteúdo sobre o perfil sabotador |
| `mc_prova_social` | Depoimentos e prova social |
| `mc_vespera` | Véspera da Masterclass |
| `mc_objecao_tempo` | Quebra de objeção pós-Masterclass |
| `mc_carta_daniely` | Carta pessoal da Daniely |
| `mc_ultimo_dia` | Último dia de vendas |

#### `wpp_templates`
Templates de mensagem WhatsApp com variáveis inline. Mesmo set de variáveis dos e-mails.

Templates ativos relevantes:
| Slug | Descrição |
|------|-----------|
| `ira_optin_importados` | WPP de opt-in (referência — conteúdo inline na sequência) |

#### `sequences`
Sequências reutilizáveis. Cada item tem: `type` (wpp/email), `template_slug` (email), `message` (wpp inline), `delay_hours` ou `fixed_date`/`fixed_time`.

Sequências ativas:
| ID | Nome | Itens | Uso |
|----|------|-------|-----|
| `2aecfbb8-ab1a-49e0-ac87-ff0696662d5b` | Aquecimento Importados | 3 | Primeira abordagem de leads importados |
| `6733dd19-9090-4cec-ad72-bfc4fd1ec30e` | Masterclass 2026 — Sequência Completa | 20 | Após SIM no opt-in |

#### `site_config`
Configurações da landing page e do sistema.

Chaves relevantes:
| Chave | Valor atual | Descrição |
|-------|-------------|-----------|
| `link_video_ira` | `https://youtube.com/shorts/sRicNLmjFGI?feature=share` | Vídeo da Ira para opt-in importados |
| `optin_followup_sequence_id` | `6733dd19-9090-4cec-ad72-bfc4fd1ec30e` | ID da sequência enrolada após SIM |
| `prog_ann_text` | — | Texto da barra de anúncio (HTML) |
| `prog_turma_data` | — | Data da turma (suporta `\|` para split de linha) |
| `prog_hero_titulo` | — | Título H1 da hero (HTML) |
| `prog_hero_sub` | — | Subtítulo da hero |
| `prog_mentor_video` | — | URL YouTube do vídeo das mentoras (seção Depoimentos) |
| `prog_preco` | — | Preço exibido no price card (ex: "R$ 997") |
| `prog_preco_parcelado` | — | Texto de parcelamento |
| `prog_cta_aberto` | `1` | `1` = inscrições abertas · `0` = CTA "Avise-me" |
| `prog_bonus1_titulo` | — | Título do Bônus 1 |
| `prog_bonus1_desc` | — | Descrição do Bônus 1 |
| `prog_bonus2_titulo` | — | Título do Bônus 2 |
| `prog_bonus2_desc` | — | Descrição do Bônus 2 |
| `prog_faq` | — | FAQ como JSON array `[{"q":"...","a":"..."}]` |
| `print_depo_1`…`print_depo_6` | — | URLs das imagens de prints de depoimentos |

#### `page_events`
Eventos de funil gravados pelas landing pages via `track.php`.
| Coluna | Tipo | Descrição |
|--------|------|-----------|
| `id` | uuid PK | |
| `session_id` | text | ID único de sessão (gerado no browser, persiste em sessionStorage) |
| `event` | text | page_view / quiz_opened / quiz_q1-q4 / quiz_completed / form_opened / form_submitted / vip_click |
| `page` | text | index ou ig |
| `step` | int | Número da pergunta (1–4) |
| `sabotador` | text | Perfil A/B/C/D |
| `source` | text | utm_source |
| `source_campaign` | text | utm_campaign |
| `referrer` | text | document.referrer (máx 200 chars) |
| `city` | text | Cidade (geolocalização por IP, só em page_view) |
| `region` | text | Estado/região |
| `country` | text | País |
| `created_at` | timestamptz | |

**RLS:** INSERT permitido para anon; SELECT restrito ao service_role.

#### `testimonials`, `depoimentos_prints`
Depoimentos em vídeo e capturas de tela exibidos na LP.

---

## Fluxo de um Lead Orgânico

```
Visitante acessa ig.html ou index.html
  ↓  track('page_view') gravado em page_events

ig.html  → Preenche formulário VIP na Hero (nome/email/phone)
           → track('form_opened') ao focar o campo Nome
           → track('form_submitted') + track('vip_click') ao salvar
           → POST email_trigger.php → sequência enfileirada
           → sequence_queued_at = now
  OU
ig.html  → Clica "DESCOBRIR MEU PERFIL" → track('quiz_opened')
           → Faz quiz → track('quiz_q1..q4')
           → track('quiz_completed', {sabotador})
           → Preenche formulário resultado → track('form_submitted')

index.html → Clica botão quiz → track('form_opened') ao abrir lead-modal
           → openQuizModal → track('quiz_opened')
           → Faz quiz (4 perguntas) → track('quiz_q1..q4')
           → track('quiz_completed', {sabotador})
           → Preenche formulário resultado → track('form_submitted')
           → POST email_trigger.php → sequência enfileirada
  ↓
email_worker.php (cron a cada 10 min)
  → email_queue: verifica email_blocked/email_optout → envia via Resend.com
  → whatsapp_queue: verifica wpp_optout → envia via Z-API
  → Janela de envio: 08h–21h BRT (primeiro envio de cada lead sempre imediato)
```

---

## Fluxo de Lead Importado (v26)

```
Admin → Funções Importação → Upload CSV → Seleciona "Aquecimento Importados" → Importar
  ↓ (importação em lote — admin_proxy.php / import_with_sequence)
  → Cria/resolve leads em lote (batch de 100)
  → Enfileira 3 itens por lead:

  [Agora]     📱 WPP 1 — Ira se apresenta + vídeo 1 ({{link_video_ira}})
  [Agora]     📧 Email — HTML ira_optin_importados (vídeo + botão VIP + instrução SIM/NÃO)
  [+30 min]   📱 WPP 2 — Daniely se apresenta + vídeo 2 + pergunta SIM/NÃO

  ↓ Lead responde SIM no WhatsApp
  → wpp_receive.php detecta "SIM"
  → Marca optin_status = 'confirmed', wpp_optout = false
  → Verifica: source='import' E sequence_queued_at IS NULL
  → Busca optin_followup_sequence_id em site_config
  → Enfileira "Masterclass 2026 — Sequência Completa" para o lead
  → Atualiza sequence_queued_at = now, automation_enrolled = true

  ↓ Lead responde NÃO (qualquer palavra de opt-out)
  → wpp_receive.php detecta opt-out
  → wpp_optout = true + cancela whatsapp_queue pending
  → Envia confirmação de remoção

  ↓ Masterclass 2026 (20 itens, dias 0–11/06)
  → WPP boas-vindas + vídeos Ira e Daniely + link VIP
  → Emails de conteúdo, prova social, urgência
  → WPPs de engajamento e fechamento de vendas
```

### Vídeos configurados (Campanha Importados 2026)
| Vídeo | Mentora | Link | Uso |
|-------|---------|------|-----|
| Vídeo 1 | Ira | `https://youtube.com/shorts/sRicNLmjFGI?feature=share` | WPP 1 do opt-in (via `{{link_video_ira}}`) |
| Vídeo 2 | Ira | `https://youtube.com/shorts/X0E-F269QDQ?feature=share` | WPP 1 da Masterclass (hardcoded) |
| Vídeo 3 | Daniely | `https://youtube.com/shorts/82Y1WY8okKc?feature=share` | WPP 2 do opt-in + WPP 1 da Masterclass (hardcoded) |

---

## Sequência "Aquecimento Importados" (ID: `2aecfbb8`)

| # | Tipo | Delay | Conteúdo |
|---|------|-------|----------|
| 1 | WPP | 0h | Ira se apresenta + `{{link_video_ira}}` (sem pergunta SIM/NÃO) |
| 2 | Email | 0h | Template `ira_optin_importados` (HTML completo) |
| 3 | WPP | 0.5h | Daniely se apresenta + vídeo dela + pergunta SIM/NÃO |

---

## Sequência "Masterclass 2026 — Sequência Completa" (ID: `6733dd19`)

| # | Tipo | Timing | Conteúdo |
|---|------|--------|----------|
| 1 | WPP | 0h | Boas-vindas + vídeo Ira + vídeo Daniely + link VIP |
| 2 | Email | 0h | `mc_boas_vindas` |
| 3 | Email | 24h | `mc_conteudo_perfil` |
| 4 | WPP | 32h | Pergunta de engajamento emocional |
| 5 | Email | 72h | `mc_prova_social` |
| 6 | WPP | 80h | Quebra de objeções (Daniely e Ira) |
| 7 | WPP | 143h | Amanhã é o dia (véspera) |
| 8 | Email | 143h | `mc_vespera` |
| 9 | WPP | 154h | Noite antes — assinatura Daniely e Ira |
| 10 | WPP | 08/06 09h | Pós-Masterclass — vagas abertas |
| 11 | Email | 08/06 09h | `mc_objecao_tempo` |
| 12 | WPP | 08/06 17h | O que aconteceu ontem na Masterclass |
| 13 | WPP | 09/06 09h | Vagas acabando |
| 14 | WPP | 09/06 19h | Última coisa — reflexão 12 semanas |
| 15 | Email | 10/06 09h | `mc_carta_daniely` |
| 16 | WPP | 10/06 17h | Amanhã as vagas fecham — Daniely e Ira |
| 17 | WPP | 11/06 08h | Último dia |
| 18 | Email | 11/06 08h | `mc_ultimo_dia` |
| 19 | WPP | 11/06 14h | Últimas horas |
| 20 | WPP | 11/06 19h | 5 horas — Daniely e Ira |

---

## Substituição de Variáveis

### Variáveis em templates de e-mail (`email_worker.php`)
| Variável | Fonte |
|----------|-------|
| `{{nome}}` / `{{nome_lead}}` | `to_name` na fila |
| `{{nome_perfil}}` | `extra_vars` |
| `{{link_descadastro}}` | Gerado com e-mail do lead |
| `{{link_vip}}` / `{{link_wpp}}` | Hardcoded (grupo VIP) |
| `{{link_hotmart}}` | `HOTMART_LINK` env ou fallback |
| `{{link_site}}` | Hardcoded |
| `{{link_video_ira}}` | `site_config.link_video_ira` (pré-carregado uma vez) |
| `{{emoji}}`, `{{titulo}}`, `{{descricao}}` | `extra_vars` |

### Variáveis em mensagens WPP (inline nas sequências)
Função `seq_sub_vars_proxy()` em `admin_proxy.php` e `wpp_sub_vars()` em `wpp_receive.php`:
`{{nome_lead}}`, `{{nome}}`, `{{nome_perfil}}`, `{{link_vip}}`, `{{link_hotmart}}`, `{{link_site}}`, `{{link_video_ira}}`

---

## Descadastro / Opt-out

### E-mail (automático)
Todo e-mail contém `{{link_descadastro}}` no rodapé:
```
https://www.oficialemagreser.com/descadastro.php?email=xxx@yyy.com
```
→ Seta `email_optout=true` + cancela `email_queue` pending do lead

### WhatsApp (automático via wpp_receive.php)
Detecta palavras/frases de opt-out (case-insensitive):
```
NÃO, NAO, N, PARAR, PARA, STOP, CANCELAR, CANCELA, CANCEL,
SAIR, SAIO, REMOVER, REMOVE, DESCADASTRAR, DESCADASTRE, DESCADASTRO,
CHEGA, NAO QUERO, NÃO QUERO, NAO QUERO MAIS, NÃO QUERO MAIS,
PODE PARAR, PODE CANCELAR, ME REMOVE, ME REMOVA,
SAIR DA LISTA, REMOVER DA LISTA, EXCLUIR, EXCLUI,
DESINSCREVER, DESINSCRITO, UNSUBSCRIBE
```
→ `wpp_optout=true` + cancela `whatsapp_queue` pending + envia confirmação

### Opt-in (SIM — leads importados)
Quando lead importado responde **SIM** no WhatsApp:
- `optin_status = 'confirmed'`, `wpp_optout = false`
- Se `source='import'` e `sequence_queued_at IS NULL`: enrola na sequência `optin_followup_sequence_id`
- Confirma com mensagem personalizada assinada por Daniely e Ira

### Manual (admin)
No perfil do lead → card "Jornada na automação":
- 🚫 Cancelar fila e-mail / WPP
- 🗑 Excluir histórico e-mail / WPP / ambos

---

## wpp_receive.php — Funções Internas

| Função | Descrição |
|--------|-----------|
| `zapi_send()` | Envia mensagem via Z-API |
| `sb_get()` | GET no Supabase REST |
| `sb_patch()` | PATCH no Supabase REST |
| `sb_post()` | POST no Supabase REST |
| `wpp_enqueue_followup_sequence()` | Enfileira sequência pós-SIM para lead importado |
| `wpp_seq_schedule()` | Calcula `scheduled_at` de cada item da sequência |
| `wpp_sub_vars()` | Substitui variáveis em mensagens WPP inline |

---

## import_with_sequence — Operações em Lote (v26)

Reescrito para suportar grandes volumes (5.000+ leads) sem timeout:

```
set_time_limit(300)

Passo 1: Normalizar e deduplicar todas as linhas do CSV em memória
Passo 2: Batch-lookup de emails existentes (150 por request)
Passo 3: Separar novos / já enrolados (skip) / existentes sem sequência
Passo 4: Batch-criar novos leads (100 por request, retorna IDs)
Passo 5: Montar todos os itens de fila em memória (email_q + wpp_q)
Passo 6: Batch-inserir email_queue (100 por request)
Passo 7: Batch-inserir whatsapp_queue (100 por request)
Passo 8: Batch-atualizar sequence_queued_at nos leads (200 por request)
```

Para 5.420 leads: de ~21.700 requests (~30 min) para ~200 requests (~20 seg).

---

## Painel Admin (`admin/index.html`)

SPA single-page autenticada via sessão PHP (4h de inatividade).

### Grupo: Visão Geral
| Página | Função | Descrição |
|--------|--------|-----------|
| Dashboard | `dashboard()` | Stats de filas + últimos envios + leads recentes |
| Leads | `leadsPage()` | Lista/busca/filtro de leads com exportação CSV |
| Funil de Vendas | `funilPage()` | Cards por estágio + accordion com leads + mover estágio |
| Funil de Conversão | `funilConversaoPage()` | Rastreamento de visitantes: onde desistem no quiz/formulário + top cidades/estados |

### Grupo: Gestão
| Página | Função | Descrição |
|--------|--------|-----------|
| Perguntas Quiz | `quizPage()` | CRUD das perguntas e opções do quiz |
| Resultados/Perfis | `resultadosPage()` | Textos dos perfis sabotadores |
| Config Geral | `configPage()` | Editor de site_config (textos, vídeos, datas, link_video_ira) |

### Grupo: Configuração
| Página | Função | Descrição |
|--------|--------|-----------|
| Templates E-mail | `emailTemplatesPage()` | CRUD de templates HTML |
| Templates WPP | `wppTemplatesPage()` | CRUD de templates de mensagem |
| Sequências | `sequenciasPage()` | CRUD de sequências de envio |

### Grupo: Importação
| Página | Função | Descrição |
|--------|--------|-----------|
| Funções Importação | `importFuncoesPage()` | Upload CSV + seleção de sequência + canal + `import_with_sequence` |
| Lista Importados | `leadsImportadosPage()` | Leads importados com filtros (usa `optin_status`, não `optin_wpp`) |
| Dashboard | `importDashboardPage()` | Stats de importações |
| Gestão | `importGestaoPage()` | Leads agrupados por batch/campanha |

### Grupo: Comunicação
| Página | Função | Descrição |
|--------|--------|-----------|
| Fila E-mail | `painelFilaEmailPage()` | Aguardando / Enviados por lead |
| Fila WPP | `painelFilaWppPage()` | Aguardando / Enviados por lead |

### Grupo: Configurações (Admin)
| Página | Função | Descrição |
|--------|--------|-----------|
| Depoimentos | `depoimentosPage()` | CRUD vídeos de depoimento |
| Depoimentos Prints | `depoimentosPrintsPage()` | CRUD capturas de tela |
| Usuários | `usersPage()` | CRUD usuários do admin |

---

## Perfil do Lead (leadDetailPage)

- **Dados pessoais**: sabotador, faixa etária, renda, cidade, origem, data
- **Jornada na automação**: contagem enviados/pendentes, próximos agendamentos
- **Timeline unificada**: todos os e-mails e WPPs em ordem cronológica

**Ações disponíveis:**
- Mover estágio do funil
- ⏸ Pausar / ▶ Retomar automação
- 📧 Enviar e-mail agora / 💬 Enviar WPP agora
- 🚫 Cancelar fila e-mail / WPP
- 🗑 Excluir histórico e-mail / WPP / ambos

---

## Rastreamento de Funil de Conversão

Cada landing page gera `session_id` único (sessionStorage) e dispara eventos via `fetch('track.php')`.

| Evento | Quando |
|--------|--------|
| `page_view` | Ao carregar a página |
| `quiz_opened` | Ao abrir o modal do quiz |
| `quiz_q1` – `quiz_q4` | Ao exibir cada pergunta |
| `quiz_completed` | Ao finalizar o quiz (inclui `sabotador`) |
| `form_opened` | Ao abrir formulário de captura |
| `form_submitted` | Ao salvar lead com sucesso |
| `vip_click` | Ao clicar botão WPP/VIP |

`track.php`: rate limit 120 req/min por IP, valida evento contra allowlist, geo por ip-api.com (cache APCu 24h, só em `page_view`).

Admin exibe: 5 cards de resumo, barras de funil com drop-off, tabela de origens (utm_source), perfis sabotadores, **Top 15 Cidades** e **Top 10 Estados**.

---

## Funil de Vendas

| Estágio | Descrição |
|---------|-----------|
| `novo` | Lead recém cadastrado |
| `engajado` | Abriu e-mails / interagiu |
| `interessado` | Demonstrou interesse |
| `quente` | Pronto para compra |
| `convertido` | Comprou |
| `frio` | Sem engajamento |

---

## Janela de Envio

`email_worker.php` só envia entre **08h–21h BRT** (`America/Sao_Paulo`):
- Primeiro envio de cada lead: **sempre imediato** (qualquer horário)
- Envios subsequentes: adiados fora da janela, reprocessados na próxima execução

Constantes: `TZ_BRT='America/Sao_Paulo'`, `SEND_HOUR_START=8`, `SEND_HOUR_END=21`

---

## Status de Entrega WPP

### wpp_status.php
Configure no Z-API: **Webhooks → "Na entrega"** → `https://www.oficialemagreser.com/wpp_status.php`

- Valida `ZAPI_CLIENT_TOKEN` via header `Client-Token`
- `RECEIVED`/`DELIVERED`/`RECEIVEDCALLBACK` → `received` + `delivered_at`
- `READ`/`PLAYED`/`READCALLBACK` → `read` + `read_at`
- Status nunca regride (`sent < received < read`)

### wpp_receive.php (fallback inline)
Detecta callbacks de status antes de processar respostas de texto.

---

## Geolocalização de Visitantes

`track.php` consulta `ip-api.com` somente em `page_view`:
- IPs privados (127.x, 192.168.x, 10.x) ignorados
- Cache APCu 24h; timeout 2s; falha silenciosa
- Grava `city`, `region`, `country` em `page_events`

---

## Variáveis de Ambiente (`_env.php`)

```php
putenv('SUPABASE_SERVICE_KEY=...');   // service_role key
putenv('ADMIN_PASSWORD=...');         // senha do admin (legacy)
putenv('RESEND_API_KEY=...');         // chave Resend.com
putenv('ZAPI_INSTANCE=...');          // ID da instância Z-API
putenv('ZAPI_TOKEN=...');             // token Z-API
putenv('ZAPI_CLIENT_TOKEN=...');      // client-token Z-API (validação webhook)
putenv('WORKER_SECRET=...');          // secret para chamar email_worker.php
putenv('CRON_SECRET=...');            // secret para chamar cron.php
putenv('HOTMART_LINK=...');           // URL de pagamento Hotmart (opcional, tem fallback)
```

---

## Configurações de Cron (cron-job.org)

| URL | Frequência | Função |
|-----|-----------|--------|
| `/email_worker.php?secret=XXX` | A cada 10 min | Processa filas de e-mail e WPP |
| `/cron.php?token=XXX` | A cada 1 hora | Auto-enrola leads importados |

---

## Admin — Responsividade Mobile

Sidebar em drawer para telas ≤768px:
- `#mobile-topbar`: logo + hamburguer (☰) + fechar (✕)
- `#sidebar-overlay`: fundo escuro — clique fecha o drawer
- `toggleSidebar()` / `closeSidebar()` — fecha ao navegar via `goPage()`

---

## Histórico de Versões

### v29 — Masterclass remarcada para 03/09/2026 + automação pausada

#### Mudança de data (11/06 → 03/09/2026 às 20h)
- **Supabase `site_config`**: `live_data = 2026-09-03T20:00:00`, `masterclass_data = "03/09/2026 às 20h"`, `prog_turma_data = "setembro | 2026"`
- **`email_templates`**: 18 templates corrigidos em massa (11/06 → 03/09, "11 de junho" → "3 de setembro")
- **`sequences.items`**: datas corrigidas nos textos dos itens
- **`ig.html`**: meta tags, barra de anúncio, passos, countdown fallback
- **`admin/admin_proxy.php`**: mensagens de opt-in e convite
- **`import_leads.php`** (legacy): textos + datas fixas remapeadas (07-11/06 → 30/08-03/09)

#### Automação PARADA (12/06/2026)
- **Filas**: 3 e-mails `pending` → `cancelled`; 9 WPPs `pending` → `skipped` (constraint não aceita cancelled) — todos referenciavam a Masterclass de 11/06
- **Sequência "Masterclass 2026"** (`6733dd19`): `is_active = false` (datas fixas de junho já passadas)
- **`optin_followup_sequence_id`**: esvaziado — `wpp_receive.php` (SIM) e `cron.php` não enrolam mais ninguém até nova sequência ser configurada
- **Ainda ativo**: `email_trigger.php` (boas-vindas para leads orgânicos novos, com data corrigida) e sequência "Aquecimento Importados"

#### Próxima fase (a construir)
- Estratégia de nutrição de conteúdo de 12/06 até ~20/08 (14 dias antes do lançamento)
- Sequência de pré-lançamento 20/08 → 03/09 + sequência de vendas pós-Masterclass
- Discussão de refatoração SaaS (multi-tenant, autenticação) — pendente de decisões

---

### v28 — index.html = página do programa + depoimentos + automação admin expandida

#### index.html agora é a página do programa
- `index.html` substituído: era LP de captação de leads (quiz), agora é a página de vendas do Programa EmagreSer (idêntica à `programa.html`)
- A antiga LP de captação (quiz + formulário) está preservada em `ig.html` e voltará como home em setembro/2026
- Botão "DESCOBRIR MEU PERFIL" na seção de perfis sabotadores aponta para `/ig.html` + rastreia `quiz_opened`

#### Seção de Depoimentos (ambas as páginas)
Adicionada em `index.html` e `programa.html` entre `#instagram` e `#cronograma`:
- **Vídeo das mentoras**: URL configurável via `site_config.prog_mentor_video` (YouTube embed)
- **Vídeos de depoimentos**: carregados de `testimonials` (active=true, `video_url` preenchido)
- **Prints de depoimentos**: carregados de `site_config.print_depo_1` a `print_depo_6`
- Seções ocultas até haver conteúdo configurado (`.hidden` → removido pelo JS)
- Helper `ytEmbed(url)` converte URLs YouTube/Shorts para embed

#### Automação Admin — novos campos em `CONFIG_KEYS_PAGES`
| Chave | Uso |
|-------|-----|
| `prog_mentor_video` | URL YouTube do vídeo das mentoras (seção depoimentos) |
| `prog_preco` | Preço da turma exibido no price card (ex: "R$ 997") |
| `prog_preco_parcelado` | Texto de parcelamento (ex: "ou 12x de R$ 97,00") |
| `prog_cta_aberto` | `1` = inscrições abertas · `0` = CTA vira "Avise-me quando abrir" |
| `prog_bonus1_titulo` | Título do Bônus 1 |
| `prog_bonus1_desc` | Descrição do Bônus 1 |
| `prog_bonus2_titulo` | Título do Bônus 2 |
| `prog_bonus2_desc` | Descrição do Bônus 2 |
| `prog_faq` | JSON de FAQ: `[{"q":"Pergunta?","a":"Resposta."}]` — substitui FAQ hardcoded |

- Bônus têm `data-prog` attributes nos elementos H3/P para substituição via JS
- `#faq-list` tem `id` nas duas páginas para substituição dinâmica pelo JSON
- Price block tem `id="prog-preco-block"` (oculto por padrão), `id="prog-preco-val"` e `id="prog-preco-inst"`
- Quando preço é configurado, o texto estático "Datas e condições especiais..." é ocultado automaticamente

#### Correções de conteúdo em programa.html
- Item "Plano alimentar" → "Estratégia alimentar adaptável ao seu perfil" (ferramentas, não cardápio genérico)
- Perfis sabotadores com blur + overlay quiz CTA (botão "DESCOBRIR MEU PERFIL →" para `/ig.html`)
- Bônus 1 label → "Primeiras inscritas" com descrição de exclusividade
- Price card bullet corrigido em ambas as páginas

#### Arquivos modificados
- **`index.html`**: depoimentos CSS/HTML/JS, applyConfig() estendido, data-prog attributes, price block, quiz button tracking
- **`programa.html`**: mesmas correções de index.html + todas as correções de conteúdo listadas
- **`admin/index.html`**: 8 novos campos em CONFIG_KEYS_PAGES com hint para FAQ JSON

---

### v27 — página programa.html + WPPs diários + correção funil de conversão

#### Novas funcionalidades

- **`programa.html`** (novo): página de vendas do Programa EmagreSer, acessível em `/programa`
  - Design idêntico ao `index.html`: fontes Playfair Display + DM Sans, paleta `--teal:#0d9488`, `--bg:#fafaf8`
  - Mesmos componentes: announcement bar com countdown para 11/06 20h, topbar sticky, footer dark navy, floating CTA mobile, `.fade-up` IntersectionObserver
  - Carrega `site_config` (hotmart_link, whatsapp_link) e mentoras do Supabase dinamicamente
  - Seções: Hero com price card → Para quem é → Como funciona → O que inclui → 4 perfis sabotadores → Mentoras → Cronograma 12 semanas → Investimento → 2 Bônus → FAQ (8 itens) → CTA final
  - `noindex` meta (página de funil — não indexada)
  - Grava `page_view` via `track.php` com `page='programa'`

- **3 novos WPPs diários em `email_trigger.php`** (sequência orgânica expandida de 13 para 16 mensagens):
  | # | Timing | Conteúdo |
  |---|--------|----------|
  | WPP 3 | D+2 +48h | Ira Soraya se apresenta + @irasorayanutri + irasorayanutri.com.br |
  | WPP 5 | D+4 +96h | Daniely Albuquerque se apresenta + @psidanielyalbuquerque + danielydealbuquerque.com.br |
  | WPP 6 | D+5 +120h | Link para programa.html com resumo do que encontrar lá |

#### Correções do Funil de Conversão

- **`track.php`**: adiciona `'programa'` na allowlist de pages aceitas (era só `index` e `ig`)
- **`admin_proxy.php` `funnel_stats()`**:
  - Quando "Todas": filtra `page IN (index, ig, programa)` — exclui tráfego de outras fontes/bots com page inválida
  - Muda `order=created_at.asc` para `order=created_at.desc` — prioriza eventos mais recentes dentro do limite de 200k rows
  - `days=0` = "Todo o período": não aplica filtro `created_at` (verdadeiro all-time)
  - Causa do paradoxo "3 dias > total": `limit=200000` + `order=ASC` retornava os 200k eventos mais antigos, excluindo tráfego recente do query longo
- **`admin/index.html`**:
  - Dropdown "Página" adiciona opção `programa.html`
  - "Todo o período" muda de `value=365` para `value=0`

#### Arquivos modificados
- **`programa.html`** (novo): 854 linhas, design alinhado com index.html
- **`email_trigger.php`**: sequência WPP orgânica expandida para 16 mensagens
- **`track.php`**: `'programa'` na allowlist de pages
- **`admin/admin_proxy.php`**: `funnel_stats()` reescrita com order DESC, days=0 all-time, filtro IN pages
- **`admin/index.html`**: opção programa no dropdown + valor 0 para all-time

---

### v26 — Fluxo opt-in importados + correção HTTP 500 + vídeos Ira/Daniely

#### Novas funcionalidades
- **Sequência "Aquecimento Importados"** (3 itens): WPP Ira (vídeo 1) → Email HTML opt-in → WPP Daniely +30min (vídeo 2 + pergunta SIM/NÃO)
- **Sequência "Masterclass 2026"** (20 itens): Completa da boas-vindas ao fechamento 11/06, com nomes Daniely + Ira em todos os WPPs, vídeos de ambas no primeiro WPP
- **Template `ira_optin_importados`**: E-mail HTML com vídeo da Ira, botão VIP e instrução para responder SIM/NÃO no WhatsApp
- **Fluxo SIM automático**: lead importado responde SIM → `wpp_receive.php` detecta → enrola automaticamente na Masterclass 2026 via `wpp_enqueue_followup_sequence()`
- **Variável `{{link_video_ira}}`**: disponível em todos os templates e sequências WPP; valor configurável em `site_config.link_video_ira`
- **site_config**: `link_video_ira` e `optin_followup_sequence_id` configurados

#### Correções críticas
- **HTTP 500 nas importações** (`import_with_sequence`): reescrito com operações em lote; `set_time_limit(300)`; de ~30min para ~20seg para 5.420 leads
- **`list_imported_leads`**: removidas colunas inexistentes `optin_wpp`, `optin_email`, `notes` da query Supabase; substituídas por `optin_status`, `email_optout`
- **`toggle_imported_optin`**: removidos `optin_wpp`/`optin_email` do allowlist de campos válidos

#### Arquivos modificados
- **`wpp_receive.php`**: handler SIM atualizado; 3 novas funções: `wpp_enqueue_followup_sequence()`, `wpp_seq_schedule()`, `wpp_sub_vars()`
- **`email_worker.php`**: pré-carrega `link_video_ira` de `site_config`; variável disponível em todos os templates
- **`admin/admin_proxy.php`**: `import_with_sequence` reescrito (batch); `seq_sub_vars_proxy()` e `import_with_sequence()` suportam `{{link_video_ira}}`; `list_imported_leads` e `toggle_imported_optin` corrigidos

---

### v25 — Janela de envio, status de entrega WPP e geolocalização
- **`email_worker.php`**: janela 08h–21h BRT; primeiro envio sempre imediato
- **`wpp_status.php`** (novo): endpoint dedicado para callback Z-API
- **`wpp_receive.php`**: detecção inline de callbacks de status
- **`track.php`**: geolocalização por IP via ip-api.com (APCu 24h)
- **`admin_proxy.php`**: `funnel_stats()` retorna top 15 cidades e top 10 estados
- **`admin/index.html`**: tabelas "Top Cidades" e "Top Estados"

### v24 — Funil de Conversão Nativo
- **`track.php`** (novo): endpoint com rate limit, 10 eventos, grava em `page_events`
- **`index.html`** + **`ig.html`**: helper `track()` com `session_id`
- **`admin_proxy.php`**: ação `funnel_stats`
- **`admin/index.html`**: página "📉 Funil de Conversão"
- **Supabase**: tabela `page_events` com RLS

### v23 — Admin responsivo mobile
- Sidebar drawer com overlay para ≤768px
- Topbar mobile com hamburguer

### v22 — Dashboard redesenhado + CLAUDE.md
- Dashboard com 8 stats, accordions, tabelas de envios recentes
- `CLAUDE.md` criado do zero

### v21 — Excluir filas completas por lead
- `lead_purge_queues` (DELETE físico email/wpp/both)

### v20 — Descadastro automático e manual
- **`descadastro.php`** (novo)
- Opt-out WPP expandido para +20 palavras-chave
- Botões 🚫 Cancelar fila no perfil do lead

### v17 — Funil de Vendas
- Página "Funil de Vendas" com 6 cards por estágio

### v16 e anteriores
- Setup inicial das landing pages
- Sistema de quiz (4 perfis A/B/C/D)
- Painel admin completo
- Integração Supabase + Resend.com + Z-API
- Filas de e-mail e WhatsApp com worker cron
