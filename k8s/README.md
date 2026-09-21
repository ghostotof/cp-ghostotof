# Déploiement Kubernetes (Scaleway Kapsule)

Manifests Kustomize : `base/` (commun) + `overlays/{preprod,prod}/` (namespace,
domaine, réplicas, config). Le pipeline GitHub Actions (`.github/workflows/pipeline.yml`)
applique l'overlay `preprod` à chaque push d'une branche `release/*` et l'overlay `prod` au
merge de cette branche dans `main` (spec 0006) — voir les commentaires de ce fichier pour le
détail des jobs.

## Prérequis cluster (une fois, hors CI)

1. **Créer le cluster Kapsule** (console Scaleway ou `scw k8s cluster create`),
   un seul cluster pour préprod + prod (séparées par namespace).
2. **Installer ingress-nginx** (Helm) : expose les Ingress `k8s/base/ingress.yaml`.
   **Avec `k8s/ingress-nginx-values.yaml`**, qui active le proxy-protocol v2 côté
   Load Balancer Scaleway et côté contrôleur (audit du 2026-09-16, constat A25,
   ADR 0005) : sans lui, l'adresse vue par tout ce qui limite par IP — zones
   nginx du sidecar, `login_throttling`, quotas Symfony — est celle du LB, la
   même pour tout Internet. Vérification après application : six connexions
   erronées depuis une seule machine (`tools/smoke-login-throttling.sh`) sont
   freinées à la sixième, et l'access log du sidecar (`kubectl logs deploy/backend
   -c nginx`) montre l'IP publique du visiteur, pas `100.64.x.x` ni celle du LB.
   ```bash
   helm repo add ingress-nginx https://kubernetes.github.io/ingress-nginx
   helm upgrade --install ingress-nginx ingress-nginx/ingress-nginx \
     --namespace ingress-nginx --create-namespace --version 4.15.1 \
     --reuse-values -f k8s/ingress-nginx-values.yaml
   ```
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
   > **⚠ À rejouer après le point d'audit C8.** Le `Role` a changé :
   > `pods/exec: create` a été **retiré** et `batch/jobs`
   > (`get,list,watch,create,delete`) ajouté, parce que les migrations Doctrine
   > passent désormais par le Job `k8s/base/migrate-job.yaml` au lieu d'un
   > `kubectl exec`. Ce bootstrap n'étant jamais rejoué par le pipeline, la
   > boucle ci-dessus **doit être relancée avant le prochain déploiement**,
   > sinon `deploy-preprod`/`deploy-prod` échouent sur
   > `cannot create resource "jobs"`. Vérification :
   > ```bash
   > kubectl -n preprod auth can-i create jobs \
   >   --as=system:serviceaccount:preprod:github-actions-deployer   # yes attendu
   > kubectl -n preprod auth can-i create pods/exec \
   >   --as=system:serviceaccount:preprod:github-actions-deployer   # no attendu
   > ```

   **Puis le jeton du pipeline — lié, à durée limitée, régénéré par script**
   (3e audit du 2026-09-16, constat A3, décision D4). Le kubeconfig que le
   pipeline reçoit n'est plus construit à la main autour d'un Secret
   `kubernetes.io/service-account-token` (un jeton qui n'expire jamais : une
   fuite du secret GitHub valait un accès permanent) ; il l'est par
   `tools/rotate-deployer-token.sh`, autour d'un jeton **lié** (`kubectl
   create token`, 90 jours — question Q4, rotation trimestrielle), et posé
   directement comme secret d'environnement :
   ```bash
   tools/rotate-deployer-token.sh preprod            # → KUBE_CONFIG_PREPROD, environnement `preprod`
   tools/rotate-deployer-token.sh prod               # → KUBE_CONFIG_PROD,    environnement `production`
   tools/rotate-deployer-token.sh preprod --dry-run  # tout sauf l'émission et la publication
   ```
   Le script lit le endpoint et la CA dans le kubeconfig courant (jamais
   l'identité admin), émet le jeton, l'**essaie avant de le publier**
   (`auth can-i create jobs` → `yes`, `create pods/exec` → `no`, sinon rien
   n'est publié), le passe à `gh secret set` par stdin — il n'apparaît ni sur
   la sortie ni dans un argument de processus — et affiche la date
   d'expiration avec la date de la prochaine rotation. Le fichier temporaire
   est supprimé à la sortie.

   **Durée réellement accordée.** L'API tronque *en silence* une demande
   au-delà de `--service-account-max-token-expiration` du control-plane —
   valeur inconnue sur Kapsule (managé). Le script décode donc le champ `exp`
   du JWT émis (payload base64, sans signature ni secret) et avertit
   `durée TRONQUÉE` si elle est inférieure à la demande : dans ce cas, c'est
   la durée accordée qui fixe le calendrier, pas les 90 jours. Vérifier ce
   point à la **première** rotation.

   **Périodicité.** Tous les 90 jours, par environnement : poser deux
   rappels calendaires à la date « Prochaine rotation » que le script affiche
   (sept jours avant l'expiration). Un jeton expiré se voit à la première
   étape `Configure kubectl` d'un déploiement (`Unauthorized`) — relancer le
   script, rien d'autre à réparer. Rotation suivante : l'ancien jeton lié
   expire seul, rien à supprimer.

   **Première rotation, sans interruption.** Le Secret durable
   `github-actions-deployer-token` reste valide tant qu'il existe, donc le
   pipeline continue de fonctionner pendant la bascule. Dans l'ordre :
   1. `tools/rotate-deployer-token.sh preprod`, puis `… prod` ;
   2. `tools/github-settings.sh` : l'étape k doit voir `KUBE_CONFIG_PREPROD`
      et `KUBE_CONFIG_PROD` présents dans leur environnement ;
   3. un run de release complet **vert** (`deploy-preprod` → `deploy-prod`) :
      les jobs déclarant `environment:` reçoivent le secret d'environnement,
      donc le nouveau jeton, par priorité sur l'homonyme de dépôt ;
   4. seulement alors, supprimer le Secret durable des deux namespaces —
      `kubectl apply` ne supprime jamais ce qu'un manifeste ne déclare plus,
      il faut le faire explicitement :
      ```bash
      kubectl --context cp-ghostotof-preprod -n preprod delete secret github-actions-deployer-token
      kubectl --context cp-ghostotof-prod    -n prod    delete secret github-actions-deployer-token
      ```
      et `gh secret delete KUBE_CONFIG_PREPROD` / `KUBE_CONFIG_PROD` au niveau
      **dépôt** (leur valeur est l'ancien jeton durable, désormais révoqué).

   **Ce que ce jeton permet — le modèle réel, pas le souhaité.** Le `Role`
   du déployeur cumule `jobs create`, `pods/log`, `deployments update` et
   `externalsecrets create/update` : c'est, par construction, la lecture de
   **tout Secret du namespace** — un Job qui affiche son environnement, puis
   ses logs, suffit ; un ExternalSecret en fait synchroniser d'autres depuis
   Secret Manager. Le retrait de `pods/exec` (audit C8) ferme le shell
   interactif, pas cette lecture ; ce n'est pas une frontière. Réduire le
   Role sans casser `kubectl apply -k` n'est pas possible : un déployeur
   applique des Deployments, et un Deployment monte des Secrets. Ce qui
   borne l'exposition, c'est donc le **jeton** : lié, 90 jours, dans un
   secret d'environnement que seule une branche `release/*` (preprod) ou
   `main` (production) peut lire (constat A4, ci-dessous). Une fuite vaut au
   plus le reste de la période, pour un namespace, et se révoque en
   relançant le script — l'ancien jeton reste techniquement valide jusqu'à
   son `exp` ; pour couper court, supprimer et recréer le ServiceAccount
   (les jetons liés sont invalidés avec lui, rejouer ensuite la boucle
   ci-dessus).

   > **Ce sont des secrets d'environnement, pas des secrets de dépôt**
   > (3e audit du 2026-09-16, constat A4) : un secret de dépôt est servi à
   > *n'importe quel* job de *n'importe quelle* branche, y compris une
   > `feature/*` ou une PR d'un fork — le kubeconfig du déployeur de production
   > y compris. Un secret d'environnement n'est servi qu'aux jobs qui déclarent
   > cet `environment:`, et la politique de branche de l'environnement
   > (`tools/github-settings.sh`, étape d : `main` pour `production`,
   > `release/*` pour `preprod`) borne les branches d'où il est lisible.
   > C'est le script de rotation qui pose les deux `KUBE_CONFIG_*` à ce
   > niveau ; `tools/github-settings.sh` (étape k) vérifie leur présence et
   > avertit tant qu'un homonyme subsiste au niveau dépôt — tant qu'il y est,
   > il continue de servir les jobs qui ne déclarent pas d'environnement, donc
   > la garde ne vaut rien. Le supprimer (`gh secret delete KUBE_CONFIG_PROD`)
   > une fois le run de release suivant vert. Même règle pour
   > `PREPROD_BASIC_AUTH` (preprod) et `RELEASE_DEPLOY_KEY` (production).
5. **Installer External Secrets Operator** (Helm) : synchronise les Secrets
   Kubernetes depuis **Scaleway Secret Manager** (région `fr-par` — hébergement
   France garanti), au lieu d'un `kubectl create secret` manuel non versionné.
   ```
   helm repo add external-secrets https://charts.external-secrets.io
   helm upgrade --install external-secrets external-secrets/external-secrets \
     --namespace external-secrets --create-namespace
   ```
6. **Créer les namespaces** — `namespace.yaml` est dans les `resources:` de
   chaque overlay, donc `kubectl apply -k` les maintient à jour (leurs labels
   Pod Security Admission compris, cf. « Durcissement des pods » ci-dessous).
   Mais la **création** reste manuelle, avec un kubeconfig d'administrateur :
   le `ClusterRole` du déployeur CI ne porte que `get`/`patch` sur
   `namespaces`, restreint par `resourceNames` — pas `create`. Et de toute
   façon le namespace doit exister avant l'étape 4, qui y pose le
   ServiceAccount du pipeline.
   ```bash
   kubectl create namespace preprod
   kubectl create namespace prod
   ```

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
# projectId : identifiant de projet, ne permet rien à lui seul — écrit en clair
# dans k8s/base/secretstore.yaml.

# accessKey ET secretKey : le Secret bootstrap `scaleway-eso-auth` porte
# désormais DEUX clés (point d'audit C4). accessKey n'est pas un secret au sens
# IAM, mais dans un dépôt public elle désigne nommément l'identité qui a accès
# au Secret Manager : on ne la publie plus.
for NS in preprod prod; do
  kubectl create secret generic scaleway-eso-auth -n $NS \
    --from-literal=access-key="<SCALEWAY_ACCESS_KEY>" \
    --from-literal=secret-key="<SCALEWAY_SECRET_KEY>"
done
```

> **Migration depuis un cluster existant** : le Secret ne portait que
> `secret-key`. Ajouter la clé manquante avant d'appliquer le nouveau
> `secretstore.yaml`, sinon le SecretStore passe en `NotReady` et les
> ExternalSecrets cessent de se rafraîchir :
>
> ```bash
> for NS in preprod prod; do
>   kubectl patch secret scaleway-eso-auth -n $NS --type merge \
>     -p "{\"stringData\":{\"access-key\":\"<SCALEWAY_ACCESS_KEY>\"}}"
> done
> kubectl get secretstore scaleway-secret-manager -n prod -o jsonpath='{.status.conditions}'
> ```

### 1bis. Packages GHCR — publics, pull anonyme (pas de bootstrap requis)

Les 2 packages (`cp-ghostotof-backend`, `cp-ghostotof-frontend`) sont rendus
**publics** sur github.com (Settings du package > Change visibility >
Public — impossible à automatiser via API/CLI, bascule manuelle unique
faite une fois la première image construite). Le pull d'image ne nécessite donc
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
# preprod-backend-contact-email, preprod-backend-anthropic-api-key
```

**Deux secrets ont leur propre recette, plus bas**, parce qu'ils ne sont pas de
simples valeurs à recopier : `preprod-basic-auth-htpasswd` (§2bis) et
`preprod-backend-xdebug-trigger` (« Profilage Xdebug en préprod »). Ce dernier
est en outre **facultatif** : tant qu'il n'existe pas, tout fonctionne, seul le
profilage est indisponible.

**Clé Anthropic** (`<env>-backend-anthropic-api-key`, ADR 0004) — une clé de
compte de service créée dans la console Claude Platform (Settings > API keys),
idéalement une par environnement pour pouvoir les révoquer séparément, rattachée
à un workspace doté d'un plafond de dépense mensuel. La valeur ne doit jamais
transiter par l'historique du shell : la passer par fichier (`data=@fichier`)
puis supprimer le fichier.

```bash
for ENV in preprod prod; do
  ID=$(scw secret secret create name=${ENV}-backend-anthropic-api-key path=/ region=fr-par -o json | jq -r .id)
  scw secret version create "$ID" data=@/chemin/vers/anthropic.key region=fr-par
done
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

### 2bis. Basic Auth de la préprod (restriction d'accès, posée le 2026-09-12)

La préprod n'est plus censée être publique : Basic Auth sur tout l'ingress
(annotations `nginx.ingress.kubernetes.io/auth-*` dans l'overlay `preprod`
uniquement — `prod` reste public). Choisi plutôt qu'un allowlist par IP
source (`whitelist-source-range`) : une IP mobile (4G/5G) vient du CGNAT de
l'opérateur, partagée et changeante — impossible à allowlister sans soit
laisser passer une partie du réseau de l'opérateur, soit devoir la mettre à
jour en permanence.

> **⚠ Terminal séparé, jamais dans une session d'agent.** Toute commande de
> cette section qui manipule la valeur — mot de passe, fichier htpasswd,
> couple `identifiant:mot-de-passe` — se lance dans un terminal à part, et ne
> transmet cette valeur que **par fichier ou par stdin**. Jamais via le
> préfixe `!` d'une session Claude Code, jamais `gh secret set --body '…'`,
> jamais `data="$(cat …)"` : une substitution de commande remet la valeur dans
> les arguments du processus, donc visible dans `ps` et dans l'historique du
> shell. Ces deux formes figuraient ici jusqu'au 2026-09-16 ; jouées dans une
> session d'agent, elles ont recopié les identifiants en clair dans le
> transcript, et le mot de passe a été changé dans la foulée (reliquat R.3c du
> lot 1). Les recettes ci-dessous sont écrites pour que la valeur ne quitte
> jamais un fichier d'un répertoire temporaire privé.

#### Générer le mot de passe et le fichier htpasswd

Bloc commun à la première installation et à la rotation. `umask 077` avant
`mktemp -d` : le répertoire et tout ce qu'on y écrit ne sont lisibles que par
le compte courant. Le hachage est bcrypt (`-B`), celui qu'accepte déjà
l'ingress ; `-i` fait lire le mot de passe sur stdin, là où `-b` l'aurait mis
dans la ligne de commande. `httpd:alpine` évite d'installer `htpasswd` en
local (`-i` sur `docker run`, sinon stdin n'est pas transmis).

```bash
umask 077
TMP=$(mktemp -d)
openssl rand -base64 24 > "$TMP/password"
docker run --rm -i httpd:alpine htpasswd -niB '<identifiant>' < "$TMP/password" > "$TMP/htpasswd"
# Le couple attendu par curl -u, sans saut de ligne final :
{ printf '%s:' '<identifiant>'; tr -d '\n' < "$TMP/password"; } > "$TMP/credentials"
```

Vérifications, sans jamais afficher la valeur :

```bash
cut -d: -f1 "$TMP/htpasswd"                 # l'identifiant, et rien d'autre
cut -d: -f2 "$TMP/htpasswd" | cut -c1-4     # $2y$ attendu (bcrypt)
docker run --rm -i -v "$TMP:/w:ro" httpd:alpine \
  htpasswd -vi /w/htpasswd '<identifiant>' < "$TMP/password"   # sortie : mot de passe correct
```

L'alphabet base64 ne contient ni `:` ni guillemet : le couple reste
découpable par `curl -u` et par le fichier de config que construisent
`tools/audit-prod.sh` et `tools/smoke-login-throttling.sh`.

#### Première installation

Uploader le **contenu complet du fichier htpasswd** (pas juste le mot de
passe) dans Scaleway Secret Manager — `data=@<fichier>` fait lire le fichier
par la CLI, rien ne passe par un argument :

```bash
scw secret secret create name=preprod-basic-auth-htpasswd path=/ region=fr-par
scw secret version create <secret-id> data=@"$TMP/htpasswd" region=fr-par
```

ESO synchronise le Secret `preprod-basic-auth` sous 1h maximum (forcer avec
`kubectl annotate externalsecret preprod-basic-auth -n preprod
force-sync=$(date +%s) --overwrite` pour ne pas attendre). **Contrairement
aux autres Secrets** (cf. « Rotation / mise à jour d'un Secret » ci-dessous),
**aucun redémarrage de pod n'est nécessaire** : le contrôleur ingress-nginx
surveille lui-même le Secret référencé par `auth-secret` et recharge sa
configuration nginx dès qu'il change.

**Secret GitHub Actions associé** (créé côté GitHub, jamais dans ce dépôt) :
les jobs `audit-preprod` (`tools/audit-prod.sh`) et `smoke-test-preprod`
(`tools/smoke-login-throttling.sh`) interrogent la préprod — sans
identifiants, l'audit ne recevrait que des 401 et prendrait chacun pour une
fuite. `PREPROD_BASIC_AUTH` porte les mêmes identifiants que le htpasswd
ci-dessus, au format `utilisateur:mot_de_passe` (celui que `curl -u` attend,
pas le hash bcrypt). Sans `--body`, `gh secret set` lit la valeur sur stdin :

```bash
gh secret set PREPROD_BASIC_AUTH --env preprod < "$TMP/credentials"
```

`--env preprod` : c'est un secret d'**environnement**, servi aux seuls jobs
qui déclarent `environment: preprod` et depuis les seules branches `release/*`
(constat A4, cf. §4 ci-dessus). Pas un secret de dépôt : l'homonyme qui
traînait à ce niveau a été supprimé le 2026-09-16 (reliquat R.3e), parce qu'un
secret de dépôt est servi à n'importe quel job de n'importe quelle branche et
vide la garde de son sens.

Puis détruire le répertoire temporaire (cf. étape 7 de la rotation).

Absent côté `audit-prod` (la vraie prod, non protégée) : le script s'exécute
alors sans `-u`, comportement inchangé.

#### Rotation du mot de passe

Quand : fuite suspectée, exposition dans un transcript ou un journal, départ
d'une personne qui connaissait le mot de passe. Pas de calendrier imposé —
contrairement au jeton du déployeur (§4), ce mot de passe ne protège qu'un
environnement de préproduction et n'expire pas de lui-même.

Le secret Scaleway existe déjà : c'est une **nouvelle version** qu'on crée,
pas un nouveau secret — `scw secret secret create` échoue sur un nom déjà
pris. Vérifier la syntaxe exacte avec `scw secret version create --help`,
elle évolue.

1. **Générer la nouvelle valeur** dans un répertoire temporaire privé : jouer
   le bloc « Générer le mot de passe et le fichier htpasswd » ci-dessus, avec
   le **même identifiant** (le changer obligerait à modifier aussi le couple
   attendu par la CI, sans rien gagner). Vérification : les trois commandes
   `cut`/`htpasswd -vi` du même bloc.

2. **Créer une nouvelle version du secret existant.** Retrouver son
   identifiant par son nom, puis pousser le fichier :

   ```bash
   scw secret secret list name=preprod-basic-auth-htpasswd region=fr-par
   # relever l'ID de la ligne correspondante, puis :
   scw secret version create <secret-id> data=@"$TMP/htpasswd" region=fr-par
   scw secret version list <secret-id> region=fr-par
   ```

   Vérification : la nouvelle révision apparaît, `enabled` et `latest`.
   (`scw secret version create` accepte `disable-previous=true`, qui ferait
   l'étape 5 d'un coup — on ne s'en sert pas : la désactivation vaut
   révocation, et on la veut *après* le `force-sync` de l'étape 3, pas avant.)

3. **Forcer la resynchronisation de l'ExternalSecret.** Relever d'abord la
   `resourceVersion` du Secret Kubernetes, pour pouvoir constater le
   changement sans regarder le contenu :

   ```bash
   kubectl get secret preprod-basic-auth -n preprod -o jsonpath='{.metadata.resourceVersion}{"\n"}'
   kubectl annotate externalsecret preprod-basic-auth -n preprod force-sync=$(date +%s) --overwrite
   kubectl get externalsecret preprod-basic-auth -n preprod \
     -o jsonpath='{.status.conditions[?(@.type=="Ready")].status} {.status.refreshTime}{"\n"}'
   kubectl get secret preprod-basic-auth -n preprod -o jsonpath='{.metadata.resourceVersion}{"\n"}'
   ```

   Vérification : `True` et un `refreshTime` de l'instant, et une
   `resourceVersion` **différente** de celle relevée avant. Ne jamais faire
   `-o yaml` ni `| base64 -d` sur ce Secret : cela remettrait le hash — et,
   pour d'autres secrets, la valeur — dans le terminal et son historique.
   Aucun `rollout restart` ici : ingress-nginx recharge tout seul (cf.
   ci-dessus).

4. **Poser le secret GitHub d'environnement**, par stdin :

   ```bash
   gh secret set PREPROD_BASIC_AUTH --env preprod < "$TMP/credentials"
   gh secret list --env preprod
   ```

   Vérification : `gh secret list` affiche la date de mise à jour (jamais la
   valeur). C'est bien le secret de l'environnement `preprod`, pas un secret
   de dépôt — voir la note de la première installation.

5. **Désactiver les anciennes révisions** dans Secret Manager — c'est ce qui
   révoque réellement l'ancienne valeur. Après l'étape 3, jamais avant :
   l'ExternalSecret doit déjà avoir resynchronisé sur la nouvelle révision.

   ```bash
   scw secret version disable <secret-id> revision=<n> region=fr-par
   scw secret version list <secret-id> status.0=enabled region=fr-par
   ```

   Vérification : la seconde commande ne renvoie plus qu'une seule révision.

6. **Vérifier de bout en bout.** En **navigation privée** (une fenêtre
   ordinaire rejouerait les identifiants mémorisés) : l'ancien mot de passe
   doit être refusé, le nouveau accepté sur `https://preprod.cp-ghostotof.com`.
   Puis côté CI, relancer les deux jobs qui lisent le secret — ils échouent en
   masse si le couple ne correspond plus au htpasswd :

   ```bash
   # les <job-id> attendus par --job ne sont pas les numéros de l'URL du navigateur :
   gh run view <run-id> --json jobs --jq '.jobs[] | {name, databaseId}'
   gh run rerun <run-id> --job <job-id>   # smoke-test-preprod, puis audit-preprod
   ```

   ou, plus simplement, attendre le prochain push sur une branche `release/*`,
   qui les rejoue de toute façon.

7. **Détruire le répertoire temporaire** :

   ```bash
   shred -u "$TMP"/* 2>/dev/null; rm -rf "$TMP"
   ```

   `shred` ne garantit rien sur un SSD ni sur un système de fichiers journalisé
   (wear levelling, copies au fil des journaux et des instantanés) : la seule
   garantie réelle reste que la valeur n'a jamais quitté ce répertoire, et
   qu'elle est révoquée dès l'étape 5.

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

**Zéro pod par défaut dans les deux environnements.** En prod depuis le point
d'audit B2 (`k8s/overlays/prod/kustomization.yaml`), et **en préprod depuis le
3e audit** (question Q5, tranchée le 2026-09-21,
`k8s/overlays/preprod/kustomization.yaml`) : les deux overlays mettent ce
Deployment à `replicas: 0`. Garder en permanence une UI d'accès direct à la
base n'apporte rien — et la préprod n'est pas un environnement de moindre
valeur de ce point de vue : elle porte les mêmes structures que la prod, et le
Basic Auth de son Ingress ne protège pas un `port-forward`.

Pour une intervention ponctuelle (`$NS` = `preprod` ou `prod`) :

```bash
# 1. allumer
kubectl patch deploy/adminer -n $NS --type=merge -p '{"spec":{"replicas":1}}'
kubectl rollout status deploy/adminer -n $NS --timeout=60s

# 2. accéder
kubectl port-forward svc/adminer 8081:8080 -n $NS

# 3. éteindre une fois terminé
kubectl patch deploy/adminer -n $NS --type=merge -p '{"spec":{"replicas":0}}'
```

`kubectl patch` sur `spec.replicas` plutôt que `kubectl scale` : c'est la même
raison que la fenêtre de maintenance décrite plus haut — le `Role` du déployeur
CI n'a pas la sous-ressource `deployments/scale`. Avec un kubeconfig
d'administrateur, `kubectl scale` marche aussi ; la commande ci-dessus a
l'avantage de fonctionner dans les deux cas.

**Le prochain `kubectl apply -k` de la pipeline remet `replicas: 0`** : un
Adminer qu'on aurait oublié allumé s'éteint tout seul au déploiement suivant.
C'est une propriété voulue, pas un effet de bord — mais elle a un corollaire :
si le pod disparaît au milieu d'une session, c'est probablement un déploiement
qui est passé, il suffit de le rallumer. Aucun `rollout status`, smoke test,
sonde ou étape de `pipeline.yml` n'attend un pod Adminer vivant (vérifié :
aucune occurrence d'`adminer` dans `.github/workflows/pipeline.yml` ni dans
`tools/`), la mise à zéro en préprod ne casse donc rien dans la chaîne.

Une fois le `port-forward` établi, ouvrir `http://localhost:8081` — champ
"Serveur" pré-rempli (`database`, via `ADMINER_DEFAULT_SERVER`, variable
toujours honorée par l'image 5.x), identifiants à saisir à la main
(`kubectl get secret postgres-credentials -n
<preprod|prod> -o jsonpath='{.data.POSTGRES_USER}' | base64 -d`, idem pour
`POSTGRES_PASSWORD`). L'accès est donc conditionné à la possession d'un
kubeconfig valide sur le cluster (même niveau de confiance qu'un `kubectl
exec`), pas d'un simple mot de passe web.

### Version de l'image et `readOnlyRootFilesystem`

**Depuis le 2026-09-21 (3e audit, constat A11), l'image est
`adminer:5.5.1-standalone`** (auparavant `4.8.1-standalone`, figée en 2021,
non maintenue et porteuse de CVE connues). Le changement de version est aussi
un changement de base : Debian + PHP 7.4 → **Alpine + PHP 8.4.25**. Trois
conséquences concrètes pour ce manifest, toutes vérifiées image en main :

- **Un seul chemin écrit au runtime : `/tmp`.** La 4.8.1 forçait
  `session.save_path = /var/lib/php/sessions` ; en 5.x ce répertoire n'existe
  plus du tout et `session.save_path` n'a plus de valeur, donc PHP retombe sur
  `sys_get_temp_dir()` = `/tmp`. C'est aussi ce que renvoie le `get_temp_dir()`
  d'`adminer.php` (`upload_tmp_dir` non défini `?: sys_get_temp_dir()`), qui
  sert aux fichiers temporaires d'import, à `/tmp/adminer.key` et à
  `/tmp/adminer-invalid`. Le second `emptyDir` (`php-sessions`) a donc été
  **supprimé** ; il ne reste que `tmp`, avec un `sizeLimit: 256Mi` (l'image
  autorise un `upload_max_filesize` de 128M).
- **Sans ce volume, ce n'est pas seulement la connexion qui casse.**
  `session_start()` échoue en « Read-only file system », et comme
  l'avertissement PHP est émis **avant** les en-têtes, Adminer n'envoie plus
  ni CSP, ni `X-Frame-Options`, ni `X-Content-Type-Options`, ni
  `Referrer-Policy` (« Cannot modify header information »). Vérifié par un
  lancement local avec et sans `tmpfs`.
- **`runAsUser` passe de 1000 à 100**, l'UID de l'utilisateur `adminer` de
  l'image 5.x (c'était 999 en 4.8.1 — le `1000` du manifest ne correspondait
  à rien, ça marchait parce qu'un `emptyDir` est créé en 0777). Même
  convention que `postgres.yaml` (`runAsUser: 70`). `runAsGroup: 101` est
  ajouté explicitement : sans lui le conteneur tourne en GID 0, ce qui n'est
  pas nécessaire ici.

Le reste du `securityContext` est inchangé et confirmé compatible avec la 5.x :
`runAsNonRoot`, `readOnlyRootFilesystem: true`, `allowPrivilegeEscalation:
false`, `capabilities.drop: [ALL]`, `seccompProfile: RuntimeDefault`.

À ne pas faire : **`ADMINER_DESIGN` et `ADMINER_PLUGINS` sont incompatibles
avec `readOnlyRootFilesystem`**. L'`entrypoint.sh` de l'image les traite en
écrivant dans `/var/www/html` (`ln -sf designs/…/adminer.css .` et
`php plugin-loader.php … > plugins-enabled/…`), sous `set -e` : le conteneur
refuse de démarrer. Seul `touch .adminer-init`, en fin d'entrypoint, est
protégé par un `|| true` — le message `touch: .adminer-init: Read-only file
system` dans les logs du pod est donc normal et sans conséquence. Si un thème
ou un plugin devenait nécessaire, il faudrait un `emptyDir` sur
`/var/www/html` pré-rempli par un initContainer, pas un relâchement du
`readOnlyRootFilesystem`.

Pour rester connecté plus longtemps qu'une session de navigateur, cocher
**"Permanent login"** sur le formulaire de connexion : ce n'est pas un
réglage PHP mais une fonctionnalité native d'Adminer (`adminer.php`), qui
pose un cookie `adminer_permanent` valable 30 jours (`2592000`s, codé en
dur dans `cookie()`, toujours vrai en 5.5.1 — relu dans `adminer.php` et
observé sur le `Set-Cookie` `adminer_permanent` d'une réponse réelle).
Le cookie de session (`adminer_sid`) est lui codé en dur avec une durée de
vie `0` (`session_set_cookie_params(0, cookie_path(), "", HTTPS, true)` en
5.5.1, `array(0, ...)` en 4.8.1), donc **aucun réglage `session.ini` /
`session.gc_maxlifetime` côté PHP n'a d'effet** sur cette expiration rapide
— à ne pas retenter. Le port-forward reste bien sûr requis dans tous les
cas, "Permanent login" ne change rien à ça, il évite seulement d'avoir à
ressaisir les identifiants PostgreSQL à chaque nouvel onglet/navigateur.

Limite à connaître : la clé de déchiffrement de ce cookie est stockée dans
`/tmp/adminer.key`, donc dans l'`emptyDir` — qui disparaît avec le pod. Tout
ce qui recrée le pod (OOMKill — la limite `resources.limits.memory: 128Mi`
est basse pour parcourir une grosse table —, mais aussi et surtout le retour
à `replicas: 0` au déploiement suivant) invalide silencieusement tous les
cookies "Permanent login" en cours ; il suffit de recocher la case à la
prochaine connexion, aucune action corrective nécessaire. Avec les deux
environnements à zéro pod par défaut, "Permanent login" ne sert donc plus
guère qu'à l'intérieur d'une même session d'intervention.

## Profilage Xdebug en préprod (3e audit, constat A12)

L'image `…-preprod` embarque Xdebug (`docker/php/Dockerfile`, stage `preprod`).
La question « le garder ou le retirer ? » a été tranchée le 2026-09-16 :
**gardé, mais fermé par défaut et armé par un secret**.

Ce qu'était l'état constaté, et qu'il ne faut pas laisser revenir :
`xdebug.trigger_value = ${XDEBUG_TRIGGER_SECRET}` avec une variable jamais
définie. PHP remplace alors l'interpolation par une chaîne **vide**, et une
`trigger_value` vide signifie pour Xdebug « n'importe quelle valeur
déclenche ». Le réglage censé restreindre le déclenchement l'ouvrait donc à
quiconque passe le Basic Auth de l'ingress — pour un coût CPU non négligeable.
Et, dans le même temps, le profil ne pouvait s'écrire nulle part
(`readOnlyRootFilesystem`, ADR 0005) : la fonctionnalité était à la fois
ouverte et inutilisable.

Le montage actuel, en trois pièces :

| Pièce | Où | Rôle |
|---|---|---|
| `xdebug.mode = "off"` | `docker/php/xdebug.preprod.ini` (dans l'image) | Extension chargée mais **inerte**. C'est l'état par défaut. |
| Secret `backend-xdebug-trigger` | `k8s/overlays/preprod/external-secrets.yaml` | L'interrupteur : fournit `XDEBUG_MODE=profile` **et** la valeur de déclenchement. |
| `emptyDir` sur `var/profiler` | `k8s/overlays/preprod/backend-xdebug.yaml` | Le seul endroit inscriptible où un profil peut atterrir. |

Points qui portent la sécurité de l'ensemble, à ne pas défaire :

- **Le verrou est sur `xdebug.mode`, pas sur `xdebug.trigger_value`.** Une
  valeur de déclenchement absente dégénère en « ouvert à tous » ; un mode
  absent dégénère en « rien ne se passe ». On fait donc reposer la propriété
  sur le second.
- **Secret dédié, monté `optional: true`**, et non une clé de plus dans
  `backend-secrets` : le backend démarre même sans lui (et sans lui, le
  profilage est impossible), là où une clé manquante dans `backend-secrets`
  empêcherait ESO de le synchroniser et le pod de démarrer. Il n'est au
  passage injecté ni dans le worker, ni dans les Jobs de migration et de seed,
  ni dans les CronJobs, qui partagent `backend-secrets` et n'ont rien à
  profiler — avec `xdebug.mode = "off"` dans l'image, ces pods tournent avec
  une extension totalement inerte.
- **Mode `profile` seul, jamais `trace`.** Une trace Xdebug écrit les
  **arguments** des appels de fonction en clair dans le `.xt` — le mot de passe
  d'un `POST /api/login_check` s'y retrouverait tel quel (vérifié). Un profil
  cachegrind, lui, ne contient que des chemins de fichiers, des noms de
  fonctions, des numéros de ligne et des compteurs de temps et de mémoire :
  **aucune valeur de variable**. C'est ce qui rend un profil récupérable sans
  précaution particulière.
- **Rien de tout cela n'existe en prod** : ni Secret, ni variable, ni volume,
  ni même Xdebug (l'image de prod ne l'embarque pas). Vérifiable :
  `kubectl kustomize k8s/overlays/prod | grep -ic xdebug` doit répondre `0`.

### Créer le secret de déclenchement

> **⚠ Terminal séparé, jamais dans une session d'agent** — mêmes règles qu'au
> §2bis : la valeur ne transite que par fichier, jamais par un argument de
> commande ni par une substitution `$(…)`.

Le secret Scaleway n'est **pas** un prérequis de déploiement : l'`ExternalSecret`
et le montage `optional` peuvent être déployés avant qu'il existe, le backend
démarre, seul le profilage reste indisponible. C'est exactement la propriété
recherchée — mais elle a une contrepartie à connaître : tant que le secret
n'existe pas, l'`ExternalSecret` `backend-xdebug-trigger` reste en `Ready:
False` dans `kubectl get externalsecret -n preprod`. C'est normal et sans
conséquence sur le reste ; ne pas le confondre avec une panne.

`tr -d '\n'` n'est pas un détail : `scw secret version create data=@fichier`
pousse les octets du fichier **tels quels**, saut de ligne final compris, et un
cookie ne peut pas contenir de saut de ligne — le déclencheur ne
correspondrait alors jamais. Le `trim` du template de l'`ExternalSecret` est la
ceinture ; ceci sont les bretelles.

```bash
umask 077
TMP=$(mktemp -d)
openssl rand -hex 32 | tr -d '\n' > "$TMP/xdebug-trigger"
wc -c < "$TMP/xdebug-trigger"        # 64 attendu, et surtout pas 65

scw secret secret create name=preprod-backend-xdebug-trigger path=/ region=fr-par
scw secret version create <secret-id> data=@"$TMP/xdebug-trigger" region=fr-par
```

Puis forcer la synchronisation et redémarrer le backend — contrairement au
Basic Auth (relu à chaud par ingress-nginx), une variable d'environnement
n'est lue qu'au démarrage du processus :

```bash
kubectl annotate externalsecret backend-xdebug-trigger -n preprod \
  force-sync=$(date +%s) --overwrite
kubectl get externalsecret backend-xdebug-trigger -n preprod \
  -o jsonpath='{.status.conditions[?(@.type=="Ready")].status}{"\n"}'     # True
kubectl rollout restart deployment/backend -n preprod
kubectl rollout status deployment/backend -n preprod --timeout=120s
```

Vérifier que Xdebug est bien **armé mais fermé**, sans jamais afficher la
valeur (`grep -c` compte, il n'imprime rien) :

```bash
kubectl exec -n preprod deploy/backend -c php-fpm -- \
  sh -c 'php --ri xdebug | grep -E "Profiler|Tracing|Step Debugger"'
# Profiler : enabled — Tracing et Step Debugger : disabled
kubectl exec -n preprod deploy/backend -c php-fpm -- \
  sh -c 'php -r "exit(strlen(ini_get(\"xdebug.trigger_value\")) >= 32 ? 0 : 1);"' \
  && echo "valeur de declenchement non vide"
```

Garder `$TMP/xdebug-trigger` le temps de la session de profilage (c'est de là
que `curl` le lira), puis le détruire comme au §2bis, étape 7.

### Déclencher un profil

Xdebug 3.5 cherche le déclencheur dans un **cookie**, un paramètre **GET/POST**
ou une **variable d'environnement** — **pas dans un en-tête HTTP**. Vérifié en
image : `XDEBUG_TRIGGER: <valeur>` en en-tête ne produit rien. Les noms acceptés
sont `XDEBUG_TRIGGER` (générique) et `XDEBUG_PROFILE` (profileur seulement) ;
`XDEBUG_SESSION` ne déclenche pas le profileur.

**Le cookie, jamais la query string** : nginx journalise l'URL complète dans son
log d'accès, y compris `?XDEBUG_PROFILE=…`. Le secret finirait donc dans les
logs du pod, c'est-à-dire exactement là où on ne veut pas de secret.

Tout passe par un fichier de configuration `curl` (`-K`), pour que ni les
identifiants Basic Auth ni le déclencheur n'apparaissent dans `ps` ou dans
l'historique du shell. `$TMP/credentials` est le couple produit au §2bis :

```bash
umask 077
{
  printf 'user = "'; tr -d '\n' < "$TMP/credentials"; printf '"\n'
  printf 'cookie = "XDEBUG_PROFILE='; tr -d '\n' < "$TMP/xdebug-trigger"; printf '"\n'
} > "$TMP/curlrc"

curl -K "$TMP/curlrc" -sS -o /dev/null -w '%{http_code}\n' \
  https://preprod.cp-ghostotof.com/api/watch
```

Une requête sans ce cookie — ou avec une mauvaise valeur — n'écrit rien et ne
coûte rien : c'est la propriété que le secret achète.

### Récupérer, lire, puis oublier les profils

Lister puis rapatrier. **`kubectl cp` passe par `exec` + `tar`** : il faut donc
un kubeconfig d'**administrateur humain**, pas celui du déployeur CI, dont le
`Role` n'a plus `pods/exec` depuis le point d'audit C8. `tar` est bien présent
dans l'image (`/bin/tar`, GNU tar, fourni par le socle Alpine).

```bash
POD=$(kubectl get pod -n preprod -l app.kubernetes.io/name=backend \
        -o jsonpath='{.items[0].metadata.name}')
kubectl exec -n preprod "$POD" -c php-fpm -- ls -l /var/www/backend/var/profiler
kubectl cp -c php-fpm "preprod/$POD:var/www/backend/var/profiler" ./profils
```

Le chemin source est écrit **sans le `/` initial** : avec un chemin absolu,
`tar` avertit qu'il le supprime, et le fichier atterrit de toute façon au même
endroit — autant éviter l'avertissement.

Lecture : `kcachegrind` (KDE) ou `qcachegrind` (Qt, multiplateforme), ou
`webgrind` si on préfère un navigateur.

Durée de vie : le répertoire est un **`emptyDir`**, il disparaît avec le pod —
au prochain déploiement, au prochain `rollout restart`, à la première
éviction. **C'est voulu** : un profil décrit la structure interne du code, on
ne le laisse pas s'accumuler indéfiniment sur un environnement accessible. Le
`sizeLimit: 256Mi` borne ce qu'un profilage lancé en boucle peut consommer sur
le stockage éphémère du nœud. Corollaire : ce qu'on veut garder, on le
rapatrie tout de suite. Et si la préprod tournait un jour à plus d'un replica,
le profil se trouve sur le pod **qui a servi la requête**, pas forcément le
premier de la liste.

### Désarmer, faire tourner le secret

**Désarmer** (fin d'une campagne de profilage, doute sur la valeur) : pousser
une nouvelle version **courte**, et laisser le garde du template faire le
reste — moins de 32 caractères et il écrit `XDEBUG_MODE=off`, ce qui referme
l'extension sans toucher à un manifeste.

```bash
printf 'off' > "$TMP/xdebug-trigger"
scw secret version create <secret-id> data=@"$TMP/xdebug-trigger" region=fr-par
kubectl annotate externalsecret backend-xdebug-trigger -n preprod \
  force-sync=$(date +%s) --overwrite
kubectl rollout restart deployment/backend -n preprod
```

**Faire tourner** la valeur : même enchaînement que la rotation du Basic Auth
(§2bis) — nouvelle version du secret existant (`scw secret secret create`
échoue sur un nom déjà pris), `force-sync`, **`rollout restart` du backend**
(indispensable ici, contrairement au Basic Auth), puis désactivation des
anciennes révisions (`scw secret version disable <secret-id> revision=<n>`),
et destruction du répertoire temporaire. Quand : après chaque session de
profilage si la valeur a circulé, et sans délai si elle a pu apparaître dans un
transcript ou un journal.

## Journal de sécurité (Monolog, canaux `security` et `security_audit`)

Depuis le 3e audit (2026-09-16, constat A5, plan phase 3), le backend émet ses événements de
sécurité en JSON sur stderr, à partir du niveau `info`, sur deux canaux : `security` (celui de
Symfony : « Authenticator failed », etc.) et `security_audit` (le nôtre, `SecurityAuditLogger` :
`login-failed`, `login-throttled`, `csrf-rejected`, `backoffice-access-denied`, `user-invited`,
`role-changed`, `user-deleted`, `account-activated`…). Le reste suit `LOG_LEVEL` (`warning` par
défaut, `debug` dans l'image préprod). Chaque ligne `security_audit` porte `event`, l'identifiant
visé (`user`, `userId`), l'auteur (`actor`), `ip` et `path` — jamais un mot de passe, un jeton ni
un e-mail (un test le pince).

Lecture sur un pod, canal d'audit seulement, une ligne lisible par événement :

```bash
kubectl -n preprod logs deploy/backend -c php-fpm --tail=500 \
  | grep '"channel":"security_audit"' \
  | jq -r '[.datetime, .context.event, .context.user // "-", .context.actor, .context.ip, .context.path] | @tsv'
```

Vérification après un déploiement (checkpoint 3 du plan) : un `POST /api/login_check` erroné
contre la préprod doit produire une ligne `login-failed` avec l'IP publique de l'appelant (pas
`100.64.x.x` ni celle du LB, cf. ADR 0005 D6/D7), et le sixième essai une ligne `login-throttled`.
Les logs de pod ne sont conservés que par Kubernetes (rotation locale, perdus au remplacement du
pod) : la rétention et le statut RGPD de ces lignes sont traités dans `docs/rgpd/` (plan, T5.8).

## Durcissement des pods (jeton de ServiceAccount et Pod Security Admission)

Deux changements posés le 2026-09-21 (3e audit, constat A17, tâche T5.6). Ils
sont **déclaratifs et versionnés** — aucune commande à jouer en routine —, mais
le premier déploiement qui les emporte **redémarre tous les workloads**, y
compris Postgres et RabbitMQ. La validation en préprod décrite plus bas n'est
donc pas facultative.

### `automountServiceAccountToken: false` sur les dix pod specs

Par défaut, Kubernetes monte le jeton du ServiceAccount `default` dans
`/var/run/secrets/kubernetes.io/serviceaccount` de **chaque** conteneur. Un
attaquant qui obtient l'exécution de code dans un pod y trouve une identité
utilisable contre l'API du cluster, gratuitement.

Aucun pod de ce dépôt n'en a l'usage — vérifié plutôt que supposé :

- aucun pod spec ne déclare de `serviceAccountName` : tous tournent sous
  `default`, dont le RBAC est vide ;
- aucune image ne contient `kubectl` ni de client Kubernetes (ni dans
  `composer.json`, ni dans `package.json`, ni dans `docker/`), et rien ne lit
  `/var/run/secrets/kubernetes.io` ni `KUBERNETES_SERVICE_HOST` ;
- RabbitMQ est un **nœud seul** (`replicas: 1`, aucune configuration de
  cluster, aucun `enabled_plugins` monté) : il n'utilise pas
  `rabbit_peer_discovery_k8s`, le seul mécanisme par lequel ce broker
  interroge l'API ;
- External Secrets Operator est le seul composant qui parle réellement à
  l'API, et il tourne dans son **propre** namespace (`external-secrets`) avec
  son propre ServiceAccount : les `SecretStore`/`ExternalSecret` d'ici sont
  des objets qu'il lit, pas des pods à nous ;
- le ServiceAccount `github-actions-deployer` est porté par le pipeline (un
  kubeconfig), jamais par un pod.

Le champ est posé au niveau du **pod spec** (`spec.template.spec`, et
`spec.jobTemplate.spec.template.spec` pour les CronJob), pas sur le
ServiceAccount `default` que ce dépôt ne gère pas. Les dix objets concernés :
`backend`, `worker`, `frontend`, `postgres`, `rabbitmq`, `adminer`, les
CronJob `watch-refresh` et `contact-failed-messages-purge`, **et les deux Jobs
hors kustomize** `migrate-job.yaml` / `seed-job.yaml` — ces derniers ne passent
par aucun transformateur, ils ont donc été modifiés directement.

### Labels Pod Security Admission sur les deux namespaces

Portés par `k8s/overlays/{preprod,prod}/namespace.yaml`, donc **appliqués par
le pipeline** : le `ClusterRole` du déployeur a `namespaces: [get, patch]`
restreint par `resourceNames: [preprod, prod]`, ce qui suffit exactement à
poser des labels sur un namespace existant.

| Mode | Profil | Effet |
|---|---|---|
| `enforce` | `baseline` | un pod non conforme est **refusé** à la création |
| `audit` | `restricted` | consigné dans le journal d'audit du control-plane |
| `warn` | `restricted` | avertissement renvoyé à l'appelant de l'API |

**Pourquoi `enforce: baseline` et pas `restricted`.** RabbitMQ
(`k8s/base/rabbitmq.yaml`) ne déclare aucun `securityContext` de conteneur : il
viole trois contrôles `restricted` — `runAsNonRoot` absent,
`allowPrivilegeEscalation` pas à `false`, `capabilities.drop` ne contenant pas
`ALL`. C'est le constat A18, **assumé** : la release v0.5.0 a mis ce broker en
CrashLoopBackOff en production en touchant justement à son utilisateur
d'exécution (cookie Erlang, cf. l'en-tête du manifeste). `enforce: restricted`
reproduirait l'incident en pire — le pod ne serait même plus admis. Tous les
autres workloads, Jobs et CronJob compris, satisfont déjà `restricted` : quand
RabbitMQ sera durci, le passage d'`enforce` à `restricted` sera un simple
changement de mot.

**Pourquoi `-version: latest`.** La version du control-plane n'est écrite nulle
part dans ce dépôt et Kapsule est managé (Scaleway le monte de version sans
préavis) : épingler une valeur inventée figerait la politique sur un état qui
n'est peut-être pas celui du cluster. Contrepartie assumée : une version de
Kubernetes qui ajouterait un contrôle à `baseline` pourrait faire refuser un
pod au premier déploiement suivant la mise à jour. La variante recommandée par
Kubernetes, une fois la version connue (`kubectl version`), est d'épingler
`enforce-version` sur la mineure courante et de laisser `audit`/`warn` sur
`latest` — pour ces deux-là, `latest` est justement ce qu'on veut : un nouveau
contrôle doit être **signalé**, pas masqué.

### Validation en préprod, exigée avant promotion en prod

Les deux changements modifient le pod template de chaque Deployment : le
prochain `kubectl apply -k` **recrée tous les pods**. Postgres et RabbitMQ sont
en `strategy: Recreate` (leur PVC est `ReadWriteOnce`, deux pods ne peuvent pas
le partager) : l'ancien pod est arrêté **avant** que le nouveau démarre, donc
base et broker sont franchement indisponibles le temps du redémarrage. Rien ici
ne touche à un uid, un gid, un `fsGroup` ni à un mode de fichier — ce n'est donc
pas le mécanisme de l'incident v0.5.0 —, mais la règle du projet (« Postgres et
RabbitMQ portent leur état sur un PVC, un `apply --dry-run=server` ne prouve
rien ») s'applique telle quelle.

Ce à quoi s'attendre : les messages Messenger en file survivent (queue AMQP
durable, messages persistants, PVC intact) et le `failure_transport` est en
base (`doctrine://default?queue_name=failed`), pas dans le broker ; le worker
perd sa connexion, `messenger:consume` sort, le conteneur est relancé et un
message non acquitté est redélivré. Côté Postgres, les connexions en cours sont
coupées : quelques requêtes peuvent répondre 500 pendant la reprise.

Dans l'ordre, avec un kubeconfig **d'administrateur**, sur la préprod, **avant**
de fusionner la release :

1. **Hors ligne.** Le rendu doit passer et les dix pod specs porter le champ :
   ```bash
   kubectl kustomize k8s/overlays/preprod >/dev/null && echo rendu-ok
   kubectl kustomize k8s/overlays/preprod | grep -c 'automountServiceAccountToken: false'   # 8 attendu
   grep -c 'automountServiceAccountToken: false' k8s/base/migrate-job.yaml k8s/base/seed-job.yaml
   ```
2. **Lire les avertissements sans rien bloquer.** Poser d'abord `audit` et
   `warn` seuls. Au moment où un label PSA est posé ou modifié, l'API évalue
   les pods **déjà présents** et renvoie les violations en avertissements :
   c'est la vérification la plus utile, et `--dry-run=server` permet de la
   faire sans rien changer.
   ```bash
   # Simulation : liste les pods existants qui violeraient chaque profil.
   kubectl label --dry-run=server --overwrite namespace preprod \
     pod-security.kubernetes.io/enforce=restricted     # doit signaler rabbitmq
   kubectl label --dry-run=server --overwrite namespace preprod \
     pod-security.kubernetes.io/enforce=baseline       # ne doit RIEN signaler
   # Pose réelle des deux modes non bloquants.
   kubectl label --overwrite namespace preprod \
     pod-security.kubernetes.io/audit=restricted \
     pod-security.kubernetes.io/audit-version=latest \
     pod-security.kubernetes.io/warn=restricted \
     pod-security.kubernetes.io/warn-version=latest
   ```
   La simulation `baseline` **doit** être muette. Si elle ne l'est pas, ne pas
   poser `enforce` : c'est qu'un workload a dérivé depuis la dernière revue.
3. **Poser `enforce`** seulement après :
   ```bash
   kubectl label --overwrite namespace preprod \
     pod-security.kubernetes.io/enforce=baseline \
     pod-security.kubernetes.io/enforce-version=latest
   kubectl get namespace preprod -o jsonpath='{.metadata.labels}' | tr ',' '\n'
   ```
4. **Déployer la release sur la préprod** (push sur `release/*`), puis attendre
   chaque workload — y compris ceux que la pipeline n'attend pas elle-même :
   ```bash
   for d in postgres rabbitmq backend worker frontend; do
     kubectl -n preprod rollout status deployment/$d --timeout=180s
   done
   ```
   > **La pipeline attend désormais aussi les workloads à état.** Après
   > `kubectl apply -k .`, `deploy-preprod` et `deploy-prod` font le
   > `rollout status` de `postgres`, `rabbitmq` et `worker` en plus de
   > `backend`/`frontend`. Sans cela, `backend-seed` (`backoffLimit: 0`)
   > partait pendant que Postgres, en `Recreate`, n'était pas encore revenu
   > (détachement puis rattachement du PVC) et échouait sur une connexion
   > refusée ; et en production un RabbitMQ qui ne remonte pas laissait le
   > déploiement vert — le scénario de l'incident v0.5.0. Un pod template de
   > Postgres ou de RabbitMQ qui change, c'est donc une courte indisponibilité
   > franche de l'API (l'ancien pod s'arrête avant que le nouveau démarre) :
   > à savoir avant de promouvoir.
5. **Lire les journaux des deux workloads à état** — c'est là que se verrait un
   refus de démarrage sur volume déjà initialisé :
   ```bash
   kubectl -n preprod logs deployment/rabbitmq --tail=100   # « Server startup complete »
   kubectl -n preprod logs deployment/postgres --tail=100   # « database system is ready to accept connections »
   ```
6. **Vérifier que le jeton n'est plus monté** dans un pod quelconque :
   ```bash
   POD=$(kubectl -n preprod get pod -l app.kubernetes.io/name=backend -o name | head -1)
   kubectl -n preprod get "$POD" \
     -o jsonpath='{.spec.volumes[*].name}{"\n"}' | tr ' ' '\n' | grep kube-api-access || echo "aucun volume de jeton — attendu"
   ```
   Le volume projeté `kube-api-access-xxxxx` ne doit plus exister, et donc
   `/var/run/secrets/kubernetes.io/serviceaccount` non plus.
7. **Enfin seulement**, fusionner la release : `deploy-prod` posera les mêmes
   labels et rejouera le même redémarrage sur la prod. Attention, la pipeline
   n'attend en prod que `backend` et `frontend` — surveiller `postgres`,
   `rabbitmq` et `worker` à la main avec la boucle de l'étape 4 sur `prod`.

### Retour arrière

- **Un pod refusé par `enforce`** : retirer le seul label bloquant remet tout
  en ordre immédiatement, les deux autres modes ne bloquent rien.
  ```bash
  kubectl label namespace preprod pod-security.kubernetes.io/enforce-
  kubectl label namespace preprod pod-security.kubernetes.io/enforce-version-
  ```
  Correctif temporaire : le prochain `kubectl apply -k` du pipeline les
  **repose**. Pour que le retrait tienne, il faut aussi les enlever de
  `k8s/overlays/<env>/namespace.yaml` et déployer.
- **`automountServiceAccountToken`** n'a pas d'équivalent à chaud : il vit dans
  le pod template, un `kubectl patch` serait écrasé au déploiement suivant. Le
  retour arrière est un revert du commit puis un déploiement — avec, là encore,
  un redémarrage de tous les workloads.

## Limites connues (acceptables pour un projet portfolio, à retravailler sinon)

- Postgres et RabbitMQ tournent en pod (1 réplique, PVC) plutôt que sur des
  services managés Scaleway : pas de sauvegarde automatique.
- Pod Security Admission est posé (cf. « Durcissement des pods » ci-dessus)
  mais `enforce` est à `baseline`, pas à `restricted` : **RabbitMQ** est le
  seul workload qui reste en deçà (aucun `securityContext` de conteneur —
  `runAsNonRoot`, `allowPrivilegeEscalation: false` et
  `capabilities.drop: [ALL]` tous absents), constat A18 assumé après
  l'incident v0.5.0. `restricted` reste évalué en `audit`/`warn`, donc la
  dérive est visible. Le durcir demande de gérer explicitement le cookie
  Erlang (initContainer qui le régénère et le `chmod`) et un vrai rollout de
  préprod.
- `readOnlyRootFilesystem` manque aussi sur postgres et rabbitmq (leurs images
  officielles écrivent hors du PVC). Ce n'est **pas** un contrôle PSA — ni
  `baseline` ni `restricted` ne l'exigent —, donc aucun label ne le signalera :
  c'est un durcissement à faire à la main le jour venu.
- Deux bootstraps manuels `kubectl create secret` (hors ESO, cf. section
  "Prérequis cluster" ci-dessus) : `scaleway-eso-auth` (auth ESO elle-même,
  forcément hors du système qu'elle authentifie) et `cv-pdf` (dépasse la
  limite de 64 Ko par version de Secret Manager). Documentés, mais restent
  des étapes manuelles à ne pas oublier
  lors d'un rebuild de cluster.
