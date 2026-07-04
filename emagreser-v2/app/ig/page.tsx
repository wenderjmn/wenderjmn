'use client'

import { useState } from 'react'
import { createClient } from '@/lib/supabase'
import Button from '@/components/ui/Button'

export default function IGPage() {
  const supabase = createClient()
  const [form, setForm] = useState({ name: '', phone: '' })
  const [loading, setLoading] = useState(false)
  const [done, setDone] = useState(false)
  const [whatsappLink, setWhatsappLink] = useState('')

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setLoading(true)

    const params = new URLSearchParams(window.location.search)

    const { data: wppConfig } = await supabase
      .from('whatsapp_config')
      .select('whatsapp_link')
      .single()

    await supabase.from('leads').insert({
      name: form.name,
      phone: form.phone,
      source: 'instagram',
      utm_medium: 'ig',
      source_campaign: params.get('utm_campaign'),
    })

    setWhatsappLink(wppConfig?.whatsapp_link ?? '')
    setLoading(false)
    setDone(true)
  }

  if (done) {
    return (
      <div className="min-h-screen bg-[#faf9f7] flex items-center justify-center px-4">
        <div className="text-center max-w-md">
          <div className="text-6xl mb-4">🎉</div>
          <h2 className="text-2xl font-bold text-[#1a1a2e] mb-2">
            Boa, {form.name.split(' ')[0]}!
          </h2>
          <p className="text-gray-500 mb-8">
            Entre no nosso grupo e comece sua transformação hoje.
          </p>
          {whatsappLink && (
            <a
              href={whatsappLink}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-2 bg-green-500 hover:bg-green-600 text-white font-semibold px-8 py-4 rounded-xl transition-colors text-lg"
            >
              📱 Entrar no grupo agora
            </a>
          )}
        </div>
      </div>
    )
  }

  return (
    <div className="min-h-screen bg-[#faf9f7]">
      {/* Hero */}
      <section className="max-w-2xl mx-auto px-4 pt-16 pb-10 text-center">
        <div className="inline-block bg-green-50 text-green-700 text-sm font-semibold px-4 py-1.5 rounded-full mb-6">
          🌱 EmagreSer V2
        </div>
        <h1 className="text-4xl font-bold text-[#1a1a2e] leading-tight mb-4">
          Emagreça com leveza,<br />
          <span className="text-green-600">sem culpa e sem dieta</span>
        </h1>
        <p className="text-lg text-gray-500 mb-8">
          O programa que transforma sua relação com a comida — de dentro para fora.
        </p>
      </section>

      {/* 3 resultados rápidos */}
      <section className="max-w-2xl mx-auto px-4 pb-10">
        <div className="space-y-4">
          {[
            { icon: '🧠', text: 'Identifique o sabotador interno que controla sua alimentação' },
            { icon: '⚖️', text: 'Aprenda a comer com atenção plena sem contar calorias' },
            { icon: '💚', text: 'Resultados duradouros com suporte de grupo e mentoras' },
          ].map((item, i) => (
            <div key={i} className="flex items-start gap-4 bg-white rounded-2xl border border-gray-100 p-5">
              <span className="text-3xl">{item.icon}</span>
              <p className="text-gray-700 font-medium leading-snug pt-1">{item.text}</p>
            </div>
          ))}
        </div>
      </section>

      {/* Formulário */}
      <section className="max-w-md mx-auto px-4 pb-16">
        <div className="bg-white rounded-2xl border border-gray-100 p-8">
          <h2 className="text-xl font-bold text-[#1a1a2e] text-center mb-1">
            Quero começar agora
          </h2>
          <p className="text-sm text-gray-500 text-center mb-6">
            Preencha e receba acesso ao grupo no WhatsApp
          </p>
          <form onSubmit={handleSubmit} className="space-y-4">
            <input
              type="text"
              placeholder="Seu nome"
              required
              value={form.name}
              onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
              className="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
            />
            <input
              type="tel"
              placeholder="WhatsApp (com DDD)"
              required
              value={form.phone}
              onChange={e => setForm(f => ({ ...f, phone: e.target.value }))}
              className="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
            />
            <Button type="submit" loading={loading} size="lg" className="w-full">
              Quero minha vaga →
            </Button>
            <p className="text-xs text-gray-400 text-center">Sem spam. Seus dados estão seguros.</p>
          </form>
        </div>
      </section>
    </div>
  )
}
