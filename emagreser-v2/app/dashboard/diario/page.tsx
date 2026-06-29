import { createServerSupabase } from '@/lib/supabase-server'
import Link from 'next/link'
import PageHeader from '@/components/ui/PageHeader'
import Button from '@/components/ui/Button'
import EmptyState from '@/components/ui/EmptyState'
import Badge from '@/components/ui/Badge'

export const dynamic = 'force-dynamic'

const MEAL_LABELS: Record<string, string> = {
  cafe: 'Café da manhã',
  lanche_manha: 'Lanche da manhã',
  almoco: 'Almoço',
  lanche_tarde: 'Lanche da tarde',
  jantar: 'Jantar',
  ceia: 'Ceia',
}

const HUNGER_TYPE_LABELS: Record<string, string> = {
  fisico: 'Fome física',
  emocional: 'Fome emocional',
  ansiedade: 'Ansiedade',
  tedio: 'Tédio',
  habito: 'Hábito',
}

export default async function DiarioPage() {
  const supabase = await createServerSupabase()
  const { data: { user } } = await supabase.auth.getUser()

  const today = new Date().toISOString().split('T')[0]

  const { data: entries } = await supabase
    .from('diary_entries')
    .select('*')
    .eq('user_id', user!.id)
    .gte('created_at', today + 'T00:00:00')
    .order('created_at', { ascending: false })

  const totalXpToday = (entries ?? []).reduce((sum, e) => sum + (e.xp_earned ?? 0), 0)

  return (
    <div className="max-w-2xl mx-auto px-4 py-8">
      <PageHeader
        title="Diário Alimentar"
        subtitle={`${new Date().toLocaleDateString('pt-BR', { weekday: 'long', day: 'numeric', month: 'long' })}`}
        action={
          <Link href="/dashboard/diario/nova">
            <Button size="sm">+ Nova entrada</Button>
          </Link>
        }
      />

      {totalXpToday > 0 && (
        <div className="bg-green-50 border border-green-100 rounded-2xl px-5 py-3 mb-6 flex items-center gap-3">
          <span className="text-2xl">⚡</span>
          <p className="text-sm text-green-800 font-medium">
            Você ganhou <strong>{totalXpToday} XP</strong> hoje pelo diário!
          </p>
        </div>
      )}

      {(!entries || entries.length === 0) ? (
        <EmptyState
          icon="📔"
          title="Nenhuma entrada hoje"
          description="Registre sua primeira refeição do dia e ganhe XP!"
          action={
            <Link href="/dashboard/diario/nova">
              <Button>Registrar refeição</Button>
            </Link>
          }
        />
      ) : (
        <div className="space-y-4">
          {entries.map((entry) => (
            <div key={entry.id} className="bg-white rounded-2xl border border-gray-100 p-5">
              <div className="flex items-start justify-between mb-2">
                <div className="flex items-center gap-2">
                  <span className="font-semibold text-[#1a1a2e] text-sm">
                    {MEAL_LABELS[entry.meal_time] ?? entry.meal_time}
                  </span>
                  {entry.hunger_type && (
                    <Badge variant="blue" size="sm">{HUNGER_TYPE_LABELS[entry.hunger_type] ?? entry.hunger_type}</Badge>
                  )}
                </div>
                {entry.xp_earned > 0 && (
                  <Badge variant="orange" size="sm">+{entry.xp_earned} XP</Badge>
                )}
              </div>
              <p className="text-gray-700 text-sm mb-3">{entry.food_description}</p>
              <div className="flex gap-6 text-xs text-gray-400">
                <span>Fome antes: <strong className="text-gray-600">{entry.hunger_before}/10</strong></span>
                <span>Saciedade depois: <strong className="text-gray-600">{entry.satiety_after}/10</strong></span>
                {entry.emotional_state && (
                  <span>Estado: <strong className="text-gray-600">{entry.emotional_state}</strong></span>
                )}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
