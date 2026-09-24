<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'

const auth = useAuth()

const items = computed<NavigationMenuItem[][]>(() => [
  [
    { label: 'Nouveau message', icon: 'i-lucide-send', to: '/compose' },
    { label: 'Envoyés', icon: 'i-lucide-inbox', to: '/emails' },
    { label: 'Templates', icon: 'i-lucide-layout-template', to: '/templates' },
  ],
  auth.isAdmin.value
    ? [
        { label: 'Utilisateurs', icon: 'i-lucide-users', to: '/users' },
        { label: 'Applications', icon: 'i-lucide-key-round', to: '/applications' },
        { label: 'Réglages', icon: 'i-lucide-settings', to: '/settings' },
      ]
    : [],
])
</script>

<template>
  <UDashboardGroup>
    <UDashboardSidebar collapsible resizable>
      <template #header="{ collapsed }">
        <div class="flex items-center gap-2 font-semibold">
          <UIcon name="i-lucide-rocket" class="size-5 text-primary" />
          <span v-if="!collapsed">Rocket Mailer</span>
        </div>
      </template>

      <template #default="{ collapsed }">
        <UNavigationMenu :items="items[0]" :collapsed="collapsed" orientation="vertical" />
        <template v-if="items[1]?.length">
          <USeparator />
          <UNavigationMenu :items="items[1]" :collapsed="collapsed" orientation="vertical" />
        </template>
      </template>

      <template #footer="{ collapsed }">
        <div class="flex w-full items-center gap-2">
          <UUser
            v-if="auth.me.value?.user && !collapsed"
            :name="auth.me.value.user.displayName"
            :description="auth.me.value.user.email"
            size="sm"
            class="min-w-0 flex-1"
          />
          <UButton
            icon="i-lucide-log-out"
            color="neutral"
            variant="ghost"
            aria-label="Se déconnecter"
            @click="auth.logout()"
          />
        </div>
      </template>
    </UDashboardSidebar>

    <slot />
  </UDashboardGroup>
</template>
