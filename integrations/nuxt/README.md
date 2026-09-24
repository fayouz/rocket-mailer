# @rocket-mailer/nuxt

Layer Nuxt pour les applications qui intègrent [Rocket Mailer](https://github.com/fayouz/rocket-mailer) :

- le composant `<RocketMailerComposer>` (composeur d'email embarqué) ;
- l'endpoint `POST /api/rocket-mailer/token`, qui fournit un jeton d'embed pour l'utilisateur connecté ;
- les utilitaires serveur `sendRocketMailerEmail()`, `rocketMailerFetch()` et `resolveRocketMailerUser()`.

> Ce dépôt est un miroir en lecture seule de `integrations/nuxt` dans [fayouz/rocket-mailer](https://github.com/fayouz/rocket-mailer). Ouvrez les issues et les pull requests là-bas.

## Installation

```ts
// nuxt.config.ts
export default defineNuxtConfig({
  extends: ['github:fayouz/rocket-mailer-nuxt'], // ou 'github:fayouz/rocket-mailer/integrations/nuxt#develop'
})
```

Pour un dépôt privé, définissez `GIGET_AUTH=<jeton GitHub>` au moment du build.

```bash
# .env
NUXT_PUBLIC_ROCKET_MAILER_URL=https://mailer.exemple.com
NUXT_PUBLIC_ROCKET_MAILER_APPLICATION_ID=0199a1b2-…
NUXT_ROCKET_MAILER_APP_TOKEN=rma_…          # secret : serveur uniquement
# NUXT_ROCKET_MAILER_API_URL=http://mailer:80  # optionnel : URL interne serveur -> Rocket Mailer
```

## Dire qui est connecté

Le layer ne connaît pas votre authentification. Indiquez-lui l'email de l'utilisateur connecté :

```ts
// server/plugins/rocket-mailer.ts
export default defineNitroPlugin((nitro) => {
  nitro.hooks.hook('rocket-mailer:user', async (event, context) => {
    const { user } = await requireUserSession(event) // nuxt-auth-utils, sidebase, votre cookie…
    context.email = user.email
  })
})
```

## Composeur

```vue
<script setup lang="ts">
const draft = ref({
  to: ['claire@client.example'],
  template: '0199…',                                   // optionnel
  variables: { client: { prenom: 'Claire' } },          // optionnel
})
</script>

<template>
  <RocketMailerComposer :draft="draft" @sent="email => console.log(email)" />
</template>
```

| Prop | Rôle |
|---|---|
| `draft` | Brouillon, réactif (destinataires, objet, template, variables, pièces jointes…) |
| `token-url` | Endpoint de jeton, par défaut celui du layer |
| `get-token` | Fonction qui retourne un jeton, à la place de `token-url` (ex. backend Symfony avec JWT) |
| `min-height`, `auto-resize` | Affichage |

Événements : `ready`, `sent` (l'email), `error`.

## Envoyer sans composeur (serveur)

```ts
// server/api/quotes/[id]/remind.post.ts
export default defineEventHandler(async (event) => {
  const user = await resolveRocketMailerUser(event)
  return sendRocketMailerEmail(user!, {
    to: ['claire@client.example'],
    template: '0199…',
    variables: { client: { prenom: 'Claire' }, devis: { numero: '42' } },
  })
})
```

## Développement

```bash
npm install
npm run dev        # playground sur http://localhost:3002 (origine à autoriser sur l'application)
npm run typecheck
```
