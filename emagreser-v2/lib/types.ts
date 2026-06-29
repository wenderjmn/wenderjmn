export interface UserProfile {
  id: string
  name: string | null
  full_name: string | null
  email: string | null
  avatar_url: string | null
  behavioral_profile: 'A' | 'B' | 'C' | 'D' | null
  sabotador: 'A' | 'B' | 'C' | 'D' | null
  role: 'participant' | 'mentor' | 'admin'
  total_xp: number
  current_level: number
  current_week: number
  current_streak: number
  longest_streak: number
  last_active_date: string | null
  onboarding_done: boolean
  plan: 'base' | 'premium'
  joined_at: string
}

export interface Week {
  id: string
  number: number
  title: string
  theme: string | null
  mentor_ira_focus: string | null
  mentor_dany_focus: string | null
  unlock_day: number
  xp_bonus_100pct: number
}

export interface Mission {
  id: string
  week_id: string | null
  week_number: number | null
  title: string
  description: string | null
  type: 'check' | 'diary' | 'reflection' | 'challenge' | 'theater'
  xp_reward: number
  order_index: number
  required: boolean
  mentor: string
}

export interface UserMission {
  id: string
  user_id: string
  mission_id: string
  status: 'pending' | 'completed' | 'skipped'
  completed_at: string | null
  content: string | null
  xp_earned: number
}

export interface MissionWithStatus extends Mission {
  user_missions: UserMission[]
}

export interface Badge {
  id: string
  name: string
  description: string | null
  emoji: string | null
  category: string | null
  condition_type: string | null
  condition_value: number | null
  xp_reward: number
}
