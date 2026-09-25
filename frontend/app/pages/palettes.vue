<script setup lang="ts">
import type { ColorPalette, Settings } from '~/types/api'
import type { PaletteColor } from '~/utils/palette'

definePageMeta({ admin: true })
useHead({ title: 'Palettes · Rocket Mailer' })

const api = useApi()
const toast = useToast()
const theme = useTheme()

const { data: palettes, refresh } = await useAsyncData('palettes', () => api<ColorPalette[]>('/api/color_palettes'), { default: () => [] })
const { data: settings, refresh: refreshSettings } = await useAsyncData('palettes-settings', () => api<Settings>('/api/settings'))

const COLOR_LABELS: Record<PaletteColor, { label: string, hint: string }> = {
  primary: { label: 'Principale', hint: 'Boutons, liens, éléments actifs' },
  secondary: { label: 'Secondaire', hint: 'Actions secondaires' },
  success: { label: 'Succès', hint: 'Envoyé, opérationnel' },
  info: { label: 'Information', hint: 'Messages d’information' },
  warning: { label: 'Avertissement', hint: 'Dégradé, à vérifier' },
  error: { label: 'Erreur', hint: 'Échec, suppression' },
}
const NEUTRAL_LABELS: Record<Neutral, string> = { slate: 'Ardoise (bleuté)', gray: 'Gris', zinc: 'Zinc', neutral: 'Neutre', stone: 'Pierre (chaud)' }
// Examples for a new palette.
const STARTERS = [
  { name: 'Indigo', primary: '#4f46e5', neutral: 'slate' },
  { name: 'Émeraude', primary: '#059669', neutral: 'zinc' },
  { name: 'Framboise', primary: '#db2777', neutral: 'stone' },
] as const

type Form = { name: string, neutral: Neutral } & Record<PaletteColor, string>
const selectedId = ref<string | null>(null)
const form = reactive<Form>({ name: '', neutral: 'zinc', primary: '#f97316', secondary: '', success: '', info: '', warning: '', error: '' })
const saving = ref(false)
const toDelete = ref<ColorPalette | null>(null)

const isNew = computed(() => selectedId.value === 'new')
const projectPaletteId = computed(() => settings.value?.palette?.id ?? null)
const validColors = computed(() => PALETTE_COLORS.every(color => form[color] === '' || isHex(form[color])))
const canSave = computed(() => form.name.trim() !== '' && isHex(form.primary) && validColors.value)

// Live preview, limited to the preview box.
const previewCss = computed(() => scopedPaletteCss('[data-palette-preview]', {
  colors: Object.fromEntries(PALETTE_COLORS.filter(color => isHex(form[color])).map(color => [color, form[color]])),
}))
useHead({ style: [{ id: 'palette-preview', innerHTML: previewCss }] })

function open(palette: ColorPalette) {
  selectedId.value = palette.id
  Object.assign(form, {
    name: palette.name,
    neutral: palette.neutral,
    ...Object.fromEntries(PALETTE_COLORS.map(color => [color, palette[color] ?? ''])),
  })
}

function create(starter: typeof STARTERS[number] = STARTERS[0]) {
  selectedId.value = 'new'
  Object.assign(form, { name: starter.name, neutral: starter.neutral, primary: starter.primary, secondary: '', success: '', info: '', warning: '', error: '' })
}

async function save() {
  saving.value = true
  try {
    const body = { name: form.name, neutral: form.neutral, ...Object.fromEntries(PALETTE_COLORS.map(color => [color, form[color] || null])) }
    const saved = isNew.value
      ? await api<ColorPalette>('/api/color_palettes', { method: 'POST', body })
      : await api<ColorPalette>(`/api/color_palettes/${selectedId.value}`, { method: 'PATCH', body })
    await refresh()
    selectedId.value = saved.id
    // The project's palette changed: apply it now.
    if (saved.id === projectPaletteId.value) await theme.load()
    toast.add({ title: 'Palette enregistrée', color: 'success', icon: 'i-lucide-check' })
  }
  catch (error) {
    toast.add({ title: 'Enregistrement impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    saving.value = false
  }
}

async function useForProject(id: string | null) {
  try {
    await api('/api/settings', { method: 'PATCH', body: { palette: id ? `/api/color_palettes/${id}` : '' } })
    await Promise.all([refreshSettings(), theme.load()])
    toast.add({ title: id ? 'Palette du projet appliquée' : 'Couleurs par défaut rétablies', color: 'success' })
  }
  catch (error) {
    toast.add({ title: 'Modification impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

async function remove() {
  if (!toDelete.value) return
  const wasProject = toDelete.value.id === projectPaletteId.value
  try {
    await api(`/api/color_palettes/${toDelete.value.id}`, { method: 'DELETE' })
    if (selectedId.value === toDelete.value.id) selectedId.value = null
    toDelete.value = null
    await Promise.all([refresh(), refreshSettings()])
    if (wasProject) await theme.load()
  }
  catch (error) {
    toast.add({ title: 'Suppression impossible', description: apiErrorMessage(error), color: 'error' })
  }
}
</script>

<template>
  <UDashboardPanel id="palettes">
    <template #header>
      <UDashboardNavbar title="Palettes">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UDropdownMenu :items="STARTERS.map(s => ({ label: s.name, onSelect: () => create(s) }))">
            <UButton icon="i-lucide-plus" label="Nouvelle palette" trailing-icon="i-lucide-chevron-down" data-testid="new-palette" />
          </UDropdownMenu>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="grid gap-6 xl:grid-cols-[340px_1fr]">
        <div class="flex flex-col gap-3">
          <p class="text-sm text-muted">
            La palette du projet habille Rocket Mailer et, par défaut, le composeur embarqué des applications. Une application peut avoir sa propre palette (page Applications).
          </p>
          <button
            type="button"
            class="flex items-center gap-3 rounded-lg border p-3 text-left transition hover:bg-elevated/50"
            :class="projectPaletteId === null ? 'border-primary' : 'border-default'"
            @click="useForProject(null)"
          >
            <UIcon name="i-lucide-rotate-ccw" class="size-5 shrink-0 text-muted" />
            <span class="min-w-0 flex-1 text-sm">Couleurs par défaut de Rocket Mailer</span>
            <UBadge v-if="projectPaletteId === null" label="Projet" size="sm" variant="subtle" />
          </button>
          <button
            v-for="palette in palettes"
            :key="palette.id"
            type="button"
            class="flex items-center gap-3 rounded-lg border p-3 text-left transition hover:bg-elevated/50"
            :class="selectedId === palette.id ? 'border-primary' : 'border-default'"
            :data-testid="`palette-${palette.name}`"
            @click="open(palette)"
          >
            <span class="flex shrink-0 -space-x-1">
              <span
                v-for="color in PALETTE_COLORS.filter(c => palette[c])"
                :key="color"
                class="size-5 rounded-full ring-2 ring-default"
                :style="{ background: palette[color]! }"
              />
            </span>
            <span class="min-w-0 flex-1 truncate font-medium">{{ palette.name }}</span>
            <UBadge v-if="palette.id === projectPaletteId" label="Projet" size="sm" variant="subtle" />
            <UButton icon="i-lucide-trash-2" color="error" variant="ghost" size="xs" aria-label="Supprimer" @click.stop="toDelete = palette" />
          </button>
        </div>

        <form v-if="selectedId" class="flex min-w-0 flex-col gap-4" data-testid="palette-form" @submit.prevent="save">
          <div class="grid gap-3 sm:grid-cols-2">
            <UFormField label="Nom" required>
              <UInput v-model="form.name" class="w-full" />
            </UFormField>
            <UFormField label="Gris" hint="Fonds, bordures, textes">
              <USelect v-model="form.neutral" :items="NEUTRALS.map(n => ({ label: NEUTRAL_LABELS[n], value: n }))" class="w-full" />
            </UFormField>
          </div>
          <div class="grid gap-3 sm:grid-cols-2 2xl:grid-cols-3">
            <UFormField
              v-for="color in PALETTE_COLORS"
              :key="color"
              :label="COLOR_LABELS[color].label"
              :hint="color === 'primary' ? 'Obligatoire' : 'Vide : par défaut'"
              :help="COLOR_LABELS[color].hint"
              :error="form[color] !== '' && !isHex(form[color]) ? 'Format #rrggbb' : undefined"
            >
              <div class="flex items-center gap-2">
                <input
                  type="color"
                  :value="isHex(form[color]) ? form[color] : '#ffffff'"
                  :aria-label="`${COLOR_LABELS[color].label} (sélecteur)`"
                  class="size-9 shrink-0 cursor-pointer rounded border border-default bg-transparent p-0.5"
                  @input="(event) => { form[color] = (event.target as HTMLInputElement).value }"
                >
                <UInput v-model="form[color]" placeholder="#rrggbb" class="w-full font-mono" :data-testid="`palette-${color}`" />
              </div>
            </UFormField>
          </div>

          <UFormField label="Aperçu">
            <div data-palette-preview class="flex flex-col gap-3 rounded-lg border border-default p-4" data-testid="palette-preview">
              <div class="flex flex-wrap gap-2">
                <UButton label="Principale" />
                <UButton v-for="color in (['secondary', 'success', 'info', 'warning', 'error'] as const)" :key="color" :color="color" :label="COLOR_LABELS[color].label" />
              </div>
              <div class="flex flex-wrap gap-2">
                <UButton label="Contour" variant="outline" />
                <UButton label="Doux" variant="soft" />
                <UBadge label="Envoyé" color="success" variant="subtle" />
                <UBadge label="Échec" color="error" variant="subtle" />
                <UBadge label="Dégradé" color="warning" variant="subtle" />
              </div>
              <UAlert color="primary" variant="subtle" icon="i-lucide-sparkles" title="Votre message est prêt" description="Les couleurs s’appliquent à tout Rocket Mailer." />
              <div class="flex gap-1">
                <span v-for="shade in SHADES" :key="shade" class="h-6 flex-1 rounded-sm" :style="{ background: `var(--ui-color-primary-${shade})` }" :title="String(shade)" />
              </div>
            </div>
          </UFormField>

          <div class="flex flex-wrap gap-2">
            <UButton type="submit" label="Enregistrer" icon="i-lucide-save" :loading="saving" :disabled="!canSave" />
            <UButton
              v-if="!isNew && selectedId !== projectPaletteId"
              label="Utiliser pour le projet"
              icon="i-lucide-paintbrush"
              color="neutral"
              variant="outline"
              data-testid="use-for-project"
              @click="useForProject(selectedId)"
            />
            <UButton label="Fermer" color="neutral" variant="ghost" @click="selectedId = null" />
          </div>
        </form>
      </div>

      <UModal :open="toDelete !== null" title="Supprimer la palette ?" :description="toDelete ? `Le projet et les applications qui utilisent « ${toDelete.name} » reprendront leurs couleurs par défaut.` : ''" @update:open="(value: boolean) => { if (!value) toDelete = null }">
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="toDelete = null" />
            <UButton label="Supprimer" color="error" @click="remove" />
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
