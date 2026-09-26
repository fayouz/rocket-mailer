<script setup lang="ts">
import type { Application } from '#rocket/types/api'

// "Expéditeur" column of the applications page (rocket.extensions.applications.columns).
const props = defineProps<{ application: Application }>()
const { data: senders } = useApplicationSenders()
const sender = computed(() => senders.value.find(s => s.id === props.application.id))
</script>

<template>
  <div v-if="sender?.senderEmail">
    <p>{{ sender.senderName ?? sender.senderEmail }}</p>
    <p v-if="sender.senderName" class="text-xs text-muted">
      {{ sender.senderEmail }}
    </p>
  </div>
  <UBadge v-else variant="subtle" color="error" icon="i-lucide-circle-alert" label="À configurer" />
</template>
