# Plan — Provisionnement d'utilisateurs par le super-admin + réorganisation du backoffice

> Périmètre demandé (message utilisateur du 2026-09-03) :
> 1. Dans l'espace `ROLE_SUPER`, **regrouper les liens d'édition de contenu sous un seul onglet** (« Contenu ») ;
>    au même niveau, un onglet **« Utilisateurs »**.
> 2. Page de **gestion des utilisateurs** permettant au super-admin de :
>    - **créer un utilisateur à partir d'une adresse e-mail** (demande reçue via le formulaire de contact
>      ou lors d'un rendez-vous). Username = partie locale de l'e-mail (avant `@`) ; si déjà pris, suffixe
>      incrémental. La création **déclenche un e-mail** contenant un lien permettant à l'utilisateur de
>      **définir lui-même son mot de passe** ;
>    - **promouvoir / rétrograder** un utilisateur (`ROLE_SUPER`) ;
>    - **supprimer** un utilisateur (déjà en place, à conserver).
> 3. L'e-mail de notification du formulaire de contact doit **aussi transmettre l'adresse e-mail de l'expéditeur** (message du 2026-09-03).
> 4. **Créer un template d'e-mail** pour tous les e-mails sortants, reprenant la **charte graphique globale du site** (message du 2026-09-03).

Statut : **EN ATTENTE DE REVUE** — ne pas démarrer l'implémentation avant validation de la Phase 0.

---

## 1. État des lieux (lecture du code au 2026-09-03)

| Élément | État actuel |
|---|---|
| Auth backend | JWT en cookie httpOnly `BEARER`, CSRF double-submit (`XSRF-TOKEN`), `login_throttling`, `stateless`. Solide, **rien à refaire**. |
| Création de compte | **CLI uniquement** (`app:user:create`), aucune route. `CpgUser` ne stocke **pas d'e-mail**. |
| `CpgUser` | `id`, `username` (unique, `USERNAME_PATTERN = [a-zA-Z0-9_.-]{3,60}`), `roles`, `password`. `ROLE_SUPER`, `MIN_PASSWORD_LENGTH = 8`, `MAX_PASSWORD_LENGTH = 4096`. |
| Backoffice users | `GET /api/backoffice/users` (liste), `DELETE /api/backoffice/users/{id}`, `PUT /api/backoffice/users/{id}/password`. Gardes anti-lockout : `CannotDeleteOwnAccountException`, `CannotDeleteLastSuperAdminException` (via `countByRole`). |
| Backoffice nav (front) | `AdminLayout.vue` : **5 liens plats** (`technologies`, `about`, `quality`, `stats`, `users`), routes `/:locale/admin/<section>`. |
| Envoi d'e-mail | Symfony Mailer + Messenger `async` (RabbitMQ). Handler `SendContactMessageHandler` = modèle de référence (aujourd'hui **texte brut**, `->text($body)` ; **pas de Twig dans le projet**). Dev : `MAILER_DSN=null://null`. Test : transport `in-memory://`, `not_compromised_password: false`. |
| Charte graphique | `frontend/src/style.css` + Bootstrap. Fond sombre `rgba(11,10,20,…)`, dégradé de marque `#7c3aed → #4338ca`/`#4f46e5`, `--bs-primary: #7c3aed`, glyphe logo `</>`. **À rejouer dans les e-mails HTML** (contrainte : styles inline, layout `<table>`, fond clair recommandé + bandeau de marque). |
| Rate limiting | `framework.rate_limiter.contact_form` (sliding_window, 5/h, clé = IP) + `SymfonyContactRateLimiter`. Modèle réutilisable pour les endpoints publics. |
| Front admin (par ressource) | `domain/admin/<res>` → `infrastructure/admin/<res>/Http*Repository` → `application/admin/<res>/use*` → `presentation/pages/admin/Admin*Page.vue`. `BackofficeHttpClient` (cookie + CSRF) partagé. |
| i18n | `infrastructure/i18n/locales/{fr,en}.json`, clés `admin.nav.*`, `admin.users.*`, `seo.*`. |
| Docs domaine | Pas de `CONTEXT.md` ni d'ADR aujourd'hui. |

### Conflits assumés avec `.claude/CLAUDE.md` (changements de cap **volontaires** demandés par l'utilisateur)

1. « *account creation stays CLI-only* » → on ajoute une **voie API** (`POST /api/backoffice/users`). La CLI reste (amorçage du 1er `ROLE_SUPER`).
2. « *no email stored — just a username* » → `CpgUser` gagne une colonne **`email`** (nullable, unique). Justification objectif n°9 : l'e-mail n'est visible que par `ROLE_SUPER`, **jamais** exposé avant authentification. À acter dans un ADR + mise à jour de `CLAUDE.md`.

---

## 2. Décisions à valider (Phase 0)

| # | Sujet | Proposition (défaut retenu dans ce plan) | Alternative |
|---|---|---|---|
| D1 | Stockage e-mail | Colonne `email` nullable + `UNIQUE` sur `cpg_user`. | — (bloquant sans e-mail) |
| D2 | Dérivation du username | Partie locale de l'e-mail → filtrage aux caractères `USERNAME_PATTERN`, minuscules. Si `< 3` car. utiles → complète avec `user`. Collision → suffixe `2`, `3`, … (`jean`, `jean2`, `jean3`). | Suffixe aléatoire ; UUID. |
| D3 | Jeton de définition de mot de passe | Nouvelle entité `PasswordSetupToken` : **hash SHA-256** du jeton (jamais le jeton en clair), `expiresAt` (**48 h**), `usedAt` nullable, FK `CpgUser` (1 jeton actif par user, régénérable). | Jeton signé sans stockage (JWS court) — plus dur à révoquer. |
| D4 | Auto-login après définition du mot de passe | **Non** : succès → redirection front vers `/login` avec message. | Émettre le cookie JWT directement (plus de surface d'attaque). |
| D5 | Endpoints publics | `GET /api/account/password-setup/{token}` (validation, pour afficher « lien invalide/expiré » avant saisie) + `POST /api/account/password-setup/{token}` (soumission). Rate-limité par IP. | Un seul `POST` (pas de pré-validation UX). |
| D6 | Réorg nav backoffice | **Regroupement présentationnel** dans `AdminLayout.vue` : onglet « Contenu » (actif sur toute route de contenu) + sous-nav secondaire (Technologies / À propos / Qualité / Stats) ; onglet « Utilisateurs » frère. **Aucune URL ne change** (`/admin/technologies` reste). | Re-nester sous `/admin/content/*` — casse SEO keys + `adminGuard.spec.ts`, plus risqué. |
| D7 | Format des e-mails sortants | **HTML à la charte graphique du site** via `symfony/twig-bundle` + `TemplatedEmail` (demande utilisateur du 2026-09-03). Layout de base commun `templates/emails/base.html.twig` + alternative texte `*.txt.twig`. Twig est ajouté **uniquement pour les e-mails** (pas de rendu serveur de l'app — le front reste découplé). Lien d'invitation : `{APP_FRONTEND_BASE_URL}/{locale}/set-password/{token}`, nouveau param `app.frontend_base_url` + env `APP_FRONTEND_BASE_URL`. | Rester en texte brut (rejeté : demande explicite) ; composer des chaînes HTML en PHP (rejeté : non maintenable, viole les standards projet). |
| D8 | Locale de l'invité | Champ `locale` (`fr`/`en`) sur le formulaire de création, défaut `fr`. Détermine la langue de l'e-mail + du lien. | E-mail bilingue systématique. |
| D9 | Promotion | `PUT /api/backoffice/users/{id}/roles` avec allow-list `{ROLE_SUPER}` (même liste que la CLI). Gardes : `CannotModifyOwnRolesException` (pas soi-même), `CannotDemoteLastSuperAdminException` (via `countByRole`). | Endpoints séparés `promote`/`demote`. |
| D10 | Renvoi d'invitation | `POST /api/backoffice/users/{id}/invitation` = régénère le jeton + renvoie l'e-mail (utile si expiré). | Pas de renvoi (recréer le compte). |
| D11 | Statut de compte | Présenté via `activatedAt` (null = invitation en attente). Colonne `invited_at` + `activated_at` sur `cpg_user`. | Enum `status`. |

**CHECKPOINT 0** — l'utilisateur valide/ajuste D1–D11. Créer la branche `feature/admin-user-provisioning` depuis `develop` (git flow, cf. mémoire projet). Rien n'est codé avant ce point.

---

## 3. Graphe de dépendances

```
                       ┌─────────────────────────────────────────┐
                       │ P0  Décisions + branche feature          │
                       └───────────────┬─────────────────────────┘
             ┌─────────────────────────┼──────────────────────────────┐
             ▼                         ▼                              ▼
   ┌───────────────────┐   ┌──────────────────────────┐   (indépendant du back)
   │ P2 Back: inviter  │   │ P4 Back: rôles + presenter│   ┌────────────────────┐
   │  (CpgUser.email,  │   │  (dépend de P2 pour le   │   │ P1 Front: réorg nav │
   │  UsernameGen,     │   │   presenter email/statut)│   │  AdminLayout        │
   │  PasswordSetup-   │   └───────────┬──────────────┘   └─────────┬──────────┘
   │  Token, e-mail)   │               │                            │
   └─────────┬─────────┘               │                            │
             ▼                         │                            │
   ┌───────────────────┐               │                            │
   │ P3 Back: parcours │               │                            │
   │  public set-passwd│               │                            │
   └─────────┬─────────┘               │                            │
             └──────────────┬──────────┘                            │
                            ▼                                       │
                 ┌──────────────────────┐                           │
                 │ P5 Front: page admin │◀──────────────────────────┘
                 │  Utilisateurs (CRUD  │   (P5 consomme P2+P4 ; P1 fournit l'onglet)
                 │  + promote + resend) │
                 └──────────┬───────────┘
                            ▼
                 ┌──────────────────────┐
                 │ P6 Front: page       │  (P6 consomme P3)
                 │  publique set-password│
                 └──────────┬───────────┘
                            ▼
                 ┌──────────────────────┐
                 │ P7 Env / CI / ADR /  │
                 │  CLAUDE.md / smoke   │
                 └──────────────────────┘
```

- **P1** : Tâche 1.1 (réorg nav) totalement indépendante. Tâches 1.2 (fondation Twig e-mail) + 1.3 (e-mail contact) sont **prérequises** des handlers e-mail de P2 (invitation) et P4 (renvoi d'invitation).
- **P2 → P3** sont séquentiels (P3 consomme les jetons créés par P2). P2 dépend de P1 (Twig).
- **P4** dépend de P2 uniquement pour le presenter (`email`/`status`) ; la logique rôles est autonome.
- **P5** a besoin de P2 + P4 (endpoints invite + roles + presenter) et de P1 (onglet).
- **P6** a besoin de P3.
- **P7** clôt (wiring env, CI, docs, smoke test bout-en-bout).

---

## 4. Découpage vertical (une tranche = un chemin complet, testé)

### Phase 1 — Fondations transverses (indépendantes du parcours d'invitation)

> Tâche 1.1 : front pur, autonome. Tâches 1.2 + 1.3 : fondation e-mail — **prérequis** des handlers e-mail des Phases 2 et 4.

**Tâche 1.1 — Onglet « Contenu » + onglet « Utilisateurs » dans `AdminLayout.vue`**
- Fichiers : `frontend/src/presentation/layout/AdminLayout.vue`, `frontend/src/infrastructure/i18n/locales/{fr,en}.json` (clé `admin.nav.content`), `frontend/tests/presentation/layout/AdminLayout.spec.ts` (nouveau).
- Détail : nav de 1er niveau = `[ Contenu ] [ Utilisateurs ]`. « Contenu » pointe vers `admin-technologies` et est marqué actif (`aria-current="page"`) pour toute route de contenu (`admin-technologies|about|quality|stats`). Quand la route active est une route de contenu, afficher une **2ᵉ `<nav>`** (`aria-label` distinct) avec les 4 sous-liens existants. « Utilisateurs » inchangé.
- **Aucune route ni URL modifiée.** `RouterView` inchangé.
- Critères d'acceptation :
  - Sur `/fr/admin/technologies` : onglet « Contenu » actif, sous-nav visible avec 4 liens, onglet « Utilisateurs » non actif.
  - Sur `/fr/admin/users` : onglet « Utilisateurs » actif, **pas** de sous-nav de contenu.
  - Les 2 `<nav>` ont des `aria-label` différents ; le lien actif porte `aria-current="page"`.
  - `fr.json` et `en.json` ont les mêmes clés (pas de clé orpheline — lint `@intlify`).
- Vérification :
  - `cd frontend && npm run lint && npm run build && npm test` verts.
  - `AdminLayout.spec.ts` : monte le composant avec un router local à 2 routes de contenu + `users`, assert la présence/absence de la sous-nav et l'`aria-current` selon `route.name`.
  - Manuel : `make up`, ouvrir `/fr/admin`, cliquer chaque onglet.

**Tâche 1.2 — Fondation e-mail : Twig + layout de base à la charte graphique du site**
- Contexte : demande utilisateur du 2026-09-03 — « crées un template d'email pour les mails sortant pour qu'ils reprennent la charte graphique globale du site ». Le projet n'a pas Twig ; on l'ajoute **uniquement pour composer les e-mails** (aucun rendu serveur de l'app, le front reste découplé — à préciser dans l'ADR 7.2).
- Fichiers :
  - `backend/composer.json` / `composer.lock` : `composer require symfony/twig-bundle` (Flex activera `config/bundles.php` + `config/packages/twig.yaml`).
  - `backend/config/packages/twig.yaml` : config minimale (`default_path: '%kernel.project_dir%/templates'`, `strict_variables: true` en dev/test). Retirer toute génération liée aux formulaires (form themes) si Flex l'ajoute — inutile ici.
  - `backend/templates/emails/base.html.twig` : layout `<table>` centré, largeur ~600px, **styles inline**, fond de page clair (`#f4f4f7`), carte blanche, **bandeau d'en-tête** au dégradé de marque (`linear-gradient(135deg,#7c3aed,#4338ca)`) avec le nom du site + glyphe `</>`, zone de contenu (`block body`), pied de page discret (mention « Ce site est un projet de démonstration personnel » + lien mentions légales). Bloc `{% block preheader %}` masqué pour l'aperçu client.
  - `backend/templates/emails/base.txt.twig` : équivalent texte (en-tête ASCII simple + `block body`).
  - `backend/src/Shared/Infrastructure/Mail/BrandedEmailFactory.php` (ou globals Twig via `twig.yaml` `globals:`) : centralise nom du site, URL front, couleurs, adresse expéditeur — pour ne pas les répéter dans chaque template/handler.
  - Tests : `backend/tests/Shared/Infrastructure/Mail/BrandedEmailRenderingTest.php` (booter le kernel, `Twig\Environment->render()` d'un template enfant minimal, assert : présence du nom de marque, du dégradé, d'un CTA, échappement correct d'une variable).
- Contraintes e-mail : pas de CSS externe ni `<style>` non-inline fiable, layout `<table>` (pas de fl/grid), images en absolu ou pas d'image (préférer le glyphe texte), tester le rendu dans Mailpit.
- Critères d'acceptation :
  - `symfony/twig-bundle` est une dépendance **de prod** (pas `require-dev`) — les e-mails partent aussi en prod.
  - Un template enfant `{% extends 'emails/base.html.twig' %}` se rend sans erreur, HTML valide, tous styles inline.
  - PHPStan `max` + `phpstan-symfony` restent verts (le container voit `Twig\Environment`) ; Rector `--dry-run` vert ; `templates/` n'est pas scanné par Rector (que du `.twig`).
  - Le cache Twig s'écrit sous `var/` (compatible fs read-only prod, seul `var/` est inscriptible).
- Vérification : `cd backend && composer phpstan && composer rector && php bin/phpunit --filter BrandedEmailRendering` ; `bin/console debug:container twig` résout ; rendu visuel dans Mailpit (Tâche 1.3).

**Tâche 1.3 — E-mail de notification du formulaire de contact : template HTML + e-mail de l'expéditeur dans le corps**
- Contexte : (a) demande du 2026-09-03 — « il faut que l'adresse mail de l'expéditeur du message via formulaire soit transmise également » ; aujourd'hui l'e-mail de l'expéditeur n'est qu'en `Reply-To`, le nom que dans le sujet, le corps ne contient que le message. (b) passage à la charte graphique (Tâche 1.2).
- Fichiers :
  - `backend/src/Contact/Infrastructure/Messenger/SendContactMessageHandler.php` : `Email` → `Symfony\Bridge\Twig\Mime\TemplatedEmail`, `->htmlTemplate('emails/contact_notification.html.twig')` + `->textTemplate('emails/contact_notification.txt.twig')` + `->context(['senderName' => …, 'senderEmail' => …, 'body' => …])`. `from`/`to`/`replyTo`/`subject` **inchangés**.
  - `backend/templates/emails/contact_notification.html.twig` + `.txt.twig` (`extends` base) : bloc « De : {{ senderName }} &lt;{{ senderEmail }}&gt; » bien visible, puis le message (`nl2br` + `escape`).
  - `backend/tests/Contact/Infrastructure/Messenger/SendContactMessageHandlerTest.php` : mis à jour.
- Critères d'acceptation :
  - Le rendu HTML **et** le rendu texte contiennent `senderName` ET `senderEmail` en clair, en plus du message.
  - `from` = adresse du domaine, `replyTo` = expéditeur, `subject` inchangé (assertions existantes toujours vertes).
  - Aucune donnée supplémentaire (IP, en-têtes techniques) dans le corps (cf. politique de confidentialité affichée).
  - Le message de l'expéditeur est échappé (pas d'injection HTML via le corps du formulaire).
- Vérification : `cd backend && php bin/phpunit --filter SendContactMessageHandler` ; `composer phpstan && composer rector` ; rendu visuel Mailpit (`MAILER_DSN=smtp://localhost:1025`).

**CHECKPOINT 1** — revue visuelle `/admin` (fr + en) ; e-mail de contact rendu dans Mailpit (charte + expéditeur visible). Les Tâches 1.1 + 1.3 peuvent partir en PR séparée (`feature/admin-nav-grouping`), mais 1.2 est un prérequis des Phases 2/4 — préférer tout garder sur `feature/admin-user-provisioning`.

---

### Phase 2 — Backend : inviter un utilisateur (DB → domaine → application → API → e-mail)

**Tâche 2.1 — `CpgUser` : e-mail + horodatages d'invitation/activation**
- Fichiers : `backend/src/Security/User/Domain/Entity/CpgUser.php`, nouvelle migration `backend/migrations/VersionXXXX.php`, `backend/tests/Security/User/Domain/Entity/CpgUserTest.php`.
- Détail :
  - `#[ORM\Column(length: 180, unique: true, nullable: true)] private ?string $email = null;` + `#[Assert\Email]` + `#[ORM\UniqueConstraint(name: 'uniq_cpg_user_email', columns: ['email'])]`.
  - `?\DateTimeImmutable $invitedAt`, `?\DateTimeImmutable $activatedAt` (nullable).
  - Méthodes : `getEmail()/setEmail()`, `getInvitedAt()`, `markInvited(\DateTimeImmutable)`, `markActivated(\DateTimeImmutable)`, `isPendingActivation(): bool` (= `null === activatedAt`).
  - Constructeur inchangé (username + hash) — l'e-mail est posé par l'inviter.
- Critères : PHPStan `max` vert ; migration `up()`/`down()` symétriques ; un `CpgUser` créé par la CLI a `email = null`, `activatedAt = null` (pas de régression `CreateCpgUserCommandTest`).
- Vérification : `make sh` → `php bin/console doctrine:migrations:migrate --no-interaction` puis `--down` ; `composer phpstan` ; `php bin/phpunit --filter CpgUserTest`.

**Tâche 2.2 — `UsernameGenerator` (service domaine)**
- Fichiers : `backend/src/Security/User/Domain/Service/UsernameGenerator.php`, `backend/tests/Security/User/Domain/Service/UsernameGeneratorTest.php`.
- Signature : `generateFromEmail(string $email): string`. Dépend de `CpgUserRepositoryInterface::findOneByUsername`.
- Règles : partie locale (`substr` avant `@`) → `strtolower` → suppression des caractères hors `[a-z0-9_.-]` → troncature à 60 → si longueur `< 3`, préfixer/compléter par `user`. Collision → suffixe numérique à partir de `2`, en respectant la borne 60 (tronquer la base si besoin).
- Critères / cas de test :
  - `jean.dupont@example.com` libre → `jean.dupont`.
  - `jean.dupont@…` pris → `jean.dupont2` ; `jean.dupont2` aussi pris → `jean.dupont3`.
  - `a+b@x.fr` → `ab` puis complété → `abuser`? **NON** : `ab` fait 2 car. → `ab` + complétion → `abus`… ⇒ règle testée : `< 3` ⇒ append `user` → `abuser` (6 car.). Documenter la règle exacte dans le test.
  - `CAPS@x.fr` → `caps`.
  - Base de 60 car. + collision → base tronquée pour loger le suffixe, résultat ≤ 60 et unique.
- Vérification : `php bin/phpunit --filter UsernameGeneratorTest` ; PHPStan.

**Tâche 2.3 — `PasswordSetupToken` : entité + repository**
- Fichiers :
  - `backend/src/Security/User/Domain/Entity/PasswordSetupToken.php`
  - `backend/src/Security/User/Domain/Repository/PasswordSetupTokenRepositoryInterface.php`
  - `backend/src/Security/User/Infrastructure/Doctrine/PasswordSetupTokenRepository.php`
  - migration `VersionXXXX.php`
  - `backend/tests/Security/User/Infrastructure/Doctrine/PasswordSetupTokenRepositoryTest.php`
- Entité : `id`, `#[ORM\ManyToOne(CpgUser)] $user` (`onDelete: CASCADE`), `string $tokenHash` (unique, SHA-256 hex = 64 car.), `\DateTimeImmutable $expiresAt`, `?\DateTimeImmutable $usedAt`. Méthodes : `isUsable(\DateTimeImmutable $now): bool` (`null === usedAt && $now < expiresAt`), `markUsed(\DateTimeImmutable)`.
- Repo interface : `save`, `remove`, `findOneByTokenHash(string): ?PasswordSetupToken`, `deleteForUser(CpgUser): void` (purge des jetons précédents avant régénération).
- Critères : suppression d'un `CpgUser` ⇒ jetons supprimés (CASCADE, testé) ; `findOneByTokenHash` insensible aux jetons d'autres users.
- Vérification : `php bin/phpunit --filter PasswordSetupTokenRepositoryTest` (test d'intégration Doctrine, base `_test`).

**Tâche 2.4 — Application : `CpgUserInviter` + message + handler e-mail**
- Fichiers :
  - `backend/src/Security/User/Application/CpgUserInviterInterface.php` + `CpgUserInviter.php`
  - `backend/src/Security/User/Application/Message/SendAccountInvitationMessage.php`
  - `backend/src/Security/User/Infrastructure/Messenger/SendAccountInvitationHandler.php`
  - `backend/src/Security/User/Domain/Exception/EmailAlreadyUsedException.php`
  - `backend/config/packages/messenger.yaml` (routing du nouveau message vers `async`)
  - tests : `backend/tests/Security/User/Application/CpgUserInviterTest.php`, `backend/tests/Security/User/Infrastructure/Messenger/SendAccountInvitationHandlerTest.php`
- `CpgUserInviter::invite(string $email, Locale $locale): CpgUser` :
  1. rejet si `findOneByUsername`… non : rejet si e-mail déjà utilisé (`repo->findOneByEmail`, **nouvelle méthode** à ajouter à `CpgUserRepositoryInterface` + impl) → `EmailAlreadyUsedException`.
  2. `username = UsernameGenerator::generateFromEmail($email)`.
  3. `new CpgUser($username, '')` ; `setEmail`, `markInvited(now)` ; `roles = []` ; `save`.
  4. jeton : `bin2hex(random_bytes(32))` (clair, jamais persisté) ; `PasswordSetupToken(user, hash('sha256', $clear), now+48h)` ; `tokenRepo->deleteForUser($user)` puis `save`.
  5. `messageBus->dispatch(new SendAccountInvitationMessage($user->getId(), $email, $clear, $locale->value))`.
  6. retourne le `CpgUser`.
- Handler : construit un `TemplatedEmail` **à la charte** (`extends 'emails/base.html.twig'`, cf. Tâche 1.2) — `->htmlTemplate('emails/account_invitation.html.twig')` + `->textTemplate('emails/account_invitation.txt.twig')` + `->context(['username' => …, 'setupUrl' => "{APP_FRONTEND_BASE_URL}/{locale}/set-password/{token}", 'expiresAt' => …, 'locale' => …])`. `from` = adresse du domaine (`app.contact_sender_email` réutilisé, ou nouveau `app.account_sender_email` = même valeur), `to` = e-mail de l'invité, sujet localisé (fr/en, 2 chaînes dans le handler). Template : titre + phrase d'accueil + **bouton CTA** « Définir mon mot de passe » (lien absolu) + mention d'expiration (48 h) + repli lien texte. `catch TransportExceptionInterface` → nouvelle `AccountInvitationDeliveryException` (mappée 503). En test : transport `in-memory://` ⇒ assertions sur `htmlTemplate`/`textTemplate`/`context` (pas sur le HTML rendu, non rendu tant que non envoyé).
- Critères :
  - `invite()` crée 1 user (username dérivé, `email` posé, `invitedAt` non null, `activatedAt` null, `roles == []`), 1 jeton (hash only, `expiresAt ≈ now+48h`), dispatch 1 message.
  - E-mail déjà utilisé → `EmailAlreadyUsedException`, aucun user créé.
  - Handler : `TemplatedEmail` avec `htmlTemplate` + `textTemplate` `emails/account_invitation.*`, `context['setupUrl']` = `…/fr/set-password/<64 hex>`, `to` = invité, `from` = adresse du domaine (jamais l'e-mail invité). Un test de **rendu** (kernel booté) vérifie que le HTML final reprend le bandeau de marque et contient le CTA.
- Vérification : `php bin/phpunit --filter 'CpgUserInviterTest|SendAccountInvitationHandlerTest'` ; `composer phpstan` ; `composer rector`.

**Tâche 2.5 — API : `POST /api/backoffice/users` (invitation)**
- Fichiers :
  - `backend/src/Security/User/Presentation/ApiResource/BackofficeUserResource.php` (ajout de l'opération `Post`)
  - `backend/src/Security/User/Presentation/ApiResource/BackofficeUserInviteInput.php` (DTO d'entrée `{email, locale}` avec `#[Assert\Email]`, `#[Assert\Choice(['fr','en'])]`) — ou champs sur la ressource, au choix cohérent avec le reste du backoffice
  - `backend/src/Security/User/Infrastructure/ApiPlatform/BackofficeUserInviteProcessor.php`
  - `backend/config/packages/api_platform.yaml` (`exception_to_status`: `EmailAlreadyUsedException: 409`, `AccountInvitationDeliveryException: 503` si créée)
  - test : `backend/tests/Security/User/Presentation/ApiResource/BackofficeUserInviteResourceTest.php`
- `Post` : `uriTemplate: '/backoffice/users'`, `status: 201`, `processor: BackofficeUserInviteProcessor`, `provider` non requis (création). Réponse = ressource présentée (`id`, `username`, `email`, `roles`, `status`).
- Processor : appelle `CpgUserInviter::invite`, mappe vers `BackofficeUserResource`.
- Critères / cas de test fonctionnels (`ApiTestCase`, `HttpJson::jsonBody`) :
  - `POST` авторisé `ROLE_SUPER` avec `{email:"jean.dupont@ex.com", locale:"fr"}` → `201`, corps `username == "jean.dupont"`, `status == "pending"` ; 1 message `SendAccountInvitationMessage` dans le transport `in-memory`.
  - Anonyme → `401` (access_control `^/api/backoffice`).
  - `ROLE_USER` simple → `403`.
  - E-mail déjà utilisé → `409`.
  - E-mail invalide / locale absente → `422`.
- Vérification : `php bin/phpunit backend/tests/Security/User/Presentation` ; `composer phpstan && composer rector`.

**CHECKPOINT 2** — `cd backend && composer phpstan && composer rector && php bin/phpunit` **tout vert**. Manuel : `make up` + `make consume` (ou Mailpit via `MAILER_DSN=smtp://localhost:1025`), `curl` авторisé (cookie `BEARER` d'un `ROLE_SUPER`) sur `POST /api/backoffice/users`, vérifier l'e-mail rendu et le lien.

---

### Phase 3 — Backend : parcours public de définition du mot de passe

**Tâche 3.1 — `PasswordSetupService`**
- Fichiers :
  - `backend/src/Security/User/Application/PasswordSetupServiceInterface.php` + `PasswordSetupService.php`
  - `backend/src/Security/User/Domain/Exception/InvalidPasswordSetupTokenException.php` (jeton inconnu)
  - `backend/src/Security/User/Domain/Exception/PasswordSetupTokenExpiredException.php` (expiré ou déjà utilisé)
  - test : `backend/tests/Security/User/Application/PasswordSetupServiceTest.php`
- `validate(string $clearToken): void` — hash, `findOneByTokenHash`, `isUsable(now)` sinon exception adéquate.
- `complete(string $clearToken, string $plainPassword): void` — idem + `passwordHasher->hashPassword` + `user->setPassword` + `user->markActivated(now)` + `token->markUsed(now)` + `save` (user & token). Longueur `[MIN,MAX]` validée en amont par le DTO ; garde défensive ici aussi.
- Critères / cas :
  - jeton valide → `validate()` ne lève rien ; `complete()` pose le hash, `activatedAt` non null, `usedAt` non null.
  - jeton inconnu → `InvalidPasswordSetupTokenException`.
  - jeton expiré → `PasswordSetupTokenExpiredException`.
  - jeton déjà utilisé → `PasswordSetupTokenExpiredException` (réutilisation impossible).
- Vérification : `php bin/phpunit --filter PasswordSetupServiceTest`.

**Tâche 3.2 — API publique `GET`/`POST /api/account/password-setup/{token}` + rate limiting**
- Fichiers :
  - `backend/src/Security/User/Presentation/ApiResource/AccountPasswordSetupResource.php` (DTO `{password}` avec `#[Assert\Length(min: CpgUser::MIN_PASSWORD_LENGTH, max: CpgUser::MAX_PASSWORD_LENGTH)]` + `#[Assert\NotCompromisedPassword]`)
  - `backend/src/Security/User/Infrastructure/ApiPlatform/AccountPasswordSetupProvider.php` (`Get` : renvoie `{valid: true}` ou lève l'exception → 404/410)
  - `backend/src/Security/User/Infrastructure/ApiPlatform/AccountPasswordSetupProcessor.php` (`Post` : `complete()`, `status: 204`, `output: false`)
  - `backend/config/packages/rate_limiter.yaml` (`account_password_setup`: sliding_window, ex. 10/h, clé IP)
  - `backend/src/Security/User/Infrastructure/Http/AccountPasswordSetupRateLimitListener.php` **ou** un `RateLimiter` injecté dans le provider/processor (calquer `SymfonyContactRateLimiter` + `ContactRateLimitRetryAfterListener`)
  - `backend/config/packages/api_platform.yaml` : `InvalidPasswordSetupTokenException: 404`, `PasswordSetupTokenExpiredException: 410`
  - test : `backend/tests/Security/User/Presentation/ApiResource/AccountPasswordSetupResourceTest.php`
- **Accès** : le chemin `^/api/account` n'est couvert par **aucune** entrée `access_control` ⇒ public sous le firewall `api` (`stateless`, `jwt: ~` mais non requis). Vérifier explicitement dans le test qu'un appel **anonyme** aboutit.
- Critères / cas fonctionnels :
  - `GET .../{token valide}` anonyme → `200 {valid:true}`.
  - `GET .../{token inconnu}` → `404`.
  - `GET .../{token expiré}` → `410`.
  - `POST .../{token valide}` `{password: "<8+ non compromis>"}` → `204` ; `GET /api/me` avec les identifiants (username dérivé + ce mot de passe) via `login_check` → `200` (le compte est utilisable).
  - `POST` re-joué avec le même jeton → `410`.
  - `POST` mot de passe `< 8` → `422`.
  - Au-delà de la limite → `429` avec `Retry-After`.
- Vérification : `php bin/phpunit backend/tests/Security/User` ; `composer phpstan && composer rector`.

**CHECKPOINT 3** — gate backend complet vert. Manuel bout-en-bout : invitation (Phase 2) → récupérer le lien dans Mailpit → `GET` puis `POST` le jeton → `login_check` avec le nouveau mot de passe.

---

### Phase 4 — Backend : promotion / rétrogradation + presenter enrichi + renvoi d'invitation

**Tâche 4.1 — `CpgUserRoleAdministrator`**
- Fichiers :
  - `backend/src/Security/User/Application/CpgUserRoleAdministratorInterface.php` + impl
  - `backend/src/Security/User/Domain/Exception/CannotModifyOwnRolesException.php`
  - `backend/src/Security/User/Domain/Exception/CannotDemoteLastSuperAdminException.php`
  - test : `backend/tests/Security/User/Application/CpgUserRoleAdministratorTest.php`
- `setSuperAdmin(int $id, bool $grant, CpgUser $actingUser): void` :
  - `id === actingUser->getId()` → `CannotModifyOwnRolesException`.
  - user introuvable → `CpgUserNotFoundException`.
  - retrait alors que `countByRole(ROLE_SUPER) <= 1` et l'utilisateur est ce dernier → `CannotDemoteLastSuperAdminException`.
  - sinon `setRoles([ROLE_SUPER])` ou `setRoles([])` (allow-list ; `ROLE_USER` reste implicite via `getRoles()`).
- Critères / cas : promotion OK ; rétrogradation OK ; soi-même → 409 ; dernier super → 409 ; id inconnu → 404.
- Vérification : `php bin/phpunit --filter CpgUserRoleAdministratorTest`.

**Tâche 4.2 — API `PUT /api/backoffice/users/{id}/roles`**
- Fichiers : `backend/src/Security/User/Presentation/ApiResource/BackofficeUserRoleResource.php` (DTO `{superAdmin: bool}`, `output: false`, `status: 204`), provider (résout le user, 404 sinon — **`Put` nécessite un `provider` explicite**, cf. `CLAUDE.md`), processor, `exception_to_status` (`CannotModifyOwnRolesException: 409`, `CannotDemoteLastSuperAdminException: 409`).
- Test fonctionnel : promotion `204` + `GET /backoffice/users` reflète `ROLE_SUPER` ; rétrograder le dernier super → `409` ; se rétrograder soi-même → `409` ; anonyme → `401`.
- Vérification : `php bin/phpunit backend/tests/Security/User/Presentation` ; PHPStan / Rector.

**Tâche 4.3 — Presenter : `email` + `status`**
- Fichiers : `backend/src/Security/User/Application/CpgUserAdminPresenter.php`, `backend/src/Security/User/Presentation/ApiResource/BackofficeUserResource.php`, `backend/src/Security/User/Infrastructure/ApiPlatform/BackofficeUserProvider.php`, tests impactés (`BackofficeUserResourceTest`, `CpgUserAdminPresenter` si testé).
- `present()` ajoute `'email' => $user->getEmail()`, `'status' => $user->isPendingActivation() ? 'pending' : 'active'`. `BackofficeUserResource` : `?string $email`, `string $status`.
- Critères : liste `GET /backoffice/users` renvoie `email` (null pour comptes CLI) + `status` ; tests existants mis à jour, verts.
- Vérification : `php bin/phpunit backend/tests/Security/User`.

**Tâche 4.4 — API `POST /api/backoffice/users/{id}/invitation` (renvoi)**
- Fichiers : opération `Post` dédiée (ressource `BackofficeUserResource` ou `BackofficeUserInvitationResource`), processor appelant un nouveau `CpgUserInviter::reinvite(CpgUser): void` (régénère jeton + dispatch), provider explicite (404), test fonctionnel.
- Critères : `204`/`202` ; nouveau message dispatché ; sur un compte déjà activé → `409` (`AccountAlreadyActivatedException`, mappée) **ou** autorisé selon D10 (défaut : refuser si déjà activé).
- Vérification : `php bin/phpunit backend/tests/Security/User/Presentation`.

**CHECKPOINT 4** — gate backend complet vert (`composer phpstan && composer rector && php bin/phpunit`). API figée pour le front.

---

### Phase 5 — Frontend : page d'administration des utilisateurs

**Tâche 5.1 — Domaine + infrastructure `admin/users`**
- Fichiers :
  - `frontend/src/domain/admin/users/entities/AdminUser.ts` (+ `email: string | null`, `status: 'pending' | 'active'`)
  - `frontend/src/domain/admin/users/repositories/AdminUserRepository.ts` (+ `invite(email, locale)`, `setSuperAdmin(id, grant)`, `resendInvitation(id)`)
  - `frontend/src/domain/admin/users/errors/AdminUserError.ts` (+ reasons `email-taken`, `cannot-modify-own-roles`, `cannot-demote-last-super`, `already-activated`)
  - `frontend/src/infrastructure/admin/users/HttpAdminUserRepository.ts` (implémente les 3 appels via `BackofficeHttpClient.mutate`, mapping statut → `AdminUserError`)
  - `frontend/tests/infrastructure/admin/users/HttpAdminUserRepository.spec.ts` (étendu)
- Critères / cas de test :
  - `invite('a@b.com','fr')` → `POST /api/backoffice/users` corps `{email,locale}` ; `201` → `AdminUser` retourné.
  - `409` sur invite → `AdminUserError('email-taken')`.
  - `setSuperAdmin(3, true)` → `PUT /api/backoffice/users/3/roles` `{superAdmin:true}` ; `409` → `cannot-modify-own-roles` / `cannot-demote-last-super` selon `detail`.
  - `resendInvitation(3)` → `POST /api/backoffice/users/3/invitation`.
  - CSRF : header `X-XSRF-TOKEN` présent (déjà couvert par `BackofficeHttpClient`, vérifier via mock).
- Vérification : `cd frontend && npm test -- HttpAdminUserRepository`.

**Tâche 5.2 — `useAdminUsers` expose invite / setSuperAdmin / resendInvitation**
- Fichiers : `frontend/src/application/admin/users/useAdminUsers.ts`, `frontend/tests/application/admin/users/useAdminUsers.spec.ts`.
- Détail : réutiliser `runAction` (recharge la liste après invite / setSuperAdmin / resend). `errorMessage` pour l'affichage local.
- Critères : après `invite`, `load()` est rappelé ; une erreur `AdminUserError` remonte dans `errorMessage` sans casser la liste.
- Vérification : `npm test -- useAdminUsers`.

**Tâche 5.3 — `AdminUsersPage.vue` : formulaire de création + promote/demote + resend + statut**
- Fichiers : `frontend/src/presentation/pages/admin/AdminUsersPage.vue`, `frontend/src/infrastructure/i18n/locales/{fr,en}.json`, `frontend/tests/presentation/pages/admin/AdminUsersPage.spec.ts`.
- UI :
  - **Formulaire « Inviter un utilisateur »** : `BaseTextInput` (e-mail, `type="email"`) + `BaseSelect` (langue fr/en, défaut fr) + bouton. Succès → message « Invitation envoyée à `<username>` » + reset. Erreur `email-taken` → message dédié.
  - **Colonne « Statut »** : `pending` (badge) / `active`.
  - **Bouton Promouvoir / Rétrograder** (`setSuperAdmin`), désactivé sur sa propre ligne (comparaison `username` via `useAuth`), libellé selon que la ligne a `ROLE_SUPER`.
  - **Bouton « Renvoyer l'invitation »** visible seulement si `status === 'pending'`.
  - Conserver : changement de mot de passe direct, suppression (inchangés).
- i18n (fr + en, mêmes clés) : `admin.users.invite.{title,emailLabel,localeLabel,submit,success,alreadyExists}`, `admin.users.{promote,demote,statusPending,statusActive,resendInvitation,resendSuccess}`, `admin.users.errors.{email-taken,cannot-modify-own-roles,cannot-demote-last-super,already-activated}`.
- Critères / cas de test :
  - Soumission du formulaire avec un e-mail → appelle `invite` avec `{email, locale}` ; message de succès affiché avec le username renvoyé.
  - `email-taken` → message d'erreur, la liste n'est pas cassée.
  - Bouton promote sur une autre ligne → `setSuperAdmin(id, true)` ; sur sa propre ligne → bouton `disabled`.
  - `resendInvitation` visible uniquement pour les lignes `pending`.
  - `fr.json`/`en.json` synchronisés (lint `@intlify`).
- Vérification : `cd frontend && npm run lint && npm run build && npm test`.

**CHECKPOINT 5** — gate frontend complet vert. Revue visuelle `/fr/admin/users` : inviter un utilisateur fictif, vérifier l'appel réseau (DevTools), promouvoir/rétrograder, renvoyer.

---

### Phase 6 — Frontend : page publique de définition du mot de passe

**Tâche 6.1 — Domaine + infrastructure `account`**
- Fichiers :
  - `frontend/src/domain/account/repositories/AccountRepository.ts` (`validateSetupToken(token): Promise<void>`, `completePasswordSetup(token, password): Promise<void>`)
  - `frontend/src/domain/account/errors/PasswordSetupLinkError.ts` (`reason: 'invalid' | 'expired' | 'weak-password' | 'rate-limited' | 'unknown'`)
  - `frontend/src/infrastructure/account/HttpAccountRepository.ts` (`GET`/`POST /api/account/password-setup/{token}`, `credentials: 'include'` non nécessaire — endpoint public — mais sans effet ; mapping `404→invalid`, `410→expired`, `422→weak-password`, `429→rate-limited`)
  - `frontend/tests/infrastructure/account/HttpAccountRepository.spec.ts`
- Critères / cas : `validate` sur `200` → resolve ; `410` → `PasswordSetupLinkError('expired')`. `complete` `204` → resolve ; `422` → `weak-password`.
- Vérification : `npm test -- HttpAccountRepository`.

**Tâche 6.2 — `useAccountPasswordSetup` + wiring `main.ts`**
- Fichiers : `frontend/src/application/account/useAccountPasswordSetup.ts` (+ `InjectionKey` `ACCOUNT_REPOSITORY`), `frontend/src/main.ts` (`app.provide(ACCOUNT_REPOSITORY, new HttpAccountRepository(apiUrl))`), `frontend/tests/application/account/useAccountPasswordSetup.spec.ts`.
- Expose : `state` (`checking | ready | invalid | expired | submitting | done | error`), `validate(token)`, `submit(token, password)`.
- Vérification : `npm test -- useAccountPasswordSetup`.

**Tâche 6.3 — `SetPasswordPage.vue` + route publique + SEO noindex**
- Fichiers :
  - `frontend/src/presentation/pages/SetPasswordPage.vue`
  - `frontend/src/presentation/router/index.ts` (route `path: 'set-password/:token'`, `name: 'set-password'`, **sans** `requiresAuth`, sous `/:locale(fr|en)`, `meta: { titleKey:'seo.setPassword.title', descriptionKey:'seo.setPassword.description', noindex: true }`)
  - `frontend/src/presentation/router/seo.ts` (gérer `meta.noindex` → `<meta name="robots" content="noindex, nofollow">`, comme la 404)
  - `frontend/src/infrastructure/i18n/locales/{fr,en}.json` (`account.setPassword.*`, `seo.setPassword.*`)
  - `frontend/tests/presentation/pages/SetPasswordPage.spec.ts`, `frontend/tests/presentation/router/*` (route publique + noindex)
- UI : au montage → `validate(token)`. États : chargement / lien invalide / lien expiré (avec invite à recontacter) / formulaire (mot de passe + confirmation, règle « ≥ 8 caractères ») / succès (lien vers `/login`). `role="alert"` sur les états d'erreur.
- Critères / cas :
  - Montée avec un token que le mock déclare valide → formulaire affiché.
  - Token expiré → message dédié, pas de formulaire.
  - Soumission OK → écran de succès + lien login.
  - Mot de passe trop court → message, pas d'appel réseau (garde front) ; `weak-password` renvoyé par l'API → message.
  - La route est accessible **sans** authentification (garde router ne la bloque pas) et le `<meta robots>` vaut `noindex, nofollow`.
- Vérification : `cd frontend && npm run lint && npm run build && npm test`.

**CHECKPOINT 6** — gate frontend complet vert. **Démo bout-en-bout** : `/fr/admin/users` → inviter → Mailpit → ouvrir le lien `/fr/set-password/<token>` → définir le mot de passe → `/fr/login` → connexion réussie.

---

### Phase 7 — Wiring env / CI / documentation / smoke test

**Tâche 7.1 — Configuration `APP_FRONTEND_BASE_URL`**
- Fichiers : `backend/.env` (clé documentée, valeur dev `http://localhost:5173`), `backend/config/services.yaml` (`parameters: app.frontend_base_url: '%env(APP_FRONTEND_BASE_URL)%'`), `docker/php/init-symfony.sh` (défaut fresh-clone dans `.env.local`/`.env.test.local`), `.github/workflows/pipeline.yml` (job `test-backend` : ajouter la variable au `.env.test.local` généré).
- Critères : `php bin/phpunit` en CI ne casse pas sur un paramètre manquant ; `bin/console debug:container --parameter=app.frontend_base_url` résout.
- Vérification : relancer `test-backend` en CI (ou localement en simulant `.env.test.local`).

**Tâche 7.2 — Vérifier l'empaquetage des templates Twig dans l'image de prod**
- Fichiers : `docker/php/Dockerfile` (stage `vendor` / `production`), éventuellement `.dockerignore`.
- Détail : confirmer que `backend/templates/` est bien copié dans le stage `production` (le stage copie tout `backend/`, donc a priori OK — vérifier qu'aucune règle `.dockerignore` n'exclut `templates/`) et que `var/` reste inscriptible pour le cache Twig. `composer install --no-dev` du stage `vendor` doit inclure `symfony/twig-bundle` (dépendance de prod, cf. Tâche 1.2).
- Critères : `make build-prod` réussit ; `docker run … php bin/console cache:warmup --env=prod` ne se plaint pas de templates manquants ni d'un cache non inscriptible.
- Vérification : `make build-prod` ; smoke `docker run` du warmup.

**Tâche 7.3 — ADR + mise à jour `CLAUDE.md`**
- Fichiers : `docs/adr/0001-admin-user-provisioning.md` (nouveau — crée le dossier `docs/adr/`), `.claude/CLAUDE.md`.
- ADR : contexte (objectif n°9 + demande d'invitation par e-mail + charte graphique des e-mails), décision (voie API `POST /backoffice/users`, stockage `email`, jeton `PasswordSetupToken`, **Twig ajouté pour les e-mails HTML uniquement**, `TemplatedEmail` + layout de marque), conséquences (revient sur « CLI-only », « no email stored » et « No Twig » ; l'e-mail reste `ROLE_SUPER`-only donc l'objectif n°9 tient ; Twig ne sert jamais à rendre l'app), alternatives écartées (e-mails texte brut, auto-login, jeton signé sans stockage, HTML composé en PHP).
- `CLAUDE.md` : section `Security/User/` (colonne `email`, `PasswordSetupToken`, `CpgUserInviter`, endpoints publics `/api/account/password-setup/*`), section Backoffice (nouveaux resources `BackofficeUser` `Post`/roles/invitation ; nav regroupée), section Architecture (nuancer « No Twig/AssetMapper » → « Twig présent uniquement pour composer les e-mails via `TemplatedEmail`, templates dans `backend/templates/emails/` »), corriger les mentions « CLI-only » / « no email stored ».
- Vérification : relecture ; `grep -n "CLI-only\|no email\|No Twig" .claude/CLAUDE.md` ne renvoie plus d'affirmation obsolète non nuancée.

**Tâche 7.4 — Smoke test bout-en-bout + amorçage super-admin**
- Détail : `make up` + worker (`make consume`) + `MAILER_DSN=smtp://localhost:1025` (Mailpit) ; créer un `ROLE_SUPER` via `app:user:create --role ROLE_SUPER` (inchangé) ; parcours complet invitation → e-mail (charte + CTA) → set-password → login → promotion → suppression ; vérifier aussi l'e-mail de contact (charte + expéditeur visible).
- Critères : aucun 500 ; e-mails reçus dans Mailpit à la charte, avec le bon lien localisé ; le nouvel utilisateur peut se connecter ; la promotion se reflète dans `GET /api/me` (`roles`).
- Vérification : check-list manuelle cochée ; `make` gates complets : `cd backend && composer phpstan && composer rector && php bin/phpunit` + `cd frontend && npm run lint && npm run build && npm test`.

**Tâche 7.3 — Smoke test bout-en-bout + amorçage super-admin**
- Détail : `make up` + worker (`make consume`) + `MAILER_DSN=smtp://localhost:1025` (Mailpit) ; créer un `ROLE_SUPER` via `app:user:create --role ROLE_SUPER` (inchangé) ; parcours complet invitation → e-mail → set-password → login → promotion → suppression.
- Critères : aucun 500 ; e-mail reçu dans Mailpit avec le bon lien localisé ; le nouvel utilisateur peut se connecter ; la promotion se reflète dans `GET /api/me` (`roles`).
- Vérification : check-list manuelle cochée ; `make` gates complets : `cd backend && composer phpstan && composer rector && php bin/phpunit` + `cd frontend && npm run lint && npm run build && npm test`.

**CHECKPOINT 7 (final)** — branche `feature/admin-user-provisioning` prête. Ouvrir la PR vers `develop` (git flow). Résumé PR : les 2 renversements de décision + lien ADR.

---

## 5. Stratégie de test (rappel politique projet)

- **Backend** : chaque service de domaine/application → cas nominal + limite + erreur + exceptions (mémoire `politique de tests`). Régressions : un test qui échoue avant, passe après.
- **Fonctionnels API** : `ApiTestCase`, corps via `App\Tests\Support\HttpJson::jsonBody()`, transport Messenger `in-memory` pour asserter les dispatch. Couvrir explicitement l'**access control** (anonyme, `ROLE_USER`, `ROLE_SUPER`) sur chaque nouvelle route.
- **Frontend** : Vitest, specs au chemin miroir sous `tests/`, repository stub injecté par `InjectionKey`, router/i18n **locaux** par test.
- **PHPStan `max` + strict-rules** et **Rector `--dry-run`** doivent rester verts sur `src/` **et** `tests/` — pas de baseline.
- **Lint front** `@intlify/vue-i18n` : toute nouvelle clé dans `fr.json` **et** `en.json`.

## 6. Risques / points d'attention

| Risque | Mitigation |
|---|---|
| Ordre des règles `access_control` | Ne **rien** insérer avant `^/api/backoffice`. `^/api/account/*` doit rester public (aucune règle) — test anonyme obligatoire. |
| Fuite d'e-mail avant auth | `email` n'apparaît **que** dans les réponses `^/api/backoffice/*` (déjà `ROLE_SUPER`). Aucun autre presenter public ne doit exposer `getEmail()`. Test : `GET /api/me` ne contient pas `email`. |
| Énumération de comptes via l'e-mail d'invitation | Endpoint d'invitation réservé `ROLE_SUPER` → pas de surface publique. Le `GET password-setup` renvoie `404`/`410` sans révéler d'identité. |
| Jeton en clair loggé | Ne jamais journaliser le jeton clair ; ne persister que le SHA-256 ; `deleteForUser` avant régénération. |
| Rate-limit du parcours public | `account_password_setup` par IP + `Retry-After` (calquer Contact). |
| E-mail non délivré (TEM) | `failure_transport` Messenger déjà en place ; exception transport → 503 mappé ; bouton « renvoyer l'invitation » (Tâche 4.4). |
| `MIN_PASSWORD_LENGTH` dupliqué | Réutiliser la constante `CpgUser::MIN_PASSWORD_LENGTH` / `MAX_PASSWORD_LENGTH` partout (DTO, front = simple garde UX). |
| Changement d'URL des routes admin | **Écarté** (D6) : regroupement purement présentationnel, zéro route touchée. |
| CI `test-backend` écrit son propre `.env.test.local` | Tâche 7.1 : ajouter `APP_FRONTEND_BASE_URL` au writer du pipeline, sinon `php bin/phpunit` casse en CI. |
| Ajout de Twig (le projet le proscrivait) | Ajouté **uniquement pour les e-mails** (`TemplatedEmail`), jamais pour servir des pages — le front reste découplé. Acter dans l'ADR 7.2 + `CLAUDE.md` (nuancer « No Twig/AssetMapper »). |
| Rendu e-mail incohérent selon les clients | Layout `<table>`, **styles inline** uniquement, fond clair + bandeau de marque, pas d'image distante, glyphe texte `</>`. Vérif visuelle Mailpit ; alternative `text/plain` fournie systématiquement. |
| Cache Twig en prod (fs read-only) | `twig.cache` sous `var/` (seul répertoire inscriptible du stage `production`). Vérifier `docker/php/Dockerfile` : `templates/` est bien copié (le stage copie tout `backend/`), et `var/` reste un volume/inscriptible. |
| Échappement dans les templates e-mail | `strict_variables: true` + auto-escape Twig ; le corps du formulaire de contact et le `username` sont échappés (test d'injection HTML). |

## 7. Exécution en mode automatique

Le plan est découpé pour `agent-skills:build` : chaque tâche a des fichiers cibles, des critères d'acceptation et une commande de vérification. Les **CHECKPOINTS 0 à 7** sont les points d'arrêt humains (revue visuelle / validation de décisions / démo). En mode « auto », s'arrêter à chaque checkpoint.
