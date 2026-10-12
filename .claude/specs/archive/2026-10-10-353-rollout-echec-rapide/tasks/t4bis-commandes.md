# T4 bis (#353) : commandes à lancer par Christophe

Rédigées le 2026-10-09. Terminal séparé, **sans** préfixe `!`, kubeconfig administrateur. Collez un
bloc à la fois et lisez sa sortie avant de passer au suivant. Tout est journalisé dans
`~/t4bis-353.log`, à renvoyer à la fin (bloc 9).

**Pourquoi c'est nécessaire.**
1. Le Role déployeur gagne `get`/`list` sur `events`. Le RBAC est appliqué à la main et la pipeline
   ne le rejoue jamais (`k8s/README.md` §4).
2. La détection suppose que `PodReadyToStartContainers=False` pendant un `FailedMount`. Il faut le
   constater sur le cluster 1.36.

`kustomize` n'est pas installé sur le poste, d'où le kustomize intégré à `kubectl` et l'ajout de la
ligne `namespace:` à la main, équivalent de `kustomize edit set namespace`. Chaque bloc nomme son
contexte, parce que le poste en a trois : `cp-ghostotof-preprod`, `cp-ghostotof-prod` et
`cp-ghostotof-6214b942-…`.

## Bloc 1 : préparer

```bash
cd ~/dev/cp-ghostotof
git switch feature/353-rollout-echec-rapide
ctx_avant="$(kubectl config current-context)"
echo "contexte d'origine : $ctx_avant"
: > ~/t4bis-353.log
```

## Bloc 2 : RBAC en préprod, le diff d'abord

```bash
kubectl config use-context cp-ghostotof-preprod
TMP=$(mktemp -d)
cp k8s/base/github-actions-rbac/*.yaml "$TMP/"
printf 'namespace: preprod\n' >> "$TMP/kustomization.yaml"
kubectl diff -k "$TMP" 2>&1 | tee -a ~/t4bis-353.log
```

Attendu : un diff qui ne fait qu'**ajouter** la règle `events` (`get`, `list`) et son commentaire au
Role `github-actions-deployer`. Si le diff montre autre chose, en particulier un `ClusterRoleBinding`
ou ses `subjects`, **arrêtez-vous** et renvoyez la sortie.

## Bloc 3 : appliquer en préprod et vérifier

À lancer seulement si le diff du bloc 2 est conforme.

```bash
kubectl apply -k "$TMP" 2>&1 | tee -a ~/t4bis-353.log
rm -rf "$TMP"
kubectl -n preprod auth can-i list events --as=system:serviceaccount:preprod:github-actions-deployer | tee -a ~/t4bis-353.log
```

Attendu : le Role est `configured`, les autres objets `unchanged`, puis `yes`.

## Bloc 4 : expérience en préprod, un Secret absent monté en volume

Le pod jetable a un initContainer, comme `backend`.

```bash
kubectl -n preprod apply -f - <<'EOF'
apiVersion: apps/v1
kind: Deployment
metadata: {name: wr353-mount}
spec:
  replicas: 1
  selector: {matchLabels: {app: wr353-mount}}
  template:
    metadata: {labels: {app: wr353-mount}}
    spec:
      automountServiceAccountToken: false
      terminationGracePeriodSeconds: 1
      securityContext:
        runAsNonRoot: true
        runAsUser: 65534
        seccompProfile: {type: RuntimeDefault}
      initContainers:
        - name: init
          image: busybox:1.37
          command: ["true"]
          securityContext: {allowPrivilegeEscalation: false, capabilities: {drop: [ALL]}}
      containers:
        - name: busybox
          image: busybox:1.37
          command: [sleep, "3600"]
          securityContext: {allowPrivilegeEscalation: false, capabilities: {drop: [ALL]}}
          volumeMounts: [{name: probe, mountPath: /probe, readOnly: true}]
      volumes:
        - name: probe
          secret: {secretName: wr353-absent}
EOF
echo "=== T4 bis : Secret absent monté en volume ===" >> ~/t4bis-353.log
debut=$SECONDS
tools/wait-rollout.sh preprod deployment/wr353-mount --timeout 120 > >(tee -a ~/t4bis-353.log) 2>&1
rc=$?; echo "rc=$rc en $((SECONDS - debut)) s" | tee -a ~/t4bis-353.log
```

Attendu : `rc=1` en 20 à 40 s, avec une ligne `::error::… volume probe : FailedMount observé depuis
1x s — MountVolume.SetUp failed for volume "probe" : secret "wr353-absent" not found`.

## Bloc 5 : relevés, avant le nettoyage

```bash
{
  echo "=== conditions du pod ==="
  kubectl -n preprod get pods -l app=wr353-mount -o json | jq -c '.items[].status.conditions | map({type, status})'
  echo "=== statuts des conteneurs ==="
  kubectl -n preprod get pods -l app=wr353-mount -o json | jq -c '.items[].status | {phase, init: [.initContainerStatuses[]?.state.waiting.reason], containers: [.containerStatuses[]?.state.waiting.reason]}'
  echo "=== événements FailedMount ==="
  kubectl -n preprod get events --field-selector involvedObject.kind=Pod,reason=FailedMount -o json | jq -c '[.items[] | select(.involvedObject.name | startswith("wr353-mount")) | {msg: .message, count, last: .lastTimestamp}]'
} 2>&1 | tee -a ~/t4bis-353.log
```

Attendu : `{"type":"PodReadyToStartContainers","status":"False"}` dans les conditions, et
`PodInitializing` pour l'initContainer comme pour le conteneur.

## Bloc 6 : nettoyer la préprod

```bash
kubectl -n preprod delete deployment wr353-mount
sleep 5
kubectl -n preprod get pods -l app=wr353-mount
```

Attendu : `No resources found in preprod namespace.`

## Bloc 7 : RBAC en prod, le diff d'abord

À lancer seulement si les blocs 4 et 5 sont conformes.

```bash
kubectl config use-context cp-ghostotof-prod
TMP=$(mktemp -d)
cp k8s/base/github-actions-rbac/*.yaml "$TMP/"
printf 'namespace: prod\n' >> "$TMP/kustomization.yaml"
kubectl diff -k "$TMP" 2>&1 | tee -a ~/t4bis-353.log
```

Attendu : le même diff qu'en préprod, et rien d'autre.

## Bloc 8 : appliquer en prod, vérifier, rétablir le contexte

```bash
kubectl apply -k "$TMP" 2>&1 | tee -a ~/t4bis-353.log
rm -rf "$TMP"
kubectl -n prod auth can-i list events --as=system:serviceaccount:prod:github-actions-deployer | tee -a ~/t4bis-353.log
kubectl config use-context "$ctx_avant"
kubectl config current-context
```

Attendu : le Role est `configured`, puis `yes`, puis le contexte d'origine.

## Bloc 9 : renvoyer le journal

```bash
cat ~/t4bis-353.log
```
