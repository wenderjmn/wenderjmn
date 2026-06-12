# EmagreSer v2 — Guia de Início (Novo Projeto)

> Este documento orienta a criação do **novo repositório** para a v2 do EmagreSer, com stack moderna (Next.js + Vercel + Supabase), separado do repositório atual (`wenderjmn/wenderjmn`, v1 em PHP/Hostinger).

---

## Por que um repositório novo

A v1 está em produção (oficialemagreser.com), atendendo leads reais e rodando automações ativas. A v2 muda completamente a stack (PHP → Next.js, Hostinger → Vercel). Misturar os dois no mesmo repositório criaria risco de:

- Quebrar o site em produção durante a evolução da v2
- Conflitos entre o workflow de deploy FTP (v1) e o deploy Vercel (v2)
- Dificuldade de revisar PRs (mudanças de stack completamente diferentes no mesmo histórico)

**Decisão**: v1 e v2 em repositórios separados. O banco Supabase (`drgrwpmhmrrhxuwxabow`) é **compartilhado** durante a transição — não há migração de dados a fazer agora.

---

## Passo a passo

### 1. Criar o repositório

Em `github.com/new`, criar (sugestão de nome): **`emagreser-v2`** (ou outro nome de sua preferência — ex: `emagreser-saas`, `emagreser-platform`).

Pode iniciar vazio (sem README, sem .gitignore — o scaffold do Next.js cuida disso).

### 2. Dar acesso ao app "Claude" neste novo repositório

1. `github.com/settings/installations`
2. App **Claude** (ou "Claude for GitHub") → **Configure**
3. Adicionar o novo repositório (`emagreser-v2`) à lista de **Repository access**
4. Confirmar que a permissão **Contents: Read and write** está ativa

### 3. Copiar a documentação inicial para o novo repositório

Os 3 arquivos abaixo (desta pasta `docs/v2-handoff/`) devem ser copiados para o novo repositório:

| Arquivo neste repo | Destino no novo repo |
|---|---|
| `docs/v2-handoff/CLAUDE.md` | `CLAUDE.md` (raiz) |
| `docs/v2-handoff/SETUP.md` | `docs/SETUP.md` |
| `docs/v2-blueprint.md` | `docs/v1-blueprint-historico.md` (referência — mantém todo o contexto de decisões da v1→v2) |

Também é útil exportar o `CLAUDE.md` atual deste repositório (`wenderjmn/wenderjmn/CLAUDE.md`) como `docs/v1-claude-reference.md` no novo repo — ele documenta todas as regras de negócio (sequências, templates, variáveis, opt-in/out) que a v2 precisa replicar.

### 4. Abrir uma nova sessão no claude.ai/code

Selecione o novo repositório (`emagreser-v2`) como destino da sessão. Meu acesso nesta sessão atual está restrito a `wenderjmn/wenderjmn` — não consigo criar arquivos/commits em outro repositório a partir daqui.

### 5. Primeiro prompt sugerido para a nova sessão

```
Li o CLAUDE.md e docs/SETUP.md deste repositório. Quero iniciar o projeto
EmagreSer v2 conforme descrito: Next.js (App Router) + Vercel + Supabase
(projeto drgrwpmhmrrhxuwxabow, já existente — não criar novo).

Comece pela Fase 1 do roadmap (docs/v1-blueprint-historico.md, seção 7):
- Scaffold do projeto (Next.js + TS + Tailwind)
- Conectar Supabase (client browser/server)
- Estrutura de pastas inicial
- Página de login (Supabase Auth) como primeiro fluxo funcional
```

---

## O que NÃO fazer agora

- Não apagar/migrar nada do `wenderjmn/wenderjmn` (v1 continua em produção)
- Não criar um novo projeto Supabase — a v2 usa o mesmo banco (`drgrwpmhmrrhxuwxabow`), adicionando `tenant_id` às tabelas core quando chegar a hora (Fase 1 do roadmap)
- Não decidir ainda sobre o projeto **`gamificacao`** (Vercel) — ele pode acabar sendo absorvido pelo `emagreser-v2` ou servir de referência. Isso fica para a primeira sessão do novo repo, quando localizarmos o código-fonte dele.
