export default defineNuxtRouteMiddleware(async (to) => {
  // The embed page authenticates itself with a token handed over by the host application.
  if (to.path.startsWith('/embed')) return
  // Public redirects to the documentation site (docs and changelog).
  if (to.path === '/changelog' || to.path === '/docs' || to.path.startsWith('/docs/')) return

  const auth = useAuth()

  if (to.path === '/login') {
    return auth.token.value ? navigateTo('/') : undefined
  }

  if (!auth.token.value) {
    return navigateTo({ path: '/login', query: { redirect: to.fullPath } })
  }

  if (!auth.me.value) {
    try {
      await auth.fetchMe()
    }
    catch {
      return navigateTo('/login')
    }
  }

  if (to.meta.admin && !auth.isAdmin.value) {
    return navigateTo('/')
  }
})
