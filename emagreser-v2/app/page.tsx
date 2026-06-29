import LandingClient from '@/components/LandingClient'
import { createServerSupabase } from '@/lib/supabase-server'

export const revalidate = 3600

export default async function HomePage() {
  const supabase = await createServerSupabase()

  const [{ data: questions }, { data: testimonials }, { data: wppConfig }] = await Promise.all([
    supabase.from('quiz_questions').select('*').eq('active', true).order('order_index'),
    supabase.from('testimonials').select('*').eq('active', true).order('order_index'),
    supabase.from('whatsapp_config').select('whatsapp_link').single(),
  ])

  return (
    <LandingClient
      questions={questions ?? []}
      testimonials={testimonials ?? []}
      whatsappLink={wppConfig?.whatsapp_link ?? ''}
    />
  )
}
