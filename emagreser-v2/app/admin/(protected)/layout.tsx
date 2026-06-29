import { redirect } from 'next/navigation'
import { cookies } from 'next/headers'
import { createServerSupabase } from '@/lib/supabase-server'
import Link from 'next/link'

async function getAdminUser() {
  const cookieStore = await cookies()
  const adminId = cookieStore.get('admin_session')?.value
  if (!adminId) return null

  const supabase = await createServerSupabase()
  const { data } = await supabase
    .from('admin_users')
    .select('id, username, active')
    .eq('id', adminId)
    .eq('active', true)
    .single()

  return data
}

const NAV_ITEMS = [
  { href: '/admin', label: '📊 Dashboard' },
  { href: '/admin/leads', label: '👥 Leads' },
  { href: '/admin/missoes', label: '🎯 Missões' },
  { href: '/admin/email-templates', label: '📧 E-mails' },
  { href: '/admin/mentoras', label: '👩‍🏫 Mentoras' },
  { href: '/admin/config', label: '⚙️ Config' },
]

export default async function AdminLayout({ children }: { children: React.ReactNode }) {
  const admin = await getAdminUser()

  if (!admin) {
    redirect('/admin/login')
  }

  return (
    <div className="min-h-screen bg-[#faf9f7]">
      <nav className="bg-white border-b border-gray-100 sticky top-0 z-10">
        <div className="max-w-7xl mx-auto px-4 h-14 flex items-center justify-between">
          <div className="flex items-center gap-6">
            <span className="font-bold text-[#1a1a2e]">🌱 Admin</span>
            <div className="hidden sm:flex items-center gap-1">
              {NAV_ITEMS.map(item => (
                <Link
                  key={item.href}
                  href={item.href}
                  className="px-3 py-1.5 rounded-lg text-sm text-gray-600 hover:bg-gray-100 hover:text-gray-900 transition-colors"
                >
                  {item.label}
                </Link>
              ))}
            </div>
          </div>
          <div className="flex items-center gap-3">
            <span className="text-sm text-gray-500">{admin.username}</span>
            <form action="/api/admin/auth" method="POST">
              <button
                type="button"
                onClick={async () => {
                  await fetch('/api/admin/auth', { method: 'DELETE' })
                  window.location.href = '/admin/login'
                }}
                className="text-sm text-gray-400 hover:text-gray-600"
              >
                Sair
              </button>
            </form>
          </div>
        </div>
      </nav>
      <main className="max-w-7xl mx-auto px-4 py-8">
        {children}
      </main>
    </div>
  )
}
