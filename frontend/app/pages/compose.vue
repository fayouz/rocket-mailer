<script setup lang="ts">
useHead({ title: 'Nouveau message · Rocket Mailer' })

// Declaring and embedding applications is an admin matter.
const auth = useAuth()
</script>

<template>
  <UDashboardPanel id="compose">
    <template #header>
      <UDashboardNavbar title="Nouveau message">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="mx-auto w-full max-w-4xl">
        <EmailComposer :embeddable="auth.isAdmin.value" @sent="navigateTo('/emails')" />
      </div>
    </template>
  </UDashboardPanel>
</template>
