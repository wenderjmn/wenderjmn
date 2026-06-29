'use client'

import { createClient } from '@/lib/supabase'
import { useRouter } from 'next/navigation'
import type { UserProfile } from '@/lib/types'

interface Props {
  profile: UserProfile | null
  userId: string
}

export default function DashboardNav({ profile, userId }: Props) {
  const supabase = createClient()
  const router = useRouter()

  async function handleLogout() {
    await supabase.auth.signOut()
    router.push('/')
    router.refresh()
  }

  return (
    <nav className="bg-white border-b border-gray-100 sticky top-0 z-10">
      <div className="max-w-4xl mx-auto px-4 h-14 flex items-center justify-between">
        <a href="/dashboard" className="flex items-center gap-2">
          <span className="text-xl">🌱</span>
          <span className="font-bold text-gray-900 text-sm sm:text-base">EmagreSer V2</span>
        </a>

        <div className="flex items-center gap-3">
          <div className="hidden sm:flex items-center gap-1 text-sm text-gray-500">
            <span className="text-orange-500">🔥</span>
            <span className="font-medium">{profile?.current_streak ?? 0} dias</span>
          </div>
          <div className="hidden sm:flex items-center gap-1 text-sm text-gray-500">
            <span>⭐</span>
            <span className="font-medium">{profile?.total_xp ?? 0} XP</span>
          </div>
          <button
            onClick={handleLogout}
            className="text-sm text-gray-500 hover:text-gray-700 font-medium px-3 py-1.5 rounded-lg hover:bg-gray-100 transition-colors"
          >
            Sair
          </button>
        </div>
      </div>
    </nav>
  )
}
