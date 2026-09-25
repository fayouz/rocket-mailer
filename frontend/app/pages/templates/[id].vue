<script setup lang="ts">
import type { EmailLayout, EmailTemplate, TemplateVariable, TemplateVersion } from '~/types/api'

const route = useRoute()
const api = useApi()
const auth = useAuth()
const toast = useToast()

const id = computed(() => route.params.id as string)
const isNew = computed(() => id.value === 'new')

const meta = reactive({ name: '', description: '', defaultSubject: '', shared: false, layout: null as string | null })

// Layouts: the organization's wrappers (header, footer…) around the content.
const { data: layouts } = await useAsyncData('template-layouts', () => api<EmailLayout[]>('/api/email_layouts'), { default: () => [] })
const layoutItems = computed(() => [
  { label: 'Aucun layout', value: 'none' },
  ...layouts.value.map(l => ({ label: l.name, value: `/api/email_layouts/${l.id}`, description: l.description ?? undefined })),
])
const previewOpen = ref(false)
const previewHtml = ref('')
async function openPreview() {
  const html = await editor.value?.getHtml() ?? ''
  const layout = layouts.value.find(l => meta.layout?.endsWith(l.id))
  previewHtml.value = layout ? wrapInLayout(layout.html, html) : html
  previewOpen.value = true
}
const template = ref<EmailTemplate | null>(null)
const editorKey = ref(0)
const editor = useTemplateRef<{
  getData: () => Promise<{ html: string, projectData: Record<string, unknown> }>
  getHtml: () => Promise<string>
  insertVariable: (name: string) => void
}>('editor')
const saving = ref(false)
const dirty = ref(false)

// Variables: "{{ name }}" placeholders, filled by the composer or by the application that sends the email.
const variables = ref<TemplateVariable[]>([])
const variablesOpen = ref(false)
const pickerOpen = ref(false)
const newVariable = ref('')

/** Adds the placeholders typed in the content or the subject that are not declared yet. */
function syncVariables(html: string) {
  const declared = new Set(variables.value.map(v => v.name))
  for (const name of placeholderNames(meta.defaultSubject, html)) {
    if (!declared.has(name)) variables.value.push({ name, label: null, defaultValue: null })
  }
}

const usedVariables = ref<string[]>([])
async function openVariables() {
  const html = await editor.value?.getHtml() ?? ''
  syncVariables(html)
  usedVariables.value = placeholderNames(meta.defaultSubject, html)
  variablesOpen.value = true
}

function addVariable(name: string): boolean {
  const trimmed = name.trim()
  if (!VARIABLE_NAME.test(trimmed)) {
    toast.add({ title: 'Nom de variable invalide', description: 'Lettres, chiffres, « _ » et « . » : par exemple client.prenom', color: 'warning' })
    return false
  }
  if (!variables.value.some(v => v.name === trimmed)) variables.value.push({ name: trimmed, label: null, defaultValue: null })
  dirty.value = true
  return true
}

function insertVariable(name: string) {
  if (!addVariable(name)) return
  editor.value?.insertVariable(name.trim())
  pickerOpen.value = false
  newVariable.value = ''
}

function removeVariable(index: number) {
  variables.value.splice(index, 1)
  dirty.value = true
}

const canEdit = computed(() => isNew.value || auth.isAdmin.value || template.value?.owner.id === auth.me.value?.user?.id)

async function load() {
  if (isNew.value) return
  template.value = await api<EmailTemplate>(`/api/email_templates/${id.value}`)
  variables.value = template.value.variables.map(({ name, label, defaultValue }) => ({ name, label, defaultValue }))
  Object.assign(meta, {
    name: template.value.name,
    description: template.value.description ?? '',
    defaultSubject: template.value.defaultSubject ?? '',
    shared: template.value.shared,
    layout: template.value.layout?.['@id'] ?? null,
  })
  editorKey.value++
  dirty.value = false
}

try {
  await load()
}
catch (error) {
  toast.add({ title: 'Template introuvable', description: apiErrorMessage(error), color: 'error' })
  await navigateTo('/templates')
}

useHead({ title: computed(() => `${meta.name || 'Nouveau template'} · Rocket Mailer`) })

async function save() {
  if (!meta.name.trim()) {
    toast.add({ title: 'Le nom est obligatoire', color: 'warning' })
    return
  }
  saving.value = true
  try {
    const content = await editor.value!.getData()
    syncVariables(content.html)
    const body = {
      ...meta,
      description: meta.description || null,
      defaultSubject: meta.defaultSubject || null,
      variables: variables.value.filter(v => v.name.trim()),
      ...content,
    }
    const saved = isNew.value
      ? await api<EmailTemplate>('/api/email_templates', { method: 'POST', body })
      : await api<EmailTemplate>(`/api/email_templates/${id.value}`, { method: 'PATCH', body })
    template.value = saved
    dirty.value = false
    toast.add({ title: 'Template enregistré', color: 'success', icon: 'i-lucide-check' })
    if (isNew.value) await navigateTo(`/templates/${saved.id}`, { replace: true })
  }
  catch (error) {
    toast.add({ title: 'Enregistrement impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    saving.value = false
  }
}

// Version history (Gedmo Loggable)
const historyOpen = ref(false)
const versions = ref<TemplateVersion[]>([])
const restoring = ref<number | null>(null)

const fieldLabels: Record<string, string> = {
  name: 'nom', description: 'description', defaultSubject: 'objet', html: 'contenu', projectData: 'mise en page', shared: 'partage', variables: 'variables', layout: 'layout',
}

async function openHistory() {
  historyOpen.value = true
  versions.value = await api<TemplateVersion[]>(`/api/email_templates/${id.value}/versions`)
}

async function restore(version: number) {
  restoring.value = version
  try {
    await api(`/api/email_templates/${id.value}/versions/${version}/restore`, { method: 'POST' })
    await load()
    versions.value = await api<TemplateVersion[]>(`/api/email_templates/${id.value}/versions`)
    toast.add({ title: `Version ${version} restaurée`, color: 'success' })
  }
  catch (error) {
    toast.add({ title: 'Restauration impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    restoring.value = null
  }
}

onBeforeRouteLeave(() => {
  if (dirty.value && !window.confirm('Des modifications ne sont pas enregistrées. Quitter quand même ?')) return false
})
</script>

<template>
  <UDashboardPanel id="template-editor" :ui="{ body: 'p-0 sm:p-0 gap-0' }">
    <template #header>
      <UDashboardNavbar :title="isNew ? 'Nouveau template' : meta.name">
        <template #leading>
          <UButton icon="i-lucide-arrow-left" color="neutral" variant="ghost" to="/templates" aria-label="Retour" />
        </template>
        <template #right>
          <UButton
            icon="i-lucide-eye"
            label="Aperçu"
            color="neutral"
            variant="outline"
            data-testid="template-preview"
            @click="openPreview"
          />
          <UButton
            icon="i-lucide-braces"
            :label="`Variables${variables.length ? ` (${variables.length})` : ''}`"
            color="neutral"
            variant="outline"
            data-testid="variables-button"
            @click="openVariables"
          />
          <UButton
            v-if="!isNew"
            icon="i-lucide-history"
            label="Historique"
            color="neutral"
            variant="outline"
            @click="openHistory"
          />
          <UButton
            v-if="canEdit"
            icon="i-lucide-save"
            label="Enregistrer"
            :loading="saving"
            @click="save"
          />
        </template>
      </UDashboardNavbar>

      <UDashboardToolbar>
        <div class="flex w-full flex-wrap items-end gap-3 py-2">
          <UFormField label="Nom" required class="min-w-56 flex-1">
            <UInput v-model="meta.name" :disabled="!canEdit" class="w-full" @update:model-value="dirty = true" />
          </UFormField>
          <UFormField label="Objet par défaut" class="min-w-56 flex-1">
            <UInput v-model="meta.defaultSubject" :disabled="!canEdit" class="w-full" @update:model-value="dirty = true" />
          </UFormField>
          <UFormField label="Description" class="min-w-56 flex-[2]">
            <UInput v-model="meta.description" :disabled="!canEdit" class="w-full" @update:model-value="dirty = true" />
          </UFormField>
          <UFormField label="Layout" class="min-w-48">
            <USelect
              :model-value="meta.layout ?? 'none'"
              :items="layoutItems"
              :disabled="!canEdit"
              class="w-full"
              data-testid="template-layout"
              @update:model-value="(value: string) => { meta.layout = value === 'none' ? null : value; dirty = true }"
            />
          </UFormField>
          <USwitch v-model="meta.shared" :disabled="!canEdit" label="Partagé" class="pb-2" @update:model-value="dirty = true" />
        </div>
      </UDashboardToolbar>
    </template>

    <template #body>
      <ClientOnly>
        <GrapesEditor
          ref="editor"
          :key="editorKey"
          :project-data="template?.projectData ?? null"
          :html="template?.html"
          class="flex-1"
          @change="dirty = true"
          @variable-request="pickerOpen = true"
        />
      </ClientOnly>

      <UModal v-model:open="previewOpen" title="Aperçu" description="Le template dans son layout, tel qu’il sera importé dans le composeur." :ui="{ content: 'max-w-4xl' }">
        <template #body>
          <!-- Untrusted HTML: sandboxed, no scripts. -->
          <iframe :srcdoc="previewHtml" sandbox="" title="Aperçu du template" class="h-[70vh] w-full rounded-md border border-default bg-white" data-testid="template-preview-frame" />
        </template>
      </UModal>

      <UModal v-model:open="pickerOpen" title="Insérer une variable" description="Elle sera remplacée par sa valeur à l’envoi, par exemple le prénom du client.">
        <template #body>
          <div class="flex flex-col gap-4">
            <ul v-if="variables.length" class="flex flex-col gap-1" data-testid="variable-choices">
              <li v-for="variable in variables" :key="variable.name">
                <UButton
                  color="neutral"
                  variant="ghost"
                  class="w-full justify-between"
                  @click="insertVariable(variable.name)"
                >
                  <span>{{ variable.label || variable.name }}</span>
                  <code class="text-xs text-muted">{{ `{{ ${variable.name} }\u007d` }}</code>
                </UButton>
              </li>
            </ul>
            <form class="flex items-end gap-2" @submit.prevent="insertVariable(newVariable)">
              <UFormField label="Nouvelle variable" hint="ex. client.prenom" class="flex-1">
                <UInput v-model="newVariable" placeholder="client.prenom" class="w-full" autofocus data-testid="new-variable" />
              </UFormField>
              <UButton type="submit" label="Insérer" :disabled="!newVariable.trim()" />
            </form>
          </div>
        </template>
      </UModal>

      <USlideover v-model:open="variablesOpen" title="Variables du template" :ui="{ content: 'max-w-2xl' }">
        <template #body>
          <div class="flex flex-col gap-4 text-sm">
            <p class="text-muted">
              Écrivez <code>{{ '{{ client.prenom }\u007d' }}</code> dans le texte ou l’objet, ou utilisez le bouton <code>{x}</code> de la barre d’édition du texte.
              À l’envoi, chaque variable est remplacée par la valeur donnée par le composeur ou par l’application, sinon par sa valeur par défaut.
            </p>
            <div v-for="(variable, index) in variables" :key="index" class="grid grid-cols-1 gap-2 rounded-md border border-default p-3 sm:grid-cols-[1fr_1fr_1fr_auto]" data-testid="variable-row">
              <UFormField label="Nom">
                <UInput v-model="variable.name" :disabled="!canEdit" class="w-full font-mono" @update:model-value="dirty = true" />
              </UFormField>
              <UFormField label="Libellé">
                <UInput :model-value="variable.label ?? ''" :disabled="!canEdit" placeholder="Prénom du client" class="w-full" @update:model-value="(v: string) => { variable.label = v || null; dirty = true }" />
              </UFormField>
              <UFormField label="Valeur par défaut">
                <UInput :model-value="variable.defaultValue ?? ''" :disabled="!canEdit" placeholder="Aucune : obligatoire" class="w-full" @update:model-value="(v: string) => { variable.defaultValue = v || null; dirty = true }" />
              </UFormField>
              <div class="flex items-end gap-1 pb-1">
                <UBadge v-if="!usedVariables.includes(variable.name)" label="Inutilisée" color="warning" variant="subtle" size="sm" />
                <UButton v-if="canEdit" icon="i-lucide-trash-2" color="neutral" variant="ghost" aria-label="Retirer" @click="removeVariable(index)" />
              </div>
            </div>
            <form v-if="canEdit" class="flex items-end gap-2" @submit.prevent="addVariable(newVariable) && (newVariable = '')">
              <UFormField label="Ajouter une variable" class="flex-1">
                <UInput v-model="newVariable" placeholder="client.prenom" class="w-full font-mono" />
              </UFormField>
              <UButton type="submit" icon="i-lucide-plus" label="Ajouter" color="neutral" variant="outline" :disabled="!newVariable.trim()" />
            </form>
          </div>
        </template>
      </USlideover>

      <USlideover v-model:open="historyOpen" title="Historique des versions">
        <template #body>
          <ul class="divide-y divide-default">
            <li v-for="entry in versions" :key="entry.version" class="flex items-center gap-3 py-3">
              <div class="min-w-0 flex-1">
                <p class="font-medium">
                  Version {{ entry.version }}
                  <span class="text-sm font-normal text-muted">· {{ entry.action === 'create' ? 'création' : 'modification' }}</span>
                </p>
                <p class="text-sm text-muted">
                  {{ formatDate(entry.loggedAt) }} · {{ entry.username ?? 'système' }}
                </p>
                <p v-if="entry.changedFields.length" class="text-xs text-dimmed">
                  {{ entry.changedFields.map(f => fieldLabels[f] ?? f).join(', ') }}
                </p>
              </div>
              <UButton
                v-if="canEdit && entry.version !== versions[0]?.version"
                label="Restaurer"
                size="sm"
                color="neutral"
                variant="outline"
                :loading="restoring === entry.version"
                @click="restore(entry.version)"
              />
            </li>
          </ul>
        </template>
      </USlideover>
    </template>
  </UDashboardPanel>
</template>
