'use client'

import { useEffect, useState } from 'react'
import { createClient } from '@/lib/supabase'
import PageHeader from '@/components/ui/PageHeader'
import Button from '@/components/ui/Button'
import Badge from '@/components/ui/Badge'
import EmptyState from '@/components/ui/EmptyState'
import Spinner from '@/components/ui/Spinner'

interface Mission {
  id: string
  title: string
  description: string | null
  xp_reward: number
  week_number: number
  type: string | null
  active: boolean
}

const TYPE_LABELS: Record<string, string> = {
  video: 'Vídeo',
  diary: 'Diário',
  reflection: 'Reflexão',
  challenge: 'Desafio',
  bonus: 'Bônus',
}

export default function AdminMissoesPage() {
  const supabase = createClient()
  const [missions, setMissions] = useState<Mission[]>([])
  const [loading, setLoading] = useState(true)
  const [selectedWeek, setSelectedWeek] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [saving, setSaving] = useState(false)
  const [editId, setEditId] = useState<string | null>(null)
  const [form, setForm] = useState({ title: '', description: '', xp_reward: 50, type: 'video', active: true })

  async function loadMissions() {
    setLoading(true)
    const { data } = await supabase
      .from('missions')
      .select('*')
      .eq('week_number', selectedWeek)
      .order('created_at')
    setMissions(data ?? [])
    setLoading(false)
  }

  useEffect(() => { loadMissions() }, [selectedWeek])

  function startEdit(m: Mission) {
    setEditId(m.id)
    setForm({ title: m.title, description: m.description ?? '', xp_reward: m.xp_reward, type: m.type ?? 'video', active: m.active })
    setShowForm(true)
  }

  function resetForm() {
    setEditId(null)
    setForm({ title: '', description: '', xp_reward: 50, type: 'video', active: true })
    setShowForm(false)
  }

  async function handleSave() {
    if (!form.title.trim()) return
    setSaving(true)

    if (editId) {
      await supabase.from('missions').update({ ...form, week_number: selectedWeek }).eq('id', editId)
    } else {
      await supabase.from('missions').insert({ ...form, week_number: selectedWeek })
    }

    resetForm()
    await loadMissions()
    setSaving(false)
  }

  async function toggleActive(id: string, current: boolean) {
    await supabase.from('missions').update({ active: !current }).eq('id', id)
    await loadMissions()
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Missões"
        subtitle="Gerencie as missões por semana"
        action={
          <Button size="sm" onClick={() => { resetForm(); setShowForm(true) }}>+ Nova missão</Button>
        }
      />

      {/* Seletor de semana */}
      <div className="flex gap-2 flex-wrap">
        {Array.from({ length: 12 }, (_, i) => i + 1).map(w => (
          <button
            key={w}
            onClick={() => setSelectedWeek(w)}
            className={`px-3 py-1.5 rounded-xl text-sm font-medium transition-colors ${
              selectedWeek === w ? 'bg-green-600 text-white' : 'bg-white border border-gray-200 text-gray-600 hover:border-green-400'
            }`}
          >
            Sem. {w}
          </button>
        ))}
      </div>

      {/* Form */}
      {showForm && (
        <div className="bg-white rounded-2xl border border-green-100 p-6 space-y-4">
          <h3 className="font-bold text-[#1a1a2e]">{editId ? 'Editar missão' : 'Nova missão'} — Semana {selectedWeek}</h3>
          <input
            type="text" placeholder="Título" value={form.title}
            onChange={e => setForm(f => ({ ...f, title: e.target.value }))}
            className="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
          />
          <textarea
            placeholder="Descrição" rows={3} value={form.description}
            onChange={e => setForm(f => ({ ...f, description: e.target.value }))}
            className="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-green-500 resize-none"
          />
          <div className="flex gap-4">
            <div className="flex-1">
              <label className="block text-sm text-gray-600 mb-1">XP</label>
              <input
                type="number" value={form.xp_reward} min={0}
                onChange={e => setForm(f => ({ ...f, xp_reward: Number(e.target.value) }))}
                className="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
              />
            </div>
            <div className="flex-1">
              <label className="block text-sm text-gray-600 mb-1">Tipo</label>
              <select
                value={form.type}
                onChange={e => setForm(f => ({ ...f, type: e.target.value }))}
                className="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none"
              >
                {Object.entries(TYPE_LABELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </select>
            </div>
          </div>
          <div className="flex gap-3">
            <Button loading={saving} onClick={handleSave}>{editId ? 'Salvar' : 'Criar'}</Button>
            <Button variant="ghost" onClick={resetForm}>Cancelar</Button>
          </div>
        </div>
      )}

      {loading ? (
        <div className="flex justify-center py-12"><Spinner size="lg" /></div>
      ) : missions.length === 0 ? (
        <EmptyState icon="🎯" title="Nenhuma missão nesta semana" description="Crie a primeira missão da semana." />
      ) : (
        <div className="space-y-3">
          {missions.map(m => (
            <div key={m.id} className="bg-white rounded-2xl border border-gray-100 p-5 flex items-start gap-4">
              <div className="flex-1">
                <div className="flex items-center gap-2 mb-1">
                  <span className="font-semibold text-[#1a1a2e] text-sm">{m.title}</span>
                  <Badge variant="orange" size="sm">+{m.xp_reward} XP</Badge>
                  {m.type && <Badge variant="blue" size="sm">{TYPE_LABELS[m.type] ?? m.type}</Badge>}
                  {!m.active && <Badge variant="gray" size="sm">Inativa</Badge>}
                </div>
                {m.description && <p className="text-xs text-gray-500">{m.description}</p>}
              </div>
              <div className="flex gap-2 shrink-0">
                <button onClick={() => startEdit(m)} className="text-xs text-gray-400 hover:text-gray-700 px-2 py-1 rounded-lg hover:bg-gray-100">✏️</button>
                <button onClick={() => toggleActive(m.id, m.active)} className="text-xs text-gray-400 hover:text-gray-700 px-2 py-1 rounded-lg hover:bg-gray-100">
                  {m.active ? 'Desativar' : 'Ativar'}
                </button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
