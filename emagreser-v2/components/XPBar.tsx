interface Props {
  totalXp: number
  level: number
  xpProgress: number
  xpForNextLevel: number
  streak: number
}

export default function XPBar({ totalXp, level, xpProgress, xpForNextLevel, streak }: Props) {
  const pct = Math.min(100, Math.round((xpProgress / xpForNextLevel) * 100))

  return (
    <div className="bg-white rounded-2xl p-4 border border-gray-100 flex gap-4 items-center">
      <div className="flex-shrink-0 w-12 h-12 rounded-2xl bg-green-100 flex items-center justify-center">
        <span className="text-xl">🏆</span>
      </div>
      <div className="flex-1 min-w-0">
        <div className="flex justify-between items-center mb-1">
          <span className="text-sm font-semibold text-gray-700">Nível {level}</span>
          <span className="text-xs text-gray-400">{xpProgress} / {xpForNextLevel} XP</span>
        </div>
        <div className="h-2 bg-gray-100 rounded-full overflow-hidden">
          <div
            className="h-full bg-gradient-to-r from-green-400 to-emerald-500 rounded-full transition-all"
            style={{ width: `${pct}%` }}
          />
        </div>
        <p className="text-xs text-gray-400 mt-1">{totalXp} XP total</p>
      </div>
      <div className="flex-shrink-0 text-center">
        <div className="text-xl">🔥</div>
        <div className="text-sm font-bold text-orange-500">{streak}d</div>
      </div>
    </div>
  )
}
