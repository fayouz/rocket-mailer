<script setup lang="ts">
import type { Attachment, Email, EmailDraft, EmailTemplate, SenderOption, TemplateVariable } from '~/types/api'

const props = defineProps<{
  initial?: Partial<EmailDraft>
  /** Shows "Intégrer": the code to embed this composer, with the current draft, in a calling application. */
  embeddable?: boolean
}>()
const emit = defineEmits<{ sent: [email: Email] }>()

const api = useApi()
const toast = useToast()

function emptyDraft(): EmailDraft {
  return { from: null, mailbox: null, to: [], cc: [], bcc: [], subject: '', htmlBody: '', template: null, attachments: [], ...props.initial }
}

const draft = reactive<EmailDraft>(emptyDraft())
const showCopies = ref(draft.cc.length > 0 || draft.bcc.length > 0)
const pickerOpen = ref(false)
const templateName = ref<string | null>(null)
const pendingTemplate = ref<EmailTemplate | null>(null)
const sending = ref(false)
const attachments = ref<Attachment[]>([])
const editor = useTemplateRef<{ getData: () => string | undefined }>('editor')

// Template variables: values given by the host application (draft.variables) or typed here.
const templateVariables = ref<TemplateVariable[]>([])
const variableValues = ref<Record<string, string>>({})
const variableInputs = ref<Record<string, string>>({})

/** Current content: the v-model is debounced, the editor is exact. */
function currentHtml(): string {
  const html = editor.value?.getData()
  if (html !== undefined) draft.htmlBody = html
  return draft.htmlBody
}

/** Variables of the imported template still present in the subject or the body. */
const missingVariables = ref<TemplateVariable[]>([])
function refreshMissing(html = draft.htmlBody) {
  const remaining = new Set(placeholderNames(draft.subject, html))
  missingVariables.value = templateVariables.value.filter(v => remaining.has(v.name))
}

/** Replaces every variable that has a value (given, typed, or the template default). */
function fillVariables(extra: Record<string, string> = {}) {
  const defaults = Object.fromEntries(templateVariables.value.filter(v => v.defaultValue !== null).map(v => [v.name, v.defaultValue!]))
  const values = { ...defaults, ...variableValues.value, ...extra }
  draft.subject = renderPlaceholders(draft.subject, values, false)
  const html = renderPlaceholders(currentHtml(), values, true)
  draft.htmlBody = html
  refreshMissing(html)
}

function applyTypedValues() {
  const typed = Object.fromEntries(Object.entries(variableInputs.value).filter(([, value]) => value.trim() !== ''))
  variableValues.value = { ...variableValues.value, ...typed }
  fillVariables(typed)
}
const uploading = ref(false)

// "From" choices: the settings' addresses, the user's own address, any address imposed by the host application,
// then the sending mailboxes (of the application, or shared with every user).
const senders = ref<SenderOption[]>([])
const sourceLabels: Record<SenderOption['source'], string> = {
  settings: 'Adresse de l’organisation',
  personal: 'Votre adresse',
  application: 'Adresse de l’application',
  mailbox: 'Boîte d’envoi',
}
const optionKey = (option: SenderOption) => (option.mailbox ? `mailbox:${option.mailbox}` : option.from)
const mailboxIri = (reference: string) => (reference.startsWith('/api/') ? reference : `/api/mailboxes/${reference}`)

const senderItems = computed(() => {
  const toItem = (option: SenderOption) => ({
    label: option.from,
    value: optionKey(option),
    description: option.mailboxName ? `${sourceLabels.mailbox} · ${option.mailboxName}` : sourceLabels[option.source],
    icon: option.mailbox ? 'i-lucide-mailbox' : 'i-lucide-at-sign',
  })
  const addresses = senders.value.filter(o => !o.mailbox).map(toItem)
  const mailboxes = senders.value.filter(o => o.mailbox).map(toItem)
  if (!mailboxes.length) return addresses
  return [
    [{ type: 'label' as const, label: 'Adresses d’envoi' }, ...addresses],
    [{ type: 'label' as const, label: 'Boîtes d’envoi' }, ...mailboxes],
  ]
})

/** Selected option: an address ("from") or a mailbox. */
const fromKey = computed(() => (draft.mailbox ? `mailbox:${mailboxIri(draft.mailbox)}` : draft.from) ?? undefined)
function selectKey(key: string) {
  const option = senders.value.find(o => optionKey(o) === key)
  draft.mailbox = option?.mailbox ?? null
  draft.from = option?.mailbox ? null : (option?.from ?? key)
}

function selectFrom(from: string | null | undefined) {
  if (!from) {
    if (!draft.mailbox) draft.from = senders.value.find(option => option.default)?.from ?? null
    return
  }
  // An address that is one of the offered mailboxes selects the mailbox.
  const email = from.match(/<([^>]+)>/)?.[1] ?? from
  const mailbox = senders.value.find(o => o.mailbox && o.email === email.trim().toLowerCase())
  if (mailbox) {
    selectKey(optionKey(mailbox))
    return
  }
  if (!senders.value.some(option => option.from === from)) {
    senders.value = [...senders.value, { from, email: from, name: null, default: false, source: 'application' }]
  }
  draft.mailbox = null
  draft.from = from
}

function selectMailbox(reference: string) {
  const option = senders.value.find(o => o.mailbox === mailboxIri(reference))
  if (option) selectKey(optionKey(option))
  else toast.add({ title: 'Boîte d’envoi indisponible', description: 'Elle n’est pas rattachée à cette application.', color: 'warning' })
}

const sendersLoaded = ref(false)

async function loadSenders() {
  try {
    const imposed = senders.value.filter(option => option.source === 'application')
    const offered = await api<SenderOption[]>('/api/senders')
    senders.value = [...offered, ...imposed.filter(option => !offered.some(o => o.from === option.from))]
    sendersLoaded.value = true
    if (draft.mailbox) selectMailbox(draft.mailbox)
    else selectFrom(draft.from)
  }
  catch (error) {
    toast.add({ title: 'Adresses d’expédition indisponibles', description: apiErrorMessage(error), color: 'warning' })
  }
}

onMounted(loadSenders)

/** Through an application with no sender configured (and no mailbox): nothing can be sent. */
const noSender = computed(() => sendersLoaded.value && senders.value.length === 0)

const canSend = computed(() => !noSender.value && draft.to.length > 0 && draft.subject.trim() !== '' && draft.htmlBody.trim() !== '' && !sending.value && !uploading.value)

function keepValidAddresses(field: 'to' | 'cc' | 'bcc') {
  const invalid = draft[field].filter(address => !isEmail(address))
  if (invalid.length) {
    draft[field] = draft[field].filter(isEmail)
    toast.add({ title: 'Adresse invalide', description: invalid.join(', '), color: 'warning' })
  }
}

function onTemplatePicked(template: EmailTemplate) {
  if (draft.htmlBody.trim() !== '') {
    pendingTemplate.value = template
    return
  }
  applyTemplate(template)
}

function applyTemplate(template: EmailTemplate) {
  // The content in its layout, as computed by the API.
  draft.htmlBody = template.renderedHtml ?? template.html
  draft.template = `/api/email_templates/${template.id}`
  templateName.value = template.name
  if (!draft.subject.trim() && template.defaultSubject) draft.subject = template.defaultSubject
  pendingTemplate.value = null
  templateVariables.value = template.variables ?? []
  variableInputs.value = {}
  // The editor receives the new content through v-model: render from the draft, not from the editor.
  const defaults = Object.fromEntries(templateVariables.value.filter(v => v.defaultValue !== null).map(v => [v.name, v.defaultValue!]))
  const values = { ...defaults, ...variableValues.value }
  draft.subject = renderPlaceholders(draft.subject, values, false)
  draft.htmlBody = renderPlaceholders(draft.htmlBody, values, true)
  refreshMissing()
}

/** Template requested by the host application: an id or an IRI. */
async function loadTemplate(reference: string) {
  const id = reference.split('/').pop()
  try {
    applyTemplate(await api<EmailTemplate>(`/api/email_templates/${encodeURIComponent(id ?? '')}`))
  }
  catch (error) {
    toast.add({ title: 'Template indisponible', description: apiErrorMessage(error), color: 'warning' })
  }
}

function applyDraft(incoming: Partial<EmailDraft>) {
  const { attachments: attachmentIds, from, mailbox, variables, template, ...fields } = incoming
  if (typeof mailbox === 'string' && mailbox) selectMailbox(mailbox)
  else if (from !== undefined) selectFrom(from)
  if (variables && typeof variables === 'object') {
    variableValues.value = { ...variableValues.value, ...flattenVariables(variables) }
  }
  Object.assign(draft, {
    ...fields,
    to: fields.to?.filter(isEmail) ?? draft.to,
    cc: fields.cc?.filter(isEmail) ?? draft.cc,
    bcc: fields.bcc?.filter(isEmail) ?? draft.bcc,
  })
  if (draft.cc.length || draft.bcc.length) showCopies.value = true
  if (Array.isArray(attachmentIds)) loadAttachments(attachmentIds)
  // A template without content of its own: load it (its variables are filled with the values above).
  if (typeof template === 'string' && template && typeof fields.htmlBody !== 'string') loadTemplate(template)
  else {
    if (template !== undefined) draft.template = template
    if (variables) fillVariables()
  }
}

/** Attachments uploaded beforehand for this user, e.g. a PDF generated by the host application. */
async function loadAttachments(ids: string[]) {
  const known = new Set(attachments.value.map(a => a.id))
  for (const id of ids.filter(id => typeof id === 'string' && !known.has(id))) {
    try {
      attachments.value = [...attachments.value, await api<Attachment>(`/api/attachments/${encodeURIComponent(id)}`)]
    }
    catch (error) {
      toast.add({ title: 'Pièce jointe indisponible', description: apiErrorMessage(error), color: 'warning' })
    }
  }
}

async function send() {
  refreshMissing(currentHtml())
  if (missingVariables.value.length) {
    toast.add({ title: 'Variables à compléter', description: missingVariables.value.map(v => v.label || v.name).join(', '), color: 'warning' })
    return
  }
  if (!canSend.value) return
  sending.value = true
  try {
    const email = await api<Email>('/api/emails', {
      method: 'POST',
      body: { ...draft, attachments: attachments.value.map(a => `/api/attachments/${a.id}`) },
    })
    toast.add({ title: 'Message envoyé', description: email.subject, color: 'success', icon: 'i-lucide-check' })
    emit('sent', email)
    const { from, mailbox } = draft
    Object.assign(draft, emptyDraft())
    Object.assign(draft, { from, mailbox })
    attachments.value = []
    templateName.value = null
    templateVariables.value = []
    missingVariables.value = []
  }
  catch (error) {
    toast.add({ title: 'Envoi impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    sending.value = false
  }
}

// "Intégrer": the current draft as the calling application would pass it. With a template, the template
// provides the content and the subject; its variables become values to fill ("client.prenom": "Prénom du client").
const embedOpen = ref(false)
const embedDraft = computed<EmbedDraft>(() => ({
  // The default sender is preselected anyway: only an explicit choice is worth passing.
  from: !draft.mailbox && draft.from !== senders.value.find(option => option.default)?.from ? draft.from : null,
  mailbox: draft.mailbox ? draft.mailbox.split('/').pop() : null,
  to: draft.to,
  cc: draft.cc,
  bcc: draft.bcc,
  subject: draft.template ? undefined : draft.subject,
  template: draft.template,
  variables: templateVariables.value.length
    ? nestVariables(Object.fromEntries(templateVariables.value.map(v => [v.name, variableValues.value[v.name] ?? `… (${v.label || v.name})`])))
    : undefined,
}))

defineExpose({ applyDraft })
</script>

<template>
  <form class="flex flex-col gap-4" @submit.prevent="send">
    <UAlert
      v-if="noSender"
      color="error"
      variant="subtle"
      icon="i-lucide-mail-x"
      title="Aucune adresse d’expédition"
      description="Cette application n’a pas d’expéditeur configuré. Un administrateur de Rocket Mailer doit le définir (Administration → Applications)."
      data-testid="no-sender"
    />
    <UFormField v-else label="De" required>
      <USelect
        :model-value="fromKey"
        :items="senderItems"
        :loading="!sendersLoaded"
        placeholder="Adresse d’expédition"
        class="w-full"
        data-testid="from-select"
        @update:model-value="(value: string) => selectKey(value)"
      />
    </UFormField>

    <UFormField label="À" required>
      <div class="flex items-start gap-2">
        <UInputTags
          v-model="draft.to"
          placeholder="destinataire@exemple.com"
          add-on-blur
          add-on-paste
          class="flex-1"
          @update:model-value="keepValidAddresses('to')"
        />
        <UButton
          v-if="!showCopies"
          label="Cc / Cci"
          color="neutral"
          variant="ghost"
          @click="showCopies = true"
        />
      </div>
    </UFormField>

    <template v-if="showCopies">
      <UFormField label="Cc">
        <UInputTags v-model="draft.cc" add-on-blur add-on-paste class="w-full" @update:model-value="keepValidAddresses('cc')" />
      </UFormField>
      <UFormField label="Cci">
        <UInputTags v-model="draft.bcc" add-on-blur add-on-paste class="w-full" @update:model-value="keepValidAddresses('bcc')" />
      </UFormField>
    </template>

    <UFormField label="Objet" required>
      <UInput v-model="draft.subject" maxlength="255" class="w-full" />
    </UFormField>

    <div class="flex flex-wrap items-center gap-2">
      <UButton
        icon="i-lucide-layout-template"
        label="Importer un template"
        color="neutral"
        variant="outline"
        @click="pickerOpen = true"
      />
      <UBadge v-if="templateName" :label="templateName" variant="subtle" color="neutral" />
      <UButton
        v-if="embeddable"
        icon="i-lucide-code-xml"
        label="Intégrer"
        color="neutral"
        variant="ghost"
        class="ms-auto"
        data-testid="embed-button"
        @click="embedOpen = true"
      />
    </div>

    <UAlert
      v-if="missingVariables.length"
      color="warning"
      variant="subtle"
      icon="i-lucide-braces"
      title="Variables à compléter"
      data-testid="missing-variables"
    >
      <template #description>
        <form class="mt-2 grid gap-2 sm:grid-cols-2" @submit.prevent="applyTypedValues">
          <UFormField v-for="variable in missingVariables" :key="variable.name" :label="variable.label || variable.name" :hint="variable.label ? variable.name : undefined">
            <UInput v-model="variableInputs[variable.name]" class="w-full" :data-testid="`variable-${variable.name}`" />
          </UFormField>
          <div class="flex items-end sm:col-span-2">
            <UButton type="submit" label="Remplacer dans le message" icon="i-lucide-replace" size="sm" />
          </div>
        </form>
      </template>
    </UAlert>

    <AttachmentsField v-model="attachments" :disabled="sending" @busy="uploading = $event" />

    <ClientOnly>
      <RichTextEditor ref="editor" v-model="draft.htmlBody" :disabled="sending" />
      <template #fallback>
        <div class="h-[420px] animate-pulse rounded-md bg-elevated" />
      </template>
    </ClientOnly>

    <div class="flex justify-end">
      <UButton type="submit" icon="i-lucide-send" label="Envoyer" :loading="sending" :disabled="!canSend" />
    </div>

    <TemplatePicker v-model:open="pickerOpen" @pick="onTemplatePicked" />
    <EmbedCodeModal v-if="embeddable" v-model:open="embedOpen" :draft="embedDraft" />

    <UModal
      :open="pendingTemplate !== null"
      title="Remplacer le contenu ?"
      description="Le message en cours sera remplacé par le contenu du template."
      @update:open="(value: boolean) => { if (!value) pendingTemplate = null }"
    >
      <template #footer>
        <div class="flex w-full justify-end gap-2">
          <UButton label="Annuler" color="neutral" variant="ghost" @click="pendingTemplate = null" />
          <UButton label="Remplacer" color="warning" @click="applyTemplate(pendingTemplate!)" />
        </div>
      </template>
    </UModal>
  </form>
</template>
