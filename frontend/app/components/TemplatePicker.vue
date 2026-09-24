<script setup lang="ts">
import type { EmailTemplate, EmailTemplateSummary } from '~/types/api'

const emit = defineEmits<{ pick: [template: EmailTemplate] }>()
const open = defineModel<boolean>('open', { default: false })

const api = useApi()
const toast = useToast()
const search = ref('')
const loadingId = ref<string | null>(null)

const { data: templates, status, refresh } = useAsyncData(
  'template-picker',
  () => api<EmailTemplateSummary[]>('/api/email_templates', { query: { itemsPerPage: 200 } }),
  { immediate: false, default: () => [] },
)

watch(open, (value) => {
  if (value) refresh()
})

const filtered = computed(() => {
  const term = search.value.trim().toLowerCase()
  return term
    ? templates.value.filter(t => `${t.name} ${t.description ?? ''}`.toLowerCase().includes(term))
    : templates.value
})

async function pick(summary: EmailTemplateSummary) {
  loadingId.value = summary.id
  try {
    emit('pick', await api<EmailTemplate>(`/api/email_templates/${summary.id}`))
    open.value = false
  }
  catch (error) {
    toast.add({ title: 'Import impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    loadingId.value = null
  }
}
</script>

<template>
  <UModal v-model:open="open" title="Importer un template" :ui="{ content: 'max-w-2xl' }">
    <template #body>
      <UInput v-model="search" icon="i-lucide-search" placeholder="Rechercher un template…" class="mb-4 w-full" />

      <div v-if="status === 'pending'" class="py-8 text-center text-muted">
        Chargement…
      </div>
      <UEmpty
        v-else-if="!filtered.length"
        icon="i-lucide-layout-template"
        title="Aucun template"
        description="Créez un template dans l'éditeur de templates pour pouvoir l'importer ici."
      />
      <ul v-else class="divide-y divide-default">
        <li v-for="template in filtered" :key="template.id" class="flex items-center gap-3 py-3">
          <div class="min-w-0 flex-1">
            <p class="truncate font-medium">
              {{ template.name }}
              <UBadge v-if="template.shared" label="Partagé" variant="subtle" size="sm" class="ml-1" />
            </p>
            <p class="truncate text-sm text-muted">
              {{ template.description || `Par ${template.owner.displayName}` }}
            </p>
          </div>
          <UButton label="Importer" size="sm" :loading="loadingId === template.id" @click="pick(template)" />
        </li>
      </ul>
    </template>
  </UModal>
</template>
