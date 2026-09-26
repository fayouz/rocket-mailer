<script setup lang="ts">
import type { Application } from '#rocket/types/api'

// "Code d'intégration" of one application (rocket.extensions.applications.rowActions): same dialog as "Intégrer"
// in the composer, without a draft.
defineProps<{ application: Application }>()
// Row actions may ask the page to reload the list; this one only shows a dialog.
defineEmits<{ refresh: [] }>()
const open = ref(false)
</script>

<template>
  <UButton icon="i-lucide-code-xml" color="neutral" variant="ghost" aria-label="Code d’intégration" @click="open = true" />
  <EmbedCodeModal v-if="open" v-model:open="open" :application-id="application.id" />
</template>
