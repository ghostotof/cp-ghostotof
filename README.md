# cp-ghostotof

[![Pipeline](https://github.com/ghostotof/cp-ghostotof/actions/workflows/pipeline.yml/badge.svg?branch=main)](https://github.com/ghostotof/cp-ghostotof/actions/workflows/pipeline.yml)
[![CodeQL](https://github.com/ghostotof/cp-ghostotof/actions/workflows/codeql.yml/badge.svg?branch=main)](https://github.com/ghostotof/cp-ghostotof/actions/workflows/codeql.yml)
[![Licence MIT](https://img.shields.io/badge/code-MIT-blue.svg)](LICENSE)

**Le site tourne en ligne : [cp-ghostotof.com](https://cp-ghostotof.com)** — ce dépôt en est le code
source intégral, backend, frontend, images et déploiement.

Un portfolio de développeur PHP, conçu comme une démonstration de ce qu'une application moderne
peut exiger d'elle-même : API Symfony 8 (API Platform) découplée d'un front Vue 3/TypeScript,
découpée en contextes DDD, analysée en PHPStan niveau maximal sans baseline, testée des deux
côtés, bilingue, accessible, et déployée sur Kubernetes par un pipeline qui promeut la même image
de la préprod à la prod.

## Ce que le site démontre

- **Trois paliers d'accès** ([ADR 0003](docs/adr/0003-paliers-d-acces.md)) : anonyme, un palier
  de base obtenu en un clic sans compte ni mot de passe (jeton signé de 15 minutes), et un palier
  nominatif accordé par invitation, seul à ouvrir le CV. Un test parcourt le routeur et refuse par
  défaut toute route `/api` qui n'a pas de justification écrite pour être publique.
- **Un backoffice complet** (`ROLE_SUPER`) : chaque contenu du site est administrable, en deux
  langues, ordonné par glisser-déposer avec un lien explicite entre les versions FR et EN, et
  chaque entité a une clé primaire UUID v7 ([specs 0003 et 0004](.claude/specs/)).
- **Une assistance IA tenue en laisse** ([ADR 0004](docs/adr/0004-assistance-ia.md)) : un
  assistant de traduction FR/EN dans le backoffice via Symfony AI, derrière une interface
  applicative, coût borné par construction, jamais de suggestion persistée sans un humain, aucun
  test qui sort sur le réseau. La phase 2, un assistant conversationnel sur le parcours réservé
  au palier nominatif, est spécifiée ([spec 0005](.claude/specs/0005-career-assistant.md)).
- **Une veille technique en données vivantes** ([ADR 0002](docs/adr/0002-veille-technique.md)) :
  fins de support et vulnérabilités connues des paquets réellement déployés, lues d'un snapshot
  local écrit par un CronJob — aucun appel sortant dans un chemin de rendu public.
- **Des comptes provisionnés par invitation** ([ADR 0001](docs/adr/0001-admin-user-provisioning.md)),
  sans mot de passe initial ni identifiant partagé.
- **Un journal des incidents de production** et leurs invariants, publié sur le site : ce que
  chaque panne a appris, sous forme de règle qu'on ne peut plus enfreindre sans le savoir.

## Comment il est construit

| Couche | Choix | Garde-fous |
|---|---|---|
| Backend | Symfony 8.1, API Platform 4.3, Doctrine 3 / PostgreSQL, Messenger / RabbitMQ, JWT en cookie httpOnly + double-submit CSRF | PHPStan `max` + `strict-rules` sans baseline, Rector, Psalm (flux de données), Symfony Language Tools, PHPUnit |
| Frontend | Vue 3, TypeScript, Vite, Vue Router, vue-i18n, Bootstrap 5, architecture en couches (`domain` → `infrastructure` → `application` → `presentation`) | Vitest + axe-core, ESLint avec règles d'accessibilité et d'i18n, `vue-tsc` |
| Livraison | Images multi-stage (`preprod` construite `FROM production`), Kubernetes (Kapsule), kustomize, External Secrets, migrations en Job **avant** le rollout | Pipeline GitHub Actions déclenché par tag : tests → analyse → images → préprod → smoke test → prod → release ; CodeQL ; Dependabot ; actions figées sur un SHA |

Les décisions sont écrites avant le code, avec leurs alternatives écartées, dans
[`docs/adr/`](docs/adr/) ; les spécifications qui les mettent en œuvre dans
[`.claude/specs/`](.claude/specs/) ; les invariants opérationnels appris en production (et ce qui
ne doit plus jamais être défait) dans [`.claude/CLAUDE.md`](.claude/CLAUDE.md). Le registre RGPD
des traitements est dans [`docs/rgpd/`](docs/rgpd/registre-traitements.md).

## Développement local

Prérequis : Docker Compose v2 et `make`. Tout tourne dans des conteneurs, la machine hôte n'a besoin
ni de PHP ni de Node.

```bash
make help              # toutes les cibles
make up                # démarre la stack
make sh                # shell dans le backend (utilisateur dev)
make back-test         # PHPUnit
make back-quality      # PHPStan + Rector + Psalm + lsp:check
make front-test        # Vitest
make front-lint        # ESLint
make front-build       # vue-tsc -b + vite build
```

| Service    | Rôle                       | Accès depuis l'hôte |
|------------|----------------------------|---------------------|
| `web`      | nginx                      | http://localhost:8080 |
| `backend`  | PHP-FPM + Symfony          | `make sh` |
| `database` | PostgreSQL                 | localhost:5432 |
| `adminer`  | Interface admin PostgreSQL | http://localhost:8081 |
| `rabbitmq` | RabbitMQ + UI              | http://localhost:15672 |
| `frontend` | Node + Vite                | http://localhost:5173 |

Les conteneurs `backend` et `frontend` tournent sous l'UID/GID de l'utilisateur qui a généré le
projet (`UID`/`GID` dans `.env`), pour que les fichiers créés dans les conteneurs restent éditables
depuis l'IDE. **Un autre poste** adapte ces deux valeurs (`id -u` / `id -g`) puis relance
`make build`. Les README de [`backend/`](backend/README.md) et [`frontend/`](frontend/README.md)
détaillent chaque moitié.

## Images et environnements

Le `Dockerfile` du backend est multi-stage. Trois cibles sont exploitables :

| Cible        | Usage        | Code       | Construite via |
|--------------|--------------|------------|----------------|
| `dev`        | poste local  | bind mount | `make build` |
| `production` | déploiement  | copié      | `make build-prod` |
| `preprod`    | validation   | copié      | `make build-preprod` |

`preprod` est construite **à partir de** `production` : ses couches applicatives sont identiques à
l'octet près. Ce qui est validé en préprod est donc bien l'artefact déployé, augmenté d'Xdebug en
mode profilage déclenché manuellement (`XDEBUG_TRIGGER`), sans surcoût sur le trafic normal.

Le frontend suit la même idée (`docker/node/Dockerfile`) : Vite ne tourne pas en déployé, il a
produit des fichiers statiques servis par nginx. `API_URL` est un réglage d'**exécution**, pas de
build : `docker-entrypoint.sh` génère `config.js` au démarrage du conteneur, donc une seule image
est promue de la préprod vers la prod, seul `API_URL` diffère. Il doit être l'origine du domaine
**sans** suffixe `/api` (le code l'ajoute lui-même).

```bash
make build-prod          TAG=1.2.3
make build-preprod       TAG=1.2.3
make build-front-prod    TAG=1.2.3
make build-front-preprod TAG=1.2.3
```

Sans `TAG`, le SHA court du commit courant est utilisé : chaque image reste traçable jusqu'à la
révision exacte du code qu'elle contient. Toutes les versions d'images et d'outils sont figées dans
`.env` et `versions.lock` ; seule exception, Composer, épinglé sur sa branche majeure.

## Sécurité

Un signalement responsable passe par le formulaire de contact du site, jamais par une issue
publique : voir [`SECURITY.md`](SECURITY.md).

## Licence

Le **code** de ce dépôt est publié sous [licence MIT](LICENSE). Le **contenu du site** — textes,
études de cas, CV, identité visuelle — reste sous droit d'auteur et n'est pas couvert par cette
licence ; il n'est d'ailleurs pas dans le dépôt, hors les jeux de données de démonstration des
commandes `app:*:seed`.
