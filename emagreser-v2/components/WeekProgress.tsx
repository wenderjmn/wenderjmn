interface Props {
  weekNumber: number
  weekTitle: string
  weekTheme: string
  completed: number
  total: number
  xpEarned: number
}

export default function WeekProgress({ weekNumber, weekTitle, weekTheme, completed, total, xpEarned }: Props) {
  const pct = total > 0 ? Math.round((completed / total) * 100) : 0

  return (
    <div className="bg-gradient-to-br from-green-500 to-emerald-600 rounded-2xl p-5 text-white">
      <div className="flex justify-between items-start mb-3">
        <div>
          <p className="text-green-100 text-xs font-medium mb-1">SEMANA {weekNumber}</p>
          <h3 className="font-bold text-lg leading-tight">{weekTitle}</h3>
          {weekTheme && <p className="text-green-100 text-sm mt-1 line-clamp-1">{weekTheme}</p>}
        </div>
        <div className="text-right">
          <p className="text-2xl font-bold">{pct}%</p>
          <p className="text-green-100 text-xs">{completed}/{total} missões</p>
        </div>
      </div>
      <div className="h-2 bg-green-400/40 rounded-full overflow-hidden">
        <div
          className="h-full bg-white rounded-full transition-all"
          style={{ width: `${pct}%` }}
        />
      </div>
      {xpEarned > 0 && (
        <p className="text-green-100 text-xs mt-2">+{xpEarned} XP conquistados esta semana</p>
      )}
    </div>
  )
}
