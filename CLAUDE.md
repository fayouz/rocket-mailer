# Rocket Mailer

Brique du Middleware Rocket. Socle commun : [rocket-core](https://github.com/fayouz/rocket-core) (bundle Symfony `rocket/core-bundle` + layer Nuxt `@rocket/core`), à lire avant de modifier les comptes, le SSO, les applications, le tableau de bord ou la mise en page : ce code n'est pas ici.

## Repères
- `app_id` `mailer`, jetons d'application `rma_…`, ports front 3000 · api 8000 · docs 3001 (démo : CRM 4000, Mailpit 8025).
- Domaine : e-mails : `src/Template`, `src/Sender` (`SenderPolicy`, `ApplicationSender` 1:1 avec l'Application du socle), `src/Mailbox` (SMTP/IMAP, `MAILBOX_ENCRYPTION_KEY`), `src/Attachment`, `src/Embed` (endpoints du composeur embarqué), sondes `src/Health`.
- Stack : Symfony 8.1 + API Platform + Doctrine/PostgreSQL + LexikJWT + Messenger/Scheduler (`backend/`), Nuxt 4 + Nuxt UI 4 qui étend le layer (`frontend/`), Nuxt Content (`docs/`), Docker Compose (`compose.yaml` + `compose.demo.yaml`).
- Points d'extension du socle (interfaces) : `DashboardSectionInterface`, `ServiceProbeInterface`, `DemoSeederInterface`, `RecurringTaskProviderInterface`, `EmbedEndpointsInterface` ; côté front `rocket.extensions` dans `app.config.ts`.
- Mode suite : `ROCKET_AUTH_URL` non vide (voir le README de rocket-core).
- Tests des boîtes : GreenMail (`GREENMAIL_HOST`), absent en local → 3 tests ignorés, lancés en CI.
- Composeur embarqué : `pages/embed/compose.vue` + `public/embed.js` (source postMessage `rocket-mailer`).
- Page Applications : extensions du layer (`rocket.extensions.applications` + `components/global/Mailer*.vue`), pas de copie de la page.

## Vérifier avant de pousser
```bash
cd backend && php bin/console lint:container && php bin/console doctrine:schema:validate && php bin/phpunit
cd frontend && npm run lint && npm run typecheck
cd docs && npm run lint && npm run typecheck && npm run generate   # si docs/ a changé
```
CI : `.github/workflows/ci.yml` appelle les workflows réutilisables de rocket-core (`@vX.Y.Z`) ; scénarios de démo dans `.github/demo-scenarios.sh`.

## Pièges connus
- API Platform répond en JSON-LD par défaut : envoyer `Accept: application/json` pour obtenir un tableau (scripts, curl, jq).
- Local sans `ext-ldap` : `composer install --ignore-platform-req=ext-ldap`. Composer installe depuis les sources git : `vendor/*/*/.git` pèse plusieurs Go, à supprimer si le disque manque.
- Base de test partagée entre dépôts en local (`app_test`) : la recréer et migrer si des colonnes manquent.
- rocket-core suit semver (`^0.x`) ; Renovate ouvre les mises à jour (correctifs fusionnés seuls si la CI passe). Nouvelles migrations du socle : idempotentes (`write()` + `return`), jamais `skipIf`.
- Ne pas copier une page du layer pour l'étendre : utiliser `rocket.extensions`.
