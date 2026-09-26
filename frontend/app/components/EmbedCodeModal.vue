<script setup lang="ts">
import type { Application, ApplicationSender } from '~/types/api'

/**
 * "Intégrer": the code to paste in a calling application, for one of the declared applications,
 * prefilled with the current draft (recipients, subject, template and its variables).
 */
const props = defineProps<{
  draft?: EmbedDraft
  /** Preselected application. */
  applicationId?: string
}>()
const open = defineModel<boolean>('open', { default: false })

const api = useApi()
const toast = useToast()

const { data: applications, status, execute } = useAsyncData(
  'embed-applications',
  () => api<Application[]>('/api/applications', { query: { itemsPerPage: 200 } }),
  { immediate: false, default: () => [] },
)
const { data: senders, execute: loadSenders } = useAsyncData(
  'embed-application-senders',
  () => api<ApplicationSender[]>('/api/application_senders', { query: { itemsPerPage: 200 } }),
  { immediate: false, default: () => [] },
)

const selectedId = ref<string | undefined>(props.applicationId)
watch(open, async (value) => {
  if (!value) return
  await Promise.all([execute(), loadSenders()])
  if (props.applicationId) selectedId.value = props.applicationId
  // Default: the most recently used application that can embed the composer.
  const usable = applications.value
    .filter(a => a.enabled && a.canImpersonate)
    .sort((a, b) => (b.lastUsedAt ?? '').localeCompare(a.lastUsedAt ?? ''))
  selectedId.value ??= usable[0]?.id ?? applications.value[0]?.id
}, { immediate: true })

const selected = computed(() => applications.value.find(a => a.id === selectedId.value))
const items = computed(() => applications.value.map(a => ({
  label: a.name,
  value: a.id,
  description: a.enabled ? (a.allowedOrigins.join(', ') || 'Aucune origine autorisée') : 'Désactivée',
})))

const warnings = computed(() => {
  const app = selected.value
  if (!app) return []
  const list: string[] = []
  if (!app.enabled) list.push('Cette application est désactivée : le composeur refusera de s’afficher.')
  if (!app.canImpersonate) list.push('« Peut agir en tant qu’utilisateur » est désactivé : l’application ne peut pas obtenir de jeton pour le composeur.')
  if (!app.allowedOrigins.length) list.push('Aucune origine autorisée : ajoutez l’origine de l’application appelante (ex. https://crm.exemple.com).')
  const sender = senders.value.find(s => s.id === app.id)
  if (!sender?.senderEmail) list.push('L’application n’a pas d’expéditeur : configurez-le (Applications → Modifier), sinon ses envois sont refusés.')
  if (props.draft?.from && !sender?.allowedSenders.length) list.push('Le brouillon impose une adresse « De » : elle doit être proposée à l’utilisateur ou autorisée pour l’application.')
  return list
})

const input = computed(() => ({
  baseUrl: window.location.origin,
  applicationId: selected.value?.id ?? '',
  draft: props.draft ?? {},
}))

const tabs = computed(() => [
  { label: 'Web component', icon: 'i-lucide-component', slot: 'code' as const, code: webComponentSnippet(input.value), lang: 'html' },
  { label: 'JavaScript', icon: 'i-lucide-braces', slot: 'code' as const, code: javascriptSnippet(input.value), lang: 'html' },
  { label: 'Nuxt', icon: 'i-lucide-mountain', slot: 'code' as const, code: nuxtSnippet(input.value), lang: 'ts' },
  { label: 'Endpoint de jeton', icon: 'i-lucide-key-round', slot: 'code' as const, code: tokenEndpointSnippet(input.value), lang: 'js' },
])

async function copy(code: string) {
  try {
    await navigator.clipboard.writeText(code)
    toast.add({ title: 'Code copié', color: 'success', icon: 'i-lucide-copy-check' })
  }
  catch {
    toast.add({ title: 'Copie impossible', description: 'Sélectionnez le code et copiez-le manuellement.', color: 'warning' })
  }
}
</script>

<template>
  <UModal
    v-model:open="open"
    title="Intégrer le composeur"
    description="Code à coller dans l’application appelante. Il reprend le brouillon en cours : destinataires, objet, template et variables."
    :ui="{ content: 'max-w-4xl' }"
  >
    <template #body>
      <div class="flex flex-col gap-4" data-testid="embed-code">
        <UFormField label="Application">
          <USelect
            v-model="selectedId"
            :items="items"
            :loading="status === 'pending'"
            placeholder="Choisir une application"
            class="w-full"
            data-testid="embed-application"
          />
        </UFormField>

        <UEmpty
          v-if="status === 'success' && !applications.length"
          icon="i-lucide-key-round"
          title="Aucune application"
          description="Déclarez d’abord l’application appelante."
          :actions="[{ label: 'Créer une application', to: '/applications?new=1' }]"
        />

        <template v-else-if="selected">
          <UAlert
            v-for="warning in warnings"
            :key="warning"
            color="warning"
            variant="subtle"
            icon="i-lucide-triangle-alert"
            :description="warning"
          />

          <UTabs :items="tabs" variant="link" class="w-full">
            <template #code="{ item }">
              <div class="relative">
                <UButton
                  icon="i-lucide-copy"
                  label="Copier"
                  size="xs"
                  color="neutral"
                  variant="subtle"
                  class="absolute top-2 right-2"
                  @click="copy(item.code)"
                />
                <pre class="max-h-[50vh] overflow-auto rounded-md bg-elevated p-4 pr-24 text-xs leading-relaxed" :data-lang="item.lang"><code>{{ item.code }}</code></pre>
              </div>
            </template>
          </UTabs>

          <p class="text-xs text-muted">
            Le jeton secret <code>rma_…</code> de l’application ne doit jamais être dans le navigateur : seul votre backend l’utilise, dans l’endpoint de jeton.
            <ULink :to="`${$config.public.docsUrl.includes('github.com') ? $config.public.docsUrl : `${$config.public.docsUrl}/embed/overview`}`" target="_blank" class="text-primary">
              Documentation d’intégration
            </ULink>
          </p>
        </template>
      </div>
    </template>
  </UModal>
</template>
