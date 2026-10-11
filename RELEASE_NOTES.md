# v0.23.1 — MAC exigé sur toute enveloppe Messenger

Release de sécurité, suite de v0.23.0. Elle ferme l'injection d'objet par les files Messenger que le mode
transition de #444 laissait ouverte : une enveloppe sans MAC valable est désormais refusée sans
condition, sous une **nouvelle clé** qui invalide toute signature de la transition. Elle retire aussi la
tolérance des messages d'invitation de l'ancien format. **Aucune migration**, **aucun secret nouveau**,
aucun droit RBAC nouveau. **Déploiement avec fenêtre de maintenance obligatoire** (voir plus bas).

## Comportements qui changent

| Cas | Avant | Après | Issue |
|---|---|---|---|
| Enveloppe Messenger sans MAC (`async` ou `failed`) | décodée, `warning` « sans MAC acceptée » | **refusée** avant tout `unserialize()` ; le paramètre `app.messenger.require_envelope_mac` n'existe plus | #450 |
| Enveloppe signée avant cette release (clé `messenger-envelope/v1`) | acceptée | **refusée** (« MAC invalide ») : clé passée en `v2` | #450 |
| Refus de MAC | trois réessais (1, 2, 4 s), puis `failed` | **droit dans `failed`**, sans réessai (`EnvelopeMacRefusedException`) ; une ligne `critical` « Removing from transport » par message refusé | #450 |
| Message d'invitation sans génération (format v0.22.0 et avant) ou de génération < 1 | servi comme génération 1 | **refusé au décodage**, part dans `failed` : relancer depuis le backoffice puis `messenger:failed:remove`, jamais `retry` | #448 |
| Échec de décodage d'une enveloppe signée | gardait une copie sans MAC : faux `warning` « sans MAC » | garde l'enveloppe reçue, MAC compris, et sa vraie cause | #465 |
| `rollback-preprod` | défaisait `backend` et `frontend` | défait aussi `worker`, chaque `undo` tenté même si un autre échoue | #450 |

## Messenger (#448, #450, #465)

- **Le mode transition est supprimé, pas désactivé** : aucune configuration ne peut rouvrir l'injection.
- **`KEY_CONTEXT` des enveloppes en `…/v2`** : pendant la transition, une enveloppe forgée sans MAC pouvait
  être re-signée au réessai et rangée dans `failed` avec un MAC valable. La nouvelle clé invalide toute
  signature de cette période, sans dépendre d'une inspection.
- **Coût du changement de clé** : tout ce qui est signé `v1` devient illisible, `async`, files de délai
  et `failed` compris. D'où la fenêtre de maintenance et les contrôles ci-dessous.
- Un échec du sérialiseur interne (classe inconnue pendant un rollout) reste réessayé.

## Documentation et pipeline

- `k8s/README.md` : « Retour arrière applicatif » et « Cas du `KEY_CONTEXT` des enveloppes Messenger ».
- `rollback-preprod` ramène `worker` avec `backend` (#450).

## Déploiement : fenêtre de maintenance

- **`DEPLOY_MAINTENANCE_WINDOW=true` avant de pousser la branche de release**, et **retirée juste après
  la mise en prod**. Sans elle, un ancien pod d'API (`RollingUpdate`) signe encore en `v1` pendant que
  le worker (`Recreate`) exige déjà `v2` : un message de contact ou une invitation envoyé à ce moment
  est perdu, sans rattrapage.
- Pendant la fenêtre, l'API répond 503, le frontend reste servi. Aucune migration : la fenêtre dure le
  temps du redémarrage de `backend` et `worker`.

## Avant le merge vers `main` (préprod et prod, lecture seule)

- `SELECT count(*) FROM messenger_messages;` → 0. Sinon, lire chaque ligne (`messenger:failed:show`) et
  la rejouer ou la purger **avant** le merge : après, une ligne signée `v1` est illisible.
- `SELECT count(*) FROM messenger_messages WHERE headers NOT LIKE '%X-Envelope-Mac%' OR body LIKE '%MessageDecodingFailedException%';` → 0.
- RabbitMQ : `rabbitmqctl list_queues` → `messages` à 0, aucune file de délai.
- Hors code, fait le 2026-10-11 : `APP_SECRET` distincts entre préprod et prod ; aucun `warning` « sans
  MAC acceptée » dans les journaux restants du worker.

## À vérifier en préprod

- Smoke tests et audit verts.
- Journal du déploiement : « Fenêtre de maintenance : arrêt du backend et du worker ».
- Journal du worker : aucun « refusée (issue #444) » après le redémarrage.

## À vérifier en prod

- Journal du déploiement : fenêtre de maintenance appliquée.
- `SELECT count(*) FROM messenger_messages;` → 0 après le déploiement.
- Un message de contact passe : reçu, aucune ligne `failed`.
- Retirer `DEPLOY_MAINTENANCE_WINDOW`.

## Retour arrière

- **`backend` et `worker` ensemble**, toujours : défaire l'un sans l'autre envoie chaque message dans
  `failed`, sans rattrapage.
- **Jamais vers v0.23.0 sans le décider** : cette image accepte encore une enveloppe sans MAC et rouvre
  l'injection tant qu'elle tourne. Préférer une correction en avant.

## Angle mort connu

Aucun collecteur ne conserve les journaux des pods, et le worker redémarre toutes les heures : on ne
peut pas établir qu'aucune enveloppe sans MAC n'a été traitée pendant toute la transition (v0.23.0).
La clé `v2` invalide les signatures de cette période, pas les effets d'une injection déjà exécutée.
Suivis ouverts : #468 (`cache_items` désérialisé sans MAC), #469 (taille d'un corps d'enveloppe),
#470 (smoke test du trajet API → worker), #471 (README périmé).
