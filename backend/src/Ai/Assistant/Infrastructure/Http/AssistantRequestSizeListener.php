<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Infrastructure\Http;

use App\Shared\Infrastructure\Http\CanonicalPath;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Refuse en 413 un corps de plus de 64 Kio sous `/api/assistant` (spec 0005
 * M4), avant toute désérialisation. nginx laisse passer jusqu'à 1 Mo
 * (`client_max_body_size`) ; la borne applicative découle des longueurs D6 :
 * la plus longue conversation valide (6 × 1 000 + 5 × 4 000 caractères) tient
 * sous 64 Kio tant que ses caractères font au plus deux octets en UTF-8.
 *
 * Priorité 4 : après le firewall (8) et son access_control. La taille ne se
 * juge qu'une fois l'accès accordé — un anonyme ou le palier de base reçoit
 * son refus d'accès, jamais une réponse sur la forme de sa requête. Chemin
 * canonique (issue #77) : `/api/%61ssistant/…` n'y échappe pas.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final readonly class AssistantRequestSizeListener
{
    public const int MAX_BODY_BYTES = 65536;

    private const string PATH_PREFIX = '/api/assistant';

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = CanonicalPath::of($request);
        if (self::PATH_PREFIX !== $path && !str_starts_with($path, self::PATH_PREFIX.'/')) {
            return;
        }

        if (\strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            throw new RequestBodyTooLargeException();
        }
    }
}
