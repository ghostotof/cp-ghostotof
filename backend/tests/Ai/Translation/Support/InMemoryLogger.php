<?php

declare(strict_types=1);

namespace App\Tests\Ai\Translation\Support;

use Psr\Log\AbstractLogger;

/**
 * Logger de test : conserve niveau, message et contexte de chaque entrée, pour
 * vérifier ce qui est journalisé — et surtout ce qui ne doit jamais l'être (le
 * contenu traduit).
 */
final class InMemoryLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $stringKeyed = [];
        foreach ($context as $key => $value) {
            $stringKeyed[(string) $key] = $value;
        }

        $this->records[] = [
            'level' => \is_string($level) ? $level : 'unknown',
            'message' => (string) $message,
            'context' => $stringKeyed,
        ];
    }

    /** Tout ce qui a été journalisé, sérialisé, pour chercher une fuite de contenu. */
    public function dump(): string
    {
        return json_encode($this->records, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }
}
