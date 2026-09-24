/*!
 * Rocket Mailer embed widget.
 *
 *   <script src="https://mailer.example.com/embed.js"></script>
 *   <script>
 *     const mailer = RocketMailer.mount('#mailer', {
 *       baseUrl: 'https://mailer.example.com',
 *       applicationId: '<application uuid>',
 *       // Called whenever a token is needed (initially and when it expires).
 *       // It must hit YOUR backend, which calls POST /api/embed/token with the application secret.
 *       getToken: () => fetch('/rocket-mailer/token').then(r => r.json()).then(d => d.token),
 *       draft: { to: ['client@example.com'], subject: 'Hello' },  // optional prefill
 *       onSent: email => console.log('sent', email),                // optional
 *     })
 *     mailer.setDraft({ subject: 'Updated' })
 *     mailer.destroy()
 *   </script>
 *
 * The application secret must never be sent to the browser: only short-lived embed tokens are.
 */
(function (global) {
  'use strict'

  var SOURCE = 'rocket-mailer'

  function mount(target, options) {
    var container = typeof target === 'string' ? document.querySelector(target) : target
    if (!container) throw new Error('RocketMailer: container not found')
    if (!options || !options.baseUrl || !options.applicationId || typeof options.getToken !== 'function') {
      throw new Error('RocketMailer: baseUrl, applicationId and getToken are required')
    }

    var origin = new URL(options.baseUrl).origin
    var iframe = document.createElement('iframe')
    iframe.src = origin + '/embed/compose?app=' + encodeURIComponent(options.applicationId)
    iframe.title = options.title || 'Rocket Mailer'
    iframe.style.width = '100%'
    iframe.style.border = '0'
    iframe.style.minHeight = (options.minHeight || 640) + 'px'
    iframe.setAttribute('allow', 'clipboard-write')
    iframe.setAttribute('referrerpolicy', 'strict-origin')
    container.appendChild(iframe)

    function post(message) {
      message.source = SOURCE
      // Always target the mailer origin so the token cannot leak to another document.
      iframe.contentWindow.postMessage(message, origin)
    }

    function sendToken() {
      return Promise.resolve(options.getToken()).then(function (token) {
        post({ type: 'token', token: token })
      }).catch(function (error) {
        if (options.onError) options.onError(error)
      })
    }

    function onMessage(event) {
      if (event.origin !== origin || event.source !== iframe.contentWindow) return
      var data = event.data || {}
      if (data.source !== SOURCE) return

      switch (data.type) {
        case 'ready':
        case 'token-request':
          sendToken()
          break
        case 'loaded':
          if (options.draft) post({ type: 'draft', draft: options.draft })
          if (options.onReady) options.onReady()
          break
        case 'resize':
          if (options.autoResize !== false && data.height) iframe.style.height = data.height + 'px'
          break
        case 'sent':
          if (options.onSent) options.onSent(data.email)
          break
      }
    }

    global.addEventListener('message', onMessage)

    return {
      iframe: iframe,
      setDraft: function (draft) { post({ type: 'draft', draft: draft }) },
      destroy: function () {
        global.removeEventListener('message', onMessage)
        iframe.remove()
      },
    }
  }

  global.RocketMailer = { mount: mount }
})(window)
