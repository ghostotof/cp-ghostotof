# v0.21.0 — Refus attribuables, 429 nginx en problem+json et livraison plus sûre

Release de consolidation, sans nouvelle fonctionnalité visible. Elle rend attribuables dans le
journal de sécurité les refus qui ne l'étaient pas, retire les adresses e-mail des exceptions
journalisées, fixe le niveau de journalisation de chaque erreur de l'API, met en problem+json les
429 émis par nginx et fait échouer le déploiement tôt si un secret n'est pas synchronisé. Aucune
migration, aucun secret nouveau. Un seul manifeste Kubernetes change : l'annotation qui rend
facultatif le secret Xdebug de préprod.

## Journal de sécurité et données personnelles (#356)

- **Cinq refus deviennent attribuables** dans le canal `security_audit` (IP, chemin canonique,
  jamais le jeton) : `password-setup-token-rejected` (lien inconnu), `password-setup-token-replayed`
  (lien déjà utilisé, avec le compte qu'il activait), `password-setup-throttled`,
  `contact-throttled` et `base-access-throttled`. La réponse ne change pas : rejoué et expiré
  restent le même 410.
- **Consommation atomique du lien de définition de mot de passe.** Deux soumissions simultanées
  du même lien passaient toutes les deux ; la seconde est maintenant refusée et journalisée.
- **Aucune adresse e-mail dans un message d'exception journalisé.** La chaîne d'erreur du
  transport SMTP n'est plus conservée (seules sa classe et un code numérique restent), et
  l'exception « adresse déjà utilisée » ne cite plus l'adresse.
- **Validation stricte des adresses** (`email_validation_mode: strict`) : une adresse que l'envoi
  aurait refusée (`a..b@example.com`) est rejetée en 422 à la saisie, au lieu d'échouer en boucle
  dans le worker.
- **Le traducteur journalise son usage sur `ai_usage`**, comme l'assistant : jetons, durée et
  refus de quota, jamais le contenu.

## Niveaux de journalisation (#348, #355, #357)

- **Chaque exception rendue par l'API a un niveau explicite**, vérifié sur la configuration
  compilée et identique entre environnements. Les refus attendus (quotas, liens inconnus) passent
  en `info`.
- **Un corps de requête illisible** (JSON mal formé, type inattendu) sort en 400 `info`, et non
  plus en `critical`.

## 429 de nginx en problem+json (#347)

- **Toutes les zones de débit nginx** (contact, définition de mot de passe, accès instantané,
  connexion, filet général de l'API et assistant) refusent en `application/problem+json`, type
  `/errors/rate-limited`, au lieu de la page HTML de nginx. Les 429 rendus par Symfony passent
  inchangés, `Retry-After` compris.
- **Limite connue**, suivie dans #368 : ces 429 ne portent pas d'en-têtes CORS, donc un
  navigateur sur une autre origine ne peut pas les lire.
- **Nouveau garde CI `backend-nginx-rate-limits`**, requis avant la release et la prod : il lance
  le nginx du sidecar sur les deux confs, sature chaque zone et vérifie le corps, le type et les
  7 en-têtes de sécurité.

## Livraison (#325)

- **Le déploiement attend que chaque `ExternalSecret` soit synchronisé** avant la migration et le
  rollout, et échoue en nommant le secret et ses clés distantes. Avant, une clé absente de Secret
  Manager ne se voyait qu'après l'expiration du rollout, en `CreateContainerConfigError`.
- Le secret Xdebug de préprod est annoté facultatif : son absence donne un avertissement, pas un
  échec.
- `rollback-preprod` ne se déclenche plus quand le déploiement s'est arrêté avant son rollout.
- **Première exécution réelle sur cette release.**

## Outillage et dépendances

- `CLAUDE.md` découpé en règles par zone du dépôt sous `.claude/rules/`, avec un garde CI (#346).
- `source-map-js` et `postcss-selector-parser` montés de version, ce qui lève un avis npm de
  niveau élevé dans l'outillage du frontend (#367).

## À vérifier en préprod

- Smoke tests et audit verts.
- `deploy-preprod` : l'étape d'attente des `ExternalSecret` passe, et `backend-xdebug-trigger`
  n'y est qu'un avertissement s'il n'est pas synchronisé.
- `kubectl exec … -c nginx -- nginx -T | grep -E 'error_page|_status'` sur le sidecar :
  `error_page 429 = @rate_limited`, `limit_req_status 429` et `limit_conn_status 429` au niveau
  server (critère 2 de #347).
- `POST /api/account/base-access` rejoué au-delà de la zone nginx : 429 en
  `application/problem+json`, type `/errors/rate-limited`.
