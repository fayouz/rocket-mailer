<script setup lang="ts">
import type { ConversationDetail, ConversationSummary, InboundItem } from '~/types/api'

/** A conversation of a shared inbox: messages, replies and notes; reply, note, assign, close. */
const props = defineProps<{ conversationId: string }>()
const emit = defineEmits<{ changed: [ConversationSummary], close: [] }>()

const api = useApi()
const toast = useToast()
const auth = useAuth()

const NOBODY = 'nobody'
const images = ref(false)
// The parent keys this component by conversation: one instance per conversation.
const { data: conversation, refresh, status } = await useAsyncData(
  `conversation-${props.conversationId}`,
  () => api<ConversationDetail>(`/api/inbox/conversations/${props.conversationId}`, { query: images.value ? { images: 1 } : {} }),
  { watch: [images] },
)

const hasRemoteImages = computed(() => conversation.value?.items.some(i => i.type === 'inbound' && i.hasRemoteImages) ?? false)
const assigneeItems = computed(() => [
  { label: 'Non assignée', value: NOBODY },
  ...(conversation.value?.members ?? []).map(m => ({ label: m.id === auth.me.value?.user?.id ? `${m.displayName} (moi)` : m.displayName, value: m.id })),
])

async function update(body: { status?: 'open' | 'closed', assignee?: string | null }) {
  if (!conversation.value) return
  try {
    const summary = await api<ConversationSummary>(`/api/inbox/conversations/${conversation.value.id}`, { method: 'PATCH', body })
    // useAsyncData data is shallow: replaced, not mutated.
    conversation.value = { ...conversation.value, ...summary }
    emit('changed', summary)
  }
  catch (error) {
    toast.add({ title: 'Mise à jour impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

async function markUnread() {
  if (!conversation.value) return
  await api(`/api/inbox/conversations/${conversation.value.id}/unread`, { method: 'POST' })
  emit('changed', { ...conversation.value, unread: true })
  emit('close')
}

// --- Reply and note -------------------------------------------------------------------------

const mode = ref<'reply' | 'note'>('reply')
const replyHtml = ref('')
const noteText = ref('')
const sending = ref(false)
const lastInbound = computed(() => [...(conversation.value?.items ?? [])].reverse().find((i): i is InboundItem => i.type === 'inbound'))
const replyTo = computed(() => lastInbound.value?.replyTo ?? lastInbound.value?.from ?? '')

async function send() {
  if (!conversation.value) return
  sending.value = true
  try {
    if (mode.value === 'reply') {
      await api(`/api/inbox/conversations/${conversation.value.id}/reply`, { method: 'POST', body: { htmlBody: replyHtml.value } })
      replyHtml.value = ''
      toast.add({ title: 'Réponse envoyée', color: 'success', icon: 'i-lucide-send' })
    }
    else {
      await api(`/api/inbox/conversations/${conversation.value.id}/notes`, { method: 'POST', body: { body: noteText.value } })
      noteText.value = ''
    }
    await refresh()
    if (conversation.value) emit('changed', conversation.value)
  }
  catch (error) {
    toast.add({ title: mode.value === 'reply' ? 'Envoi impossible' : 'Note impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    sending.value = false
  }
}

async function download(attachment: InboundItem['attachments'][number]) {
  try {
    const blob = await api<Blob>(`/api/inbox/attachments/${attachment.id}`, { responseType: 'blob' })
    const url = URL.createObjectURL(blob)
    Object.assign(document.createElement('a'), { href: url, download: attachment.filename }).click()
    URL.revokeObjectURL(url)
  }
  catch (error) {
    toast.add({ title: 'Téléchargement impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

const REPLY_STATUS = { queued: ['En file', 'warning'], sent: ['Envoyé', 'success'], failed: ['Échec', 'error'] } as const
</script>

<template>
  <div v-if="conversation" class="flex min-h-0 flex-1 flex-col" data-testid="conversation-thread">
    <div class="flex flex-wrap items-center gap-2 border-b border-default px-4 py-3">
      <UButton icon="i-lucide-arrow-left" color="neutral" variant="ghost" class="lg:hidden" aria-label="Retour" @click="emit('close')" />
      <div class="min-w-0 flex-1">
        <h2 class="truncate font-semibold">
          {{ conversation.subject }}
        </h2>
        <p class="truncate text-xs text-muted">
          {{ conversation.participants.join(', ') || conversation.mailbox.email }}
        </p>
      </div>
      <USelect
        :model-value="conversation.assignee?.id ?? NOBODY"
        :items="assigneeItems"
        icon="i-lucide-user-round"
        class="w-48"
        aria-label="Assigner"
        data-testid="conversation-assignee"
        @update:model-value="(value: string) => update({ assignee: value === NOBODY ? null : value })"
      />
      <UButton
        v-if="conversation.status === 'open'"
        icon="i-lucide-circle-check"
        label="Fermer"
        color="neutral"
        variant="outline"
        data-testid="conversation-close"
        @click="update({ status: 'closed' })"
      />
      <UButton v-else icon="i-lucide-rotate-ccw" label="Rouvrir" color="neutral" variant="outline" @click="update({ status: 'open' })" />
      <UTooltip text="Marquer comme non lu">
        <UButton icon="i-lucide-mail" color="neutral" variant="ghost" aria-label="Marquer comme non lu" @click="markUnread" />
      </UTooltip>
    </div>

    <div class="flex-1 space-y-4 overflow-y-auto p-4">
      <UAlert
        v-if="hasRemoteImages && !images"
        color="neutral"
        variant="subtle"
        icon="i-lucide-image-off"
        description="Les images distantes sont bloquées : elles permettent à l’expéditeur de savoir que le message a été lu."
        :actions="[{ label: 'Afficher les images', color: 'neutral', variant: 'outline', onClick: () => { images = true } }]"
        data-testid="show-images"
      />

      <template v-for="item in conversation.items" :key="item.id">
        <UCard v-if="item.type === 'inbound'" :ui="{ body: 'space-y-3' }">
          <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
            <p>
              <span class="font-medium">{{ item.fromName ?? item.from }}</span>
              <span v-if="item.fromName" class="text-muted"> &lt;{{ item.from }}&gt;</span>
            </p>
            <time class="text-xs text-muted">{{ formatDate(item.at) }}</time>
          </div>
          <p v-if="item.cc.length" class="text-xs text-muted">
            Cc : {{ item.cc.join(', ') }}
          </p>
          <InboxMessageFrame v-if="item.html" :html="item.html" :images="images" />
          <p v-else class="text-sm whitespace-pre-wrap">
            {{ item.text }}
          </p>
          <div v-if="item.attachments.length" class="flex flex-wrap gap-2">
            <UButton
              v-for="attachment in item.attachments"
              :key="attachment.id"
              icon="i-lucide-paperclip"
              :label="`${attachment.filename} (${formatSize(attachment.size)})`"
              color="neutral"
              variant="outline"
              size="sm"
              @click="download(attachment)"
            />
          </div>
        </UCard>

        <UCard v-else-if="item.type === 'reply'" class="ml-6 lg:ml-12" :ui="{ root: 'ring-primary/40', body: 'space-y-3' }">
          <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
            <p>
              <UIcon name="i-lucide-reply" class="mr-1 align-middle text-primary" />
              <span class="font-medium">{{ item.author?.displayName ?? 'Réponse' }}</span>
              <span class="text-muted"> à {{ item.to.join(', ') }}</span>
            </p>
            <span class="flex items-center gap-2">
              <UBadge :label="REPLY_STATUS[item.status][0]" :color="REPLY_STATUS[item.status][1]" variant="subtle" size="sm" :title="item.error ?? undefined" />
              <time class="text-xs text-muted">{{ formatDate(item.at) }}</time>
            </span>
          </div>
          <InboxMessageFrame :html="item.html" :images="false" />
        </UCard>

        <div v-else class="ml-6 rounded-md border border-dashed border-warning/50 bg-warning/5 p-3 text-sm lg:ml-12">
          <p class="mb-1 flex items-center justify-between gap-2 text-xs text-muted">
            <span><UIcon name="i-lucide-sticky-note" class="mr-1 align-middle text-warning" />Note interne · {{ item.author?.displayName ?? '—' }}</span>
            <time>{{ formatDate(item.at) }}</time>
          </p>
          <p class="whitespace-pre-wrap">
            {{ item.body }}
          </p>
        </div>
      </template>
    </div>

    <div class="space-y-2 border-t border-default p-4">
      <div class="flex items-center justify-between gap-2">
        <UTabs
          v-model="mode"
          :items="[{ label: 'Répondre', value: 'reply', icon: 'i-lucide-reply' }, { label: 'Note interne', value: 'note', icon: 'i-lucide-sticky-note' }]"
          :content="false"
          size="sm"
        />
        <p v-if="mode === 'reply'" class="truncate text-xs text-muted">
          De {{ conversation.mailbox.email }} à {{ replyTo }}
        </p>
        <p v-else class="text-xs text-muted">
          Visible uniquement par les membres
        </p>
      </div>
      <RichTextEditor v-if="mode === 'reply'" v-model="replyHtml" :disabled="sending" />
      <UTextarea v-else v-model="noteText" :rows="3" autoresize placeholder="Contexte, suite à donner…" class="w-full" :disabled="sending" />
      <div class="flex justify-end">
        <UButton
          :icon="mode === 'reply' ? 'i-lucide-send' : 'i-lucide-plus'"
          :label="mode === 'reply' ? 'Envoyer la réponse' : 'Ajouter la note'"
          :loading="sending"
          :disabled="mode === 'reply' ? !replyHtml.replace(/<[^>]*>/g, '').trim() : !noteText.trim()"
          data-testid="conversation-send"
          @click="send"
        />
      </div>
    </div>
  </div>
  <div v-else class="flex flex-1 items-center justify-center text-muted">
    <UIcon v-if="status === 'pending'" name="i-lucide-loader-circle" class="size-6 animate-spin" />
  </div>
</template>
