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
5. **Créer les namespaces** — fait automatiquement par `kubectl apply -k`
   (`namespace.yaml` est dans les resources de chaque overlay), pas besoin de
   le faire à la main.

## Secrets — jamais créés par CI, jamais dans git

À lancer une fois par namespace (remplacer les valeurs), **avant** le premier
déploiement (les Deployments referencent ces Secrets et resteront en
`CreateContainerConfigError` tant qu'ils n'existent pas) :

```bash
NS=preprod   # puis répéter pour NS=prod avec des valeurs DIFFÉRENTES

# --- Postgres ---
kubectl create secret generic postgres-credentials -n $NS \
  --from-literal=POSTGRES_DB=cp_ghostotof \
  --from-literal=POSTGRES_USER=app \
  --from-literal=POSTGRES_PASSWORD="$(openssl rand -base64 32)"

# --- RabbitMQ ---
kubectl create secret generic rabbitmq-credentials -n $NS \
  --from-literal=RABBITMQ_DEFAULT_USER=app \
  --from-literal=RABBITMQ_DEFAULT_PASS="$(openssl rand -base64 32)"

# --- Backend (APP_SECRET, DATABASE_URL, MESSENGER_TRANSPORT_DSN, JWT_PASSPHRASE,
#     MAILER_DSN, CONTACT_RECIPIENT_EMAIL) ---
# DATABASE_URL / MESSENGER_TRANSPORT_DSN pointent sur les Services k8s "database"
# et "rabbitmq" (cf. k8s/base/postgres.yaml et rabbitmq.yaml), avec les
# identifiants créés ci-dessus.
kubectl create secret generic backend-secrets -n $NS \
  --from-literal=APP_SECRET="$(openssl rand -hex 16)" \
  --from-literal=DATABASE_URL="postgresql://app:<POSTGRES_PASSWORD>@database:5432/cp_ghostotof?serverVersion=18&charset=utf8" \
  --from-literal=MESSENGER_TRANSPORT_DSN="amqp://app:<RABBITMQ_PASS>@rabbitmq:5672/%2f/messages" \
  --from-literal=JWT_PASSPHRASE="<générée localement, voir ci-dessous>" \
  --from-literal=MAILER_DSN="null://null" \
  --from-literal=CONTACT_RECIPIENT_EMAIL="contact@cp-ghostotof.com"

# --- Clés JWT : générées en LOCAL (jamais sur le cluster), une paire par
#     namespace (préprod et prod ne doivent PAS partager la même paire) ---
#   make sh
#   php bin/console lexik:jwt:generate-keypair --skip-if-exists
kubectl create secret generic jwt-keys -n $NS \
  --from-file=private.pem=backend/config/jwt/private.pem \
  --from-file=public.pem=backend/config/jwt/public.pem

# --- CV (jamais commité, cf. backend/resources/README.md) ---
kubectl create secret generic cv-pdf -n $NS \
  --from-file=cv.pdf=/chemin/vers/cv.pdf
```

## Rotation / mise à jour d'un Secret

`kubectl create secret ... --dry-run=client -o yaml | kubectl apply -f -` (ou
`kubectl delete secret <nom> -n $NS` puis recréer), suivi d'un
`kubectl rollout restart deployment/backend -n $NS` pour que les pods relisent
la nouvelle valeur (les Secrets montés en `envFrom` ne sont pas rechargés à
chaud).

## Limites connues (acceptables pour un projet portfolio, à retravailler sinon)

- Postgres et RabbitMQ tournent en pod (1 réplique, PVC) plutôt que sur des
  services managés Scaleway : pas de sauvegarde automatique.
- Pas de Pod Security Admission `restricted` au niveau namespace : les
  Deployments applicatifs (backend, frontend) respectent déjà ce profil
  (`runAsNonRoot`, `readOnlyRootFilesystem`, capacités supprimées), mais
  postgres/rabbitmq utilisent leurs images officielles telles quelles.
