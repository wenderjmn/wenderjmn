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
├── descadastro.php         # Página de descadastro de e-mail (link nos rodapés)
├── email_trigger.php       # Enfileira sequência ao novo lead orgânico
├── email_worker.php        # Worker cron: processa email_queue + whatsapp_queue
├── import_leads.php        # Importação de CSV com sequência configurável
├── cron.php                # Auto-enrola leads importados (cron-job.org, horário)
├── wpp_receive.php         # Webhook Z-API: processa respostas dos leads
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

---

## Fluxo de um Lead Orgânico

```
Visitante acessa ig.html ou index.html
  ↓
ig.html  → Preenche formulário VIP na Hero (nome/email/phone)
           → POST email_trigger.php → 7 e-mails + 13 WPPs enfileirados
           → sequence_queued_at = now

index.html → Clica botão quiz → lead-modal (captura dados)
           → Faz quiz (4 perguntas) → Resultado + formulário
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

## Histórico de Versões

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
