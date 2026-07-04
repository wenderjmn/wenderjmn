interface Props {
  children: React.ReactNode
  variant?: 'green' | 'blue' | 'orange' | 'red' | 'gray' | 'purple'
  size?: 'sm' | 'md'
}

const variants = {
  green: 'bg-green-50 text-green-700',
  blue: 'bg-blue-50 text-blue-700',
  orange: 'bg-orange-50 text-orange-700',
  red: 'bg-red-50 text-red-600',
  gray: 'bg-gray-100 text-gray-600',
  purple: 'bg-purple-50 text-purple-700',
}

const sizes = {
  sm: 'text-xs px-2 py-0.5 rounded-full',
  md: 'text-sm px-3 py-1 rounded-full',
}

export default function Badge({ children, variant = 'gray', size = 'sm' }: Props) {
  return (
    <span className={`inline-flex items-center font-medium ${variants[variant]} ${sizes[size]}`}>
      {children}
    </span>
  )
}
