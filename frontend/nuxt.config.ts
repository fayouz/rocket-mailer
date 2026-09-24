export default defineNuxtConfig({
  compatibilityDate: '2026-09-01',
  modules: ['@nuxt/ui', '@nuxt/eslint'],
  // Back-office app: CKEditor and GrapesJS are browser-only, and auth lives client-side.
  ssr: false,
  css: ['~/assets/css/main.css'],
  // No third-party font CDNs: system fonts only.
  ui: { fonts: false },
  devtools: { enabled: false },
  app: {
    head: {
      title: 'Rocket Mailer',
      htmlAttrs: { lang: 'fr' },
    },
  },
  runtimeConfig: {
    // Server-to-server URL of the API (e.g. http://api:80 inside Docker); falls back to the public one.
    apiInternalBase: '',
    public: {
      apiBase: 'http://localhost:8000',
      // Dashboard shortcuts.
      docsUrl: 'https://github.com/fayouz/rocket-mailer/tree/develop/docs/content',
      mailpitUrl: '',
    },
  },
  icon: {
    serverBundle: { collections: ['lucide'] },
    clientBundle: { scan: true },
  },
  vite: {
    optimizeDeps: {
      include: ['ckeditor5', '@ckeditor/ckeditor5-vue', 'grapesjs', 'grapesjs-preset-newsletter'],
    },
  },
})
