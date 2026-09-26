import { fileURLToPath } from 'node:url'

export default defineNuxtConfig({
  // The Rocket core layer (npm "@rocket/core", from GitHub): layout, dashboard, accounts, applications, updates…
  // ROCKET_CORE_LAYER: a local checkout of rocket-core/nuxt, to work on both at once.
  extends: [process.env.ROCKET_CORE_LAYER || fileURLToPath(new URL('./node_modules/@rocket/core/nuxt', import.meta.url))],
  compatibilityDate: '2026-09-01',
  modules: ['@nuxt/eslint'],
  app: {
    head: {
      title: 'Rocket Mailer',
    },
  },
  runtimeConfig: {
    public: {
      apiBase: 'http://localhost:8000',
      // Dashboard shortcuts.
      docsUrl: 'https://github.com/fayouz/rocket-mailer/tree/develop/docs/content',
      changelogUrl: 'https://github.com/fayouz/rocket-mailer/blob/develop/CHANGELOG.md',
      // Test inbox (demo, local stack): "Mailpit" shortcut of the dashboard; empty: hidden.
      mailpitUrl: '',
    },
  },
  // CKEditor and GrapesJS are browser-only (the layer turns SSR off).
  vite: {
    optimizeDeps: {
      include: ['ckeditor5', '@ckeditor/ckeditor5-vue', 'grapesjs', 'grapesjs-preset-newsletter'],
    },
  },
})
