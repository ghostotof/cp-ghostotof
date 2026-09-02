# Déploiement Kubernetes (Scaleway Kapsule)

Manifests Kustomize : `base/` (commun) + `overlays/{preprod,prod}/` (namespace,
domaine, réplicas, config). Le pipeline GitHub Actions (`.github/workflows/pipeline.yml`)
applique ces overlays sur tag Git — voir les commentaires de ce fichier pour le détail
des jobs.

## Prérequis cluster (une fois, hors CI)

1. **Créer le cluster Kapsule** (console Scaleway ou `scw k8s cluster create`),
   un seul cluster pour préprod + prod (séparées par namespace).
2. **Installer ingress-nginx** (Helm) : expose les Ingress `k8s/base/ingress.yaml`.
3. **Installer cert-manager** + un `ClusterIssuer` nommé `letsencrypt` (référencé
   par l'annotation `cert-manager.io/cluster-issuer` de l'Ingress) : gère les
   certificats TLS Let's Encrypt automatiquement.
4. **ServiceAccount dédié au pipeline GitHub Actions** (remplace le tunnel GitLab
   Agent for Kubernetes) : manifests sous `k8s/base/github-actions-rbac/`
   (`ServiceAccount` + `Role`/`RoleBinding` namespaced, scope limité aux kinds
   gérés par `kubectl apply -k` ; `ClusterRole`/`ClusterRoleBinding` étroits,
   restreints par `resourceNames` aux deux namespaces `preprod`/`prod` et au
   `ClusterIssuer` `letsencrypt` — pas de `cluster-admin`). Délibérément
   **hors** de `k8s/base/kustomization.yaml` (pas réappliqué par le pipeline
   à chaque déploiement — sinon le SA devrait pouvoir se lire/gérer
   lui-même, un privilège qu'on ne lui donne pas). Appliqué une fois par
   namespace, via une kustomization jetable (le champ `namespace:` doit être
   posé par kustomize pour que les subjects des RoleBinding soient
   correctement qualifiés — un simple `kubectl apply -n` ne suffit pas) :
   ```bash
   for NS in preprod prod; do
     TMP=$(mktemp -d)
     cp k8s/base/github-actions-rbac/*.yaml "$TMP/"
     (cd "$TMP" && kustomize edit set namespace "$NS" && kubectl apply -k .)
     rm -rf "$TMP"
   done
   ```
   Puis construire un kubeconfig autonome à partir du token durable
   (`Secret` `github-actions-deployer-token`, type `kubernetes.io/service-account-token`)
   et du endpoint/CA du cluster (`kubectl config view --raw`), et le stocker
   comme secret GitHub Actions (`KUBE_CONFIG_PREPROD` / `KUBE_CONFIG_PROD`,
   Settings > Secrets and variables > Actions) — jamais commité.
5. **Installer External Secrets Operator** (Helm) : synchronise les Secrets
   Kubernetes depuis **Scaleway Secret Manager** (région `fr-par` — hébergement
   France garanti), au lieu d'un `kubectl create secret` manuel non versionné.
   ```
   helm repo add external-secrets https://charts.external-secrets.io
   helm upgrade --install external-secrets external-secrets/external-secrets \
     --namespace external-secrets --create-namespace
   ```
6. **Créer les namespaces** — fait automatiquement par `kubectl apply -k`
   (`namespace.yaml` est dans les resources de chaque overlay), pas besoin de
   le faire à la main.

## Secrets — Scaleway Secret Manager, jamais dans git

`k8s/base/secretstore.yaml` (un `SecretStore` ESO, partagé par les deux
namespaces) et `k8s/overlays/{preprod,prod}/external-secrets.yaml` (5
`ExternalSecret` chacun) sont **déjà commités** : ils déclarent *comment*
chaque Secret Kubernetes (`backend-secrets`, `postgres-credentials`,
`rabbitmq-credentials`, `jwt-keys`, `cv-pdf`) doit être rempli depuis Scaleway
Secret Manager, mais pas les valeurs elles-mêmes. `kubectl apply -k` suffit
donc désormais à redéployer un environnement complet — reste seulement à
alimenter Scaleway Secret Manager, une fois, à la main.

### 1. Clé d'API Scaleway dédiée à ESO (principe du moindre privilège)

Créer une **IAM Application** dédiée (pas votre clé de compte principale),
avec une policy limitée à `SecretManagerReadOnly` sur le projet concerné,
puis générer une clé API pour cette application (console Scaleway : IAM >
Applications). Reporter les deux valeurs obtenues :

```bash
# projectId + accessKey : pas des secrets (identifiants), à écrire en clair
# dans k8s/base/secretstore.yaml (remplacer les CHANGE_ME_*).

# secretKey : SEULE valeur sensible, jamais en git — un Secret bootstrap par
# namespace :
for NS in preprod prod; do
  kubectl create secret generic scaleway-eso-auth -n $NS \
    --from-literal=secret-key="<SCALEWAY_SECRET_KEY>"
done
```

### 1bis. Packages GHCR — publics, pull anonyme (pas de bootstrap requis)

Les 2 packages (`cp-ghostotof-backend`, `cp-ghostotof-frontend`) sont rendus
**publics** sur github.com (Settings du package > Change visibility >
Public — impossible à automatiser via API/CLI, bascule manuelle unique
faite une fois le premier tag construit). Le pull d'image ne nécessite donc
aucun `imagePullSecrets` ni PAT : les Deployments backend/frontend
(`k8s/base/{backend,frontend}-deployment.yaml`) n'en référencent plus.

Si les packages redeviennent privés un jour, un Secret Kubernetes de type
`docker-registry` (PAT scope `read:packages`, même logique que
`scaleway-eso-auth` ci-dessus) redevient nécessaire :

```bash
for NS in preprod prod; do
  kubectl create secret docker-registry ghcr-registry -n $NS \
    --docker-server=ghcr.io \
    --docker-username=ghostotof \
    --docker-password=<PAT>
done
```
— et il faudrait alors réajouter `imagePullSecrets: [ghcr-registry]` dans
les deux Deployments.

### 1ter. CV (troisième et dernier bootstrap manuel)

Le CV (`backend/resources/private/cv/cv.pdf`, jamais commité, cf.
`backend/resources/README.md`) n'est **pas** géré par ESO : Scaleway Secret
Manager plafonne une version de secret à 64 Ko, très en-dessous de la taille
réelle du fichier (~416 Ko brut, ~555 Ko en base64 — testé en conditions
réelles, la création de la version échoue avec `'data' is wrongly formatted`
/ `Must be between 1 and 65535 bytes long`). Le Secret Kubernetes `cv-pdf`
est donc créé directement, sans passer par Secret Manager — toujours hébergé
en France puisqu'il vit dans le cluster Kapsule (`fr-par`), seule la brique
Secret Manager n'est pas utilisable pour ce fichier précis. Le volume est
monté en `optional: true` (`k8s/base/backend-deployment.yaml`) : son absence
ne bloque que `GET /api/cv`, pas le reste de l'application.

```bash
for NS in preprod prod; do
  kubectl create secret generic cv-pdf -n $NS \
    --from-file=cv.pdf=/chemin/vers/cv.pdf
done
# Puis, si le Deployment backend tournait déjà sans ce secret (volume
# optional vide au démarrage) :
kubectl rollout restart deployment/backend -n preprod
kubectl rollout restart deployment/backend -n prod
```

### 2. Alimenter Scaleway Secret Manager

Un secret Scaleway = une valeur (pas de JSON multi-clés, cf. commentaire dans
`external-secrets.yaml`). Nommage : `<preprod|prod>-<nom>`, exactement ce que
référence `remoteRef.key` dans chaque `ExternalSecret`. Exemple via `scw`
CLI (vérifier la syntaxe exacte avec `scw secret secret create --help`, elle
évolue) — à répéter pour `preprod-*` et `prod-*` avec des valeurs
**différentes** :

```bash
scw secret secret create name=preprod-postgres-db path=/ region=fr-par
scw secret version create secret-id=<id> data="cp_ghostotof" region=fr-par
# ... idem pour : preprod-postgres-user, preprod-postgres-password,
# preprod-rabbitmq-user, preprod-rabbitmq-password, preprod-backend-app-secret,
# preprod-backend-database-url (postgresql://app:<PASSWORD>@database:5432/cp_ghostotof?serverVersion=18&charset=utf8),
# preprod-backend-messenger-dsn (amqp://app:<PASSWORD>@rabbitmq:5672/%2f/messages),
# preprod-backend-jwt-passphrase, preprod-backend-mailer-dsn,
# preprod-backend-contact-email
```

**Clés JWT** — générées en LOCAL (jamais sur le cluster ni en clair ailleurs
qu'ici), une paire par environnement (préprod et prod ne doivent PAS partager
la même paire) :
```bash
make sh
php bin/console lexik:jwt:generate-keypair --skip-if-exists
# puis uploader le CONTENU des .pem (texte brut, pas de base64) dans
# preprod-jwt-private-pem / preprod-jwt-public-pem
```

Une fois toutes les valeurs présentes dans Scaleway Secret Manager, ESO les
synchronise automatiquement dans le cluster (`refreshInterval: 1h` sur chaque
`ExternalSecret`) — pas d'action supplémentaire côté `kubectl apply -k`.

## Rotation / mise à jour d'un Secret

Mettre à jour la valeur dans Scaleway Secret Manager (nouvelle version) : ESO
la resynchronise sous 1h maximum. Pour forcer immédiatement :
`kubectl annotate externalsecret <nom> -n $NS force-sync=$(date +%s) --overwrite`,
suivi d'un `kubectl rollout restart deployment/backend -n $NS` pour que les
pods relisent la nouvelle valeur (les Secrets montés en `envFrom` ne sont pas
rechargés à chaud).

## Administration de la base (Adminer)

`k8s/base/adminer.yaml` déploie Adminer (interface web PostgreSQL) **sans
route Ingress** : c'est un Service `ClusterIP` interne au cluster, jamais
exposé sur Internet.

**Prod (point d'audit B2)** : l'overlay `prod` met ce Deployment à
`replicas: 0` (`k8s/overlays/prod/kustomization.yaml`) — aucun pod Adminer ne
tourne en prod, pour ne pas garder une UI d'accès direct à la base en
permanence. Pour une intervention ponctuelle :

```bash
kubectl scale deploy/adminer --replicas=1 -n prod
kubectl port-forward svc/adminer 8081:8080 -n prod
# ... puis, une fois terminé :
kubectl scale deploy/adminer --replicas=0 -n prod
```

**Préprod** : Adminer tourne normalement, accès à la demande :

```bash
kubectl port-forward svc/adminer 8081:8080 -n preprod
```

puis ouvrir `http://localhost:8081` — champ "Serveur" pré-rempli (`database`),
identifiants à saisir à la main (`kubectl get secret postgres-credentials -n
<preprod|prod> -o jsonpath='{.data.POSTGRES_USER}' | base64 -d`, idem pour
`POSTGRES_PASSWORD`). L'accès est donc conditionné à la possession d'un
kubeconfig valide sur le cluster (même niveau de confiance qu'un `kubectl
exec`), pas d'un simple mot de passe web.

Testé en conditions réelles (préprod et prod) : `securityContext.runAsUser:
1000` + `readOnlyRootFilesystem: true` fonctionnent, à une réserve près déjà
corrigée — le `session.save_path` réel de l'image `adminer:4.8.1-standalone`
est `/var/lib/php/sessions` (vérifié via `php -i` dans le pod), pas `/tmp`.
Sans un `emptyDir` dédié monté sur ce chemin, PHP ne peut jamais persister la
session malgré un `/tmp` inscriptible, et Adminer répond systématiquement
"Session expirée" dès la tentative de connexion — `k8s/base/adminer.yaml`
monte donc deux `emptyDir` distincts (`tmp` et `php-sessions`).

Pour rester connecté plus longtemps qu'une session de navigateur, cocher
**"Permanent login"** sur le formulaire de connexion : ce n'est pas un
réglage PHP mais une fonctionnalité native d'Adminer (`adminer.php`), qui
pose un cookie `adminer_permanent` valable 30 jours (`2592000`s, codé en
dur dans `cookie()`). Vérifié par lecture du code source de l'image
`adminer:4.8.1-standalone` : le cookie de session (`adminer_sid`) est lui
codé en dur avec une durée de vie `0` (`session_set_cookie_params`,
paramètre `array(0, ...)`), donc **aucun réglage `session.ini` /
`session.gc_maxlifetime` côté PHP n'a d'effet** sur cette expiration rapide
— à ne pas retenter. Le port-forward reste bien sûr requis dans tous les
cas, "Permanent login" ne change rien à ça, il évite seulement d'avoir à
ressaisir les identifiants PostgreSQL à chaque nouvel onglet/navigateur.

Limite à connaître : la clé de déchiffrement de ce cookie est stockée dans
`/tmp/adminer.key`, un chemin non persisté (pas de volume dédié) — un
redémarrage du pod (ex. OOMKill, la limite `resources.limits.memory: 128Mi`
est basse pour parcourir une grosse table) invalide silencieusement tous les
cookies "Permanent login" en cours ; il suffit de recocher la case à la
prochaine connexion, aucune action corrective nécessaire.

## Limites connues (acceptables pour un projet portfolio, à retravailler sinon)

- Postgres et RabbitMQ tournent en pod (1 réplique, PVC) plutôt que sur des
  services managés Scaleway : pas de sauvegarde automatique.
- Pas de Pod Security Admission `restricted` au niveau namespace : les
  Deployments applicatifs (backend, frontend) respectent déjà ce profil
  (`runAsNonRoot`, `readOnlyRootFilesystem`, capacités supprimées), mais
  postgres/rabbitmq utilisent leurs images officielles telles quelles.
- Deux bootstraps manuels `kubectl create secret` (hors ESO, cf. section
  "Prérequis cluster" ci-dessus) : `scaleway-eso-auth` (auth ESO elle-même,
  forcément hors du système qu'elle authentifie) et `cv-pdf` (dépasse la
  limite de 64 Ko par version de Secret Manager). Documentés, mais restent
  des étapes manuelles à ne pas oublier
  lors d'un rebuild de cluster.
