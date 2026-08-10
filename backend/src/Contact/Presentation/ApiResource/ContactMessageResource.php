<?php

declare(strict_types=1);

namespace App\Contact\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Contact\Infrastructure\ApiPlatform\ContactMessageProcessor;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Endpoint public (aucune entrée dans access_control, cf.
 * config/packages/security.yaml — et exclu du contrôle CSRF double-submit
 * cookie, cf. CsrfCookieRequestSubscriber : un visiteur anonyme n'a pas de
 * session à protéger). Statut 202 : le message est accepté pour envoi
 * asynchrone (cf. ContactMessageProcessor), pas encore délivré au moment de
 * la réponse.
 */
#[ApiResource(
    shortName: 'ContactMessage',
    operations: [
        new Post(
            uriTemplate: '/contact',
            status: 202,
            processor: ContactMessageProcessor::class,
        ),
    ],
)]
final class ContactMessageResource
{
    #[Assert\NotBlank(message: 'Votre nom est requis.')]
    #[Assert\Length(min: 2, max: 100, minMessage: 'Votre nom est trop court.', maxMessage: 'Votre nom est trop long.')]
    public string $name = '';

    #[Assert\NotBlank(message: 'Votre email est requis.')]
    #[Assert\Email(message: 'Cette adresse email n\'est pas valide.')]
    public string $email = '';

    #[Assert\NotBlank(message: 'Votre message est requis.')]
    #[Assert\Length(min: 10, max: 5000, minMessage: 'Votre message est trop court.', maxMessage: 'Votre message est trop long (5000 caractères maximum).')]
    public string $message = '';

    /**
     * Honeypot anti-spam : champ absent visuellement du formulaire (masqué en
     * CSS côté frontend, jamais rempli par un humain) que les bots de
     * formulaire remplissent aveuglément. Si non vide, ContactMessageProcessor
     * ignore silencieusement la soumission sans révéler au bot que la
     * détection a eu lieu.
     */
    public ?string $website = null;
}
