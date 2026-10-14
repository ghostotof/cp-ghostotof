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
   **Sans le binaire `kustomize`**, avec le kustomize intégré à `kubectl` (la
   procédure jouée le 2026-10-10 pour #353). Ajouter la ligne `namespace:` au
   `kustomization.yaml` jetable revient au même que `kustomize edit set
   namespace`. On nomme le contexte de chaque namespace, et on lit le
   `kubectl diff` avant tout `apply` : il ne doit montrer que le changement
   attendu, jamais un `ClusterRoleBinding` ni ses `subjects`.
   ```bash
   for NS in preprod prod; do
     kubectl config use-context "cp-ghostotof-$NS"
     TMP=$(mktemp -d)
     cp k8s/base/github-actions-rbac/*.yaml "$TMP/"
     printf 'namespace: %s\n' "$NS" >> "$TMP/kustomization.yaml"
     kubectl diff -k "$TMP"     # lire avant de continuer
     kubectl apply -k "$TMP"    # seulement si le diff est conforme
     rm -rf "$TMP"
   done
   ```
   En pratique, on joue ces lignes une à une, un namespace après l'autre, en
   lisant le diff avant l'`apply`. La boucle les résume, elle ne s'utilise pas
   telle quelle.
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

   > **⚠ À rejouer après l'issue #353.** Le `Role` a gagné `get`/`list` sur
   > `events` (lecture seule) : `tools/wait-rollout.sh` y lit les
   > `FailedMount` d'un pod qui attend ses volumes, seule trace d'un
   > Secret ou d'un ConfigMap absent monté en volume. Relancer la boucle
   > ci-dessus avant la release qui embarque #353 (fait le 2026-10-10, en
   > préprod puis en prod, par la variante sans `kustomize`). Oublier ne casse **pas**
   > le déploiement — le script émet un `::warning::` « lecture des
   > événements … refusée » et continue —, mais ce cas finit alors en délai
   > dépassé, sans cause. Vérification, chaque namespace dans son contexte :
   > ```bash
   > kubectl --context cp-ghostotof-preprod -n preprod auth can-i list events \
   >   --as=system:serviceaccount:preprod:github-actions-deployer   # yes attendu
   > kubectl --context cp-ghostotof-prod -n prod auth can-i list events \
   >   --as=system:serviceaccount:prod:github-actions-deployer      # yes attendu
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
   3. les deux runs d'une release **verts**, celui de la branche `release/*`
      (`deploy-preprod`) puis celui du merge dans `main` (`deploy-prod`) :
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
namespaces) et `k8s/overlays/{preprod,prod}/external-secrets.yaml` (un
`ExternalSecret` par Secret) sont **déjà commités** : ils déclarent *comment*
chaque Secret Kubernetes (`backend-secrets`, `backend-migration-secrets`,
`postgres-credentials`, `rabbitmq-credentials`, `jwt-keys`, `cv-pdf`) doit être rempli depuis Scaleway
Secret Manager, mais pas les valeurs elles-mêmes. `kubectl apply -k` suffit
donc désormais à redéployer un environnement complet — reste seulement à
alimenter Scaleway Secret Manager, une fois, à la main.

### 1. Clé d'API Scaleway dédiée à ESO (principe du moindre privilège)

Créer une **IAM Application** dédiée (pas votre clé de compte principale),
avec une policy limitée à `SecretManagerReadOnly` sur le projet concerné,
puis générer une clé API pour cette application (console Scaleway : IAM >
Applications). Reporter les deux valeurs obtenues :

> **⚠ Terminal séparé, valeurs par fichier — même règle que la §2bis.** La
> clé `SecretManagerReadOnly` lit *tous* les secrets du projet : une fois
> passée en argument (`--from-literal=…`, `-p '{"stringData":…}'`), elle
> reste dans `ps`, dans l'historique du shell et dans le transcript d'une
> session d'agent. Les recettes ci-dessous (reprises le 2026-09-22, reliquat
> du 3e audit, issue #239) la font entrer par `read -rs` — lecture silencieuse,
> rien sur la ligne de commande — dans un répertoire temporaire privé, puis
> `kubectl` lit ce fichier. `printf` est une commande interne du shell : la
> valeur n'apparaît dans les arguments d'aucun processus.

```bash
# projectId : identifiant de projet, ne permet rien à lui seul — écrit en clair
# dans k8s/base/secretstore.yaml.

# accessKey ET secretKey : le Secret bootstrap `scaleway-eso-auth` porte
# désormais DEUX clés (point d'audit C4). accessKey n'est pas un secret au sens
# IAM, mais dans un dépôt public elle désigne nommément l'identité qui a accès
# au Secret Manager : on ne la publie plus.
umask 077
TMP=$(mktemp -d)
read -rsp 'Access key : ' V && printf '%s' "$V" > "$TMP/access-key" && echo
read -rsp 'Secret key : ' V && printf '%s' "$V" > "$TMP/secret-key" && echo
unset V

for NS in preprod prod; do
  kubectl create secret generic scaleway-eso-auth -n $NS \
    --from-file=access-key="$TMP/access-key" \
    --from-file=secret-key="$TMP/secret-key"
done
rm -rf "$TMP"
```

> **Migration depuis un cluster existant** : le Secret ne portait que
> `secret-key`. Ajouter la clé manquante avant d'appliquer le nouveau
> `secretstore.yaml`, sinon le SecretStore passe en `NotReady` et les
> ExternalSecrets cessent de se rafraîchir. Le correctif JSON est assemblé par
> redirections (`--patch-file`), la clé ne passe par aucun argument :
>
> ```bash
> umask 077
> TMP=$(mktemp -d)
> read -rsp 'Access key : ' V && printf '%s' "$V" > "$TMP/access-key" && echo
> unset V
> { printf '{"stringData":{"access-key":"'; cat "$TMP/access-key"; printf '"}}'; } > "$TMP/patch.json"
>
> for NS in preprod prod; do
>   kubectl patch secret scaleway-eso-auth -n $NS --type merge --patch-file "$TMP/patch.json"
> done
> rm -rf "$TMP"
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
`scaleway-eso-auth` ci-dessus) redevient nécessaire. `kubectl create secret
docker-registry` n'accepte le mot de passe qu'en argument
(`--docker-password`), ce que la règle de la §2bis interdit : on construit
soi-même le `.dockerconfigjson` qu'il aurait produit — `{"auths":{"ghcr.io":
{"auth":"<base64 de identifiant:PAT>"}}}` — par redirections, et on le donne
par fichier à un Secret `generic` du même type :

```bash
umask 077
TMP=$(mktemp -d)
read -rsp 'PAT GitHub (read:packages) : ' V && printf '%s' "$V" > "$TMP/pat" && echo
unset V
{ printf 'ghostotof:'; cat "$TMP/pat"; } | base64 -w0 > "$TMP/auth"
{ printf '{"auths":{"ghcr.io":{"auth":"'; cat "$TMP/auth"; printf '"}}}'; } > "$TMP/config.json"

for NS in preprod prod; do
  kubectl create secret generic ghcr-registry -n $NS \
    --type=kubernetes.io/dockerconfigjson \
    --from-file=.dockerconfigjson="$TMP/config.json"
done
rm -rf "$TMP"
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
# preprod-backend-database-url (postgresql://cpg_app:<PASSWORD>@database:5432/cp_ghostotof?serverVersion=18&charset=utf8,
#   le rôle APPLICATIF, DML seulement — jamais le POSTGRES_USER de l'image, issue #575),
# preprod-backend-migration-database-url (postgresql://cpg_migrator:<PASSWORD>@…, le rôle
#   PROPRIÉTAIRE du schéma, lu par le seul Job de migration — les deux rôles et leurs
#   mots de passe sont créés par la procédure du § « Rôles PostgreSQL »),
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

**Assistant de carrière** (`<env>-backend-scaleway-ai-api-key` et
`<env>-backend-scaleway-ai-project-id`, spec 0005) — **à publier avant de
pousser la branche `release/*` qui livre l'assistant**. Un `ExternalSecret` qui
référence une clé absente laisse `backend-secrets` incomplet, et le Deployment
ne démarre pas : c'est la leçon de la phase 1 (clé Anthropic). Depuis #325, le
pipeline l'attrape avant tout dégât (voir « Garde du déploiement » ci-dessous) ;
publier d'abord reste la règle, la garde ne fait que transformer l'oubli en un
échec lisible.

1. **Une application IAM dédiée par environnement** (console : IAM >
   Applications), distincte de celle d'ESO (§1) et de celle du mailer. Une
   par environnement permet de les révoquer séparément. Noms en place depuis
   le 2026-10-04 : `cpg-career-assistant-{dev,preprod,prod}`, politiques
   `cpg-career-assistant-<env>-model-access`. Les applications et leurs
   politiques ne portent aucun secret : on peut les créer au CLI
   (`scw iam application create`, `scw iam policy create … rules.0.project-ids.0=<projet>`).
   La **clé**, elle, se génère dans la console (Applications > API keys) : sa
   partie secrète ne s'affiche qu'une fois, à la création, et ne doit passer
   ni par un terminal partagé ni par une session d'agent.
2. **Sa politique ne donne que l'inférence Generative APIs**, sur **un seul
   projet** : le jeu de permissions Generative APIs le plus restreint qui
   autorise l'appel aux modèles. À la date d'écriture, c'est
   `GenerativeApisModelAccess`. Vérifier le nom dans la console (IAM >
   Policies > Add rule), il peut changer. Pas de `FullAccess`, pas d'accès à
   l'organisation.
3. **L'identifiant de ce projet est le second secret.** Il entre dans le
   chemin des appels (`api.scaleway.ai/<projet>/v1`). Sans lui, l'API vise le
   projet par défaut de l'organisation, sur lequel la politique ne donne aucun
   droit : réponse **403**, que le bridge 0.13.0 affiche comme « Unknown
   error », et l'assistant répond 503. Ce n'est pas un secret au sens IAM. Il
   reste hors du dépôt public parce qu'il désigne le projet qui porte la
   facturation. Le lire dans la console (Project dashboard > Settings) ou par
   `scw account project list`.

Recette, dans un **terminal séparé** (jamais derrière un `!` dans une session
d'agent, cf. §1). Les valeurs entrent au clavier (la clé est masquée), sont
écrites **sans saut de ligne final** (`data=@fichier` pousse les octets tels
quels, et un saut de ligne casserait l'URL ou l'en-tête), et ne passent par
aucun argument. Le `curl` vérifie le couple clé + projet, et la présence du
modèle, **avant** toute publication : un échec arrête tout sans rien publier.
L'en-tête est lu depuis un fichier (`-H @fichier`), la clé ne figure donc pas
dans `ps`.

Le bloc entier se colle tel quel : il s'exécute dans `bash` par un *heredoc*,
quel que soit le shell interactif. Sous zsh, `read -p` ne veut pas dire
« prompt » (il lit un coprocessus : `read: -p: no coprocess`), et un `set -u`
collé directement reste actif dans le shell et casse le prompt. Les `read`
lisent `/dev/tty`, puisque l'entrée standard de `bash` est le heredoc. La
publication est **rejouable** : un secret déjà créé reçoit une nouvelle
version au lieu d'échouer sur un identifiant vide. Le dossier temporaire est
supprimé à la sortie, même sur une erreur ou un Ctrl-C.

```bash
bash <<'RECETTE'
set -euo pipefail
umask 077
MODEL=mistral-small-3.2-24b-instruct-2506
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
DEFAULT_PROJECT=$(scw account project list -o json | jq -r '.[] | select(.name=="cp-ghostotof") | .id')

# 1. Saisie.
for ENV in preprod prod; do
  read -rsp "Clé secrète Generative APIs ($ENV) : " V </dev/tty && echo
  [ -n "$V" ] || { echo "Clé vide pour $ENV : abandon." >&2; exit 1; }
  printf '%s' "$V" > "$TMP/$ENV-key"
  read -rp "Identifiant du projet ($ENV) [$DEFAULT_PROJECT] : " V </dev/tty
  printf '%s' "${V:-$DEFAULT_PROJECT}" > "$TMP/$ENV-project"
done
unset V

# 2. Contrôle AVANT publication. 403 : la politique ne porte pas sur ce
#    projet. 401 : la clé.
ok=true
for ENV in preprod prod; do
  { printf 'Authorization: Bearer '; cat "$TMP/$ENV-key"; printf '\n'; } > "$TMP/$ENV-header"
  code=$(curl -s -o "$TMP/$ENV-models.json" -w '%{http_code}' -H @"$TMP/$ENV-header" \
    "https://api.scaleway.ai/$(cat "$TMP/$ENV-project")/v1/models")
  echo "$ENV : HTTP $code"
  if [ "$code" != 200 ]; then ok=false
  elif jq -r '.data[].id' "$TMP/$ENV-models.json" | grep -qx "$MODEL"; then echo "$ENV : modèle disponible"
  else echo "$ENV : modèle $MODEL ABSENT"; ok=false
  fi
done
$ok || { echo "Contrôle en échec : rien n'a été publié." >&2; exit 1; }
read -rp "Publier les quatre secrets ? [o/N] " answer </dev/tty
[ "$answer" = o ] || { echo "Abandon : rien n'a été publié."; exit 0; }

# 3. Publication, rejouable.
publish() {
  local id
  id=$(scw secret secret list name="$1" region=fr-par -o json | jq -r '.[0].id // empty')
  [ -n "$id" ] || id=$(scw secret secret create name="$1" path=/ region=fr-par -o json | jq -r .id)
  scw secret version create "$id" data=@"$2" region=fr-par > /dev/null && echo "publié : $1"
}
for ENV in preprod prod; do
  publish "${ENV}-backend-scaleway-ai-api-key"    "$TMP/$ENV-key"
  publish "${ENV}-backend-scaleway-ai-project-id" "$TMP/$ENV-project"
done

# 4. Contrôle : les quatre noms existent. Les noms seuls, aucune valeur n'est lue.
scw secret secret list region=fr-par -o json | jq -r '.[].name' | grep scaleway-ai
RECETTE
```

La vérification en préprod pendant la release est décrite plus bas, dans
« Assistant de carrière : vérification pendant la release ».

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

**Garde du déploiement (issue #325).** `deploy-preprod` et `deploy-prod`
appliquent les seuls objets ESO de la release (`SecretStore`, `ExternalSecret`)
juste après `backend-config`, puis `tools/wait-external-secrets.sh` attend
jusqu'à 120 s que chacun soit synchronisé — **avant** la fenêtre de
maintenance, la migration et le rollout. « Synchronisé » veut dire `Ready=True`
**et** `status.syncedResourceVersion` de la génération courante : tant qu'ESO
n'a pas réconcilié une spec modifiée, le statut garde le `Ready=True` de la
précédente, et un simple `kubectl wait --for=condition=Ready` passerait au vert
précisément quand une release ajoute une clé. Un échec nomme l'`ExternalSecret`,
la raison d'ESO et les **noms** des clés distantes qu'il référence (le message
d'ESO, « could not get secret data from provider », ne dit pas laquelle
manque) ; aucune valeur n'est lue ni affichée. Fail-closed : rien n'a été migré
ni déployé, la release précédente reste en service.

Pour s'en remettre : publier la clé manquante (ci-dessus), puis relancer le job
en échec. Le script pose `force-sync` sur les `ExternalSecret` obligatoires non
prêts dès son premier tour, ce qui sort ESO de son backoff : sans cela, une
relance juste après la publication pouvait encore échouer.

Un `ExternalSecret` annoté `cp-ghostotof.com/deploy-gate: optional` ne bloque
pas : il est signalé en avertissement. Seul `backend-xdebug-trigger` l'est
(préprod, « Profilage Xdebug en préprod ») ; ne jamais poser cette annotation
sur un `ExternalSecret` dont un pod ou un Job a besoin pour démarrer. Le Role
déployeur a déjà `get`/`list`/`patch` sur `externalsecrets` : rien à rejouer
au §4.

Conséquence de cet ordre : les `ExternalSecret` d'une release sont appliqués
**même si sa migration échoue ensuite**, et les pods de la release précédente,
toujours en service, redémarrent sur ses Secrets. D'où la même discipline que
pour les colonnes (expand/contract, `.claude/rules/deploiement.md`) : **ne
jamais retirer ni renommer une clé de Secret dans la release qui cesse de la
lire**, seulement dans la suivante. Ajouter une clé est toujours sûr.
`rollback-preprod` ne joue `rollout undo` que si `deploy-preprod` a réussi : un
déploiement arrêté par cette garde n'a fait aucun rollout, il n'y a rien à
défaire.

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

## Assistant de carrière : vérification pendant la release

La préprod n'est déployée que depuis une branche `release/*` : l'assistant
(spec 0005, `POST /api/assistant/answers`) n'est donc vérifié en conditions
réelles qu'à la release qui le livre, **avant** le merge dans `main`. Les
secrets Scaleway doivent avoir été publiés avant le push de la branche (§2,
« Assistant de carrière »). Après le déploiement préprod de la branche :

1. **Les deux clés sont arrivées dans le Secret.** La commande lit les noms des
   clés, pas leurs valeurs :

   ```bash
   kubectl -n preprod get externalsecret backend-secrets
   # STATUS SecretSynced, READY True
   kubectl -n preprod get secret backend-secrets -o json | jq -r '.data | keys[]' | grep SCALEWAY_AI
   # SCALEWAY_AI_API_KEY et SCALEWAY_AI_PROJECT_ID
   ```

2. **Le sidecar sert la nouvelle `location`.** La ConfigMap nginx est hachée,
   sa modification déclenche donc un rollout (issue #18). Le vérifier quand
   même : un sidecar resté sur l'ancienne configuration ferait passer la
   requête par `location /`, avec la mise en tampon et la zone `publicapi`.

   ```bash
   kubectl -n preprod exec deploy/backend -c nginx -- nginx -T | grep -n assistant
   # attendu : les zones assistant/assistantconn et la location ^~ /api/assistant/
   ```

3. **Une question réelle, en flux**, depuis un compte de test `ROLE_TRUSTED`
   de la préprod (un compte sur invitation, jamais un compte de production), sur la
   page Assistant. Dans l'onglet Réseau des DevTools, la réponse doit être en
   `text/event-stream` et le texte doit s'afficher **au fil de l'eau**, pas
   d'un bloc à la fin. Un affichage d'un bloc signale un tampon resté actif
   quelque part (sidecar ou ingress).

4. **Le journal `ai_usage` l'a comptée**, sans aucun contenu :

   ```bash
   kubectl -n preprod logs deploy/backend -c php-fpm --since=10m \
     | grep '"channel":"ai_usage"' \
     | jq -c '.context'
   # attendu : {"outcome":"done","messageCount":1,"durationMs":…,"promptTokens":…,"completionTokens":…}
   ```

   Sur `"outcome":"error"`, lire `providerStatus` : **403**, la politique de
   la clé ne porte pas sur le projet de `SCALEWAY_AI_PROJECT_ID` ; **401**, la
   clé elle-même. Corriger dans Secret Manager, forcer la resynchronisation
   puis redémarrer (« Rotation / mise à jour d'un Secret » ci-dessous).

La production ne reçoit pas de question de test. Après le déploiement prod, le
point 1 suffit (`-n prod`), avec en plus le point 2 sur le sidecar de prod.

## Rotation / mise à jour d'un Secret

Mettre à jour la valeur dans Scaleway Secret Manager (nouvelle version) : ESO
la resynchronise sous 1h maximum. Pour forcer immédiatement :
`kubectl annotate externalsecret <nom> -n $NS force-sync=$(date +%s) --overwrite`,
suivi d'un `kubectl rollout restart deployment/backend -n $NS` pour que les
pods relisent la nouvelle valeur (les Secrets montés en `envFrom` ne sont pas
rechargés à chaud).

### Cas d'`APP_SECRET`

Le secret signe plus qu'une session : les cookies `XSRF-TOKEN`, les empreintes du journal de sécurité
(§ Journal de sécurité) et, depuis l'issue #444, **le MAC de chaque message Messenger mis en file**,
vérifié par le worker avant toute désérialisation. Chaque usage en dérive sa propre clé (issue #455) :
changer le `KEY_CONTEXT` d'une seule classe est une rotation ciblée de cet usage, sans toucher au
secret. Une rotation invalide les `XSRF-TOKEN` déjà émis : une écriture, déconnexion comprise, répond
403 ; l'interface ramène alors à la page de connexion, et un nouveau login remplace les cookies. Une enveloppe signée avec l'ancien secret est refusée par un worker qui a
le nouveau (échec de décodage définitif, droit dans `failed` sans réessai depuis l'issue #450, où
elle reste refusée et part avec la purge des 30 jours). Ordre, en période calme :

1. Vider les files : la file `async` est consommée par le worker ; lire `failed` et rejouer ou
   purger ce qui doit l'être (§ « File d'échec Messenger » ci-dessous : lecture par `kubectl exec`,
   rejeu et suppression par Job).
2. Publier la nouvelle version dans Secret Manager, puis
   `kubectl annotate externalsecret backend-secrets -n $NS force-sync=$(date +%s) --overwrite`.
3. Redémarrer ensemble : `kubectl rollout restart deployment/backend deployment/worker -n $NS`.
4. Relancer l'arrêt des workers par son Job, `k8s/base/messenger-stop-workers-job.yaml`
   (§ « File d'échec Messenger » ci-dessous) : le cache en base (`cache_items`)
   est signé sous une clé dérivée du secret (issue #468), et la ligne du signal de redémarrage,
   sans expiration, serait sinon refusée à chaque tour du worker, un `warning` par tour. La
   commande, `app:messenger:stop-workers`, la réécrit sous la nouvelle clé **et la relit** : le Job
   échoue si la valeur écrite ne se relit pas (issue #528 ; celle de Symfony,
   `messenger:stop-workers`, répondait `[OK]` même quand PostgreSQL refusait l'écriture). Un Job
   en échec ici : lire ses journaux (canal `cache`, « Failed to save key » ; base joignable en
   écriture depuis le pod ?), corriger, relancer — sans danger. Tant qu'il n'est pas vert, rien ne
   prouve que les workers s'arrêteront. Vert, il prouve l'écriture sous la clé du Job (les Secrets
   de l'étape 2) ; l'arrêt lui-même se voit au compteur `RESTARTS` du pod `worker`
   (`kubectl -n $NS get pods -l app.kubernetes.io/name=worker`), qui augmente d'un. Le reste du cache se perd et se reconstruit : les
   compteurs des limiteurs repartent de zéro, y compris à chaque alternance d'anciens et de
   nouveaux pods pendant le redémarrage.

**Perte acceptée** : entre l'étape 2 et la fin des deux redémarrages (le backend en `RollingUpdate`,
le worker en `Recreate`), un ancien pod d'API peut encore mettre en file un message signé avec
l'ancien secret, que le nouveau worker refusera — un message de contact ou une invitation, à
relancer. Seul le worker vérifie ; l'API ne décode jamais. Accepter l'ancienne clé en vérification
le temps d'une rotation éviterait cette perte : non fait, à reprendre si les rotations deviennent
fréquentes. Garder des secrets distincts en préprod et en prod, sans quoi un message capturé dans
l'une serait accepté par l'autre.

### Cas du `KEY_CONTEXT` des enveloppes Messenger

Changer `KEY_CONTEXT` dans `AuthenticatedEnvelopeSerializer` (passé de `…/v1` à `…/v2` par l'issue
#450) invalide, comme une rotation d'`APP_SECRET`, **tout** MAC d'enveloppe déjà émis : `async`, ses
files de délai et `failed`. Un refus de MAC est définitif (`EnvelopeMacRefusedException`, sans
réessai) et `messenger:failed:retry` ne le sauve pas. Ce n'est donc pas une « rotation ciblée » sans
conséquence. Avant le merge de la release :

1. Vider `async` et lire `failed`, rejouer ou purger ce qui doit l'être (étape 1 ci-dessus) : une
   ligne laissée là devient illisible.
2. Déployer avec la **fenêtre de maintenance** (`DEPLOY_MAINTENANCE_WINDOW=true`, cf.
   `.claude/rules/deploiement.md`) : `backend` et `worker` passent à 0 avant la migration et
   repartent ensemble sous la nouvelle clé. Sans elle, le backend en `RollingUpdate` garde un ancien
   pod qui signe sous l'ancienne clé pendant que le worker, en `Recreate`, exige déjà la nouvelle :
   un message de contact envoyé à ce moment est perdu (il n'existe que dans la file). Prix de la
   fenêtre : l'API répond 503 le temps du basculement, le frontend reste servi. Retirer la variable
   juste après le déploiement en prod.

## File d'échec Messenger (`failed`)

Un message dont les réessais sont épuisés, ou qui ne se décode pas (MAC refusé, invitation sans
génération), reste dans la table `messenger_messages` (`queue_name = failed`) jusqu'à son rejeu,
sa suppression ou la purge des 30 jours (messages de contact, `messenger-purge-cronjob.yaml`).
Quatre opérations, deux voies (issue #521, règle de #520 dans `.claude/rules/deploiement.md`) :

| Opération | Effet de bord | Ligne d'audit | Ce que la sortie expose | Voie |
|---|---|---|---|---|
| `messenger:failed:show` (liste, `--stats`, un Id) | aucun | aucune | classe, Id, dates, message d'erreur (expurgé, #356) | `kubectl exec` humain |
| `messenger:failed:show <id> -vv` | aucun | aucune | **le message lui-même** : nom, adresse et texte d'un visiteur ; `userId` d'une invitation ; corps brut d'une enveloppe non décodée | `kubectl exec` humain, terminal seulement |
| `messenger:failed:retry <id>` | le handler s'exécute (un e-mail part) | aucune (journaux du handler) | comme `show` sans `-vv` | Job |
| `messenger:failed:remove <id>` | suppression définitive | aucune | comme `show` sans `-vv` | Job |
| `app:messenger:stop-workers` (#528, à la place de `messenger:stop-workers`) | réécrit le signal de redémarrage en cache, puis le relit (échec si la valeur écrite ne se relit pas) | aucune | rien | Job |

Mesuré en dev (base de test, données fictives) : sans `-vv`, ni le nom, ni l'adresse, ni le texte
d'un message de contact, ni le `userId` d'une invitation n'apparaissent ; avec `-vv`, Messenger
vide le message par VarDumper, plus la trace de l'exception. Un Job écrirait cette sortie dans les
journaux de son pod, lisibles jusqu'à sa suppression, y compris par le jeton CI (`pods/log`) :
c'est pourquoi la lecture est un `exec`, et pourquoi les Jobs ne passent jamais `-v`.

Pour lire `failed`, depuis un **terminal séparé** (jamais derrière un `!` dans une session
d'assistant : en `-vv`, la réponse est une donnée personnelle), avec un **kubeconfig
d'administrateur humain** — le jeton CI n'a pas `pods/exec` (audit C8) :

```bash
NS=        # preprod ou prod, à renseigner : vide, la commande s'arrête avant d'appeler le cluster
# La liste : Id, classe, date d'échec, erreur. Aucun contenu de message. 50 lignes au plus par
# défaut : si l'en-tête « There are N messages » dépasse 50, relancer avec --max=N.
kubectl -n "${NS:?preprod ou prod}" exec deploy/backend -c php-fpm -- \
  env PGOPTIONS='-c default_transaction_read_only=on -c lock_timeout=5s' php bin/console messenger:failed:show --no-interaction
# Le compte par classe.
kubectl -n "${NS:?preprod ou prod}" exec deploy/backend -c php-fpm -- \
  env PGOPTIONS='-c default_transaction_read_only=on -c lock_timeout=5s' php bin/console messenger:failed:show --stats --no-interaction
# Un message en détail, Id pris dans la liste. -vv affiche le message lui-même : à garder sur ce
# terminal, jamais collé dans une issue, un ticket ou une session d'assistant.
kubectl -n "${NS:?preprod ou prod}" exec deploy/backend -c php-fpm -- \
  env PGOPTIONS='-c default_transaction_read_only=on -c lock_timeout=5s' php bin/console messenger:failed:show <id> -vv --no-interaction
```

- **Lecture seule, vérifiée.** `PGOPTIONS` est lu par libpq, donc par `pdo_pgsql` : toute la
  session PostgreSQL de la commande est en `default_transaction_read_only`. Mesuré en dev :
  `failed:show` (liste, `--stats`, `-vv`) y passe, un `failed:remove` y échoue en SQLSTATE 25006
  (« cannot execute DELETE in a read-only transaction »). `lock_timeout=5s` y borne les attentes
  de verrou comme dans les Jobs (#525 ; `PGOPTIONS` remplace la variable entière). Un garde-fou, pas une frontière, comme
  pour « nommer un compte » (§ Journal de sécurité) : la même identité peut tout faire. Mesuré en
  dev seulement : à confirmer à la première exécution en préprod (#521) — si la lecture y échoue en
  SQLSTATE 25006, une écriture annexe (un cache sur DBAL) s'est glissée dans la session, le signaler
  avant de retirer le garde-fou.
- **Le `userId` d'une invitation n'apparaît qu'en `-vv`** (dans le corps brut, pour une invitation
  sans génération qui ne se décode pas) : c'est la seule raison de passer `-vv` dans la procédure
  de `.claude/rules/security-user.md`.
- `deploy/backend -c php-fpm` : le conteneur a l'image, les Secrets et le cache système de la
  release qui sert ; la commande y tourne à côté de php-fpm, sans rien écrire en base.

Pour rejouer, supprimer ou relancer le signal d'arrêt, un Job, par `tools/run-job.sh` (bash ≥ 4,
jq, envsubst), depuis la racine du dépôt — **un checkout de la release qui tourne**
(`git switch --detach vX.Y.Z`, la version de l'image ci-dessous) : le manifeste lance une commande
de cette image, et `app:messenger:stop-workers` (#528) n'existe pas dans une image plus ancienne
(« Command "app:messenger:stop-workers" is not defined », exit 1). L'Id est celui de la liste **du même namespace** — chaque
base a sa propre séquence, l'Id 42 de la préprod n'est pas celui de la prod —, **un seul par Job**,
et le Job refuse en 64 tout ce qui n'est pas un entier (`--all` lu comme une option viderait la
table) :

```bash
NS=        # preprod ou prod, à renseigner, le même que pour la lecture de l'Id
: "${NS:?preprod ou prod}"
IMAGE="$(kubectl -n "$NS" get deploy backend -o jsonpath='{.spec.template.spec.containers[?(@.name=="php-fpm")].image}')"
# Rejouer : le handler s'exécute dans le pod du Job (un e-mail part) ; un nouvel échec revient dans
# `failed` sous un nouvel Id.
tools/run-job.sh "$NS" k8s/base/messenger-failed-retry-job.yaml "$IMAGE" 300 FAILED_MESSAGE_ID=<id>
# Supprimer définitivement.
tools/run-job.sh "$NS" k8s/base/messenger-failed-remove-job.yaml "$IMAGE" 300 FAILED_MESSAGE_ID=<id>
# Arrêter les workers (Kubernetes les relance), étape 4 de la rotation d'APP_SECRET. Le Job lance
# app:messenger:stop-workers, qui relit le signal écrit : vert, il est enregistré (#528).
tools/run-job.sh "$NS" k8s/base/messenger-stop-workers-job.yaml "$IMAGE" 300
```

**Jamais `failed:retry`** pour une enveloppe au MAC refusé ni une invitation sans génération :
elles échouent au décodage à chaque fois (lire le `userId`, renvoyer l'invitation depuis le
backoffice, puis supprimer la ligne). Chaque Job garde ses journaux une heure
(`ttlSecondsAfterFinished`), ne se rejoue pas (`backoffLimit: 0`) et échoue 360 s après sa
création quoi qu'il fasse (`activeDeadlineSeconds`, démarrage et commande compris) — **au-dessus**
des 300 s d'attente, exprès (#535) : un pod bloqué tourne encore quand l'attente expire, et ses
journaux s'impriment avec l'`::error::` ; à l'échéance, le contrôleur de Job supprime le pod,
journaux compris. Puis `run-job.sh` **arrête** un Job resté actif (ni `Complete` ni `Failed`),
en attendant la fin de ses pods (90 s au plus) : un `::error::` veut dire « arrêté », le Job
suivant peut partir. Si cet arrêt échoue, un second `::error::` le dit : le Job peut alors encore
aboutir jusqu'à son délai (un e-mail parti, une ligne supprimée) — attendre `Complete` ou `Failed`
dans `kubectl -n "$NS" get job/<nom du Job>`, et relire ses journaux, avant de relancer. **Un Job à la
fois** : `run-job.sh` supprime le Job précédent du même nom sans attendre sa fin — lancé depuis un
second terminal, il couperait un rejeu en plein envoi, et l'e-mail repartirait au rejeu suivant.
Pour plusieurs Ids, les enchaîner dans un même terminal ; les journaux d'un Job remplacé sont
perdus, sauf les 200 dernières lignes que `run-job.sh` a imprimées.

## Retour arrière applicatif

Il n'y a pas de retour arrière automatique en prod (`rollback-preprod` n'existe qu'en préprod) :
**préférer une correction en avant**, par une nouvelle branche de release. S'il faut défaire à la
main :

- **`backend` et `worker` ensemble, toujours** :
  `kubectl -n $NS rollout undo deployment/backend`, puis `deployment/worker`, puis
  `deployment/frontend`. Le worker lit ce que l'API met en file, sous la même clé de MAC : défaire
  l'un sans l'autre (une clé `messenger-envelope/v2` face à `v1`) envoie chaque message dans
  `failed`, sans rattrapage. Lire `failed` ensuite (§ « File d'échec Messenger » ci-dessus).
- **Jamais vers une version antérieure à #450 (v0.23.0 et avant)** sans l'avoir décidé : ces images
  acceptent encore une enveloppe **sans** MAC (mode transition de #444) et rouvrent l'injection
  d'objet par la file tant qu'elles tournent. Au redéploiement suivant, ce qu'elles ont mis en file
  sans MAC est refusé.
- `rollout undo` ne rejoue jamais le `down()` d'une migration : le schéma reste celui de la release
  défaite (`.claude/rules/deploiement.md`, migration avant le rollout).

## Rôles PostgreSQL (issue #575)

Le rôle que l'image crée (`POSTGRES_USER`, Secret `postgres-credentials`) est
le **superutilisateur bootstrap** de l'instance, propriétaire des tables.
Jusqu'à #575, `DATABASE_URL` le réutilisait : l'application, le worker, les
CronJobs et le Job de migration tournaient tous sous lui, et les gardes de la
base — le trigger différé des groupes de traduction (#390, #501, #503), la
contrainte `CHECK` des durées (#372) — ne tenaient que tant que personne ne
les désactivait depuis l'application (`ALTER TABLE … DISABLE TRIGGER`, `SET
session_replication_role`, `COPY … PROGRAM`…). Trois rôles sans aucun de ces
pouvoirs les remplacent :

| Rôle | Pouvoirs | Qui l'utilise | Où est son URL |
|---|---|---|---|
| `cpg_app` | `SELECT, INSERT, UPDATE, DELETE` sur chaque table, `USAGE` sur chaque séquence ; ni `TRUNCATE`, ni DDL, ni propriété ; pas la table des migrations | FPM, worker, CronJobs, Jobs applicatifs (seed, création de compte, témoin, `failed`, arrêt des workers) | `backend-secrets.DATABASE_URL` ← `<env>-backend-database-url` |
| `cpg_migrator` | propriétaire de la base, du schéma `public` et de chaque objet ; les privilèges par défaut de ce qu'il crée vont à `cpg_app` | le seul Job de migration (`migrate-job.yaml`, `env` `DATABASE_URL`) | `backend-migration-secrets.DATABASE_URL` ← `<env>-backend-migration-database-url` |
| `cpg_reader` | `pg_read_all_data`, aucun mot de passe : joignable depuis l'intérieur du pod seulement (socket local et `127.0.0.1`, en `trust`), refusé par le réseau (`pg_hba.conf` du dépôt, #593) | les `psql` humains de ce runbook | — |

Le SQL est versionné, `k8s/base/postgres-roles/{roles,privileges,verify}.sql`,
et joué en une seule transaction, rejouable, par un `kubectl exec` dans le pod
`postgres` : `psql` sur le **socket local**, sous le rôle bootstrap. Depuis
l'issue #593, c'est le seul chemin de ce rôle : le `pg_hba.conf` du dépôt
(`k8s/base/postgres-pg_hba.conf`) le refuse par le réseau, et `pg_hba` ne sait
pas distinguer un pod d'un autre — un Job (`postgres-roles-job.yaml`, retiré)
n'aurait plus de quoi se connecter. C'est l'**unique exception écrite** à
« un effet de bord passe par un Job » (#520) : une commande, dans cette
section, bornée par `OperatorExecBoundaryTest` et pincée par
`PostgresRolesProcedureTest`. En échange, les mots de passe ne traversent plus
le réseau du cluster (flux de l'exec, puis socket local), et aucun Secret ni
ConfigMap temporaire n'est créé ; la sortie de `verify.sql` est sur le terminal
de l'opérateur, pas dans `kubectl logs`. `privileges.sql` est aussi rejoué par
`ApplicationRolePrivilegesTest` sur le schéma migré de la base de test, à
chaque run de la suite : un `GRANT` qu'une table nouvelle attendrait, ou un
pouvoir rendu au rôle applicatif, rougit la CI avant la release. Le rôle
bootstrap reste pour l'image (entrypoint, sondes `pg_isready`) et pour cette
procédure, de l'intérieur du pod seulement (« Limites connues », #593).

**Quand.** Hors déploiement, à la main, **préprod puis prod** : à la création
d'un environnement (ci-dessous), pour une **rotation** des mots de passe, pour
rattraper un **objet créé à la main** sous le superutilisateur, ou pour
ramener les trois rôles à leurs attributs. Rien ne le rejoue en CI : l'identité
du pipeline n'a pas `pods/exec`, et ce n'est pas un oubli.

**Terminal séparé, jamais derrière un `!` dans une session d'agent**, mêmes
règles qu'au §2bis : les mots de passe naissent dans des fichiers d'un
répertoire privé et n'en sortent que par l'entrée standard de `psql` (un
`cat` qui ne reçoit que le chemin) et par `data=@fichier` ; rien dans un
argument, rien à l'écran. Depuis la racine d'un checkout à jour, dont le SQL
est celui de `HEAD` (`git diff --quiet HEAD -- k8s/base/postgres-roles` doit
être muet). `NS` sans valeur par défaut, comme partout ici.

Pour créer ou rejouer les rôles :

```bash
# Namespace : preprod, puis prod. Vide, rien ne part.
NS=
: "${NS:?preprod ou prod}"
# Rotation : 1 réécrit les mots de passe de cpg_app et cpg_migrator (ci-dessous).
ROTATE=0
umask 077
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
# 1. Mots de passe (hexadécimal : aucun caractère à encoder dans une URL) et URL, par fichier.
#    Ignorés par roles.sql pour un rôle qui existe déjà, sauf ROTATE=1.
openssl rand -hex 24 | tr -d '\n' > "$TMP/app-password"
openssl rand -hex 24 | tr -d '\n' > "$TMP/migrator-password"
DB="$(kubectl -n "${NS:?preprod ou prod}" get secret postgres-credentials -o jsonpath='{.data.POSTGRES_DB}' | base64 -d)"
{ printf 'postgresql://cpg_app:'; cat "$TMP/app-password"; printf '@database:5432/%s?serverVersion=18&charset=utf8' "$DB"; } > "$TMP/app-url"
{ printf 'postgresql://cpg_migrator:'; cat "$TMP/migrator-password"; printf '@database:5432/%s?serverVersion=18&charset=utf8' "$DB"; } > "$TMP/migrator-url"
# 2. Les fichiers du checkout, une transaction, sur le socket local du pod postgres.
#    Mots de passe et rotation en tête de l'entrée standard : jamais dans l'argv de l'exec.
#    BEGIN en tête du flux, commit.sql en dernier : il ne valide que si verify.sql est allé
#    au bout (pas --single-transaction, qui validait un flux coupé ou incomplet).
#    PGOPTIONS fixe la session dès la connexion, devant tout réglage de la base (#608).
{
  printf 'BEGIN;\n'
  printf '\\set app_pw '; cat "$TMP/app-password"; printf '\n'
  printf '\\set migrator_pw '; cat "$TMP/migrator-password"; printf '\n'
  printf '\\set rotate %s\n' "$ROTATE"
  cat k8s/base/postgres-roles/roles.sql k8s/base/postgres-roles/privileges.sql k8s/base/postgres-roles/verify.sql k8s/base/postgres-roles/commit.sql
} | kubectl -n "${NS:?preprod ou prod}" exec -i deploy/postgres -c postgres -- \
  sh -c 'PGOPTIONS="-c lock_timeout=5s -c idle_in_transaction_session_timeout=30s -c statement_timeout=0 -c transaction_timeout=0 -c idle_session_timeout=0 -c default_transaction_read_only=off -c role=none -c search_path=pg_catalog,pg_temp -c password_encryption=scram-sha-256" psql -X -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v app_role=cpg_app -v migrator_role=cpg_migrator -v reader_role=cpg_reader -f -'
```

Chaque `ALTER … OWNER TO` prend un verrou `ACCESS EXCLUSIVE` tenu jusqu'au
COMMIT de la transaction unique (quelques secondes, `verify.sql` compris) :
les requêtes de l'API sur ces tables attendent ce temps-là, et un
interblocage avec le trafic (`40P01`) annule l'une des deux transactions —
la procédure, à relancer, ou une écriture du backoffice, un 500 pour son
auteur. Une heure calme, donc. Lire la sortie de `verify.sql` sur le
terminal : trois rôles, `rolsuper` `f` partout, le lecteur sans mot de passe,
le rôle de migration propriétaire de la base et du schéma, `0` objet de
`public` hors de lui, `0` table sans DML pour `cpg_app`, `f | f | f`, `2`
privilèges par défaut, `t | f` pour le lecteur, le `pg_hba.conf` actif
(`/etc/postgresql/pg_hba.conf`, `0`, `0`, `reject` — #593), les bases de
l'image fermées (`t | f | 0 | 0` — #597 : refus avant toute règle qui
accepte, base de l'application au nom non réservé, aucune base de plus,
plus de `CONNECT`/`TEMPORARY` à `PUBLIC` sur `postgres` et `template1` ; une
base créée à la main, une restauration, fait donc échouer la procédure tant
qu'elle existe : la supprimer, ou décider de l'admettre, avant de relancer),
la base de l'application (`{TimeZone} | 0 | -1 | f | 3` — #608 : le fuseau
seul réglage de base, vide tant que `Version20261011120000` n'est pas passée,
aucun réglage `ALTER ROLE … IN DATABASE`, pas de limite de connexions, pas un
modèle, `CONNECT` pour les trois rôles ; les noms des réglages, jamais leurs
valeurs), puis `conforme` et `COMMIT`. Un refus `#608` ne se répare pas par la
procédure, qui échoue tant que l'écart existe : aucun chemin écrit à ce jour
(issue #610). **Un écart fait échouer la commande** : `verify.sql` se termine
par un bloc qui lève une erreur sur chaque attente trahie, psql sort en 3
avant `commit.sql` et la session close annule tout — rien n'est à défaire,
lire la cause, corriger, relancer l'étape 2. **Sans `conforme` ni `COMMIT`,
rien n'est validé** : `commit.sql` ne valide que si `verify.sql` est allé au
bout — `verify.sql` absent du flux, erreur nommée, sortie 3 ; lancé hors de
la racine du checkout, `cat: …: No such file`, aucun fichier ni `COMMIT`, la
session close annule (sortie 0 possible : lire le `COMMIT`, pas le code ;
mesuré, #593). Un `55P03` est un `ALTER … OWNER TO` bloqué par une
transaction longue (une migration, un seed) : relancer hors déploiement. Des
mots de passe absents ou trop courts, ou un `ROTATE` qui ne vaut ni 0 ni 1,
sont refusés avant toute écriture.

**Lire la ligne `mot_de_passe_cpg_app_ecrit | mot_de_passe_cpg_migrator_ecrit`**
avant de publier quoi que ce soit : `t` pour un rôle créé ou une rotation,
`f` pour un rôle existant rejoué sans rotation — **ne jamais publier une URL
dont le mot de passe n'a pas été écrit**, la base ne le connaît pas et
l'application serait coupée à son redémarrage suivant.

**Une commande interrompue** (Ctrl-C, réseau, terminal fermé) **laisse un état
inconnu** : le flux, une vingtaine de Ko, est souvent déjà livré dans le pod,
et psql peut aller jusqu'au `COMMIT` sans que la sortie revienne. Un flux
coupé avant `commit.sql` n'écrit rien (mesuré, #593), et une transaction
laissée en attente est fermée au bout de 30 s (`idle_in_transaction_session_timeout`,
dans `PGOPTIONS`).
Relancer **la seule commande de l'étape 2, dans le même terminal** (mêmes
fichiers de `$TMP`, même `ROTATE`) — elle est rejouable, et ses mots de
passe sont ceux que la base a peut-être déjà — **jamais l'étape 1**, qui en
générerait d'autres. Le terminal a été fermé pendant une rotation (`$TMP`
perdu) : refaire les étapes 1 et 2 avec `ROTATE=1`. **Une fois les URL
publiées, ou si rien n'est à publier : `rm -rf "$TMP"`** — le `trap` ne
s'exécute qu'à la fermeture du terminal, et un second passage dans le même
terminal le remplace.

**Environnement neuf** (nouveau namespace, PVC perdu) : la procédure passe
**avant la première migration**, sur la base encore vide que l'image a
créée — `privileges.sql` et `verify.sql` ne supposent aucune table —, puis
la `DATABASE_URL` de migration est publiée, et le déploiement migre sous
`cpg_migrator`. Rejouer ensuite l'étape 2 (`ROTATE=0`) pour retirer à
`cpg_app` la table des migrations, que les privilèges par défaut lui avaient
ouverte.

Le même contrôle, plus tard, sans rien écrire, sous le lecteur (lecture seule,
sortie sur le terminal, requête sur l'entrée standard) :

```bash
NS=        # preprod ou prod, à renseigner : vide, la commande s'arrête avant d'appeler le cluster
kubectl -n "${NS:?preprod ou prod}" exec -i deploy/postgres -c postgres -- \
  sh -c 'PGOPTIONS="-c default_transaction_read_only=on -c lock_timeout=5s" psql -X -A -v ON_ERROR_STOP=1 -U cpg_reader -d "$POSTGRES_DB"' <<'SQL'
SELECT rolname, rolsuper, rolcanlogin FROM pg_roles WHERE rolname IN ('cpg_app', 'cpg_migrator', 'cpg_reader') ORDER BY 1;
SELECT count(*) AS objets_hors_migration FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p', 'v', 'm', 'S', 'i') AND pg_get_userbyid(c.relowner) <> 'cpg_migrator';
SELECT count(*) AS tables_sans_dml FROM information_schema.tables t
WHERE t.table_schema = 'public' AND t.table_type = 'BASE TABLE' AND t.table_name <> 'doctrine_migration_versions'
  AND NOT has_table_privilege('cpg_app', format('%I.%I', t.table_schema, t.table_name), 'SELECT,INSERT,UPDATE,DELETE');
SQL
# attendu : trois lignes avec rolsuper f, puis 0, puis 0.
```

**Le `pg_hba.conf` vu du réseau** (issue #593), à la release qui l'embarque,
préprod puis prod, après le rollout de `postgres` : `cpg_reader`, admis sans
mot de passe sur le loopback seulement, tente `database` — le Service — depuis
le pod `postgres` lui-même. La connexion sort par le réseau du cluster et
revient avec l'adresse du pod pour source : elle doit tomber sur la règle
`reject`, comme le superutilisateur depuis n'importe quel pod. Un `SELECT`
réussi voudrait dire que le serveur lit encore le fichier de l'image, ou que
le CNI présente la connexion comme venant de `127.0.0.1` : arrêter la release.
Lecture seule, aucun mot de passe en jeu (`-w` : psql n'en demande pas) :

```bash
NS=        # preprod ou prod, à renseigner : vide, la commande s'arrête avant d'appeler le cluster
echo 'SELECT 1;' | kubectl -n "${NS:?preprod ou prod}" exec -i deploy/postgres -c postgres -- \
  sh -c 'PGOPTIONS="-c default_transaction_read_only=on -c lock_timeout=5s" psql -X -w -v ON_ERROR_STOP=1 -h database -U cpg_reader -d "$POSTGRES_DB"'
# attendu : FATAL:  pg_hba.conf rejects connection for host "<adresse du pod>", user "cpg_reader", …
```

Le même refus, mesuré sur l'image épinglée pour chaque rôle et chaque voie
(réplication, loopback compris), est le job CI `postgres-hba`
(`tools/check-postgres-hba.sh`) ; dans le cluster, `verify.sql` relit aussi le
fichier actif à chaque passage de la procédure des rôles. **En préprod, la
pipeline le refait à chaque déploiement** (issue #596) : le job
`postgres-hba-preprod` lance par `tools/run-job.sh` le Job `postgres-hba-probe`
(image du serveur, sans mot de passe, seulement le nom du superutilisateur lu
dans `postgres-credentials`), qui tente `database` sous le superutilisateur, sur
la base de l'application puis sur `postgres`, et n'aboutit que sur deux
`pg_hba.conf rejects connection` (dernière ligne `#596 : OK`) — sinon le job
échoue et sa ligne `#596 : ÉCHEC (…)` dit pourquoi. **Cet échec ne déclenche
pas `rollback-preprod`** (issue #600) : le retour arrière ramènerait
l'application, qui n'est pas en cause, et jamais `postgres`, qui l'est. La
release reste donc en place contre le `postgres` à corriger, et la PR vers
`main` reste rouge (`postgres-hba-preprod` est un check requis du ruleset) :
corriger la release par un nouveau commit sur sa branche. Un serveur qui refuse
ou laisse expirer la connexion est réessayé quatre fois. Sa sonde,
`k8s/base/postgres-hba-probe.sh`, est recopiée à l'identique dans le `command`
du Job (`PostgresHbaProbeJobManifestTest`) ; elle masque le nom du
superutilisateur et de la base dans ses journaux. **En préprod, seul le sens
« refusé » se mesure** ; le sens « échec » (fichier de l'image, base `postgres`
rouverte, port fermé) est mesuré en CI contre l'image épinglée par
`tools/check-postgres-hba.sh`. En prod, il n'y a pas
de smoke : la commande ci-dessus reste celle de la release qui touche
`postgres.yaml` ou le `pg_hba.conf`.

**Publier les URL, puis basculer** — seulement celles dont le mot de passe a
été écrit (`t` ci-dessus) ; même recette que les secrets de l'assistant, §2 :
`publish` crée le secret ou y ajoute une version, `data=@fichier` :

```bash
publish() {
  local id
  id=$(scw secret secret list name="$1" region=fr-par -o json | jq -r '.[0].id // empty')
  [ -n "$id" ] || id=$(scw secret secret create name="$1" path=/ region=fr-par -o json | jq -r .id)
  scw secret version create "$id" data=@"$2" region=fr-par > /dev/null && echo "publié : $1"
}
publish "${NS}-backend-database-url"           "$TMP/app-url"        # nouvelle version : cpg_app
publish "${NS}-backend-migration-database-url" "$TMP/migrator-url"   # nouveau secret : cpg_migrator
# Publiées : les fichiers n'ont plus d'usage.
rm -rf "$TMP"
# Les pods relisent backend-secrets au redémarrage seulement ; backend et worker ensemble (§ Retour
# arrière applicatif). backend-migration-secrets n'a pas de pod : le Job de migration le lira.
kubectl -n "$NS" annotate externalsecret backend-secrets force-sync="$(date +%s)" --overwrite
kubectl -n "$NS" get externalsecret backend-secrets -o jsonpath='{.status.conditions[?(@.type=="Ready")].status} {.status.refreshTime}{"\n"}'
kubectl -n "$NS" rollout restart deployment/backend deployment/worker
kubectl -n "$NS" rollout status deployment/backend --timeout=180s
kubectl -n "$NS" rollout status deployment/worker --timeout=180s
# Un privilège manquant se lit en SQLSTATE 42501 dans les journaux : 0 attendu.
kubectl -n "$NS" logs deploy/backend -c php-fpm --since=5m | grep -c '42501' || true
kubectl -n "$NS" logs deploy/worker --since=5m | grep -c '42501' || true
```

Puis, à la main, ce que les journaux ne prouvent pas : une page publique
(`/api/watch`), un login raté (le compteur de `cache_items` s'écrit sous
`cpg_app`), une écriture du backoffice qui réordonne (le trigger se déclenche
sous `cpg_app`), et, pour la préprod, le prochain push de la branche de
release — son Job de migration tourne alors sous `cpg_migrator`, ses smoke
tests sous `cpg_app`. Aucun signal d'arrêt des workers à relancer : le
worker est redémarré, et le cache signé est intact (même `APP_SECRET`).

**Retour arrière** (à tout moment, le rôle bootstrap existe toujours) :
republier l'URL du superutilisateur comme **nouvelle** version de
`<env>-backend-database-url` — c'est la **dernière version d'avant #575**,
pas forcément la précédente (après une rotation, la précédente porte un mot
de passe de `cpg_app` devenu faux) ; la relire dans Secret Manager vers un
fichier, jamais à l'écran : `scw secret version access <secret-id>
revision=<n> region=fr-par > "$TMP/previous-url"` (vérifier la forme de
sortie avec `--help`, elle évolue : brute, ou `-o json` à décoder par `jq -r
.data | base64 -d`), puis `scw secret version create <secret-id>
data=@"$TMP/previous-url" region=fr-par` —, `force-sync`, `rollout restart`
des deux. Les rôles et la propriété transférée ne gênent en rien le
superutilisateur ; les laisser. **Depuis l'issue #593, ce retour arrière
suppose d'abord de rouvrir le réseau au superutilisateur** : le `pg_hba.conf`
du dépôt le refuse par TCP, et l'application, dans un autre pod, ne s'y
connecterait plus. Revenir sur #593 (une release qui ajoute la règle, ou
le retrait de `-c hba_file` de `postgres.yaml`) — PostgreSQL redémarre
(`Recreate`) —, puis republier l'URL. Une fois la bascule validée en prod, désactiver
les versions qui portent l'URL du superutilisateur **sauf la plus récente**,
gardée pour ce retour arrière (`scw secret version disable`, §2bis étape 5).

**La procédure ne réécrit jamais un mot de passe existant par défaut** :
rejouée avec `ROTATE=0`, elle laisse l'application connectée. **Un objet créé
à la main** sous le superutilisateur (une table, une séquence) n'hérite pas
des privilèges par défaut, attachés au rôle qui crée : rejouer les étapes 1
et 2 — les mots de passe générés sont ignorés, les `GRANT … ON ALL`
rattrapent l'objet, et rien n'est à publier — ou, mieux, ne créer d'objet que
par une migration. **Rotation des mots de passe** : les étapes 1 et 2 avec
`ROTATE=1`, puis republier les deux URL, `force-sync`, `rollout restart`.
Entre le COMMIT et le redémarrage, toute nouvelle connexion de l'application
échoue : enchaîner sans attendre, à une heure calme.

**Fenêtre à connaître, entre la bascule et la release qui l'embarque.** Le
`migrate-job.yaml` d'avant #575 lit `backend-secrets`, donc `cpg_app` une fois
la bascule faite, et `cpg_app` n'a pas la table des migrations : un
déploiement depuis `main` d'avant #575 (un hotfix) échouerait alors à son Job
de migration, fail-closed, même sans migration en attente. D'où l'ordre :
basculer la prod juste avant le merge de la release, pas des jours avant ; et
si un hotfix doit partir entre-temps, le retour arrière ci-dessous le temps du
hotfix, puis rebasculer.

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
toujours honorée par l'image 5.x), identifiants à saisir à la main. Adminer
est un pod comme un autre : le `pg_hba.conf` du dépôt (issue #593) n'y admet
que **`cpg_app`** (données, DML seulement) et **`cpg_migrator`**
(propriétaire : DDL), jamais le superutilisateur de l'image, refusé par le
réseau. Le nom de rôle et le mot de passe sont ceux de l'URL de
`backend-secrets` (`cpg_app`) ou de `backend-migration-secrets`
(`cpg_migrator`), clé `DATABASE_URL`, de la forme
`postgresql://<rôle>:<mot de passe>@database:5432/…`. Le mot de passe va au
presse-papiers sans passer par l'écran, dans un sous-shell (son `umask` et
son répertoire privé ne survivent pas à la commande) :

```bash
NS=
SECRET=backend-secrets
(
  umask 077
  d=$(mktemp -d)
  trap 'rm -rf "$d"' EXIT
  kubectl get secret "$SECRET" -n "${NS:?preprod ou prod}" -o jsonpath='{.data.DATABASE_URL}' | base64 -d \
    | sed -E 's#^postgresql://[^:]+:([^@]+)@.*#\1#' > "$d/password"
  # xclip sous X11 ; wl-copy sous Wayland ; pbcopy sous macOS.
  xclip -selection clipboard -loops 1 < "$d/password"
)
```

`SECRET=backend-migration-secrets` pour `cpg_migrator`. `-loops 1` vide la
sélection après le premier collage.

Le champ **"Base de données" se remplit aussi** : vide, Adminer se connecte à
`postgres`, que le `pg_hba.conf` refuse aux deux rôles comme `template0` et
`template1` (issue #597) — « pg_hba.conf rejects connection … database
"postgres" ». Le nom est celui de l'URL, après `database:5432/`. Une page
d'Adminer qui se reconnecterait à `postgres` (liste des bases, niveau
serveur) tombe sur le même refus : rester dans la base de l'application.

L'accès reste conditionné à la possession d'un kubeconfig valide sur le
cluster (même niveau de confiance qu'un `kubectl exec`), pas d'un simple mot
de passe web. Sous `cpg_migrator`, tout ce qu'Adminer fait touche le schéma
(un `DROP`, un `DISABLE TRIGGER` y passent) : préférer `cpg_app` pour une
correction de données, et, pour une simple lecture, le `psql` sous
`cpg_reader` du § « Journal de sécurité ».

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

## Sessions fantômes PostgreSQL (keepalives TCP, issue #279)

Le verrou des limiteurs est un advisory lock **de session** (#272). Un pod
perdu sans fermer son socket (nœud tombé, partition réseau) laissait sa
session ouverte côté serveur, verrou compris, jusqu'au keepalive Linux par
défaut : deux heures pendant lesquelles chaque requête sur la clé répondait 500
au bout de `lock_timeout` (5 s). `k8s/base/postgres.yaml` passe au serveur
`tcp_keepalives_idle=60`, `tcp_keepalives_interval=10` et
`tcp_keepalives_count=3` : une session muette est fermée, et ses verrous
libérés, en 60 + 10 × 3 = 90 s. Linux n'envoie toutefois aucune sonde tant que
des données attendent leur accusé de réception : si le client disparaît pendant
que le serveur lui répond (fenêtre étroite, mais réelle), ce sont les
retransmissions qui décident, une quinzaine de minutes. `tcp_user_timeout=90000`
(millisecondes) borne ce cas aussi.

Mesuré sur `postgres:18.6-alpine`, client coupé du réseau sans FIN
(`docker network disconnect`, relevé toutes les 5 s) :

| Coupure | Sans réglage | Keepalives seuls | Keepalives + `tcp_user_timeout` |
|---|---|---|---|
| session inactive | tenu après 202 s | libéré en 96 s | libéré en 96 s |
| réponse en cours d'envoi | — | tenu après 303 s | libéré en 106 s (15 s de requête + 90) |

Un pod supprimé, même en force, n'est pas une session fantôme : le kubelet tue
le conteneur, le noyau ferme le socket et le serveur reçoit le FIN.

Changer ces réglages redémarre Postgres (`Recreate`) : une coupure courte de
l'API, à annoncer dans les notes de release et à valider en préprod
(`rollout status` + journaux), comme toute modification de cette charge.

**Vérifier qu'ils sont actifs, par TCP.** Sur une connexion par socket Unix,
PostgreSQL ignore ces paramètres et `SHOW` les lit à `0` : la commande
habituelle `psql` dans le pod mentirait. Depuis un terminal séparé, avec un
kubeconfig d'administrateur (lecture seule, la sortie reste sur le terminal) :

```bash
NS=        # preprod ou prod, à renseigner : vide, la commande s'arrête avant d'appeler le cluster
printf '%s\n' 'SHOW tcp_keepalives_idle;' 'SHOW tcp_keepalives_interval;' 'SHOW tcp_keepalives_count;' 'SHOW tcp_user_timeout;' \
  | kubectl -n "${NS:?preprod ou prod}" exec -i deploy/postgres -c postgres -- \
    sh -c 'PGOPTIONS="-c default_transaction_read_only=on -c lock_timeout=5s" psql -X -A -t -v ON_ERROR_STOP=1 -h 127.0.0.1 -U cpg_reader -d "$POSTGRES_DB"'
# attendu : 60, 10, 3 et 90000 sur quatre lignes ; 0 : connexion par socket
# (-h manquant) ou serveur démarré sans les args.
```

Comme pour la recherche d'un compte (§ « Journal de sécurité »), la requête arrive
par l'entrée standard, rien dans l'argv de l'`exec`, sous le rôle lecteur
`cpg_reader` (§ « Rôles PostgreSQL », issue #575). Aucun mot de passe : le
`pg_hba.conf` du dépôt (`k8s/base/postgres-pg_hba.conf`, issue #593) fait
confiance (`trust`) aux connexions TCP de 127.0.0.1 pour `cpg_reader` **seul**
— de l'intérieur du pod, ou d'un `port-forward` — ; le superutilisateur y est
refusé, contrairement au fichier de l'image.

Ce qui n'est pas posé, volontairement :

- **`idle_session_timeout`** fermerait aussi les sessions saines restées
  inactives, celle du worker Messenger entre deux messages par exemple. Les
  keepalives ne ferment que celles dont le client ne répond plus.
- **`client_connection_check_interval`** ne surveille le client que pendant
  qu'une requête s'exécute. Une session fantôme qui tient un verrou est
  inactive, et ses attentes sont déjà bornées à 5 s.

Limite acceptée : après une partition réseau qui guérit, le client peut croire
tenir encore un verrou que le serveur a libéré, et un autre pod peut l'avoir
pris entre-temps. Le client l'apprend à sa requête suivante sur cette
connexion, qui échoue (le serveur a fermé la session). Pour un limiteur,
l'effet se borne à un décompte non atomique pendant cet intervalle, jamais à un
blocage.

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
conséquence sur le reste ; ne pas le confondre avec une panne. Le déploiement
non plus ne s'y arrête pas : l'annotation `cp-ghostotof.com/deploy-gate:
optional` le fait signaler en avertissement par la garde du §2 (issue #325).

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
défaut, `debug` dans l'image préprod). Chaque ligne `security_audit` porte `event`, le compte
visé (`userId`), l'auteur (`actor`), `ip` et `path` — jamais un mot de passe, un jeton, un e-mail
ni l'**identifiant de connexion** d'un compte (issue #363 : celui d'un invité est dérivé de son
adresse). Un compte, sujet ou auteur, est désigné par son **UUID** ; `user` ne porte plus que le
marqueur aléatoire `guest-…` du palier de base, et `actor` vaut l'UUID du compte, ce marqueur,
`anonymous`, `system` ou `console`. Le canal `security` de Symfony suit la même règle
(`SecurityChannelAccountRedactor`) : « Authenticator successful! » écrit
`JWTPostAuthenticationToken(userId="…", roles="…")`. Des tests le pincent.

Pour nommer un compte pendant un incident, tant qu'il existe (une fois supprimé, ses lignes ne
désignent plus personne, et c'est voulu), par `psql` dans le pod `postgres`, depuis un **terminal
séparé** — la réponse est un nom et une adresse, qui n'ont rien à faire dans le transcript d'une
session d'assistant —, avec un **kubeconfig d'administrateur humain**. Le jeton du déployeur CI
échoue ici, et c'est voulu : son `Role` n'a plus `pods/exec` (audit C8).

```bash
NS=        # preprod ou prod, à renseigner : vide, la commande s'arrête avant d'appeler le cluster
printf '%s\n' '\set id <userId>' "SELECT username, email FROM cpg_user WHERE id = :'id'::uuid;" \
  | kubectl -n "${NS:?preprod ou prod}" exec -i deploy/postgres -c postgres -- \
    sh -c 'PGOPTIONS="-c default_transaction_read_only=on -c lock_timeout=5s" psql -X -A -v ON_ERROR_STOP=1 -U cpg_reader -d "$POSTGRES_DB"'
# attendu : l'en-tête username|email, une ligne nom|adresse et « (1 row) » ;
# « (0 rows) » : aucun compte de cet UUID dans CE namespace — relire $NS avant de conclure.
```

C'est l'**exception assumée** à « jamais `kubectl exec` » (issue #520, règle dans
`.claude/rules/deploiement.md`) : une lecture humaine dont la sortie reste sur le terminal. Un Job
aurait écrit le nom et l'adresse dans les journaux de son pod, lisibles jusqu'à sa suppression,
y compris par le jeton CI (`pods/log`), ce que #363 cherche justement à éviter. Ce que fait chaque
morceau, essayé sur l'image `postgres:18.6-alpine` en local (pas encore sur le cluster) :

- **Rien dans l'argv.** La requête arrive par l'entrée standard : ni le SQL ni l'UUID ne figurent
  dans l'argv de l'`exec`, donc dans l'URL de la requête à l'API server. L'UUID reste en revanche
  dans l'historique du shell de l'opérateur — un identifiant pseudonyme, pas un secret.
- **Pas d'injection.** L'UUID passe par une variable psql (`\set`), citée par `:'id'` et convertie
  en `uuid` : une valeur collée depuis un journal qui contiendrait une apostrophe ou du SQL ne
  devient qu'une erreur « invalid input syntax for type uuid ».
- **Lecture seule, deux fois.** `default_transaction_read_only` fait refuser une écriture par
  erreur (« cannot execute UPDATE in a read-only transaction ») ; un `SET` dans la session le lève,
  c'est un garde-fou. Le rôle, depuis l'issue #575, en est un second : `cpg_reader` n'a que
  `pg_read_all_data` (§ « Rôles PostgreSQL »), jamais le superutilisateur de l'image — une
  erreur d'opérateur n'écrit rien. Pas une frontière contre qui détient l'`exec` : dans le pod,
  `trust` donne aussi `-U "$POSTGRES_USER"`, et `pg_read_all_data` lit `pg_authid` (jamais de
  `SELECT *` dessus dans un terminal). Tant que la procédure des rôles n'a pas été jouée dans ce
  namespace, le rôle n'existe pas et la commande échoue en le disant. `lock_timeout=5s` borne ses attentes de verrou comme celles de FPM et
  des Jobs (#525) : `PGOPTIONS` remplace la variable entière, la borne doit donc y être répétée.
- **Un résultat lisible.** `-X` ignore un `~/.psqlrc` ; `-A` rend `nom|adresse` sans alignement mais
  garde l'en-tête et le compte de lignes, pour qu'un namespace erroné (« (0 rows) ») ne se confonde
  pas avec une sortie vide ; `ON_ERROR_STOP=1` fait sortir en code 3 sur une erreur au lieu de 0.
  `${NS:?…}` refuse un namespace vide : un bloc copié tel quel ne vise ni la préprod ni la prod par
  défaut.
- **Aucun secret ne sort du pod.** La connexion passe par le socket local du conteneur, sans mot de
  passe : le `pg_hba.conf` du dépôt (`k8s/base/postgres-pg_hba.conf`, lu par `-c hba_file`, issue
  #593) y pose `local all all trust`, comme l'image le faisait. `cpg_reader` n'a d'ailleurs aucun
  mot de passe, et ce fichier le refuse depuis un autre pod (`reject`, comme tout rôle autre que
  `cpg_app` et `cpg_migrator`) ; seuls le socket local et `127.0.0.1` du pod, en `trust`, le
  prennent. `tools/check-postgres-hba.sh` (CI `postgres-hba`) le mesure à chaque push.

Pincé par `OperatorExecBoundaryTest`.

Alternative graphique : Adminer (§ ci-dessus), allumé le temps de la recherche puis éteint, avec le
mot de passe PostgreSQL à saisir à la main.

L'identifiant **tenté** au login n'est jamais écrit tel quel (issue #418) : ce peut être un mot de
passe tapé dans le mauvais champ. `login-failed` et `login-throttled` portent son empreinte,
`userFingerprint` (HMAC sous une clé dérivée d'`APP_SECRET`, insensible à la casse comme le compteur de
`login_throttling`), et ne désignent le compte (`userId`) que s'il existe et a été chargé :
pas de `userId` pour un identifiant inconnu, ni **jamais** pour `login-throttled` (refusé
avant tout chargement). Pour relier un refus aux échecs qui l'ont causé, filtrer sur l'empreinte :

```bash
kubectl -n preprod logs deploy/backend -c php-fpm --tail=2000 \
  | grep '"channel":"security_audit"' \
  | jq -r 'select(.context.userFingerprint == "<empreinte>") | [.datetime, .context.event, .context.userId // "-", .context.ip] | @tsv'
```

Une rotation d'`APP_SECRET` (procédure : § Rotation / mise à jour d'un Secret, cas
d'`APP_SECRET`) change toutes les empreintes : les lignes d'avant et d'après ne se
relient plus. Un refus du compteur **par IP seule** (25 essais en 15 min, toutes saisies confondues)
peut porter l'empreinte d'une saisie jamais vue : rien ne s'y relie, lire alors les lignes de la
même `ip`. L'empreinte est courte exprès (6 caractères hexadécimaux, 24 bits) : deux saisies
différentes peuvent coïncider, c'est un indice à IP et fenêtre fixées, pas une preuve entre IP.

Lecture sur un pod, canal d'audit seulement, une ligne lisible par événement :

```bash
kubectl -n preprod logs deploy/backend -c php-fpm --tail=500 \
  | grep '"channel":"security_audit"' \
  | jq -r '[.datetime, .context.event, .context.userId // .context.user // "-", .context.userFingerprint // "-", .context.actor, .context.ip, .context.path] | @tsv'
```

Vérification après un déploiement (checkpoint 3 du plan) : un `POST /api/login_check` erroné
contre la préprod doit produire une ligne `login-failed` avec l'IP publique de l'appelant (pas
`100.64.x.x` ni celle du LB, cf. ADR 0005 D6/D7), et le sixième essai une ligne `login-throttled`.
Les logs de pod ne sont conservés que par Kubernetes (rotation locale, perdus au remplacement du
pod) : la rétention et le statut RGPD de ces lignes sont traités dans `docs/rgpd/` (plan, T5.8).

### Créer un compte super-administrateur (issue #386)

`app:user:create` est la seule voie qui crée un compte sans invitation : l'amorçage du premier
`ROLE_SUPER` (ADR 0001), ou sa recréation si tous ont été perdus. **Jamais par `kubectl exec`** :
la ligne `user-created` du journal partirait sur le terminal de l'opérateur, pas sur le flux du
conteneur, et `kubectl logs` ne la verrait jamais. Le Job `k8s/base/create-user-job.yaml` (hors
`kustomization.yaml`, comme le Job de migration) lance la commande dans son propre pod. Le mot
de passe passe par un Secret temporaire créé **depuis un fichier**, monté en lecture seule et lu
par `--password-stdin` : jamais par un argument ni une variable. Dans un **terminal séparé**,
jamais derrière un `!` dans une session Claude Code (cf. « Deployment invariants » de
`.claude/CLAUDE.md`). La préprod d'abord, pour valider la procédure.

```bash
NS=preprod   # puis prod
umask 077
dir="$(mktemp -d)"
# Saisie sans écho, écrite sans fin de ligne (une fin de ligne restante serait refusée).
IFS= read -rs pw && printf '%s' "$pw" > "$dir/password" && unset pw
kubectl -n "$NS" create secret generic backend-create-user-password --from-file=password="$dir/password"
rm -rf "$dir"

# L'image qui tourne, pour que le Job exécute exactement le code déployé.
IMAGE="$(kubectl -n "$NS" get deploy backend -o jsonpath='{.spec.template.spec.containers[?(@.name=="php-fpm")].image}')"
# Depuis la racine du dépôt (issue #488) : suppression du Job précédent, envsubst à liste
# explicite, apply, attente par tools/wait-rollout.sh — un refus échoue aussitôt au lieu
# d'épuiser le délai —, puis les journaux du Job, la ligne `user-created` comprise, après un
# succès comme après un échec. 300 s : sous les 360 s d'activeDeadlineSeconds du Job, exprès
# (#535) — un pod bloqué tourne encore à l'expiration, ses journaux s'impriment, puis
# run-job.sh l'arrête : après un échec, plus rien ne tourne (sauf si l'arrêt échoue, ce qu'il dit).
tools/run-job.sh "$NS" k8s/base/create-user-job.yaml "$IMAGE" 300 CPG_USERNAME='<nom-du-compte>'
# Dans tous les cas, succès ou échec :
kubectl -n "$NS" delete secret backend-create-user-password
```

`tools/run-job.sh` demande **bash ≥ 4, jq et envsubst** (gettext) sur le poste : il le vérifie
avant de toucher au cluster, et refuse un nom de compte vide ou hors `[A-Za-z0-9._@:/+-]` —
inséré tel quel dans le YAML, un guillemet ou une fin de ligne y ajouteraient des clés. Le nom
n'est pas un secret, mais il passe en argument, lisible dans `ps` et l'historique du shell le
temps de la commande : la moitié d'un identifiant (Goal #9), jamais écrite dans le dépôt. Un
refus avant le cluster sort en 2, un refus du cluster en 3, un Job en échec en 1.

Le Job ne se rejoue pas (`backoffLimit: 0`) : un nom pris ou un mot de passe refusé (trop court,
compromis, fin de ligne) s'y lisent dans les logs, et l'on recommence depuis le début. Un pod
qui ne démarre pas (Secret supprimé trop tôt, image introuvable) fait échouer le Job au bout de
360 s (`activeDeadlineSeconds`), ou dès l'échec de l'attente : `run-job.sh` arrête alors le Job
resté actif, ses pods attendus, si bien que supprimer le Secret juste après ne coupe rien qui
tourne. Si cet arrêt échoue (un `::error::` le dit), le Job peut encore créer le compte jusqu'à
son délai : attendre `Complete` ou `Failed` dans `kubectl -n "$NS" get job/backend-create-user`
et relire ses journaux (`user-created`, ou un nom déjà pris au second essai) avant de recommencer. Il
disparaît de lui-même au bout d'un jour (`ttlSecondsAfterFinished`), et ses logs avec lui :
l'acteur `console` de l'événement ne nomme personne, l'attribution de l'`apply` reste celle du
kubeconfig qui l'a fait.

### Avant de changer `password_hashers` (issue #590)

Le hasher des comptes est écrit en clair dans `backend/config/packages/security.yaml` :
`algorithm: bcrypt`, `cost: 13` (`cost: 4` en test). `CpgUserPasswordHasherTest` fige ces valeurs
dans chaque environnement, et c'est voulu : changer l'algorithme ou le coût rouvre un écart de
temps au login (constat A10). `verify()` travaille au coût **inscrit dans le hachage stocké**,
alors que l'égaliseur des échecs (`FailedLoginTimingEqualizer`) paie le coût **configuré**. Après
le changement, chaque compte au hachage ancien répond donc à un mauvais mot de passe en un temps
à lui, plus court ou plus long qu'un identifiant inconnu. Mesuré sur un poste : 34 ms au coût 10,
135 ms au coût 12 et 539 ms au coût 14, contre 270 ms au coût 13. Le chronomètre dit alors que
ce compte existe. L'écart dure jusqu'à la prochaine connexion réussie du compte, qui rehache son
mot de passe (`PasswordMigratingListener`, `CpgUserRepository::upgradePassword()`, pincé par
`StalePasswordHashRehashTest`). Un compte dormant le garde. Rehacher d'avance demande le mot de
passe en clair. Il existe deux contournements, non retenus : un hachage encapsulé (bcrypt de
l'ancien hachage), qu'aucun hasher de Symfony ne vérifie ; ou vider le mot de passe puis réinviter.
Le compte devient alors « sans mot de passe », un cas que l'égaliseur compense, mais la personne doit
en choisir un nouveau. Ne jamais le faire sur le dernier `ROLE_SUPER` qui a un mot de passe.
Décision du 2026-10-13 : on accepte cet écart et on le documente.

Avant de modifier la configuration, puis après le déploiement, compter les hachages par format,
en préprod puis en prod. La commande se lance depuis un **terminal séparé**, avec un kubeconfig
d'administrateur humain, comme la recherche d'un compte (§ « Journal de sécurité »). C'est une
lecture seule dont la sortie reste sur le terminal. Elle n'affiche que l'en-tête du format (algorithme
et coût, ou paramètres argon) et un nombre de comptes, jamais un sel, un hachage ni un identifiant.

```bash
NS=        # preprod ou prod, à renseigner : vide, la commande s'arrête avant d'appeler le cluster
kubectl -n "${NS:?preprod ou prod}" exec -i deploy/postgres -c postgres -- \
  sh -c 'PGOPTIONS="-c default_transaction_read_only=on -c lock_timeout=5s" psql -X -A -v ON_ERROR_STOP=1 -U cpg_reader -d "$POSTGRES_DB"' <<'SQL'
SELECT CASE
         WHEN password = '' THEN '(sans mot de passe)'
         WHEN password LIKE '$argon2%' THEN coalesce(substring(password FROM '^(\$argon2[a-z]*\$v=[0-9]+\$[^$]*\$)'), '(argon, format inconnu)')
         ELSE coalesce(substring(password FROM '^(\$2[abxy]\$[0-9]{2}\$)'), '(format inconnu)')
       END AS entete,
       count(*) AS comptes
FROM cpg_user GROUP BY 1 ORDER BY 1;
SQL
# attendu, avec la configuration actuelle : l'en-tête entete|comptes, les lignes
# « (sans mot de passe)|n » et « $2y$13$|m » (l'une ou l'autre peut manquer), puis « (k rows) ».
# Toute autre ligne désigne des hachages anciens, ou posés à la main en SQL.
```

Le nombre de comptes désignables au chronomètre est la somme de la colonne `comptes` sur toutes les
lignes, sauf celle du format configuré et « (sans mot de passe) », que l'égaliseur compense déjà. On le
lit avant de décider. `CpgUserPasswordHasherTest` se met à jour dans le même
changement que `security.yaml`.

## Durcissement des pods (jeton de ServiceAccount et Pod Security Admission)

Deux changements posés le 2026-09-21 (3e audit, constat A17, tâche T5.6). Ils
sont **déclaratifs et versionnés** — aucune commande à jouer en routine —, mais
le premier déploiement qui les emporte **redémarre tous les workloads**, y
compris Postgres et RabbitMQ. La validation en préprod décrite plus bas n'est
donc pas facultative.

### `automountServiceAccountToken: false` sur chaque pod spec

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
ServiceAccount `default` que ce dépôt ne gère pas. Les quinze objets concernés :
`backend`, `worker`, `frontend`, `postgres`, `rabbitmq`, `adminer`, les
CronJob `watch-refresh` et `contact-failed-messages-purge`, **et les sept Jobs
hors kustomize** `migrate-job.yaml`, `seed-job.yaml`, `create-user-job.yaml`
(issue #386), `messenger-probe-job.yaml` (issue #470) et les trois Jobs de
`failed` (issue #521). Ces Jobs ne passent par aucun transformateur, ils
portent donc le champ directement. (`postgres-roles-job.yaml`, issue #575,
était le huitième : l'issue #593 l'a retiré.)

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

**Pourquoi `enforce-version: v1.36` et `audit`/`warn-version: latest`.** La
mineure du control-plane a été relevée le 2026-09-22 (`kubectl version` :
Server `v1.36.1` ; `scw k8s cluster list` : `auto_upgrade=false`, une montée
de version est donc un geste explicite). Épingler le seul mode bloquant fige
la sémantique validée en préprod : une version de Kubernetes qui ajouterait un
contrôle à `baseline` ne refusera pas un pod au premier déploiement suivant la
mise à jour. `audit`/`warn` restent sur `latest` — pour ces deux-là, c'est
justement ce qu'on veut : non bloquants, ils sont le pré-avis de ce qu'un
futur `enforce` refuserait, et un nouveau contrôle doit être **signalé**, pas
masqué. (Jusqu'au 2026-09-22 les trois étaient sur `latest`, faute de version
connue dans le dépôt.)

**Après une montée de version du cluster** (c'est la seule maintenance de ces
labels) : relancer la simulation `--dry-run=server` ci-dessous avec la
nouvelle mineure (`pod-security.kubernetes.io/enforce-version=v1.NN`), puis
déplacer l'épingle dans `k8s/overlays/{preprod,prod}/namespace.yaml`, préprod
d'abord. Une épingle plus ancienne que le cluster n'est pas une erreur —
l'admission connaît toutes les versions passées — mais elle n'applique pas les
contrôles ajoutés depuis, ce que `warn` signalera.

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

1. **Hors ligne.** Le rendu doit passer et les quinze pod specs porter le champ :
   ```bash
   kubectl kustomize k8s/overlays/preprod >/dev/null && echo rendu-ok
   kubectl kustomize k8s/overlays/preprod | grep -c 'automountServiceAccountToken: false'   # 8 attendu
   grep -c 'automountServiceAccountToken: false' k8s/base/migrate-job.yaml k8s/base/seed-job.yaml \
     k8s/base/create-user-job.yaml k8s/base/messenger-probe-job.yaml \
     k8s/base/messenger-stop-workers-job.yaml k8s/base/messenger-failed-retry-job.yaml \
     k8s/base/messenger-failed-remove-job.yaml
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
     pod-security.kubernetes.io/enforce-version=v1.36   # la mineure du cluster, cf. plus haut
   kubectl get namespace preprod -o jsonpath='{.metadata.labels}' | tr ',' '\n'
   ```
4. **Déployer la release sur la préprod** (push sur `release/*`), puis attendre
   chaque workload — la pipeline les attend tous désormais, cette boucle le
   confirme à la main :
   ```bash
   for d in postgres rabbitmq backend worker frontend; do
     kubectl -n preprod rollout status deployment/$d --timeout=180s
   done
   ```
   > **La pipeline attend désormais aussi les workloads à état.** Après
   > `kubectl apply -k .`, `deploy-preprod` et `deploy-prod` attendent
   > (`tools/wait-rollout.sh`, issue #353) le rollout de `postgres`,
   > `rabbitmq` et `worker` en plus de
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
7. **Enfin seulement**, fusionner la PR de la release vers `main` : `deploy-prod`
   posera les mêmes labels et rejouera le même redémarrage sur la prod. Il
   attend, comme `deploy-preprod`, le rollout de `postgres`, `rabbitmq` et
   `worker` en plus de `backend`/`frontend` (`tools/wait-rollout.sh`, issue
   #353, cf. l'encadré de l'étape 4) : un workload à état qui ne remonte pas
   met le job en échec. Lire tout de même leurs journaux (étape 5, sur `prod`) :
   en prod, aucun retour arrière automatique ne suit (cf. « Retour arrière
   applicatif »).

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
- Le superutilisateur PostgreSQL de l'image n'est plus joignable que de
  l'intérieur du pod `postgres` (issue #593, `pg_hba.conf` du dépôt) : son
  mot de passe, toujours dans `postgres-credentials` et Secret Manager (l'image
  en a besoin à l'initialisation), ne sert plus à rien depuis un autre pod. Qui
  détient `pods/exec` sur le namespace l'a encore, sans mot de passe (`trust`
  sur le socket local) : c'est une frontière de kubeconfig, pas de base. La
  procédure des rôles en dépend, unique `exec` du runbook qui écrit
  (§ « Rôles PostgreSQL »). Le loopback, que rejoignent un `kubectl
  port-forward` et un conteneur éphémère (`kubectl debug`), ne fait confiance
  qu'à `cpg_reader` : l'image y admettait le superutilisateur sans mot de
  passe, la relecture de #593 l'a fermé.
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
