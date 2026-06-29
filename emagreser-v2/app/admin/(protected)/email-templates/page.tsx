'use client'

import { useEffect, useState } from 'react'
import { createClient } from '@/lib/supabase'
import PageHeader from '@/components/ui/PageHeader'
import Button from '@/components/ui/Button'
import Spinner from '@/components/ui/Spinner'

interface Template {
  id: string
  name: string
  subject: string
  body: string
  trigger: string | null
}

export default function EmailTemplatesPage() {
  const supabase = createClient()
  const [templates, setTemplates] = useState<Template[]>([])
  const [loading, setLoading] = useState(true)
  const [selected, setSelected] = useState<Template | null>(null)
  const [saving, setSaving] = useState(false)
  const [saveMsg, setSaveMsg] = useState('')

  useEffect(() => {
    supabase.from('email_templates').select('*').order('name').then(({ data }) => {
      setTemplates(data ?? [])
      if (data && data.length > 0) setSelected(data[0])
      setLoading(false)
    })
  }, [])

  async function handleSave() {
    if (!selected) return
    setSaving(true)
    await supabase.from('email_templates').update({ subject: selected.subject, body: selected.body }).eq('id', selected.id)
    setSaveMsg('Salvo!')
    setSaving(false)
    setTimeout(() => setSaveMsg(''), 2000)
  }

  if (loading) return <div className="flex justify-center py-20"><Spinner size="lg" /></div>

  return (
    <div className="space-y-6">
      <PageHeader title="E-mail Templates" subtitle="Edite os templates de automação" />

      <div className="grid sm:grid-cols-3 gap-6">
        {/* Lista */}
        <div className="space-y-2">
          {templates.map(t => (
            <button
              key={t.id}
              onClick={() => setSelected(t)}
              className={`w-full text-left px-4 py-3 rounded-xl border text-sm transition-colors ${
                selected?.id === t.id ? 'border-green-400 bg-green-50 text-green-800 font-medium' : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300'
              }`}
            >
              {t.name}
              {t.trigger && <span className="block text-xs text-gray-400 mt-0.5">{t.trigger}</span>}
            </button>
          ))}
          {templates.length === 0 && (
            <p className="text-sm text-gray-400 px-4">Nenhum template encontrado na tabela <code>email_templates</code>.</p>
          )}
        </div>

        {/* Editor */}
        {selected && (
          <div className="sm:col-span-2 bg-white rounded-2xl border border-gray-100 p-6 space-y-4">
            <div className="flex items-center justify-between">
              <h3 className="font-bold text-[#1a1a2e]">{selected.name}</h3>
              {saveMsg && <span className="text-sm text-green-600">{saveMsg}</span>}
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Assunto</label>
              <input
                type="text"
                value={selected.subject}
                onChange={e => setSelected(s => s ? { ...s, subject: e.target.value } : s)}
                className="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Corpo (HTML ou texto)</label>
              <textarea
                rows={14}
                value={selected.body}
                onChange={e => setSelected(s => s ? { ...s, body: e.target.value } : s)}
                className="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-green-500 resize-y font-mono"
              />
            </div>
            <Button loading={saving} onClick={handleSave}>Salvar template</Button>
          </div>
        )}
      </div>
    </div>
  )
}
