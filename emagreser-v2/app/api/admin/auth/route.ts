import { NextRequest, NextResponse } from 'next/server'
import { createServerSupabase } from '@/lib/supabase-server'
import { cookies } from 'next/headers'
import { createHash } from 'crypto'

function hashPassword(password: string): string {
  return createHash('sha256').update(password).digest('hex')
}

export async function POST(request: NextRequest) {
  const { username, password } = await request.json()

  if (!username || !password) {
    return NextResponse.json({ error: 'Credenciais inválidas' }, { status: 400 })
  }

  const supabase = await createServerSupabase()
  const { data: admin } = await supabase
    .from('admin_users')
    .select('id, username, password_hash, active')
    .eq('username', username)
    .eq('active', true)
    .single()

  if (!admin) {
    return NextResponse.json({ error: 'Usuário não encontrado' }, { status: 401 })
  }

  const hashed = hashPassword(password)
  if (hashed !== admin.password_hash) {
    return NextResponse.json({ error: 'Senha incorreta' }, { status: 401 })
  }

  const cookieStore = await cookies()
  cookieStore.set('admin_session', admin.id, {
    httpOnly: true,
    secure: process.env.NODE_ENV === 'production',
    sameSite: 'lax',
    maxAge: 60 * 60 * 8,
    path: '/',
  })

  return NextResponse.json({ ok: true })
}

export async function DELETE() {
  const cookieStore = await cookies()
  cookieStore.delete('admin_session')
  return NextResponse.json({ ok: true })
}
