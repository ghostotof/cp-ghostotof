# cp-ghostotof

Projet Symfony dockerisé, généré le 2026-08-08.
Mode backend : **API (API Platform) + frontend Vite dédié**

## Services

| Service    | Rôle                    | Accès depuis l'hôte |
|------------|-------------------------|---------------------|
| `web`      | nginx                   | http://localhost:8080 |
| `backend`  | PHP-FPM + Symfony       | `make sh` |
| `database` | PostgreSQL              | localhost:5432 |
| `adminer`  | Interface admin PostgreSQL | http://localhost:8081 |
| `rabbitmq` | RabbitMQ + UI           | http://localhost:15672 |
| `frontend` | Node + Vite             | http://localhost:5173 |

## Commandes

```bash
make help      # liste toutes les cibles
make up        # démarre la stack
make sh        # shell dans le backend (utilisateur dev)
make logs      # logs en direct
```

## Droits d'accès

Les conteneurs `backend` et `frontend` tournent sous l'UID/GID de
l'utilisateur qui a généré le projet (voir `UID`/`GID` dans `.env`). Les fichiers
créés dans les conteneurs sont donc directement éditables depuis l'IDE.

**Si un autre développeur clone le projet**, il doit adapter `UID`/`GID` dans
`.env` à son poste (`id -u` / `id -g`) puis relancer `make build`.

## Images et environnements

Le `Dockerfile` est multi-stage. Trois cibles sont exploitables :

| Cible        | Usage        | Code       | Construite via |
|--------------|--------------|------------|----------------|
| `dev`        | poste local  | bind mount | `make build` |
| `production` | déploiement  | copié      | `make build-prod` |
| `preprod`    | validation   | copié      | `make build-preprod` |

`preprod` est construite **à partir de** `production` : ses couches applicatives
sont identiques à l'octet près. Ce qui est validé en préprod est donc bien
l'artefact déployé, augmenté d'Xdebug en mode profilage déclenché manuellement
(`XDEBUG_TRIGGER`) — donc sans surcoût sur le trafic normal.

L'ordre du pipeline reste dev → préprod → prod, indépendamment de l'ordre de
déclaration dans le fichier, qui n'est qu'une contrainte de Docker.

### Frontend

En développement, le service `frontend` utilise l'image Node officielle telle
quelle : il n'y a rien à construire, `make build` ne construit que le backend.

En déployé, Vite ne tourne pas — il a produit des fichiers statiques, servis par
nginx (`docker/node/Dockerfile`, cibles `production` et `preprod`) :

```bash
make build-front-prod    API_URL=https://api.exemple.com TAG=1.2.3
make build-front-preprod API_URL=https://api-preprod.exemple.com TAG=1.2.3
```

`API_URL` est obligatoire au build : Vite **inline** les variables `VITE_*` dans
le bundle. Changer l'URL de l'API impose donc de reconstruire l'image — ce n'est
pas un réglage d'exécution.

Là encore, `preprod` est construit `FROM production` : les fichiers JS/CSS
servis sont identiques, seules s'ajoutent les source maps.

```bash
make build-prod TAG=1.2.3
make build-preprod TAG=1.2.3
```

Sans `TAG`, le SHA court du commit courant est utilisé : chaque image reste
traçable jusqu'à la révision exacte du code qu'elle contient.

## RGPD

Le registre des traitements de données personnelles (formulaire de contact,
authentification, logs techniques...) est tenu dans
[`docs/rgpd/registre-traitements.md`](docs/rgpd/registre-traitements.md). Les
pages publiques "Mentions légales" et "Politique de confidentialité" du
frontend en sont le résumé destiné aux visiteurs.

## Versions

Toutes les versions sont figées — voir `versions.lock` et le fichier `.env`.

Seule exception : **Composer**, épinglé sur sa branche majeure (`composer:2`).
Les correctifs 2.x sont donc récupérés à chaque `make build`, mais un futur
Composer 3 ne sera jamais installé sans modification explicite du `.env`.
