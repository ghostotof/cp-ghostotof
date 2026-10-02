# v0.18.9 — Traces et journaux sans données sensibles

Release corrective. Elle porte sur ce que l'application laisse échapper dans ses traces et ses
journaux. Aucune migration, aucun secret nouveau, aucun changement de manifeste Kubernetes. La
configuration PHP des images production et préprod change d'une ligne.

## Les traces d'exception ne portent plus les arguments des appels (#278)

- `zend.exception_ignore_args = On` dans `docker/php/php.prod.ini`, donc en production et en
  préprod, puisque l'image `preprod` est construite à partir de `production`.
- Jusqu'ici, chaque frame d'une trace gardait ses arguments. La protection d'un DSN, d'un mot de
  passe ou d'un jeton dépendait du `#[\SensitiveParameter]` posé sur chaque signature, une par
  une, et un seul oubli suffisait.
- Le dev garde `Off` pour pouvoir déboguer. Un test vérifie la directive dans `php.prod.ini`.

## L'échec du traducteur IA ne journalise plus la réponse du fournisseur (#269)

- Le bridge Symfony AI recopie le corps de la réponse d'Anthropic dans le message de son
  exception, et ce corps peut citer l'entrée, c'est-à-dire du contenu du backoffice. Il sortait
  par trois chemins : notre log métier, l'`ErrorListener` du noyau (CRITICAL sur le 503) et API
  Platform (DEBUG), ces deux derniers à travers la chaîne `previous`.
- L'échec est désormais journalisé par la classe de l'exception, le statut HTTP (pour un 5xx) et
  le type d'erreur Anthropic. Ce type est un mot-clé fixe, lu par une expression ancrée qui ne
  capture que `[a-z_]`. L'exception métier ne transporte plus de `previous`.
- Une sonde de test écoute tous les canaux Monolog, et pas une liste écrite à la main. Elle
  vérifie qu'une sentinelle placée dans la réponse simulée ne sort par aucun journal, y compris
  sur un canal ajouté plus tard.
- Le statut HTTP ne change pas : toujours 503.

## Outillage et tests

- Les suites de `tools/tests/` ne dépendent plus de l'environnement git du poste (#304) :
  signature des commits et des étiquettes, hooks, configuration passée par l'environnement,
  `GIT_DIR` hérité. Une nouvelle suite, `git-config-isolation.test.sh`, les rejoue sous un
  environnement hostile. Rien ne change pour `finalize-release.sh` en CI.
- Le test d'enregistrement du premier produit surveillé vérifie maintenant la persistance, et la
  dernière notice PHPUnit de la suite disparaît.

## À vérifier en préprod

- Smoke tests et audit verts.
- `php -i | grep exception_ignore_args` dans le conteneur `php-fpm` affiche `On`.
- Le bouton de traduction du backoffice fonctionne toujours, et une suggestion revient.
