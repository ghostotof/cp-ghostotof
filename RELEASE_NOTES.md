# v0.18.5 — RabbitMQ n'est plus tué pendant son démarrage

Correctif d'infrastructure seul : modification des sondes de RabbitMQ dans
`k8s/base/rabbitmq.yaml`. Les images backend et frontend sont reconstruites à l'identique du
code de la v0.18.4. Aucune migration, aucun secret nouveau.

**Courte coupure de la file de messages au déploiement** : le template de pod de RabbitMQ
change, et ce Deployment est en stratégie `Recreate`. Le pod est donc recréé. Les messages en
attente (formulaire de contact, invitations) ne sont pas perdus, car la file est persistante et
le worker réessaie.

## Les sondes de RabbitMQ le redémarraient à tort (#295)

RabbitMQ a redémarré neuf fois en huit jours en production. Au dernier redémarrage, le broker
était prêt à 51 s et a pourtant reçu un `SIGTERM` à 86 s. La sonde de vie
`rabbitmq-diagnostics -q ping` démarre un nœud Erlang à chaque appel : sous charge, pendant un
démarrage justement, elle dépassait son délai de 10 s, et trois échecs suffisaient pour tuer un
broker qui fonctionnait.

- Les trois sondes vérifient désormais simplement que le port AMQP 5672 répond, comme dans le
  RabbitMQ Cluster Operator. Elles ne démarrent plus de nœud Erlang.
- Une `startupProbe` laisse jusqu'à 5 minutes au démarrage. La sonde de vie ne redémarre le
  broker que si son port reste fermé une minute entière.
- `RabbitMqProbesTest` fige ces réglages.

Contrepartie assumée : un nœud figé qui garderait son port ouvert ne serait plus redémarré
automatiquement.

## À vérifier en préprod

- `kubectl rollout status deployment/rabbitmq` aboutit, et les logs du broker sont propres.
- `restartCount` du pod RabbitMQ reste à 0.
- Le formulaire de contact aboutit de bout en bout : le message est consommé par le worker.
