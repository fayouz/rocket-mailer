<script setup lang="ts">
import type { RocketMailerDraft, RocketMailerSentEmail } from '../../types'

/** The Rocket Mailer composer, embedded for the logged-in user (see the "rocket-mailer:user" hook). */
const props = defineProps<{
  draft?: RocketMailerDraft
  /** Defaults to the endpoint provided by the layer. Called with the cookies of your site. */
  tokenUrl?: string
  /**
   * Instead of tokenUrl, when the token endpoint needs more than cookies, e.g. a Symfony / API Platform
   * backend on another domain with a JWT: () => $fetch('/rocket-mailer/token', { method: 'POST', headers })
   */
  getToken?: () => string | Promise<string>
  minHeight?: number
  autoResize?: boolean
}>()

const emit = defineEmits<{
  ready: []
  sent: [email: RocketMailerSentEmail]
  error: [error: Error]
}>()

const config = useRuntimeConfig().public.rocketMailer
const baseUrl = config.url.replace(/\/$/, '')

// embed.js defines <rocket-mailer-composer>; loaded once, whatever the number of composers.
useHead({ script: [{ key: 'rocket-mailer-embed', src: `${baseUrl}/embed.js`, defer: true }] })

type ComposerElement = HTMLElement & {
  draft: RocketMailerDraft | null
  getToken?: () => string | Promise<string>
  setDraft: (draft: RocketMailerDraft) => void
}
const element = ref<ComposerElement>()

// Reactive proxies cannot cross postMessage: pass a plain copy.
const plain = (draft: RocketMailerDraft) => JSON.parse(JSON.stringify(draft)) as RocketMailerDraft

watch([element, () => props.getToken], ([el, getToken]) => {
  if (el && getToken) el.getToken = getToken
}, { immediate: true })

watch([element, () => props.draft], ([el, draft]) => {
  if (el && draft) el.draft = plain(draft)
}, { deep: true })

// For type checking, <rocket-mailer-composer> resolves to this very component (same name): read events loosely.
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const detail = <T>(event: any): T => (event as CustomEvent<T>).detail

function setDraft(draft: RocketMailerDraft) {
  element.value?.setDraft?.(plain(draft))
}

defineExpose({ setDraft })
</script>

<template>
  <ClientOnly>
    <rocket-mailer-composer
      ref="element"
      :base-url="baseUrl"
      :application-id="config.applicationId"
      :token-url="getToken ? undefined : (tokenUrl ?? config.tokenRoute)"
      :min-height="minHeight"
      :no-auto-resize="autoResize === false ? '' : undefined"
      @ready="emit('ready')"
      @sent="(event: any) => emit('sent', detail<RocketMailerSentEmail>(event))"
      @error="(event: any) => emit('error', detail<Error>(event))"
    />
  </ClientOnly>
</template>
