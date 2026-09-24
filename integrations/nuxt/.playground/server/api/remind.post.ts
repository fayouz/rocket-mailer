// Server-side sending, no composer: a template and its variables, on behalf of the logged-in user.
export default defineEventHandler(async (event) => {
  const user = await resolveRocketMailerUser(event)
  if (!user) throw createError({ statusCode: 401 })
  const { template } = await readBody<{ template: string }>(event)
  return sendRocketMailerEmail(user, {
    to: ['client@example.com'],
    template,
    variables: { client: { prenom: 'Claire', societe: 'Société Exemple' }, devis: { numero: '42', montant: '1 250 €' } },
  })
})
