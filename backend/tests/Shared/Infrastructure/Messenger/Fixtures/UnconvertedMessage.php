<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger\Fixtures;

/**
 * Message de la fixture AsyncRoutingProject (issue #491) : il ne nomme
 * aucune exception, mais n'est routé que vers `sync` — rien n'est publié,
 * la garde doit le laisser passer (hors du joker de `dev`).
 */
final readonly class UnconvertedMessage
{
}
