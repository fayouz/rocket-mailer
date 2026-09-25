<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { Application, Mailbox, MailboxTestResult } from '~/types/api'

definePageMeta({ admin: true })
useHead({ title: 'Boîtes d’envoi · Rocket Mailer' })

const api = useApi()
const toast = useToast()
const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const USwitch = resolveComponent('USwitch')

const { data: mailboxes, status, refresh } = await useAsyncData('mailboxes', () => api<Mailbox[]>('/api/mailboxes'), { default: () => [] })
const { data: applications } = await useAsyncData('mailbox-applications', () => api<Application[]>('/api/applications', { query: { itemsPerPage: 200 } }), { default: () => [] })

const applicationItems = computed(() => applications.value.map(a => ({ label: a.name, value: `/api/applications/${a.id}` })))
const applicationName = (iri: string) => applications.value.find(a => iri.endsWith(a.id))?.name ?? iri

async function patch(mailbox: Mailbox, body: Partial<Mailbox>) {
  try {
    Object.assign(mailbox, await api<Mailbox>(`/api/mailboxes/${mailbox.id}`, { method: 'PATCH', body }))
  }
  catch (error) {
    toast.add({ title: 'Mise à jour impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

const columns: TableColumn<Mailbox>[] = [
  {
    accessorKey: 'name',
    header: 'Boîte',
    cell: ({ row }) => h('div', [
      h('p', { class: 'font-medium' }, row.original.name),
      h('p', { class: 'text-xs text-muted' }, row.original.displayName ? `${row.original.displayName} <${row.original.email}>` : row.original.email),
    ]),
  },
  {
    id: 'transport',
    header: 'Envoi',
    cell: ({ row }) => row.original.transport === 'dsn'
      ? h(UBadge, { variant: 'subtle', color: 'neutral', label: row.original.dsnHint ?? 'Fournisseur' })
      : `${row.original.smtpHost}:${row.original.smtpPort}`,
  },
  {
    id: 'imap',
    header: 'Copie IMAP',
    cell: ({ row }) => row.original.imapEnabled
      ? h(UBadge, { variant: 'subtle', color: 'success', label: row.original.imapSentFolder ?? 'Envoyés (auto)' })
      : h('span', { class: 'text-muted' }, '—'),
  },
  {
    id: 'usage',
    header: 'Proposée à',
    cell: ({ row }) => h('div', { class: 'flex flex-wrap gap-1' }, [
      ...(row.original.availableToUsers ? [h(UBadge, { variant: 'subtle', color: 'primary', label: 'Tous les utilisateurs' })] : []),
      ...row.original.applications.map(iri => h(UBadge, { variant: 'outline', color: 'neutral', label: applicationName(iri) })),
      ...(!row.original.availableToUsers && !row.original.applications.length ? [h('span', { class: 'text-muted' }, 'Personne')] : []),
    ]),
  },
  {
    accessorKey: 'enabled',
    header: 'Active',
    cell: ({ row }) => h(USwitch, { 'modelValue': row.original.enabled, 'onUpdate:modelValue': (value: boolean) => patch(row.original, { enabled: value }) }),
  },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'flex justify-end gap-1' }, [
      h(UButton, { 'icon': 'i-lucide-plug-zap', 'color': 'neutral', 'variant': 'ghost', 'aria-label': 'Tester la connexion', 'onClick': () => openTest(row.original) }),
      h(UButton, { 'icon': 'i-lucide-pencil', 'color': 'neutral', 'variant': 'ghost', 'aria-label': 'Modifier', 'onClick': () => edit(row.original) }),
      h(UButton, { 'icon': 'i-lucide-trash-2', 'color': 'error', 'variant': 'ghost', 'aria-label': 'Supprimer', 'onClick': () => (toDelete.value = row.original) }),
    ]),
  },
]

// --- Form -------------------------------------------------------------------------------------

function emptyForm() {
  return {
    name: '', email: '', displayName: '', transport: 'smtp' as Mailbox['transport'],
    smtpHost: '', smtpPort: 587, smtpEncryption: 'starttls' as Mailbox['smtpEncryption'], smtpUsername: '', smtpPassword: '',
    dsn: '',
    imapEnabled: true, imapHost: '', imapPort: 993, imapEncryption: 'ssl' as Mailbox['imapEncryption'], imapUsername: '', imapPassword: '', imapSentFolder: '',
    availableToUsers: false, applications: [] as string[],
  }
}

const formOpen = ref(false)
const editing = ref<Mailbox | null>(null)
const form = reactive(emptyForm())
const preset = ref<string | undefined>()
const saving = ref(false)
const presetNote = computed(() => MAILBOX_PRESETS.find(p => p.id === preset.value)?.note)

function create() {
  editing.value = null
  preset.value = undefined
  Object.assign(form, emptyForm())
  formOpen.value = true
}

function edit(mailbox: Mailbox) {
  editing.value = mailbox
  preset.value = undefined
  Object.assign(form, emptyForm(), {
    ...mailbox,
    displayName: mailbox.displayName ?? '',
    smtpHost: mailbox.smtpHost ?? '',
    smtpUsername: mailbox.smtpUsername ?? '',
    imapHost: mailbox.imapHost ?? '',
    imapUsername: mailbox.imapUsername ?? '',
    imapSentFolder: mailbox.imapSentFolder ?? '',
    smtpPassword: '',
    imapPassword: '',
    dsn: '',
  })
  formOpen.value = true
}

function applyPreset(id: string) {
  const selected = MAILBOX_PRESETS.find(p => p.id === id)
  if (!selected) return
  form.transport = selected.transport
  if (selected.smtp) Object.assign(form, { smtpHost: selected.smtp.host, smtpPort: selected.smtp.port, smtpEncryption: selected.smtp.encryption })
  if (selected.imap) Object.assign(form, { imapEnabled: true, imapHost: selected.imap.host, imapPort: selected.imap.port, imapEncryption: selected.imap.encryption })
  if (selected.dsn) form.dsn = selected.dsn
  if (!form.smtpUsername && form.email) form.smtpUsername = form.email
}

watch(preset, id => id && applyPreset(id))

async function save() {
  saving.value = true
  try {
    const body = {
      ...form,
      displayName: form.displayName || null,
      smtpPort: Number(form.smtpPort) || null,
      imapPort: Number(form.imapPort) || null,
      // Empty secrets keep the stored ones.
      smtpPassword: form.smtpPassword || null,
      imapPassword: form.imapPassword || null,
      dsn: form.dsn || null,
    }
    if (editing.value) {
      Object.assign(editing.value, await api<Mailbox>(`/api/mailboxes/${editing.value.id}`, { method: 'PATCH', body }))
    }
    else {
      await api<Mailbox>('/api/mailboxes', { method: 'POST', body })
      await refresh()
    }
    formOpen.value = false
    toast.add({ title: 'Boîte d’envoi enregistrée', color: 'success', icon: 'i-lucide-check' })
  }
  catch (error) {
    toast.add({ title: 'Enregistrement impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    saving.value = false
  }
}

// --- Test -------------------------------------------------------------------------------------

const testing = ref<Mailbox | null>(null)
// Kept while the dialog closes (its title would otherwise flash "undefined").
const testName = ref('')
const testTo = ref('')
const testRunning = ref(false)
const testResult = ref<MailboxTestResult | null>(null)
const auth = useAuth()

function openTest(mailbox: Mailbox) {
  testing.value = mailbox
  testName.value = mailbox.name
  testResult.value = null
  testTo.value = auth.me.value?.user?.email ?? ''
}

async function runTest(send: boolean) {
  if (!testing.value) return
  testRunning.value = true
  try {
    testResult.value = await api<MailboxTestResult>(`/api/mailboxes/${testing.value.id}/test`, {
      method: 'POST',
      body: send ? { sendTo: testTo.value } : {},
    })
  }
  catch (error) {
    toast.add({ title: 'Test impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    testRunning.value = false
  }
}

// --- Delete -----------------------------------------------------------------------------------

const toDelete = ref<Mailbox | null>(null)
async function remove() {
  if (!toDelete.value) return
  try {
    await api(`/api/mailboxes/${toDelete.value.id}`, { method: 'DELETE' })
    toDelete.value = null
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Suppression impossible', description: apiErrorMessage(error), color: 'error' })
  }
}
</script>

<template>
  <UDashboardPanel id="mailboxes">
    <template #header>
      <UDashboardNavbar title="Boîtes d’envoi">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-plus" label="Nouvelle boîte" data-testid="new-mailbox" @click="create" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <p class="max-w-3xl text-sm text-muted">
        Une boîte d’envoi est un vrai compte email : les messages partent par son serveur SMTP (ou un fournisseur), et une copie est rangée dans son dossier « Envoyés » par IMAP.
        Rattachez-la aux applications qui pourront la proposer dans le composeur, ou ouvrez-la à tous les utilisateurs.
      </p>

      <UTable
        :data="mailboxes"
        :columns="columns"
        :loading="status === 'pending'"
        empty="Aucune boîte d’envoi. Les emails partent par le serveur de la plateforme (MAILER_DSN)."
      />

      <USlideover v-model:open="formOpen" :title="editing ? `Modifier ${editing.name}` : 'Nouvelle boîte d’envoi'" :ui="{ content: 'max-w-2xl' }">
        <template #body>
          <form id="mailbox-form" class="flex flex-col gap-5" data-testid="mailbox-form" @submit.prevent="save">
            <UFormField label="Configuration type" hint="Pré-remplit les serveurs">
              <USelect v-model="preset" :items="MAILBOX_PRESETS.map(p => ({ label: p.label, value: p.id }))" placeholder="Choisir un fournisseur…" class="w-full" data-testid="mailbox-preset" />
            </UFormField>
            <UAlert v-if="presetNote" color="info" variant="subtle" icon="i-lucide-info" :description="presetNote" />

            <div class="grid gap-3 sm:grid-cols-2">
              <UFormField label="Nom" required hint="Interne">
                <UInput v-model="form.name" placeholder="Service commercial" class="w-full" />
              </UFormField>
              <UFormField label="Adresse" required>
                <UInput v-model="form.email" type="email" placeholder="commercial@exemple.com" class="w-full" />
              </UFormField>
              <UFormField label="Nom affiché" hint="Dans « De »" class="sm:col-span-2">
                <UInput v-model="form.displayName" placeholder="Service commercial" class="w-full" />
              </UFormField>
            </div>

            <USeparator label="Envoi" />
            <URadioGroup
              v-model="form.transport"
              orientation="horizontal"
              :items="[{ label: 'Serveur SMTP', value: 'smtp' }, { label: 'Fournisseur (API, DSN)', value: 'dsn' }]"
            />

            <div v-if="form.transport === 'smtp'" class="grid gap-3 sm:grid-cols-3">
              <UFormField label="Serveur SMTP" required class="sm:col-span-2">
                <UInput v-model="form.smtpHost" placeholder="smtp.exemple.com" class="w-full" />
              </UFormField>
              <UFormField label="Port" required>
                <UInput v-model.number="form.smtpPort" type="number" class="w-full" />
              </UFormField>
              <UFormField label="Chiffrement">
                <USelect v-model="form.smtpEncryption" :items="ENCRYPTION_ITEMS" class="w-full" />
              </UFormField>
              <UFormField label="Identifiant">
                <UInput v-model="form.smtpUsername" autocomplete="off" class="w-full" />
              </UFormField>
              <UFormField label="Mot de passe" :hint="editing?.hasSmtpPassword ? 'Vide : inchangé' : undefined">
                <UInput v-model="form.smtpPassword" type="password" autocomplete="new-password" class="w-full" />
              </UFormField>
            </div>

            <UFormField
              v-else
              label="DSN du fournisseur"
              :hint="editing?.dsnHint ? `Actuel : ${editing.dsnHint} — vide : inchangé` : undefined"
              help="Format Symfony Mailer, ex. brevo+api://CLÉ@default. Stocké chiffré, jamais réaffiché."
            >
              <UInput v-model="form.dsn" type="password" autocomplete="off" class="w-full font-mono" />
            </UFormField>

            <USeparator label="Copie dans « Envoyés » (IMAP)" />
            <USwitch v-model="form.imapEnabled" label="Ranger une copie de chaque email dans la boîte" />
            <div v-if="form.imapEnabled" class="grid gap-3 sm:grid-cols-3">
              <UFormField label="Serveur IMAP" required class="sm:col-span-2">
                <UInput v-model="form.imapHost" placeholder="imap.exemple.com" class="w-full" />
              </UFormField>
              <UFormField label="Port" required>
                <UInput v-model.number="form.imapPort" type="number" class="w-full" />
              </UFormField>
              <UFormField label="Chiffrement">
                <USelect v-model="form.imapEncryption" :items="ENCRYPTION_ITEMS" class="w-full" />
              </UFormField>
              <UFormField label="Identifiant" hint="Vide : celui du SMTP">
                <UInput v-model="form.imapUsername" autocomplete="off" class="w-full" />
              </UFormField>
              <UFormField label="Mot de passe" :hint="editing?.hasImapPassword ? 'Vide : inchangé' : 'Vide : celui du SMTP'">
                <UInput v-model="form.imapPassword" type="password" autocomplete="new-password" class="w-full" />
              </UFormField>
              <UFormField label="Dossier" hint="Vide : détecté" class="sm:col-span-3">
                <UInput v-model="form.imapSentFolder" placeholder="Envoyés, Sent, [Gmail]/Messages envoyés…" class="w-full" />
              </UFormField>
            </div>

            <USeparator label="Qui peut l’utiliser ?" />
            <UFormField label="Applications" help="Proposée dans le composeur de ces applications, et utilisable par leur API.">
              <USelectMenu
                v-model="form.applications"
                :items="applicationItems"
                value-key="value"
                multiple
                placeholder="Aucune"
                class="w-full"
                data-testid="mailbox-applications"
              />
            </UFormField>
            <USwitch v-model="form.availableToUsers" label="Proposée aussi à tous les utilisateurs de Rocket Mailer" />
          </form>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="formOpen = false" />
            <UButton type="submit" form="mailbox-form" label="Enregistrer" :loading="saving" />
          </div>
        </template>
      </USlideover>

      <UModal :open="testing !== null" :title="`Tester « ${testName} »`" @update:open="(value: boolean) => { if (!value) testing = null }">
        <template #body>
          <div class="flex flex-col gap-4" data-testid="mailbox-test">
            <p class="text-sm text-muted">
              Vérifie la connexion et l’authentification SMTP et IMAP. Un email de test confirme l’envoi de bout en bout, et il est rangé dans « Envoyés ».
            </p>
            <div class="flex flex-wrap items-end gap-2">
              <UButton label="Tester la connexion" icon="i-lucide-plug-zap" :loading="testRunning" @click="runTest(false)" />
              <UFormField label="ou envoyer un email de test à" class="min-w-60 flex-1">
                <UInput v-model="testTo" type="email" class="w-full" />
              </UFormField>
              <UButton label="Envoyer" icon="i-lucide-send" color="neutral" variant="outline" :loading="testRunning" :disabled="!isEmail(testTo)" @click="runTest(true)" />
            </div>
            <template v-if="testResult">
              <UAlert
                :color="testResult.smtp.ok ? 'success' : 'error'"
                variant="subtle"
                :icon="testResult.smtp.ok ? 'i-lucide-circle-check' : 'i-lucide-circle-x'"
                :title="testing?.transport === 'dsn' ? 'Fournisseur' : 'SMTP'"
                :description="testResult.smtp.message"
                data-testid="test-smtp"
              />
              <UAlert
                v-if="testResult.imap"
                :color="testResult.imap.ok ? 'success' : 'error'"
                variant="subtle"
                :icon="testResult.imap.ok ? 'i-lucide-circle-check' : 'i-lucide-circle-x'"
                title="IMAP"
                :description="testResult.imap.ok ? `${testResult.imap.message} Dossier des copies : ${testResult.imap.sentFolder}.` : testResult.imap.message"
                data-testid="test-imap"
              />
            </template>
          </div>
        </template>
      </UModal>

      <UModal :open="toDelete !== null" title="Supprimer la boîte d’envoi ?" :description="toDelete ? `« ${toDelete.name} » ne sera plus proposée. Les emails déjà envoyés gardent son nom.` : ''" @update:open="(value: boolean) => { if (!value) toDelete = null }">
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="toDelete = null" />
            <UButton label="Supprimer" color="error" @click="remove" />
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
