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
