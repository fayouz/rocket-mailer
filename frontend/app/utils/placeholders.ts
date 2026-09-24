// Template variables: "{{ name }}" placeholders. Same rules as the API (App\Template\Placeholders).
export const VARIABLE_NAME = /^[A-Za-z_]\w*(?:\.\w+)*$/
const PLACEHOLDER = /\{\{\s*([A-Za-z_]\w*(?:\.\w+)*)\s*\}\}/g

/** Names used in the texts, in order of appearance, without duplicates. */
export function placeholderNames(...texts: (string | null | undefined)[]): string[] {
  const names = new Set<string>()
  for (const text of texts) {
    for (const match of (text ?? '').matchAll(PLACEHOLDER)) names.add(match[1]!)
  }
  return [...names]
}

/** { client: { firstName: 'Jean' } } and { 'client.firstName': 'Jean' } both give { 'client.firstName': 'Jean' }. */
export function flattenVariables(values: unknown, prefix = ''): Record<string, string> {
  const flat: Record<string, string> = {}
  if (!values || typeof values !== 'object') return flat
  for (const [key, value] of Object.entries(values)) {
    const name = prefix + key
    if (value !== null && typeof value === 'object') Object.assign(flat, flattenVariables(value, `${name}.`))
    else if (typeof value === 'string' || typeof value === 'number') flat[name] = String(value)
    else if (typeof value === 'boolean') flat[name] = value ? 'oui' : 'non'
  }
  return flat
}

function escapeHtml(value: string): string {
  return value.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' })[c]!)
}

/** Replaces the placeholders that have a value. In HTML, values are escaped: they are text, never markup. */
export function renderPlaceholders(text: string, values: Record<string, string>, html: boolean): string {
  return text.replace(PLACEHOLDER, (placeholder, name: string) => {
    if (!(name in values)) return placeholder
    return html ? escapeHtml(values[name]!) : values[name]!.replace(/\s+/g, ' ')
  })
}
