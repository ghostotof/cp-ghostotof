# v0.23.0 — Invitations sérialisées, horodatages avec fuseau et secrets hors des traces

Release de sécurité. Elle rend le cycle de vie des invitations sûr en concurrence (génération,
verrou par compte, révocation des liens), stocke chaque horodatage avec son décalage avant le
changement d'heure du 25 octobre, authentifie toute enveloppe Messenger avant `unserialize()`, dérive
une clé par usage d'`APP_SECRET`, et retire mots de passe, jetons, identifiants tentés et données de
formulaire des journaux et des traces. **Une migration** (cinq horodatages en `TIMESTAMP WITH TIME
ZONE`, `invitation_generation`, fuseau de session de la base), **aucun secret ni variable nouveaux**,
aucun droit RBAC nouveau. **Reconnexion forcée** des sessions ouvertes (voir plus bas).

## Comportements qui changent

À lire avant de déployer : un client, un opérateur ou une alerte peut s'appuyer sur l'ancien comportement.

| Cas | Avant | Après | Issue |
|---|---|---|---|
| `app:user:create --password=…` | mot de passe en argument | **option supprimée** : `--username=… --password-stdin < fichier` ; Job `k8s/base/create-user-job.yaml` | #386 |
| Cookies `XSRF-TOKEN` émis avant la release | valides | **invalides** : toute écriture (backoffice, assistant, `POST /api/logout`) répond 403 jusqu'au prochain login | #455 |
| Déconnexion refusée (front) | l'interface restait « connectée » | session locale remise à anonyme, retour à la page de connexion | #455 |
| Identifiant de login hors `USERNAME_PATTERN` (NUL, `\n` final…) | pouvait aboutir (200) | **401** sans requête | #418 |
| Journal `security_audit` `login-failed` / `login-throttled` | saisie brute dans `user` | empreinte `userFingerprint` ; `user` seulement si le compte a été chargé. Les empreintes d'avant ne se relient pas à celles d'après | #418, #455 |
| Mot de passe trop long | 500 au-delà d'environ 2 048 caractères multi-octets | **422**, plafond de 4 096 **octets** (API, lien, CLI) ; refus local sur la page de définition | #386, #410 |
| Nom de technologie vide une fois rogné, ou avec caractère de contrôle | 500 `critical` ou doublon | **422** sur `name`, nom rogné des blancs Unicode | #386 |
| Slug de produit surveillé ou nom de champ à traduire terminé par `\n` | accepté | refusé (ancrage `\z`) | #409 |
| Lien d'invitation après échec d'envoi | valable 48 h | **404**, le jeton non remis est retiré | #425 |
| Lien d'un compte déjà activé, ou dont l'admin a posé le mot de passe | utilisable | **410** (compte déjà activé) ou **404** (jetons purgés) | #458, #460 |
| Lien qui expire pendant le hachage | consommé | **410** | #447 |
| Verrou du compte non obtenu en 5 s | — | **500 nommé** `AccountLockTimeoutException` (réessai côté worker) | #458 |
| Message d'invitation d'une génération dépassée (réessai, relance, double clic) | purgeait le jeton de la relance | écarté avec un `warning` « Invitation ignorée… » | #434, #435 |
| Durée d'un lien (48 h) ou seuil de fraîcheur de la veille (36 h) la veille d'un changement d'heure | 49/47 h, 37/35 h | heures écoulées exactes | #445 |
| `app:contact:purge-failed-messages` | seuil en heure de Paris, purge 1 à 2 h trop tôt | seuil en UTC, annoncé comme tel | #446 |
| Erreur PostgreSQL dans un journal | `DETAIL` / `CONTEXT` avec les valeurs (`Key (email)=(…)`) | valeurs masquées, SQLSTATE et noms de contrainte gardés | #428 |
| Échec d'envoi d'une invitation ou d'un message de contact | message d'origine (URL avec jeton, adresse, texte du visiteur) | message nommé, sans aucune donnée | #419, #427 |

## Invitations et comptes (#434, #435, #425, #447, #458, #459, #460)

- **Chaque invitation a une génération** (`cpg_user.invitation_generation`), portée par le message
  `SendAccountInvitationMessage`. Le worker prend le verrou du compte (`SELECT … FOR UPDATE`, attente
  bornée à 5 s), revérifie ses critères et écarte un message dépassé au lieu de purger le jeton d'une
  relance plus récente. Un message d'avant la release, sans génération, sert la génération 1.
- L'activation prend ce même verrou et purge les autres jetons du compte. Un mot de passe posé par
  l'admin révoque le lien en cours. La purge des invitations en attente (CronJob) revérifie chaque
  compte sous son verrou, et épargne un compte relancé ou activé entre-temps.

## Horodatages et fuseau (#434, #445, #446)

- **Migration `Version20261011120000`** :
  - cinq colonnes passent en `TIMESTAMP(0) WITH TIME ZONE`, valeurs réinterprétées en heure de Paris : `cpg_user.invited_at`, `cpg_user.activated_at`, `password_setup_token.expires_at`, `password_setup_token.used_at` et `watch_snapshot.refreshed_at` ;
  - `invitation_generation` est ajoutée ;
  - `ALTER DATABASE … SET timezone TO 'Europe/Paris'`, qui **exige que le rôle applicatif soit propriétaire de la base**.
- L'ancien code lit le nouveau schéma : migration avant rollout, sans fenêtre de maintenance (`DEPLOY_MAINTENANCE_WINDOW` non défini).
- Les tables sont petites et chaque `ALTER` attend au plus 5 s (`lock_timeout`). Un échec laisse l'ancienne release en service.
- **À livrer avant le 25 octobre 2026** (passage à l'heure d'hiver) : c'est ce qui donne aux liens leurs 48 h exactes et lève l'ambiguïté de l'heure répétée.
- `messenger_messages` reste en heure murale UTC sans fuseau. Une session `psql` est désormais en Europe/Paris : convertir avant de comparer à `now()`.

## Messenger et clés (#444, #455)

- **Toute enveloppe porte un MAC** (`X-Envelope-Mac`), vérifié avant `unserialize()` sur `async` comme sur `failed`. Un MAC faux est refusé.
- **Mode transition dans cette release** : une enveloppe sans MAC est encore acceptée, avec un `warning` « Enveloppe Messenger sans MAC acceptée ». L'exiger est l'objet de #450, après cette release.
- **Chaque usage d'`APP_SECRET` dérive sa clé** (HKDF) : signeur CSRF, empreinte de login, MAC Messenger. D'où la reconnexion forcée.
- Une rotation d'`APP_SECRET` invalide désormais les messages en file : procédure dans `k8s/README.md`.

## Secrets hors des journaux et des traces (#386, #411, #414, #418, #419, #420, #427, #428, #433)

- `#[SensitiveParameter]` sur tout paramètre recevant un mot de passe, un jeton, un JWT ou un secret CSRF, avec des gardes CI.
- `--password` masqué dans l'argv journalisé.
- Middleware DBAL qui masque les valeurs des erreurs PostgreSQL.
- Échecs du worker sans jeton ni donnée du formulaire de contact.
- Traces sans arguments sous PHPUnit.

## Outillage

- `composer.json` exige `php >=8.5`, comme les images (#429).
- Toute regex de `src/` est ancrée par `\z` (#409).
- Nouvelles gardes CI : pas de classe anonyme ni de fonction hors classe (#432), pas de `SendEmailMessage` routé en asynchrone (#426), énumération des enums, interfaces et traits (#421).
- La CI teste avec le fuseau `Europe/Paris` des images (#434).
- Nouveau Job manuel `k8s/base/create-user-job.yaml`, hors `kustomization.yaml` (#386).

## Avant le merge vers `main` (préprod et prod, lecture seule)

- Aucun nom de technologie à rogner ni en doublon (#386) :
  `SELECT name FROM experience_technology WHERE name <> btrim(name) OR btrim(name) IN (SELECT btrim(name) FROM experience_technology GROUP BY 1 HAVING count(*) > 1);` → 0 ligne.
- Aucun compte hors motif, qui ne pourrait plus se connecter (#418) :
  `SELECT username FROM cpg_user WHERE username !~ '^[a-zA-Z0-9_.-]{3,60}$' OR username ~ E'\n';` → 0 ligne.
- File `async` vide et `messenger:failed:show` lu (format du message d'invitation, enveloppes sans MAC).

## À vérifier en préprod

- Smoke tests et audit verts.
- Journal du Job de migration : `Version20261011120000` passe, `ALTER DATABASE` compris. `SHOW timezone` répond `Europe/Paris` dans une nouvelle session.
- Journal du worker : le `warning` « sans MAC » éventuel ne concerne que des messages de l'ancien code, puis cesse.
- Backoffice : après la reconnexion, une écriture et une déconnexion passent.

## À vérifier en prod

- Journal du Job de migration, comme en préprod.
- `messenger:failed:show` : un message d'invitation mis en file pendant le rollout et refusé par l'ancien worker se rejoue une fois le nouveau en place.
- Aucun jeton valable sur un compte actif :
  `SELECT count(*) FROM password_setup_token t JOIN cpg_user u ON u.id = t.user_id WHERE u.activated_at IS NOT NULL AND t.used_at IS NULL AND t.expires_at > now();` → 0.
- Ensuite seulement : #448 (retirer le chemin des messages sans génération) et #450 (exiger le MAC).
