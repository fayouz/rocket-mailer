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

const escape = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' })[c])

function page(appId) {
  const options = USERS.map(u => `<option>${escape(u)}</option>`).join('')
  return `<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Démo CRM</title>
<style>
  body { margin: 0; font-family: system-ui, sans-serif; background: #eef2ff; color: #1e1b4b; }
  header { background: #4338ca; color: white; padding: 14px 24px; display: flex; gap: 16px; align-items: center; }
  header h1 { font-size: 18px; margin: 0; flex: 1; }
  main { display: grid; grid-template-columns: 280px 1fr; gap: 24px; padding: 24px; max-width: 1400px; margin: auto; }
  aside, section { background: white; border-radius: 10px; padding: 16px; box-shadow: 0 1px 3px #0001; }
  label { font-size: 13px; display: block; margin-bottom: 4px; }
  select { width: 100%; padding: 6px; }
  .client { border: 1px solid #c7d2fe; border-radius: 8px; padding: 10px; margin-top: 12px; font-size: 14px; }
  button { margin-top: 8px; width: 100%; padding: 8px; border: 0; border-radius: 6px; background: #4338ca; color: white; cursor: pointer; }
  #log { font-size: 12px; font-family: monospace; white-space: pre-wrap; margin-top: 16px; }
</style></head>
<body>
<header><h1>Démo CRM — application tierce</h1><span>Intégration Rocket Mailer</span></header>
<main>
  <aside>
    <label for="user">Utilisateur connecté au CRM (impersonné)</label>
    <select id="user">${options}</select>
    <div class="client"><strong>Client : Société Exemple</strong><br>client@example.com<br>Devis n°42 en attente
      <button id="prefill">Écrire à ce client</button></div>
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
  document.getElementById('prefill').addEventListener('click', () => widget.setDraft({ to: ['client@example.com'], subject: 'Votre devis n°42' }))
  mount()
</script>
</body></html>`
}

let appIdPromise
http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost')
  try {
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
    if (url.pathname === '/') {
      appIdPromise ??= applicationId().catch((e) => { appIdPromise = undefined; throw e })
      const html = page(await appIdPromise)
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
