# EmagreSer v2 — Blueprint para Evolução SaaS

> Documento de planejamento estratégico. Consolida o estado atual do sistema (v1), uma descoberta importante no banco de dados (schema "Área do Aluno" já modelado e não utilizado), a análise de mercado/concorrência, e a proposta de arquitetura para a v2 — um produto SaaS multi-tenant que outros criadores de programas/lançamentos possam usar.
>
> Para detalhes operacionais do sistema atual (fluxos, automações, templates, versões), ver `CLAUDE.md`. Este documento foca no **caminho para a v2**.

---

## 1. Objetivo da v2

Transformar o EmagreSer — hoje um sistema sob medida para o Programa EmagreSer (Daniely + Ira) — em uma **plataforma SaaS multi-tenant** para profissionais de saúde/bem-estar (psicólogos, nutricionistas, coaches, personal trainers) que fazem **lançamentos/turmas recorrentes** com:

- Landing pages com quiz de qualificação de lead
- Automação de e-mail + WhatsApp unificada
- Funil de conversão com tracking de drop-off e geolocalização
- Painel de gestão de leads, sequências e templates
- **Área do Aluno gamificada** (módulo diferencial — ver seção 4)

A v1 já resolve 6 dos 8 principais gaps identificados no mercado (seção 3). A v2 não precisa "reinventar" — precisa **produtizar**: multi-tenant, UI moderna, onboarding self-service, e ativar o módulo de Área do Aluno que já está modelado no banco mas nunca foi construído.

---

## 2. Estado atual (v1) — resumo

| Camada | Tecnologia |
|--------|-----------|
| Hospedagem | Hostinger (PHP 8.x) |
| Banco | Supabase (Postgres), projeto `drgrwpmhmrrhxuwxabow` |
| E-mail | Resend.com |
| WhatsApp | Z-API (instância própria, não-oficial) |
| Admin | SPA HTML/JS puro (sem framework), ~5000 linhas em `admin/index.html` |
| Landing pages | HTML/CSS/JS puro (`index.html`, `ig.html`, `programa.html`) |
| Deploy | Manual via hPanel (upload de arquivos) — **sem CI/CD, sem Git deploy** |

**Pontos fortes a preservar:**
- Modelo de sequências configuráveis (email + WPP intercalados, com delay ou data fixa)
- Substituição de variáveis (`{{nome}}`, `{{link_video_ira}}` etc.) — reaproveitável
- Janela de envio 08h-21h BRT + opt-in/opt-out automático em PT-BR
- Funil de conversão nativo (`page_events`) com geolocalização por IP
- Quiz de perfil sabotador integrado à LP (recém-implementado, v28)

**Limitações estruturais da v1:**
- Single-tenant: tudo hardcoded para "EmagreSer" (textos, mentoras, cores, domínio)
- Sem autenticação multi-usuário real (admin usa sessão PHP simples)
- Configuração via chaves soltas em `site_config` (`prog_*`) — não escala para N páginas/clientes
- Sem CI/CD — todo deploy é manual (upload FTP/hPanel)
- WhatsApp via Z-API não-oficial — risco de banimento, não é o caminho para um SaaS sério

---

## 3. Análise de mercado — gaps priorizados

Pesquisa cobrindo Hotmart, Kiwify, Eduzz, Monetizze, LeadLovers, RD Station, Mautic, ActiveCampaign, Klaviyo, ManyChat, GoHighLevel, Cademi.

| # | Gap de mercado | Situação na v1 |
|---|---|---|
| 1 | WhatsApp + E-mail unificados numa sequência só, sem integração de terceiros | ✅ **Já temos** (sequences + email_queue/whatsapp_queue) |
| 2 | Quiz de qualificação de perfil nativo, ligado à LP e ao funil | ✅ **Já temos** (quiz_questions + sabotador) |
| 3 | Funil de conversão com drop-off por etapa + geolocalização nativos | ✅ **Já temos** (page_events) |
| 4 | "Turmas/lançamentos" como unidade de primeira classe (não tags manuais) | ⚠️ Parcial — existe tabela `turmas` mas não usada na automação principal |
| 5 | Preço acessível em BRL, sem contrato mínimo, para profissional solo | 🔲 Não aplicável na v1 (não é vendido) — definir para v2 |
| 6 | Templates verticais prontos para saúde/bem-estar | ✅ **Já temos** (sequência Masterclass, templates de e-mail) |
| 7 | Janela de envio + opt-in/opt-out automático em PT-BR com compliance | ✅ **Já temos** |
| 8 | Painel unificado por lead (timeline email+WPP, pausar/retomar) | ✅ **Já temos** (leadDetailPage) |
| 9 | **Área do Aluno gamificada integrada ao funil de vendas/automação** | 🆕 **Modelada no banco, zero implementação** — ver seção 4 |

**Conclusão:** o diferencial mais forte e exclusivo do mercado é o item 9 — nenhum concorrente pesquisado oferece uma área de membros gamificada (XP, missões, badges, diário, comunidade) nativamente integrada ao funil de vendas e à automação de mensagens. Isso vira o **carro-chefe da v2**.

---

## 4. Descoberta: schema "Área do Aluno" (não utilizado)

Durante a auditoria do banco para este blueprint, encontramos **13 tabelas já modeladas e populadas com conteúdo seed**, sem nenhuma página/app que as utilize. Isso indica que uma "Área do Aluno" gamificada foi projetada anteriormente (provavelmente em outra sessão/contexto) mas nunca chegou a ser construída no front-end.

### 4.1 Tabelas e estado atual

| Tabela | Linhas | Propósito |
|--------|--------|-----------|
| `turmas` | 0 | Turma/lançamento — `name`, `launch_date`, `whatsapp_link`, `active` |
| `weeks` | **12** | Currículo das 12 semanas — `number`, `title`, `theme`, `mentor_ira_focus`, `mentor_dany_focus`, `unlock_day`, `xp_bonus_100pct` |
| `missions` | **61** | Missões por semana — `week_id`, `title`, `description`, `type`, `xp_reward`, `order_index`, `required`, `mentor` (ira/dany/both) |
| `badges` | **22** | Conquistas — `name`, `description`, `emoji`, `category`, `condition_type`, `condition_value`, `xp_reward` |
| `users_profile` | 0 | Perfil do aluno — `full_name`, `avatar_url`, `behavioral_profile` (sabotador A-D), `turma_id`, `role`, `onboarding_done`, `total_xp`, `current_level`, `streak_days`, `streak_record`, `streak_last_date` |
| `user_missions` | 0 | Progresso do aluno por missão — `status`, `completed_at`, `content`, `xp_earned` |
| `user_badges` | 0 | Badges conquistados pelo aluno |
| `xp_transactions` | 0 | Histórico de pontos — `amount`, `reason`, `source_type`, `source_id` |
| `community_posts` | 0 | Mural da comunidade — `turma_id`, `type`, `content`, `media_url`, `pinned`, `reactions_count` (jsonb: hug/fire/heart) |
| `community_comments` | 0 | Comentários nos posts |
| `diary_entries` | 0 | **Diário alimentar** — `food_description`, `meal_time`, `time_dedicated`, `hunger_before`, `satiety_after`, `location_context`, `emotional_state`, `hunger_type`, `xp_earned` |
| `sabotador_journals` | 0 | **Diário do sabotador** (CBT) — `sabotador_type`, `trigger_situation`, `sabotador_phrase`, `emotion_felt`, `gentle_confrontation`, `outcome`, `xp_earned` |
| `theater_exercises` | 0 | **Exercício de assertividade** — `scenario`, `attacker_phrase`, `assertive_response`, `body_feeling`, `phrase_to_keep`, `xp_earned` |
| `mentors` | **2** | Daniely + Ira — `name`, `role`, `bio`, `photo_url`, `video_url`, `instagram_url`, `facebook_url`, `photo_position` |
| `page_content` | 2 | CMS genérico por seção — `section_name`, `title`, `description`, `content` |

### 4.2 O que isso revela sobre o produto pretendido

O conteúdo já modelado (12 semanas, 61 missões, 22 badges, diário alimentar, diário do sabotador, teatro de assertividade) **espelha exatamente o cronograma de 12 semanas do programa** (documentado em `programa.html` / `CLAUDE.md`). Ou seja: a área de membros foi pensada como a **continuação natural da jornada** — o lead converte na LP → entra na turma → acessa a Área do Aluno → segue as 12 semanas com missões gamificadas, diários terapêuticos (alinhados ao método psicólogo+nutricionista) e comunidade.

Isso é um diferencial competitivo real: **funil de vendas + automação + experiência do aluno em uma única base de dados/tenant**, algo que exigiria integrar 3-4 ferramentas em qualquer concorrente pesquisado.

### 4.3 ATUALIZAÇÃO: o frontend já existe — projeto "gamificacao" (Vercel)

Confirmado nesta sessão via Briefing Executivo enviado pelo usuário + consulta ao Vercel: existe um projeto **Next.js 14 + TypeScript + Tailwind chamado `gamificacao`** (`prj_sRpYVF2BA3T5tPXpoYKzhtEaYjjk`, time `wenderjmns-projects`), já com deploy `READY` em `gamificacao-wenderjmns-projects.vercel.app`. Ou seja, **a seção 4 deste blueprint não é mais "uma proposta" — é a continuação direta de um trabalho em andamento**.

**Estado do frontend (conforme briefing, ~3.500 LOC / 26 componentes / 9 rotas):**

| Módulo | Status |
|---|---|
| Layout (Sidebar/BottomNav/Header com XP+streak) | ✅ Completo |
| Onboarding (triagem 4 passos) | ✅ Completo |
| Check-in emocional (modal, 6 emoções, intensidade 1-5) | ✅ Completo |
| Teatro Interno (Sabotador vs Sábio) | ✅ Completo |
| Medidor de Bem-Estar semanal (Paz c/ Comida, Energia, Sono) | ✅ Completo |
| Passaporte da Autonomia | ⚠️ Parcial (faltam `AutonomyPassport` e página) |
| CMS Mentoras (admin de missões por semana) | ✅ Completo |
| Roda do Autocuidado / Diário | 🔲 Placeholders vazios |
| **Integração Supabase (Auth, RLS, dados reais)** | 🔲 **0%** |

**Importante — o schema real do Supabase (seção 4.1) é MAIS COMPLETO que o "Schema Proposto" do briefing.** O briefing propõe criar `users_profile`, `missions`, `user_missions`, `checkins`, `xp_transactions`, `user_badges` do zero — mas essas tabelas (e mais 7) **já existem, com RLS habilitado e conteúdo real seedado** (12 semanas/61 missões/22 badges/2 mentoras, já espelhando o cronograma do Programa EmagreSer). Mapeamento briefing → schema real:

| Conceito do briefing | Tabela real já existente | Diferença |
|---|---|---|
| `users_profile` (sabotador_type, current_week, total_xp, streak_days) | `users_profile` | Real usa `behavioral_profile` (A-D) + `current_level`/`streak_record`/`turma_id`; sem `current_week` (calculado via `turmas.launch_date` + `weeks.unlock_day`) |
| `missions` (week_number) | `missions` | Real usa `week_id` FK → `weeks` (não `week_number` direto); tem `mentor` (ira/dany/both), `required`, `order_index` |
| `user_missions` (response_data jsonb) | `user_missions` | Real usa `content` (text) + `status` + `xp_earned` |
| `checkins` (intensity, emotion, trigger) | **Não existe — substituído por 3 tabelas mais ricas**: `diary_entries` (diário alimentar), `sabotador_journals` (CBT/check-in emocional do sabotador), `theater_exercises` (Sabotador vs Sábio) |
| `xp_transactions`, `user_badges`, `badges` | Idêntico, já existe e populado | — |
| — (não previsto no briefing) | `community_posts`/`community_comments`, `mentors`, `turmas`, `weeks`, `page_content` | Módulos extras já modelados |

**Conclusão prática:** a Fase 2 do roadmap do briefing ("Integração Supabase — criar schema") **já está feita e é superior ao planejado**. O trabalho real é conectar os componentes React existentes às tabelas reais (Auth, queries, engine de XP/badges/`unlock_day`), não criar schema novo.

### 4.4 Tabelas legadas/duplicadas encontradas (limpar na v2)

| Tabela | Linhas | Observação |
|--------|--------|-----------|
| `imported_leads` | 0 | Duplicata antiga de `leads` (source=import) — schema com `optin_wpp`/`optin_email` que o CLAUDE.md já marca como inexistentes na tabela `leads` atual |
| `wpp_queue` | 0 | Duplicata antiga de `whatsapp_queue` (schema mais simples, sem `delivery_status`/`zapi_message_id`) |
| `whatsapp_config` | 1 | Config solta (`whatsapp_link`) — substituída por `site_config` |
| `automation_log` | 0 | Log genérico não usado pelo `email_worker.php`/`wpp_receive.php` atuais (que não logam em tabela própria) |

Recomendação: **não migrar** essas 4 tabelas para a v2 — são vestígios de uma arquitetura anterior, totalmente vazias, substituídas pelas tabelas atuais (`leads`, `whatsapp_queue`, `site_config`).

---

## 5. Segurança — auditoria realizada

- ✅ `wpp_interactions` estava **sem RLS** (exposta via API se a anon key fosse usada para SELECT). Corrigido nesta sessão: RLS habilitado + policy `service_role_all` (FOR ALL TO service_role).
- Todas as demais 31 tabelas do schema `public` já possuem RLS habilitado.
- Recomendação para v2: ao criar o schema multi-tenant, toda tabela nova deve nascer com RLS + policy por `tenant_id`/`auth.uid()` desde o primeiro migration.

---

## 6. Proposta de arquitetura v2

### 6.1 Stack

| Camada | v1 (atual) | v2 (proposta) |
|--------|-----------|---------------|
| Frontend | HTML/JS puro | **Next.js (App Router)** — SSR para LPs (SEO), client components para admin/área do aluno |
| Hosting | Hostinger PHP | **Vercel** (já conectado via MCP neste ambiente — deploy automático por branch) |
| Banco | Supabase Postgres | Mantém Supabase — Auth, Storage, Realtime (útil para comunidade/XP ao vivo) |
| Backend/API | PHP isolado (`*.php`) | **Next.js API Routes / Server Actions** + Supabase Edge Functions para workers (email/WPP cron) |
| E-mail | Resend | Mantém Resend |
| WhatsApp | Z-API (não-oficial) | **Avaliar WhatsApp Cloud API oficial** (Meta) por tenant — risco de banimento da Z-API é incompatível com SaaS pago. Pode manter Z-API como opção "BYO" para tenants técnicos, com Cloud API como padrão |
| Deploy | Manual (hPanel/FTP) | **Git push → Vercel (CI/CD automático)** — resolve o bloqueio de deploy manual da v1 |

### 6.2 Multi-tenant

- Cada conta = 1 `tenant` (psicólogo/nutricionista/coach individual)
- Tabelas centrais ganham `tenant_id` (leads, sequences, templates, turmas, weeks, missions, page_content, site_config)
- RLS por `tenant_id = auth.jwt() ->> 'tenant_id'` (ou tabela `tenant_members` para equipes)
- Supabase Auth para login do admin **e** dos alunos (hoje a v1 não tem auth real para nenhum dos dois)

### 6.3 Editor de páginas (substitui `CONFIG_KEYS_PAGES`)

Em vez de uma lista plana e hardcoded de chaves por página (`prog_hero_titulo`, `prog_bonus1_titulo`, ...), modelar como:

```
pages (id, tenant_id, slug, title, template)
page_blocks (id, page_id, type, order_index, data jsonb)
```

Onde `type` ∈ `hero | text | image | video | bonus | faq | price | testimonials | cta`. O admin vira um editor genérico de blocos (drag-and-drop ou formulário por tipo), reutilizável para LP, IG, Programa e qualquer página nova — sem precisar codar um novo `CONFIG_KEYS_*` a cada vez. A tabela `page_content` já existente (`section_name`, `title`, `description`, `content`) é um precursor disso — a v2 formaliza e expande esse padrão.

### 6.4 Área do Aluno (módulo carro-chefe)

Construir o app de membros sobre o schema já existente (seção 4):

- **Onboarding**: aluno faz login (Supabase Auth) → quiz de perfil sabotador (reusa `quiz_questions`) → grava `users_profile.behavioral_profile`
- **Dashboard semanal**: `weeks` + `missions` desbloqueados por `unlock_day` desde `turmas.launch_date`
- **Gamificação**: completar missão → `user_missions.status='done'` → trigger grava `xp_transactions` → atualiza `users_profile.total_xp`/`current_level` → checa `badges.condition_type/value` → concede `user_badges`
- **Diários terapêuticos**: 3 formulários (alimentar, sabotador, teatro de assertividade) ligados ao método das mentoras, cada um concede XP
- **Comunidade**: mural por turma (`community_posts`/`community_comments`) com reações
- **Streak**: `streak_days`/`streak_record` — gamificação de consistência (hábito diário)

Esse módulo é o que justifica um SaaS "vendável": LP + automação por si só já tem concorrência (mesmo com gaps); **LP + automação + experiência de aluno gamificada em um único produto** é uma categoria nova.

**Status real (atualizado):** o app `gamificacao` (Next.js, Vercel) já implementa boa parte da UI (seção 4.3). O trabalho restante para um MVP funcional:

1. Conectar Supabase Auth (login/registro do aluno) no projeto `gamificacao`
2. Implementar client Supabase (browser/server) + RLS por `auth.uid()` nas tabelas `users_profile`, `user_missions`, `diary_entries`, `sabotador_journals`, `theater_exercises`, `user_badges`, `xp_transactions`
3. Engine de `unlock_day` (semana atual = `turmas.launch_date` + `weeks.unlock_day`)
4. Engine de XP/Badges (trigger ou função no Supabase: missão concluída → `xp_transactions` → `users_profile.total_xp`/`current_level` → checa `badges` → `user_badges`)
5. Conectar CMS Mentoras (admin do `gamificacao`) às tabelas `weeks`/`missions` reais
6. Completar telas faltantes: `AutonomyPassport`, Roda do Autocuidado, Diário (3 formulários → `diary_entries`/`sabotador_journals`/`theater_exercises`)

---

## 7. Roadmap de migração por fases

| Fase | Entregável | Depende de |
|------|-----------|-----------|
| **0 — Agora até ~20/08** | Estratégia de nutrição de conteúdo (fora deste documento — discutido separadamente) + correções pontuais na v1 | — |
| **1 — Fundação v2** | Setup Next.js + Vercel + Supabase Auth; schema multi-tenant (tenant_id em tabelas core); editor de blocos genérico | Decisão de stack (este doc) |
| **2 — Migração de automação** | Portar sequences/templates/queues + workers (email/WPP) para Edge Functions; avaliar WhatsApp Cloud API | Fase 1 |
| **3 — Área do Aluno (MVP)** | **Já em andamento** (projeto `gamificacao`, Vercel) — falta: integração Supabase (Auth, RLS, queries reais), engine de XP/badges/`unlock_day`, telas faltantes (seção 6.4) | Independe das demais — pode evoluir em paralelo |
| **4 — Comunidade + Diários** | Mural, diário alimentar, diário do sabotador, teatro de assertividade | Fase 3 |
| **5 — Self-service onboarding** | Tenant cria conta, configura sua turma/sequências/páginas sem suporte manual | Fases 1-4 |
| **6 — Lançamento comercial** | Pricing BRL, planos, billing (Stripe/Mercado Pago) | Fase 5 |

A v1 continua operando (organicamente + Aquecimento Importados) durante as fases 1-3, sem necessidade de migração de dados até a Fase 5 (apenas 1 tenant = EmagreSer).

---

## 8. Decisões pendentes (para discussão)

1. **WhatsApp**: Z-API (rápido, risco de ban) vs Cloud API oficial (mais estável, requer aprovação Meta + custo por conversa) — qual o ponto de partida da v2?
2. **Billing**: Stripe (internacional) vs Mercado Pago/Pagar.me (BRL nativo, Pix)?
3. **Escopo do MVP comercial**: vender já com Área do Aluno, ou lançar primeiro só "LP + automação" (mais rápido) e adicionar Área do Aluno depois?
4. **Tabelas legadas** (`imported_leads`, `wpp_queue`, `whatsapp_config`, `automation_log`): confirmar remoção (estão vazias e substituídas).
5. **Reaproveitamento de conteúdo**: as 12 semanas/61 missões/22 badges já modeladas são específicas do EmagreSer — na v2 multi-tenant, isso se torna um "template de programa" que cada tenant pode clonar/customizar?
