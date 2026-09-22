# v0.16.0 — Jeton d'invitation hors des URL, durcissement des pods, registre RGPD

Lot 2 de la remédiation du 3e audit de sécurité (2026-09-16), phases 4 et 5. Le lot 1 est en
production depuis v0.14.1 et v0.15.0.

**Cette version redémarre PostgreSQL et RabbitMQ** (leur gabarit de pod change, stratégie
`Recreate`) : l'API est brièvement indisponible pendant le déploiement, en préprod puis en
production. Le frontend continue de servir. Aucune migration de schéma.

## Le jeton d'invitation ne voyage plus dans une URL (phase 4)

- Côté API, la définition du mot de passe passe par deux `POST` dont le jeton est dans le corps :
  `/api/account/password-setup/validate` et `/api/account/password-setup`. Les routes `GET|POST
  …/{token}` disparaissent — un chemin d'URL est écrit dans les journaux d'accès du sidecar nginx
  et de l'ingress, un corps ne l'est pas. Les deux routes répondent de la même façon quel que soit
  l'état du lien (422, 404, 410), sans dire si un lien a déjà servi.
- Côté e-mail, le lien porte le jeton dans le **fragment** (`…/set-password#<jeton>`), que le
  navigateur ne transmet jamais au serveur. La page le lit, l'efface de l'URL et le garde en
  mémoire seulement ; la balise `canonical` et les alternates `hreflang` ne le republient plus.
  L'ancienne forme `…/set-password/<jeton>` reste lue pendant 48 heures, la durée de vie d'un
  jeton, et sera retirée dans la version suivante.

## Hygiène du code et de l'infrastructure (phase 5)

- **Erreurs sous `/api` toujours en JSON** (RFC 7807), y compris les 404/405 du routeur et les 403
  des gardes CSRF. Un corps JSON malformé ou un champ mal typé répond 400 : la table
  `exception_to_status` écrasait les défauts d'API Platform et transformait ces cas en 500, ce qui
  permettait à un appelant anonyme d'en fabriquer à volonté.
- **En-têtes de sécurité sur toutes les réponses du frontend.** Un `add_header` dans une `location`
  nginx annule ceux du bloc parent : `/assets/`, `/config.js` et `/healthz` sortaient sans CSP ni
  HSTS. Les sept en-têtes vivent dans un fichier unique, inclus partout ; `tools/audit-prod.sh`
  vérifie désormais la réponse finale de `/config.js` et d'un asset.
- **Un échec de connexion ne se chronomètre plus.** Un identifiant inconnu ou un compte en attente
  d'activation répondait sans calculer de hachage, donc bien plus vite qu'un mot de passe faux ;
  l'écart (~270 ms) disait quels comptes existent. Les trois cas coûtent maintenant le même temps.
- **Firewall `login` borné** à `^/api/login_check$` — toute route future sous `/api/login…` aurait
  été servie sans lire le jeton. **Sessions désactivées** dans tous les environnements : rien n'en
  a besoin, et le gestionnaire par défaut aurait écrit sous `var/` sur un pod en lecture seule.
- **Pods sans jeton de ServiceAccount** (`automountServiceAccountToken: false` sur les dix
  spécifications, Jobs compris — aucun ne parle à l'API Kubernetes) et **Pod Security Admission**
  sur les deux namespaces : `baseline` imposé, `restricted` en avertissement et audit.
- **Xdebug inerte dans l'image de préprod**, armé seulement par un secret dédié et optionnel,
  profilage seul (jamais de trace : elle écrirait les arguments, donc les mots de passe), sortie
  sur un volume éphémère. La production n'embarque rien de tout cela.
- **Clés JWT et `.env.test` hors du contexte de build** (`.dockerignore`) ; **`security.txt`**
  publié (RFC 9116), avec une date d'expiration que l'audit de production surveille.
- Adminer passe en 5.5.1, à zéro réplica par défaut en préprod.
- La rotation du mot de passe Basic Auth de la préprod est documentée sans jamais passer un secret
  en argument de commande.

## Registre RGPD et politique de confidentialité

Le registre des traitements affirmait « aucun e-mail n'est stocké » alors que les comptes invités
en portent un depuis l'ADR 0001. Son §3 distingue désormais l'accès de base sans compte,
l'invitation et le compte nominatif, et l'authentification ; la politique de confidentialité
(FR/EN) est alignée, et cesse d'affirmer qu'aucun transfert hors UE n'a lieu tant que la
redirection des e-mails passe par un prestataire américain. L'ADR 0003 consigne le risque accepté
de la non-révocation d'un JWT à la déconnexion.

## Dépendances

Mises à jour Dependabot de la période : groupes mineurs/correctifs backend (Composer) et frontend
(npm), `unplugin-icons` 24, actions GitHub.
