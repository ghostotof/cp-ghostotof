# Plan — purge automatique des invitations jamais activées (issue #238)

Design validé par Christophe le 2026-09-22 (session Claude Code, voir l'issue #238 pour le contexte
RGPD). Feature sans spec : ce dossier `tasks/` vit sur `feature/purge-pending-invitations` et sera
archivé sous `.claude/specs/archive/<date>-purge-pending-invitations/tasks/` par la PR de clôture.

## Règle métier

Un compte **en attente d'activation** (`CpgUser::isPendingActivation()` : `invitedAt` non nul,
`activatedAt` nul) dont `invitedAt` est antérieur à *maintenant − 30 jours* est supprimé ; ses jetons
partent avec lui (`PasswordSetupToken`, FK `ON DELETE CASCADE`). `invitedAt` est remis à jour à chaque
renvoi d'invitation (`CpgUserInviter::reinvite` → `markInvited($clock->now())`), donc « 30 jours après
la **dernière** invitation ». Un compte en attente qui porte `ROLE_SUPER` n'est **jamais** purgé
(ignoré, avertissement) : c'est une décision humaine, comme le garde « dernier super-admin » de la
suppression manuelle. Aucune notification à la personne (l'adresse est précisément ce qu'on supprime).

## Global Constraints

- **Langue** : français partout (docblocks, messages, commits, sortie de commande) ; identifiants en
  anglais ; `.claude/CLAUDE.md` en anglais.
- **Commits** : Conventional Commits, un commit par tâche, corps expliquant le pourquoi, et ces deux
  trailers **exactement**, quel que soit le modèle qui écrit (règle du propriétaire du dépôt, qui prime
  sur l'attribution par défaut du harnais) :
  ```
  Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01PtNcxNMDh8dqhorVmqduk9
  ```
  Jamais `[skip ci]`. Ne jamais `git push`. **Ne jamais `git add tasks/` ni `git add -A`** : le
  répertoire `tasks/wizards/` non suivi appartient à une autre branche ; ajouter les fichiers par nom.
- **TDD** : test écrit d'abord, vu échouer pour la bonne raison, puis le code ; preuve RED/GREEN
  (commande + extrait) dans le rapport.
- **Standards du dépôt** : `declare(strict_types=1)`, `final`, `readonly` quand pertinent, types
  partout, PHPStan niveau max + strict-rules **sans** `ignoreErrors` ajouté, Rector dry-run sans diff,
  sortie PHPUnit **pristine** (ni risky, ni warning, ni deprecation). Une exception métier explicite si
  besoin, jamais de `RuntimeException` générique. Doctrine : QueryBuilder, jamais un `findAll()` filtré
  en PHP.
- **Journal d'audit** : jamais d'e-mail, de mot de passe, de jeton, de corps de requête ni d'exception
  sérialisée dans un contexte ; un compte est nommé par `user` (username) + `userId` (RFC 4122).
  `SecurityAuditLoggerTest::testNoContextValueEverCarriesAPasswordATokenOrAnEmail` doit couvrir toute
  nouvelle méthode.
- **Environnement** : checkout principal `/home/ghostotof/dev/cp-ghostotof`, branche
  `feature/purge-pending-invitations`. PHP tourne dans le conteneur :
  `docker compose exec -T -u dev backend php bin/phpunit <chemin>` ·
  `docker compose exec -T -u dev backend composer phpstan` · `… composer rector`.
  Source périmée dans le conteneur (test introuvable, ancien contenu) → `docker compose restart backend`
  d'abord. Après un changement de config : `docker compose exec -T -u dev backend rm -rf var/cache/test`.
- **Rapport** : écrit dans le fichier indiqué par le dispatch, retour court.

---

## Task 1 — Domaine, application, journal d'audit

### Ce qui existe

- `backend/src/Security/User/Domain/Entity/CpgUser.php` : `invitedAt`/`activatedAt` (`?\DateTimeImmutable`,
  colonnes nullables), `isPendingActivation()`, `getRoles()`, `ROLE_SUPER`.
- `backend/src/Security/User/Domain/Repository/CpgUserRepositoryInterface.php` (frontière DIP) et
  `backend/src/Security/User/Infrastructure/Doctrine/CpgUserRepository.php` (`ServiceEntityRepository`,
  `remove()`, `save()`).
- `backend/src/Security/User/Application/CpgUserAdministrator.php` + interface : modèle de forme
  (`final readonly`, constructeur promu, `remove()` puis `auditLogger->userDeleted($user)`).
  `backend/src/Security/User/Application/CpgUserInviter.php` injecte `Psr\Clock\ClockInterface`.
- `backend/src/Security/Authentication/Application/SecurityAuditLoggerInterface.php` (13 méthodes) et
  `backend/src/Security/Authentication/Infrastructure/Log/SecurityAuditLogger.php` (`record(string
  $event, string $message, array $subject = [])`, `account(CpgUser)`, `ANONYMOUS`, acteur lu dans le
  token storage). Tests : `backend/tests/Security/Authentication/Infrastructure/Log/SecurityAuditLoggerTest.php`
  (sentinelles, ligne ~346) ; `backend/tests/Security/User/Application/CpgUserAdministratorTest.php`
  (mocks PHPUnit, `never()` sur les chemins refusés).

### À faire

1. **Repository** — `CpgUserRepositoryInterface::findPendingActivationInvitedBefore(\DateTimeImmutable
   $threshold): array` (`@return list<CpgUser>`), implémentée en **QueryBuilder** :
   `invitedAt IS NOT NULL AND activatedAt IS NULL AND invitedAt < :threshold`, `ORDER BY invitedAt ASC`.
   Test fonctionnel `backend/tests/Security/User/Infrastructure/Doctrine/CpgUserRepositoryTest.php`
   (créer s'il n'existe pas, `KernelTestCase`, table `cpg_user` nettoyée en `tearDown` comme les autres
   tests fonctionnels) : trois comptes — invité il y a 40 j, invité il y a 10 j, invité il y a 40 j
   puis activé — avec `--older-than` 30 j, seul le premier ressort. Les dates se posent par
   `markInvited(new \DateTimeImmutable('-40 days'))` / `markActivated(...)` avant `save()`.
2. **Audit** — `SecurityAuditLoggerInterface::userPurged(CpgUser $user): void` ; dans
   `SecurityAuditLogger` : événement **`user-purged`**, message « Compte en attente d'activation purgé
   (invitation expirée) », contexte `account($user)` + `'reason' => 'invitation-expired'`, et **acteur
   `system`** : ajouter à `record()` un paramètre `?string $actor = null` utilisé à la place de la
   lecture du token storage quand il est fourni (en CLI le token storage donnerait `anonymous`, faux).
   Tests dans `SecurityAuditLoggerTest` : un test dédié (événement, `reason`, `actor === 'system'`,
   `user`/`userId` présents, pas d'e-mail) + **étendre** `testNoContextValueEverCarriesAPasswordATokenOrAnEmail`
   à `userPurged`. Le docblock de classe qui liste les événements gagne `user-purged`.
3. **Cas d'usage** — `backend/src/Security/User/Application/PendingInvitationPurgerInterface.php` et
   `PendingInvitationPurger.php` (`final readonly`, `CpgUserRepositoryInterface`, `ClockInterface`,
   `SecurityAuditLoggerInterface`, `Psr\Log\LoggerInterface`) :
   `purge(\DateInterval $maxAge, bool $dryRun = false): PendingInvitationPurgeResult`.
   `PendingInvitationPurgeResult` (`final readonly`, `backend/src/Security/User/Application/`) porte
   `purged` et `skipped` (`list<string>` de usernames) et `threshold` (`\DateTimeImmutable`).
   Algorithme : `threshold = clock->now()->sub($maxAge)` ; pour chaque compte renvoyé par le repository :
   s'il porte `ROLE_SUPER` → `skipped`, `logger->warning(...)` (username, jamais l'e-mail) ; sinon, si
   `!$dryRun` : `repository->remove($user)` puis `auditLogger->userPurged($user)` ; dans tous les cas
   → `purged` (en dry-run, la liste dit ce qui *serait* purgé). Ordre : `remove()` avant
   `userPurged()`, comme `CpgUserAdministrator::delete`.
   Test unitaire `backend/tests/Security/User/Application/PendingInvitationPurgerTest.php` (mocks,
   horloge fixe — `Symfony\Component\Clock\MockClock`) : nominal (2 comptes → 2 `remove`, 2 `userPurged`,
   seuil = now − 30 j passé au repository) ; dry-run (`never()` sur `remove` et `userPurged`, `purged`
   rempli) ; `ROLE_SUPER` ignoré (`never()` sur `remove`, `warning` appelé, `skipped`) ; liste vide →
   résultat vide, aucune écriture.
4. Vérifier : `php bin/phpunit tests/Security/` vert et pristine, `composer phpstan`, `composer rector`.
   Un commit `feat(security): …`.

### Critères d'acceptation

- Méthode de repository en QueryBuilder, testée sur base réelle (3 cas).
- `user-purged` émis avec `actor: system`, `reason: invitation-expired`, sans e-mail ; sentinelle étendue.
- Purgeur testé sur les 4 cas ; `ROLE_SUPER` jamais supprimé ; dry-run n'écrit rien.
- PHPStan/Rector verts ; aucun autre fichier touché que ceux nommés (+ `services.yaml` seulement si
  l'autowiring d'une interface l'exige — vérifier d'abord que l'autowiring par interface unique suffit,
  comme pour les autres cas d'usage).

---

## Task 2 — Commande `app:user:purge-pending-invitations` et CronJob

### Ce qui existe

- `backend/src/Contact/Presentation/Command/PurgeFailedContactMessagesCommand.php` : modèle exact
  pour `--older-than` (`VALUE_REQUIRED`, défaut `'30 days'`, `new \DateTimeImmutable('-'.$olderThan)`
  gardé par `DateMalformedStringException` → message d'erreur + `Command::INVALID`), et son test
  `backend/tests/Contact/Presentation/Command/PurgeFailedContactMessagesCommandTest.php`
  (`KernelTestCase`, `CommandTester`, exit code 2 sur intervalle invalide).
- `backend/src/Security/User/Presentation/Command/CreateCpgUserCommand.php` (`#[AsCommand(name:
  'app:user:create', …)]`, style du contexte).
- `backend/tests/Support/ReadsSecurityAuditLog.php` : `securityAuditEvents('user-purged')` lit les
  enregistrements du handler de test (kernel non redémarré dans un `KernelTestCase`).
- `k8s/base/messenger-purge-cronjob.yaml` : `sh -c` avec `set -e`, deux commandes ; **le nom du
  CronJob ne change pas** (`apply -k` ne supprime jamais un objet renommé).
- Task 1 a livré `PendingInvitationPurgerInterface::purge(\DateInterval, bool): PendingInvitationPurgeResult`.

### À faire

1. `backend/src/Security/User/Presentation/Command/PurgePendingInvitationsCommand.php` —
   `#[AsCommand(name: 'app:user:purge-pending-invitations', description: 'Supprime les comptes invités
   jamais activés au-delà d\'une durée depuis leur dernière invitation (RGPD, minimisation).')]`.
   Options : `--older-than` (défaut `30 days`, même parsing que la purge du contact, mais convertie en
   `\DateInterval` via `\DateInterval::createFromDateString()` — refuser un résultat vide/faux ou une
   chaîne que `new \DateTimeImmutable('-'.$s)` refuse, exit `Command::INVALID`) et `--dry-run`
   (`VALUE_NONE`). Sortie (SymfonyStyle) : le seuil calculé, la liste des comptes purgés (usernames) ou
   « rien à purger », les comptes ignorés (`ROLE_SUPER`) avec le rappel que c'est une décision
   manuelle ; en dry-run, le titre dit « simulation ». Exit `SUCCESS` même à zéro purge — c'est le cas
   normal de presque chaque exécution du CronJob.
   Docblock de classe : pourquoi (registre RGPD §3.2, minimisation art. 5-1-c), le seuil, le CronJob.
2. Test fonctionnel `backend/tests/Security/User/Presentation/Command/PurgePendingInvitationsCommandTest.php`
   (`KernelTestCase` + `CommandTester`, `use ReadsSecurityAuditLog`) : comptes créés via
   `CpgUserRepositoryInterface::save()` directement (constructeur `CpgUser` + `markInvited(...)`), dont
   un avec un `PasswordSetupToken` persisté ; cas : (a) nominal 40 j → purgé, jeton disparu
   (`SELECT COUNT(*) FROM password_setup_token`), événement `user-purged` lu avec `actor === 'system'`,
   `reason`, `user`, `userId`, et **aucun e-mail dans aucun enregistrement** ; (b) 10 j → conservé ;
   (c) activé → conservé ; (d) `--dry-run` → rien supprimé, sortie liste le compte, aucun événement ;
   (e) `ROLE_SUPER` en attente → conservé, sortie le nomme comme ignoré ; (f) `--older-than
   not-an-interval` → exit 2. Nettoyage `cpg_user` en `tearDown` (cascade sur les jetons).
3. `k8s/base/messenger-purge-cronjob.yaml` : ajouter `php bin/console app:user:purge-pending-invitations
   --no-interaction` entre la purge du contact et `cache:pool:prune`, avec un commentaire d'une ligne
   (#238, 30 j). `kubectl kustomize k8s/overlays/preprod >/dev/null` doit passer.
4. Vérifier : `php bin/phpunit tests/Security/` + `tests/Contact/` verts et pristine ; `composer phpstan` ;
   `composer rector` ; `php bin/console app:user:purge-pending-invitations --dry-run` dans le conteneur
   (base de dev : doit répondre proprement, quel que soit son contenu — consigner la sortie).
   Un commit `feat(security): …`.

### Critères d'acceptation

- Commande listée par `php bin/console list app:user`, dry-run inoffensif, exit 2 sur intervalle invalide.
- Les 6 cas fonctionnels verts ; l'événement d'audit lu depuis le handler de test.
- CronJob : une ligne ajoutée, nom inchangé, kustomize vert.

---

## Task 3 — Documentation

### Ce qui existe

- `docs/rgpd/registre-traitements.md` §3.2, ligne « **Durée de conservation** » (l. ~70) : se termine par
  « **Aucune purge automatique d'une invitation jamais activée n'existe à ce jour** : la revue est
  manuelle, depuis la liste des comptes du backoffice (statut « en attente ») ».
- `.claude/CLAUDE.md` : section `Security/User/` (liste des `Application/` use cases, l. ~250-260) ;
  section `SecurityAuditLogger` (liste des événements, l. ~330) ; section « Seeding »/CronJob non
  concernée. `k8s/base/messenger-purge-cronjob.yaml` est cité dans « Deployment invariants » (ADR 0005).

### À faire

1. Registre §3.2, remplacer la phrase en gras par : « **Invitation jamais activée : 30 jours après la
   dernière invitation**, puis suppression automatique du compte et de ses jetons par
   `app:user:purge-pending-invitations` (CronJob de maintenance quotidien, 03:17, `k8s/base/
   messenger-purge-cronjob.yaml`), tracée dans le journal d'audit (§6, événement `user-purged`, acteur
   `system`, jamais l'adresse). Un compte en attente qui porterait `ROLE_SUPER` n'est pas purgé
   automatiquement : sa suppression reste une décision manuelle. Renvoyer l'invitation depuis le
   backoffice repousse le délai. » Et dans la ligne « Composants concernés » du même tableau, ajouter la
   commande et son cas d'usage (`PendingInvitationPurger`).
   §6 (journal d'audit) : ajouter `user-purged` là où les événements sont énumérés, s'ils le sont.
2. `.claude/CLAUDE.md` : dans la liste des use cases de `Security/User/Application`, ajouter
   `PendingInvitationPurger` (« deletes pending accounts invited more than N days ago — 30 by default —,
   never a `ROLE_SUPER` one ; `app:user:purge-pending-invitations`, daily in the housekeeping CronJob,
   `--dry-run` for a hand run ; issue #238 ») ; dans la liste des événements de `SecurityAuditLogger`,
   ajouter `user-purged` avec la mention `actor: system` (the one event whose actor is not read from
   the token storage — `record()` takes an explicit actor for CLI callers).
3. Un commit `docs(rgpd): …`. Aucun code.

### Critères d'acceptation

- Registre exact et cohérent avec le code (30 j, `ROLE_SUPER` ignoré, `user-purged`, acteur `system`).
- CLAUDE.md : deux insertions, aux bons endroits, en anglais.
