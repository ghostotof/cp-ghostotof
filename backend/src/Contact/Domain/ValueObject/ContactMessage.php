<?php

declare(strict_types=1);

namespace App\Contact\Domain\ValueObject;

/**
 * Représente un message de contact déjà validé (cf. les contraintes Assert
 * sur ContactMessageResource, exécutées avant que le Processor ne construise
 * cette VO) : l'Application/Domain n'a jamais à revalider ces valeurs.
 */
final readonly class ContactMessage
{
    public function __construct(
        public string $senderName,
        public string $senderEmail,
        public string $body,
    ) {
    }
}
