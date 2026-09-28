<script setup lang="ts">
import type { InboxMember } from '~/types/api'

/** Members of a shared inbox (managers and administrators): add by address, change the role, remove. */
const props = defineProps<{ mailbox: { id: string, name: string } | null, note?: string }>()
const emit = defineEmits<{ close: [] }>()

const api = useApi()
const toast = useToast()
const members = ref<InboxMember[]>([])
const loading = ref(false)
const email = ref('')
const role = ref<InboxMember['role']>('member')
const adding = ref(false)
// Kept while the dialog closes (its title would otherwise flash).
const title = ref('')

const ROLE_ITEMS = [
  { label: 'Membre : lit, répond, envoie', value: 'member' },
  { label: 'Responsable : gère aussi les membres', value: 'manager' },
]

async function load() {
  if (!props.mailbox) return
  loading.value = true
  try {
    members.value = await api<InboxMember[]>(`/api/inbox/mailboxes/${props.mailbox.id}/members`)
  }
  catch (error) {
    toast.add({ title: 'Membres indisponibles', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    loading.value = false
  }
}

watch(() => props.mailbox, (mailbox) => {
  if (!mailbox) return
  title.value = `Membres de « ${mailbox.name} »`
  members.value = []
  email.value = ''
  load()
}, { immediate: true })

async function add() {
  if (!props.mailbox) return
  adding.value = true
  try {
    await api(`/api/inbox/mailboxes/${props.mailbox.id}/members`, { method: 'POST', body: { email: email.value, role: role.value } })
    email.value = ''
    await load()
  }
  catch (error) {
    toast.add({ title: 'Ajout impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    adding.value = false
  }
}

async function changeRole(member: InboxMember, value: InboxMember['role']) {
  try {
    Object.assign(member, await api<InboxMember>(`/api/inbox/members/${member.id}`, { method: 'PATCH', body: { role: value } }))
  }
  catch (error) {
    toast.add({ title: 'Modification impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

async function remove(member: InboxMember) {
  try {
    await api(`/api/inbox/members/${member.id}`, { method: 'DELETE' })
    members.value = members.value.filter(m => m.id !== member.id)
  }
  catch (error) {
    toast.add({ title: 'Retrait impossible', description: apiErrorMessage(error), color: 'error' })
  }
}
</script>

<template>
  <UModal :open="mailbox !== null" :title="title" :ui="{ content: 'max-w-xl' }" @update:open="(value: boolean) => { if (!value) emit('close') }">
    <template #body>
      <div class="flex flex-col gap-4" data-testid="mailbox-members">
        <UAlert v-if="note" color="info" variant="subtle" icon="i-lucide-info" :description="note" />
        <form class="flex flex-wrap items-end gap-2" @submit.prevent="add">
          <UFormField label="Ajouter un utilisateur" class="min-w-52 flex-1">
            <UInput v-model="email" type="email" placeholder="prenom.nom@exemple.com" class="w-full" data-testid="member-email" />
          </UFormField>
          <USelect v-model="role" :items="ROLE_ITEMS" class="w-52" />
          <UButton type="submit" icon="i-lucide-user-plus" label="Ajouter" :loading="adding" :disabled="!isEmail(email)" />
        </form>

        <p v-if="!loading && !members.length" class="text-sm text-muted">
          Aucun membre pour l’instant.
        </p>
        <ul class="divide-y divide-default">
          <li v-for="member in members" :key="member.id" class="flex items-center gap-3 py-2">
            <UAvatar :alt="member.user.displayName" size="sm" />
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-medium">
                {{ member.user.displayName }}
              </p>
              <p class="truncate text-xs text-muted">
                {{ member.user.email }}
              </p>
            </div>
            <USelect :model-value="member.role" :items="ROLE_ITEMS.map(i => ({ ...i, label: i.value === 'member' ? 'Membre' : 'Responsable' }))" class="w-36" @update:model-value="(value: string) => changeRole(member, value as InboxMember['role'])" />
            <UButton icon="i-lucide-user-minus" color="error" variant="ghost" aria-label="Retirer" @click="remove(member)" />
          </li>
        </ul>
      </div>
    </template>
  </UModal>
</template>
