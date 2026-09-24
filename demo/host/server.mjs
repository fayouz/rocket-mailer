// "Démo CRM": a fake third-party application embedding the Rocket Mailer composer.
// Its backend holds the application secret and mints embed tokens; the browser never sees the secret.
import http from 'node:http'

const PORT = Number(process.env.PORT ?? 4000)
const API = process.env.API_INTERNAL_URL ?? 'http://localhost:8000'
const MAILER_URL = process.env.MAILER_PUBLIC_URL ?? 'http://localhost:3000'
const APP_TOKEN = process.env.APP_TOKEN
const USERS = (process.env.DEMO_USERS ?? 'alice@example.org').split(',').map(u => u.trim()).filter(Boolean)

if (!APP_TOKEN) throw new Error('APP_TOKEN is required')

async function applicationId() {
  const response = await fetch(`${API}/api/me`, { headers: { Authorization: `Bearer ${APP_TOKEN}`, Accept: 'application/json' } })
  if (!response.ok) throw new Error(`Rocket Mailer API answered ${response.status}`)
  return (await response.json()).application.id
}

const nav = current => `<header><h1>Démo CRM — application tierce</h1>
<nav>${[['/', 'Widget JavaScript'], ['/web-component', 'Web component']]
    .map(([href, label]) => `<a href="${href}"${href === current ? ' aria-current="page"' : ''}>${label}</a>`).join('')}</nav></header>`

const escape = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' })[c])

const STYLE = `<style>
  body { margin: 0; font-family: system-ui, sans-serif; background: #eef2ff; color: #1e1b4b; }
  header { background: #4338ca; color: white; padding: 14px 24px; display: flex; gap: 16px; align-items: center; }
  header h1 { font-size: 18px; margin: 0; flex: 1; }
  header nav { display: flex; gap: 4px; }
  header a { color: white; text-decoration: none; padding: 6px 10px; border-radius: 6px; font-size: 14px; }
  header a[aria-current] { background: #ffffff33; }
  main { display: grid; grid-template-columns: 280px 1fr; gap: 24px; padding: 24px; max-width: 1400px; margin: auto; }
  aside, section { background: white; border-radius: 10px; padding: 16px; box-shadow: 0 1px 3px #0001; }
  label { font-size: 13px; display: block; margin-bottom: 4px; }
  select { width: 100%; padding: 6px; }
  .client { border: 1px solid #c7d2fe; border-radius: 8px; padding: 10px; margin-top: 12px; font-size: 14px; }
  button { margin-top: 8px; width: 100%; padding: 8px; border: 0; border-radius: 6px; background: #4338ca; color: white; cursor: pointer; }
  #log { font-size: 12px; font-family: monospace; white-space: pre-wrap; margin-top: 16px; }
</style>`

// Same integration with the <rocket-mailer-composer> web component: no JavaScript needed to mount it.
function webComponentPage(appId) {
  const options = USERS.map(u => `<option>${escape(u)}</option>`).join('')
  return `<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Démo CRM — web component</title>
${STYLE}</head>
<body>
${nav('/web-component')}
<main>
  <aside>
    <label for="user">Utilisateur connecté au CRM (impersonné)</label>
    <select id="user">${options}</select>
    <div class="client"><strong>Client : Société Exemple</strong><br>client@example.com<br>Devis n°42 en attente
      <button id="prefill">Écrire à ce client</button></div>
    <div id="log">Événements du composant :</div>
  </aside>
  <section>
    <rocket-mailer-composer
      id="composer"
      base-url="${escape(MAILER_URL)}"
      application-id="${escape(appId)}"
      token-url="/token?user=${encodeURIComponent(USERS[0])}"
      draft='${escape(JSON.stringify({ to: ['client@example.com'] }))}'
    ></rocket-mailer-composer>
  </section>
</main>
<script src="${escape(MAILER_URL)}/embed.js"></script>
<script>
  const log = (m) => { document.getElementById('log').textContent += '\\n' + new Date().toLocaleTimeString() + ' ' + m }
  const composer = document.getElementById('composer')
  composer.addEventListener('ready', () => log('ready'))
  composer.addEventListener('sent', (e) => log('sent : « ' + e.detail.subject + ' » → ' + e.detail.to.join(', ')))
  composer.addEventListener('error', (e) => log('error : ' + e.detail.message))
  // Changing an attribute remounts the composer, here as another CRM user.
  document.getElementById('user').addEventListener('change', (e) => composer.setAttribute('token-url', '/token?user=' + encodeURIComponent(e.target.value)))
  document.getElementById('prefill').addEventListener('click', () => {
    composer.draft = { from: 'Service commercial <commercial@crm.example.org>', to: ['client@example.com'], subject: 'Votre devis n°42' }
  })
</script>
</body></html>`
}

function page(appId) {
  const options = USERS.map(u => `<option>${escape(u)}</option>`).join('')
  return `<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Démo CRM</title>
${STYLE}</head>
<body>
${nav('/')}
<main>
  <aside>
    <label for="user">Utilisateur connecté au CRM (impersonné)</label>
    <select id="user">${options}</select>
    <div class="client"><strong>Client : Société Exemple</strong><br>client@example.com<br>Devis n°42 en attente
      <button id="prefill">Écrire à ce client</button>
      <button id="quote">Envoyer le devis (PDF joint)</button>
      <button id="remind">Relancer le devis (template + variables)</button></div>
    <div id="log">Événements du widget :</div>
  </aside>
  <section><div id="mailer"></div></section>
</main>
<script src="${escape(MAILER_URL)}/embed.js"></script>
<script>
  const log = (m) => { document.getElementById('log').textContent += '\\n' + new Date().toLocaleTimeString() + ' ' + m }
  let widget
  function mount() {
    widget?.destroy()
    const user = document.getElementById('user').value
    widget = RocketMailer.mount('#mailer', {
      baseUrl: ${JSON.stringify(MAILER_URL)},
      applicationId: ${JSON.stringify(appId)},
      getToken: () => fetch('/token?user=' + encodeURIComponent(user)).then(r => r.json()).then(d => { log('jeton embed obtenu pour ' + user); return d.token }),
      onReady: () => log('composeur prêt'),
      onSent: (email) => log('envoyé : « ' + email.subject + ' » → ' + email.to.join(', ')),
      onError: (e) => log('erreur : ' + e.message),
    })
  }
  document.getElementById('user').addEventListener('change', mount)
  // "From" imposed by the CRM: allowed because the application may use *@crm.example.org.
  const FROM = 'Service commercial <commercial@crm.example.org>'
  document.getElementById('prefill').addEventListener('click', () => widget.setDraft({ from: FROM, to: ['client@example.com'], subject: 'Votre devis n°42' }))
  // The CRM backend generates the PDF and uploads it as the current user; the composer receives its id.
  document.getElementById('quote').addEventListener('click', async () => {
    const user = document.getElementById('user').value
    const response = await fetch('/quote?user=' + encodeURIComponent(user), { method: 'POST' })
    if (!response.ok) return log('erreur : devis non joint (' + response.status + ')')
    const attachment = await response.json()
    log('devis joint : ' + attachment.filename)
    widget.setDraft({ from: FROM, to: ['client@example.com'], subject: 'Votre devis n°42', attachments: [attachment.id] })
  })
  // Template variables: the CRM knows the client and the quote, the composer fills "{{ … }}" with them.
  document.getElementById('remind').addEventListener('click', async () => {
    const user = document.getElementById('user').value
    const response = await fetch('/template?name=' + encodeURIComponent('Relance devis') + '&user=' + encodeURIComponent(user))
    if (!response.ok) return log('erreur : template introuvable (' + response.status + ')')
    const template = await response.json()
    log('template « ' + template.name + ' » avec variables')
    widget.setDraft({
      from: FROM,
      to: ['client@example.com'],
      template: template.id,
      variables: { client: { prenom: 'Claire', societe: 'Société Exemple' }, devis: { numero: '42', montant: '1 250 €' } },
    })
  })
  mount()
</script>
</body></html>`
}

// Minimal one-page PDF, standing in for a document generated by the CRM.
function quotePdf() {
  const text = 'Devis n 42 - Societe Exemple - 1 250,00 EUR HT'
  const objects = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
    null,
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
  ]
  const stream = `BT /F1 18 Tf 72 760 Td (${text}) Tj ET`
  objects[3] = `<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`
  let pdf = '%PDF-1.4\n'
  const offsets = objects.map((body, i) => {
    const offset = pdf.length
    pdf += `${i + 1} 0 obj\n${body}\nendobj\n`
    return offset
  })
  const xref = pdf.length
  pdf += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n${offsets.map(o => `${String(o).padStart(10, '0')} 00000 n \n`).join('')}`
  pdf += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`
  return Buffer.from(pdf, 'latin1')
}

let appIdPromise
http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost')
  try {
    if (url.pathname === '/quote' && req.method === 'POST') {
      const user = url.searchParams.get('user')
      if (!USERS.includes(user)) {
        res.writeHead(400).end()
        return
      }
      const form = new FormData()
      form.append('file', new Blob([quotePdf()], { type: 'application/pdf' }), 'devis-42.pdf')
      const response = await fetch(`${API}/api/attachments`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${APP_TOKEN}`, 'X-Impersonate-User': user, 'Accept': 'application/json' },
        body: form,
      })
      res.writeHead(response.status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' })
      res.end(await response.text())
      return
    }
    // Looks a template up by name, as the CRM user (templates are listed with the application token).
    if (url.pathname === '/template') {
      const user = url.searchParams.get('user')
      if (!USERS.includes(user)) {
        res.writeHead(400).end()
        return
      }
      const response = await fetch(`${API}/api/email_templates?itemsPerPage=200`, {
        headers: { 'Authorization': `Bearer ${APP_TOKEN}`, 'X-Impersonate-User': user, 'Accept': 'application/json' },
      })
      const template = response.ok ? (await response.json()).find(t => t.name === url.searchParams.get('name')) : undefined
      res.writeHead(template ? 200 : 404, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' })
      res.end(JSON.stringify(template ? { id: template.id, name: template.name } : { error: 'not found' }))
      return
    }
    if (url.pathname === '/token') {
      const user = url.searchParams.get('user')
      if (!USERS.includes(user)) {
        res.writeHead(400).end()
        return
      }
      const response = await fetch(`${API}/api/embed/token`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${APP_TOKEN}`, 'X-Impersonate-User': user, 'Accept': 'application/json' },
      })
      res.writeHead(response.status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' })
      res.end(await response.text())
      return
    }
    if (url.pathname === '/' || url.pathname === '/web-component') {
      appIdPromise ??= applicationId().catch((e) => { appIdPromise = undefined; throw e })
      const appId = await appIdPromise
      const html = url.pathname === '/' ? page(appId) : webComponentPage(appId)
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' })
      res.end(html)
      return
    }
    res.writeHead(404).end()
  }
  catch (error) {
    if (res.headersSent) {
      res.destroy(error)
      return
    }
    res.writeHead(503, { 'Content-Type': 'text/plain; charset=utf-8' })
    res.end(`Rocket Mailer n'est pas encore prêt (${error.message}). Réessayez dans quelques secondes.`)
  }
}).listen(PORT, () => console.log(`Démo CRM on http://localhost:${PORT}`))
