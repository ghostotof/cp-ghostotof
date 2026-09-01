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
   `ClusterIssuer` `letsencrypt` — pas de `cluster-admin`). Appliqué une fois par
   namespace :
   ```
   kubectl apply -f <(kubectl kustomize k8s/overlays/preprod | \
     yq 'select(.metadata.name == "github-actions-deployer" or .metadata.name == "github-actions-deployer-token")')
   # idem pour prod
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

### 1bis. PAT GitHub pour le pull d'images GHCR (registre privé par défaut)

Les Deployments backend/frontend référencent `imagePullSecrets: [ghcr-registry]`
(`k8s/base/{backend,frontend}-deployment.yaml`) : un Secret Kubernetes de type
`docker-registry`, deuxième bootstrap manuel `kubectl create secret`
(même logique que `scaleway-eso-auth` ci-dessus — pas de valeur sensible à
committer, donc pas géré par ESO ni par les overlays).

Un package GHCR publié via `GITHUB_TOKEN` (cf. job `build-images` du pipeline)
est **privé par défaut**, même sur un dépôt public — impossible à changer via
API/CLI, seulement via **Settings du package sur github.com > Change
visibility > Public** (une fois le premier tag construit). Tant que ce n'est
pas fait (ou si vous préférez garder les images privées), ce PAT est
nécessaire pour le pull :

Créer le token sur GitHub : **Settings (compte) > Developer settings >
Personal access tokens > Tokens (classic)**, scope `read:packages`
uniquement, sans expiration courte (le pull d'image en dépend en continu).
Un seul token suffit pour les deux namespaces :

```bash
for NS in preprod prod; do
  kubectl create secret docker-registry ghcr-registry -n $NS \
    --docker-server=ghcr.io \
    --docker-username=ghostotof \
    --docker-password=<PAT>
done
```

Si les 2 packages (`cp-ghostotof-backend`, `cp-ghostotof-frontend`) sont
rendus publics par la suite, ce secret et la ligne `imagePullSecrets`
correspondante dans les Deployments peuvent être supprimés — le pull
anonyme fonctionne alors directement.

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

`k8s/base/adminer.yaml` déploie Adminer (interface web PostgreSQL) dans chaque
namespace, mais **sans route Ingress** : c'est un Service `ClusterIP` interne
au cluster, jamais exposé sur Internet. Accès à la demande :

```bash
kubectl port-forward svc/adminer 8081:8080 -n preprod   # ou -n prod
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
- Trois bootstraps manuels `kubectl create secret` (hors ESO, cf. section
  "Prérequis cluster" ci-dessus) : `scaleway-eso-auth` (auth ESO elle-même,
  forcément hors du système qu'elle authentifie), `ghcr-registry` (pull
  d'images, aucune valeur committable — évitable si les packages GHCR sont
  publics) et `cv-pdf` (dépasse la limite de 64 Ko par version de Secret
  Manager). Documentés, mais restent des étapes manuelles à ne pas oublier
  lors d'un rebuild de cluster.
