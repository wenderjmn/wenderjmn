# Setup Inicial — EmagreSer v2

Guia técnico para o scaffold do novo repositório (Next.js + Vercel + Supabase).

---

## 1. Scaffold do projeto

```bash
npx create-next-app@latest . --typescript --tailwind --app --eslint --src-dir=false --import-alias "@/*"
```

## 2. Dependências adicionais

```bash
npm install @supabase/supabase-js @supabase/ssr
```

## 3. Variáveis de ambiente (`.env.local` — não versionar)

Reaproveitar as credenciais do **mesmo projeto Supabase** da v1 (`drgrwpmhmrrhxuwxabow`):

```
NEXT_PUBLIC_SUPABASE_URL=https://drgrwpmhmrrhxuwxabow.supabase.co
NEXT_PUBLIC_SUPABASE_ANON_KEY=<anon/publishable key>
SUPABASE_SERVICE_ROLE_KEY=<service_role key — apenas server-side>
```

> A `SUPABASE_SERVICE_ROLE_KEY` é um segredo de produção. **Nunca commitar.** Configurar via Vercel Project Settings → Environment Variables, e via `.env.local` (já no `.gitignore` padrão do Next.js).
>
> Obs.: o histórico do repositório v1 (`wenderjmn/wenderjmn`) teve uma service key exposta em commits antigos, que foi removida via reescrita de histórico — **essa chave específica pode já estar comprometida**. Considerar rotacionar a service_role key do projeto Supabase como parte do setup (Project Settings → API → gerar nova chave), atualizando também `_env.php` na v1.

## 4. Estrutura de pastas sugerida

```
app/
  (auth)/
    login/
    signup/
  (admin)/
    dashboard/
    leads/
    sequences/
    pages/            # editor de blocos
  (aluno)/
    onboarding/
    dashboard/
    missoes/
    diario/
    comunidade/
lib/
  supabase/
    client.ts         # browser client (anon key)
    server.ts         # server client (cookies/session, ou service role quando necessário)
  utils/
components/
  ui/                  # componentes genéricos (botões, cards, etc.)
docs/
  v1-blueprint-historico.md
  v1-claude-reference.md
  SETUP.md
```

## 5. Conectar ao Vercel

```bash
npx vercel link
npx vercel env pull .env.local
```

Configurar as variáveis de ambiente (passo 3) no dashboard do Vercel antes do primeiro deploy, em **Project Settings → Environment Variables** (Production + Preview + Development).

## 6. Supabase clients (referência rápida)

`lib/supabase/client.ts`:
```ts
import { createBrowserClient } from '@supabase/ssr'

export function createClient() {
  return createBrowserClient(
    process.env.NEXT_PUBLIC_SUPABASE_URL!,
    process.env.NEXT_PUBLIC_SUPABASE_ANON_KEY!
  )
}
```

`lib/supabase/server.ts`:
```ts
import { createServerClient } from '@supabase/ssr'
import { cookies } from 'next/headers'

export async function createClient() {
  const cookieStore = await cookies()
  return createServerClient(
    process.env.NEXT_PUBLIC_SUPABASE_URL!,
    process.env.NEXT_PUBLIC_SUPABASE_ANON_KEY!,
    {
      cookies: {
        getAll: () => cookieStore.getAll(),
        setAll: (cookies) => {
          cookies.forEach(({ name, value, options }) =>
            cookieStore.set(name, value, options)
          )
        },
      },
    }
  )
}
```

## 7. Primeira tarefa recomendada

1. Tela de login (Supabase Auth — email/senha ou magic link)
2. Middleware de sessão (`middleware.ts`) protegendo rotas `(admin)` e `(aluno)`
3. Página inicial simples listando dados reais de `leads` (via `lib/supabase/server.ts`) — valida a conexão end-to-end com o banco existente
