<script setup lang="ts">
import type { Dashboard, DashboardActivity, ServiceStatus } from '~/types/api'

useHead({ title: 'Tableau de bord · Rocket Mailer' })

const api = useApi()
const auth = useAuth()
const config = useRuntimeConfig()
const toast = useToast()

const { data, status, refresh } = await useAsyncData('dashboard', () => api<Dashboard>('/api/dashboard'))

// Relative times ("il y a 2 min") and the periodic refresh share one clock.
const now = ref(new Date())
let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  timer = setInterval(() => {
    now.value = new Date()
    if (now.value.getTime() - new Date(data.value?.generatedAt ?? 0).getTime() >= 60_000) refresh()
  }, 15_000)
})
onBeforeUnmount(() => clearInterval(timer))

const isAdmin = computed(() => auth.isAdmin.value)
const firstName = computed(() => auth.me.value?.user?.firstName || auth.me.value?.user?.displayName || '')
const today = computed(() => capitalize(new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(now.value)))

const HEALTH: Record<ServiceStatus, { label: string, dot: string, badge: 'success' | 'warning' | 'error' | 'neutral' }> = {
  operational: { label: 'Opérationnel', dot: 'bg-success', badge: 'success' },
  degraded: { label: 'Dégradé', dot: 'bg-warning', badge: 'warning' },
  down: { label: 'Hors service', dot: 'bg-error', badge: 'error' },
  disabled: { label: 'Désactivé', dot: 'bg-neutral-400 dark:bg-neutral-600', badge: 'neutral' },
  unknown: { label: 'Non vérifié', dot: 'bg-neutral-300 dark:bg-neutral-700', badge: 'neutral' },
}

// LDAP and mailboxes are checked over the network by the worker every 5 minutes; "Vérifier" runs them now.
const checking = ref(false)
async function checkServices() {
  if (!data.value) return
  checking.value = true
  try {
    data.value.health = await api<Dashboard['health']>('/api/health/check', { method: 'POST' })
  }
  catch (error) {
    toast.add({ title: 'Vérification impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    checking.value = false
  }
}
function checkLabel(check: { status: string, detail?: string, checkedAt?: string } | null | undefined, protocol: string): string {
  if (!check || check.status === 'unknown') return `${protocol} : non vérifié`
  return check.status === 'operational' ? `${protocol} : OK` : `${protocol} : ${check.detail}`
}
const platformStatus = computed(() => ({
  operational: 'Systèmes opérationnels',
  degraded: 'Service dégradé',
  down: 'Incident en cours',
}[data.value?.health.status ?? 'operational']))

// --- KPIs ---------------------------------------------------------------------------------------

const emails = computed(() => data.value?.emails)
const sentDelta = computed(() => {
  const e = emails.value
  if (!e || e.previousSent === 0) return null
  return Math.round(((e.sent - e.previousSent) / e.previousSent) * 100)
})
const dailySent = computed(() => data.value?.daily.map(d => d.sent) ?? [])
const rateColor = computed(() => {
  const rate = emails.value?.deliveryRate
  if (rate === null || rate === undefined) return 'neutral'
  return rate >= 98 ? 'success' : rate >= 90 ? 'warning' : 'error'
})

// --- Actions ------------------------------------------------------------------------------------

const syncing = ref(false)
async function syncLdap() {
  syncing.value = true
  try {
    const report = await api<{ created: number, updated: number, disabled: number }>('/api/ldap/sync', { method: 'POST' })
    toast.add({ title: 'Annuaire synchronisé', description: `${report.created} créé(s), ${report.updated} mis à jour, ${report.disabled} désactivé(s)`, color: 'success' })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Synchronisation impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    syncing.value = false
  }
}

const quickActions = computed(() => [
  { label: 'Nouveau message', icon: 'i-lucide-send', to: '/compose', tone: 'bg-primary/10 text-primary' },
  { label: 'Nouveau template', icon: 'i-lucide-layout-template', to: '/templates/new', tone: 'bg-sky-500/10 text-sky-600 dark:text-sky-400' },
  ...(isAdmin.value
    ? [
        { label: 'Nouvelle application', icon: 'i-lucide-plug', to: '/applications?new=1', tone: 'bg-violet-500/10 text-violet-600 dark:text-violet-400' },
        { label: 'Nouvel utilisateur', icon: 'i-lucide-user-plus', to: '/users?new=1', tone: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' },
      ]
    : [
        { label: 'Mes envois', icon: 'i-lucide-inbox', to: '/emails', tone: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' },
      ]),
])

// --- Activity -----------------------------------------------------------------------------------

const ACTIVITY: Record<DashboardActivity['type'], { icon: string, label: string, color: string }> = {
  'email.sent': { icon: 'i-lucide-send', label: 'Email envoyé', color: 'text-success bg-success/10' },
  'email.failed': { icon: 'i-lucide-circle-x', label: 'Échec d’envoi', color: 'text-error bg-error/10' },
  'email.queued': { icon: 'i-lucide-clock', label: 'Email en file d’attente', color: 'text-muted bg-elevated' },
  'template.create': { icon: 'i-lucide-layout-template', label: 'Template créé', color: 'text-sky-600 bg-sky-500/10 dark:text-sky-400' },
  'template.update': { icon: 'i-lucide-pencil', label: 'Template modifié', color: 'text-sky-600 bg-sky-500/10 dark:text-sky-400' },
  'template.remove': { icon: 'i-lucide-trash-2', label: 'Template supprimé', color: 'text-muted bg-elevated' },
  'user.created': { icon: 'i-lucide-user-plus', label: 'Nouvel utilisateur', color: 'text-emerald-600 bg-emerald-500/10 dark:text-emerald-400' },
  'application.created': { icon: 'i-lucide-plug', label: 'Nouvelle application', color: 'text-violet-600 bg-violet-500/10 dark:text-violet-400' },
}

const activityByDay = computed(() => {
  const groups = new Map<string, DashboardActivity[]>()
  for (const event of data.value?.activity ?? []) {
    const day = dayLabel(event.at)
    groups.set(day, [...(groups.get(day) ?? []), event])
  }
  return [...groups.entries()]
})

function dayLabel(value: string): string {
  const date = new Date(value)
  const days = Math.round((new Date(now.value).setHours(0, 0, 0, 0) - new Date(date).setHours(0, 0, 0, 0)) / 86_400_000)
  if (days === 0) return 'Aujourd’hui'
  if (days === 1) return 'Hier'
  return capitalize(new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }).format(date))
}

function time(value: string): string {
  return new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit' }).format(new Date(value))
}

// --- Deliverability bars ------------------------------------------------------------------------

const bars = computed(() => {
  const daily = data.value?.daily ?? []
  const max = Math.max(...daily.map(d => d.sent + d.failed), 1)
  return daily.map((d) => {
    const total = d.sent + d.failed
    const rate = total ? (100 * d.sent) / total : null
    return {
      ...d,
      height: total ? 25 + 75 * (total / max) : 12,
      color: rate === null ? 'bg-accented' : rate === 100 ? 'bg-success' : rate >= 90 ? 'bg-warning' : 'bg-error',
      tooltip: `${new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short' }).format(new Date(d.date))} · ${d.sent} envoyé(s)${d.failed ? `, ${d.failed} échec(s)` : ''}`,
    }
  })
})

// --- Applications -------------------------------------------------------------------------------

function applicationState(application: NonNullable<Dashboard['applications']>[number]) {
  if (!application.enabled) return { label: 'Désactivée', color: 'neutral' as const }
  if (application.lastUsedAt && now.value.getTime() - new Date(application.lastUsedAt).getTime() < 7 * 86_400_000) {
    return { label: 'Active', color: 'success' as const }
  }
  return { label: 'Inactive', color: 'warning' as const }
}

const STATUS_BADGE = {
  queued: { label: 'En file', color: 'neutral' },
  sent: { label: 'Envoyé', color: 'success' },
  failed: { label: 'Échec', color: 'error' },
} as const

// --- Shortcuts ----------------------------------------------------------------------------------

const shortcuts = computed(() => [
  { label: 'Documentation', description: 'Guides d’intégration et d’administration', icon: 'i-lucide-book-open', to: config.public.docsUrl, external: true },
  { label: 'Nouveautés', description: 'Changelog des versions', icon: 'i-lucide-history', to: config.public.changelogUrl, external: true },
  { label: 'API', description: 'OpenAPI et bac à sable', icon: 'i-lucide-braces', to: `${config.public.apiBase}/api/docs`, external: true },
  ...(config.public.mailpitUrl ? [{ label: 'Mailpit', description: 'Boîte de réception de test', icon: 'i-lucide-mail-search', to: config.public.mailpitUrl, external: true }] : []),
  { label: 'Templates', description: 'Éditeur visuel GrapesJS', icon: 'i-lucide-layout-template', to: '/templates', external: false },
  ...(isAdmin.value
    ? [
        { label: 'Applications', description: 'Jetons, origines, impersonation', icon: 'i-lucide-key-round', to: '/applications', external: false },
        { label: 'Réglages', description: 'Adresses d’expédition', icon: 'i-lucide-settings', to: '/settings', external: false },
      ]
    : []),
])

function serviceMetric(service: NonNullable<Dashboard['health']['services']>[number]): string | null {
  switch (service.id) {
    case 'database': return service.latencyMs !== undefined ? `${service.latencyMs} ms` : null
    case 'queue': return `${service.queued ?? 0} en attente`
    case 'ldap': return service.check?.checkedAt
      ? `Vérifié ${timeAgo(service.check.checkedAt, now.value)}${service.latencyMs ? ` · ${service.latencyMs} ms` : ''}`
      : service.lastSyncAt ? `Synchro ${timeAgo(service.lastSyncAt, now.value)}` : null
    case 'mailboxes':
    case 'sso': return service.total ? `${service.total - (service.failing ?? 0)}/${service.total} OK` : null
    case 'storage': return service.freeBytes ? `${formatSize(service.freeBytes)} libres` : null
    default: return null
  }
}
</script>

<template>
  <UDashboardPanel id="dashboard">
    <template #header>
      <UDashboardNavbar title="Tableau de bord">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-refresh-cw" color="neutral" variant="ghost" aria-label="Rafraîchir" :loading="status === 'pending'" @click="refresh()" />
          <UButton icon="i-lucide-send" to="/compose" aria-label="Nouveau message">
            <span class="hidden sm:inline">Nouveau message</span>
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div v-if="data" class="flex w-full flex-col gap-6" data-testid="dashboard">
        <!-- Greeting, status and illustration -->
        <div class="grid gap-4 lg:grid-cols-5">
          <div class="flex flex-col justify-center gap-3 lg:col-span-3">
            <div>
              <h1 class="text-2xl font-semibold text-highlighted sm:text-3xl">
                Bonjour{{ firstName ? `, ${firstName}` : '' }} 👋
              </h1>
              <p class="mt-1 text-muted">
                {{ data.scope === 'platform' ? 'Voici l’état de votre plateforme en temps réel.' : 'Voici l’activité de vos envois.' }}
              </p>
            </div>
            <div class="flex flex-wrap gap-2 text-sm">
              <span class="inline-flex items-center gap-2 rounded-full border border-default px-3 py-1" data-testid="platform-status">
                <span class="relative flex size-2">
                  <span class="absolute inline-flex size-full animate-ping rounded-full opacity-60" :class="HEALTH[data.health.status].dot" />
                  <span class="relative inline-flex size-2 rounded-full" :class="HEALTH[data.health.status].dot" />
                </span>
                {{ platformStatus }}
              </span>
              <span class="inline-flex items-center gap-2 rounded-full border border-default px-3 py-1 text-muted">
                <UIcon name="i-lucide-refresh-cw" class="size-3.5" />
                Mis à jour {{ timeAgo(data.generatedAt, now) }}
              </span>
              <span class="inline-flex items-center gap-2 rounded-full border border-default px-3 py-1 text-muted">
                <UIcon name="i-lucide-calendar" class="size-3.5" />
                {{ today }}
              </span>
            </div>
          </div>
          <DashboardHeroBanner class="hidden sm:block lg:col-span-2" />
        </div>

        <!-- Quick actions -->
        <section aria-labelledby="quick-actions">
          <h2 id="quick-actions" class="mb-2 text-sm font-medium text-muted">
            Actions rapides
          </h2>
          <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <ULink
              v-for="action in quickActions"
              :key="action.label"
              :to="action.to"
              class="flex items-center gap-3 rounded-lg border border-default bg-default p-3 font-medium text-highlighted transition hover:border-primary/40 hover:bg-elevated/50"
            >
              <span class="flex size-9 shrink-0 items-center justify-center rounded-md" :class="action.tone">
                <UIcon :name="action.icon" class="size-5" />
              </span>
              {{ action.label }}
            </ULink>
            <UButton
              v-if="isAdmin && data.health.services?.find(s => s.id === 'ldap')?.status !== 'disabled'"
              icon="i-lucide-folder-sync"
              label="Synchroniser LDAP"
              color="neutral"
              variant="outline"
              class="justify-center"
              :loading="syncing"
              @click="syncLdap"
            />
          </div>
        </section>

        <!-- KPIs -->
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <DashboardKpiCard title="Emails envoyés (30 j)" :value="formatNumber(data.emails.sent)" icon="i-lucide-send" tone="bg-primary/10 text-primary" data-testid="kpi-sent">
            <DashboardSparkline :values="dailySent" />
            <template #footer>
              <span v-if="sentDelta !== null" :class="sentDelta >= 0 ? 'text-success' : 'text-error'" class="inline-flex items-center gap-1 font-medium">
                <UIcon :name="sentDelta >= 0 ? 'i-lucide-trending-up' : 'i-lucide-trending-down'" class="size-3.5" />
                {{ sentDelta > 0 ? '+' : '' }}{{ sentDelta }} %
              </span>
              <span>{{ sentDelta !== null ? 'vs 30 jours précédents' : `${data.emails.previousSent} sur les 30 jours précédents` }}</span>
            </template>
          </DashboardKpiCard>

          <DashboardKpiCard title="Taux de délivrabilité" :value="formatPercent(data.emails.deliveryRate)" icon="i-lucide-badge-check" tone="bg-success/10 text-success" data-testid="kpi-rate">
            <UProgress :model-value="data.emails.deliveryRate ?? 0" :color="rateColor" size="sm" class="mt-auto" />
            <template #footer>
              <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-success" />{{ data.emails.sent }} délivrés</span>
              <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-error" />{{ data.emails.failed }} échecs</span>
            </template>
          </DashboardKpiCard>

          <DashboardKpiCard title="File d’envoi" :value="formatNumber(data.emails.queued)" icon="i-lucide-hourglass" tone="bg-amber-500/10 text-amber-600 dark:text-amber-400" data-testid="kpi-queue">
            <p class="text-sm text-muted">
              {{ data.emails.oldestQueuedAt ? `Plus ancien : ${timeAgo(data.emails.oldestQueuedAt, now)}` : 'Aucun email en attente' }}
            </p>
            <template #footer>
              <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-primary" />{{ data.emails.withAttachments }} avec pièces jointes</span>
            </template>
          </DashboardKpiCard>

          <DashboardKpiCard
            v-if="data.users"
            title="Utilisateurs"
            :value="formatNumber(data.users.total)"
            icon="i-lucide-users"
            tone="bg-violet-500/10 text-violet-600 dark:text-violet-400"
            data-testid="kpi-users"
          >
            <p class="text-sm text-muted">
              {{ data.users.enabled }} actif(s)
            </p>
            <template #footer>
              <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-sky-500" />{{ data.users.local }} locaux</span>
              <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-violet-500" />{{ data.users.ldap }} LDAP</span>
            </template>
          </DashboardKpiCard>
          <DashboardKpiCard
            v-else
            title="Templates disponibles"
            :value="formatNumber(data.templates.total)"
            icon="i-lucide-layout-template"
            tone="bg-sky-500/10 text-sky-600 dark:text-sky-400"
          >
            <template #footer>
              <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-sky-500" />{{ data.templates.shared }} partagés</span>
            </template>
          </DashboardKpiCard>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
          <div class="flex min-w-0 flex-col gap-6 xl:col-span-2">
            <!-- Integrations -->
            <UCard v-if="data.applications" :ui="{ header: 'flex items-center justify-between gap-2' }">
              <template #header>
                <h2 class="font-semibold text-highlighted">
                  Intégrations
                </h2>
                <UButton label="Gérer" to="/applications" color="neutral" variant="ghost" size="sm" trailing-icon="i-lucide-arrow-right" />
              </template>
              <div v-if="data.applications.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-testid="integrations">
                <div
                  v-for="application in data.applications.slice(0, 6)"
                  :key="application.id"
                  class="flex flex-col gap-2 rounded-lg border border-default p-3"
                >
                  <div class="flex items-center justify-between gap-2">
                    <div class="flex min-w-0 items-center gap-2">
                      <UAvatar :text="application.name.slice(0, 2).toUpperCase()" size="sm" class="bg-primary/10 text-primary" />
                      <span class="truncate font-medium text-highlighted">{{ application.name }}</span>
                    </div>
                    <UBadge v-bind="applicationState(application)" variant="subtle" size="sm" />
                  </div>
                  <div class="flex items-center justify-between text-xs text-muted">
                    <span>{{ application.sent }} envoi(s){{ application.failed ? ` · ${application.failed} échec(s)` : '' }}</span>
                    <span>{{ timeAgo(application.lastUsedAt, now) }}</span>
                  </div>
                </div>
              </div>
              <p v-else class="text-sm text-muted">
                Aucune application : créez-en une pour embarquer le composeur dans vos outils.
              </p>
            </UCard>

            <!-- Latest emails -->
            <UCard :ui="{ header: 'flex items-center justify-between gap-2', body: 'p-0 sm:p-0' }">
              <template #header>
                <h2 class="font-semibold text-highlighted">
                  Derniers envois
                </h2>
                <UButton label="Tout voir" :to="isAdmin ? '/emails/all' : '/emails'" color="neutral" variant="ghost" size="sm" trailing-icon="i-lucide-arrow-right" />
              </template>
              <ul v-if="data.recentEmails.length" class="divide-y divide-default" data-testid="recent-emails">
                <li v-for="email in data.recentEmails" :key="email.id" class="flex items-center gap-3 px-4 py-3 sm:px-6">
                  <div class="min-w-0 flex-1">
                    <p class="truncate font-medium text-highlighted">
                      {{ email.subject }}
                    </p>
                    <p class="truncate text-xs text-muted">
                      À {{ email.to.join(', ') }}<template v-if="data.scope === 'platform'">
                        · par {{ email.sender }}
                      </template><template v-if="email.applicationName">
                        · via {{ email.applicationName }}
                      </template>
                    </p>
                  </div>
                  <span class="hidden text-xs text-muted sm:block">{{ timeAgo(email.createdAt, now) }}</span>
                  <UBadge v-bind="STATUS_BADGE[email.status]" variant="subtle" size="sm" />
                </li>
              </ul>
              <div v-else class="flex flex-col items-center gap-3 p-8 text-center text-sm text-muted">
                Aucun email envoyé pour le moment.
                <UButton label="Écrire un message" icon="i-lucide-send" to="/compose" size="sm" />
              </div>
            </UCard>

            <div class="grid gap-6" :class="data.health.services ? 'lg:grid-cols-2' : ''">
              <!-- Services -->
              <UCard v-if="data.health.services">
                <template #header>
                  <div class="flex items-center justify-between gap-2">
                    <h2 class="font-semibold text-highlighted">
                      État des services
                    </h2>
                    <UButton
                      label="Vérifier"
                      icon="i-lucide-activity"
                      size="xs"
                      color="neutral"
                      variant="ghost"
                      :loading="checking"
                      data-testid="check-services"
                      @click="checkServices"
                    />
                  </div>
                </template>
                <ul class="flex flex-col gap-4" data-testid="services">
                  <li v-for="service in data.health.services" :key="service.id" class="flex flex-col gap-1.5">
                    <div class="flex items-center justify-between gap-2">
                      <span class="inline-flex items-center gap-2 font-medium text-highlighted">
                        <span class="size-2 rounded-full" :class="HEALTH[service.status].dot" />
                        {{ service.label }}
                      </span>
                      <span class="text-xs text-muted">{{ serviceMetric(service) ?? HEALTH[service.status].label }}</span>
                    </div>
                    <p class="truncate text-xs text-muted" :title="service.detail">
                      {{ service.detail }}
                    </p>
                    <p v-if="service.check?.failingSince && service.status === 'down'" class="text-xs text-error">
                      En échec depuis {{ timeAgo(service.check.failingSince, now) }}
                    </p>
                    <ul v-if="service.items?.length" class="ms-4 flex flex-col gap-1" data-testid="mailbox-health">
                      <li v-for="mailbox in service.items" :key="mailbox.id" class="flex items-start gap-2 text-xs">
                        <span class="mt-1 size-1.5 shrink-0 rounded-full" :class="HEALTH[mailbox.status].dot" />
                        <span class="min-w-0">
                          <NuxtLink to="/mailboxes" class="font-medium text-default hover:underline">{{ mailbox.name }}</NuxtLink>
                          <span class="block truncate text-muted" :title="[checkLabel(mailbox.smtp, 'Envoi'), mailbox.imap ? checkLabel(mailbox.imap, 'IMAP') : ''].join(' · ')">
                            {{ checkLabel(mailbox.smtp, 'Envoi') }}<template v-if="mailbox.imap"> · {{ checkLabel(mailbox.imap, 'IMAP') }}</template>
                          </span>
                        </span>
                      </li>
                    </ul>
                    <UProgress
                      v-if="service.usagePercent !== undefined && service.usagePercent !== null"
                      :model-value="service.usagePercent"
                      :color="service.usagePercent >= 90 ? 'warning' : 'primary'"
                      size="xs"
                    />
                  </li>
                </ul>
              </UCard>

              <!-- Deliverability -->
              <UCard>
                <template #header>
                  <h2 class="font-semibold text-highlighted">
                    Délivrabilité ({{ data.days }} derniers jours)
                  </h2>
                </template>
                <div class="flex flex-col gap-4">
                  <div class="flex items-baseline gap-2">
                    <span class="text-3xl font-semibold tabular-nums text-highlighted">{{ formatPercent(data.emails.deliveryRate, 2) }}</span>
                    <span class="text-sm text-muted">des emails traités ont été délivrés</span>
                  </div>
                  <div class="flex h-16 items-end gap-[3px]" data-testid="delivery-bars">
                    <UTooltip v-for="bar in bars" :key="bar.date" :text="bar.tooltip">
                      <div class="min-w-0 flex-1 rounded-sm" :class="bar.color" :style="{ height: `${bar.height}%` }" />
                    </UTooltip>
                  </div>
                  <div class="flex justify-between text-xs text-muted">
                    <span>Il y a {{ data.days }} jours</span>
                    <span>Aujourd’hui</span>
                  </div>
                  <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                    <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-sm bg-success" />100 %</span>
                    <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-sm bg-warning" />≥ 90 %</span>
                    <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-sm bg-error" />&lt; 90 %</span>
                    <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-sm bg-accented" />Aucun envoi</span>
                  </div>
                </div>
              </UCard>
            </div>
          </div>

          <!-- Activity timeline -->
          <UCard class="xl:row-span-2" :ui="{ body: 'flex flex-col gap-5' }">
            <template #header>
              <h2 class="font-semibold text-highlighted">
                Activité récente
              </h2>
            </template>
            <section v-for="[day, events] in activityByDay" :key="day" data-testid="activity-day">
              <h3 class="mb-3 text-xs font-medium uppercase tracking-wide text-muted">
                {{ day }}
              </h3>
              <ol class="relative flex flex-col gap-4 border-l border-default pl-5">
                <li v-for="(event, index) in events" :key="index" class="relative">
                  <span class="absolute -left-[33px] flex size-6 items-center justify-center rounded-full ring-4 ring-(--ui-bg)" :class="ACTIVITY[event.type].color">
                    <UIcon :name="ACTIVITY[event.type].icon" class="size-3.5" />
                  </span>
                  <p class="text-xs text-muted">
                    {{ ACTIVITY[event.type].label }} · {{ time(event.at) }}
                  </p>
                  <ULink v-if="event.link" :to="event.link" class="block truncate font-medium text-highlighted hover:underline">
                    {{ event.title }}
                  </ULink>
                  <p v-else class="truncate font-medium text-highlighted">
                    {{ event.title }}
                  </p>
                  <p v-if="event.actor" class="truncate text-xs text-muted">
                    {{ event.actor }}
                  </p>
                </li>
              </ol>
            </section>
            <p v-if="!activityByDay.length" class="text-sm text-muted">
              Rien à signaler pour l’instant.
            </p>
          </UCard>
        </div>

        <!-- Shortcuts -->
        <section aria-labelledby="shortcuts">
          <h2 id="shortcuts" class="mb-2 text-sm font-medium text-muted">
            Services & raccourcis
          </h2>
          <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
            <ULink
              v-for="shortcut in shortcuts"
              :key="shortcut.label"
              :to="shortcut.to"
              :target="shortcut.external ? '_blank' : undefined"
              class="flex items-center gap-3 rounded-lg border border-default p-3 transition hover:border-primary/40 hover:bg-elevated/50"
            >
              <UIcon :name="shortcut.icon" class="size-5 shrink-0 text-primary" />
              <span class="min-w-0">
                <span class="flex items-center gap-1 font-medium text-highlighted">
                  {{ shortcut.label }}
                  <UIcon v-if="shortcut.external" name="i-lucide-external-link" class="size-3 text-muted" />
                </span>
                <span class="block truncate text-xs text-muted">{{ shortcut.description }}</span>
              </span>
            </ULink>
          </div>
        </section>
      </div>

      <div v-else-if="status === 'error'" class="flex flex-col items-center gap-3 p-10 text-muted">
        Le tableau de bord n’a pas pu être chargé.
        <UButton label="Réessayer" icon="i-lucide-refresh-cw" color="neutral" variant="outline" @click="refresh()" />
      </div>
    </template>
  </UDashboardPanel>
</template>
