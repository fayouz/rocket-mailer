import type { NitroFetchOptions, NitroFetchRequest } from 'nitropack'
import { FetchError } from 'ofetch'

type ApiOptions = NitroFetchOptions<NitroFetchRequest>

/**
 * $fetch bound to the API with the current credentials.
 * In embed mode an expired token is renewed through the host page once, then the call is retried.
 */
export function useApi() {
  const config = useRuntimeConfig()
  const auth = useAuth()

  return async function api<T>(url: string, options: ApiOptions = {}, retried = false): Promise<T> {
    const headers = new Headers(options.headers as HeadersInit | undefined)
    if (!headers.has('Accept')) headers.set('Accept', 'application/json')
    const authorization = auth.authorizationHeader()
    if (authorization) headers.set('Authorization', authorization)
    if (options.method === 'PATCH') headers.set('Content-Type', 'application/merge-patch+json')

    try {
      return await $fetch<T>(url, { ...options, baseURL: config.public.apiBase, headers } as ApiOptions) as T
    }
    catch (error) {
      if (error instanceof FetchError && error.statusCode === 401) {
        if (auth.embedToken.value && !retried) {
          await useEmbedBridge().renewToken()
          return api<T>(url, options, true)
        }
        if (!auth.embedToken.value) await auth.logout()
      }
      throw error
    }
  }
}

/** Human-readable message from an API Platform / Symfony error response. */
export function apiErrorMessage(error: unknown): string {
  if (error instanceof FetchError) {
    const data = error.data as { detail?: string, message?: string, violations?: { propertyPath: string, message: string }[] } | undefined
    if (data?.violations?.length) {
      return data.violations.map(v => (v.propertyPath ? `${v.propertyPath} : ` : '') + v.message).join('\n')
    }
    return data?.detail ?? data?.message ?? error.message
  }
  return error instanceof Error ? error.message : String(error)
}
