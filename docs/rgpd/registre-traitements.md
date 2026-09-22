# Registre des traitements de données personnelles

Registre tenu au sens de l'article 30 du RGPD. Ce document est la source de
vérité technique ; la page publique **Politique de confidentialité** du site
(`frontend/src/presentation/pages/PrivacyPolicyPage.vue`) en est le résumé
destiné aux visiteurs.

**Responsable de traitement** : l'éditeur du site, personne physique agissant à
titre non professionnel (cf. `docs/rgpd/registre-traitements.md` et les
mentions légales pour le détail du statut), joignable à
`contact@cp-ghostotof.com`.

**Dernière mise à jour** : 2026-09-21 (3e audit de sécurité : ajout du §6 « Journal
d'audit de sécurité », stockage des compteurs de limitation de débit au §2, précisions
sur les journaux techniques au §5 ; §3 repris en entier — accès de base, invitation par
e-mail et comptes nominatifs, que l'ancienne rédaction niait ; §7 et §8 complétés en
conséquence).

---

## 1. Formulaire de contact

| | |
|---|---|
| **Composants concernés** | `backend/src/Contact/Presentation/ApiResource/ContactMessageResource.php` → `Infrastructure/ApiPlatform/ContactMessageProcessor.php` → `Application/ContactMessageSender.php` → Messenger (transport `async`, RabbitMQ) → `Infrastructure/Messenger/SendContactMessageHandler.php` (envoi via Symfony Mailer). Purge des échecs : `backend/src/Contact/Presentation/Command/PurgeFailedContactMessagesCommand.php` (`app:contact:purge-failed-messages`), planifiée par le CronJob `contact-failed-messages-purge` (`k8s/base/messenger-purge-cronjob.yaml`) |
| **Finalité** | Répondre aux demandes de contact d'un visiteur |
| **Base légale** | Mesures précontractuelles prises à la demande de la personne concernée (art. 6-1-b RGPD) ; à défaut, intérêt légitime à pouvoir échanger avec un visiteur qui en fait la demande |
| **Données collectées** | Nom, adresse email, message (champ libre) |
| **Durée de conservation** | **Cas nominal : aucune persistance en base.** Le message transite par une file RabbitMQ (transport `async`) jusqu'à son envoi par email, puis est supprimé de la file. **Cas d'échec d'envoi** (SMTP indisponible, retries épuisés) : le message est routé vers le transport `failed` (`failure_transport: failed` dans `config/packages/messenger.yaml`), stocké en base dans la table `messenger_messages` — il contient alors le nom, l'email et le message du visiteur. **Rétention : 30 jours maximum**, appliquée par la commande `app:contact:purge-failed-messages` (paramètre `--older-than`, défaut `30 days`), exécutée quotidiennement par le CronJob `contact-failed-messages-purge` (03:17). L'email effectivement envoyé est par ailleurs conservé dans la boîte `contact@cp-ghostotof.com`, selon la politique du fournisseur de messagerie retenu en production. |
| **Destinataires** | L'éditeur du site, via la boîte `contact@cp-ghostotof.com` (variable `CONTACT_RECIPIENT_EMAIL`). Sous-traitants techniques : **Scaleway SAS — Transactional Email** (envoi du message de notification, `MAILER_DSN=scaleway+api://…`, infrastructure en France) et **Cloudflare, Inc. — Email Routing** (redirection de `contact@cp-ghostotof.com` vers la boîte réelle de l'éditeur, société établie aux États-Unis, cf. §8). En dev, `MAILER_DSN=null://null` (aucun envoi). |
| **Mesures de sécurité** | Validation stricte côté API (`Assert\Email`, longueurs bornées), honeypot anti-bot, rate limiting (cf. §2), transport chiffré vers le serveur SMTP (dépend du DSN de production) |

## 2. Anti-spam / limitation de débit des endpoints publics

| | |
|---|---|
| **Composants concernés** | `config/packages/rate_limiter.yaml` (limiteurs `contact_form`, `account_password_setup`, `base_access`) et leurs adaptateurs `backend/src/Contact/Infrastructure/RateLimiter/SymfonyContactRateLimiter.php`, `backend/src/Security/User/Infrastructure/RateLimiter/SymfonyPasswordSetupRateLimiter.php`, `SymfonyBaseAccessRateLimiter.php` ; `login_throttling` du firewall `login` (`config/packages/security.yaml`) ; zones nginx `contact`, `pwsetup`, `baseaccess`, `login`, `publicapi` (`docker/nginx/default.conf`, `k8s/base/backend-nginx.conf`) |
| **Finalité** | Lutte contre les abus, le spam et les tentatives de force brute sur les endpoints publics (`POST /api/contact`, `POST /api/account/password-setup` et `…/validate`, `POST /api/account/base-access`, `POST /api/login_check`) |
| **Base légale** | Intérêt légitime (sécurité du service, art. 6-1-f RGPD) |
| **Données collectées** | Adresse IP de l'appelant, utilisée uniquement comme clé de comptage. Le `login_throttling` compte aussi par couple (IP, identifiant saisi). Le quota de l'assistant de traduction du backoffice (`translation_assistant`) est, lui, indexé sur le nom de compte et non sur une IP. Les compteurs nginx ne retiennent qu'une forme binaire de l'adresse, en mémoire du processus |
| **Durée de conservation** | Compteurs Symfony : pool `cache.app`, adossé depuis le correctif ADR 0005 (`docs/adr/0005-etat-hors-du-pod.md`, hotfix `v0.14.1`) à **Doctrine DBAL**, table `cache_items` de la base applicative, et non plus au système de fichiers du pod. La donnée ne vit que le temps de la fenêtre glissante du limiteur (1 heure pour `contact_form`, `account_password_setup` et `base_access` ; 15 minutes pour `login_throttling`) ; les entrées expirées sont supprimées par `cache:pool:prune`, exécuté quotidiennement par le CronJob de maintenance (`k8s/base/messenger-purge-cronjob.yaml`). Aucun historique, aucune agrégation, aucun export |

## 3. Comptes, invitation et authentification

Trois situations distinctes, décrites par l'ADR 0003 (`docs/adr/0003-paliers-d-acces.md`) et
l'ADR 0001 (`docs/adr/0001-admin-user-provisioning.md`) : l'**accès de base** sans compte, le
**compte nominatif** accordé sur invitation, et le compte d'administration de l'éditeur.

### 3.1 Accès de base (« Accès instantané »), sans compte

| | |
|---|---|
| **Composants concernés** | `backend/src/Security/User/Presentation/Controller/BaseAccessController.php` (`POST /api/account/base-access`) ; côté navigateur `frontend/src/application/auth/useAuth.ts` |
| **Finalité** | Ouvrir, à la demande du visiteur et en un clic, les contenus du palier de base (études de cas, CV sans identité) |
| **Base légale** | Exécution du service demandé par la personne (art. 6-1-b RGPD) |
| **Données traitées** | **Aucun compte n'est créé et rien n'est écrit en base.** Le jeton porte un identifiant éphémère aléatoire (`guest-…`) qui ne désigne personne. L'adresse IP de l'appelant sert au comptage anti-abus (§2) et figure dans le journal d'audit (§6) |
| **Traceurs posés** | Cookies `BEARER` et `XSRF-TOKEN` (mêmes attributs qu'au §3.3), d'une durée de **15 minutes** ; `localStorage`, clé `baseAccessExpiresAt` : l'heure d'expiration de l'accès, pour que l'interface cesse d'annoncer un accès expiré — jamais transmise au serveur. Strictement nécessaires à un service expressément demandé, donc exemptés de consentement (art. 82 de la loi Informatique et Libertés, lignes directrices CNIL du 17 septembre 2020) |
| **Durée de conservation** | 15 minutes, ou jusqu'à « Terminer cet accès » (`POST /api/logout`, qui expire les cookies) |

### 3.2 Invitation et compte nominatif

| | |
|---|---|
| **Composants concernés** | `backend/src/Security/User/*` : `Application/CpgUserInviter.php`, `Infrastructure/Messenger/SendAccountInvitationHandler.php`, `Application/PasswordSetupService.php`, `Application/PendingInvitationPurger.php` (interface + purge use case, CLI `app:user:purge-pending-invitations`), entités `Domain/Entity/CpgUser.php` et `PasswordSetupToken.php` ; gabarits `backend/templates/emails/account_invitation.*` ; page `frontend/src/presentation/pages/SetPasswordPage.vue` |
| **Finalité** | Accorder à une personne désignée, à l'initiative de l'éditeur, un accès nominatif aux contenus réservés (CV complet, palier `ROLE_TRUSTED`) ; lui permettre de définir elle-même son mot de passe |
| **Base légale** | Intérêt légitime de l'éditeur à accorder un accès nominatif plutôt qu'un identifiant partagé (art. 6-1-f RGPD) pour l'envoi de l'invitation, l'adresse étant fournie par la personne ou dans le cadre d'un échange professionnel avec elle ; exécution du service demandé (art. 6-1-b) une fois le compte activé |
| **Personnes concernées** | Les personnes invitées par l'éditeur (recruteurs, interlocuteurs professionnels). Aucune inscription publique n'existe |
| **Données traitées** | **Adresse e-mail** de la personne invitée (`cpg_user.email`, unique), nom d'utilisateur **dérivé de la partie locale de cette adresse** (`UsernameGenerator`), dates d'invitation et d'activation, rôles, mot de passe **haché** (`password_hashers: auto`, jamais lisible). Jeton de définition du mot de passe : seule son **empreinte SHA-256** est stockée (`password_setup_token`), usage unique, validité 48 h. Les comptes créés en ligne de commande (`app:user:create`, amorçage du compte de l'éditeur) n'ont pas d'adresse e-mail |
| **Durée de conservation** | Tant que l'accès est accordé : le compte et son adresse sont supprimés par l'éditeur depuis le backoffice, à la fin du besoin ou sur simple demande de la personne (§7) ; la suppression emporte ses jetons (`ON DELETE CASCADE`). Jeton : 48 h, remplacé à chaque renvoi d'invitation. **Invitation jamais activée : 30 jours après la dernière invitation**, puis suppression automatique du compte et de ses jetons par `app:user:purge-pending-invitations` (CronJob de maintenance quotidien, 03:17, `k8s/base/messenger-purge-cronjob.yaml`), tracée dans le journal d'audit (§6, événement `user-purged`, acteur `system`, jamais l'adresse). Un compte en attente qui porterait `ROLE_SUPER` n'est pas purgé automatiquement : sa suppression reste une décision manuelle. Renvoyer l'invitation depuis le backoffice repousse le délai. |
| **Destinataires** | L'éditeur et les comptes `ROLE_SUPER` (seuls à voir les adresses, `GET /api/backoffice/users`). Sous-traitant : **Scaleway SAS — Transactional Email** (envoi de l'invitation, France). L'adresse n'est jamais exposée avant authentification, jamais écrite dans le journal d'audit (§6) ; le message Messenger ne transporte que l'identifiant du compte et la langue, ni adresse ni jeton |
| **Mesures de sécurité** | Jeton transmis dans le **fragment** du lien puis dans le corps des requêtes, jamais dans un chemin d'URL (constat A7) ; réponses identiques quel que soit l'état du lien ; limitation de débit par IP (§2). Le mot de passe choisi est contrôlé contre les fuites connues (`NotCompromisedPassword`) : seuls les **5 premiers caractères de son empreinte SHA-1** sont envoyés à `api.pwnedpasswords.com` (k-anonymat) — ni le mot de passe, ni l'identité de la personne |

### 3.3 Authentification

| | |
|---|---|
| **Composants concernés** | `backend/src/Security/Authentication/*` (`json_login`, LexikJWT, `AuthCookieFactory`, gardes CSRF) |
| **Finalité** | Permettre aux titulaires d'un compte d'accéder aux contenus de leur palier (CV nominatif pour `ROLE_TRUSTED`, backoffice pour `ROLE_SUPER`) |
| **Base légale** | Exécution du service demandé par l'utilisateur (art. 6-1-b RGPD) |
| **Données traitées** | Nom d'utilisateur et mot de passe saisis ; le JWT contient le nom d'utilisateur et les rôles. Échecs et réussites de connexion : cf. §2 (compteurs) et §6 (journal) |
| **Cookies posés** | `BEARER` (JWT, `HttpOnly`, `Secure` en prod, `SameSite=Lax`) ; `XSRF-TOKEN` (lisible en JS, protection CSRF par double soumission, `Secure` en prod, `SameSite=Lax`). Posés **uniquement** à la connexion ou à la demande d'accès de base, jamais à la simple visite ; strictement nécessaires, donc exemptés de consentement (mêmes références qu'au §3.1). Aucun bandeau ni recueil de consentement n'est donc requis ; l'information est donnée par la politique de confidentialité |
| **Durée de conservation** | Jeton de connexion : **1 heure** (`token_ttl: 3600`) ; cookies expirés explicitement à la déconnexion (`CookieLogoutListener`). **Limite connue** : la déconnexion ne révoque pas le jeton, qui reste techniquement valide jusqu'à son expiration s'il a été copié auparavant (risque accepté, ADR 0003, amendement du 2026-09-21) |

## 4. Préférences mémorisées dans le navigateur

### 4.1 Préférence de langue

| | |
|---|---|
| **Composant concerné** | `frontend/src/presentation/router/preferredLocale.ts` (`localStorage`, clé `LOCALE_STORAGE_KEY`) |
| **Finalité** | Mémoriser la langue choisie par le visiteur entre deux visites |
| **Base légale** | Non applicable — donnée non identifiante, stockage purement fonctionnel côté navigateur, aucun consentement requis |
| **Durée de conservation** | Jusqu'à suppression par l'utilisateur (stockage navigateur local, jamais transmis au serveur) |

### 4.2 Bandeau d'information sur les cookies

| | |
|---|---|
| **Composants concernés** | `frontend/src/application/cookieNotice/useCookieNotice.ts` (`localStorage`, clé `cookieNoticeDismissed`), `frontend/src/presentation/layout/CookieNotice.vue` |
| **Finalité** | Informer le visiteur que le site ne pose que des traceurs strictement nécessaires, et ne pas réafficher cette information une fois lue. **Bandeau d'information, pas de recueil de consentement** : tous les traceurs du site sont exemptés (§3), il n'y a donc ni acceptation ni refus, et la valeur mémorisée ne conditionne rien |
| **Base légale** | Non applicable — donnée non identifiante (`1`), stockage purement fonctionnel côté navigateur, aucun consentement requis |
| **Durée de conservation** | Jusqu'à suppression par l'utilisateur (stockage navigateur local, jamais transmis au serveur) |

## 5. Logs techniques

| | |
|---|---|
| **Composants concernés** | nginx : sidecar du backend (`k8s/base/backend-nginx.conf`) et frontend (`docker/node/nginx.conf`), plus l'ingress du cluster ; en développement `docker/nginx/`. Journaux applicatifs du conteneur backend : Monolog sur `php://stderr` au format JSON (`config/packages/monolog.yaml`), donc collectés par Kubernetes en production et lisibles par `docker compose logs` en développement |
| **Finalité** | Sécurité, diagnostic, détection d'incidents |
| **Base légale** | Intérêt légitime (art. 6-1-f RGPD) |
| **Données collectées** | Adresse IP, user-agent, URL et méthode HTTP, code de réponse. **Aucun secret dans une URL** : depuis le 3e audit (constat A7, tâches T4.1/T4.2), le jeton de définition de mot de passe voyage dans le corps de la requête côté API et dans le **fragment** du lien côté e-mail — un fragment n'est jamais transmis au serveur. Aucun journal d'accès, ni du sidecar, ni du frontend, ni de l'ingress, ne peut donc plus contenir un jeton d'invitation exploitable |
| **Durée de conservation** | Bornée par la rotation des journaux de conteneur du nœud (réglage kubelet) et par le cycle de vie du pod : les journaux d'un pod remplacé disparaissent avec lui. **Aucun collecteur ni agrégateur de journaux n'est déployé** (aucun objet de ce type dans `k8s/`) : il n'existe ni export, ni archivage, ni copie hors du nœud. La valeur exacte de rotation n'est pas maîtrisée depuis ce dépôt (Kubernetes Kapsule est managé par l'hébergeur) — **à confirmer auprès de l'hébergeur et à consigner ici**. Pour mémoire, la recommandation CNIL usuelle pour des journaux de sécurité est de 6 à 12 mois maximum |

## 6. Journal d'audit de sécurité

| | |
|---|---|
| **Composants concernés** | `backend/src/Security/Authentication/Infrastructure/Log/SecurityAuditLogger.php` (point d'entrée unique, interface `Application/SecurityAuditLoggerInterface`), appelé par `Infrastructure/Log/SecurityEventsSubscriber.php`, les deux gardes CSRF, `BaseAccessController` et les cas d'usage de `src/Security/User/Application/`. Canal Monolog dédié `security_audit`, handler `stream` vers `php://stderr` au format JSON, niveau `info` en dur et jamais bufferisé (`config/packages/monolog.yaml`) |
| **Finalité** | Détection d'intrusion et traçabilité des actions d'administration : conserver la trace des tentatives d'authentification, des rejets de garde CSRF, des refus d'accès au backoffice et des actions effectuées sur les comptes (invitation, changement de rôle, changement de mot de passe, suppression, activation, purge automatique d'invitation jamais activée). Introduit par le 3e audit de sécurité (constat A5) : auparavant, aucun événement de sécurité ne laissait de trace |
| **Base légale** | Intérêt légitime (art. 6-1-f RGPD), au titre de la sécurité du traitement exigée par l'article 32 RGPD |
| **Données collectées** | Par enregistrement : `event` (clé stable de l'événement), `actor` (identifiant du jeton de sécurité, ou `anonymous`), `ip` (adresse IP de l'appelant), `path` (chemin de la requête, forme décodée), l'horodatage et le canal ajoutés par Monolog, et selon l'événement `user` (le **nom d'utilisateur** saisi ou visé, ou l'identifiant éphémère `guest-…` d'un jeton de palier de base), `userId` (UUID du compte) et `superAdmin` (booléen) |
| **Données volontairement exclues** | **Jamais** de mot de passe, de jeton (JWT, XSRF, invitation), d'adresse e-mail, de corps de requête ni d'exception sérialisée. Un compte invité est désigné par son nom d'utilisateur et son UUID, jamais par son e-mail. Le contexte est construit valeur par valeur dans `SecurityAuditLogger::record()` — jamais à partir d'un tableau reçu — et `SecurityAuditLoggerTest::testNoContextValueEverCarriesAPasswordATokenOrAnEmail` le vérifie en faisant passer des valeurs sentinelles par chaque méthode |
| **Destinataires** | L'éditeur du site, seul à disposer d'un accès au cluster (`kubectl logs`). Sous-traitant technique : **Scaleway SAS** (Kubernetes Kapsule, `fr-par`), en tant qu'hébergeur du nœud qui conserve les journaux de conteneur |
| **Durée de conservation** | Identique au §5 : ces enregistrements sont des lignes de journal de conteneur, bornées par la rotation du nœud et perdues au remplacement du pod, sans export ni archivage. **Valeur exacte à confirmer auprès de l'hébergeur.** Aucun stockage en base de données, aucune copie applicative |
| **Mesures de sécurité** | Canal distinct du canal `security` du framework et du handler `main`, pour qu'un événement sorte une seule fois ; niveau `info` figé en dur plutôt que piloté par `LOG_LEVEL`, afin que la trace existe en production ; pas de `fingers_crossed`, une série d'échecs sans erreur ultérieure étant précisément ce qu'il faut pouvoir relire |

## 7. Droits des personnes concernées

Toute personne dont les données sont traitées via le site (les expéditeurs du
formulaire de contact et les personnes invitées à disposer d'un compte, §3.2) peut exercer ses droits d'accès, de
rectification, d'effacement, de limitation, d'opposition et de portabilité en
écrivant à **contact@cp-ghostotof.com**. Une réponse est apportée dans un délai
d'un mois (art. 12-3 RGPD). Pour une personne invitée, l'effacement se traduit par la
suppression du compte et de son adresse depuis le backoffice. En cas de désaccord persistant, une réclamation peut
être introduite auprès de la CNIL (www.cnil.fr).

Aucun profilage ni décision entièrement automatisée n'est réalisé sur les
données collectées.

## 8. Transferts hors Union européenne

**Hébergement** : Scaleway SAS (RCS Paris 433 115 904, siège 8 rue de la Ville
l'Évêque, 75008 Paris). L'infrastructure Kubernetes, la base de données et les
secrets (Scaleway Secret Manager) sont localisés en **France, région `fr-par`**.
Aucun transfert hors Union européenne pour ce volet.

**Envoi des emails de notification** : Scaleway SAS — Transactional Email,
infrastructure en France (`fr-par`). Aucun transfert hors Union européenne.

**Contrôle des mots de passe compromis** : `api.pwnedpasswords.com` (Have I Been
Pwned, service hébergé derrière Cloudflare, hors UE possible). Seul un préfixe de
5 caractères d'une empreinte SHA-1 est transmis (§3.2) : ce n'est pas une donnée
personnelle, il n'y a donc pas de transfert au sens du RGPD ; l'appel est consigné ici
par transparence.

**Réception / redirection de `contact@cp-ghostotof.com`** : Cloudflare, Inc.
(Email Routing), société établie aux **États-Unis**. Transfert encadré par
l'**EU–US Data Privacy Framework** (Cloudflare y est certifié) ; à défaut, les
clauses contractuelles types de la Commission européenne s'appliquent au titre
du DPA Cloudflare. Le service se limite à relayer le message vers la boîte de
l'éditeur ; aucune conservation durable côté Cloudflare au-delà du routage.
