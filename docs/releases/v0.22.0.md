# v0.22.0 — Erreurs typées, écritures ordonnées verrouillées et déploiement qui échoue tôt

Release de consolidation. Elle donne un type et un statut précis aux erreurs qui n'en avaient pas,
verrouille les écritures qui placent un contenu ordonné, fait échouer le déploiement en quelques
secondes quand un conteneur ne démarrera pas, et laisse l'assistant de parcours renvoyer une réponse
anglaise entière. **Une migration** (contrainte `CHECK` sur les années d'expérience), **un droit RBAC
de plus** pour le déployeur (déjà appliqué en préprod et en prod), aucun secret nouveau.

## Comportements qui changent

À lire avant de déployer : un client ou une alerte peut s'appuyer sur l'ancien comportement.

| Cas | Avant | Après | Issue |
|---|---|---|---|
| Connexion refusée par `login_throttling` | 401 de Lexik | **429** `/errors/rate-limited` avec `Retry-After`, la page de connexion annonce le délai | #399 |
| Quotas de contact, de définition de mot de passe et de traduction | 429 de `type` `/errors/429` | 429 de `type` **`/errors/rate-limited`**, comme les zones nginx (le `title` change aussi) | #369 |
| `PUT` du backoffice avec un `id` dans le corps | 400 `critical` (IRI non résolue) | **400 `info`**, corps refusé | #373 |
| Groupe de traduction aux positions divergentes | 409 « langue déjà présente » sur une paire FR/EN | **500 `critical`** (`TranslationGroupHasSeveralPositionsException`), quelle que soit la langue | #384 |
| Verrou d'ordre non obtenu en 5 s | — | **500 nommé** `OrderScopeLockTimeoutException` | #389 |
| Défaut du Serializer côté sortie (réponse non encodable…) | 400 | **500**, c'est un défaut serveur | #360 |
| `GET /api` (point d'entrée Hydra) | ne rendait qu'une erreur de format (400 `critical` en dev/test) | désactivé dans tous les environnements | #360 |
| Années d'une technologie hors de `[0, 100]` ou non finies | acceptées (`1e999` → `INF`, puis 500 sur la page publique) | **422** nommant `years` dans l'API, refus en CLI, et la base refuse aussi | #372 |
| Message de l'assistant renvoyé | tronqué à 4 000 caractères | jusqu'à **5 000** caractères | #406 |

## Contenus ordonnés (#389)

- **Toute écriture qui place une entrée prend le verrou de son périmètre** : création, modification
  et réordonnancement, dans les neuf contextes ordonnés. Un réordonnancement concurrent d'une
  « Créer la version EN » laissait un groupe sur deux positions, et deux créations simultanées
  recevaient la même position.
- Verrou consultatif PostgreSQL de transaction, attente bornée à 5 s dans PHP-FPM.

## Expérience : années bornées (#372)

- **Migration `Version20261006120000`** : une contrainte `CHECK` borne `experience_technology.years`
  à `[0, 100]`. Avant de la poser, la migration ramène toute ligne fautive dans les bornes, la passe
  en `secondary` et l'écrit en **`warning`** dans la sortie du Job de migration, avec sa valeur
  d'origine.
- L'ancien code lit et écrit ce schéma sans difficulté : migration avant rollout, sans fenêtre de
  maintenance (`DEPLOY_MAINTENANCE_WINDOW` non défini).

## Livraison (#353)

- **`tools/wait-rollout.sh` remplace les 13 attentes du déploiement** (Jobs de migration et de seed,
  Deployments). Il échoue en quelques secondes en nommant le pod, le conteneur et la raison :
  `CreateContainerConfigError`, échec de pull, `InvalidImageName`, Job `Failed`, Secret ou ConfigMap
  absent monté en volume (`FailedMount`). Avant, il fallait attendre l'expiration de chaque délai.
- Le Role du déployeur lit les `events` du namespace (`get`, `list`), pour voir les `FailedMount`.
  **Déjà appliqué en préprod et en prod.** Sans ce droit, le script avertit et le déploiement
  continue.
- **Première exécution réelle sur cette release** : les cas d'échec ne sont éprouvés qu'hors ligne.

## Erreurs et exceptions (#338, #383, #360, #373)

- **Plus aucune exception générique levée nue dans `src/`** (`\LogicException`, `\RuntimeException`,
  `\InvalidArgumentException`, `\Exception`) : chaque échec a sa classe, ciblable dans
  `framework.exceptions` et reconnaissable dans les journaux. Un garde CI interdit leur retour.
- Effets visibles : un manifeste de paquets impossible à écrire casse désormais le `docker build` ;
  `app:user:create` ne rogne plus le mot de passe saisi à l'invite, et en mode `-n` une option
  absente est refusée en la nommant.
- Les entrées de repli d'API Platform sont contrôlées comme les autres : leur statut et leur niveau
  de journalisation doivent concorder (#373).

## Sécurité et quotas (#399, #369, #361)

- Le refus de connexion pour excès de tentatives porte son délai (`Retry-After`) et le même type
  que tous les autres quotas. Les autres échecs de connexion gardent le 401 de Lexik, à l'octet
  près.
- Un garde CI exige qu'un quota anonyme ait son propre événement `…-throttled` dans
  `security_audit`, ou une justification écrite.
- Le smoke test de préprod `smoke-login-throttling.sh` attend désormais le 429 de Symfony, distinct
  de celui de la zone nginx par son `Retry-After` et son `detail`.

## Outillage et documentation

- Toute classe s'importe par `use`, natives comprises, et les `use` sont triés. Deux gardes CI
  vérifient l'ordre des imports et la qualification des fonctions natives (#391, #394).
- Test de concurrence rejouable des limiteurs de débit, avec de vrais processus (#277).
- Les 429 de nginx sont lisibles sans CORS en préprod et en prod, où le front partage l'origine de
  l'API. Seul le dev ne peut pas les lire, c'est documenté (#368).
- Archive épurée des assistants de bascule DNS et messagerie (#231).

## À vérifier en préprod

- Smoke tests et audit verts, dont `smoke-login-throttling` sur le nouveau 429.
- `deploy-preprod` : lire les lignes `Attente de …` de `wait-rollout.sh`, première exécution réelle.
- Journal du Job de migration : aucun `warning` attendu en préprod (contenu issu des seeds).
- `\d experience_technology` : la contrainte `CHECK` est présente.
- Page de connexion : au-delà du seuil, le message annonce le délai.

## À vérifier en prod

- Journal du Job de migration : chaque `warning` nomme une ligne ramenée dans les bornes et passée
  en `secondary`, à corriger dans le backoffice.
- `deploy-prod` : lignes `Attente de …` de `wait-rollout.sh`.
