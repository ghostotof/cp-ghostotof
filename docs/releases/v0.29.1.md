# v0.29.1 — Renouvellement TLS débloqué, ingress épinglé

Cette release corrige un incident découvert le 2026-10-26. Depuis le 2026-10-09, aucun des trois
certificats TLS (préprod, prod, www) ne pouvait se renouveler, alors qu'ils expirent le 2026-11-08. Une
NetworkPolicy coupait l'ingress des solveurs HTTP-01 de Let's Encrypt. La release épingle aussi les
réglages de l'ingress-nginx dont dépend le refus des corps multipart, et solde les suivis de sécurité de la
v0.29.0.

## Ce qui change

- **Renouvellement des certificats TLS** : la NetworkPolicy `default-deny-ingress` (2026-09-02) empêchait
  l'ingress de joindre le pod solveur que cert-manager crée le temps d'un challenge (port 8089). Le chemin
  public `/.well-known/acme-challenge/…` répondait `504`, et le renouvellement prévu le 2026-10-09 est resté
  bloqué 17 jours sans alerte. La nouvelle policy `allow-acme-solver-from-ingress-nginx` n'ouvre que ce
  port, depuis le namespace `ingress-nginx`. Elle a déjà été appliquée à la main le 2026-10-26 pour ne pas
  attendre la release : les trois certificats se sont renouvelés le jour même et sont valides jusqu'au
  2027-01-24. Le signal d'expiration qui a manqué est suivi dans #719.
- **Ingress-nginx épinglé** (#712) : `k8s/ingress-nginx-values.yaml` fixe `proxy-request-buffering: "on"`,
  `proxy-body-size: "1m"` et `allowSnippetAnnotations: false`. Ce sont les valeurs déjà en vigueur, mais
  elles n'étaient écrites nulle part. L'ingress lit tout le corps avant de joindre le pod, et refuse en
  `413` un corps de plus d'1 Mo. Au-delà de cette taille, un multipart reçoit donc ce `413` HTML de
  l'ingress, et non le `415` du sidecar.
- **Corps multipart** (#702) : la limite est désormais écrite et gardée. Un multipart n'est compté par
  aucune zone de limitation du sidecar, puisqu'il est refusé avant elles, et cela ne coûte rien : aucun
  n'atteint PHP. Le comportement ne change pas.
- **Cookie `BEARER`** (#703, #714) : `Secure` partout sauf en `dev`. Rien ne change en préprod ni en prod,
  qui le posaient déjà. Les tests le lisent maintenant sur la vraie chaîne de pose, et seule la fabrique
  peut créer un cookie.
- **Interne** :
  - parité des services de sécurité compilés entre environnements (#698) ;
  - test de la purge des messages en échec rendu indépendant des changements d'heure (#709 : la commande
    était juste, seul le test comptait mal) ;
  - mise à jour de undici et Vitest, dépendances de dev du frontend (#707).

## À savoir pour le déploiement

- **Aucune migration**, aucun changement de clé ni de secret : pas de fenêtre de maintenance, pas besoin de
  rejouer la procédure des rôles.
- **Rollout du backend** : le hash de `backend-nginx-conf` change. Seuls des commentaires ont bougé, mais
  la ConfigMap est hachée. Le sidecar est remplacé sans coupure. PostgreSQL et RabbitMQ ne redémarrent
  pas.
- **NetworkPolicy** : l'objet existe déjà dans `preprod` et `prod` (appliqué à la main le 2026-10-26).
  `apply -k` le réapplique à l'identique.
- **Ingress-nginx** : hors pipeline. Le `helm upgrade` a déjà été appliqué le 2026-10-26 (révision 3), et
  la release ne le rejoue pas.
- **`audit-preprod` vérifie le certificat de préprod** (bloquant sous 14 jours de validité) : la release a
  été coupée après le renouvellement du 2026-10-26.

## Vérifications

- **Certificats** : constaté le 2026-10-26, les trois `Certificate` sont `Ready` et valides jusqu'au 2027-01-24,
  sans challenge en attente. Après la release, `kubectl get challenges -A` doit toujours être vide.
- **Ingress** : la vérification `jq -e` du prérequis n°2 de `k8s/README.md` affiche `true`.
- **Préprod et prod** : un `POST` en `multipart/form-data` sur l'API répond toujours `415` problem+json, et
  le formulaire de contact passe comme avant.
- **Prod** : une connexion pose toujours le cookie `BEARER` avec `Secure`, `HttpOnly` et `SameSite=Lax`.
