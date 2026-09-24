# Changelog

Toutes les évolutions notables de Rocket Mailer. Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet respecte le [versionnage sémantique](https://semver.org/lang/fr/).

## [0.6.0] - 2026-09-24

Un tableau de bord pour suivre la plateforme d'un coup d'œil, et un journal pour retrouver n'importe quel email envoyé.

### Ajouté

- **Tableau de bord**, nouvelle page d'accueil :
  - envois des 30 derniers jours, comparés aux 30 jours précédents ;
  - taux de délivrabilité, file d'envoi, utilisateurs locaux et LDAP ;
  - intégrations, derniers envois et activité récente groupée par jour ;
  - barres de délivrabilité quotidienne et raccourcis.
- **État des services** pour les administrateurs : base de données, file d'envoi, relais SMTP, annuaire LDAP et stockage des pièces jointes.
- **Tous les envois** (administrateurs) et **Mes envois** :
  - recherche dans l'objet, l'adresse d'expédition et tous les destinataires ;
  - filtres par statut, application, expéditeur et période ;
  - pagination et filtres conservés dans l'URL.
- API : `GET /api/dashboard`, et les filtres `q`, `status`, `sender`, `application` et `createdAt` sur `GET /api/emails`.
- Actions rapides : « Nouvelle application » et « Nouvel utilisateur » ouvrent directement le formulaire.
- Ce changelog, publié dans le site de documentation.

### Modifié

- Le menu est organisé en sections (Messagerie, Administration).
- Après connexion, on arrive sur le tableau de bord au lieu du composeur.

### Corrigé

- Créer un template sans utilisateur connecté (commande console, import) faisait échouer l'enregistrement de sa version.

## [0.5.0] - 2026-09-24

Choisir l'adresse d'expédition.

### Ajouté

- Liste **« De »** dans le composeur :
  - elle propose les adresses d'expédition des Réglages, l'adresse par défaut présélectionnée ;
  - elle propose aussi l'adresse de l'utilisateur, sauf si les Réglages l'interdisent.
- Page **Réglages** (administrateurs) : adresses d'expédition, adresse par défaut et autorisation de l'adresse personnelle.
- `MAILER_DEFAULT_FROM` crée l'adresse par défaut à l'installation.
- Une application peut imposer l'adresse à la volée avec `setDraft({ from })` ou le champ `from` de l'API. Elle est limitée à ses **adresses d'expédition autorisées** (`contact@…` ou `*@domaine`).
- Quand l'email part d'une adresse partagée, les réponses reviennent à l'utilisateur (`Reply-To`).

### Supprimé

- La variable `MAILER_SENDER`, remplacée par `MAILER_DEFAULT_FROM`.

### Sécurité

- Toute adresse d'expédition qui n'est ni proposée à l'utilisateur ni autorisée pour l'application est refusée (`422`).

## [0.4.0] - 2026-09-24

Les pièces jointes.

### Ajouté

- Pièces jointes dans le composeur et dans le widget, par bouton ou par glisser-déposer. Par défaut : 10 fichiers, 10 Mo par fichier et 25 Mo par email.
- Une application peut joindre un document qu'elle a généré, par exemple un devis PDF : son backend le téléverse, puis la page le passe au widget avec `setDraft({ attachments })`.
- Téléchargement des pièces jointes depuis l'historique des envois.
- Commande `app:attachments:purge` pour supprimer les fichiers jamais envoyés.

### Corrigé

- Les derniers caractères tapés juste avant « Envoyer » pouvaient manquer dans l'email : le composeur lit maintenant le contenu de l'éditeur au moment de l'envoi.

### Sécurité

- Les exécutables et les scripts sont refusés (`.exe`, `.js`, `.bat`, `.ps1`…).
- Une pièce jointe n'est utilisable que par son propriétaire, et dans un seul email.

## [0.3.0] - 2026-09-24

La documentation.

### Ajouté

- Site de documentation (Nuxt UI + Nuxt Content), centré sur l'intégration du composeur embarqué :
  - déclaration de l'application et endpoint de jeton ;
  - widget, pré-remplissage et événements ;
  - exemples Vue, Nuxt et React ;
  - sécurité, protocole `postMessage` et dépannage.
- Pages API (authentification, emails) et administration (utilisateurs et LDAP, templates).

## [0.2.0] - 2026-09-24

Une démo prête à tester.

### Ajouté

- Démo en une commande : comptes locaux et LDAP, templates partagés, Mailpit, et **Démo CRM**, une application tierce qui embarque le composeur.
- Lancement dans **GitHub Codespaces** : ports publics et URLs configurés automatiquement.
- Vérification des scénarios de démo dans la CI.

### Corrigé

- En production, les emails restaient en file d'attente : il manquait le transport Doctrine de Messenger.
- Le proxy `/api` du front ne transmettait pas l'en-tête `Accept` : l'API répondait en JSON-LD au lieu de JSON.

## [0.1.0] - 2026-09-24

Première version.

### Ajouté

- **Utilisateurs** locaux ou **LDAP** (authentification par bind), synchronisation de l'annuaire en ligne de commande ou depuis l'interface, rôle administrateur par groupe LDAP.
- **Applications externes** : un jeton secret `rma_…`, dont seule l'empreinte est stockée, et l'**impersonation** avec `X-Impersonate-User`.
- **Composeur d'email** en texte enrichi (CKEditor 5).
- **Templates d'email** visuels (GrapesJS, preset newsletter) :
  - importables dans le composeur ;
  - privés ou partagés ;
  - **versionnés**, avec historique et restauration.
- **Composeur embarquable** dans une autre application (`embed.js`) :
  - jeton d'embed à durée de vie courte ;
  - origines autorisées (`frame-ancestors`) ;
  - protocole `postMessage` vérifié.
- Traçabilité : toutes les entités sont horodatées et attribuées (Timestampable, Blameable).
- Envoi asynchrone (Symfony Messenger), historique des envois.
- Images Docker publiées sur ghcr.io, CI (lint, tests, build, smoke test des images) et gitflow.

### Sécurité

- Une application n'obtient jamais le rôle administrateur, même en agissant au nom d'un administrateur.
- Un jeton d'embed n'accède qu'aux endpoints du composeur, et il est révoqué dès que l'application est désactivée.

[0.6.0]: https://github.com/fayouz/rocket-mailer/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/fayouz/rocket-mailer/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/fayouz/rocket-mailer/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/fayouz/rocket-mailer/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/fayouz/rocket-mailer/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/fayouz/rocket-mailer/releases/tag/v0.1.0
