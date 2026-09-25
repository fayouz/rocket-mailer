<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { Application, ApplicationSender, SenderOption } from '~/types/api'

/**
 * The applications page of the rocket-core layer, with the sender settings of each application
 * (GET/PATCH /api/application_senders/{id}): its own "From" address and the addresses it may impose.
 */
definePageMeta({ admin: true })
useHead({ title: 'Applications · Rocket Mailer' })

const api = useApi()
const toast = useToast()
const config = useRuntimeConfig()
const requestUrl = useRequestURL()
const UBadge = resolveComponent('UBadge')
const USwitch = resolveComponent('USwitch')
const UButton = resolveComponent('UButton')

const { data: applications, status, refresh } = await useAsyncData('applications', () => api<Application[]>('/api/applications'), { default: () => [] })
const { data: senders, refresh: refreshSenders } = await useAsyncData('application-senders', () => api<ApplicationSender[]>('/api/application_senders', { query: { itemsPerPage: 200 } }), { default: () => [] })
const senderOf = (application: Application) => senders.value.find(s => s.id === application.id)

// The platform's default address is only a suggestion for an application's sender, never used as is.
const { data: platformSenders } = await useAsyncData('applications-sender-suggestion', () => api<SenderOption[]>('/api/senders'), { default: () => [] })
const suggestion = computed(() => platformSenders.value.find(o => o.default && o.source === 'settings') ?? null)

async function patch(application: Application, body: Partial<Application>) {
  try {
    Object.assign(application, await api<Application>(`/api/applications/${application.id}`, { method: 'PATCH', body }))
    return true
  }
  catch (error) {
    toast.add({ title: 'Mise à jour impossible', description: apiErrorMessage(error), color: 'error' })
    return false
  }
}

const columns: TableColumn<Application>[] = [
  {
    accessorKey: 'name',
    header: 'Application',
    cell: ({ row }) => h('div', [
      h('p', { class: 'font-medium' }, row.original.name),
      h('p', { class: 'font-mono text-xs text-muted' }, `${row.original.tokenHint}…`),
    ]),
  },
  {
    accessorKey: 'canImpersonate',
    header: 'Impersonation',
    cell: ({ row }) => h(UBadge, { variant: 'subtle', color: row.original.canImpersonate ? 'warning' : 'neutral', label: row.original.canImpersonate ? 'Autorisée' : 'Non' }),
  },
  {
    id: 'sender',
    header: 'Expéditeur',
    cell: ({ row }) => {
      const sender = senderOf(row.original)
      return sender?.senderEmail
        ? h('div', [
            h('p', sender.senderName ?? sender.senderEmail),
            sender.senderName ? h('p', { class: 'text-xs text-muted' }, sender.senderEmail) : null,
          ])
        : h(UBadge, { variant: 'subtle', color: 'error', icon: 'i-lucide-circle-alert', label: 'À configurer' })
    },
  },
  { accessorKey: 'allowedOrigins', header: 'Origines (embed)', cell: ({ row }) => row.original.allowedOrigins.join(', ') || '—' },
  { accessorKey: 'lastUsedAt', header: 'Dernier appel', cell: ({ row }) => formatDate(row.original.lastUsedAt) },
  {
    accessorKey: 'enabled',
    header: 'Active',
    cell: ({ row }) => h(USwitch, { 'modelValue': row.original.enabled, 'onUpdate:modelValue': (value: boolean) => patch(row.original, { enabled: value }) }),
  },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'flex justify-end gap-1' }, [
      h(UButton, { icon: 'i-lucide-code-xml', color: 'neutral', variant: 'ghost', 'aria-label': 'Code d’intégration', onClick: () => (embedFor.value = row.original.id) }),
      h(UButton, { icon: 'i-lucide-pencil', color: 'neutral', variant: 'ghost', 'aria-label': 'Modifier', onClick: () => edit(row.original) }),
      h(UButton, { icon: 'i-lucide-rotate-cw', color: 'neutral', variant: 'ghost', 'aria-label': 'Régénérer le jeton', onClick: () => (toRotate.value = row.original) }),
      h(UButton, { icon: 'i-lucide-trash-2', color: 'error', variant: 'ghost', 'aria-label': 'Supprimer', onClick: () => (toDelete.value = row.original) }),
    ]),
  },
]

// Create / edit
// "Code d'intégration" of one application (same dialog as "Intégrer" in the composer, without a draft).
const embedFor = ref<string | null>(null)
const embedOpen = computed({
  get: () => embedFor.value !== null,
  set: (value: boolean) => {
    if (!value) embedFor.value = null
  },
})

const formOpen = ref(false)
const editing = ref<Application | null>(null)
const form = reactive({ name: '', description: '', canImpersonate: true, allowedOrigins: [] as string[], allowedSenders: [] as string[], senderName: '', senderEmail: '' })

function useSuggestion() {
  if (!suggestion.value) return
  form.senderEmail = suggestion.value.email
  form.senderName = form.name || suggestion.value.name || ''
}

function create() {
  editing.value = null
  Object.assign(form, { name: '', description: '', canImpersonate: true, allowedOrigins: [], allowedSenders: [], senderName: '', senderEmail: '' })
  formOpen.value = true
}

// "Nouvelle application" from the dashboard.
onMounted(() => {
  if (useRoute().query.new) create()
})

function edit(application: Application) {
  editing.value = application
  Object.assign(form, {
    name: application.name,
    description: application.description ?? '',
    canImpersonate: application.canImpersonate,
    allowedOrigins: [...application.allowedOrigins],
    allowedSenders: [...(senderOf(application)?.allowedSenders ?? [])],
    senderName: senderOf(application)?.senderName ?? '',
    senderEmail: senderOf(application)?.senderEmail ?? '',
  })
  formOpen.value = true
}

// The sender settings are a resource of their own, saved after the application.
async function saveSender(application: Application) {
  try {
    await api<ApplicationSender>(`/api/application_senders/${application.id}`, {
      method: 'PATCH',
      body: { senderName: form.senderName || null, senderEmail: form.senderEmail || null, allowedSenders: form.allowedSenders },
    })
    await refreshSenders()
    return true
  }
  catch (error) {
    toast.add({ title: 'Expéditeur non enregistré', description: apiErrorMessage(error), color: 'error' })
    return false
  }
}

async function submit() {
  const body = { name: form.name, description: form.description || null, canImpersonate: form.canImpersonate, allowedOrigins: form.allowedOrigins }
  if (editing.value) {
    if (await patch(editing.value, body) && await saveSender(editing.value)) formOpen.value = false
    return
  }
  let created: Application
  try {
    created = await api<Application>('/api/applications', { method: 'POST', body })
  }
  catch (error) {
    toast.add({ title: 'Création impossible', description: apiErrorMessage(error), color: 'error' })
    return
  }
  // Created: its token is shown once, even if its sender still has to be fixed (Modifier).
  await saveSender(created)
  formOpen.value = false
  revealed.value = { application: created, token: created.plainToken! }
  await refresh()
}

// Secret shown once
const revealed = ref<{ application: Application, token: string } | null>(null)
const toRotate = ref<Application | null>(null)
const toDelete = ref<Application | null>(null)

async function rotate() {
  const application = toRotate.value!
  toRotate.value = null
  try {
    const { token } = await api<{ token: string }>(`/api/applications/${application.id}/regenerate-token`, { method: 'POST' })
    revealed.value = { application, token }
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Régénération impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

async function remove() {
  const application = toDelete.value!
  toDelete.value = null
  try {
    await api(`/api/applications/${application.id}`, { method: 'DELETE' })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Suppression impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

async function copy(text: string) {
  await navigator.clipboard.writeText(text)
  toast.add({ title: 'Copié', color: 'success', duration: 1500 })
}

// Split so the SFC parser does not see a closing script tag.
const endScript = '</' + 'script>'
const snippet = computed(() => revealed.value && `# 1. Server side (never expose the application token to the browser)
curl -X POST ${config.public.apiBase || requestUrl.origin}/api/embed/token \\
  -H "Authorization: Bearer ${revealed.value.token}" \\
  -H "X-Impersonate-User: jean.dupont@example.org"

# 2. Browser side
<script src="${requestUrl.origin}/embed.js">${endScript}
<div id="mailer"></div>
<script>
  RocketMailer.mount('#mailer', {
    baseUrl: '${requestUrl.origin}',
    applicationId: '${revealed.value.application.id}',
    getToken: () => fetch('/my-backend/rocket-mailer-token').then(r => r.json()).then(d => d.token),
    onSent: (email) => console.log('sent', email),
  })
${endScript}`)
</script>

<template>
  <UDashboardPanel id="applications">
    <template #header>
      <UDashboardNavbar title="Applications externes">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-plus" label="Nouvelle application" @click="create" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UAlert
        icon="i-lucide-info"
        variant="subtle"
        color="neutral"
        title="Comment ça marche"
        description="Une application s'authentifie avec son jeton (Authorization: Bearer rma_…). Si l'impersonation est autorisée, l'en-tête X-Impersonate-User lui permet d'agir en tant qu'un utilisateur (jamais avec le rôle administrateur) et de générer des jetons d'intégration pour le composeur embarqué, affichable uniquement depuis les origines déclarées."
      />
      <UTable :data="applications" :columns="columns" :loading="status === 'pending'" empty="Aucune application." />

      <UModal v-model:open="formOpen" :title="editing ? `Modifier ${editing.name}` : 'Nouvelle application'">
        <template #body>
          <form id="application-form" class="flex flex-col gap-3" @submit.prevent="submit">
            <UFormField label="Nom" required>
              <UInput v-model="form.name" class="w-full" />
            </UFormField>
            <UFormField label="Description">
              <UTextarea v-model="form.description" class="w-full" :rows="2" />
            </UFormField>
            <div class="flex flex-col gap-2 rounded-md border border-default p-3" data-testid="application-sender">
              <p class="text-sm font-medium">
                Expéditeur de l’application
              </p>
              <p class="text-xs text-muted">
                Adresse « De » par défaut de son composeur et de ses envois par l’API. Obligatoire : une application n’envoie jamais depuis les adresses de Rocket Mailer.
              </p>
              <div class="grid gap-2 sm:grid-cols-2">
                <UFormField label="Nom affiché">
                  <UInput v-model="form.senderName" :placeholder="form.name || 'Service commercial'" class="w-full" />
                </UFormField>
                <UFormField label="Adresse email" required>
                  <UInput v-model="form.senderEmail" type="email" placeholder="contact@crm.exemple.com" class="w-full" data-testid="application-sender-email" />
                </UFormField>
              </div>
              <div v-if="suggestion && !form.senderEmail">
                <UButton
                  :label="`Suggestion : ${suggestion.from}`"
                  icon="i-lucide-wand-sparkles"
                  size="xs"
                  color="neutral"
                  variant="link"
                  class="px-0"
                  data-testid="sender-suggestion"
                  @click="useSuggestion"
                />
              </div>
            </div>
            <USwitch v-model="form.canImpersonate" label="Peut agir en tant qu'utilisateur (impersonation + embed)" />
            <UFormField label="Origines autorisées à embarquer le composeur" hint="ex. https://crm.exemple.com">
              <UInputTags v-model="form.allowedOrigins" add-on-blur add-on-paste class="w-full" />
            </UFormField>
            <UFormField label="Adresses d'expédition qu'elle peut imposer" hint="ex. *@crm.exemple.com ou agence@exemple.com">
              <UInputTags v-model="form.allowedSenders" add-on-blur add-on-paste class="w-full" />
            </UFormField>
          </form>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="formOpen = false" />
            <UButton type="submit" form="application-form" :label="editing ? 'Enregistrer' : 'Créer'" />
          </div>
        </template>
      </UModal>

      <UModal :open="revealed !== null" title="Jeton de l'application" :dismissible="false" :ui="{ content: 'max-w-2xl' }" @update:open="(value: boolean) => { if (!value) revealed = null }">
        <template #body>
          <div v-if="revealed" class="flex flex-col gap-4">
            <UAlert color="warning" variant="subtle" icon="i-lucide-triangle-alert" description="Copiez ce jeton maintenant : il ne sera plus jamais affiché. Stockez-le côté serveur uniquement." />
            <div class="flex items-center gap-2">
              <code class="min-w-0 flex-1 break-all rounded bg-elevated p-2 text-sm">{{ revealed.token }}</code>
              <UButton icon="i-lucide-copy" color="neutral" variant="outline" aria-label="Copier" @click="copy(revealed.token)" />
            </div>
            <div>
              <p class="mb-1 text-sm font-medium">
                Intégration du composeur
              </p>
              <pre class="max-h-72 overflow-auto rounded bg-elevated p-3 text-xs">{{ snippet }}</pre>
            </div>
          </div>
        </template>
        <template #footer>
          <div class="flex w-full justify-end">
            <UButton label="J'ai copié le jeton" @click="revealed = null" />
          </div>
        </template>
      </UModal>

      <UModal :open="toRotate !== null" title="Régénérer le jeton ?" description="L'ancien jeton cessera immédiatement de fonctionner." @update:open="(value: boolean) => { if (!value) toRotate = null }">
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="toRotate = null" />
            <UButton label="Régénérer" color="warning" @click="rotate" />
          </div>
        </template>
      </UModal>

      <UModal :open="toDelete !== null" title="Supprimer l'application ?" :description="toDelete ? `« ${toDelete.name} » ne pourra plus accéder à l'API.` : ''" @update:open="(value: boolean) => { if (!value) toDelete = null }">
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="toDelete = null" />
            <UButton label="Supprimer" color="error" @click="remove" />
          </div>
        </template>
      </UModal>
      <EmbedCodeModal v-if="embedFor" v-model:open="embedOpen" :application-id="embedFor" />
    </template>
  </UDashboardPanel>
</template>
