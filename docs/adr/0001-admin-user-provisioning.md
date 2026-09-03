# ADR 0001 — Provisionnement d'utilisateurs par le super-admin

- Statut : accepté
- Date : 2026-09-03
- Portée : `src/Security/User`, `src/Security/Authentication`, `templates/emails`, frontend `admin/users` + page publique `set-password`, k8s `backend-config`

## Contexte

Objectif n°9 du projet : rien d'identifiant n'est affiché avant authentification ;
ces informations deviennent visibles une fois connecté. Jusqu'ici, les comptes
`CpgUser` se créaient **exclusivement en CLI** (`app:user:create`) et ne
stockaient **pas d'e-mail** (juste un `username`).

Besoin exprimé : depuis l'espace `ROLE_SUPER`, pouvoir **inviter** une personne
(suite à une demande via le formulaire de contact ou un rendez-vous) à partir de
son adresse e-mail. L'invité reçoit un lien pour **définir lui-même son mot de
passe**. Le super-admin doit aussi pouvoir **promouvoir / rétrograder** et
**supprimer** des comptes. En parallèle, tous les e-mails sortants doivent
reprendre la charte graphique du site.

## Décisions

1. **`CpgUser` gagne une colonne `email`** (nullable, unique) + `invited_at` /
   `activated_at`. L'e-mail n'est exposé que dans les réponses `^/api/backoffice/*`
   (déjà `ROLE_SUPER`), jamais avant authentification : l'objectif n°9 tient.
   Les comptes CLI restent valides (`email = null`).

2. **Voie API d'invitation** : `POST /api/backoffice/users {email, locale}`
   (`ROLE_SUPER`). `CpgUserInviter` dérive un `username` de la partie locale de
   l'e-mail (`UsernameGenerator` : filtrage `[a-z0-9_.-]`, borne 60, complétion
   `user` si < 3, suffixe numérique en cas de collision), crée un compte **sans
   mot de passe** marqué « en attente », génère un `PasswordSetupToken` et
   dispatche l'e-mail (Messenger `async`). La commande CLI reste en place pour
   l'amorçage (notamment le premier `ROLE_SUPER`).

3. **`PasswordSetupToken`** : entité dédiée, **seul le SHA-256** du jeton est
   stocké (jamais le clair), expiration **48 h**, usage unique, FK `CpgUser`
   `ON DELETE CASCADE`, un seul jeton actif par compte.

4. **Parcours public** `/(fr|en)/set-password/:token` (frontend) ↔
   `GET`/`POST /api/account/password-setup/{token}` (backend, **sans
   authentification ni CSRF**, exclu du double-submit comme `/api/contact`).
   `GET` → `{valid:true}` / 404 / 410 ; `POST {password}` → 204 (hache, active le
   compte, consomme le jeton). Rate-limité par IP (10/h, en-tête `Retry-After`).
   Pas d'auto-login après définition : redirection front vers `/login`.

5. **Rôles** : `PUT /api/backoffice/users/{id}/roles {superAdmin: bool}`
   (`CpgUserRoleAdministrator`, idempotent). Gardes : pas ses propres rôles
   (`CannotModifyOwnRolesException`), pas le dernier `ROLE_SUPER`
   (`CannotDemoteLastSuperAdminException`, défensive — non atteignable via l'API
   puisque l'appelant est toujours super).

6. **Renvoi d'invitation** : `POST /api/backoffice/users/{id}/invitation {locale}`
   (régénère le jeton + redispatch). Refusé si le compte n'est pas en attente
   d'activation (`AccountNotAwaitingActivationException`).

7. **E-mails HTML à la charte** : ajout de `symfony/twig-bundle`, **uniquement**
   pour composer les e-mails via `TemplatedEmail` — jamais pour rendre des
   pages, le frontend reste découplé. Layout commun `templates/emails/base.*.twig`
   (bandeau au dégradé de marque, alternative texte). Les chaînes localisées
   sont construites dans les handlers ; les templates sont de pures mises en page.

8. **Config** : `APP_FRONTEND_BASE_URL` (param `app.frontend_base_url`) fournit
   la base des liens des e-mails. `backend/.env` (dev/test/CI) ;
   `configMapGenerator backend-config` des overlays k8s prod/preprod (consommé
   par le backend **et** le worker Messenger, qui envoie réellement).

## Conséquences

- **Revient sur trois invariants documentés** :
  - « account creation stays CLI-only » → une voie API existe (l'invitation) ;
    la CLI reste pour l'amorçage.
  - « no email stored — just a username » → un e-mail est stocké, `ROLE_SUPER`-only,
    jamais exposé avant authentification.
  - « No Twig/AssetMapper » → Twig est présent, cantonné aux e-mails.
- Deux migrations Doctrine (`Version20260903155605`, `Version20260903160900`).
- La partie « dernier super-admin » de `CpgUserRoleAdministrator` est une garde
  défensive, couverte seulement par un test unitaire.
- `skip_null_values: false` sur `BackofficeUserResource` pour que `email: null`
  reste présent dans la réponse (le frontend distingue « sans e-mail » de
  « champ absent »).
- Suite à la revue de code (PR #8) : les deux 409 de `PUT …/roles`
  (auto-modification vs dernier super-admin) portent un `type`
  (`/errors/cannot-modify-own-roles`, `/errors/cannot-demote-last-super`) via
  `ProblemExceptionInterface` + trait `HasProblemType`, au lieu que le frontend
  fasse un `str_contains` sur le `detail` localisé. `superAdmin` du DTO de rôle
  est `?bool` + `#[Assert\NotNull]` (un corps sans le champ → 422, plus 500). Les
  actions de ligne du tableau `/admin/users` sont regroupées derrière un menu
  « ⋯ » (un seul ouvert à la fois).

## Alternatives écartées

- **E-mails en texte brut** (statu quo) : rejeté, demande explicite d'une charte.
- **Composer le HTML en PHP** sans Twig : non maintenable, viole les standards
  du projet.
- **Auto-login** après définition du mot de passe : plus de surface d'attaque
  pour un gain d'UX marginal.
- **Jeton signé sans stockage** (JWS court) : plus difficile à révoquer / à
  rendre à usage unique que l'entité `PasswordSetupToken`.
- **Colonne `locale` sur `CpgUser`** pour mémoriser la langue d'invitation :
  la locale est re-choisie à chaque (ré)envoi depuis le backoffice.
