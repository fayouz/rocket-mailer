<script setup lang="ts">
import type { ConversationSummary, InboxMailbox } from '~/types/api'

useHead({ title: 'Boîtes partagées · Rocket Mailer' })

const api = useApi()
const toast = useToast()
const route = useRoute()
const router = useRouter()

const { data: mailboxes, refresh: refreshMailboxes } = await useAsyncData('inbox-mailboxes', () => api<InboxMailbox[]>('/api/inbox/mailboxes'), { default: () => [] })

// Selection in the URL: ?mailbox=…&c=… (shareable, back button).
const mailboxId = computed(() => (route.query.mailbox as string | undefined) ?? mailboxes.value[0]?.id)
const conversationId = computed(() => route.query.c as string | undefined)
const mailbox = computed(() => mailboxes.value.find(m => m.id === mailboxId.value))

function select(query: { mailbox?: string, c?: string | undefined }) {
  router.replace({ query: { ...route.query, ...query } })
}

// --- Conversations ----------------------------------------------------------------------------

const status = ref<'open' | 'closed' | 'all'>('open')
const mine = ref(false)
const search = ref('')
const q = ref('')
let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, (value) => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => (q.value = value.trim()), 300)
})

const { data: conversations, status: listStatus, refresh } = await useAsyncData(
  () => `inbox-conversations-${mailboxId.value}`,
  () => mailboxId.value
    ? api<ConversationSummary[]>(`/api/inbox/mailboxes/${mailboxId.value}/conversations`, {
        query: { status: status.value === 'all' ? undefined : status.value, mine: mine.value ? 1 : undefined, q: q.value || undefined },
      })
    : Promise.resolve([]),
  { default: () => [], watch: [mailboxId, status, mine, q] },
)

// useAsyncData data is shallow: the list is replaced, not mutated.
function patchRow(id: string, patch: Partial<ConversationSummary>) {
  conversations.value = conversations.value
    .map(c => c.id === id ? { ...c, ...patch } : c)
    // Closed or reopened: leaves a filtered list.
    .filter(c => c.id !== id || status.value === 'all' || c.status === status.value)
}

function open(conversation: ConversationSummary) {
  patchRow(conversation.id, { unread: false })
  select({ c: conversation.id })
  refreshMailboxes()
}

function changed(summary: ConversationSummary) {
  if (!conversations.value.some(c => c.id === summary.id)) refresh()
  patchRow(summary.id, summary)
  refreshMailboxes()
}

const fetching = ref(false)
async function fetchNow() {
  if (!mailbox.value) return
  fetching.value = true
  try {
    const { fetched } = await api<{ fetched: number }>(`/api/inbox/mailboxes/${mailbox.value.id}/fetch`, { method: 'POST' })
    toast.add({ title: fetched ? `${fetched} nouveau${fetched > 1 ? 'x' : ''} message${fetched > 1 ? 's' : ''}` : 'Aucun nouveau message', color: 'success', icon: 'i-lucide-inbox' })
    await Promise.all([refresh(), refreshMailboxes()])
  }
  catch (error) {
    toast.add({ title: 'Relève impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    fetching.value = false
  }
}

// Newly fetched messages (worker) appear without reloading.
let poll: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  poll = setInterval(() => {
    if (document.visibilityState === 'visible') {
      refresh()
      refreshMailboxes()
    }
  }, 60_000)
})
onBeforeUnmount(() => {
  clearInterval(poll)
  clearTimeout(searchTimer)
})

const membersOpen = ref(false)

const STATUS_ITEMS = [
  { label: 'Ouvertes', value: 'open' },
  { label: 'Fermées', value: 'closed' },
  { label: 'Toutes', value: 'all' },
]
</script>

<template>
  <div class="flex min-w-0 flex-1">
    <UDashboardPanel id="inbox-list" :default-size="32" :min-size="24" :max-size="45" resizable :class="conversationId ? 'max-lg:hidden' : ''">
      <template #header>
        <UDashboardNavbar title="Boîtes partagées">
          <template #leading>
            <UDashboardSidebarCollapse />
          </template>
          <template #right>
            <UTooltip v-if="mailbox" text="Relever les nouveaux messages">
              <UButton icon="i-lucide-refresh-cw" color="neutral" variant="ghost" aria-label="Relever" :loading="fetching" data-testid="inbox-fetch" @click="fetchNow" />
            </UTooltip>
            <UTooltip v-if="mailbox?.role === 'manager'" text="Membres">
              <UButton icon="i-lucide-users" color="neutral" variant="ghost" aria-label="Membres" @click="membersOpen = true" />
            </UTooltip>
          </template>
        </UDashboardNavbar>

        <UDashboardToolbar v-if="mailboxes.length">
          <div class="flex w-full flex-col gap-2 py-2">
            <div class="flex flex-wrap gap-1" data-testid="inbox-mailboxes">
              <UButton
                v-for="item in mailboxes"
                :key="item.id"
                :label="item.name"
                :color="item.id === mailboxId ? 'primary' : 'neutral'"
                :variant="item.id === mailboxId ? 'soft' : 'ghost'"
                size="sm"
                icon="i-lucide-inbox"
                @click="select({ mailbox: item.id, c: undefined })"
              >
                <template #trailing>
                  <UBadge v-if="item.unread" :label="String(item.unread)" size="sm" color="primary" variant="solid" />
                </template>
              </UButton>
            </div>
            <UInput v-model="search" icon="i-lucide-search" placeholder="Rechercher (objet, expéditeur, texte)" size="sm" class="w-full" />
            <div class="flex items-center justify-between gap-2">
              <UTabs v-model="status" :items="STATUS_ITEMS" :content="false" size="xs" />
              <USwitch v-model="mine" label="Les miennes" size="sm" />
            </div>
          </div>
        </UDashboardToolbar>
      </template>

      <template #body>
        <UEmpty
          v-if="!mailboxes.length"
          icon="i-lucide-inbox"
          title="Aucune boîte partagée"
          description="Un administrateur peut activer la réception sur une boîte d’envoi et vous en rendre membre."
        />
        <template v-else>
          <UAlert v-if="mailbox?.error" color="error" variant="subtle" icon="i-lucide-triangle-alert" title="Dernière relève en erreur" :description="mailbox.error" class="mb-2" />
          <p v-if="listStatus !== 'pending' && !conversations.length" class="p-4 text-center text-sm text-muted">
            Aucune conversation.
          </p>
          <ul class="-mx-4 divide-y divide-default sm:-mx-6" data-testid="inbox-conversations">
            <li v-for="conversation in conversations" :key="conversation.id">
              <button
                type="button"
                class="w-full px-4 py-3 text-left transition-colors hover:bg-elevated/60 sm:px-6"
                :class="conversation.id === conversationId ? 'bg-elevated' : ''"
                @click="open(conversation)"
              >
                <div class="flex items-center gap-2">
                  <span v-if="conversation.unread" class="size-2 shrink-0 rounded-full bg-primary" aria-label="Non lu" />
                  <span class="flex-1 truncate text-sm" :class="conversation.unread ? 'font-semibold' : ''">{{ conversation.lastFrom }}</span>
                  <span v-if="conversation.messageCount > 1" class="text-xs text-muted">{{ conversation.messageCount }}</span>
                  <time class="shrink-0 text-xs text-muted">{{ formatDate(conversation.lastActivityAt) }}</time>
                </div>
                <p class="truncate text-sm" :class="conversation.unread ? 'font-medium text-highlighted' : ''">
                  {{ conversation.subject }}
                </p>
                <div class="flex items-center gap-2">
                  <p class="flex-1 truncate text-xs text-muted">
                    {{ conversation.snippet }}
                  </p>
                  <UBadge v-if="conversation.status === 'closed'" label="Fermée" size="sm" color="neutral" variant="subtle" />
                  <UBadge v-if="conversation.assignee" :label="conversation.assignee.displayName" size="sm" color="neutral" variant="outline" icon="i-lucide-user-round" />
                </div>
              </button>
            </li>
          </ul>
        </template>
      </template>
    </UDashboardPanel>

    <UDashboardPanel id="inbox-thread" :class="conversationId ? '' : 'max-lg:hidden'">
      <template #body>
        <ConversationThread
          v-if="conversationId"
          :key="conversationId"
          :conversation-id="conversationId"
          class="-m-4 sm:-m-6"
          @changed="changed"
          @close="select({ c: undefined })"
        />
        <UEmpty v-else icon="i-lucide-mail-open" title="Sélectionnez une conversation" class="flex-1" />
      </template>
    </UDashboardPanel>

    <MailboxMembersModal :mailbox="membersOpen && mailbox ? { id: mailbox.id, name: mailbox.name } : null" @close="membersOpen = false" />
  </div>
</template>
