# EmagreSer v2 — Documentação do Projeto (Draft)

> Este é um **rascunho inicial** de `CLAUDE.md` para o novo repositório da v2. Ajuste/expanda conforme o projeto evolui — o objetivo é dar contexto suficiente para qualquer sessão começar produtiva.

## Visão Geral

EmagreSer v2 é a evolução do sistema atual (v1, repositório `wenderjmn/wenderjmn`, PHP/Hostinger) para uma **plataforma SaaS multi-tenant** voltada a profissionais de saúde/bem-estar (psicólogos, nutricionistas, coaches) que fazem lançamentos/turmas recorrentes.

Módulos principais:
- Landing pages com quiz de qualificação de lead (editor de blocos genérico)
- Automação de e-mail + WhatsApp unificada (sequências configuráveis)
- Funil de conversão com tracking de drop-off e geolocalização
- Painel de gestão (leads, sequências, templates, páginas)
- **Área do Aluno gamificada** (módulo carro-chefe — XP, missões, badges, diários, comunidade)

Para o racional completo de decisões (análise de mercado, schema já existente, roadmap por fases), ver `docs/v1-blueprint-historico.md`.

---

## Stack Tecnológica

| Camada | Tecnologia |
|--------|-----------|
| Frontend | Next.js (App Router) + TypeScript + Tailwind |
| Hosting/CI-CD | Vercel (deploy automático por push) |
| Banco de dados | Supabase (Postgres) — projeto `drgrwpmhmrrhxuwxabow` (compartilhado com v1 durante a transição) |
| Auth | Supabase Auth (admin do tenant + alunos) |
| E-mail transacional | Resend.com |
| WhatsApp | Z-API (avaliar migração para WhatsApp Cloud API oficial — ver decisões pendentes) |

---

## Banco de Dados (Supabase — projeto `drgrwpmhmrrhxuwxabow`)

**Importante**: este projeto Supabase é o **mesmo usado pela v1**. Não criar um novo projeto. Ao adicionar `tenant_id` em tabelas existentes (Fase 1), coordenar com o que está em produção na v1 (ver `docs/v1-claude-reference.md`).

### Tabelas já existentes e relevantes para a v2

**Automação/CRM** (já em uso pela v1 — ver `docs/v1-claude-reference.md` para detalhes de colunas):
- `leads`, `email_queue`, `whatsapp_queue`, `email_templates`, `wpp_templates`, `sequences`, `site_config`, `page_events`, `testimonials`

**Área do Aluno (módulo carro-chefe da v2 — schema já modelado, zero implementação até o momento)**:
- `turmas`, `weeks` (12), `missions` (61), `badges` (22), `users_profile`, `user_missions`, `user_badges`, `xp_transactions`, `community_posts`, `community_comments`, `diary_entries`, `sabotador_journals`, `theater_exercises`, `mentors` (2), `page_content` (2)

Ver `docs/v1-blueprint-historico.md` seção 4 para descrição completa de cada tabela/coluna.

**Tabelas legadas (não usar na v2)**: `imported_leads`, `wpp_queue`, `whatsapp_config`, `automation_log` — vazias, substituídas pelas tabelas atuais.

---

## Convenções de Código

- TypeScript estrito
- Componentes em `app/` (App Router) — Server Components por padrão, Client Components só quando necessário (interatividade)
- Supabase client: separar `lib/supabase/server.ts` (Server Components/Actions, usa service role quando necessário) e `lib/supabase/client.ts` (browser, usa anon key)
- RLS habilitado em **toda** tabela nova desde a primeira migration, com policy por `tenant_id` (admin) ou `auth.uid()` (área do aluno)
- Migrations via Supabase MCP (`apply_migration`) — nunca alterar schema direto sem migration registrada

---

## Multi-tenant (Fase 1)

- 1 conta = 1 `tenant` (profissional/clínica)
- Tabelas core ganham `tenant_id`: `leads`, `sequences`, `email_templates`, `wpp_templates`, `site_config`, `turmas`, `weeks`, `missions`, `page_content`, `pages`/`page_blocks`
- RLS: `tenant_id = (auth.jwt() -> 'app_metadata' ->> 'tenant_id')` (ajustar conforme estrutura de claims escolhida)
- Durante a fase de transição, **EmagreSer é o único tenant** — os dados existentes recebem um `tenant_id` fixo (seed)

---

## Editor de Páginas (substitui `CONFIG_KEYS_PAGES` da v1)

```
pages (id, tenant_id, slug, title, template)
page_blocks (id, page_id, type, order_index, data jsonb)
```

`type` ∈ `hero | text | image | video | bonus | faq | price | testimonials | cta`

A tabela `page_content` (já existente, 2 rows) é o precursor — a v2 formaliza esse padrão para todas as páginas (LP, IG, Programa, e futuras páginas de outros tenants).

---

## Área do Aluno — Status e Próximos Passos

Há um projeto Vercel **`gamificacao`** (Next.js 14 + TS + Tailwind, ~3.500 LOC, 26 componentes, 9 rotas) que já implementa boa parte da UI desta área (onboarding, check-in emocional, teatro interno, medidor de bem-estar, CMS mentoras). **Localizar o repositório-fonte desse projeto é a primeira tarefa** — ele pode se tornar a base deste repositório `emagreser-v2` em vez de recomeçar do zero.

Trabalho restante (independente de onde o código mora):
1. Conectar Supabase Auth (login/registro do aluno)
2. Client Supabase (browser/server) + RLS por `auth.uid()` em `users_profile`, `user_missions`, `diary_entries`, `sabotador_journals`, `theater_exercises`, `user_badges`, `xp_transactions`
3. Engine de `unlock_day` (semana atual = `turmas.launch_date` + `weeks.unlock_day`)
4. Engine de XP/Badges (trigger/função: missão concluída → `xp_transactions` → `users_profile.total_xp`/`current_level` → checa `badges` → `user_badges`)
5. Conectar CMS Mentoras às tabelas reais (`weeks`/`missions`)
6. Completar telas faltantes: `AutonomyPassport`, Roda do Autocuidado, Diário (3 formulários)

---

## Decisões Pendentes

Ver `docs/v1-blueprint-historico.md` seção 8. Resumo:
1. WhatsApp: Z-API vs Cloud API oficial
2. Billing: Stripe vs Mercado Pago/Pagar.me
3. Escopo do MVP: com ou sem Área do Aluno no lançamento comercial
4. Confirmação de remoção das 4 tabelas legadas
5. Conteúdo das 12 semanas/61 missões/22 badges — tornar "template de programa" clonável por tenant?

---

## Roadmap (resumo — ver blueprint para detalhes)

| Fase | Entregável |
|------|-----------|
| 1 | Setup Next.js + Vercel + Supabase Auth + schema multi-tenant + editor de blocos |
| 2 | Migração de automação (sequences/templates/queues → Edge Functions) |
| 3 | Área do Aluno MVP (integração Supabase no projeto `gamificacao`) |
| 4 | Comunidade + Diários |
| 5 | Self-service onboarding |
| 6 | Lançamento comercial (pricing, billing) |

A v1 (`wenderjmn/wenderjmn`) continua operando em produção durante as fases 1-3.
