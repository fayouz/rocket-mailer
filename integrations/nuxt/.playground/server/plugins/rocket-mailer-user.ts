// Playground only: a real application reads its own session here (nuxt-auth-utils, sidebase, cookies…).
export default defineNitroPlugin((nitro) => {
  nitro.hooks.hook('rocket-mailer:user', (event, context) => {
    context.email = getCookie(event, 'playground-user') || 'alice@example.org'
  })
})
