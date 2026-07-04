interface Column<T> {
  key: string
  label: string
  render?: (row: T) => React.ReactNode
}

interface Props<T extends Record<string, unknown>> {
  columns: Column<T>[]
  rows: T[]
  loading?: boolean
  keyField?: string
}

export default function Table<T extends Record<string, unknown>>({
  columns, rows, loading, keyField = 'id'
}: Props<T>) {
  if (loading) {
    return (
      <div className="bg-white rounded-2xl border border-gray-100 overflow-hidden">
        <div className="p-8 text-center text-gray-400 text-sm">Carregando...</div>
      </div>
    )
  }

  return (
    <div className="bg-white rounded-2xl border border-gray-100 overflow-hidden">
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-gray-100 bg-gray-50">
              {columns.map(col => (
                <th key={col.key} className="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">
                  {col.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row, i) => (
              <tr key={String(row[keyField] ?? i)} className="border-b border-gray-50 hover:bg-gray-50/50">
                {columns.map(col => (
                  <td key={col.key} className="px-4 py-3 text-gray-700">
                    {col.render ? col.render(row) : String(row[col.key] ?? '—')}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
        {rows.length === 0 && (
          <div className="p-8 text-center text-gray-400 text-sm">Nenhum registro encontrado.</div>
        )}
      </div>
    </div>
  )
}
