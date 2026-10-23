# v0.29.0 — Suivis de sécurité de la phase 1 MCP

Cette release solde les suivis ouverts par la phase 1 du serveur MCP et par le correctif PHP 8.5.12. Le
backend ne lit plus aucun corps multipart. Les noms de version venus d'endoflife.date sont contrôlés avant
d'atteindre la veille et les assistants. La langue du prompt `present_for_role` se complète. Enfin, la
configuration de sécurité et les routes sont gardées identiques d'un environnement à l'autre.

## Ce qui change

- **Corps multipart refusés** (#686, suivi de CVE-2026-109618) : le sidecar nginx répond `415`
  `application/problem+json` (`/errors/415`) à tout corps `multipart/form-data`, quelle que soit la méthode,
  avant les zones et avant PHP. En seconde barrière, PHP n'en analyse plus aucun :
  `max_multipart_body_parts = 0`, `file_uploads = Off` et `post_max_size = 1M` (la borne de nginx), et
  `user_ini.filename` est vidé. Aucune route ne lisait de multipart. L'urlencoded reste admis, car le point
  de jeton OAuth de la phase 2 MCP le recevra.
- **Veille technique** (#658) : un nom de cycle ou de version d'endoflife.date doit avoir la forme d'un
  numéro (32 octets au plus) à l'écriture du relevé, puis de nouveau à sa relecture. Un nom hostile ne
  peut donc plus atteindre `/stack` ni l'outil MCP `get_tech_watch`, ce qui ferme la piste d'une injection de
  prompt indirecte. Un produit dont le cycle est refusé n'est pas rapproché.
- **MCP** (#693) : `completion/complete` sur l'argument `locale` du prompt `present_for_role` propose les
  langues du site au lieu d'une liste vide.
- **Interne** (#659) : deux gardes de CI comparent la section `security`, la configuration Lexik et les
  routes compilées de l'environnement `test` à celles des autres, `prod` compris : les gardes d'exposition
  ne lisent que `test`. Une règle ou une route déclarée pour un seul environnement fait échouer la suite.
  Suivi : #698.

## À savoir pour le déploiement

- **Aucune migration**, aucun changement de clé ni de secret : pas de fenêtre de maintenance.
- **Rollout du backend** : le hash de `backend-nginx-conf` change (le `map` du multipart et la location
  `@unsupported_media_type`). Le sidecar est remplacé sans coupure. PostgreSQL et RabbitMQ ne redémarrent
  pas.
- **Pas besoin de rejouer la procédure des rôles** : ni le schéma ni les rôles ne changent.
- **En dev** : `make build` pour embarquer le nouveau `php.dev.ini`.

## Vérifications

- **Préprod et prod** : un `POST` en `multipart/form-data` sur n'importe quelle route de l'API doit
  répondre `415` avec le corps problem+json de nginx, et un `POST` JSON (formulaire de contact) doit
  passer comme avant.
- **Prod** : `/stack` doit afficher les mêmes statuts qu'avant la release. Un produit passé en statut
  inconnu signalerait un nom de cycle refusé à la relecture. Au prochain passage du CronJob
  `watch-refresh`, un refus à l'écriture laisse un `warning` « nom hors de la forme d'un numéro de
  version » dans ses journaux.
