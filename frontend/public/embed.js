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
 * Or, as a web component (same script):
 *
 *   <rocket-mailer-composer application-id="<application uuid>" token-url="/rocket-mailer/token"></rocket-mailer-composer>
 *   <script>
 *     const composer = document.querySelector('rocket-mailer-composer')
 *     composer.draft = { to: ['client@example.com'], subject: 'Hello' }
 *     composer.addEventListener('sent', event => console.log('sent', event.detail))
 *   </script>
 *
 * The application secret must never be sent to the browser: only short-lived embed tokens are.
 */
(function (global) {
  'use strict'

  var SOURCE = 'rocket-mailer'
  // Where this script is served from: the default Rocket Mailer URL of the web component.
  var SCRIPT_ORIGIN = (function () {
    try { return new URL(document.currentScript.src).origin } catch (e) { return null }
  })()

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

  /*
   * <rocket-mailer-composer> web component.
   *
   * Attributes: application-id (required), token-url (or the getToken property), token-method (POST),
   * base-url (default: where embed.js is served from), draft (JSON), min-height, no-auto-resize, frame-title.
   * Properties: draft, getToken, iframe (read-only). Method: setDraft(draft).
   * Events (bubbling, composed): "ready", "sent" (detail: the email), "error" (detail: the error).
   */
  if (!global.customElements || global.customElements.get('rocket-mailer-composer')) return

  var OBSERVED = ['application-id', 'base-url', 'token-url', 'token-method', 'draft', 'min-height', 'no-auto-resize', 'frame-title']

  function RocketMailerComposer() {
    return Reflect.construct(HTMLElement, [], RocketMailerComposer)
  }
  RocketMailerComposer.prototype = Object.create(HTMLElement.prototype)
  RocketMailerComposer.prototype.constructor = RocketMailerComposer
  Object.setPrototypeOf(RocketMailerComposer, HTMLElement)
  Object.defineProperty(RocketMailerComposer, 'observedAttributes', { get: function () { return OBSERVED } })

  var proto = RocketMailerComposer.prototype

  proto._init = function () {
    if (this._root) return
    this._root = this.attachShadow({ mode: 'open' })
    this._root.innerHTML = '<style>:host{display:block}:host([hidden]){display:none}div{width:100%}</style><div part="container"></div>'
    this._container = this._root.querySelector('div')
    this._instance = null
    this._ready = false
    this._pending = null
    // Properties set before embed.js was loaded live on the element itself: route them through the setters.
    var self = this
    ;['draft', 'getToken'].forEach(function (name) {
      if (Object.prototype.hasOwnProperty.call(self, name)) {
        var value = self[name]
        delete self[name]
        self[name] = value
      }
    })
  }

  proto._emit = function (type, detail) {
    this.dispatchEvent(new CustomEvent(type, { detail: detail, bubbles: true, composed: true }))
  }

  proto._tokenProvider = function () {
    var self = this
    if (typeof this._getToken === 'function') return this._getToken
    var url = this.getAttribute('token-url')
    if (!url) return null
    return function () {
      return fetch(url, {
        method: self.getAttribute('token-method') || 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      }).then(function (response) {
        if (!response.ok) throw new Error('RocketMailer: token endpoint answered ' + response.status)
        return response.json()
      }).then(function (data) {
        if (!data || typeof data.token !== 'string') throw new Error('RocketMailer: the token endpoint must return { "token": "…" }')
        return data.token
      })
    }
  }

  // Frameworks often set properties right after inserting the element: mount on the next microtask.
  proto._scheduleMount = function () {
    var self = this
    if (this._scheduled) return
    this._scheduled = true
    Promise.resolve().then(function () {
      self._scheduled = false
      if (self.isConnected) self._mount()
    })
  }

  proto._mount = function () {
    var self = this
    this._unmount()
    var applicationId = this.getAttribute('application-id')
    var baseUrl = this.getAttribute('base-url') || SCRIPT_ORIGIN
    var getToken = this._tokenProvider()
    if (!applicationId || !baseUrl || !getToken) {
      // Frameworks may set getToken a little later (after rendering): look again once before complaining.
      if (!this._waited) {
        this._waited = true
        setTimeout(function () { if (self.isConnected && !self._instance) self._mount() }, 50)
        return
      }
      this._emit('error', new Error('RocketMailer: application-id and token-url (or the getToken property) are required'))
      return
    }
    this._waited = false
    try {
      this._instance = mount(this._container, {
        baseUrl: baseUrl,
        applicationId: applicationId,
        getToken: getToken,
        draft: this._pending || undefined,
        title: this.getAttribute('frame-title') || undefined,
        minHeight: Number(this.getAttribute('min-height')) || undefined,
        autoResize: !this.hasAttribute('no-auto-resize'),
        onReady: function () {
          self._ready = true
          self._pending = null
          self._emit('ready')
        },
        onSent: function (email) { self._emit('sent', email) },
        onError: function (error) { self._emit('error', error) },
      })
    } catch (error) {
      this._emit('error', error)
    }
  }

  proto._unmount = function () {
    if (this._instance) this._instance.destroy()
    this._instance = null
    this._ready = false
  }

  proto.connectedCallback = function () {
    this._init()
    this._scheduleMount()
  }

  proto.disconnectedCallback = function () {
    this._unmount()
  }

  proto.attributeChangedCallback = function (name, oldValue, value) {
    if (oldValue === value) return
    this._init()
    if (name === 'draft') {
      if (!value) return
      try {
        this.setDraft(JSON.parse(value))
      } catch (error) {
        this._emit('error', new Error('RocketMailer: the draft attribute must be valid JSON'))
      }
      return
    }
    if (this.isConnected) this._scheduleMount()
  }

  /** Applies a draft now, or as soon as the composer is ready. */
  proto.setDraft = function (draft) {
    this._init()
    if (!draft || typeof draft !== 'object') return
    if (this._ready && this._instance) this._instance.setDraft(draft)
    else this._pending = Object.assign({}, this._pending, draft)
  }

  Object.defineProperty(proto, 'draft', {
    get: function () { return this._pending },
    set: function (draft) { this.setDraft(draft) },
  })

  Object.defineProperty(proto, 'getToken', {
    get: function () { return this._getToken },
    set: function (fn) {
      this._getToken = fn
      if (this.isConnected) this._scheduleMount()
    },
  })

  Object.defineProperty(proto, 'iframe', {
    get: function () { return this._instance ? this._instance.iframe : null },
  })

  global.customElements.define('rocket-mailer-composer', RocketMailerComposer)
})(window)
