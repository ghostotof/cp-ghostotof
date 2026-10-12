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
   *Contrat d'origine, remplacé : le jeton ne voyage plus dans un chemin
   (audit A7) et 404/410 sont fusionnés — voir l'amendement du 2026-10-12
   ci-dessous.*

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
  fasse un `str_contains` sur le `detail` localisé. Depuis #551 (2026-10-12),
  `PUT …/roles` et `DELETE …/users/{id}` peuvent aussi répondre **403**
  `/errors/acting-account-no-longer-super` : l'acteur, relu sous le verrou
  d'administration, n'est plus `ROLE_SUPER` ou n'existe plus (voir
  `.claude/rules/security-user.md`) ; le frontend l'affiche comme une erreur
  générique. `superAdmin` du DTO de rôle
  est `?bool` + `#[Assert\NotNull]` (un corps sans le champ → 422, plus 500). Les
  actions de ligne du tableau `/admin/users` sont regroupées derrière un menu
  « ⋯ » (un seul ouvert à la fois).

### Palier générique retiré au profit d'un octroi nominatif (ADR 0003, 2026-09-12)

Cette section décrivait le risque opérationnel d'un **compte invité générique
partagé** (point d'audit I8), prévu par l'objectif n°9 pour ouvrir `ROLE_USER`
— et donc, à l'époque, le CV — à quiconque en connaîtrait les identifiants. Ce
compte n'a jamais été créé en production.

**ADR 0003 retire ce modèle plutôt que de l'assainir.** `ROLE_USER` redevient
le palier de base (authentifié, non identifiant, jamais suffisant pour le
CV) ; l'accès au CV et à `/api/me` exige désormais `ROLE_TRUSTED`, accordé
**individuellement** par un `ROLE_SUPER` via ce même flux d'invitation
(`CpgUserInviter::invite`), jamais par un identifiant partagé. Il n'y a donc
plus de secret partagé à faire tourner, protéger ou révoquer : l'hygiène
opérationnelle ci-dessus (mot de passe dédié, rotation, jamais `ROLE_SUPER`)
n'a plus d'objet.

Voir `docs/adr/0003-paliers-d-acces.md` pour le modèle complet.

### Un seul refus pour tout lien inutilisable (amendement du 2026-10-12, issue #362)

**Le §4 ci-dessus est périmé sur deux points.** Le jeton ne voyage plus dans
un chemin d'URL (audit A7, T4.1–T4.4) : le lien de l'e-mail est
`/(fr|en)/set-password#<jeton>`, et le backend expose
`POST /api/account/password-setup/validate {token}` et
`POST /api/account/password-setup {token, password}`, tous deux 204. Et le
couple 404 / 410 n'existe plus.

**Constat.** 404 répondait à un jeton inconnu, 410 à un jeton expiré ou déjà
utilisé — fusion voulue pour ne pas dire qu'un lien avait servi. Elle ne tenait
que pendant la validité du lien : un jeton consommé est conservé (il fait
reconnaître un rejeu au journal), alors qu'un compte jamais activé est purgé
à 30 jours avec ses jetons, et qu'une relance remplace le jeton. Passé ce
délai, un 410 voulait donc dire « ce compte a été activé » — et l'e-mail
d'invitation porte l'identifiant de connexion. Le corps le disait aussi : le
`detail` est le message de l'exception, le `type` dérive du statut.

**Décision.** Tout lien qui ne peut pas servir — inconnu, remplacé, purgé,
supprimé avec son compte, expiré, déjà utilisé, ou visant un compte déjà
actif — reçoit **la même réponse** : 404, même `type`, `title` et `detail`.
Une seule exception métier, `UnusablePasswordSetupLinkException`, au message
constant ; ses constructeurs nommés (`unknown()`, `expiredOrAlreadyUsed()`)
ne servent qu'à la lecture du code. La distinction reste là où elle sert
l'exploitation, le journal de sécurité (`password-setup-token-rejected` /
`password-setup-token-replayed`), jamais la réponse.

- **404 plutôt que 410** : un jeton forgé ou tronqué est réellement inconnu,
  alors que 410 affirme que la ressource a existé ; RFC 9110 (§15.5.5) admet
  le 404 pour taire l'existence d'une ressource. 422 était exclu : à la
  soumission, il signifie déjà « mot de passe refusé ».
- **Frontend** : un écran « lien inutilisable » dont le message recouvre
  chaque motif (rouvrir le lien depuis l'e-mail, sinon demander un nouveau
  lien) et propose la connexion à qui a déjà défini son mot de passe ; un
  message distinct « lien incomplet » pour ce que l'URL seule révèle
  (fragment absent, jeton refusé pour sa forme — 422 au `validate`). Le 410
  reste lu comme un lien inutilisable, pour un backend d'avant ce changement
  (déploiement en cours, rollback).
- **Coût accepté** : la personne au lien expiré, cas réel le plus fréquent,
  perd un message ciblé. Écartés : documenter la fuite et la garder ; purger
  les jetons morts au même horizon que les comptes (ne ferme que l'après
  30 jours, et un compte `ROLE_SUPER` en attente, jamais purgé, aurait inversé
  la fuite) ; garder l'empreinte des jetons supprimés pour leur répondre 410
  (une table et une rétention de plus, et la fuite revient à leur terme).
- **Garde-fous** : `AccountPasswordSetupResourceTest::testEveryUnusableLinkGetsTheSameResponse`
  compare statut, en-têtes et corps des sept devenirs d'un lien, sur les deux
  routes ; `UnusablePasswordSetupLinkExceptionTest` épingle le message
  constant. Ne jamais rétablir de statut ni de message propre à l'un des cas.
- **Limite acceptée, le temps de réponse** : les chemins ne font pas tout à
  fait le même travail — un lien inconnu ou rejoué écrit une ligne au journal
  de sécurité, un lien simplement expiré n'en écrit aucune (ce n'est pas un
  événement, choix de #356). Aucun aller-retour SQL ne les sépare (le compte
  est chargé par jointure avec le jeton) : l'écart est celui d'une écriture
  Monolog, sous la gigue du réseau, avec 10 essais par heure et par IP. Ne pas
  l'« égaliser » en journalisant les liens expirés sans revoir ce choix.
- **Fenêtre de déploiement** : un ancien bundle du frontend encore chargé dans
  un navigateur lit le nouveau 404 d'un lien expiré comme « lien invalide,
  rouvrez-le depuis l'e-mail », sans l'inviter à demander un nouveau lien. La
  fenêtre est transitoire, la page rechargée la referme.

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
