/**
 * Clickjacking protection. Pages can never be framed, except /embed/** which can only be framed
 * by the origins declared on the application (?app=<id>), as reported by the API.
 */
const cache = new Map<string, { origins: string[], expires: number }>()
const CACHE_TTL_MS = 60_000

async function allowedOrigins(appId: string): Promise<string[]> {
  const cached = cache.get(appId)
  if (cached && cached.expires > Date.now()) return cached.origins

  const config = useRuntimeConfig()
  let origins: string[]
  try {
    const policy = await $fetch<{ frameAncestors: string[] }>('/api/embed/frame-policy', {
      baseURL: config.apiInternalBase || config.public.apiBase,
      query: { app: appId },
      timeout: 3000,
    })
    origins = policy.frameAncestors.filter(origin => /^https?:\/\/[a-z0-9.-]+(:\d+)?$/i.test(origin))
  }
  catch {
    origins = []
  }

  cache.set(appId, { origins, expires: Date.now() + CACHE_TTL_MS })
  return origins
}

export default defineEventHandler(async (event) => {
  const url = getRequestURL(event)
  if (url.pathname.startsWith('/_nuxt/') || url.pathname.startsWith('/__nuxt') || url.pathname.startsWith('/api/')) return

  let ancestors = '\'none\''
  if (url.pathname.startsWith('/embed/')) {
    const appId = url.searchParams.get('app') ?? ''
    const origins = /^[0-9a-f-]{36}$/i.test(appId) ? await allowedOrigins(appId) : []
    if (origins.length) ancestors = origins.join(' ')
  }

  setResponseHeader(event, 'Content-Security-Policy', `frame-ancestors ${ancestors}`)
  setResponseHeader(event, 'X-Content-Type-Options', 'nosniff')
  setResponseHeader(event, 'Referrer-Policy', 'strict-origin-when-cross-origin')
})
