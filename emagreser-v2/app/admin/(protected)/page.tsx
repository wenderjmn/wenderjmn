import { createServerSupabase } from '@/lib/supabase-server'
import Link from 'next/link'

export const dynamic = 'force-dynamic'

export default async function AdminPage() {
  const supabase = await createServerSupabase()
  const sevenDaysAgo = new Date(Date.now() - 7 * 24 * 60 * 60 * 1000).toISOString()

  const [
    { count: totalLeads },
    { count: newLeads },
    { count: totalUsers },
  ] = await Promise.all([
    supabase.from('leads').select('id', { count: 'exact', head: true }),
    supabase.from('leads').select('id', { count: 'exact', head: true }).gte('created_at', sevenDaysAgo),
    supabase.from('users_profile').select('id', { count: 'exact', head: true }),
  ])

  const stats = [
    { label: 'Total de Leads', value: totalLeads ?? 0, icon: '👥', href: '/admin/leads' },
    { label: 'Leads (7 dias)', value: newLeads ?? 0, icon: '📈', href: '/admin/leads' },
    { label: 'Participantes', value: totalUsers ?? 0, icon: '🌱', href: '/admin/leads' },
  ]

  const quickLinks = [
    { href: '/admin/leads', icon: '👥', label: 'Gerenciar Leads', desc: 'Visualizar e filtrar leads capturados' },
    { href: '/admin/missoes', icon: '🎯', label: 'Missões', desc: 'Criar e editar missões por semana' },
    { href: '/admin/email-templates', icon: '📧', label: 'E-mail Templates', desc: 'Editar templates de automação' },
    { href: '/admin/mentoras', icon: '👩‍🏫', label: 'Mentoras', desc: 'Perfis da Ira e Dany' },
    { href: '/admin/config', icon: '⚙️', label: 'Configurações', desc: 'WhatsApp, textos do site' },
  ]

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-bold text-[#1a1a2e]">Dashboard</h1>
        <p className="text-gray-500 text-sm mt-1">Visão geral do EmagreSer V2</p>
      </div>

      <div className="grid sm:grid-cols-3 gap-4">
        {stats.map(stat => (
          <Link key={stat.label} href={stat.href} className="bg-white rounded-2xl border border-gray-100 p-5 hover:border-green-200 transition-colors">
            <div className="text-3xl mb-2">{stat.icon}</div>
            <div className="text-3xl font-bold text-[#1a1a2e]">{stat.value.toLocaleString('pt-BR')}</div>
            <div className="text-sm text-gray-500 mt-1">{stat.label}</div>
          </Link>
        ))}
      </div>

      <div>
        <h2 className="text-lg font-semibold text-[#1a1a2e] mb-4">Acesso rápido</h2>
        <div className="grid sm:grid-cols-2 gap-4">
          {quickLinks.map(item => (
            <Link key={item.href} href={item.href} className="bg-white rounded-2xl border border-gray-100 p-5 flex items-start gap-4 hover:border-green-200 transition-colors">
              <span className="text-2xl">{item.icon}</span>
              <div>
                <p className="font-semibold text-[#1a1a2e] text-sm">{item.label}</p>
                <p className="text-xs text-gray-500 mt-0.5">{item.desc}</p>
              </div>
            </Link>
          ))}
        </div>
      </div>
    </div>
  )
}
