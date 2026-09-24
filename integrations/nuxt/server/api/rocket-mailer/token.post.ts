// Embed token for the logged-in user: the composer calls it whenever it needs a (new) token.
export default defineEventHandler(async (event) => {
  const email = await resolveRocketMailerUser(event)
  if (!email) {
    throw createError({ statusCode: 401, statusMessage: 'No logged-in user: implement the "rocket-mailer:user" Nitro hook' })
  }
  setResponseHeader(event, 'Cache-Control', 'no-store')
  return rocketMailerFetch<{ token: string, expiresAt: string }>('/api/embed/token', { method: 'POST', actAs: email })
})
