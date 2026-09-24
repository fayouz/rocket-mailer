# Rocket Mailer

Envoi d'emails en texte enrichi, templates d'email visuels, et composeur embarquable dans des applications tierces.

| Dossier | Stack |
|---|---|
| `backend/` | Symfony 8.1, API Platform 5, Doctrine ORM 3 (PostgreSQL), StofDoctrineExtensions, LexikJWT, Messenger, Mailer, LDAP |
| `frontend/` | Nuxt 4, Nuxt UI 4, CKEditor 5 (composeur), GrapesJS + preset newsletter (templates) |
| `docs/` | Site de documentation (Nuxt UI + Nuxt Content), avec le changelog sur `/changelog` : `cd docs && npm install && npm run dev`, puis http://localhost:3001 |

## Démarrage rapide

```bash
docker compose up -d --build
docker compose exec api php bin/console app:user:create admin@example.org 'un-mot-de-passe-long' --admin
```

- Application : http://localhost:3000
- API + documentation OpenAPI : http://localhost:8000/api/docs
- Emails reçus (Mailpit) : http://localhost:8025

### Démo prête à tester

`docker compose -f compose.yaml -f compose.demo.yaml up -d --build` lance une démo complète : comptes locaux et LDAP, templates, et une application tierce qui embarque le composeur. Voir [demo/README.md](demo/README.md).

### Développement sans Docker

```bash
# backend (PHP 8.4, PostgreSQL)
cd backend && composer install
php bin/console lexik:jwt:generate-keypair
php bin/console doctrine:migrations:migrate
echo 'MESSENGER_TRANSPORT_DSN=sync://' >> .env.local   # ou lancer messenger:consume async
php -S 127.0.0.1:8000 -t public
php bin/phpunit

# frontend
cd frontend && npm install && npm run dev            # NUXT_PUBLIC_API_BASE=http://localhost:8000
```

## Fonctionnalités

### Tableau de bord et suivi des envois
- **Tableau de bord** (page d'accueil) : envois et délivrabilité sur 30 jours, file d'envoi, intégrations, activité récente, état des services (base, file, SMTP, LDAP, stockage). Un utilisateur y voit ses propres chiffres ; un administrateur, toute la plateforme. API : `GET /api/dashboard`.
- **Mes envois** et **Tous les envois** (admin) : recherche dans l'objet et les destinataires, filtres par statut, application, expéditeur et période, pagination. Les mêmes filtres existent dans l'API (`GET /api/emails?q=…&status=…`).

### Utilisateurs et LDAP
- Comptes **locaux** (mot de passe haché) ou **LDAP** (authentification par bind sur l'annuaire).
- Synchronisation : `php bin/console app:ldap:sync [--dry-run]` (à planifier en cron) ou bouton « Synchroniser LDAP » (admin).
  Elle crée et met à jour les comptes et désactive ceux qui ont disparu de l'annuaire. Elle ne prend jamais le contrôle d'un compte local portant le même email.
- `LDAP_ADMIN_GROUP_DN` : les membres de ce groupe (attribut `memberOf`) reçoivent `ROLE_ADMIN`. Vide : les admins sont gérés dans l'application.
- Variables : `LDAP_ENABLED`, `LDAP_URL`, `LDAP_BASE_DN`, `LDAP_SEARCH_DN`, `LDAP_SEARCH_PASSWORD`, `LDAP_USER_FILTER`, `LDAP_ADMIN_GROUP_DN`.

### Applications externes et impersonation
Un administrateur crée une application. Son jeton secret (`rma_…`) n'est affiché qu'une seule fois, et seul son hash SHA-256 est stocké.

| En-têtes | Effet |
|---|---|
| `Authorization: Bearer rma_…` | L'application s'identifie (accès limité à `GET /api/me`). |
| `+ X-Impersonate-User: jean@exemple.org` | L'application agit **en tant que** cet utilisateur (si « impersonation » est autorisée). Elle n'obtient **jamais** `ROLE_ADMIN`, même en impersonnant un admin. |

Chaque email envoyé garde l'utilisateur **et** l'application d'origine. Désactiver une application ou régénérer son jeton coupe l'accès immédiatement.

### Composeur embarqué (widget)
1. **Côté serveur de l'application tierce** (le secret ne doit jamais aller dans le navigateur) :
   ```bash
   curl -X POST https://mailer.exemple.com/api/embed/token \
     -H "Authorization: Bearer rma_…" -H "X-Impersonate-User: jean@exemple.org"
   # → { "token": "<jwt 15 min>", "applicationId": "…", "expiresAt": "…" }
   ```
2. **Côté navigateur** :
   ```html
   <script src="https://mailer.exemple.com/embed.js"></script>
   <div id="mailer"></div>
   <script>
     RocketMailer.mount('#mailer', {
       baseUrl: 'https://mailer.exemple.com',
       applicationId: '<uuid de l’application>',
       getToken: () => fetch('/mon-backend/rocket-mailer-token').then(r => r.json()).then(d => d.token),
       draft: { to: ['client@exemple.com'], subject: 'Votre devis' }, // optionnel
       onSent: (email) => console.log('envoyé', email),               // optionnel
     })
   </script>
   ```

Sécurité du composeur embarqué :
- **Origines autorisées :** la page `/embed/compose` n'est affichable que depuis les origines déclarées sur l'application (`Content-Security-Policy: frame-ancestors`). Toutes les autres pages envoient `frame-ancestors 'none'`.
- **Transmission du jeton :** le jeton passe par `postMessage` ; l'iframe n'accepte que les messages venant de `window.parent` et d'une origine autorisée. Il reste en mémoire (pas de cookie, pas d'URL) et il est renouvelé automatiquement via `getToken` quand il expire.
- **Jeton d'embed :** c'est un JWT à scope `embed`, envoyé avec `Authorization: Embed <jwt>`. Il ne peut que lister et lire les templates, envoyer un email et lire ses propres envois. Il est refusé comme session utilisateur (`Bearer`) et révoqué dès que l'application est désactivée.

### Adresse d'expédition (« De »)
- Le composeur a une liste **De**. Elle propose les adresses d'expédition des **Réglages** (l'adresse par défaut est présélectionnée) et l'adresse de l'utilisateur, sauf si les Réglages l'interdisent.
- À l'installation, `MAILER_DEFAULT_FROM="Nom <adresse>"` crée l'adresse par défaut. On la gère ensuite dans les Réglages.
- Une application peut imposer l'adresse à la volée, avec `setDraft({ from })` ou le champ `from` de l'API, dans la limite de ses **adresses d'expédition autorisées** (`contact@…` ou `*@domaine`). Toute autre adresse est refusée. Les réponses reviennent à l'utilisateur (`Reply-To`).

### Pièces jointes
- Dans le composeur (application et widget), avec le bouton **Joindre des fichiers** ou par glisser-déposer : 10 fichiers, 10 Mo par fichier et 25 Mo par email par défaut. Les exécutables et scripts sont refusés.
- Une application peut joindre un document qu'elle génère, par exemple un devis PDF. Son backend le téléverse au nom de l'utilisateur, puis la page le passe au widget avec `setDraft({ attachments: [id] })`.
- Stockage sur disque dans `ATTACHMENTS_DIR`, un volume partagé entre l'API et le worker. Les fichiers jamais envoyés sont purgés par `app:attachments:purge`.

### Templates d'email
- Éditeur visuel GrapesJS (preset newsletter). On stocke le projet GrapesJS (réédition) et le HTML email avec CSS inliné (import).
- Import dans le composeur via « Importer un template » : le contenu arrive dans CKEditor, qui conserve le balisage d'email grâce à General HTML Support.
- Templates privés ou partagés ; seul le propriétaire (ou un admin) les modifie.
- **Variables** `{{ client.prenom }}` : insérées avec le bouton **{x}** de l'éditeur, avec un libellé et une valeur par défaut. Les valeurs viennent du composeur, de l'application qui l'embarque (`setDraft({ template, variables })`) ou de l'API (`POST /api/emails` avec `template` et `variables`).
- **Versionnés** (Gedmo Loggable) : `GET /api/email_templates/{id}/versions` et `POST …/versions/{n}/restore`.

### Traçabilité
Toutes les entités sont **Timestampable** et **Blameable** (`createdAt`, `updatedAt`, `createdBy`, `updatedBy`) via StofDoctrineExtensionsBundle. Seuls les templates sont versionnés.

## CI/CD

`.github/workflows/ci.yml` :
- à chaque push et pull request : lint du container, validation du schéma Doctrine, PHPUnit, puis ESLint, typecheck et build Nuxt ;
- sur `main`, `develop` et les tags `v*` : build et push des images sur **ghcr.io** :
  - `ghcr.io/fayouz/rocket-mailer-api`
  - `ghcr.io/fayouz/rocket-mailer-front`

  Les tags d'image suivent le nom de branche, le semver, le sha court, et `latest` pour `main`.

Le worker utilise l'image API avec `php bin/console messenger:consume async`.

## Gitflow

- `main` : production (images `latest` et tags `vX.Y.Z`)
- `develop` : intégration (images `develop`)
- `feature/*` : une fonctionnalité, en pull request vers `develop`. Chaque pull request complète la section `[Non publié]` de [CHANGELOG.md](CHANGELOG.md) (format [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/)), publiée sur la page `/changelog` de la documentation.
- `release/*` et `hotfix/*` : préparation de version et correctifs vers `main`. À la release, `[Non publié]` devient `[X.Y.Z] - date`, puis on tague `vX.Y.Z`.
