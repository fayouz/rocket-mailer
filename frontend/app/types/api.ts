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
  enabled: boolean
  lastUsedAt: string | null
}

export interface EmailTemplateSummary extends Tracked {
  id: string
  name: string
  description: string | null
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
  to: string[]
  cc: string[]
  bcc: string[]
  subject: string
  htmlBody: string
  template: string | null
  /** Ids of attachments already uploaded for this user (e.g. by the host application's backend). */
  attachments: string[]
}
