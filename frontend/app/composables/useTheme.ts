import type { Neutral, ThemePalette } from '~/utils/palette'

interface ThemeResponse {
  source: 'application' | 'project' | 'default'
  palette: ThemePalette | null
}

const STYLE_ID = 'rm-palette'

/**
 * Colors of the interface: the project's palette (Réglages), or in the embedded composer the application's one.
 * Light / dark / system is the user's choice (useColorMode), kept in the browser.
 */
export function useTheme() {
  const config = useRuntimeConfig()
  const appConfig = useAppConfig()
  const current = useState<ThemeResponse | null>('rm_theme', () => null)
  const defaultNeutral = useState<string>('rm_theme_default_neutral', () => appConfig.ui.colors.neutral)

  function apply(palette: ThemePalette | null) {
    appConfig.ui.colors.neutral = (palette?.neutral ?? defaultNeutral.value) as Neutral
    if (!import.meta.client) return
    document.getElementById(STYLE_ID)?.remove()
    const declarations = palette ? paletteDeclarations(palette) : ''
    if (!declarations) return
    const style = document.createElement('style')
    style.id = STYLE_ID
    // Unlayered: wins over Nuxt UI's @layer theme.
    style.textContent = `:root, :host {\n  ${declarations}\n}`
    document.head.appendChild(style)
  }

  /** Loads and applies the palette; public endpoint, so it works on the login page and in the embed. */
  async function load(application?: string | null) {
    try {
      current.value = await $fetch<ThemeResponse>('/api/theme', {
        baseURL: config.public.apiBase,
        query: application ? { app: application } : {},
      })
      apply(current.value.palette)
    }
    catch {
      // Default colors.
    }
  }

  return { current, apply, load }
}
