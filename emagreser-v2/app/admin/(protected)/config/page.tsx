'use client'

import { useEffect, useState } from 'react'
import { createClient } from '@/lib/supabase'
import PageHeader from '@/components/ui/PageHeader'
import Button from '@/components/ui/Button'
import Spinner from '@/components/ui/Spinner'

interface ConfigItem {
  key: string
  value: string
  description: string | null
}

interface WppConfig {
  whatsapp_link: string
}

export default function AdminConfigPage() {
  const supabase = createClient()
  const [configs, setConfigs] = useState<ConfigItem[]>([])
  const [wpp, setWpp] = useState<WppConfig | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState<string | null>(null)
  const [saveMsg, setSaveMsg] = useState('')

  useEffect(() => {
    Promise.all([
      supabase.from('site_config').select('key, value, description').order('key'),
      supabase.from('whatsapp_config').select('whatsapp_link').single(),
    ]).then(([{ data: cfg }, { data: wppData }]) => {
      setConfigs(cfg ?? [])
      setWpp(wppData)
      setLoading(false)
    })
  }, [])

  async function saveConfig(key: string, value: string) {
    setSaving(key)
    await supabase.from('site_config').upsert({ key, value }, { onConflict: 'key' })
    setSaveMsg(`${key} salvo!`)
    setSaving(null)
    setTimeout(() => setSaveMsg(''), 2000)
  }

  async function saveWpp() {
    if (!wpp) return
    setSaving('whatsapp')
    await supabase.from('whatsapp_config').update({ whatsapp_link: wpp.whatsapp_link }).neq('id', '00000000-0000-0000-0000-000000000000')
    setSaveMsg('WhatsApp salvo!')
    setSaving(null)
    setTimeout(() => setSaveMsg(''), 2000)
  }

  if (loading) return <div className="flex justify-center py-20"><Spinner size="lg" /></div>

  return (
    <div className="space-y-6">
      <PageHeader title="Configurações" subtitle="Ajuste as configurações do site" />

      {saveMsg && (
        <div className="bg-green-50 border border-green-100 text-green-800 text-sm px-4 py-3 rounded-xl">{saveMsg}</div>
      )}

      {/* WhatsApp */}
      {wpp !== null && (
        <div className="bg-white rounded-2xl border border-gray-100 p-6">
          <h3 className="font-bold text-[#1a1a2e] mb-4">📱 Link do WhatsApp</h3>
          <div className="flex gap-3">
            <input
              type="url"
              value={wpp.whatsapp_link}
              onChange={e => setWpp({ whatsapp_link: e.target.value })}
              placeholder="https://chat.whatsapp.com/..."
              className="flex-1 px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
            />
            <Button loading={saving === 'whatsapp'} onClick={saveWpp} size="sm">Salvar</Button>
          </div>
        </div>
      )}

      {/* site_config key/value */}
      {configs.length > 0 && (
        <div className="bg-white rounded-2xl border border-gray-100 p-6">
          <h3 className="font-bold text-[#1a1a2e] mb-4">⚙️ Configurações gerais</h3>
          <div className="space-y-4">
            {configs.map(cfg => (
              <EditableConfigRow
                key={cfg.key}
                cfg={cfg}
                saving={saving === cfg.key}
                onSave={saveConfig}
              />
            ))}
          </div>
        </div>
      )}
    </div>
  )
}

function EditableConfigRow({ cfg, saving, onSave }: { cfg: ConfigItem; saving: boolean; onSave: (key: string, value: string) => void }) {
  const [value, setValue] = useState(cfg.value)

  return (
    <div className="flex items-start gap-4">
      <div className="w-48 shrink-0">
        <p className="text-sm font-medium text-gray-700">{cfg.key}</p>
        {cfg.description && <p className="text-xs text-gray-400">{cfg.description}</p>}
      </div>
      <input
        type="text"
        value={value}
        onChange={e => setValue(e.target.value)}
        className="flex-1 px-4 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
      />
      <Button size="sm" loading={saving} onClick={() => onSave(cfg.key, value)} variant="ghost">Salvar</Button>
    </div>
  )
}
