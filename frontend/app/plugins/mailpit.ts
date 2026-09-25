/** The test inbox (NUXT_PUBLIC_MAILPIT_URL, demo and local stack) among the shortcuts of the dashboard. */
export default defineNuxtPlugin(() => {
  const url = useRuntimeConfig().public.mailpitUrl
  const shortcuts = useAppConfig().rocket.shortcuts
  if (url && !shortcuts.some(s => s.to === url)) {
    shortcuts.push({ label: 'Mailpit', description: 'Boîte de réception de test', icon: 'i-lucide-mail-search', to: url })
  }
})
