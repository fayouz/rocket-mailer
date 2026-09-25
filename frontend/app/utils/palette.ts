/**
 * Palettes: a "#rrggbb" per semantic color becomes the 11 shades (50 to 950) Nuxt UI reads from
 * --ui-color-<color>-<shade>. Shades follow Tailwind's lightness scale in OKLCH; 500 is the exact color.
 */

export const PALETTE_COLORS = ['primary', 'secondary', 'success', 'info', 'warning', 'error'] as const
export type PaletteColor = typeof PALETTE_COLORS[number]
export const NEUTRALS = ['slate', 'gray', 'zinc', 'neutral', 'stone'] as const
export type Neutral = typeof NEUTRALS[number]

/** What GET /api/theme returns for a palette. */
export interface ThemePalette {
  id: string
  name: string
  colors: Partial<Record<PaletteColor, string>>
  neutral: Neutral
}

export const SHADES = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950] as const
// Lightness of Tailwind's palettes, and how much of the color's chroma each shade keeps.
const LIGHTNESS = [0.971, 0.936, 0.885, 0.808, 0.704, 0.637, 0.577, 0.505, 0.444, 0.396, 0.258]
const CHROMA = [0.1, 0.2, 0.4, 0.65, 0.9, 1, 1, 0.9, 0.78, 0.65, 0.45]

export function isHex(value: string | null | undefined): value is string {
  return !!value && /^#[0-9a-f]{6}$/i.test(value)
}

/** sRGB hex → OKLCH [lightness 0-1, chroma, hue in degrees]. */
export function hexToOklch(hex: string): [number, number, number] {
  const channel = (i: number) => {
    const c = Number.parseInt(hex.slice(1 + i * 2, 3 + i * 2), 16) / 255
    return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
  }
  const [r, g, b] = [channel(0), channel(1), channel(2)]
  const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b)
  const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b)
  const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b)
  const L = 0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s
  const A = 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s
  const B = 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s
  const hue = (Math.atan2(B, A) * 180) / Math.PI

  return [L, Math.sqrt(A * A + B * B), hue < 0 ? hue + 360 : hue]
}

/** The 11 shades of a color, as CSS values; 500 is the color itself. */
export function shadesOf(hex: string): Record<typeof SHADES[number], string> {
  const [, chroma, hue] = hexToOklch(hex)
  return Object.fromEntries(SHADES.map((shade, i) => [
    shade,
    shade === 500 ? hex : `oklch(${LIGHTNESS[i]!.toFixed(3)} ${(chroma * CHROMA[i]!).toFixed(3)} ${hue.toFixed(1)})`,
  ])) as Record<typeof SHADES[number], string>
}

/** CSS declarations overriding Nuxt UI's shades for the palette's colors. */
export function paletteDeclarations(palette: Pick<ThemePalette, 'colors'>): string {
  return PALETTE_COLORS.flatMap((color) => {
    const hex = palette.colors[color]
    if (!isHex(hex)) return []
    const shades = shadesOf(hex)
    return SHADES.map(shade => `--ui-color-${color}-${shade}: ${shades[shade]};`)
  }).join('\n  ')
}

/**
 * Same, scoped to an element (live preview): the semantic variables are resolved where they are declared,
 * so they are declared again on the element.
 */
export function scopedPaletteCss(selector: string, palette: Pick<ThemePalette, 'colors'>): string {
  const colors = PALETTE_COLORS.filter(color => isHex(palette.colors[color]))
  return `${selector} {
  ${paletteDeclarations(palette)}
  ${colors.map(color => `--ui-${color}: var(--ui-color-${color}-500);`).join('\n  ')}
}
.dark ${selector} {
  ${colors.map(color => `--ui-${color}: var(--ui-color-${color}-400);`).join('\n  ')}
}`
}

/** Text color readable on a background color. */
export function contrastText(hex: string): string {
  return hexToOklch(hex)[0] > 0.68 ? '#18181b' : '#ffffff'
}
