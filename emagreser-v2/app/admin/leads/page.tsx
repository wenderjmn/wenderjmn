import { createServerSupabase } from '@/lib/supabase-server'
import PageHeader from '@/components/ui/PageHeader'
import Badge from '@/components/ui/Badge'
import EmptyState from '@/components/ui/EmptyState'

export const dynamic = 'force-dynamic'

const SABOTADOR_LABELS: Record<string, string> = {
  A: 'Hiperrracional',
  B: 'Agradadora',
  C: 'Hipervigilante',
  D: 'Controladora',
}

export default async function AdminLeadsPage({
  searchParams,
}: {
  searchParams: Promise<{ source?: string; sabotador?: string; q?: string }>
}) {
  const params = await searchParams
  const supabase = await createServerSupabase()

  let query = supabase.from('leads').select('*').order('created_at', { ascending: false }).limit(100)

  if (params.source) query = query.eq('source', params.source)
  if (params.sabotador) query = query.eq('sabotador', params.sabotador)
  if (params.q) query = query.ilike('name', `%${params.q}%`)

  const { data: leads } = await query

  const sourceVariant: Record<string, 'green' | 'blue' | 'purple'> = {
    site: 'green',
    instagram: 'purple',
  }

  return (
    <div className="space-y-6">
      <PageHeader title="Leads" subtitle={`${leads?.length ?? 0} registros`} />

      {/* Filtros */}
      <form className="flex flex-wrap gap-3">
        <input
          type="text"
          name="q"
          defaultValue={params.q}
          placeholder="Buscar por nome..."
          className="px-4 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
        />
        <select name="source" defaultValue={params.source ?? ''} className="px-4 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none">
          <option value="">Todos os canais</option>
          <option value="site">Site</option>
          <option value="instagram">Instagram</option>
        </select>
        <select name="sabotador" defaultValue={params.sabotador ?? ''} className="px-4 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none">
          <option value="">Todos sabotadores</option>
          {Object.entries(SABOTADOR_LABELS).map(([k, v]) => (
            <option key={k} value={k}>{v}</option>
          ))}
        </select>
        <button type="submit" className="px-4 py-2 bg-green-600 text-white text-sm font-medium rounded-xl hover:bg-green-700 transition-colors">
          Filtrar
        </button>
        <a href="/admin/leads" className="px-4 py-2 text-gray-500 text-sm rounded-xl hover:bg-gray-100 transition-colors">
          Limpar
        </a>
      </form>

      {(!leads || leads.length === 0) ? (
        <EmptyState icon="👥" title="Nenhum lead encontrado" description="Ajuste os filtros ou aguarde novos registros." />
      ) : (
        <div className="bg-white rounded-2xl border border-gray-100 overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-gray-100 bg-gray-50">
                  {['Nome', 'E-mail', 'Telefone', 'Sabotador', 'Canal', 'Data'].map(h => (
                    <th key={h} className="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {leads.map(lead => (
                  <tr key={lead.id} className="border-b border-gray-50 hover:bg-gray-50/50">
                    <td className="px-4 py-3 font-medium text-gray-900">{lead.name ?? '—'}</td>
                    <td className="px-4 py-3 text-gray-600">{lead.email ?? '—'}</td>
                    <td className="px-4 py-3 text-gray-600">{lead.phone ?? '—'}</td>
                    <td className="px-4 py-3">
                      {lead.sabotador ? (
                        <Badge variant="green" size="sm">{SABOTADOR_LABELS[lead.sabotador] ?? lead.sabotador}</Badge>
                      ) : '—'}
                    </td>
                    <td className="px-4 py-3">
                      {lead.source ? (
                        <Badge variant={sourceVariant[lead.source] ?? 'gray'} size="sm">{lead.source}</Badge>
                      ) : '—'}
                    </td>
                    <td className="px-4 py-3 text-gray-500 text-xs">
                      {lead.created_at ? new Date(lead.created_at).toLocaleDateString('pt-BR') : '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}
