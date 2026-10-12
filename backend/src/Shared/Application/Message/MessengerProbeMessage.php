<?php

declare(strict_types=1);

namespace App\Shared\Application\Message;

/**
 * Message témoin du smoke préprod (issue #470) : mis en file par
 * `app:messenger:probe`, il ne porte qu'un identifiant aléatoire, et son traitement
 * n'a aucun effet de bord hors d'une marque en cache.
 *
 * Il fait le trajet réel d'un message de contact ou d'une invitation : signé
 * par le processus qui l'envoie (l'image de la release, avec les Secrets du
 * backend), décodé par le worker. Un écart de clé de MAC entre les deux, ou un
 * worker incapable de décoder, l'envoie dans `failed` : la marque ne vient
 * jamais, et la sonde échoue.
 */
final readonly class MessengerProbeMessage
{
    public function __construct(
        public string $probeId,
    ) {
    }
}
