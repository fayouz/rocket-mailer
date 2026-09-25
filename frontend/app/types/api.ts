import type { Tracked, UserSummary } from '#rocket/types/api'

// Types of the Rocket core (users, applications, dashboard…), then those of this application.
export type * from '#rocket/types/api'

/** A "{{ name }}" placeholder of a template. */
export interface TemplateVariable {
  name: string
  label: string | null
  defaultValue: string | null
  /** Present in the content or the subject (false: declared but unused). */
  used?: boolean
}

/** HTML layout around templates, with the {{ content }} slot. */
export interface EmailLayout extends Tracked {
  id: string
  name: string
  description: string | null
  html: string
}

export interface EmailTemplateSummary extends Tracked {
  id: string
  name: string
  description: string | null
  variables: TemplateVariable[]
  layout: { '@id': string, 'id': string, 'name': string } | null
  defaultSubject: string | null
  shared: boolean
  owner: UserSummary
}

export interface EmailTemplate extends EmailTemplateSummary {
  html: string
  /** The content in its layout: what the composer imports and what is sent. */
  renderedHtml: string
  projectData: Record<string, unknown> | null
}

export interface TemplateVersion {
  version: number
  action: 'create' | 'update' | 'remove'
  loggedAt: string
  username: string | null
  changedFields: string[]
}

/** GET/PATCH /api/application_senders/{application id}: the sender settings of an application (administrators). */
export interface ApplicationSender {
  /** Id of the application. */
  id: string
  /** Its own sender: the default "From" of its composer and of its API sends (required to send). */
  senderEmail?: string | null
  senderName?: string | null
  /** Addresses it may impose: exact addresses or whole domains ("*@crm.example.com"). */
  allowedSenders: string[]
}

/** A "From" choice offered in the composer. */
export interface SenderOption {
  from: string
  email: string
  name: string | null
  default: boolean
  source: 'settings' | 'personal' | 'application' | 'mailbox'
  /** IRI of the sending mailbox (source "mailbox"). */
  mailbox?: string
  mailboxName?: string
}

export type MailEncryption = 'ssl' | 'starttls' | 'none'

/** A sending mailbox: its own SMTP server (or provider DSN) and an IMAP "Sent" folder. */
export interface Mailbox extends Tracked {
  id: string
  name: string
  email: string
  displayName: string | null
  transport: 'smtp' | 'dsn'
  smtpHost: string | null
  smtpPort: number | null
  smtpEncryption: MailEncryption
  smtpUsername: string | null
  hasSmtpPassword: boolean
  dsnHint: string | null
  imapEnabled: boolean
  imapHost: string | null
  imapPort: number | null
  imapEncryption: MailEncryption
  imapUsername: string | null
  hasImapPassword: boolean
  imapSentFolder: string | null
  availableToUsers: boolean
  enabled: boolean
  /** Application IRIs. */
  applications: string[]
}

export interface MailboxTestResult {
  smtp: { ok: boolean, message: string }
  imap: { ok: boolean, message: string, sentFolder?: string } | null
}

export interface SenderAddress extends Tracked {
  id: string
  email: string
  name: string | null
  isDefault: boolean
}

export interface Settings {
  personalFromAllowed: boolean
  installDefaultFrom?: string
}

export interface Attachment {
  id: string
  filename: string
  mimeType: string
  size: number
}

export type EmailStatus = 'queued' | 'sent' | 'failed'

export interface Email extends Tracked {
  id: string
  sender: UserSummary
  from: string | null
  applicationName: string | null
  to: string[]
  cc?: string[]
  bcc?: string[]
  subject: string
  htmlBody?: string
  status: EmailStatus
  errorMessage?: string | null
  sentAt: string | null
  /** Sending mailbox, if any. */
  mailboxName?: string | null
  /** IMAP folder of the mailbox holding the copy. */
  archivedIn?: string | null
  archiveError?: string | null
  attachmentCount: number
  attachments?: Attachment[]
}

export interface EmailDraft {
  /** "Name <email>" or "email"; empty = default sender. */
  from: string | null
  /** Sending mailbox (id or IRI), instead of "from". */
  mailbox?: string | null
  to: string[]
  cc: string[]
  bcc: string[]
  subject: string
  htmlBody: string
  template: string | null
  /** Ids of attachments already uploaded for this user (e.g. by the host application's backend). */
  attachments: string[]
  /** Values of the template variables, flat ({ "client.firstName": "Jean" }) or nested ({ client: { firstName: "Jean" } }). */
  variables?: Record<string, unknown>
}
