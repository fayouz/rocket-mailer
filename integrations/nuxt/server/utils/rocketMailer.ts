import type { H3Event } from 'h3'
import type { RocketMailerDraft, RocketMailerUserContext } from '../../types'

/**
 * The logged-in user of the host application, as told by its "rocket-mailer:user" Nitro hook.
 * Never taken from the request: the browser must not choose who it acts as.
 */
export async function resolveRocketMailerUser(event: H3Event): Promise<string | undefined> {
  const context: RocketMailerUserContext = {}
  await useNitroApp().hooks.callHook('rocket-mailer:user', event, context)
  return context.email
}

/** Calls the Rocket Mailer API with the application secret, optionally acting as a user. */
export function rocketMailerFetch<T>(path: string, options: { method?: 'GET' | 'POST', body?: unknown, actAs?: string } = {}): Promise<T> {
  const config = useRuntimeConfig()
  const baseURL = config.rocketMailer.apiUrl || config.public.rocketMailer.url
  if (!baseURL || !config.rocketMailer.appToken) {
    throw createError({ statusCode: 500, statusMessage: 'Rocket Mailer is not configured (NUXT_PUBLIC_ROCKET_MAILER_URL, NUXT_ROCKET_MAILER_APP_TOKEN)' })
  }
  return $fetch<T>(path, {
    baseURL,
    method: options.method ?? 'GET',
    body: options.body as Record<string, unknown> | undefined,
    headers: {
      'Authorization': `Bearer ${config.rocketMailer.appToken}`,
      'Accept': 'application/json',
      ...(options.actAs ? { 'X-Impersonate-User': options.actAs } : {}),
    },
  })
}

export interface RocketMailerSendInput extends Omit<RocketMailerDraft, 'template' | 'attachments'> {
  to: string[]
  /** Template IRI (/api/email_templates/<id>) or id. */
  template?: string
  /** Attachment IRIs or ids. */
  attachments?: string[]
}

/** Sends an email server-side, on behalf of a user (no composer): e.g. a template with its variables. */
export function sendRocketMailerEmail(actAs: string, input: RocketMailerSendInput) {
  const iri = (collection: string, value: string) => (value.startsWith('/api/') ? value : `/api/${collection}/${value}`)
  return rocketMailerFetch<{ id: string, status: string, subject: string }>('/api/emails', {
    method: 'POST',
    actAs,
    body: {
      ...input,
      template: input.template ? iri('email_templates', input.template) : undefined,
      attachments: input.attachments?.map(id => iri('attachments', id)),
    },
  })
}
