<script setup lang="ts">
import type { EmailLayout } from '~/types/api'

definePageMeta({ admin: true })
useHead({ title: 'Layouts d’email · Rocket Mailer' })

const api = useApi()
const toast = useToast()

const { data: layouts, status, refresh } = await useAsyncData('layouts', () => api<EmailLayout[]>('/api/email_layouts'), { default: () => [] })

// Braces would be read by Vue's template syntax: keep them in script strings.
const SLOT_TEXT = '{{ content }}'

const selectedId = ref<string | null>(null)
const form = reactive({ name: '', description: '', html: '' })
const saving = ref(false)
const toDelete = ref<EmailLayout | null>(null)

const isNew = computed(() => selectedId.value === 'new')
const editing = computed(() => selectedId.value !== null)
const slotMissing = computed(() => form.html.trim() !== '' && !hasSlot(form.html))
const preview = computed(() => (form.html ? wrapInLayout(form.html, LAYOUT_PREVIEW_CONTENT) : ''))

function open(layout: EmailLayout) {
  selectedId.value = layout.id
  Object.assign(form, { name: layout.name, description: layout.description ?? '', html: layout.html })
}

function create(starter = LAYOUT_STARTERS[0]!) {
  selectedId.value = 'new'
  Object.assign(form, { name: '', description: '', html: starter.html })
}

async function save() {
  saving.value = true
  try {
    const body = { name: form.name, description: form.description || null, html: form.html }
    const saved = isNew.value
      ? await api<EmailLayout>('/api/email_layouts', { method: 'POST', body })
      : await api<EmailLayout>(`/api/email_layouts/${selectedId.value}`, { method: 'PATCH', body })
    await refresh()
    selectedId.value = saved.id
    toast.add({ title: 'Layout enregistré', color: 'success', icon: 'i-lucide-check' })
  }
  catch (error) {
    toast.add({ title: 'Enregistrement impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    saving.value = false
  }
}

async function remove() {
  if (!toDelete.value) return
  try {
    await api(`/api/email_layouts/${toDelete.value.id}`, { method: 'DELETE' })
    if (selectedId.value === toDelete.value.id) selectedId.value = null
    toDelete.value = null
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Suppression impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

function insertSlot() {
  form.html = form.html.includes('</body>') ? form.html.replace('</body>', `${SLOT_TEXT}\n</body>`) : `${form.html}\n${SLOT_TEXT}`
}
</script>

<template>
  <UDashboardPanel id="layouts">
    <template #header>
      <UDashboardNavbar title="Layouts d’email">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UDropdownMenu :items="LAYOUT_STARTERS.map(s => ({ label: s.label, onSelect: () => create(s) }))">
            <UButton icon="i-lucide-plus" label="Nouveau layout" trailing-icon="i-lucide-chevron-down" data-testid="new-layout" />
          </UDropdownMenu>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="grid gap-6 xl:grid-cols-[320px_1fr]">
        <div class="flex flex-col gap-3">
          <p class="text-sm text-muted">
            Un layout habille les templates : en-tête, pied de page, couleurs. Son HTML contient l’emplacement <code>{{ SLOT_TEXT }}</code>, où vient le contenu de chaque template.
          </p>
          <div v-if="status === 'pending'" class="text-sm text-muted">
            Chargement…
          </div>
          <UEmpty v-else-if="!layouts.length" icon="i-lucide-panels-top-left" title="Aucun layout" description="Partez d’un modèle avec « Nouveau layout »." />
          <button
            v-for="layout in layouts"
            :key="layout.id"
            type="button"
            class="flex items-center gap-3 rounded-lg border p-3 text-left transition hover:bg-elevated/50"
            :class="selectedId === layout.id ? 'border-primary' : 'border-default'"
            @click="open(layout)"
          >
            <UIcon name="i-lucide-panels-top-left" class="size-5 shrink-0 text-primary" />
            <span class="min-w-0 flex-1">
              <span class="block truncate font-medium">{{ layout.name }}</span>
              <span class="block truncate text-xs text-muted">{{ layout.description || `Modifié le ${formatDate(layout.updatedAt)}` }}</span>
            </span>
            <UButton icon="i-lucide-trash-2" color="error" variant="ghost" size="xs" aria-label="Supprimer" @click.stop="toDelete = layout" />
          </button>
        </div>

        <form v-if="editing" class="flex min-w-0 flex-col gap-4" data-testid="layout-form" @submit.prevent="save">
          <div class="grid gap-3 sm:grid-cols-2">
            <UFormField label="Nom" required>
              <UInput v-model="form.name" placeholder="Charte de l’entreprise" class="w-full" />
            </UFormField>
            <UFormField label="Description">
              <UInput v-model="form.description" class="w-full" />
            </UFormField>
          </div>
          <div class="grid gap-4 2xl:grid-cols-2">
            <UFormField label="HTML" required :error="slotMissing ? `L’emplacement ${SLOT_TEXT} est obligatoire.` : undefined">
              <template #hint>
                <UButton :label="`Insérer ${SLOT_TEXT}`" size="xs" color="neutral" variant="link" @click="insertSlot" />
              </template>
              <UTextarea v-model="form.html" :rows="24" autoresize :maxrows="40" class="w-full font-mono text-xs" data-testid="layout-html" />
            </UFormField>
            <UFormField label="Aperçu" help="Avec un contenu d’exemple. Les variables comme {{ client.prenom }} sont remplacées à l’envoi.">
              <!-- Untrusted HTML: sandboxed, no scripts. -->
              <iframe :srcdoc="preview" sandbox="" title="Aperçu du layout" class="h-[560px] w-full rounded-md border border-default bg-white" data-testid="layout-preview" />
            </UFormField>
          </div>
          <div class="flex gap-2">
            <UButton type="submit" label="Enregistrer" icon="i-lucide-save" :loading="saving" :disabled="slotMissing || !form.name.trim()" />
            <UButton label="Fermer" color="neutral" variant="ghost" @click="selectedId = null" />
          </div>
        </form>
      </div>

      <UModal :open="toDelete !== null" title="Supprimer le layout ?" :description="toDelete ? `Les templates qui utilisent « ${toDelete.name} » n’auront plus de layout.` : ''" @update:open="(value: boolean) => { if (!value) toDelete = null }">
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
