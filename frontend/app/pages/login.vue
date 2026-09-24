<script setup lang="ts">
definePageMeta({ layout: 'bare' })
useHead({ title: 'Connexion · Rocket Mailer' })

const auth = useAuth()
const route = useRoute()
const state = reactive({ email: '', password: '' })
const error = ref<string | null>(null)
const loading = ref(false)

async function submit() {
  loading.value = true
  error.value = null
  try {
    await auth.login(state.email, state.password)
    const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') && !route.query.redirect.startsWith('//')
      ? route.query.redirect
      : '/'
    await navigateTo(redirect)
  }
  catch (e) {
    error.value = apiErrorMessage(e)
  }
  finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="flex min-h-dvh items-center justify-center p-4">
    <UCard class="w-full max-w-sm">
      <template #header>
        <div class="flex items-center gap-2 text-lg font-semibold">
          <UIcon name="i-lucide-rocket" class="size-6 text-primary" />
          Rocket Mailer
        </div>
        <p class="mt-1 text-sm text-muted">
          Connectez-vous avec votre compte local ou votre compte d'annuaire (LDAP).
        </p>
      </template>

      <form class="flex flex-col gap-4" @submit.prevent="submit">
        <UFormField label="Email" required>
          <UInput v-model="state.email" type="email" autocomplete="username" class="w-full" autofocus />
        </UFormField>
        <UFormField label="Mot de passe" required>
          <UInput v-model="state.password" type="password" autocomplete="current-password" class="w-full" />
        </UFormField>
        <UAlert v-if="error" color="error" variant="subtle" :description="error" icon="i-lucide-circle-alert" />
        <UButton type="submit" label="Se connecter" block :loading="loading" />
      </form>
    </UCard>
  </div>
</template>
