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
  /** Its own sender, required to send (never the platform's addresses). */
  senderEmail: string | null
  senderName: string | null
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

/** unknown: network check not run yet (LDAP, mailboxes: every 5 minutes). */
export type ServiceStatus = 'operational' | 'degraded' | 'down' | 'disabled' | 'unknown'

/** Last result of a background network check. */
export interface ServiceCheckResult {
  status: 'operational' | 'down' | 'unknown'
  detail?: string
  latencyMs?: number | null
  checkedAt?: string
  lastOkAt?: string | null
  failingSince?: string | null
}

export interface MailboxHealth {
  id: string
  name: string
  email: string
  status: 'operational' | 'down' | 'unknown'
  smtp: ServiceCheckResult | null
  /** null: no IMAP copy for this mailbox. */
  imap: ServiceCheckResult | null
}

export interface ServiceHealth {
  id: 'database' | 'queue' | 'mailer' | 'mailboxes' | 'ldap' | 'storage'
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
  /** ldap: last network check. */
  check?: ServiceCheckResult | null
  /** mailboxes */
  total?: number
  failing?: number
  items?: MailboxHealth[]
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
  health: { status: Exclude<ServiceStatus, 'disabled' | 'unknown'>, services?: ServiceHealth[] }
  users?: { total: number, enabled: number, ldap: number, local: number }
  applications?: DashboardApplication[]
}

/** A JSON-LD collection page (Accept: application/ld+json), for paginated lists. */
export interface Collection<T> {
  member: T[]
  totalItems: number
}

export interface LdapAttributes {
  email: string
  firstName: string
  lastName: string
  groups: string
}

/** GET /api/ldap/config: the directory settings (the bind password is never returned). */
export interface LdapConfig {
  enabled: boolean
  url: string
  startTls: boolean
  baseDn: string
  bindDn: string
  hasBindPassword: boolean
  userFilter: string
  adminGroupDn: string
  attributes: LdapAttributes
  /** "environment": the LDAP_* variables of the .env (nothing saved yet). */
  source: 'database' | 'environment'
  defaults: { attributes: LdapAttributes }
}

export interface LdapTestResult {
  ok: boolean
  message: string
  count: number
  sample: { dn: string, email: string, firstName: string | null, lastName: string | null, admin: boolean | null }[]
}

/** GET /api/system/version */
export interface AppVersionInfo {
  /** "0.7.0", "0.7.0+3 (abc1234)" (commits after a release), or "dev" / a branch name. */
  version: string
  release: string | null
}

export interface ReleaseInfo {
  version: string
  /** Git tag ("v0.7.0"). */
  tag: string
  name: string
  url: string
  publishedAt: string | null
  /** Release notes (Markdown). */
  notes: string | null
}

export type UpdateMethod = 'docker' | 'script' | 'manual'

export interface UpdateRun {
  id: string
  method: UpdateMethod
  /** requested: waiting for the scheduled task (script); started: sent to Watchtower (docker). */
  status: 'requested' | 'running' | 'started' | 'succeeded' | 'failed' | 'cancelled'
  fromVersion: string
  target: string | null
  log?: string
  requestedAt: string | null
  requestedBy: string | null
  startedAt: string | null
  finishedAt: string | null
}

/** GET /api/system/update (administrators) */
export interface UpdateStatus {
  current: AppVersionInfo & { raw: string }
  latest: ReleaseInfo | null
  /** null: this build has no version number to compare (development build, branch image). */
  updateAvailable: boolean | null
  checkEnabled: boolean
  repositoryUrl: string | null
  error: string | null
  method: UpdateMethod
  defaultMethod: UpdateMethod
  /** environment: no choice saved, UPDATE_METHOD (or automatic). */
  methodSource: 'database' | 'environment'
  methods: {
    docker: { configured: boolean }
    script: { script: string, installed: boolean, schedulerAlive: boolean, heartbeatAt: string | null }
  }
  run: UpdateRun | null
  history: UpdateRun[]
}
