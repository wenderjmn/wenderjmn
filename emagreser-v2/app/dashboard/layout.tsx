import { redirect } from 'next/navigation'
import { createServerSupabase } from '@/lib/supabase-server'
import DashboardNav from '@/components/DashboardNav'

export default async function DashboardLayout({
  children,
}: {
  children: React.ReactNode
}) {
  const supabase = await createServerSupabase()
  const { data: { user } } = await supabase.auth.getUser()

  if (!user) redirect('/')

  const { data: profile } = await supabase
    .from('users_profile')
    .select('*')
    .eq('id', user.id)
    .single()

  if (!profile) {
    // Create profile if first login
    await supabase.from('users_profile').insert({
      id: user.id,
      email: user.email,
      name: user.email?.split('@')[0],
      full_name: user.email?.split('@')[0],
    })
  }

  return (
    <div className="min-h-screen bg-gray-50 flex flex-col">
      <DashboardNav profile={profile} userId={user.id} />
      <main className="flex-1 max-w-4xl mx-auto w-full px-4 py-6">
        {children}
      </main>
    </div>
  )
}
