import type { EmailDraft } from '~/types/api'

/**
 * postMessage protocol between the embedded compose UI (iframe) and the host page (public/embed.js).
 * Every message carries `source: 'rocket-mailer'`. Messages from the host are only trusted when they come
 * from window.parent AND from an origin declared on the application (allowedOrigins).
 *
 * iframe -> host: ready, token-request, sent, resize
 * host -> iframe: token { token }, draft { draft }
 */
const SOURCE = 'rocket-mailer'
const TOKEN_TIMEOUT_MS = 15_000

type HostMessage
  = | { source: typeof SOURCE, type: 'token', token: string }
    | { source: typeof SOURCE, type: 'draft', draft: Partial<EmailDraft> }

let pendingRenewal: Promise<void> | null = null

export function useEmbedBridge() {
  const allowedOrigins = useState<string[]>('rm_embed_origins', () => [])
  const hostOrigin = useState<string | null>('rm_embed_host', () => null)
  const auth = useAuth()

  function isTrusted(event: MessageEvent): event is MessageEvent<HostMessage> {
    return event.source === window.parent
      && allowedOrigins.value.includes(event.origin)
      && event.data?.source === SOURCE
  }

  /** Non-sensitive signal, sent before the host origin is known. */
  function signal(type: 'ready' | 'token-request') {
    window.parent.postMessage({ source: SOURCE, type }, '*')
  }

  /** Sensitive message: only ever delivered to the verified host origin. */
  function notify(type: string, payload: Record<string, unknown> = {}) {
    if (hostOrigin.value) {
      window.parent.postMessage({ source: SOURCE, type, ...payload }, hostOrigin.value)
    }
  }

  /** Origin of the embedding page when the browser exposes it and it is allowed. */
  function detectHostOrigin(): string | null {
    const candidates = [
      window.location.ancestorOrigins?.[0],
      document.referrer ? new URL(document.referrer).origin : undefined,
    ]
    return candidates.find(o => o && allowedOrigins.value.includes(o)) ?? null
  }

  function waitForToken(): Promise<string> {
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        window.removeEventListener('message', listener)
        reject(new Error('The host page did not provide an embed token.'))
      }, TOKEN_TIMEOUT_MS)

      function listener(event: MessageEvent) {
        if (!isTrusted(event) || event.data.type !== 'token' || typeof event.data.token !== 'string') return
        clearTimeout(timer)
        window.removeEventListener('message', listener)
        hostOrigin.value = event.origin
        resolve(event.data.token)
      }

      window.addEventListener('message', listener)
    })
  }

  async function requestToken(type: 'ready' | 'token-request') {
    const token = waitForToken()
    signal(type)
    auth.embedToken.value = await token
  }

  function renewToken(): Promise<void> {
    pendingRenewal ??= requestToken('token-request').finally(() => {
      pendingRenewal = null
    })
    return pendingRenewal
  }

  function onDraft(handler: (draft: Partial<EmailDraft>) => void) {
    const listener = (event: MessageEvent) => {
      if (isTrusted(event) && event.data.type === 'draft') handler(event.data.draft)
    }
    window.addEventListener('message', listener)
    return () => window.removeEventListener('message', listener)
  }

  return { allowedOrigins, hostOrigin, detectHostOrigin, requestToken, renewToken, notify, onDraft }
}
