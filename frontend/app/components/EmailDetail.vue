<script setup lang="ts">
import type { Attachment, Email } from '~/types/api'

/** Slideover with a sent email: headers, attachments and the HTML body in a sandbox. */
const email = defineModel<Email | null>({ required: true })

const api = useApi()
const toast = useToast()

const open = computed({
  get: () => email.value !== null,
  set: (value: boolean) => {
    if (!value) email.value = null
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
</script>

<template>
  <USlideover v-model:open="open" :title="email?.subject" :ui="{ content: 'max-w-3xl' }">
    <template #body>
      <div v-if="email" class="flex h-full flex-col gap-4">
        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
          <dt class="text-muted">
            De
          </dt><dd>{{ email.from ?? `${email.sender.displayName} <${email.sender.email}>` }}</dd>
          <template v-if="email.from && !email.from.includes(email.sender.email)">
            <dt class="text-muted">
              Par
            </dt><dd>{{ email.sender.displayName }} &lt;{{ email.sender.email }}&gt;</dd>
          </template>
          <dt class="text-muted">
            À
          </dt><dd>{{ email.to.join(', ') }}</dd>
          <template v-if="email.cc?.length">
            <dt class="text-muted">
              Cc
            </dt><dd>{{ email.cc.join(', ') }}</dd>
          </template>
          <template v-if="email.bcc?.length">
            <dt class="text-muted">
              Cci
            </dt><dd>{{ email.bcc.join(', ') }}</dd>
          </template>
          <dt class="text-muted">
            Envoyé
          </dt><dd>{{ formatDate(email.sentAt) }}</dd>
          <dt class="text-muted">
            Via
          </dt><dd>{{ email.applicationName ?? 'Rocket Mailer' }}</dd>
        </dl>
        <div v-if="email.attachments?.length" class="flex flex-wrap gap-2">
          <UButton
            v-for="attachment in email.attachments"
            :key="attachment.id"
            icon="i-lucide-paperclip"
            :label="`${attachment.filename} (${formatSize(attachment.size)})`"
            color="neutral"
            variant="outline"
            size="sm"
            @click="download(attachment)"
          />
        </div>
        <UAlert v-if="email.errorMessage" color="error" variant="subtle" :description="email.errorMessage" />
        <!-- Email HTML is untrusted: render it in a sandbox without scripts or same-origin access. -->
        <iframe
          :srcdoc="email.htmlBody"
          sandbox=""
          referrerpolicy="no-referrer"
          title="Contenu du message"
          class="min-h-[480px] w-full flex-1 rounded-md border border-default bg-white"
        />
      </div>
    </template>
  </USlideover>
</template>
