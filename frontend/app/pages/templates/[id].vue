<script setup lang="ts">
import type { EmailTemplate, TemplateVersion } from '~/types/api'

const route = useRoute()
const api = useApi()
const auth = useAuth()
const toast = useToast()

const id = computed(() => route.params.id as string)
const isNew = computed(() => id.value === 'new')

const meta = reactive({ name: '', description: '', defaultSubject: '', shared: false })
const template = ref<EmailTemplate | null>(null)
const editorKey = ref(0)
const editor = useTemplateRef<{ getData: () => { html: string, projectData: Record<string, unknown> } }>('editor')
const saving = ref(false)
const dirty = ref(false)

const canEdit = computed(() => isNew.value || auth.isAdmin.value || template.value?.owner.id === auth.me.value?.user?.id)

async function load() {
  if (isNew.value) return
  template.value = await api<EmailTemplate>(`/api/email_templates/${id.value}`)
  Object.assign(meta, {
    name: template.value.name,
    description: template.value.description ?? '',
    defaultSubject: template.value.defaultSubject ?? '',
    shared: template.value.shared,
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
    const body = {
      ...meta,
      description: meta.description || null,
      defaultSubject: meta.defaultSubject || null,
      ...editor.value!.getData(),
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
  name: 'nom', description: 'description', defaultSubject: 'objet', html: 'contenu', projectData: 'mise en page', shared: 'partage',
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
        />
      </ClientOnly>

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
