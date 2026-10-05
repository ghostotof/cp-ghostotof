<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Lecture du canal `ai_usage` (jetons, durée, fins et refus de quota des
 * modèles de langage, ADR 0004 D5) dans les tests fonctionnels — le pendant
 * de ReadsSecurityAuditLog, dont il reprend les règles : handler `test` de
 * monolog.yaml (when@test) retrouvé parmi ceux du logger de canal, public
 * dans tous les environnements ; enregistrements de la DERNIÈRE requête,
 * sauf `$client->disableReboot()`.
 *
 * @phpstan-require-extends KernelTestCase
 */
trait ReadsAiUsageLog
{
    /**
     * @return list<LogRecord>
     */
    private static function aiUsageRecords(): array
    {
        // Filtré sur le niveau : la sonde de tous les canaux (ReadsAllChannelsLog,
        // `debug`, issue #269) est elle aussi un TestHandler de ce logger, et
        // elle porte les enregistrements de toute l'application.
        foreach (self::getContainer()->get('monolog.logger.ai_usage')->getHandlers() as $handler) {
            if ($handler instanceof TestHandler && Level::Info === $handler->getLevel()) {
                return array_values($handler->getRecords());
            }
        }

        self::fail('Aucun TestHandler sur le canal ai_usage : voir monolog.yaml (when@test).');
    }

    /**
     * Les enregistrements d'`ai_usage` dont le contexte porte cette fin.
     *
     * @return list<LogRecord>
     */
    private static function aiUsageRecordsWithOutcome(string $outcome): array
    {
        return array_values(array_filter(
            self::aiUsageRecords(),
            static fn (LogRecord $record): bool => $outcome === ($record->context['outcome'] ?? null),
        ));
    }
}
