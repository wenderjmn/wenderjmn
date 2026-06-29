interface Props {
  title: string
  subtitle?: string
  action?: React.ReactNode
  backHref?: string
}

export default function PageHeader({ title, subtitle, action, backHref }: Props) {
  return (
    <div className="flex items-start justify-between gap-4">
      <div>
        {backHref && (
          <a href={backHref} className="text-sm text-gray-400 hover:text-gray-600 mb-1 inline-block">
            ← Voltar
          </a>
        )}
        <h1 className="text-2xl font-bold text-gray-900">{title}</h1>
        {subtitle && <p className="text-gray-500 mt-1 text-sm">{subtitle}</p>}
      </div>
      {action && <div className="flex-shrink-0">{action}</div>}
    </div>
  )
}
