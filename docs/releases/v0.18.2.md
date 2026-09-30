# v0.18.2 — Les limiteurs de débit prennent un verrou partagé entre pods

Correctif de sécurité backend (#272). Nouvelle image backend (dépendance `symfony/lock`,
configuration PHP-FPM) ; aucune migration, aucun manifeste Kubernetes ni secret nouveau.
Déploiement sans interruption.

## Les quotas décomptaient une unité pour une salve (#272)

`symfony/lock` n'était pas installé : chaque limiteur de débit recevait un verrou `null`, et
`consume()` faisait un lire-modifier-écrire **non atomique** sur `cache.app`. Deux requêtes
lisant la même fenêtre écrivaient chacune « n + 1 ». Reproduit en dev : vingt appels simultanés
sur la même clé ne décomptaient **qu'une** unité. Tous les limiteurs étaient concernés —
`login_throttling`, contact, palier de base, définition de mot de passe, et le quota facturé de
l'assistant de traduction.

- Chaque limiteur prend désormais un **advisory lock PostgreSQL**, partagé entre pods, sans
  table ni migration. Avec le verrou, les vingt appels décomptent vingt unités.
- Son DSN est **dérivé de `DATABASE_URL`** par un env processor (`pg_advisory:`) : pas de second
  secret portant le mot de passe de la base. Tout autre schéma qu'une URL PostgreSQL est refusé,
  sans jamais reprendre l'URL dans l'erreur.
- Le `LOCK_DSN=flock` posé par la recette Flex est retiré : un `flock` est local au pod.
- Coût mesuré : ~1,5 ms par `consume()`, plus une seconde connexion PostgreSQL sur les requêtes
  qui atteignent un limiteur.

## Suites de la relecture de sécurité

- **Aucune requête web n'attend un verrou indéfiniment** : `www.prod.conf` pose
  `lock_timeout=5s` (via `PGOPTIONS`, workers FPM uniquement — la console, donc les migrations,
  n'est pas concernée) et `request_terminate_timeout = 65s`. Une panne ou une attente trop
  longue du verrou refuse la requête (500), elle ne la laisse jamais passer.
- Le canal de log `lock` a son propre handler plafonné à `notice` : la préprod, en `debug`,
  aurait sinon journalisé une IP ou un identifiant à chaque requête limitée.
- `RateLimiterStorageTest` confronte sa liste aux limiteurs du conteneur : un limiteur ajouté
  sans contrôle de stockage ni de verrou fait rougir la suite.
- ADR 0005 amendé (D8 à D10).

Suivis ouverts : #276 (503 sur panne du verrou), #277 (test de concurrence rejouable), #278
(`zend.exception_ignore_args`), #279 (keepalives TCP de PostgreSQL).

## Aussi dans cette version

- Dépendances frontend de l'outillage : `brace-expansion` en version corrigée (trois advisories
  *high* de déni de service, rien dans le bundle servi) (#280).
- Les specs livrées 0001 à 0004 rejoignent leurs plans dans `.claude/specs/archive/` (#258).

## À vérifier en préprod

`tools/smoke-login-throttling.sh` traverse le limiteur de login, donc le verrou, sur un vrai pod
et la vraie `DATABASE_URL` : c'est le contrôle qui confirme que son schéma est bien accepté.
