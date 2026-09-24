<script setup lang="ts">
definePageMeta({ admin: true })
useHead({ title: 'Tous les envois · Rocket Mailer' })

const log = useTemplateRef<{ refresh: () => Promise<void> }>('log')
</script>

<template>
  <UDashboardPanel id="emails-all">
    <template #header>
      <UDashboardNavbar title="Tous les envois">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-refresh-cw" color="neutral" variant="ghost" aria-label="Rafraîchir" @click="log?.refresh()" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <EmailLog ref="log" scope="all" />
    </template>
  </UDashboardPanel>
</template>
