<script setup lang="ts">
type Actor = 'browser' | 'host' | 'iframe' | 'api'

const actors: Record<Actor, { label: string, icon: string, class: string }> = {
  browser: { label: 'Page de votre application', icon: 'i-lucide-app-window', class: 'bg-info/10 text-info ring-info/25' },
  host: { label: 'Votre serveur', icon: 'i-lucide-server', class: 'bg-secondary/10 text-secondary ring-secondary/25' },
  iframe: { label: 'Iframe Rocket Mailer', icon: 'i-lucide-square-dashed-mouse-pointer', class: 'bg-primary/10 text-primary ring-primary/25' },
  api: { label: 'API Rocket Mailer', icon: 'i-lucide-database', class: 'bg-success/10 text-success ring-success/25' },
}

const steps: { from: Actor, to: Actor, text: string, detail?: string }[] = [
  { from: 'browser', to: 'iframe', text: 'embed.js crée l’iframe', detail: '/embed/compose?app=<applicationId>' },
  { from: 'iframe', to: 'api', text: 'Lit les origines autorisées de l’application', detail: 'GET /api/embed/frame-policy (public)' },
  { from: 'iframe', to: 'browser', text: 'Signale qu’elle est prête', detail: 'postMessage { type: "ready" }' },
  { from: 'browser', to: 'host', text: 'getToken() appelle votre backend', detail: 'avec la session de VOTRE utilisateur' },
  { from: 'host', to: 'api', text: 'Demande un jeton d’embed pour cet utilisateur', detail: 'POST /api/embed/token + Bearer rma_… + X-Impersonate-User' },
  { from: 'browser', to: 'iframe', text: 'Transmet le jeton (15 min)', detail: 'postMessage { type: "token" } vers l’origine Rocket Mailer uniquement' },
  { from: 'iframe', to: 'api', text: 'Compose et envoie en tant que l’utilisateur', detail: 'Authorization: Embed <jwt>' },
  { from: 'iframe', to: 'browser', text: 'Notifie l’envoi', detail: 'postMessage { type: "sent" } → onSent(email)' },
]
</script>

<template>
  <div class="my-6 rounded-lg border border-default p-4">
    <div class="mb-4 flex flex-wrap gap-2">
      <span
        v-for="(actor, key) in actors"
        :key="key"
        class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium ring ring-inset"
        :class="actor.class"
      >
        <UIcon :name="actor.icon" class="size-4" />
        {{ actor.label }}
      </span>
    </div>

    <ol class="space-y-3">
      <li v-for="(step, index) in steps" :key="index" class="flex gap-3">
        <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-elevated text-xs font-semibold">
          {{ index + 1 }}
        </span>
        <div class="min-w-0">
          <div class="flex flex-wrap items-center gap-1.5 text-sm">
            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs ring ring-inset" :class="actors[step.from].class">
              <UIcon :name="actors[step.from].icon" class="size-3.5" />
            </span>
            <UIcon name="i-lucide-arrow-right" class="size-4 text-dimmed" />
            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs ring ring-inset" :class="actors[step.to].class">
              <UIcon :name="actors[step.to].icon" class="size-3.5" />
            </span>
            <span class="font-medium">{{ step.text }}</span>
          </div>
          <p v-if="step.detail" class="mt-0.5 font-mono text-xs text-muted">
            {{ step.detail }}
          </p>
        </div>
      </li>
    </ol>
  </div>
</template>
