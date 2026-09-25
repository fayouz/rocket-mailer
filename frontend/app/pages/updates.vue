<script setup lang="ts">
import type { AppVersionInfo } from '~/types/api'

definePageMeta({ admin: true })
useHead({ title: 'Mises à jour · Rocket Mailer' })

const config = useRuntimeConfig()
const api = useApi()
const toast = useToast()
const { update, frontVersion, fetchUpdate } = useAppVersion()

const checking = ref(false)
const confirmOpen = ref(false)
const updating = ref(false)
const waitingSince = ref<Date | null>(null)
const stalled = ref(false)
let poll: ReturnType<typeof setInterval> | undefined

// Checks against GitHub once per page load (cached an hour by the API); "Vérifier maintenant" forces it.
await useAsyncData('update-status', () => fetchUpdate())

const current = computed(() => update.value?.current)
const latest = computed(() => update.value?.latest ?? null)
const versionsDiffer = computed(() => !!current.value && current.value.version !== frontVersion.value && frontVersion.value !== 'dev')

async function check() {
  checking.value = true
  try {
    await fetchUpdate(true)
  }
  catch (error) {
    toast.add({ title: 'Vérification impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    checking.value = false
  }
}

async function start() {
  confirmOpen.value = false
  const before = current.value?.version
  try {
    await api('/api/system/update', { method: 'POST' })
  }
  catch (error) {
    toast.add({ title: 'Mise à jour impossible', description: apiErrorMessage(error), color: 'error' })
    return
  }
  updating.value = true
  stalled.value = false
  waitingSince.value = new Date()
  // The API and the interface restart: wait for the new version to answer, then reload the page.
  poll = setInterval(async () => {
    try {
      const { version } = await api<AppVersionInfo>('/api/system/version')
      if (version !== before) {
        clearInterval(poll)
        toast.add({ title: `Rocket Mailer ${version} est installé`, color: 'success', icon: 'i-lucide-party-popper' })
        setTimeout(() => window.location.reload(), 1500)
        return
      }
    }
    catch {
      // Restarting.
    }
    if (waitingSince.value && Date.now() - waitingSince.value.getTime() > 5 * 60_000) stalled.value = true
  }, 5000)
}

onBeforeUnmount(() => clearInterval(poll))
</script>

<template>
  <UDashboardPanel id="updates">
    <template #header>
      <UDashboardNavbar title="Mises à jour">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton
            label="Vérifier maintenant"
            icon="i-lucide-refresh-cw"
            color="neutral"
            variant="outline"
            :loading="checking"
            :disabled="!update?.checkEnabled || updating"
            data-testid="check-updates"
            @click="check"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div v-if="update" class="flex max-w-3xl flex-col gap-6">
        <UPageCard title="Version installée" variant="subtle">
          <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
              <p class="text-3xl font-semibold" data-testid="installed-version">
                {{ current?.version }}
              </p>
              <p v-if="versionsDiffer" class="text-sm text-muted">
                Interface : {{ frontVersion }}
              </p>
            </div>
            <UButton label="Changelog" icon="i-lucide-scroll-text" color="neutral" variant="link" :to="config.public.changelogUrl" target="_blank" />
          </div>
          <p v-if="current?.release === null" class="text-sm text-muted">
            Build de développement ou d’une branche : il n’a pas de numéro de version à comparer aux versions publiées.
          </p>
        </UPageCard>

        <UAlert v-if="update.error" color="warning" variant="subtle" icon="i-lucide-cloud-off" title="Vérification impossible" :description="update.error" />
        <UAlert
          v-else-if="!update.checkEnabled"
          color="neutral"
          variant="subtle"
          icon="i-lucide-bell-off"
          title="Vérification des mises à jour désactivée"
          description="UPDATE_REPOSITORY est vide : Rocket Mailer ne consulte pas les versions publiées."
        />
        <UAlert
          v-else-if="!latest"
          color="neutral"
          variant="subtle"
          icon="i-lucide-package-search"
          title="Aucune version publiée pour l’instant"
          :description="`Aucune version n’est publiée sur ${update.repositoryUrl}.`"
        />

        <UPageCard
          v-if="latest"
          :title="update.updateAvailable ? `Rocket Mailer ${latest.version} est disponible` : update.updateAvailable === false ? 'Rocket Mailer est à jour' : `Dernière version publiée : ${latest.version}`"
          :description="latest.name !== latest.version && latest.name !== `v${latest.version}` ? latest.name : undefined"
          :icon="update.updateAvailable ? 'i-lucide-sparkles' : 'i-lucide-circle-check'"
          :variant="update.updateAvailable ? 'soft' : 'subtle'"
          data-testid="latest-release"
        >
          <p class="text-sm text-muted">
            {{ latest.publishedAt ? `Version ${latest.version}, publiée le ${formatDate(latest.publishedAt)}.` : `Version ${latest.version}.` }}
            <ULink :to="latest.url" target="_blank" class="text-primary">
              Voir la version
            </ULink>
          </p>
          <div v-if="latest.notes && update.updateAvailable" class="max-h-80 overflow-auto whitespace-pre-wrap rounded-md border border-default bg-default p-3 text-sm">
            {{ latest.notes }}
          </div>
        </UPageCard>

        <UAlert
          v-if="updating"
          :color="stalled ? 'warning' : 'info'"
          variant="subtle"
          :icon="stalled ? 'i-lucide-triangle-alert' : 'i-lucide-loader-circle'"
          :ui="{ icon: stalled ? '' : 'animate-spin' }"
          :title="stalled ? 'La nouvelle version ne répond toujours pas' : 'Mise à jour en cours…'"
          :description="stalled
            ? 'Aucune nouvelle version n’a démarré. Vérifiez les journaux du service de mise à jour (docker compose logs updater), et que les services utilisent les images publiées (API_IMAGE, FRONT_IMAGE).'
            : 'Téléchargement des nouvelles images, puis redémarrage des services : Rocket Mailer est indisponible une à deux minutes. La page se recharge toute seule.'"
          data-testid="update-progress"
        />

        <UPageCard
          v-if="update.updater.configured"
          title="Mettre à jour"
          description="Télécharge les dernières images publiées de Rocket Mailer, puis redémarre l’API, l’envoi et l’interface."
          icon="i-lucide-download"
          variant="subtle"
        >
          <div>
            <UButton
              label="Mettre à jour"
              icon="i-lucide-download"
              :color="update.updateAvailable ? 'primary' : 'neutral'"
              :variant="update.updateAvailable ? 'solid' : 'outline'"
              :loading="updating && !stalled"
              :disabled="updating && !stalled"
              data-testid="start-update"
              @click="confirmOpen = true"
            />
          </div>
        </UPageCard>
        <UPageCard
          v-else
          title="Mettre à jour"
          description="La mise à jour en un clic n’est pas activée. Sur le serveur, dans le dossier de Rocket Mailer :"
          icon="i-lucide-terminal"
          variant="subtle"
          data-testid="manual-update"
        >
          <pre class="overflow-x-auto rounded-md bg-elevated p-3 text-sm"><code>docker compose pull
docker compose up -d</code></pre>
          <p class="text-sm text-muted">
            Pour un bouton « Mettre à jour » ici, lancez le service <code>updater</code> (profil <code>updater</code>) avec un secret <code>UPDATER_TOKEN</code> : voir la documentation, Administration → Mises à jour.
          </p>
        </UPageCard>
      </div>

      <UModal
        v-model:open="confirmOpen"
        title="Mettre à jour Rocket Mailer ?"
        :description="latest && update?.updateAvailable ? `Installation de la version ${latest.version}.` : 'Installation des dernières images publiées.'"
      >
        <template #body>
          <ul class="list-disc space-y-1 pl-5 text-sm text-muted">
            <li>L’API, l’envoi et l’interface redémarrent : Rocket Mailer est indisponible une à deux minutes.</li>
            <li>Les emails en attente partent après le redémarrage ; rien n’est perdu.</li>
            <li>La base de données est migrée automatiquement au démarrage.</li>
          </ul>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="confirmOpen = false" />
            <UButton label="Mettre à jour" icon="i-lucide-download" data-testid="confirm-update" @click="start" />
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
