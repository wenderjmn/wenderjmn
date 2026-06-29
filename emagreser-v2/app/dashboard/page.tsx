import { redirect } from 'next/navigation'
import { createServerSupabase } from '@/lib/supabase-server'
import MissionCard from '@/components/MissionCard'
import WeekProgress from '@/components/WeekProgress'
import XPBar from '@/components/XPBar'
import type { MissionWithStatus, Week } from '@/lib/types'

export const revalidate = 0

export default async function DashboardPage() {
  const supabase = await createServerSupabase()
  const { data: { user } } = await supabase.auth.getUser()
  if (!user) redirect('/')

  // Fetch user profile
  const { data: profile } = await supabase
    .from('users_profile')
    .select('*')
    .eq('id', user.id)
    .single()

  if (!profile) redirect('/')

  const currentWeek = profile.current_week ?? 1

  // Fetch current week info
  const { data: weekData } = await supabase
    .from('weeks')
    .select('*')
    .eq('number', currentWeek)
    .single()

  const week = weekData as Week | null

  // FIX: Fetch missions by week_number (int), then join user_missions separately
  // This avoids the UUID/integer mismatch that caused missions to not appear
  const { data: missionsRaw } = await supabase
    .from('missions')
    .select('*')
    .eq('week_number', currentWeek)
    .order('order_index')

  // Fetch user's mission statuses for current week missions
  const missionIds = (missionsRaw ?? []).map(m => m.id)

  const { data: userMissions } = missionIds.length > 0
    ? await supabase
        .from('user_missions')
        .select('*')
        .eq('user_id', user.id)
        .in('mission_id', missionIds)
    : { data: [] }

  // Merge missions with their user status
  const missions: MissionWithStatus[] = (missionsRaw ?? []).map(m => ({
    ...m,
    user_missions: (userMissions ?? []).filter(um => um.mission_id === m.id),
  }))

  const completedCount = missions.filter(
    m => m.user_missions.some(um => um.status === 'completed')
  ).length

  const totalXpThisWeek = missions.reduce((acc, m) => {
    const completed = m.user_missions.find(um => um.status === 'completed')
    return acc + (completed ? m.xp_reward : 0)
  }, 0)

  // XP level calculation
  const xpForNextLevel = profile.current_level * 500
  const xpProgress = profile.total_xp % 500

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold text-gray-900">
          Olá, {profile.name || profile.full_name || 'Participante'} 👋
        </h1>
        <p className="text-gray-500 mt-1">
          Semana {currentWeek} de 12 — {week?.title ?? 'Carregando...'}
        </p>
      </div>

      {/* XP Bar */}
      <XPBar
        totalXp={profile.total_xp}
        level={profile.current_level}
        xpProgress={xpProgress}
        xpForNextLevel={xpForNextLevel}
        streak={profile.current_streak}
      />

      {/* Week Progress */}
      <WeekProgress
        weekNumber={currentWeek}
        weekTitle={week?.title ?? ''}
        weekTheme={week?.theme ?? ''}
        completed={completedCount}
        total={missions.length}
        xpEarned={totalXpThisWeek}
      />

      {/* Missions */}
      <div>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold text-gray-900">
            Missões da Semana {currentWeek}
          </h2>
          <span className="text-sm text-gray-500">
            {completedCount}/{missions.length} concluídas
          </span>
        </div>

        {missions.length === 0 ? (
          <div className="bg-white rounded-2xl p-8 text-center border border-gray-100">
            <div className="text-4xl mb-3">🔍</div>
            <p className="text-gray-500">Nenhuma missão encontrada para a semana {currentWeek}.</p>
          </div>
        ) : (
          <div className="space-y-3">
            {missions.map(mission => (
              <MissionCard
                key={mission.id}
                mission={mission}
                userId={user.id}
              />
            ))}
          </div>
        )}
      </div>

      {/* All Weeks */}
      <div>
        <h2 className="text-lg font-semibold text-gray-900 mb-4">Programa Completo</h2>
        <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
          {Array.from({ length: 12 }, (_, i) => i + 1).map(wk => (
            <a
              key={wk}
              href={`/dashboard/semana/${wk}`}
              className={`
                flex flex-col items-center justify-center p-3 rounded-xl border text-sm font-medium transition-colors
                ${wk === currentWeek
                  ? 'bg-green-500 text-white border-green-500'
                  : wk < currentWeek
                  ? 'bg-green-50 text-green-700 border-green-200 hover:bg-green-100'
                  : 'bg-white text-gray-400 border-gray-200 cursor-default'
                }
              `}
            >
              <span className="text-xs opacity-70">Semana</span>
              <span className="text-lg font-bold">{wk}</span>
            </a>
          ))}
        </div>
      </div>
    </div>
  )
}
