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
    /**
     * Le nom est repris dans DEUX en-têtes du mail sortant : le sujet et le
     * replyTo (cf. SendContactMessageHandler). Symfony Mime encode ses
     * en-têtes et ne laisserait pas passer une injection, mais on refuse les
     * retours chariot en amont plutôt que de dépendre de ce comportement :
     * la règle « une donnée qui finit dans un en-tête ne contient pas de CR
     * ni de LF » doit être lisible à l'endroit où la donnée entre, pas
     * déduite d'une lecture du composant Mime.
     */
    #[Assert\NotBlank(message: 'Votre nom est requis.', normalizer: 'trim')]
    #[Assert\Length(min: 2, max: 100, minMessage: 'Votre nom est trop court.', maxMessage: 'Votre nom est trop long.', normalizer: 'trim')]
    #[Assert\Regex(pattern: '/[\r\n]/', match: false, message: 'Votre nom contient des caractères non autorisés.')]
    public string $name = '';

    #[Assert\NotBlank(message: 'Votre email est requis.', normalizer: 'trim')]
    #[Assert\Email(message: 'Cette adresse email n\'est pas valide.', normalizer: 'trim')]
    #[Assert\Length(max: 255, maxMessage: 'Votre email est trop long.', normalizer: 'trim')]
    public string $email = '';

    #[Assert\NotBlank(message: 'Votre message est requis.', normalizer: 'trim')]
    #[Assert\Length(min: 10, max: 5000, minMessage: 'Votre message est trop court.', maxMessage: 'Votre message est trop long (5000 caractères maximum).', normalizer: 'trim')]
    public string $message = '';

    /**
     * Honeypot anti-spam : champ absent visuellement du formulaire (masqué en
     * CSS côté frontend, jamais rempli par un humain) que les bots de
     * formulaire remplissent aveuglément. Si non vide, ContactMessageProcessor
     * ignore silencieusement la soumission sans révéler au bot que la
     * détection a eu lieu.
     */
    #[Assert\Length(max: 2000)]
    public ?string $website = null;
}
