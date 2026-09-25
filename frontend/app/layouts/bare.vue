<script setup lang="ts">
// The embedded composer follows its host page: no theme switch there.
const embedded = computed(() => useRoute().path.startsWith('/embed'))
</script>

<template>
  <div class="relative min-h-dvh bg-default">
    <div v-if="!embedded" class="absolute end-3 top-3 z-10">
      <ColorModeSwitch />
    </div>
    <slot />
  </div>
</template>
