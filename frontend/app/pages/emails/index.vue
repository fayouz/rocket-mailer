<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { Attachment, Email, EmailStatus } from '~/types/api'

useHead({ title: 'Envoyés · Rocket Mailer' })

const api = useApi()
const toast = useToast()
const UBadge = resolveComponent('UBadge')
const UIcon = resolveComponent('UIcon')

const { data: emails, status, refresh } = await useAsyncData('emails', () => api<Email[]>('/api/emails', { query: { itemsPerPage: 100 } }), { default: () => [] })

const statusBadge: Record<EmailStatus, { label: string, color: 'neutral' | 'success' | 'error' }> = {
  queued: { label: 'En file', color: 'neutral' },
  sent: { label: 'Envoyé', color: 'success' },
  failed: { label: 'Échec', color: 'error' },
}

const columns: TableColumn<Email>[] = [
  { accessorKey: 'createdAt', header: 'Date', cell: ({ row }) => formatDate(row.original.createdAt) },
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
]

const selected = ref<Email | null>(null)
const detailOpen = computed({
  get: () => selected.value !== null,
  set: (value: boolean) => {
    if (!value) selected.value = null
  },
})

async function download(attachment: Attachment) {
  try {
    const blob = await api<Blob>(`/api/attachments/${attachment.id}/download`, { responseType: 'blob' })
    const url = URL.createObjectURL(blob)
    const link = Object.assign(document.createElement('a'), { href: url, download: attachment.filename })
    link.click()
    URL.revokeObjectURL(url)
  }
  catch (error) {
    toast.add({ title: 'Téléchargement impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

async function open(email: Email) {
  try {
    selected.value = await api<Email>(`/api/emails/${email.id}`)
  }
  catch (error) {
    toast.add({ title: 'Erreur', description: apiErrorMessage(error), color: 'error' })
  }
}
</script>

<template>
  <UDashboardPanel id="emails">
    <template #header>
      <UDashboardNavbar title="Messages envoyés">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-refresh-cw" color="neutral" variant="ghost" aria-label="Rafraîchir" @click="refresh()" />
          <UButton icon="i-lucide-send" label="Nouveau message" to="/compose" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UTable
        :data="emails"
        :columns="columns"
        :loading="status === 'pending'"
        empty="Aucun message envoyé pour le moment."
        class="cursor-pointer"
        @select="(_e: Event, row: { original: Email }) => open(row.original)"
      />

      <USlideover v-model:open="detailOpen" :title="selected?.subject" :ui="{ content: 'max-w-3xl' }">
        <template #body>
          <div v-if="selected" class="flex h-full flex-col gap-4">
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
              <dt class="text-muted">
                De
              </dt><dd>{{ selected.from ?? `${selected.sender.displayName} <${selected.sender.email}>` }}</dd>
              <template v-if="selected.from && !selected.from.includes(selected.sender.email)">
                <dt class="text-muted">
                  Par
                </dt><dd>{{ selected.sender.displayName }} &lt;{{ selected.sender.email }}&gt;</dd>
              </template>
              <dt class="text-muted">
                À
              </dt><dd>{{ selected.to.join(', ') }}</dd>
              <template v-if="selected.cc?.length">
                <dt class="text-muted">
                  Cc
                </dt><dd>{{ selected.cc.join(', ') }}</dd>
              </template>
              <template v-if="selected.bcc?.length">
                <dt class="text-muted">
                  Cci
                </dt><dd>{{ selected.bcc.join(', ') }}</dd>
              </template>
              <dt class="text-muted">
                Envoyé
              </dt><dd>{{ formatDate(selected.sentAt) }}</dd>
              <dt class="text-muted">
                Via
              </dt><dd>{{ selected.applicationName ?? 'Rocket Mailer' }}</dd>
            </dl>
            <div v-if="selected.attachments?.length" class="flex flex-wrap gap-2">
              <UButton
                v-for="attachment in selected.attachments"
                :key="attachment.id"
                icon="i-lucide-paperclip"
                :label="`${attachment.filename} (${formatSize(attachment.size)})`"
                color="neutral"
                variant="outline"
                size="sm"
                @click="download(attachment)"
              />
            </div>
            <UAlert v-if="selected.errorMessage" color="error" variant="subtle" :description="selected.errorMessage" />
            <!-- Email HTML is untrusted: render it in a sandbox without scripts or same-origin access. -->
            <iframe
              :srcdoc="selected.htmlBody"
              sandbox=""
              referrerpolicy="no-referrer"
              title="Contenu du message"
              class="min-h-[480px] w-full flex-1 rounded-md border border-default bg-white"
            />
          </div>
        </template>
      </USlideover>
    </template>
  </UDashboardPanel>
</template>
