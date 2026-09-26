<script setup lang="ts">
import type { Application } from '#rocket/types/api'
import type { RocketApplicationFormExtension } from '#rocket/types/extensions'
import type { ApplicationSender, SenderOption } from '~/types/api'

/**
 * Section of the application form (rocket.extensions.applications.formSections): the sender settings of the
 * application (GET/PATCH /api/application_senders/{id}), its own "From" address and the addresses it may impose.
 * application is null when creating.
 */
const props = defineProps<{ application: Application | null }>()

const api = useApi()
const { data: senders, refresh: refreshSenders } = useApplicationSenders()

const current = props.application ? senders.value.find(s => s.id === props.application!.id) : undefined
const form = reactive({
  senderName: current?.senderName ?? '',
  senderEmail: current?.senderEmail ?? '',
  allowedSenders: [...(current?.allowedSenders ?? [])],
})

// Mounted when the form opens: the saved settings of the edited application, if the list is not loaded yet.
onMounted(async () => {
  if (!props.application || current) return
  const sender = await api<ApplicationSender>(`/api/application_senders/${props.application.id}`)
  Object.assign(form, { senderName: sender.senderName ?? '', senderEmail: sender.senderEmail ?? '', allowedSenders: [...sender.allowedSenders] })
})

// The platform's default address is only a suggestion for an application's sender, never used as is.
const { data: platformSenders } = useAsyncData('applications-sender-suggestion', () => api<SenderOption[]>('/api/senders'), { default: () => [] })
const suggestion = computed(() => platformSenders.value.find(o => o.default && o.source === 'settings') ?? null)

// Displayed name of the suggestion: the application's name (known once it is created), else the platform's.
const nameFromApplication = ref(false)
function useSuggestion() {
  if (!suggestion.value) return
  form.senderEmail = suggestion.value.email
  form.senderName = props.application?.name ?? ''
  nameFromApplication.value = !props.application
  if (!form.senderName && !nameFromApplication.value) form.senderName = suggestion.value.name ?? ''
}

// Saved by the page once the application itself is saved (it then has an id); an error keeps the dialog open.
defineExpose<RocketApplicationFormExtension>({
  async save(application: Application) {
    await api<ApplicationSender>(`/api/application_senders/${application.id}`, {
      method: 'PATCH',
      body: { senderName: form.senderName || (nameFromApplication.value ? application.name : null), senderEmail: form.senderEmail || null, allowedSenders: form.allowedSenders },
    })
    await refreshSenders()
  },
})
</script>

<template>
  <div class="flex flex-col gap-2 rounded-md border border-default p-3" data-testid="application-sender">
    <p class="text-sm font-medium">
      Expéditeur de l’application
    </p>
    <p class="text-xs text-muted">
      Adresse « De » par défaut de son composeur et de ses envois par l’API. Obligatoire : une application n’envoie jamais depuis les adresses de Rocket Mailer.
    </p>
    <div class="grid gap-2 sm:grid-cols-2">
      <UFormField label="Nom affiché">
        <UInput v-model="form.senderName" :placeholder="application?.name || (nameFromApplication ? 'Nom de l’application' : 'Service commercial')" class="w-full" />
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
  <UFormField label="Adresses d'expédition qu'elle peut imposer" hint="ex. *@crm.exemple.com ou agence@exemple.com">
    <UInputTags v-model="form.allowedSenders" add-on-blur add-on-paste class="w-full" />
  </UFormField>
</template>
