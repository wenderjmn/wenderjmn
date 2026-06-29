'use client'

import { useState } from 'react'
import { createClient } from '@/lib/supabase'
import Button from '@/components/ui/Button'

interface Question {
  id: string
  question_text: string
  option_a: string
  option_b: string
  option_c: string
  option_d: string
  weight_a: string
  weight_b: string
  weight_c: string
  weight_d: string
  order_index: number
}

interface Testimonial {
  id: string
  name: string
  result: string | null
  quote: string | null
  profession: string | null
  photo_url: string | null
  sabotador: string | null
  type: string
}

const SABOTADOR_INFO: Record<string, { emoji: string; label: string; desc: string }> = {
  A: { emoji: '🧠', label: 'Hiperrracional', desc: 'Você tende a racionalizar demais e evitar sentimentos.' },
  B: { emoji: '🙋', label: 'Agradadora', desc: 'Você coloca as necessidades dos outros antes das suas.' },
  C: { emoji: '⚡', label: 'Hipervigilante', desc: 'Você está sempre em alerta, antecipando problemas.' },
  D: { emoji: '🎯', label: 'Controladora', desc: 'Você precisa controlar tudo ao seu redor para se sentir segura.' },
}

interface Props {
  questions: Question[]
  testimonials: Testimonial[]
  whatsappLink: string
}

export default function LandingClient({ questions, testimonials, whatsappLink }: Props) {
  const supabase = createClient()
  const [step, setStep] = useState<'hero' | 'quiz' | 'result' | 'capture' | 'thanks'>('hero')
  const [currentQ, setCurrentQ] = useState(0)
  const [answers, setAnswers] = useState<Record<string, string>>({})
  const [sabotador, setSabotador] = useState('')
  const [form, setForm] = useState({ name: '', email: '', phone: '' })
  const [loading, setLoading] = useState(false)

  function calcSabotador(ans: Record<string, string>) {
    const counts: Record<string, number> = { A: 0, B: 0, C: 0, D: 0 }
    Object.values(ans).forEach(v => { if (v in counts) counts[v]++ })
    return Object.entries(counts).sort((a, b) => b[1] - a[1])[0][0]
  }

  function handleAnswer(questionId: string, weight: string) {
    const newAnswers = { ...answers, [questionId]: weight }
    setAnswers(newAnswers)

    if (currentQ < questions.length - 1) {
      setCurrentQ(q => q + 1)
    } else {
      const result = calcSabotador(newAnswers)
      setSabotador(result)
      setStep('result')
    }
  }

  async function handleCapture(e: React.FormEvent) {
    e.preventDefault()
    setLoading(true)

    const params = new URLSearchParams(window.location.search)
    await supabase.from('leads').insert({
      name: form.name,
      email: form.email,
      phone: form.phone,
      sabotador,
      quiz_answers: answers,
      source: 'site',
      source_campaign: params.get('utm_campaign'),
      utm_medium: params.get('utm_medium'),
    })

    setLoading(false)
    setStep('thanks')
  }

  const info = SABOTADOR_INFO[sabotador]

  return (
    <div className="min-h-screen bg-[#faf9f7]">
      {/* Hero */}
      {step === 'hero' && (
        <>
          <nav className="border-b border-gray-100 bg-white/80 backdrop-blur-sm sticky top-0 z-10">
            <div className="max-w-5xl mx-auto px-4 h-14 flex items-center justify-between">
              <span className="font-bold text-gray-900 flex items-center gap-2">
                <span>🌱</span> EmagreSer V2
              </span>
              <a href="/login" className="text-sm text-gray-500 hover:text-gray-700 font-medium">
                Já sou aluna →
              </a>
            </div>
          </nav>

          <section className="max-w-5xl mx-auto px-4 py-20 text-center">
            <div className="inline-block bg-green-50 text-green-700 text-sm font-semibold px-4 py-1.5 rounded-full mb-6">
              Programa de 12 semanas
            </div>
            <h1 className="text-4xl sm:text-5xl font-bold text-[#1a1a2e] leading-tight mb-6">
              Emagreça sem dieta,<br />
              <span className="text-green-600">com consciência</span>
            </h1>
            <p className="text-xl text-gray-500 max-w-2xl mx-auto mb-10">
              Descubra qual sabotador interno está impedindo sua transformação e aprenda a viver em equilíbrio com a comida.
            </p>
            <Button size="lg" onClick={() => setStep('quiz')} className="text-base">
              Descobrir meu sabotador →
            </Button>
            <p className="text-sm text-gray-400 mt-4">Gratuito · 3 minutos · Resultado imediato</p>
          </section>

          {/* Benefícios */}
          <section className="bg-white border-y border-gray-100 py-16">
            <div className="max-w-5xl mx-auto px-4">
              <h2 className="text-2xl font-bold text-center text-[#1a1a2e] mb-12">
                Por que o EmagreSer é diferente?
              </h2>
              <div className="grid sm:grid-cols-3 gap-8">
                {[
                  { icon: '🧠', title: 'Mente', desc: 'Identifique os padrões emocionais que sabotam sua alimentação.' },
                  { icon: '🥗', title: 'Corpo', desc: 'Aprenda a comer com atenção plena, honrando fome e saciedade.' },
                  { icon: '🤝', title: 'Comunidade', desc: 'Jornada em grupo com mentoras especializadas ao seu lado.' },
                ].map(b => (
                  <div key={b.title} className="text-center">
                    <div className="text-4xl mb-4">{b.icon}</div>
                    <h3 className="font-bold text-[#1a1a2e] mb-2">{b.title}</h3>
                    <p className="text-gray-500 text-sm">{b.desc}</p>
                  </div>
                ))}
              </div>
            </div>
          </section>

          {/* Depoimentos */}
          {testimonials.length > 0 && (
            <section className="max-w-5xl mx-auto px-4 py-16">
              <h2 className="text-2xl font-bold text-center text-[#1a1a2e] mb-10">
                Resultados reais
              </h2>
              <div className="grid sm:grid-cols-2 gap-6">
                {testimonials.slice(0, 4).map(t => (
                  <div key={t.id} className="bg-white rounded-2xl border border-gray-100 p-6">
                    <p className="text-gray-700 italic mb-4">"{t.quote}"</p>
                    <div className="flex items-center gap-3">
                      {t.photo_url && (
                        <img src={t.photo_url} alt={t.name} className="w-10 h-10 rounded-full object-cover" />
                      )}
                      <div>
                        <p className="font-semibold text-sm text-gray-900">{t.name}</p>
                        {t.result && <p className="text-xs text-green-600 font-medium">{t.result}</p>}
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            </section>
          )}

          {/* CTA final */}
          <section className="bg-green-600 py-16 text-center text-white">
            <h2 className="text-3xl font-bold mb-4">Pronta para começar?</h2>
            <p className="text-green-100 mb-8 text-lg">Descubra seu sabotador e dê o primeiro passo.</p>
            <Button variant="secondary" size="lg" onClick={() => setStep('quiz')}>
              Fazer o quiz agora →
            </Button>
          </section>
        </>
      )}

      {/* Quiz */}
      {step === 'quiz' && questions.length > 0 && (
        <div className="min-h-screen flex items-center justify-center px-4 py-12">
          <div className="w-full max-w-xl">
            <div className="flex justify-between items-center mb-6">
              <span className="text-sm text-gray-400">Pergunta {currentQ + 1} de {questions.length}</span>
              <button onClick={() => { setStep('hero'); setCurrentQ(0); setAnswers({}) }} className="text-sm text-gray-400 hover:text-gray-600">
                Sair
              </button>
            </div>

            <div className="h-1.5 bg-gray-100 rounded-full mb-8">
              <div
                className="h-full bg-green-500 rounded-full transition-all"
                style={{ width: `${((currentQ) / questions.length) * 100}%` }}
              />
            </div>

            <div className="bg-white rounded-2xl border border-gray-100 p-8">
              <h2 className="text-lg font-semibold text-[#1a1a2e] mb-6">
                {questions[currentQ].question_text}
              </h2>
              <div className="space-y-3">
                {(['a', 'b', 'c', 'd'] as const).map(opt => {
                  const text = questions[currentQ][`option_${opt}` as keyof Question] as string
                  const weight = questions[currentQ][`weight_${opt}` as keyof Question] as string
                  return (
                    <button
                      key={opt}
                      onClick={() => handleAnswer(questions[currentQ].id, weight)}
                      className="w-full text-left px-4 py-3 rounded-xl border border-gray-200 hover:border-green-400 hover:bg-green-50 text-sm text-gray-700 transition-colors"
                    >
                      <span className="font-medium text-green-600 mr-2">{opt.toUpperCase()}.</span>
                      {text}
                    </button>
                  )
                })}
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Resultado */}
      {step === 'result' && info && (
        <div className="min-h-screen flex items-center justify-center px-4 py-12">
          <div className="w-full max-w-lg text-center">
            <div className="text-6xl mb-4">{info.emoji}</div>
            <h2 className="text-2xl font-bold text-[#1a1a2e] mb-2">Seu sabotador é:</h2>
            <h3 className="text-3xl font-bold text-green-600 mb-4">{info.label}</h3>
            <p className="text-gray-600 mb-8 text-lg">{info.desc}</p>
            <div className="bg-white rounded-2xl border border-gray-100 p-6 mb-6">
              <p className="text-gray-700 font-medium mb-1">Quer receber seu diagnóstico completo?</p>
              <p className="text-sm text-gray-500">Informe seus dados para receber o plano personalizado no WhatsApp.</p>
            </div>
            <Button size="lg" onClick={() => setStep('capture')} className="w-full">
              Receber meu resultado completo →
            </Button>
          </div>
        </div>
      )}

      {/* Captura */}
      {step === 'capture' && (
        <div className="min-h-screen flex items-center justify-center px-4 py-12">
          <div className="w-full max-w-md">
            <div className="text-center mb-8">
              <div className="text-4xl mb-3">{info?.emoji}</div>
              <h2 className="text-xl font-bold text-[#1a1a2e]">Quase lá!</h2>
              <p className="text-gray-500 text-sm mt-1">Preencha para receber seu resultado personalizado.</p>
            </div>
            <div className="bg-white rounded-2xl border border-gray-100 p-8">
              <form onSubmit={handleCapture} className="space-y-4">
                <input
                  type="text" placeholder="Seu nome" required value={form.name}
                  onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
                  className="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                />
                <input
                  type="email" placeholder="Seu e-mail" required value={form.email}
                  onChange={e => setForm(f => ({ ...f, email: e.target.value }))}
                  className="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                />
                <input
                  type="tel" placeholder="WhatsApp (com DDD)" value={form.phone}
                  onChange={e => setForm(f => ({ ...f, phone: e.target.value }))}
                  className="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                />
                <Button type="submit" loading={loading} size="lg" className="w-full">
                  Quero meu diagnóstico
                </Button>
                <p className="text-xs text-gray-400 text-center">Sem spam. Seus dados estão seguros.</p>
              </form>
            </div>
          </div>
        </div>
      )}

      {/* Obrigada */}
      {step === 'thanks' && (
        <div className="min-h-screen flex items-center justify-center px-4">
          <div className="text-center max-w-md">
            <div className="text-6xl mb-4">🎉</div>
            <h2 className="text-2xl font-bold text-[#1a1a2e] mb-2">Incrível, {form.name.split(' ')[0]}!</h2>
            <p className="text-gray-500 mb-8">
              Seu diagnóstico foi enviado. Entre no nosso grupo do WhatsApp para começar sua jornada!
            </p>
            {whatsappLink && (
              <a
                href={whatsappLink}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-2 bg-green-500 hover:bg-green-600 text-white font-semibold px-8 py-4 rounded-xl transition-colors text-lg"
              >
                📱 Entrar no grupo
              </a>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
