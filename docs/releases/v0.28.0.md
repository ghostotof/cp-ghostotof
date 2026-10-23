# v0.28.0 — Serveur MCP (phase 1) et PHP 8.5.12

Cette release apporte deux choses. D'abord le correctif de sécurité de PHP 8.5.12 : deux exécutions de code à
distance non authentifiées, notées 9.2 en CVSS v4. Ensuite la phase 1 du serveur MCP (spec 0007, ADR 0006) :
tout assistant compatible MCP peut désormais lire le portfolio sur `/api/mcp`, aux paliers anonyme et de
base, sans jeton. Le palier de confiance, par connexion OAuth 2.1, viendra avec la phase 2.

## Ce qui change

- **PHP 8.5.12** (#687) : corrige CVE-2026-109618 (une corruption après OOM) et CVE-2026-109619 (un état
  global relu par la requête suivante du même worker). Seul le tag d'image change.
  `session.upload_progress.enabled` passe à `Off` dans `php.prod.ini` et `php.dev.ini`. Rien ne s'en sert,
  et l'avis recommande de l'éteindre même après la mise à jour. Suivi : refus du multipart au backend (#686).
- **Serveur MCP sur `/api/mcp`** (Streamable HTTP, lecture seule, aucun appel de modèle) :
  - **Catalogue** (#640, #641) : `get_about`, `get_quality`, `get_technical_background`,
    `list_contributions`, `list_incidents`, `get_tech_watch`, `get_contact_info`, `get_anonymous_cv`,
    `list_case_studies`. Chaque outil rend ce que sert la route de même palier. `get_nominative_corpus` est
    listé mais refusé tant que la phase 2 n'est pas livrée.
  - **Prompt `present_for_role`** (#642, #662) : il présente le profil pour un poste. Le rôle saisi est
    délimité par une balise à nonce et nettoyé, et l'auteur n'est jamais nommé (garde D8 en CI).
  - **Refus avant le SDK** (#644, #664, #671, #675) : les lots, les réponses du client, un `initialize`
    trop lourd et un `_meta` invalide sont refusés. `subscriptions/listen` répond `-32601`, et les
    capacités annoncées sont celles que le serveur tient vraiment.
  - **Journaux** (#643, #664) : canal `mcp` du SDK plafonné à `warning`. Le nouveau canal `mcp_usage` écrit
    une ligne `info` par appel, avec l'IP tronquée (/24, /48) et aucun contenu. Le registre RGPD est mis à
    jour.
- **Page « Brancher votre assistant »** (#645) : le pas-à-pas pour Claude, ChatGPT, Cursor et PhpStorm, avec
  un lien ajouté dans le pied de page.
- **Assistant de parcours** (#667) : le corpus passe par le même neutraliseur que le rôle du prompt MCP
  (NFKC, invisibles, sosies de chevrons). Les nombres en exposant, en indice et les fractions gardent leur
  forme.
- **Toute l'API ignore `X-HTTP-Method-Override`** (#675) : aucun client du site ne l'envoie.
- **Journaux** (#678) : en production, les 404, 405 et 400 du framework passent en `info` et ne sortent
  donc plus dans `kubectl logs` (`LOG_LEVEL=warning`). Une route supprimée qu'un front appelle encore ne se
  voit plus que dans l'access log de nginx. Le CV absent et les réglages « À propos » absents restent en
  `warning`.
- **Procédure des rôles** (#638) : `privileges.sql` n'accorde `pg_read_all_data` au lecteur que s'il lui
  manque. Le rejouer n'imprime plus l'avis qui nommait le superutilisateur de l'image.
- **Interne** : une seule racine pour lire les fichiers du dépôt depuis les tests (#629, #639).

## À savoir pour le déploiement

- **Aucune migration**, aucun changement de clé : pas de fenêtre de maintenance.
- **Rollout du backend** : le moteur PHP change, et le hash de `backend-nginx-conf` aussi (zones `mcp`,
  `mcpsession`, `mcpconn` et la location `= /api/mcp`). Le sidecar est remplacé sans coupure. PostgreSQL
  et RabbitMQ ne redémarrent pas.
- **Nouveau CronJob `cache-items-prune`** (#644) : il lance `cache:pool:prune` toutes les heures, et le
  ménage quotidien (`messenger-purge`) ne le fait plus. Une session MCP vit au plus deux heures en base.
- **Aucune variable d'hôte à poser** : `allowed_hosts` dérive d'`APP_FRONTEND_BASE_URL`. L'`initialize` du
  smoke MCP sur l'hôte public le prouve : un 403 voudrait dire qu'elle manque.
- **Smoke préprod** : nouvelle étape `tools/smoke-mcp.sh`, avec `initialize`, `DELETE` de la session, 413
  au-delà de 16 Kio et 429 de la zone `mcp`. Lire son 429.
- **Pas besoin de rejouer la procédure des rôles** : ni le schéma ni les rôles ne changent. #638 prendra
  effet à son prochain passage.

## Vérifications

- **Préprod** : brancher Claude Code sur `/api/mcp` avec l'en-tête Basic de la préprod. `tools/list` doit
  rendre le catalogue, `get_about` et `present_for_role` doivent répondre.
- **Prod** : brancher claude.ai, ChatGPT, Cursor et PhpStorm en suivant la page « Brancher votre
  assistant ».
- **Prod** : `php -v` dans un pod `backend` doit afficher 8.5.12. `/stack` l'affichera au prochain relevé
  de la veille.
- **Prod, la première semaine** : relever dans les journaux nginx les 429 de la zone `mcp` (60 req/min par
  IP). Les IP de sortie de claude.ai et de ChatGPT sont partagées : si ces 429 frappent des appels
  légitimes, rouvrir le débit de la zone (#673).
