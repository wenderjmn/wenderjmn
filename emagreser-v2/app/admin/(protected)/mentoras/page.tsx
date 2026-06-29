'use client'

import { useEffect, useState } from 'react'
import { createClient } from '@/lib/supabase'
import PageHeader from '@/components/ui/PageHeader'
import Button from '@/components/ui/Button'
import Spinner from '@/components/ui/Spinner'

interface Mentor {
  id: string
  name: string
  role: string | null
  bio: string | null
  photo_url: string | null
  instagram: string | null
  active: boolean
}

export default function MentorasPage() {
  const supabase = createClient()
  const [mentors, setMentors] = useState<Mentor[]>([])
  const [loading, setLoading] = useState(true)
  const [editing, setEditing] = useState<Mentor | null>(null)
  const [saving, setSaving] = useState(false)
  const [saveMsg, setSaveMsg] = useState('')

  async function load() {
    const { data } = await supabase.from('mentors').select('*').order('name')
    setMentors(data ?? [])
    setLoading(false)
  }

  useEffect(() => { load() }, [])

  async function handleSave() {
    if (!editing) return
    setSaving(true)
    await supabase.from('mentors').update({
      name: editing.name,
      role: editing.role,
      bio: editing.bio,
      photo_url: editing.photo_url,
      instagram: editing.instagram,
    }).eq('id', editing.id)
    setSaveMsg('Salvo!')
    setSaving(false)
    await load()
    setTimeout(() => setSaveMsg(''), 2000)
  }

  if (loading) return <div className="flex justify-center py-20"><Spinner size="lg" /></div>

  return (
    <div className="space-y-6">
      <PageHeader title="Mentoras" subtitle="Perfis da equipe de mentoras" />

      <div className="grid sm:grid-cols-2 gap-6">
        {mentors.map(m => (
          <div key={m.id} className="bg-white rounded-2xl border border-gray-100 p-6">
            {editing?.id === m.id ? (
              <div className="space-y-3">
                <input type="text" placeholder="Nome" value={editing.name}
                  onChange={e => setEditing(s => s ? { ...s, name: e.target.value } : s)}
                  className="w-full px-4 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                />
                <input type="text" placeholder="Cargo" value={editing.role ?? ''}
                  onChange={e => setEditing(s => s ? { ...s, role: e.target.value } : s)}
                  className="w-full px-4 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                />
                <textarea placeholder="Bio" rows={4} value={editing.bio ?? ''}
                  onChange={e => setEditing(s => s ? { ...s, bio: e.target.value } : s)}
                  className="w-full px-4 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-green-500 resize-none"
                />
                <input type="url" placeholder="URL da foto" value={editing.photo_url ?? ''}
                  onChange={e => setEditing(s => s ? { ...s, photo_url: e.target.value } : s)}
                  className="w-full px-4 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                />
                <input type="text" placeholder="@instagram" value={editing.instagram ?? ''}
                  onChange={e => setEditing(s => s ? { ...s, instagram: e.target.value } : s)}
                  className="w-full px-4 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                />
                {saveMsg && <p className="text-sm text-green-600">{saveMsg}</p>}
                <div className="flex gap-2">
                  <Button loading={saving} onClick={handleSave} size="sm">Salvar</Button>
                  <Button variant="ghost" size="sm" onClick={() => setEditing(null)}>Cancelar</Button>
                </div>
              </div>
            ) : (
              <>
                <div className="flex items-start gap-4 mb-3">
                  {m.photo_url ? (
                    <img src={m.photo_url} alt={m.name} className="w-14 h-14 rounded-full object-cover" />
                  ) : (
                    <div className="w-14 h-14 rounded-full bg-green-100 flex items-center justify-center text-2xl">👩‍🏫</div>
                  )}
                  <div>
                    <h3 className="font-bold text-[#1a1a2e]">{m.name}</h3>
                    {m.role && <p className="text-sm text-gray-500">{m.role}</p>}
                    {m.instagram && <p className="text-xs text-green-600 mt-0.5">@{m.instagram}</p>}
                  </div>
                </div>
                {m.bio && <p className="text-sm text-gray-600 mb-4 line-clamp-3">{m.bio}</p>}
                <Button size="sm" variant="ghost" onClick={() => setEditing(m)}>✏️ Editar</Button>
              </>
            )}
          </div>
        ))}
        {mentors.length === 0 && (
          <p className="text-sm text-gray-400">Nenhuma mentora encontrada na tabela <code>mentors</code>.</p>
        )}
      </div>
    </div>
  )
}
