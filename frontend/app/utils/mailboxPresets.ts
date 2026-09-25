import type { MailEncryption } from '~/types/api'

export interface MailboxPreset {
  id: string
  label: string
  transport: 'smtp' | 'dsn'
  smtp?: { host: string, port: number, encryption: MailEncryption }
  imap?: { host: string, port: number, encryption: MailEncryption }
  /** DSN template for providers (API keys between angle brackets). */
  dsn?: string
  note?: string
}

/** Usual settings, to fill the form in one click. */
export const MAILBOX_PRESETS: MailboxPreset[] = [
  {
    id: 'gmail',
    label: 'Gmail / Google Workspace',
    transport: 'smtp',
    smtp: { host: 'smtp.gmail.com', port: 465, encryption: 'ssl' },
    imap: { host: 'imap.gmail.com', port: 993, encryption: 'ssl' },
    note: 'Utilisez un mot de passe d’application (compte avec validation en deux étapes) ; IMAP doit être activé dans Gmail.',
  },
  {
    id: 'microsoft',
    label: 'Microsoft 365 / Outlook',
    transport: 'smtp',
    smtp: { host: 'smtp.office365.com', port: 587, encryption: 'starttls' },
    imap: { host: 'outlook.office365.com', port: 993, encryption: 'ssl' },
    note: 'L’envoi SMTP authentifié doit être autorisé sur la boîte (Centre d’administration Exchange). Les tenants qui imposent OAuth2 ne sont pas encore pris en charge.',
  },
  {
    id: 'ovh',
    label: 'OVHcloud (MX Plan, Email Pro)',
    transport: 'smtp',
    smtp: { host: 'ssl0.ovh.net', port: 465, encryption: 'ssl' },
    imap: { host: 'ssl0.ovh.net', port: 993, encryption: 'ssl' },
  },
  {
    id: 'infomaniak',
    label: 'Infomaniak',
    transport: 'smtp',
    smtp: { host: 'mail.infomaniak.com', port: 465, encryption: 'ssl' },
    imap: { host: 'mail.infomaniak.com', port: 993, encryption: 'ssl' },
  },
  {
    id: 'smtp',
    label: 'Autre serveur SMTP / IMAP',
    transport: 'smtp',
    smtp: { host: '', port: 587, encryption: 'starttls' },
    imap: { host: '', port: 993, encryption: 'ssl' },
  },
  { id: 'brevo', label: 'Brevo (API)', transport: 'dsn', dsn: 'brevo+api://<CLÉ_API>@default' },
  { id: 'ses', label: 'Amazon SES (API)', transport: 'dsn', dsn: 'ses+api://<ACCESS_KEY>:<SECRET_KEY>@default?region=eu-west-3' },
  { id: 'mailjet', label: 'Mailjet (API)', transport: 'dsn', dsn: 'mailjet+api://<CLÉ_PUBLIQUE>:<CLÉ_PRIVÉE>@default' },
  { id: 'sendgrid', label: 'SendGrid (API)', transport: 'dsn', dsn: 'sendgrid+api://<CLÉ_API>@default' },
  { id: 'postmark', label: 'Postmark (API)', transport: 'dsn', dsn: 'postmark+api://<SERVER_TOKEN>@default' },
  { id: 'mailgun', label: 'Mailgun (API)', transport: 'dsn', dsn: 'mailgun+api://<CLÉ_API>:<DOMAINE>@default?region=eu' },
]

export const ENCRYPTION_ITEMS: { label: string, value: MailEncryption }[] = [
  { label: 'SSL/TLS', value: 'ssl' },
  { label: 'STARTTLS', value: 'starttls' },
  { label: 'Aucun (réseau de confiance uniquement)', value: 'none' },
]
