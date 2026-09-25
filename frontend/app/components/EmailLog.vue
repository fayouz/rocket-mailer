<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { Application, Collection, Email, EmailStatus, User } from '~/types/api'

/**
 * Paginated, filterable list of sent emails.
 * "mine": the current user's emails; "all": every email of the platform (admins).
 */
const props = defineProps<{ scope: 'mine' | 'all' }>()

const api = useApi()
const auth = useAuth()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const UBadge = resolveComponent('UBadge')
const UIcon = resolveComponent('UIcon')

const PAGE_SIZE = 25

// Filters live in the URL: shareable, and kept on reload / back navigation.
const query = (key: string) => (typeof route.query[key] === 'string' ? route.query[key] as string : '')
const filters = reactive({
  q: query('q'),
  status: query('status') || 'all',
  application: query('application') || 'all',
  sender: query('sender') || 'all',
  after: query('after'),
  before: query('before'),
})
const page = ref(Number(query('page')) || 1)

const debouncedQ = ref(filters.q)
let debounce: ReturnType<typeof setTimeout> | undefined
watch(() => filters.q, (value) => {
  clearTimeout(debounce)
  debounce = setTimeout(() => (debouncedQ.value = value), 300)
})

const params = computed(() => {
  const p: Record<string, string | number> = { page: page.value, itemsPerPage: PAGE_SIZE }
  if (debouncedQ.value.trim()) p.q = debouncedQ.value.trim()
  if (filters.status !== 'all') p.status = filters.status
  if (filters.application !== 'all') p.application = filters.application
  const sender = props.scope === 'mine' ? auth.me.value?.user?.id : filters.sender !== 'all' ? filters.sender : undefined
  if (sender) p.sender = sender
  if (filters.after) p['createdAt[after]'] = filters.after
  // "before" is inclusive of the whole selected day.
  if (filters.before) p['createdAt[strictly_before]'] = nextDay(filters.before)
  return p
})

function nextDay(date: string): string {
  const d = new Date(`${date}T00:00:00Z`)
  d.setUTCDate(d.getUTCDate() + 1)
  return d.toISOString().slice(0, 10)
}

// Back to page 1 when a filter changes, and mirror the filters in the URL.
watch([debouncedQ, () => filters.status, () => filters.application, () => filters.sender, () => filters.after, () => filters.before], () => {
  page.value = 1
})
watch([debouncedQ, page, () => ({ ...filters })], () => {
  const q: Record<string, string> = {}
  if (debouncedQ.value) q.q = debouncedQ.value
  for (const key of ['status', 'application', 'sender'] as const) {
    if (filters[key] !== 'all') q[key] = filters[key]
  }
  if (filters.after) q.after = filters.after
  if (filters.before) q.before = filters.before
  if (page.value > 1) q.page = String(page.value)
  router.replace({ query: q })
}, { deep: true })

const { data, status, refresh } = useAsyncData(
  `emails-${props.scope}`,
  () => api<Collection<Email>>('/api/emails', { query: params.value, headers: { Accept: 'application/ld+json' } }),
  { watch: [params], default: () => ({ member: [], totalItems: 0 }) },
)

// Filter choices (admins only: regular users cannot list users nor applications).
const { data: applications } = useAsyncData('email-log-applications', () => auth.isAdmin.value
  ? api<Application[]>('/api/applications', { query: { itemsPerPage: 200 } })
  : Promise.resolve([] as Application[]), { default: () => [] })
const { data: users } = useAsyncData('email-log-users', () => auth.isAdmin.value && props.scope === 'all'
  ? api<User[]>('/api/users', { query: { itemsPerPage: 500 } })
  : Promise.resolve([] as User[]), { default: () => [] })

const statusItems = [
  { label: 'Tous les statuts', value: 'all' },
  { label: 'Envoyés', value: 'sent' },
  { label: 'Échecs', value: 'failed' },
  { label: 'En file', value: 'queued' },
]
const applicationItems = computed(() => [
  { label: 'Toutes les applications', value: 'all' },
  ...applications.value.map(a => ({ label: a.name, value: a.id })),
])
const senderItems = computed(() => [
  { label: 'Tous les expéditeurs', value: 'all' },
  ...users.value.map(u => ({ label: `${u.displayName} (${u.email})`, value: u.id })),
])

const hasFilters = computed(() => Boolean(filters.q || filters.status !== 'all' || filters.application !== 'all' || filters.sender !== 'all' || filters.after || filters.before))
function resetFilters() {
  Object.assign(filters, { q: '', status: 'all', application: 'all', sender: 'all', after: '', before: '' })
  debouncedQ.value = ''
}

const statusBadge: Record<EmailStatus, { label: string, color: 'neutral' | 'success' | 'error' }> = {
  queued: { label: 'En file', color: 'neutral' },
  sent: { label: 'Envoyé', color: 'success' },
  failed: { label: 'Échec', color: 'error' },
}

const columns = computed<TableColumn<Email>[]>(() => [
  { accessorKey: 'createdAt', header: 'Date', cell: ({ row }) => formatDate(row.original.createdAt) },
  ...(props.scope === 'all'
    ? [{ id: 'sender', header: 'Expéditeur', cell: ({ row }: { row: { original: Email } }) => row.original.sender.displayName }]
    : []),
  {
    id: 'from',
    header: 'De',
    cell: ({ row }) => h('span', { class: 'inline-flex items-center gap-1.5' }, [
      row.original.mailboxName ? h(UIcon, { 'name': 'i-lucide-mailbox', 'class': 'size-4 text-muted', 'aria-label': `Boîte « ${row.original.mailboxName} »` }) : null,
      row.original.from ?? row.original.sender.email,
    ]),
  },
  { accessorKey: 'to', header: 'Destinataires', cell: ({ row }) => row.original.to.join(', ') },
  {
    accessorKey: 'subject',
    header: 'Objet',
    cell: ({ row }) => h('span', { class: 'inline-flex items-center gap-1.5' }, [
      row.original.subject,
      row.original.attachmentCount
        ? h(UIcon, { 'name': 'i-lucide-paperclip', 'class': 'size-4 text-muted', 'aria-label': `${row.original.attachmentCount} pièce(s) jointe(s)` })
        : null,
    ]),
  },
  { accessorKey: 'applicationName', header: 'Via', cell: ({ row }) => row.original.applicationName ?? 'Rocket Mailer' },
  {
    accessorKey: 'status',
    header: 'Statut',
    cell: ({ row }) => h(UBadge, { variant: 'subtle', ...statusBadge[row.original.status] }),
  },
])

const selected = ref<Email | null>(null)
async function open(email: Email) {
  try {
    selected.value = await api<Email>(`/api/emails/${email.id}`)
  }
  catch (error) {
    toast.add({ title: 'Erreur', description: apiErrorMessage(error), color: 'error' })
  }
}

defineExpose({ refresh })
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center gap-2" data-testid="email-filters">
      <UInput v-model="filters.q" icon="i-lucide-search" placeholder="Objet, destinataire, adresse d’envoi…" class="w-full sm:w-72" aria-label="Rechercher" />
      <USelect v-model="filters.status" :items="statusItems" class="w-40" aria-label="Statut" />
      <USelect v-if="applications.length" v-model="filters.application" :items="applicationItems" class="w-52" aria-label="Application" />
      <USelectMenu
        v-if="scope === 'all' && users.length"
        v-model="filters.sender"
        :items="senderItems"
        value-key="value"
        class="w-60"
        aria-label="Expéditeur"
      />
      <div class="flex items-center gap-1 text-sm text-muted">
        <UInput v-model="filters.after" type="date" aria-label="Depuis le" class="w-40" />
        <span>→</span>
        <UInput v-model="filters.before" type="date" aria-label="Jusqu’au" class="w-40" />
      </div>
      <UButton v-if="hasFilters" label="Réinitialiser" icon="i-lucide-x" color="neutral" variant="ghost" @click="resetFilters" />
    </div>

    <UTable
      :data="data.member"
      :columns="columns"
      :loading="status === 'pending'"
      :empty="hasFilters ? 'Aucun message ne correspond à ces filtres.' : 'Aucun message envoyé pour le moment.'"
      class="cursor-pointer"
      @select="(_e: Event, row: { original: Email }) => open(row.original)"
    />

    <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-muted">
      <span data-testid="email-total">{{ status === 'pending' && !data.member.length ? 'Chargement…' : `${formatNumber(data.totalItems)} message(s)` }}</span>
      <UPagination v-if="data.totalItems > PAGE_SIZE" v-model:page="page" :total="data.totalItems" :items-per-page="PAGE_SIZE" />
    </div>

    <EmailDetail v-model="selected" />
  </div>
</template>
