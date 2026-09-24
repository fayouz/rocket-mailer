// Integration code shown by the "Intégrer" dialog: ready to paste in the calling application.

export interface EmbedDraft {
  from?: string | null
  to?: string[]
  cc?: string[]
  bcc?: string[]
  subject?: string
  template?: string | null
  variables?: Record<string, unknown>
}

export interface EmbedSnippetInput {
  /** Rocket Mailer URL, e.g. https://mailer.example.com */
  baseUrl: string
  applicationId: string
  draft: EmbedDraft
}

/** "client.prenom" → { client: { prenom } }: the nested form reads better in code. */
export function nestVariables(flat: Record<string, string>): Record<string, unknown> {
  const nested: Record<string, unknown> = {}
  for (const [name, value] of Object.entries(flat)) {
    const keys = name.split('.')
    let node = nested
    keys.slice(0, -1).forEach((key) => {
      if (typeof node[key] !== 'object' || node[key] === null) node[key] = {}
      node = node[key] as Record<string, unknown>
    })
    node[keys.at(-1)!] = value
  }
  return nested
}

/** Only the fields that are set, in a stable order. */
export function cleanDraft(draft: EmbedDraft): EmbedDraft {
  const clean: EmbedDraft = {}
  if (draft.from) clean.from = draft.from
  for (const field of ['to', 'cc', 'bcc'] as const) {
    if (draft[field]?.length) clean[field] = draft[field]
  }
  if (draft.subject?.trim()) clean.subject = draft.subject
  if (draft.template) clean.template = draft.template.split('/').pop()
  if (draft.variables && Object.keys(draft.variables).length) clean.variables = draft.variables
  return clean
}

function indent(code: string, spaces: number): string {
  return code.split('\n').map((line, i) => (i === 0 ? line : ' '.repeat(spaces) + line)).join('\n')
}

function js(value: unknown): string {
  // JSON is valid JavaScript: only simplify the keys that are identifiers.
  return JSON.stringify(value, null, 2).replace(/"([A-Za-z_$][\w$]*)":/g, '$1:')
}

export function webComponentSnippet({ baseUrl, applicationId, draft }: EmbedSnippetInput): string {
  const clean = cleanDraft(draft)
  const hasDraft = Object.keys(clean).length > 0
  return `<script src="${baseUrl}/embed.js"></script>

<rocket-mailer-composer
  application-id="${applicationId}"
  token-url="/rocket-mailer/token"
></rocket-mailer-composer>
${hasDraft
  ? `
<script>
  const composer = document.querySelector('rocket-mailer-composer')
  composer.draft = ${indent(js(clean), 2)}
  composer.addEventListener('sent', (event) => console.log('Envoyé', event.detail))
</script>`
  : `
<script>
  document.querySelector('rocket-mailer-composer')
    .addEventListener('sent', (event) => console.log('Envoyé', event.detail))
</script>`}`
}

export function javascriptSnippet({ baseUrl, applicationId, draft }: EmbedSnippetInput): string {
  const clean = cleanDraft(draft)
  return `<script src="${baseUrl}/embed.js"></script>
<div id="mailer"></div>

<script>
  const mailer = RocketMailer.mount('#mailer', {
    baseUrl: '${baseUrl}',
    applicationId: '${applicationId}',
    // Your backend endpoint (see the "Endpoint de jeton" tab).
    getToken: () => fetch('/rocket-mailer/token', { method: 'POST' })
      .then(r => r.json()).then(d => d.token),${Object.keys(clean).length ? `\n    draft: ${indent(js(clean), 4)},` : ''}
    onSent: (email) => console.log('Envoyé', email),
  })
</script>`
}

export function nuxtSnippet({ baseUrl, applicationId, draft }: EmbedSnippetInput): string {
  const clean = cleanDraft(draft)
  return `// nuxt.config.ts
export default defineNuxtConfig({
  extends: ['github:fayouz/rocket-mailer/integrations/nuxt#develop'],
})

// .env
NUXT_PUBLIC_ROCKET_MAILER_URL=${baseUrl}
NUXT_PUBLIC_ROCKET_MAILER_APPLICATION_ID=${applicationId}
NUXT_ROCKET_MAILER_APP_TOKEN=rma_…   # secret: server only

// server/plugins/rocket-mailer.ts: who is the current user?
export default defineNitroPlugin((nitro) => {
  nitro.hooks.hook('rocket-mailer:user', async (event, ctx) => {
    ctx.email = (await requireUserSession(event)).user.email // your auth
  })
})

<!-- pages/client.vue -->
<template>
  <RocketMailerComposer${Object.keys(clean).length ? ' :draft="draft"' : ''} @sent="email => console.log(email)" />
</template>${Object.keys(clean).length
    ? `

<script setup lang="ts">
const draft = ${js(clean)}
</script>`
    : ''}`
}

export function tokenEndpointSnippet({ baseUrl }: EmbedSnippetInput): string {
  return `// Node / Express: POST /rocket-mailer/token (behind YOUR authentication)
app.post('/rocket-mailer/token', requireLogin, async (req, res) => {
  const response = await fetch('${baseUrl}/api/embed/token', {
    method: 'POST',
    headers: {
      'Authorization': \`Bearer \${process.env.ROCKET_MAILER_APP_TOKEN}\`, // rma_…, never sent to the browser
      'X-Impersonate-User': req.user.email,                                // the logged-in user, never from the browser
      'Accept': 'application/json',
    },
  })
  res.status(response.status).json(await response.json()) // { token, expiresAt }
})

# Or test it from a terminal:
curl -X POST ${baseUrl}/api/embed/token \\
  -H "Authorization: Bearer rma_…" -H "X-Impersonate-User: user@example.com"`
}
