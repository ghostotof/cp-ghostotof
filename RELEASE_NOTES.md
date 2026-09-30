# v0.18.3 — PHP 8.5.11 et ses correctifs de sécurité

Correctif de sécurité de l'environnement d'exécution. Nouvelle image backend (PHP 8.5.10 →
8.5.11) ; aucune migration, aucun manifeste Kubernetes ni secret nouveau. Déploiement sans
interruption.

## PHP 8.5.11 (#283)

La release de PHP du 24 septembre 2026 corrige 12 CVE. Deux concernent la configuration du
site :

- **CVE-2026-91769** : la vérification du nom d'hôte TLS d'OpenSSL retombait sur le CN du
  certificat après un échec sur le SAN.
- **CVE-2026-91767** : débordement de tampon dans `php_openssl_matches_wildcard_name()` sur un
  certificat wildcard forgé.

Les deux touchent le TLS fait par les flux PHP, dont l'envoi de mail. Les appels HTTP sortants
passent par ext-curl, qui fait sa propre vérification.

Non applicable ici : la CVE FPM sur `listen.allowed_clients` en IPv6 (CVE-2026-91768), car la
configuration FPM n'utilise pas cette directive.

Correctifs de comportement examinés sans impact sur le code : `yield from` imbriqué (le
streaming de l'assistant ne délègue jamais un générateur déjà amorcé), `array_keys()`, les
floats sur grands nombres, et le JIT d'OPcache (inactif).

Seul le tag d'image change, dans `.env` et `versions.lock`. La page `/stack` lit la version
de PHP au runtime : elle affichera 8.5.11 dès le déploiement, sans saisie.

## À vérifier en préprod

`php -v` dans un pod `backend` doit afficher 8.5.11, et `/stack` doit présenter PHP comme à
jour. Les smoke tests passent par FPM, le limiteur de login et la base : ils couvrent les
extensions recompilées (amqp, xdebug en préprod).
