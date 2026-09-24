export interface Tracked {
  createdAt: string
  updatedAt: string
  createdBy: string | null
  updatedBy: string | null
}

export interface UserSummary {
  id: string
  email: string
  firstName: string | null
  lastName: string | null
  displayName: string
}

export interface User extends UserSummary, Tracked {
  roles: string[]
  source: 'local' | 'ldap'
  ldapDn: string | null
  ldapSyncedAt: string | null
  enabled: boolean
}

export interface Me {
  user: User | null
  application: { id: string, name: string } | null
  roles: string[]
  embed: boolean
}

export interface Application extends Tracked {
  id: string
  name: string
  description: string | null
  tokenHint: string
  plainToken?: string
  canImpersonate: boolean
  allowedOrigins: string[]
  allowedSenders: string[]
  enabled: boolean
  lastUsedAt: string | null
}

/** A "{{ name }}" placeholder of a template. */
export interface TemplateVariable {
  name: string
  label: string | null
  defaultValue: string | null
  /** Present in the content or the subject (false: declared but unused). */
  used?: boolean
}

export interface EmailTemplateSummary extends Tracked {
  id: string
  name: string
  description: string | null
  variables: TemplateVariable[]
  defaultSubject: string | null
  shared: boolean
  owner: UserSummary
}

export interface EmailTemplate extends EmailTemplateSummary {
  html: string
  projectData: Record<string, unknown> | null
}

export interface TemplateVersion {
  version: number
  action: 'create' | 'update' | 'remove'
  loggedAt: string
  username: string | null
  changedFields: string[]
}

/** A "From" choice offered in the composer. */
export interface SenderOption {
  from: string
  email: string
  name: string | null
  default: boolean
  source: 'settings' | 'personal' | 'application'
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
  attachmentCount: number
  attachments?: Attachment[]
}

export interface EmailDraft {
  /** "Name <email>" or "email"; empty = default sender. */
  from: string | null
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

export type ServiceStatus = 'operational' | 'degraded' | 'down' | 'disabled'

export interface ServiceHealth {
  id: 'database' | 'queue' | 'mailer' | 'ldap' | 'storage'
  label: string
  status: ServiceStatus
  detail: string
  latencyMs?: number
  queued?: number
  failedMessages?: number
  users?: number
  lastSyncAt?: string | null
  usagePercent?: number | null
  freeBytes?: number | null
}

export interface DashboardActivity {
  type: 'email.sent' | 'email.failed' | 'email.queued' | 'template.create' | 'template.update' | 'template.remove' | 'user.created' | 'application.created'
  at: string
  title: string
  actor: string | null
  link: string | null
}

export interface DashboardApplication {
  id: string
  name: string
  enabled: boolean
  canImpersonate: boolean
  lastUsedAt: string | null
  sent: number
  failed: number
}

/** GET /api/dashboard: platform-wide for admins, the user's own activity otherwise. */
export interface Dashboard {
  scope: 'platform' | 'user'
  generatedAt: string
  days: number
  emails: {
    sent: number
    failed: number
    queued: number
    oldestQueuedAt: string | null
    previousSent: number
    previousFailed: number
    /** Percentage of delivered emails among processed ones; null when nothing was processed. */
    deliveryRate: number | null
    withAttachments: number
  }
  templates: { total: number, shared: number }
  daily: { date: string, sent: number, failed: number, queued: number }[]
  recentEmails: {
    id: string
    subject: string
    to: string[]
    status: EmailStatus
    createdAt: string
    from: string | null
    sender: string
    applicationName: string | null
  }[]
  activity: DashboardActivity[]
  health: { status: Exclude<ServiceStatus, 'disabled'>, services?: ServiceHealth[] }
  users?: { total: number, enabled: number, ldap: number, local: number }
  applications?: DashboardApplication[]
}

/** A JSON-LD collection page (Accept: application/ld+json), for paginated lists. */
export interface Collection<T> {
  member: T[]
  totalItems: number
}
