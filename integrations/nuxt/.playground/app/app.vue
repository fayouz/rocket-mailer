<script setup lang="ts">
const draft = ref({ to: ['client@example.com'], subject: 'Votre devis n°42' })
const log = ref<string[]>([])
// ?getToken: token fetched by a function (as with a Symfony / API Platform backend and a JWT) instead of token-url.
const useFunction = useRoute().query.getToken !== undefined
const getToken = useFunction
  ? () => $fetch<{ token: string }>('/api/rocket-mailer/token', { method: 'POST' }).then(r => r.token)
  : undefined
</script>

<template>
  <main style="max-width: 960px; margin: 24px auto; font-family: system-ui">
    <h1>Playground @rocket-mailer/nuxt</h1>
    <button @click="draft = { ...draft, subject: 'Relance du devis n°42' }">
      Changer l’objet
    </button>
    <RocketMailerComposer
      :draft="draft"
      :get-token="getToken"
      @ready="log.push('ready')"
      @sent="email => log.push(`sent: ${email.subject}`)"
      @error="error => log.push(`error: ${error.message}`)"
    />
    <pre data-testid="log">{{ log.join('\n') }}</pre>
  </main>
</template>
