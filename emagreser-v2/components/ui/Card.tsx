interface Props {
  children: React.ReactNode
  className?: string
  padding?: 'none' | 'sm' | 'md' | 'lg'
}

const paddings = { none: '', sm: 'p-3', md: 'p-4', lg: 'p-6' }

export default function Card({ children, className = '', padding = 'md' }: Props) {
  return (
    <div className={`bg-white rounded-2xl border border-gray-100 ${paddings[padding]} ${className}`}>
      {children}
    </div>
  )
}
