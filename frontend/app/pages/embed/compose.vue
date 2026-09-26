<script setup lang="ts">
import type { Email, EmailDraft } from '~/types/api'
import type { EmbedContext } from '#rocket/composables/useEmbedBridge'

/**
 * Compose UI embedded in a third-party application (see public/embed.js).
 * Authentication: an embed token minted by the application for one user, handed over by the host page
 * (useEmbedBridge of the layer); the host may then prefill the draft ("draft" message).
 */
definePageMeta({ layout: 'bare' })
useHead({ title: 'Rocket Mailer' })

const route = useRoute()
const bridge = useEmbedBridge()

const state = ref<'loading' | 'ready' | 'error'>('loading')
const errorMessage = ref('')
const context = ref<EmbedContext | null>(null)
const composer = useTemplateRef<{ applyDraft: (draft: Partial<EmailDraft>) => void }>('composer')
let stopDraftListener: (() => void) | undefined

onMounted(async () => {
  try {
    context.value = await bridge.connect(typeof route.query.app === 'string' ? route.query.app : '')
    stopDraftListener = bridge.onHostMessage('draft', message => composer.value?.applyDraft(message.draft as Partial<EmailDraft>))
    state.value = 'ready'
  }
  catch (error) {
    errorMessage.value = apiErrorMessage(error)
    state.value = 'error'
  }
})

onBeforeUnmount(() => stopDraftListener?.())

function onSent(email: Email) {
  bridge.notify('sent', { email: { id: email.id, subject: email.subject, to: email.to, status: email.status } })
}
</script>

<template>
  <div class="p-4">
    <div v-if="state === 'loading'" class="flex items-center gap-2 py-10 text-muted">
      <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin" /> Chargement…
    </div>

    <UAlert
      v-else-if="state === 'error'"
      color="error"
      variant="subtle"
      icon="i-lucide-shield-alert"
      title="Composeur indisponible"
      :description="errorMessage"
    />

    <template v-else>
      <p class="mb-3 text-sm text-muted">
        Envoi en tant que <strong>{{ context?.user.displayName }}</strong> · via {{ context?.application.name }}
      </p>
      <EmailComposer ref="composer" @sent="onSent" />
    </template>
  </div>
</template>
