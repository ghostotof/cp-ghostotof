# Plan — Remédiation de l'audit de sécurité

Source : audit de sécurité « dépôt public, la lecture du dépôt ne doit pas permettre
d'attaquer le site » (session Claude en cours). Numérotation reprise : **C1–C8**
(constats) + **I1–I9** (informatifs).

Git flow : une branche `feature/security-audit-remediation` depuis `develop`, un
commit par tâche, PR unique vers `develop`. Trailers habituels
(`Co-Authored-By` + `Claude-Session`).

Ordre = valeur décroissante. Les phases 6–7 sont explicitement **différables** si le
temps manque : elles ne corrigent que des constats de sévérité basse.

Gates à repasser à chaque checkpoint :
- backend : `composer phpstan` (niveau max, 0 erreur) + `composer rector` (dry-run) + `php bin/phpunit`
- frontend (si touché) : `npm run lint` + `npm run build` + `npm test`
- k8s (si touché) : `kubectl kustomize k8s/overlays/prod` **et** `.../preprod` rendent sans erreur

---

## Décisions structurantes

- **D1 — C1** : la limitation de débit du parcours public *set-password* passe dans un
  listener `kernel.request` (prefix `/api/account/password-setup/`, méthodes GET+POST),
  exécuté **avant** la désérialisation/validation API Platform. Les appels
  `rateLimiter->consume()` sont **retirés** du Provider et du Processor (sinon double
  consommation → quota effectif divisé par deux).
- **D2 — C1** : `#[Assert\NotCompromisedPassword(skipOnError: true)]` sur les deux
  ressources concernées (`AccountPasswordSetupResource`, `BackofficeUserPasswordResource`).
  Compromis assumé : si api.pwnedpasswords.com est indisponible, le contrôle est
  **sauté** (mot de passe accepté) plutôt que de renvoyer 500 — la disponibilité du
  parcours prime sur ce contrôle unitaire, la longueur mini + le hash restent appliqués.
- **D3 — C2** : `SendAccountInvitationMessage` ne transporte plus que
  `{ userId: int, locale: string }`. La création du `PasswordSetupToken` (purge de
  l'ancien + nouveau + envoi) descend dans le **handler Messenger**, dans une
  transaction. Conséquence : un message routé vers le `failure_transport` doctrine ne
  contient plus aucun secret. `CpgUserInviter` ne crée plus de jeton (il crée le
  compte *pending* et publie le message).
- **D4 — C3** : `AboutContentProvider` filtre côté **serveur** : un appelant non
  authentifié reçoit `me.personalCards = []` et `me.hobbiesCards = []` (les
  `technicalCards` restent publiques, comportement identique au masquage actuel de
  `AboutPage.vue`). Le filtre frontend est conservé (défense en profondeur).
- **D5 — C6** : opération `Get('/backoffice/users/{id}')` **explicite** sur
  `BackofficeUserResource` (même provider que `Delete`), ce qui supprime la route
  générée par défaut `GET /api/backoffice_users/{id}`.
- **D6 — I3** : introduction de `Locale::fromString(string): self` levant
  `InvalidLocaleException` (Domain, mappée 404) + helper `uriVariableLocale()` dans le
  trait `ResolvesUriVariables`. Le mapping fourre-tout `ValueError: 404` de
  `api_platform.yaml` est **supprimé**.
- **D7 — C4** : `accessKey` Scaleway passe de `value:` à `secretRef:` (Secret
  bootstrap `scaleway-eso-auth`, nouvelle clé `access-key`). `projectId` reste en clair
  (identifiant de projet, pas un identifiant d'accès — commentaire à jour).
- **D8 — C8** *(différable)* : migrations Doctrine exécutées via un `Job` k8s dédié
  (`delete` + `apply` + `kubectl wait`) au lieu de `kubectl exec` ; le verbe
  `pods/exec: create` est retiré du `Role` `github-actions-deployer`.
- **Non traités (acceptés + documentés)** : I1 (401 vs 404 sur `/api/backoffice/*` :
  renvoyer 404 casserait la sémantique REST et la redirection frontend), I9 (Symfony
  encode déjà l'en-tête `Subject`, pas d'injection).

---

## Phase 0 — Préparation

- [x] 0.1 Créer `feature/security-audit-remediation` depuis `develop` à jour.
- [x] **CHECKPOINT 0** — plan validé, branche créée.

---

## Phase 1 — C1 : limiter le débit avant la validation (parcours public set-password)

**Priorité : haute.** Constat de sévérité moyenne→élevée (disponibilité), trivialement
déclenchable et lisible dans le code.

- [x] 1.1 `App\Security\User\Infrastructure\Http\PasswordSetupRateLimitRequestListener`
  - `#[AsEventListener(event: RequestEvent::class, priority: 15)]` (après le CSRF à 20,
    très en amont du contrôleur API Platform).
  - `isMainRequest()` + `str_starts_with($request->getPathInfo(), '/api/account/password-setup/')`
    + méthode ∈ {GET, POST} → `$this->rateLimiter->consume($request->getClientIp() ?? 'unknown')`.
  - L'exception `PasswordSetupRateLimitExceededException` (→429, déjà mappée) est levée
    ici ; `PasswordSetupRateLimitRetryAfterListener` (kernel.exception→kernel.response)
    continue de poser `Retry-After` sans modification.
- [x] 1.2 Retirer `PasswordSetupRateLimiterInterface` + l'appel `consume()` de
  `AccountPasswordSetupProvider` et `AccountPasswordSetupProcessor` (dépendance et ligne).
- [x] 1.3 `#[Assert\NotCompromisedPassword(skipOnError: true)]` sur
  `AccountPasswordSetupResource` **et** `BackofficeUserPasswordResource`.
- [x] 1.4 Tests :
  - unitaire listener : chemin hors préfixe ignoré ; GET et POST consomment ; au-delà
    du quota → `PasswordSetupRateLimitExceededException`.
  - fonctionnel (`AccountPasswordSetupResourceTest`) : 11ᵉ `POST /api/account/password-setup/<jeton-bidon>`
    dans la fenêtre → **429**, et la réponse arrive **sans** dépendre d'un appel HIBP
    (jeton invalide → le 429 précède la résolution du jeton ; assertion sur le code +
    `Retry-After`). GET soumis au même quota.
  - les tests set-password existants restent verts (le 404/410/204 nominal inchangé).

Critères d'acceptation : le quota IP est consommé avant toute désérialisation/validation ;
une panne HIBP ne renvoie plus 500 sur `/api/account/password-setup` ni sur
`PUT …/users/{id}/password`.

- [x] **CHECKPOINT 1** — gates backend verts.

---

## Phase 2 — C2 : sortir le jeton en clair du message (et donc du failure transport)

**Priorité : haute.** Sévérité moyenne. Refactor contenu au contexte `Security/User`.

- [ ] 2.1 `SendAccountInvitationMessage` → `readonly { public int $userId, public string $locale }`
  (suppression de `recipientEmail`, `username`, `clearToken`).
- [ ] 2.2 `SendAccountInvitationHandler` :
  - charge le `CpgUser` par `userId` ; s'il est absent ou déjà activé
    (`!isPendingActivation()`) → log `warning` + `return` (pas d'exception, pas de retry
    inutile).
  - dans une transaction Doctrine : `passwordSetupTokenRepository->deleteForUser($user)`,
    `$clear = bin2hex(random_bytes(32))`, `save(new PasswordSetupToken($user, hash('sha256',$clear), $now->modify('+48 hours')))`.
  - construit `setupUrl` (inchangé) et envoie le `TemplatedEmail` (inchangé).
  - `ClockInterface` + `CpgUserRepositoryInterface` + `PasswordSetupTokenRepositoryInterface`
    + `EntityManagerInterface` (transaction) injectés.
  - `AccountInvitationDeliveryException` toujours levée sur `TransportExceptionInterface`.
- [ ] 2.3 `CpgUserInviter` :
  - `invite(string $email, Locale $locale): CpgUser` — garde `findOneByEmail` + création
    du compte *pending* (`markInvited($now)`, `save`) + `messageBus->dispatch(new SendAccountInvitationMessage($user->getId(), $locale->value))`.
    **Ne crée plus** de `PasswordSetupToken` ni n'appelle `deleteForUser`.
  - `reinvite(CpgUser $user, Locale $locale): void` — garde `isPendingActivation()` +
    `dispatch(...)` seulement.
  - `issueTokenAndDispatch()` supprimé ; `TOKEN_LIFETIME` déménage dans le handler.
  - `MessageBusInterface` reste ; `PasswordSetupTokenRepositoryInterface` et
    `ClockInterface` peuvent sortir de `CpgUserInviter` s'ils n'y servent plus.
- [ ] 2.4 Tests :
  - `SendAccountInvitationHandlerTest` (nouveau périmètre) : crée un jeton frais + envoie
    l'e-mail ; un 2ᵉ passage (retry) supprime l'ancien jeton et en crée un autre ;
    utilisateur absent/activé → aucun envoi, aucun jeton ; `TransportException` →
    `AccountInvitationDeliveryException`.
  - `CpgUserInviterTest` : n'asserte plus la création de jeton — seulement compte
    *pending* créé + message `{userId, locale}` dispatché ; e-mail déjà pris → 409.
  - `BackofficeUserInvitationResourceTest` / `BackofficeUserInviteResourceTest` : le
    message dispatché porte `userId`+`locale`, plus de `clearToken`.
  - `AccountPasswordSetupResourceTest` (bout-en-bout invite→GET→POST→login) reste vert.

Critères d'acceptation : `php bin/console messenger:failed:show` sur une invitation en
échec ne révèle aucun jeton exploitable ; le parcours invite→e-mail→set-password→login
fonctionne toujours (smoke Mailpit en dev).

- [ ] **CHECKPOINT 2** — gates backend + smoke Mailpit bout-en-bout.

---

## Phase 3 — C3 : filtrage serveur de la section « moi » de /api/about/{locale}

**Priorité : moyenne.** Sévérité basse (latente, objectif n°9).

- [ ] 3.1 `AboutContentProvider` : injecter `Symfony\Bundle\SecurityBundle\Security`.
  `$authenticated = null !== $this->security->getUser();`
  Construire `AboutMeSectionResource` avec `personalCards` et `hobbiesCards` à `[]`
  quand `!$authenticated` (les `technicalCards` et les libellés restent). `/quality`,
  `/stats`, `/experience` inchangés (contenu non identifiant, vérifié).
- [ ] 3.2 Tests fonctionnels (`AboutContent*` / provider) : `GET /api/about/fr` anonyme
  → `me.personalCards == []` && `me.hobbiesCards == []` && `me.technicalCards != []` ;
  authentifié (`ROLE_USER`) → sections complètes. Adapter les tests provider existants.
- [ ] 3.3 (Optionnel, cosmétique) note dans `AboutPage.vue` que le filtre client est
  désormais une défense en profondeur, l'API faisant autorité.

Critères d'acceptation : aucune carte « personnelle » ou « loisir » n'est renvoyée à un
appelant non authentifié, quel que soit le contenu saisi au backoffice.

- [ ] **CHECKPOINT 3** — gates backend + `curl` anonyme sur `/api/about/fr` et `/en`.

---

## Phase 4 — C6 + I3 : nettoyage de la surface API

**Priorité : moyenne.** Sévérités basse / informative.

- [ ] 4.1 **C6** — `BackofficeUserResource` : ajouter
  `new Get(uriTemplate: '/backoffice/users/{id}', provider: <même provider que Delete>)`
  dans `operations`. Vérifier via `php bin/console debug:router` que
  `GET /api/backoffice_users/{id}` a **disparu** et que `GET /api/backoffice/users/{id}`
  existe. Test fonctionnel : lecture item en `ROLE_SUPER` → 200 + DTO attendu ;
  anonyme → 401.
- [ ] 4.2 **I3** — `App\Portfolio\Shared\Domain\ValueObject\Locale::fromString(string $value): self`
  (try/catch `\ValueError` → `InvalidLocaleException`, Domain, dans
  `Portfolio/Shared/Domain/Exception/`). Entrée `InvalidLocaleException: 404` dans
  `api_platform.yaml`, **suppression** de `ValueError: 404`. Ajouter
  `uriVariableLocale(array $uriVariables): Locale` au trait `ResolvesUriVariables` et
  l'utiliser dans tous les providers `Portfolio/*` qui font aujourd'hui
  `Locale::from($this->uriVariableString(...))`. Tests : segment `/api/about/zz` → 404
  (toujours), et un `\ValueError` non lié à la locale n'est plus transformé en 404
  (test unitaire ou revue ciblée).
- [ ] **CHECKPOINT 4** — `debug:router` (diff attendu), gates backend.

---

## Phase 5 — C4 + I2 + I5 + I6 : durcissement k8s / frontend (sans cluster)

**Priorité : moyenne-basse.** Aucune vérification cluster possible ici : on valide le
rendu kustomize + les gates frontend, la bascule réelle est manuelle (README).

- [ ] 5.1 **C4** — `k8s/base/secretstore.yaml` : `accessKey.value` → `accessKey.secretRef`
  (`name: scaleway-eso-auth`, `key: access-key`). Commentaire mis à jour. `projectId`
  reste inline (commentaire : identifiant de projet, pas un secret). Mettre à jour
  `k8s/README.md` : le bootstrap `scaleway-eso-auth` porte désormais **deux** clés
  (`secret-key` + `access-key`).
- [ ] 5.2 **I2** — `CORS_ALLOW_ORIGIN` : `…\.com$` → `…\.com\z` dans
  `k8s/overlays/prod/kustomization.yaml` et `.../preprod/kustomization.yaml`.
  (Le `\n` final toléré par `$` disparaît ; nelmio utilise un PCRE, `\z` valide.)
- [ ] 5.3 **I5** — `docker/node/nginx.conf` : `frame-ancestors 'self'` → `'none'` dans
  la CSP (bloc `server` + `location = /index.html`), `X-Frame-Options "SAMEORIGIN"` →
  `"DENY"` partout. La SPA n'est jamais encadrée (vérifié : aucun `<iframe>` interne).
- [ ] 5.4 **I6** — `securityContext: { seccompProfile: { type: RuntimeDefault } }` au
  niveau pod de `postgres.yaml`, `rabbitmq.yaml`, `adminer.yaml`, `backend-deployment.yaml`,
  `frontend-deployment.yaml`, `messenger-worker-deployment.yaml`, `messenger-purge-cronjob.yaml`.
  (`readOnlyRootFilesystem` **non** ajouté à postgres/rabbitmq : ils écrivent hors
  volume de données — cookie Erlang, `/var/run`, `/tmp`.)
- [ ] 5.5 Vérifications : `kubectl kustomize k8s/overlays/prod` **et** `.../preprod`
  rendent sans erreur ; `npm run lint && npm run build && npm test` (frontend) verts ;
  `tools/audit-prod.sh` : la liste des en-têtes attendus est inchangée (X-Frame-Options
  toujours présent, valeur plus stricte).
- [ ] **CHECKPOINT 5** — rendus kustomize OK, gates frontend OK, README à jour.

---

## Phase 6 — C7 + I4 + I8 : plafonds secondaires + hygiène (différable)

**Priorité : basse.**

- [ ] 6.1 **C7** — `limit_req` nginx sur les endpoints publics coûteux, dans
  `k8s/base/backend-nginx-conf.yaml` **et** `docker/nginx/default.conf` (garder les
  deux synchronisés) :
  - `limit_req_zone $binary_remote_addr zone=contact:1m rate=10r/m;` +
    `zone=pwsetup:1m rate=20r/m;` (hors bloc `server`, donc au niveau `http` — à
    intégrer via la clé `data:` de la ConfigMap qui contient tout le `server {}` :
    ajouter un `map`/`limit_req_zone` **avant** le `server {}` dans la même valeur).
  - `location ^~ /api/contact { limit_req zone=contact burst=5 nodelay; limit_req_status 429; try_files $uri /index.php$is_args$args; }` (et équivalent `/api/account/password-setup/`).
  - Vérif manuelle en dev : `for i in $(seq 1 30); do curl -s -o /dev/null -w '%{http_code}\n' -X POST .../api/contact; done` → apparition de `429`.
  - Note : si l'imbrication `limit_req_zone` dans la ConfigMap s'avère trop lourde,
    replier sur un limiteur Symfony à clé fixe (quota global large, ex. 200/h) sur ces
    deux chemins — même patron que les limiteurs existants.
- [ ] 6.2 **I4** — `backend/.env` : `CONTACT_RECIPIENT_EMAIL=` / `CONTACT_SENDER_EMAIL=`
  laissés **vides** (comme `APP_SECRET`), valeurs dev écrites par
  `docker/php/init-symfony.sh` dans `.env.local`. Vérifier que `make consume` en dev
  après `make init` rend toujours l'e-mail de contact.
- [ ] 6.3 **I8** — documenter dans `CONTEXT.md` (ou l'ADR 0001) : le compte invité
  générique a `ROLE_USER` et accède donc au CV réel et à toute donnée
  `IS_AUTHENTICATED` → une fuite de ses identifiants = exposition complète des données
  personnelles ; recommandation de mot de passe fort + rotation.
- [ ] **CHECKPOINT 6** — gates ; vérif manuelle `limit_req` en dev.

---

## Phase 7 — C8 : migrations via Job k8s, suppression de pods/exec (différable)

**Priorité : basse.** Touche le pipeline (préprod + prod) et le RBAC.

- [ ] 7.1 `k8s/base/migrate-job.yaml` : `Job` `backend-migrate` réutilisant l'image
  `backend`, `command: ['php','bin/console','doctrine:migrations:migrate','--no-interaction']`,
  `envFrom` = `backend-config` + `backend-secrets`, mêmes `securityContext` que le
  Deployment, `backoffLimit: 1`, `ttlSecondsAfterFinished: 600`. **Pas** dans
  `kustomization.yaml` `resources:` (appliqué à la demande par le pipeline, pas à
  chaque `apply -k`).
- [ ] 7.2 `pipeline.yml` `deploy-preprod` et `deploy-prod` : remplacer
  `kubectl exec deploy/backend -c php-fpm -- php bin/console doctrine:migrations:migrate`
  par :
  ```
  kubectl -n <ns> delete job backend-migrate --ignore-not-found
  kubectl -n <ns> apply -f ../../base/migrate-job.yaml
  kubectl -n <ns> wait --for=condition=complete --timeout=180s job/backend-migrate \
    || { kubectl -n <ns> logs job/backend-migrate; exit 1; }
  ```
- [ ] 7.3 `k8s/base/github-actions-rbac/role.yaml` : retirer la règle
  `resources: ['pods/exec'] verbs: ['create']` ; ajouter `apiGroups: ['batch']
  resources: ['jobs'] verbs: ['get','list','watch','create','delete']`. Garder
  `pods`/`pods/log` en lecture pour le diagnostic.
- [ ] 7.4 Vérifs : `kubectl kustomize` des deux overlays OK ; `migrate-job.yaml` valide
  (`kubectl apply --dry-run=client`) ; relire le pipeline (pas de test réel sans
  cluster). Mettre à jour `k8s/README.md` + la mémoire
  `project_networkpolicy_incident_v040` (le rollback ne couvrait déjà pas les
  non-Deployments : le Job y ajoute peu de risque, il est idempotent via delete+apply).
- [ ] **CHECKPOINT 7** — rendus kustomize + dry-run Job + revue pipeline.

---

## Phase 8 — C5 + clôture

- [ ] 8.1 **C5** — `git grep -nI 'b5549e957bd857d7782904c40bf9f625'` sur `HEAD` (doit
  être vide) ; vérifier qu'aucun fichier suivi ne porte un ancien `APP_SECRET`/
  `JWT_PASSPHRASE`. Consigner : les valeurs restent dans l'historique public mais sont
  sans effet (secrets déployés via ESO, keypair JWT jamais commité) ; prescription =
  confirmer côté console Scaleway que `prod-*`/`preprod-*` n'ont jamais réutilisé ces
  valeurs. Excision d'historique (`git filter-repo`) laissée en option explicite,
  non planifiée.
- [ ] 8.2 Mettre à jour la mémoire `project_security_audit.md` : ajouter cette passe
  (numérotation C1–C8 / I1–I9), l'état de traitement point par point, le commit/PR.
- [ ] 8.3 `.claude/CLAUDE.md` : refléter les changements structurants (listener de
  rate-limit set-password ; jeton d'invitation créé dans le handler ; filtre serveur
  About ; `Locale::fromString` + `InvalidLocaleException` ; `Get` explicite backoffice
  users ; `accessKey` ESO en secretRef ; migrations par Job le cas échéant).
- [ ] **CHECKPOINT 8 (final)** — tous les gates verts, PR ouverte vers `develop`, CI verte.

---

## Récapitulatif priorités

| Phase | Constats | Sévérité | Différable |
|------|----------|----------|-----------|
| 1 | C1 | Moyenne→Élevée (dispo) | non |
| 2 | C2 | Moyenne | non |
| 3 | C3 | Basse (latente) | non |
| 4 | C6, I3 | Basse / info | non |
| 5 | C4, I2, I5, I6 | Basse / info | non (peu risqué) |
| 6 | C7, I4, I8 | Basse / info | **oui** |
| 7 | C8 | Basse | **oui** |
| 8 | C5 + docs | Basse / info | non (léger) |

Non traités, acceptés : **I1** (401 vs 404 sur `/api/backoffice/*`), **I9** (en-tête
`Subject` déjà encodé par Symfony).
