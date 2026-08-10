# Déploiement Kubernetes (Scaleway Kapsule)

Manifests Kustomize : `base/` (commun) + `overlays/{preprod,prod}/` (namespace,
domaine, réplicas, config). Le pipeline GitLab CI (`.gitlab-ci.yml`) applique
ces overlays sur tag Git — voir la table des stages qui y est commentée.

## Prérequis cluster (une fois, hors CI)

1. **Créer le cluster Kapsule** (console Scaleway ou `scw k8s cluster create`),
   un seul cluster pour préprod + prod (séparées par namespace).
2. **Installer ingress-nginx** (Helm) : expose les Ingress `k8s/base/ingress.yaml`.
3. **Installer cert-manager** + un `ClusterIssuer` nommé `letsencrypt` (référencé
   par l'annotation `cert-manager.io/cluster-issuer` de l'Ingress) : gère les
   certificats TLS Let's Encrypt automatiquement.
4. **Installer le GitLab Agent for Kubernetes** (agentk) : déclarer l'agent dans
   `.gitlab/agents/cp-ghostotof/config.yaml` (déjà présent dans ce dépôt), puis
   sur le cluster :
   ```
   helm repo add gitlab https://charts.gitlab.io
   helm upgrade --install cp-ghostotof-agent gitlab/gitlab-agent \
     --namespace gitlab-agent --create-namespace \
     --set image.tag=stable \
     --set config.token=<token généré dans GitLab : Operate > Kubernetes clusters> \
     --set config.kasAddress=wss://kas.gitlab.com
   ```
   Le job CI utilise ensuite `kubectl config use-context
   <chemin-projet-gitlab>:cp-ghostotof-agent` — aucun kubeconfig à stocker en
   variable CI/CD.
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

### 1bis. Deploy Token GitLab pour le pull d'images (registre privé)

Les Deployments backend/frontend référencent `imagePullSecrets: [gitlab-registry]`
(`k8s/base/{backend,frontend}-deployment.yaml`) : un Secret Kubernetes de type
`docker-registry`, deuxième et dernier bootstrap manuel `kubectl create secret`
(même logique que `scaleway-eso-auth` ci-dessus — pas de valeur sensible à
committer, donc pas géré par ESO ni par les overlays).

Créer le token dans GitLab : **Settings > Repository > Deploy tokens**, scope
`read_registry` uniquement, sans expiration courte (le pull d'image en dépend
en continu). Un seul token suffit pour les deux namespaces (accès en lecture
seule au même registre) :

```bash
for NS in preprod prod; do
  kubectl create secret docker-registry gitlab-registry -n $NS \
    --docker-server=registry.gitlab.com \
    --docker-username=<gitlab+deploy-token-XXXXX> \
    --docker-password=<TOKEN>
done
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

**CV** (jamais commité, cf. `backend/resources/README.md`) — contenu binaire,
à encoder en base64 avant l'upload (`ExternalSecret` le décode via
`decodingStrategy: Base64`) :
```bash
base64 -w0 /chemin/vers/cv.pdf   # → valeur du secret Scaleway preprod-cv-pdf
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

## Limites connues (acceptables pour un projet portfolio, à retravailler sinon)

- Postgres et RabbitMQ tournent en pod (1 réplique, PVC) plutôt que sur des
  services managés Scaleway : pas de sauvegarde automatique.
- Pas de Pod Security Admission `restricted` au niveau namespace : les
  Deployments applicatifs (backend, frontend) respectent déjà ce profil
  (`runAsNonRoot`, `readOnlyRootFilesystem`, capacités supprimées), mais
  postgres/rabbitmq utilisent leurs images officielles telles quelles.
- Les manifests `external-secrets.yaml`/`secretstore.yaml` sont écrits d'après
  la documentation ESO/Scaleway (`external-secrets.io/v1`) mais n'ont pas pu
  être testés contre un cluster réel (aucun cluster provisionné à ce stade) :
  à valider avec `kubectl explain externalsecret.spec` /
  `kubectl get externalsecret -n preprod` une fois ESO installé, avant de s'y
  fier en prod.
