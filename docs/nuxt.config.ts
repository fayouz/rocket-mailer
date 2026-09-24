export default defineNuxtConfig({
  compatibilityDate: '2026-09-01',
  modules: ['@nuxt/ui', '@nuxt/content', '@nuxt/eslint'],
  devtools: { enabled: false },
  css: ['~/assets/css/main.css'],
  ui: { fonts: false },
  app: {
    head: { htmlAttrs: { lang: 'fr' } },
  },
  content: {
    // node:sqlite (Node >= 22.5): no native module to compile.
    experimental: { sqliteConnector: 'native' },
    build: {
      markdown: {
        highlight: {
          langs: ['bash', 'json', 'html', 'js', 'ts', 'vue', 'tsx', 'php', 'python', 'yaml', 'http'],
        },
      },
    },
  },
  icon: {
    // Served from the installed @iconify-json packages, never from the Iconify API.
    serverBundle: 'local',
    clientBundle: {
      scan: true,
      // Icons referenced from Markdown/YAML (not visible to the source scan).
      icons: [
        'lucide:code-xml', 'lucide:settings', 'lucide:terminal', 'lucide:rocket', 'lucide:house', 'lucide:download',
        'lucide:play', 'lucide:map', 'lucide:key-round', 'lucide:server', 'lucide:code', 'lucide:zap', 'lucide:component',
        'lucide:shield-check', 'lucide:arrow-left-right', 'lucide:life-buoy', 'lucide:lock', 'lucide:mail', 'lucide:users',
        'lucide:layout-template', 'lucide:square-dashed-mouse-pointer', 'lucide:pen-line', 'lucide:lock-keyhole',
        'lucide:scan-eye', 'lucide:shield-off', 'lucide:frame', 'lucide:mail-check', 'lucide:power-off', 'lucide:history',
        'lucide:arrow-right', 'lucide:book-open', 'lucide:laptop', 'simple-icons:github',
        'vscode-icons:file-type-html', 'vscode-icons:file-type-js', 'vscode-icons:file-type-nuxt',
        'vscode-icons:file-type-typescript', 'vscode-icons:file-type-vue', 'vscode-icons:file-type-php',
        'vscode-icons:file-type-python', 'vscode-icons:file-type-reactts', 'vscode-icons:file-type-dotenv', 'lucide:at-sign',
      ],
    },
  },
  nitro: {
    prerender: { routes: ['/'], crawlLinks: true },
  },
})
