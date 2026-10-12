# v0.24.0 — Files, verrous et journaux durcis

Release de durcissement, suite de v0.23.1. Elle authentifie le cache en base, borne la taille des messages
Messenger jusqu'au broker, rend visibles les pannes de RabbitMQ, regroupe les verrous PostgreSQL sous un
squelette commun, borne les attentes de verrou de toute la console et retire l'identifiant de connexion des
journaux. **Deux migrations**, déploiement standard **sans fenêtre de maintenance**. **Aucun secret
nouveau**, aucun droit RBAC nouveau. **RabbitMQ redémarre une fois** (nouvelle configuration).

## Comportements qui changent

| Cas | Avant | Après | Issue |
|---|---|---|---|
| Lien de définition de mot de passe inutilisable (inconnu, expiré, déjà utilisé, révoqué, compte purgé) | 404 ou 410 selon le cas | **toujours 404**, corps et en-têtes identiques. Changement de contrat de l'API publique | #362 |
| Page set-password | deux écrans « invalide » / « expiré » | un écran commun avec un lien « Se connecter », plus un écran « lien incomplet » pour un lien tronqué | #362 |
| Deux invitations simultanées dont l'adresse a la même partie locale | la seconde : 409 « adresse déjà utilisée », à tort | la seconde reçoit un identifiant libre (`jean2`) | #364 |
| `detail` des 409 du backoffice (comptes) | citait l'identifiant | message constant ; statut et `type` inchangés | #363 |
| Contact ou invitation pendant un redémarrage de RabbitMQ | message perdu en silence, réponse 2xx | **503** `/errors/message-queue-unavailable` (`warning`), une invitation non partie ne laisse aucun compte en attente | #479, #485, #491 |
| Message de plus de 1 Mio publié dans le broker | accepté jusqu'à 16 Mio | refusé par RabbitMQ (`max_message_size`) ; une enveloppe de plus de 256 Kio refusée avant son MAC | #469, #477 |
| Valeur de `cache_items` (limiteurs, signal d'arrêt du worker) | désérialisée sans contrôle | HMAC vérifié avant toute désérialisation ; une valeur refusée vaut un cache manquant (`warning`) | #468 |
| `PUT` d'un contenu ordonné supprimé avant la prise du verrou | 200 sur une ligne disparue | 404 | #402 |
| Groupe de traduction sur deux positions | invisible, réparé sans trace au réordonnancement suivant | refusé par la base au COMMIT (trigger différé, SQLSTATE `23T90`), sous le nom `TranslationGroupHasSeveralPositionsException` | #390 |
| Attente d'un verrou PostgreSQL en console (Jobs, CronJobs, worker, **migrations**) | illimitée | **5 s** (`PGOPTIONS`), comme FPM | #512, #525 |
| Handler Messenger qui attend un verrou plus de 5 s | bloquait le worker | `55P03`, réessayé puis `failed` | #525 |
| Job lancé par `run-job.sh` en échec mais encore actif | continuait à tourner | arrêté avant que le script rende la main (jusqu'à 90 s de plus) | #535 |
| `activeDeadlineSeconds` des Jobs | 240 s (sous l'attente de 300 s : journaux perdus à l'échéance) | 360 s, au-dessus de l'attente, partout | #525, #535 |
| Arrêt des workers (rotation d'`APP_SECRET`) | `messenger:stop-workers`, `[OK]` même sans écriture | `app:messenger:stop-workers`, qui relit le signal et sort en 1 s'il n'est pas écrit | #528 |

## Journaux (#363)

- `security_audit` : un compte n'est plus désigné que par son **UUID**. `.context.user` disparaît pour les
  comptes (il ne porte plus que le marqueur `guest-…` du palier de base) et `actor` vaut l'UUID. Les filtres
  `jq` sur `.context.user` passent à `.context.userId` (runbook à jour).
- `ai_usage` : `account` devient `userId`.
- Canal `security` de Symfony : le jeton s'écrit `…(userId="…", roles="…")` (`SecurityChannelAccountRedactor`).
- Nouvelle ligne possible : `error` « ROLLBACK refusé après un échec sous verrou PostgreSQL » (classes et
  SQLSTATE seulement), si la base coupe pendant une écriture sous verrou (#526).

## Migrations

- `Version20261011140000` (#468) : vide `cache_items` une fois. **Les compteurs des limiteurs repartent de
  zéro**, `login_throttling` compris, puis à chaque alternance d'ancien et de nouveau pod pendant le rollout.
  Des `warning` « Failed to fetch » du canal `cache` sont attendus **pendant le rollout seulement**.
- `Version20261012120000` (#390) : trigger différé sur les huit tables de contenus localisés. Les groupes
  déjà divergents sont signalés en `warning` puis renumérotés ; un groupe à cheval sur deux catégories est
  laissé et signalé, à corriger au backoffice. **Lire les `warning` du Job de migration.**
- Les migrations sont désormais bornées à 5 s d'attente de verrou : **un déploiement peut échouer sur un
  bloqueur passager** (55P03). L'ancienne release continue de servir : relancer le job de déploiement.

## RabbitMQ (#477, #479)

- Nouvelle configuration `rabbitmq.conf` (`max_message_size = 1048576`) : **le broker redémarre une fois**
  (`Recreate`), courte coupure du contact et des invitations, qui répondent 503 pendant ce temps.
- Publications confirmées par le broker (`confirm_timeout: 5`) et attentes AMQP bornées à 5 s.

## Exploitation et documentation

- Quotas IA (traduction, assistant de parcours) indexés par UUID : **remis à zéro une fois** (#363).
- `smoke-test-preprod` envoie un message témoin de l'API au worker (#470), environ une minute de plus.
- Nouveaux Jobs hors kustomize : `messenger-failed-retry`, `messenger-failed-remove`,
  `messenger-stop-workers` (#521) ; `messenger:failed:show` se lit par un `exec` humain en lecture seule.
- `k8s/README.md` : « nommer un compte » par un `exec` durci en lecture seule (#520), section « File d'échec
  Messenger » (#521), portée exacte de « jamais `kubectl exec` ».
- `run-job.sh` commun à tous les Jobs (#488) : journaux imprimés aussi après un succès.
- `composer dump-env prod` dans l'image (#489), sous `env -i`.
- `make back-style-fix` en dev (#398).

## À vérifier en préprod

- Smoke tests (dont le message témoin) et audit verts.
- `kubectl -n preprod rollout status deployment/rabbitmq` puis journaux (« Server startup complete ») ;
  `rabbitmqctl eval "application:get_env(rabbit, max_message_size)."` → `{ok,1048576}` (#477).
- Journaux du Job de migration : `warning` de `Version20261012120000` lus.
- `LOG_LEVEL=debug` toujours actif en préprod (#489).
- Validations des issues #520, #521, #525 et #535 (commandes en commentaire de chaque issue).

## À vérifier en prod

- `rollout status` de `rabbitmq`, `worker` et `backend`, puis journaux du broker.
- Journaux du Job de migration : `warning` de `Version20261012120000`.
- Les `warning` « Failed to fetch » du canal `cache` cessent après le rollout.
- Un message de contact passe : reçu, aucune ligne `failed`.

## Retour arrière

- **`backend` et `worker` ensemble**, toujours.
- Un rollback juste après cette release : l'ancien worker ne connaît pas `MessengerProbeMessage`, un témoin
  non consommé finit dans `failed`, à retirer par le Job `messenger-failed-remove` (#470).
- Broker cassé par la nouvelle configuration : `kubectl -n <ns> rollout undo deployment/rabbitmq`.
- Le Job d'arrêt des workers lance `app:messenger:stop-workers`, absente des images antérieures : lancer
  son manifeste depuis un checkout de la release qui tourne (#528).
