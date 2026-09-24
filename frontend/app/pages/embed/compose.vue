<script setup lang="ts">
import type { Email, EmailDraft, UserSummary } from '~/types/api'

/**
 * Compose UI embedded in a third-party application (see public/embed.js).
 * Authentication: an embed token minted by the application for one user, received either
 * through postMessage from a verified parent origin or in the URL fragment (never sent to servers).
 */
definePageMeta({ layout: 'bare' })
useHead({ title: 'Rocket Mailer' })

const route = useRoute()
const config = useRuntimeConfig()
const auth = useAuth()
const bridge = useEmbedBridge()
const api = useApi()

const state = ref<'loading' | 'ready' | 'error'>('loading')
const errorMessage = ref('')
const context = ref<{ user: UserSummary, application: { id: string, name: string } } | null>(null)
const composer = useTemplateRef<{ applyDraft: (draft: Partial<EmailDraft>) => void }>('composer')
let stopDraftListener: (() => void) | undefined
let resizeObserver: ResizeObserver | undefined

async function start() {
  const applicationId = typeof route.query.app === 'string' ? route.query.app : ''
  if (!applicationId) throw new Error('Paramètre "app" manquant.')

  const policy = await $fetch<{ frameAncestors: string[] }>('/api/embed/frame-policy', {
    baseURL: config.public.apiBase,
    query: { app: applicationId },
  })
  bridge.allowedOrigins.value = policy.frameAncestors
  if (window.parent === window) throw new Error('Cette page doit être intégrée dans une application autorisée.')

  const fragmentToken = new URLSearchParams(window.location.hash.slice(1)).get('token')
  if (fragmentToken) {
    history.replaceState(null, '', window.location.pathname + window.location.search)
    auth.embedToken.value = fragmentToken
    bridge.hostOrigin.value = bridge.detectHostOrigin()
  }
  else {
    await bridge.requestToken('ready')
  }

  const ctx = await api<{ user: UserSummary, application: { id: string, name: string } }>('/api/embed/context')
  if (ctx.application.id !== applicationId) throw new Error('Le jeton ne correspond pas à cette application.')
  context.value = ctx

  stopDraftListener = bridge.onDraft(draft => composer.value?.applyDraft(draft))
  resizeObserver = new ResizeObserver(() => bridge.notify('resize', { height: document.documentElement.scrollHeight }))
  resizeObserver.observe(document.body)
  state.value = 'ready'
  bridge.notify('loaded')
}

onMounted(() => {
  start().catch((error) => {
    errorMessage.value = apiErrorMessage(error)
    state.value = 'error'
  })
})

onBeforeUnmount(() => {
  stopDraftListener?.()
  resizeObserver?.disconnect()
})

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
