<script setup lang="ts">
import type { Application } from '#rocket/types/api'

// Example of the dialog showing a new token (rocket.extensions.applications.tokenExample): the composer integration.
const props = defineProps<{ application: Application, token: string }>()
const config = useRuntimeConfig()
const requestUrl = useRequestURL()

// Split so the SFC parser does not see a closing script tag.
const endScript = '</' + 'script>'
const snippet = computed(() => `# 1. Server side (never expose the application token to the browser)
curl -X POST ${config.public.apiBase || requestUrl.origin}/api/embed/token \\
  -H "Authorization: Bearer ${props.token}" \\
  -H "X-Impersonate-User: jean.dupont@example.org"

# 2. Browser side
<script src="${requestUrl.origin}/embed.js">${endScript}
<div id="mailer"></div>
<script>
  RocketMailer.mount('#mailer', {
    baseUrl: '${requestUrl.origin}',
    applicationId: '${props.application.id}',
    getToken: () => fetch('/my-backend/rocket-mailer-token').then(r => r.json()).then(d => d.token),
    onSent: (email) => console.log('sent', email),
  })
${endScript}`)
</script>

<template>
  <div>
    <p class="mb-1 text-sm font-medium">
      Intégration du composeur
    </p>
    <pre class="max-h-72 overflow-auto rounded bg-elevated p-3 text-xs">{{ snippet }}</pre>
  </div>
</template>
