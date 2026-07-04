import { redirect, notFound } from 'next/navigation'
import { createServerSupabase } from '@/lib/supabase-server'
import MissionCard from '@/components/MissionCard'
import type { MissionWithStatus, Week } from '@/lib/types'

export const revalidate = 0

export default async function WeekPage({
  params,
}: {
  params: Promise<{ week: string }>
}) {
  const { week: weekParam } = await params
  const weekNumber = parseInt(weekParam)

  if (isNaN(weekNumber) || weekNumber < 1 || weekNumber > 12) {
    notFound()
  }

  const supabase = await createServerSupabase()
  const { data: { user } } = await supabase.auth.getUser()
  if (!user) redirect('/')

  const { data: profile } = await supabase
    .from('users_profile')
    .select('current_week')
    .eq('id', user.id)
    .single()

  const currentWeek = profile?.current_week ?? 1

  // Block access to future weeks
  if (weekNumber > currentWeek) {
    redirect('/dashboard')
  }

  const [{ data: weekData }, { data: missionsRaw }] = await Promise.all([
    supabase.from('weeks').select('*').eq('number', weekNumber).single(),
    supabase.from('missions').select('*').eq('week_number', weekNumber).order('order_index'),
  ])

  const week = weekData as Week | null
  const missionIds = (missionsRaw ?? []).map(m => m.id)

  const { data: userMissions } = missionIds.length > 0
    ? await supabase
        .from('user_missions')
        .select('*')
        .eq('user_id', user.id)
        .in('mission_id', missionIds)
    : { data: [] }

  const missions: MissionWithStatus[] = (missionsRaw ?? []).map(m => ({
    ...m,
    user_missions: (userMissions ?? []).filter(um => um.mission_id === m.id),
  }))

  const completedCount = missions.filter(
    m => m.user_missions.some(um => um.status === 'completed')
  ).length

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <a href="/dashboard" className="text-gray-400 hover:text-gray-600 transition-colors">
          ← Voltar
        </a>
      </div>

      <div>
        <div className="flex items-center gap-2 mb-1">
          <span className="text-xs font-semibold text-green-600 bg-green-50 px-2 py-1 rounded-full">
            Semana {weekNumber}
          </span>
          <span className="text-xs text-gray-400">
            {completedCount}/{missions.length} missões
          </span>
        </div>
        <h1 className="text-2xl font-bold text-gray-900">{week?.title}</h1>
        {week?.theme && (
          <p className="text-gray-500 mt-1">{week.theme}</p>
        )}
      </div>

      {/* Progress bar */}
      <div className="bg-white rounded-2xl p-4 border border-gray-100">
        <div className="flex justify-between text-sm mb-2">
          <span className="text-gray-600 font-medium">Progresso</span>
          <span className="text-green-600 font-semibold">
            {missions.length > 0 ? Math.round((completedCount / missions.length) * 100) : 0}%
          </span>
        </div>
        <div className="h-2 bg-gray-100 rounded-full overflow-hidden">
          <div
            className="h-full bg-green-500 rounded-full transition-all"
            style={{ width: missions.length > 0 ? `${(completedCount / missions.length) * 100}%` : '0%' }}
          />
        </div>
      </div>

      <div className="space-y-3">
        {missions.map(mission => (
          <MissionCard key={mission.id} mission={mission} userId={user.id} />
        ))}
      </div>
    </div>
  )
}
