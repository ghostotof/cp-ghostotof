# Registre des traitements de données personnelles

Registre tenu au sens de l'article 30 du RGPD. Ce document est la source de
vérité technique ; la page publique **Politique de confidentialité** du site
(`frontend/src/presentation/pages/PrivacyPolicyPage.vue`) en est le résumé
destiné aux visiteurs.

**Responsable de traitement** : l'éditeur du site, personne physique agissant à
titre non professionnel (cf. `docs/rgpd/registre-traitements.md` et les
mentions légales pour le détail du statut), joignable à
`contact@cp-ghostotof.com`.

**Dernière mise à jour** : 2026-09-02.

---

## 1. Formulaire de contact

| | |
|---|---|
| **Composants concernés** | `backend/src/Contact/Presentation/ApiResource/ContactMessageResource.php` → `Infrastructure/ApiPlatform/ContactMessageProcessor.php` → `Application/ContactMessageSender.php` → Messenger (transport `async`, RabbitMQ) → `Infrastructure/Messenger/SendContactMessageHandler.php` (envoi via Symfony Mailer). Purge des échecs : `backend/src/Contact/Presentation/Command/PurgeFailedContactMessagesCommand.php` (`app:contact:purge-failed-messages`), planifiée par le CronJob `contact-failed-messages-purge` (`k8s/base/messenger-purge-cronjob.yaml`) |
| **Finalité** | Répondre aux demandes de contact d'un visiteur |
| **Base légale** | Mesures précontractuelles prises à la demande de la personne concernée (art. 6-1-b RGPD) ; à défaut, intérêt légitime à pouvoir échanger avec un visiteur qui en fait la demande |
| **Données collectées** | Nom, adresse email, message (champ libre) |
| **Durée de conservation** | **Cas nominal : aucune persistance en base.** Le message transite par une file RabbitMQ (transport `async`) jusqu'à son envoi par email, puis est supprimé de la file. **Cas d'échec d'envoi** (SMTP indisponible, retries épuisés) : le message est routé vers le transport `failed` (`failure_transport: failed` dans `config/packages/messenger.yaml`), stocké en base dans la table `messenger_messages` — il contient alors le nom, l'email et le message du visiteur. **Rétention : 30 jours maximum**, appliquée par la commande `app:contact:purge-failed-messages` (paramètre `--older-than`, défaut `30 days`), exécutée quotidiennement par le CronJob `contact-failed-messages-purge` (03:17). L'email effectivement envoyé est par ailleurs conservé dans la boîte `contact@cp-ghostotof.com`, selon la politique du fournisseur de messagerie retenu en production. |
| **Destinataires** | L'éditeur du site, via la boîte `contact@cp-ghostotof.com` (variable `CONTACT_RECIPIENT_EMAIL`). Sous-traitants techniques : **Scaleway SAS — Transactional Email** (envoi du message de notification, `MAILER_DSN=scaleway+api://…`, infrastructure en France) et **Cloudflare, Inc. — Email Routing** (redirection de `contact@cp-ghostotof.com` vers la boîte réelle de l'éditeur, société établie aux États-Unis, cf. §7). En dev, `MAILER_DSN=null://null` (aucun envoi). |
| **Mesures de sécurité** | Validation stricte côté API (`Assert\Email`, longueurs bornées), honeypot anti-bot, rate limiting (cf. §2), transport chiffré vers le serveur SMTP (dépend du DSN de production) |

## 2. Anti-spam / limitation de débit du formulaire de contact

| | |
|---|---|
| **Composants concernés** | `backend/src/Contact/Infrastructure/RateLimiter/SymfonyContactRateLimiter.php`, `config/packages/rate_limiter.yaml` |
| **Finalité** | Lutte contre les abus/spam sur l'endpoint public `POST /api/contact` |
| **Base légale** | Intérêt légitime (sécurité du service, art. 6-1-f RGPD) |
| **Données collectées** | Adresse IP du visiteur (utilisée uniquement comme clé de limitation) |
| **Durée de conservation** | Stockage transitoire dans le cache applicatif (pool `cache.app`, filesystem par défaut), fenêtre glissante d'1 heure, 5 requêtes maximum — aucune conservation au-delà de cette fenêtre, aucune base de données |

## 3. Authentification

| | |
|---|---|
| **Composants concernés** | `backend/src/Security/Authentication/*`, `backend/src/Security/User/*` |
| **Finalité** | Permettre à l'unique utilisateur authentifié (l'éditeur du site, cf. Goal #9 du projet) d'accéder aux contenus réservés (ex. section loisirs de la page À propos, téléchargement du CV) |
| **Base légale** | Nécessaire à l'exécution du service demandé par l'utilisateur (art. 6-1-b RGPD) |
| **Données traitées** | Nom d'utilisateur + mot de passe (haché, `password_hashers: auto`) ; **aucun email n'est stocké** (cf. `CpgUser`, créé uniquement via la commande `app:user:create`, jamais via un formulaire public) |
| **Cookies posés** | `BEARER` (JWT, httpOnly, `Secure` en prod, `SameSite=Lax`) ; `XSRF-TOKEN` (lisible en JS pour la protection CSRF double-submit-cookie, `Secure` en prod, `SameSite=Lax`) — tous deux strictement nécessaires au fonctionnement du compte, donc exemptés de consentement au sens des recommandations CNIL sur les cookies |
| **Durée de conservation** | Durée de vie du JWT (configuration Lexik JWT) ; cookies expirés explicitement au logout (`CookieLogoutListener`) |

## 4. Préférence de langue

| | |
|---|---|
| **Composant concerné** | `frontend/src/presentation/router/preferredLocale.ts` (`localStorage`, clé `LOCALE_STORAGE_KEY`) |
| **Finalité** | Mémoriser la langue choisie par le visiteur entre deux visites |
| **Base légale** | Non applicable — donnée non identifiante, stockage purement fonctionnel côté navigateur, aucun consentement requis |
| **Durée de conservation** | Jusqu'à suppression par l'utilisateur (stockage navigateur local, jamais transmis au serveur) |

## 5. Logs techniques

| | |
|---|---|
| **Composants concernés** | nginx (`access_log`/`error_log`, `docker/nginx/`), logs du conteneur backend (visibles via `docker compose logs`) |
| **Finalité** | Sécurité, diagnostic, détection d'incidents |
| **Base légale** | Intérêt légitime (art. 6-1-f RGPD) |
| **Données collectées** | Adresse IP, user-agent, URL et méthode HTTP, code de réponse |
| **Durée de conservation** | **Point ouvert** : aucune politique de rotation/purge n'est configurée à ce jour dans le dépôt (pas de `logrotate` dédié, pas de limite explicite sur les logs Docker). À définir avant mise en production (la recommandation CNIL usuelle est de 6 à 12 mois maximum pour des logs de sécurité). |

## 6. Droits des personnes concernées

Toute personne dont les données sont traitées via le site (essentiellement les
expéditeurs du formulaire de contact) peut exercer ses droits d'accès, de
rectification, d'effacement, de limitation, d'opposition et de portabilité en
écrivant à **contact@cp-ghostotof.com**. Une réponse est apportée dans un délai
d'un mois (art. 12-3 RGPD). En cas de désaccord persistant, une réclamation peut
être introduite auprès de la CNIL (www.cnil.fr).

Aucun profilage ni décision entièrement automatisée n'est réalisé sur les
données collectées.

## 7. Transferts hors Union européenne

**Hébergement** : Scaleway SAS (RCS Paris 433 115 904, siège 8 rue de la Ville
l'Évêque, 75008 Paris). L'infrastructure Kubernetes, la base de données et les
secrets (Scaleway Secret Manager) sont localisés en **France, région `fr-par`**.
Aucun transfert hors Union européenne pour ce volet.

**Envoi des emails de notification** : Scaleway SAS — Transactional Email,
infrastructure en France (`fr-par`). Aucun transfert hors Union européenne.

**Réception / redirection de `contact@cp-ghostotof.com`** : Cloudflare, Inc.
(Email Routing), société établie aux **États-Unis**. Transfert encadré par
l'**EU–US Data Privacy Framework** (Cloudflare y est certifié) ; à défaut, les
clauses contractuelles types de la Commission européenne s'appliquent au titre
du DPA Cloudflare. Le service se limite à relayer le message vers la boîte de
l'éditeur ; aucune conservation durable côté Cloudflare au-delà du routage.
