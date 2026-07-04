'use client'

import { useState } from 'react'
import { useRouter } from 'next/navigation'
import { createClient } from '@/lib/supabase'
import Button from '@/components/ui/Button'
import PageHeader from '@/components/ui/PageHeader'
import Select from '@/components/ui/Select'
import Textarea from '@/components/ui/Textarea'

const MEAL_OPTIONS = [
  { value: 'cafe', label: 'Café da manhã' },
  { value: 'lanche_manha', label: 'Lanche da manhã' },
  { value: 'almoco', label: 'Almoço' },
  { value: 'lanche_tarde', label: 'Lanche da tarde' },
  { value: 'jantar', label: 'Jantar' },
  { value: 'ceia', label: 'Ceia' },
]

const HUNGER_TYPE_OPTIONS = [
  { value: 'fisico', label: 'Fome física' },
  { value: 'emocional', label: 'Fome emocional' },
  { value: 'ansiedade', label: 'Ansiedade' },
  { value: 'tedio', label: 'Tédio' },
  { value: 'habito', label: 'Hábito' },
]

export default function NovaDiarioPage() {
  const router = useRouter()
  const supabase = createClient()

  const [form, setForm] = useState({
    meal_time: '',
    food_description: '',
    hunger_before: 5,
    satiety_after: 5,
    hunger_type: '',
    emotional_state: '',
  })
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setError('')

    if (!form.meal_time) { setError('Selecione o momento da refeição.'); return }
    if (!form.food_description.trim()) { setError('Descreva o que você comeu.'); return }

    setLoading(true)
    const { data: { user } } = await supabase.auth.getUser()
    if (!user) { router.push('/login'); return }

    const { error: insertError } = await supabase.from('diary_entries').insert({
      user_id: user.id,
      meal_time: form.meal_time,
      food_description: form.food_description,
      hunger_before: form.hunger_before,
      satiety_after: form.satiety_after,
      hunger_type: form.hunger_type || null,
      emotional_state: form.emotional_state || null,
      xp_earned: 10,
    })

    if (insertError) {
      setError('Erro ao salvar. Tente novamente.')
      setLoading(false)
      return
    }

    await supabase.from('users_profile')
      .select('total_xp')
      .eq('id', user.id)
      .single()
      .then(async ({ data: profile }) => {
        if (profile) {
          await supabase.from('users_profile')
            .update({ total_xp: (profile.total_xp ?? 0) + 10 })
            .eq('id', user.id)
        }
      })

    router.push('/dashboard/diario')
    router.refresh()
  }

  return (
    <div className="max-w-lg mx-auto px-4 py-8">
      <PageHeader
        title="Nova Entrada"
        subtitle="Registre sua refeição com atenção plena"
        backHref="/dashboard/diario"
      />

      <div className="bg-white rounded-2xl border border-gray-100 p-6">
        <form onSubmit={handleSubmit} className="space-y-5">
          <Select
            label="Momento da refeição"
            options={MEAL_OPTIONS}
            value={form.meal_time}
            onChange={e => setForm(f => ({ ...f, meal_time: (e as React.ChangeEvent<HTMLSelectElement>).target.value }))}
            placeholder="Selecione..."
          />

          <Textarea
            label="O que você comeu?"
            placeholder="Descreva os alimentos, quantidades aproximadas..."
            rows={3}
            value={form.food_description}
            onChange={e => setForm(f => ({ ...f, food_description: e.target.value }))}
          />

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Fome antes de comer: <span className="text-green-600 font-bold">{form.hunger_before}/10</span>
            </label>
            <input
              type="range" min={0} max={10} step={1}
              value={form.hunger_before}
              onChange={e => setForm(f => ({ ...f, hunger_before: Number(e.target.value) }))}
              className="w-full accent-green-500"
            />
            <div className="flex justify-between text-xs text-gray-400 mt-1">
              <span>Sem fome</span><span>Muita fome</span>
            </div>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Saciedade depois: <span className="text-green-600 font-bold">{form.satiety_after}/10</span>
            </label>
            <input
              type="range" min={0} max={10} step={1}
              value={form.satiety_after}
              onChange={e => setForm(f => ({ ...f, satiety_after: Number(e.target.value) }))}
              className="w-full accent-green-500"
            />
            <div className="flex justify-between text-xs text-gray-400 mt-1">
              <span>Ainda com fome</span><span>Muito satisfeita</span>
            </div>
          </div>

          <Select
            label="Tipo de fome (opcional)"
            options={HUNGER_TYPE_OPTIONS}
            value={form.hunger_type}
            onChange={e => setForm(f => ({ ...f, hunger_type: (e as React.ChangeEvent<HTMLSelectElement>).target.value }))}
            placeholder="Como estava sua fome?"
          />

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Estado emocional (opcional)</label>
            <input
              type="text"
              placeholder="Ex: ansiosa, tranquila, estressada..."
              value={form.emotional_state}
              onChange={e => setForm(f => ({ ...f, emotional_state: e.target.value }))}
              className="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
            />
          </div>

          {error && <p className="text-sm text-red-500">{error}</p>}

          <Button type="submit" loading={loading} size="lg" className="w-full">
            Salvar entrada (+10 XP)
          </Button>
        </form>
      </div>
    </div>
  )
}
