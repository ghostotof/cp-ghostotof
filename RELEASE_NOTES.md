# v0.30.0 — Contrôle quotidien des certificats TLS, rétentions en temps écoulé

Cette release ajoute le signal qui a manqué lors de l'incident du 2026-10-26 : un contrôle quotidien des
trois certificats TLS servis (préprod, prod, www). Elle corrige aussi le calcul des durées de rétention des
purges, qui comptaient les heures en heure murale, et intègre les mises à jour de dépendances en attente.

## Ce qui change

- **Contrôle quotidien des certificats TLS** (#719) : le nouveau workflow `Certificats TLS`
  (`.github/workflows/tls-expiry.yml`) vérifie chaque jour, pour chaque hôte des Ingress, l'échéance du
  certificat servi, le nom couvert et la chaîne de confiance. Le seuil est relatif à la durée de vie du
  certificat : le run échoue 5 jours après le moment où cert-manager aurait dû renouveler (25 jours avant
  l'échéance pour un certificat de 90 jours, 16 pour les certificats de 64 jours que Let's Encrypt
  délivrera à partir du 2027-02-10). Un run rouge signale donc un renouvellement en échec depuis au moins
  5 jours, et le log nomme l'hôte. Le signal est l'e-mail d'échec de GitHub. La garde
  `TlsExpiryWatchCoversEveryIngressHostTest` fait échouer la suite si un hôte d'Ingress n'est pas contrôlé.
- **Audit des certificats** (#719) : `audit-prod.sh`, qui sert aussi à `audit-preprod`, passe par la même
  bibliothèque (`tools/lib/tls-certificate.sh`). Il reste bloquant sous 14 jours de validité, et juge
  désormais aussi le nom couvert et la chaîne de confiance.
- **Rétentions des purges** (#710) : une rétention exprimée en heures, minutes ou secondes se compte
  maintenant en temps écoulé. Les jours, mois et années restent comptés sur le calendrier. Auparavant,
  « 12 hours » se comptait en heure murale et pouvait viser une heure de trop ou de moins autour d'un
  changement d'heure. Les deux commandes de purge, `app:contact:purge-failed-messages` et
  `app:user:purge-pending-invitations`, partagent désormais le même objet `RetentionPeriod`.
- **Contrat de `--older-than` resserré** (#710) : les deux purges refusent maintenant une expression qui
  désigne une date (`monday`, `first day of next month`, `yesterday`), qui mêle des termes de signes opposés,
  dont un nombre a 14 chiffres ou plus, ou qui place le seuil avant l'an 1. Une fraction de seconde est
  recopiée à la microseconde. Le message d'erreur de la purge des invitations dit désormais « illisible »,
  comme celui de la purge des messages. Les CronJobs ne sont pas concernés : ils lancent les deux purges
  sans `--older-than`, avec leurs rétentions par défaut (« 30 days »), acceptées à l'identique.
- **Dépendances** :
  - backend : Symfony 8.1.9 (`framework-bundle`, `messenger`, `amqp-messenger`, `doctrine-messenger`,
    `http-client`, `clock`, `uid`, `yaml`, `browser-kit`), PHPUnit 13.4.3 ;
  - frontend : Vue 3.5.44, vue-i18n 11.4.16, ESLint 10.13.0, typescript-eslint 8.72.0, jsdom 30.1.3,
    jeux d'icônes Iconify ;
  - CI : `github/codeql-action` 4.39.0.

## À savoir pour le déploiement

- **Aucune migration**, aucun changement de clé ni de secret : pas de fenêtre de maintenance, pas besoin de
  rejouer la procédure des rôles.
- **Aucun manifeste Kubernetes modifié** : seuls les images backend et frontend changent. PostgreSQL et
  RabbitMQ ne redémarrent pas.
- **Le workflow `Certificats TLS` ne s'active qu'une fois sur `main`** : un `schedule` GitHub ne tourne que
  sur la branche par défaut. Garder les notifications Actions actives, et le réactiver si GitHub le
  désactive (60 jours sans activité du dépôt, sans prévenir).
- **`audit-preprod` est plus strict** : il échoue désormais aussi sur un nom non couvert ou une chaîne
  incomplète du certificat de préprod.
- **Postes de développement** : `docker compose up -d backend` pour recréer le conteneur avec le nouveau
  montage en lecture seule de `.github/workflows`, sans quoi `TlsExpiryWatchCoversEveryIngressHostTest`
  échoue en local.

## Vérifications

- **Certificats** : après la fusion, lancer le workflow `Certificats TLS` à la main (onglet Actions,
  *Run workflow*) : il doit être vert pour les trois hôtes, valides jusqu'au 2027-01-24.
- **Préprod et prod** : `/` et `/api/watch` répondent `200`, le formulaire de contact passe comme avant.
- **Purges** : les CronJobs de purge du lendemain se terminent en succès.
