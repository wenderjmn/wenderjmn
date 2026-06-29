'use client'

import { useEffect, useState } from 'react'
import { createClient } from '@/lib/supabase'
import PageHeader from '@/components/ui/PageHeader'
import Button from '@/components/ui/Button'
import Badge from '@/components/ui/Badge'
import Spinner from '@/components/ui/Spinner'

interface Profile {
  id: string
  full_name: string | null
  email: string | null
  avatar_url: string | null
  total_xp: number
  current_level: number
  streak_current: number
  streak_best: number
}

interface UserBadge {
  badge: {
    id: string
    name: string
    description: string | null
    icon: string | null
  }
  earned_at: string
}

interface WeekProgress {
  week_number: number
  title: string
  completed: number
  total: number
}

export default function PerfilPage() {
  const supabase = createClient()
  const [profile, setProfile] = useState<Profile | null>(null)
  const [badges, setBadges] = useState<UserBadge[]>([])
  const [weekProgress, setWeekProgress] = useState<WeekProgress[]>([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [editName, setEditName] = useState('')
  const [editMode, setEditMode] = useState(false)
  const [saveMsg, setSaveMsg] = useState('')

  useEffect(() => {
    async function load() {
      const { data: { user } } = await supabase.auth.getUser()
      if (!user) return

      const [{ data: prof }, { data: userBadges }, { data: weeks }] = await Promise.all([
        supabase.from('users_profile').select('*').eq('id', user.id).single(),
        supabase.from('user_badges').select('earned_at, badge:badges(id, name, description, icon)').eq('user_id', user.id).order('earned_at', { ascending: false }),
        supabase.from('weeks').select('week_number, title').order('week_number'),
      ])

      setProfile(prof)
      setEditName(prof?.full_name ?? '')
      setBadges((userBadges as unknown as UserBadge[]) ?? [])

      if (weeks && prof) {
        const progressData: WeekProgress[] = []
        for (const week of weeks.slice(0, 12)) {
          const { count: totalMissions } = await supabase
            .from('missions')
            .select('id', { count: 'exact', head: true })
            .eq('week_number', week.week_number)
            .eq('active', true)

          const { count: completedMissions } = await supabase
            .from('user_missions')
            .select('id', { count: 'exact', head: true })
            .eq('user_id', user.id)
            .eq('completed', true)

          progressData.push({
            week_number: week.week_number,
            title: week.title,
            completed: completedMissions ?? 0,
            total: totalMissions ?? 0,
          })
        }
        setWeekProgress(progressData)
      }

      setLoading(false)
    }
    load()
  }, [])

  async function handleSave() {
    if (!profile) return
    setSaving(true)
    await supabase.from('users_profile').update({ full_name: editName }).eq('id', profile.id)
    setProfile(p => p ? { ...p, full_name: editName } : p)
    setEditMode(false)
    setSaveMsg('Perfil atualizado!')
    setSaving(false)
    setTimeout(() => setSaveMsg(''), 3000)
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-[50vh]">
        <Spinner size="lg" />
      </div>
    )
  }

  if (!profile) return null

  const xpInLevel = profile.total_xp % (profile.current_level * 500)
  const xpNeeded = profile.current_level * 500
  const xpProgress = Math.round((xpInLevel / xpNeeded) * 100)

  return (
    <div className="max-w-2xl mx-auto px-4 py-8 space-y-6">
      <PageHeader title="Meu Perfil" subtitle="Sua jornada de transformação" />

      {/* Avatar + Nome */}
      <div className="bg-white rounded-2xl border border-gray-100 p-6">
        <div className="flex items-center gap-5 mb-4">
          <div className="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center text-3xl">
            {profile.avatar_url ? (
              <img src={profile.avatar_url} alt={profile.full_name ?? ''} className="w-16 h-16 rounded-full object-cover" />
            ) : '🌱'}
          </div>
          <div className="flex-1">
            {editMode ? (
              <div className="flex items-center gap-2">
                <input
                  type="text"
                  value={editName}
                  onChange={e => setEditName(e.target.value)}
                  className="flex-1 px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                />
                <Button size="sm" loading={saving} onClick={handleSave}>Salvar</Button>
                <Button size="sm" variant="ghost" onClick={() => setEditMode(false)}>Cancelar</Button>
              </div>
            ) : (
              <div className="flex items-center gap-2">
                <h2 className="text-lg font-bold text-[#1a1a2e]">{profile.full_name ?? 'Sem nome'}</h2>
                <button onClick={() => setEditMode(true)} className="text-xs text-gray-400 hover:text-gray-600">✏️</button>
              </div>
            )}
            <p className="text-sm text-gray-500">{profile.email}</p>
          </div>
        </div>
        {saveMsg && <p className="text-sm text-green-600">{saveMsg}</p>}
      </div>

      {/* Stats */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
        {[
          { label: 'XP Total', value: profile.total_xp.toLocaleString('pt-BR'), icon: '⚡' },
          { label: 'Nível', value: profile.current_level, icon: '🏅' },
          { label: 'Streak atual', value: `${profile.streak_current}d`, icon: '🔥' },
          { label: 'Recorde', value: `${profile.streak_best}d`, icon: '🏆' },
        ].map(stat => (
          <div key={stat.label} className="bg-white rounded-2xl border border-gray-100 p-4 text-center">
            <div className="text-2xl mb-1">{stat.icon}</div>
            <div className="text-xl font-bold text-[#1a1a2e]">{stat.value}</div>
            <div className="text-xs text-gray-400">{stat.label}</div>
          </div>
        ))}
      </div>

      {/* XP Bar */}
      <div className="bg-white rounded-2xl border border-gray-100 p-5">
        <div className="flex justify-between text-sm mb-2">
          <span className="text-gray-500">Progresso nível {profile.current_level}</span>
          <span className="text-gray-700 font-medium">{xpInLevel} / {xpNeeded} XP</span>
        </div>
        <div className="h-2.5 bg-gray-100 rounded-full overflow-hidden">
          <div className="h-full bg-green-500 rounded-full transition-all" style={{ width: `${xpProgress}%` }} />
        </div>
      </div>

      {/* Badges */}
      {badges.length > 0 && (
        <div className="bg-white rounded-2xl border border-gray-100 p-5">
          <h3 className="font-bold text-[#1a1a2e] mb-4">Conquistas</h3>
          <div className="grid grid-cols-3 sm:grid-cols-5 gap-3">
            {badges.map((ub, i) => (
              <div key={i} className="flex flex-col items-center gap-1 p-3 bg-gray-50 rounded-xl" title={ub.badge.description ?? ''}>
                <span className="text-3xl">{ub.badge.icon ?? '🎖️'}</span>
                <span className="text-xs text-center text-gray-600 leading-tight">{ub.badge.name}</span>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Progresso por semana */}
      {weekProgress.length > 0 && (
        <div className="bg-white rounded-2xl border border-gray-100 p-5">
          <h3 className="font-bold text-[#1a1a2e] mb-4">Progresso por semana</h3>
          <div className="space-y-3">
            {weekProgress.map(wp => {
              const pct = wp.total > 0 ? Math.round((wp.completed / wp.total) * 100) : 0
              return (
                <div key={wp.week_number}>
                  <div className="flex justify-between text-sm mb-1">
                    <span className="text-gray-600">Semana {wp.week_number}</span>
                    <span className="text-gray-500 text-xs">{pct}%</span>
                  </div>
                  <div className="h-2 bg-gray-100 rounded-full overflow-hidden">
                    <div
                      className="h-full rounded-full transition-all"
                      style={{
                        width: `${pct}%`,
                        backgroundColor: pct === 100 ? '#16a34a' : pct > 0 ? '#4ade80' : '#e5e7eb',
                      }}
                    />
                  </div>
                </div>
              )
            })}
          </div>
        </div>
      )}
    </div>
  )
}
