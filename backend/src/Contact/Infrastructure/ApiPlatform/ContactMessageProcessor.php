<?php

declare(strict_types=1);

namespace App\Contact\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Contact\Application\ContactMessageSenderInterface;
use App\Contact\Application\ContactRateLimiterInterface;
use App\Contact\Domain\ValueObject\ContactMessage;
use App\Contact\Presentation\ApiResource\ContactMessageResource;
use Symfony\Component\HttpFoundation\Request;

/**
 * Relie ContactMessageResource (Presentation) à l'Application : seule cette
 * classe Infrastructure a le droit de connaître à la fois la ressource API
 * Platform et le use case applicatif, sur le même modèle que
 * App\Portfolio\Experience\Infrastructure\ApiPlatform\ExperienceTechnologyProvider.
 *
 * @implements ProcessorInterface<ContactMessageResource, ContactMessageResource>
 */
final readonly class ContactMessageProcessor implements ProcessorInterface
{
    public function __construct(
        private ContactMessageSenderInterface $contactMessageSender,
        private ContactRateLimiterInterface $contactRateLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ContactMessageResource
    {
        \assert($data instanceof ContactMessageResource);

        // Vérifié avant même le honeypot : borne le débit de l'endpoint quel
        // que soit l'émetteur (légitime ou bot), le honeypot ne filtrant lui
        // que les bots naïfs qui remplissent tous les champs.
        $request = $context['request'] ?? null;
        \assert($request instanceof Request);
        $this->contactRateLimiter->consume($request->getClientIp() ?? 'unknown');

        // Honeypot rempli : très probablement un bot. On répond comme si tout
        // s'était bien passé, sans jamais dispatcher le message ni prévenir
        // l'appelant que la détection a eu lieu.
        if (null !== $data->website && '' !== trim($data->website)) {
            return $data;
        }

        $this->contactMessageSender->send(new ContactMessage(
            senderName: trim($data->name),
            senderEmail: trim($data->email),
            body: trim($data->message),
        ));

        return $data;
    }
}
