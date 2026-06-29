'use client'

import { useState } from 'react'
import { createClient } from '@/lib/supabase'
import { useRouter } from 'next/navigation'
import type { MissionWithStatus } from '@/lib/types'

const TYPE_ICONS: Record<string, string> = {
  check: '✅',
  diary: '📔',
  reflection: '💭',
  challenge: '⚡',
  theater: '🎭',
}

const TYPE_LABELS: Record<string, string> = {
  check: 'Tarefa',
  diary: 'Diário',
  reflection: 'Reflexão',
  challenge: 'Desafio',
  theater: 'Teatro',
}

const MENTOR_COLORS: Record<string, string> = {
  ira: 'text-purple-600 bg-purple-50',
  dany: 'text-pink-600 bg-pink-50',
  both: 'text-blue-600 bg-blue-50',
}

interface Props {
  mission: MissionWithStatus
  userId: string
}

export default function MissionCard({ mission, userId }: Props) {
  const supabase = createClient()
  const router = useRouter()

  const existingRecord = mission.user_missions?.[0]
  const isCompleted = existingRecord?.status === 'completed'
  const [loading, setLoading] = useState(false)
  const [done, setDone] = useState(isCompleted)

  async function toggleComplete() {
    if (loading) return
    setLoading(true)

    if (done) {
      // Undo completion
      if (existingRecord) {
        await supabase
          .from('user_missions')
          .update({ status: 'pending', xp_earned: 0 })
          .eq('id', existingRecord.id)

        await supabase
          .from('users_profile')
          .update({ total_xp: supabase.rpc as never })
          .eq('id', userId)

        // Decrease XP
        await supabase.rpc('decrement_xp' as never, {
          user_id: userId,
          amount: mission.xp_reward,
        } as never)
      }
      setDone(false)
    } else {
      if (existingRecord) {
        await supabase
          .from('user_missions')
          .update({
            status: 'completed',
            completed_at: new Date().toISOString(),
            xp_earned: mission.xp_reward,
          })
          .eq('id', existingRecord.id)
      } else {
        await supabase.from('user_missions').insert({
          user_id: userId,
          mission_id: mission.id,
          status: 'completed',
          completed_at: new Date().toISOString(),
          xp_earned: mission.xp_reward,
        })
      }

      // Add XP to profile
      const { data: profile } = await supabase
        .from('users_profile')
        .select('total_xp')
        .eq('id', userId)
        .single()

      if (profile) {
        await supabase
          .from('users_profile')
          .update({ total_xp: (profile.total_xp ?? 0) + mission.xp_reward })
          .eq('id', userId)
      }

      setDone(true)
    }

    setLoading(false)
    router.refresh()
  }

  const icon = TYPE_ICONS[mission.type] ?? '📌'
  const label = TYPE_LABELS[mission.type] ?? mission.type
  const mentorColor = MENTOR_COLORS[mission.mentor] ?? MENTOR_COLORS.both

  return (
    <div
      className={`
        bg-white rounded-2xl border transition-all duration-200
        ${done ? 'border-green-200 bg-green-50/30' : 'border-gray-100 hover:border-gray-200'}
      `}
    >
      <div className="p-4 flex items-start gap-4">
        {/* Checkbox */}
        <button
          onClick={toggleComplete}
          disabled={loading}
          className={`
            flex-shrink-0 w-7 h-7 rounded-full border-2 flex items-center justify-center transition-all mt-0.5
            ${done
              ? 'bg-green-500 border-green-500 text-white'
              : 'border-gray-300 hover:border-green-400'
            }
            ${loading ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'}
          `}
        >
          {done && <span className="text-xs">✓</span>}
        </button>

        {/* Content */}
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap mb-1">
            <span className="text-sm">{icon}</span>
            <span className={`text-xs font-medium px-2 py-0.5 rounded-full ${mentorColor}`}>
              {label}
            </span>
            {mission.mentor !== 'both' && (
              <span className="text-xs text-gray-400">
                {mission.mentor === 'ira' ? 'Ira' : 'Dany'}
              </span>
            )}
          </div>

          <p className={`font-medium text-sm ${done ? 'line-through text-gray-400' : 'text-gray-900'}`}>
            {mission.title}
          </p>

          {mission.description && (
            <p className="text-xs text-gray-500 mt-1 line-clamp-2">{mission.description}</p>
          )}
        </div>

        {/* XP */}
        <div className={`flex-shrink-0 text-right ${done ? 'text-green-600' : 'text-gray-400'}`}>
          <p className="text-xs font-bold">+{mission.xp_reward}</p>
          <p className="text-xs">XP</p>
        </div>
      </div>
    </div>
  )
}
