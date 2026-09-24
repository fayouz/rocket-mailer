<script setup lang="ts">
useHead({ title: 'Mes envois · Rocket Mailer' })

const log = useTemplateRef<{ refresh: () => Promise<void> }>('log')
</script>

<template>
  <UDashboardPanel id="emails">
    <template #header>
      <UDashboardNavbar title="Mes envois">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-refresh-cw" color="neutral" variant="ghost" aria-label="Rafraîchir" @click="log?.refresh()" />
          <UButton icon="i-lucide-send" label="Nouveau message" to="/compose" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <EmailLog ref="log" scope="mine" />
    </template>
  </UDashboardPanel>
</template>
