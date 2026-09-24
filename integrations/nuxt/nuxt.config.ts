// Rocket Mailer layer: add it to a Nuxt app with `extends` (see README.md).
export default defineNuxtConfig({
  runtimeConfig: {
    rocketMailer: {
      /** Application secret (rma_…): server only. NUXT_ROCKET_MAILER_APP_TOKEN */
      appToken: '',
      /** Server-to-server URL of Rocket Mailer, if different from the public one. NUXT_ROCKET_MAILER_API_URL */
      apiUrl: '',
    },
    public: {
      rocketMailer: {
        /** Public URL of Rocket Mailer. NUXT_PUBLIC_ROCKET_MAILER_URL */
        url: '',
        /** Id of the application declared in Rocket Mailer. NUXT_PUBLIC_ROCKET_MAILER_APPLICATION_ID */
        applicationId: '',
        /** Route of the embed token endpoint provided by this layer. */
        tokenRoute: '/api/rocket-mailer/token',
      },
    },
  },
  vue: {
    compilerOptions: {
      // The composer web component, defined by Rocket Mailer's embed.js.
      isCustomElement: tag => tag === 'rocket-mailer-composer',
    },
  },
})
