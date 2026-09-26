<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { SenderAddress, Settings } from '~/types/api'

definePageMeta({ admin: true })
useHead({ title: 'Réglages · Rocket Mailer' })

const api = useApi()
const toast = useToast()
// Right after the first-run setup.
const welcome = ref(useRoute().query.welcome !== undefined)
const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')

// Loading the settings first seeds the sender addresses from MAILER_DEFAULT_FROM on a fresh install.
const { data: settings } = await useAsyncData('settings', () => api<Settings>('/api/settings'))
const { data: senders, refresh } = await useAsyncData('sender-addresses', () => api<SenderAddress[]>('/api/sender_addresses'), { default: () => [] })

async function togglePersonal(value: boolean) {
  try {
    settings.value = { ...settings.value!, ...await api<Settings>('/api/settings', { method: 'PATCH', body: { personalFromAllowed: value } }) }
    toast.add({ title: 'Réglage enregistré', color: 'success' })
  }
  catch (error) {
    toast.add({ title: 'Enregistrement impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

async function makeDefault(sender: SenderAddress) {
  try {
    await api(`/api/sender_addresses/${sender.id}`, { method: 'PATCH', body: { isDefault: true } })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Modification impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

async function remove(sender: SenderAddress) {
  try {
    await api(`/api/sender_addresses/${sender.id}`, { method: 'DELETE' })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Suppression impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

const columns: TableColumn<SenderAddress>[] = [
  { accessorKey: 'name', header: 'Nom affiché', cell: ({ row }) => row.original.name ?? '—' },
  { accessorKey: 'email', header: 'Adresse' },
  {
    accessorKey: 'isDefault',
    header: 'Par défaut',
    cell: ({ row }) => row.original.isDefault
      ? h(UBadge, { label: 'Par défaut', variant: 'subtle', color: 'primary' })
      : h(UButton, { label: 'Définir par défaut', size: 'xs', color: 'neutral', variant: 'outline', onClick: () => makeDefault(row.original) }),
  },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'flex justify-end' }, [
      h(UButton, { 'icon': 'i-lucide-trash-2', 'color': 'error', 'variant': 'ghost', 'aria-label': `Supprimer ${row.original.email}`, 'onClick': () => remove(row.original) }),
    ]),
  },
]

const form = reactive({ name: '', email: '', isDefault: false })
async function add() {
  try {
    await api('/api/sender_addresses', { method: 'POST', body: { ...form, name: form.name || null } })
    Object.assign(form, { name: '', email: '', isDefault: false })
    await refresh()
    toast.add({ title: 'Adresse ajoutée', color: 'success' })
  }
  catch (error) {
    toast.add({ title: 'Ajout impossible', description: apiErrorMessage(error), color: 'error' })
  }
}
</script>

<template>
  <UDashboardPanel id="settings">
    <template #header>
      <UDashboardNavbar title="Réglages">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="mx-auto flex w-full max-w-4xl flex-col gap-6">
        <UAlert
          v-if="welcome"
          color="success"
          variant="subtle"
          icon="i-lucide-party-popper"
          title="Rocket Mailer est prêt"
          :close="true"
          data-testid="welcome"
          @update:open="welcome = false"
        >
          <template #description>
            <p>Votre compte administrateur est créé. Prochaines étapes :</p>
            <ol class="mt-1 list-decimal ps-5">
              <li>vérifier ci-dessous l’adresse d’expédition par défaut (issue de <code>MAILER_DEFAULT_FROM</code>) ;</li>
              <li>créer les <ULink to="/users" class="underline">utilisateurs</ULink>, ou les synchroniser depuis l’annuaire LDAP ;</li>
              <li>déclarer les <ULink to="/applications" class="underline">applications</ULink> qui embarqueront le composeur.</li>
            </ol>
          </template>
        </UAlert>
        <UPageCard
          title="Adresses d'expédition"
          description="Proposées dans la liste « De » du composeur. L'adresse par défaut est présélectionnée ; les réponses arrivent toujours à l'utilisateur (Reply-To)."
          variant="subtle"
        >
          <UTable :data="senders" :columns="columns" empty="Aucune adresse : les utilisateurs envoient depuis leur propre adresse." />

          <form class="flex flex-wrap items-end gap-3" @submit.prevent="add">
            <UFormField label="Nom affiché" class="min-w-48 flex-1">
              <UInput v-model="form.name" placeholder="Service client" class="w-full" />
            </UFormField>
            <UFormField label="Adresse" required class="min-w-64 flex-1">
              <UInput v-model="form.email" type="email" placeholder="contact@exemple.com" class="w-full" />
            </UFormField>
            <USwitch v-model="form.isDefault" label="Par défaut" class="pb-2" />
            <UButton type="submit" icon="i-lucide-plus" label="Ajouter" :disabled="!form.email" />
          </form>

          <p v-if="settings?.installDefaultFrom" class="text-xs text-muted">
            Adresse initiale (MAILER_DEFAULT_FROM) : {{ settings.installDefaultFrom }}
          </p>
        </UPageCard>

        <UPageCard
          title="Adresse personnelle"
          description="Autoriser chaque utilisateur à envoyer depuis sa propre adresse. Désactivez-le si votre relais SMTP n'est pas autorisé à émettre pour les adresses de vos utilisateurs (SPF/DKIM)."
          variant="subtle"
        >
          <USwitch
            :model-value="settings?.personalFromAllowed ?? true"
            label="Les utilisateurs peuvent envoyer depuis leur adresse"
            @update:model-value="togglePersonal"
          />
        </UPageCard>

        <UAlert
          icon="i-lucide-info"
          color="neutral"
          variant="subtle"
          description="Ces adresses sont celles de Rocket Mailer : les applications externes ne les utilisent jamais. Chacune a son propre expéditeur (page Applications) ; l'adresse par défaut ci-dessus y est seulement proposée en suggestion."
        />
      </div>
    </template>
  </UDashboardPanel>
</template>
