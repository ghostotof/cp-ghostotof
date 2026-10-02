# v0.18.6 — Le worker attend RabbitMQ avant de démarrer

Correctif d'infrastructure seul : un initContainer de plus dans le pod du worker Messenger
(`k8s/base/messenger-worker-deployment.yaml`). Les images backend et frontend sont reconstruites
à l'identique du code de la v0.18.5. Aucune migration, aucun secret nouveau. RabbitMQ n'est
pas touché.

Le worker est recréé au déploiement : la file de messages reste brièvement sans consommateur,
sans perte (file persistante).

## Le worker plantait quand il démarrait avant RabbitMQ (#298)

Un déploiement qui recrée `rabbitmq` et `worker` ensemble lançait le worker avant que le
broker écoute. `messenger:consume` s'arrêtait sur « Could not connect to the AMQP server »,
un `CRITICAL` dans les journaux, puis le kubelet le relançait avec un délai croissant. Au
déploiement de la v0.18.5 : un redémarrage en préprod, trois en production, et une file sans
consommateur jusqu'à 33 s après que le broker était prêt.

- L'initContainer `wait-for-rabbitmq` attend que le port AMQP 5672 réponde, la même
  vérification que les sondes de RabbitMQ (#295).
- L'attente est bornée à 5 minutes : un broker qui ne vient jamais fait échouer le pod
  franchement, au lieu de le bloquer.
- `nc` vient de BusyBox, déjà dans l'image : aucune image ni aucun secret supplémentaire.
- `WorkerWaitsForRabbitMqTest` fige ce dispositif.

## À vérifier en préprod

- L'initContainer `wait-for-rabbitmq` du worker se termine avec le code 0 et journalise
  `rabbitmq:5672 joignable`.
- `restartCount` du worker reste à 0.
- Le formulaire de contact aboutit de bout en bout : le message est consommé par le worker.

Ce déploiement ne recrée pas RabbitMQ : la preuve complète, un démarrage simultané des deux
pods sans redémarrage du worker, viendra au premier déploiement qui les recrée ensemble.
