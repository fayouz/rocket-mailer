<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { EmailTemplateSummary } from '~/types/api'

useHead({ title: 'Templates · Rocket Mailer' })

const api = useApi()
const toast = useToast()
const auth = useAuth()
const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')

const { data: templates, status, refresh } = await useAsyncData('templates', () => api<EmailTemplateSummary[]>('/api/email_templates', { query: { itemsPerPage: 200 } }), { default: () => [] })

const toDelete = ref<EmailTemplateSummary | null>(null)

function canEdit(template: EmailTemplateSummary) {
  return auth.isAdmin.value || template.owner.id === auth.me.value?.user?.id
}

const columns: TableColumn<EmailTemplateSummary>[] = [
  {
    accessorKey: 'name',
    header: 'Nom',
    cell: ({ row }) => h('div', [
      h('p', { class: 'font-medium' }, row.original.name),
      row.original.description ? h('p', { class: 'text-sm text-muted' }, row.original.description) : null,
    ]),
  },
  { id: 'owner', header: 'Propriétaire', cell: ({ row }) => row.original.owner.displayName },
  {
    accessorKey: 'shared',
    header: 'Visibilité',
    cell: ({ row }) => h(UBadge, { variant: 'subtle', color: row.original.shared ? 'primary' : 'neutral', label: row.original.shared ? 'Partagé' : 'Privé' }),
  },
  { accessorKey: 'updatedAt', header: 'Modifié', cell: ({ row }) => `${formatDate(row.original.updatedAt)} · ${row.original.updatedBy ?? ''}` },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'flex justify-end gap-1' }, [
      h(UButton, { icon: canEdit(row.original) ? 'i-lucide-pencil' : 'i-lucide-eye', color: 'neutral', variant: 'ghost', to: `/templates/${row.original.id}`, 'aria-label': 'Ouvrir' }),
      canEdit(row.original)
        ? h(UButton, { icon: 'i-lucide-trash-2', color: 'error', variant: 'ghost', 'aria-label': 'Supprimer', onClick: () => (toDelete.value = row.original) })
        : null,
    ]),
  },
]

async function remove() {
  if (!toDelete.value) return
  try {
    await api(`/api/email_templates/${toDelete.value.id}`, { method: 'DELETE' })
    toast.add({ title: 'Template supprimé', color: 'success' })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Suppression impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    toDelete.value = null
  }
}
</script>

<template>
  <UDashboardPanel id="templates">
    <template #header>
      <UDashboardNavbar title="Templates d'email">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-plus" label="Nouveau template" to="/templates/new" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UTable :data="templates" :columns="columns" :loading="status === 'pending'" empty="Aucun template." />

      <UModal
        :open="toDelete !== null"
        title="Supprimer ce template ?"
        :description="toDelete ? `« ${toDelete.name} » ne pourra plus être importé.` : ''"
        @update:open="(value: boolean) => { if (!value) toDelete = null }"
      >
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
