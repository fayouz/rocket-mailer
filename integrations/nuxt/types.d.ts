import type { H3Event } from 'h3'

/** Draft passed to the composer (see Rocket Mailer's documentation, "Pré-remplissage"). */
export interface RocketMailerDraft {
  /** "Name <email>": must be offered to the user or allowed for your application. */
  from?: string
  to?: string[]
  cc?: string[]
  bcc?: string[]
  subject?: string
  htmlBody?: string
  /** Template id or IRI; loaded by the composer when htmlBody is not given. */
  template?: string
  /** Values of the template variables, flat or nested. */
  variables?: Record<string, unknown>
  /** Ids of attachments uploaded for this user. */
  attachments?: string[]
}

export interface RocketMailerSentEmail {
  id: string
  subject: string
  to: string[]
  status: 'queued' | 'sent' | 'failed'
}

/** Filled by your "rocket-mailer:user" hook: the logged-in user of YOUR application. */
export interface RocketMailerUserContext {
  email?: string
}

declare module 'nitropack/types' {
  interface NitroRuntimeHooks {
    'rocket-mailer:user': (event: H3Event, context: RocketMailerUserContext) => void | Promise<void>
  }
}
