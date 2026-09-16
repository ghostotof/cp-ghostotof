<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Lecture du journal de sécurité (canal `security_audit`, D5) dans les tests
 * fonctionnels. En test, monolog.yaml branche un handler `test` sur ce canal ;
 * on le retrouve parmi les handlers du logger de canal, public dans tous les
 * environnements — donc connu de phpstan-symfony, qui lit le dump du
 * conteneur dev. Les enregistrements se relisent tels qu'ils sortiraient en
 * JSON en production, sans rien remplacer dans le conteneur.
 *
 * Le kernel de KernelBrowser est redémarré avant chaque requête après la
 * première : le handler — et ses enregistrements — est donc celui de la
 * DERNIÈRE requête servie, ce qui convient à un test qui vérifie ce qu'une
 * requête donnée a produit. Pour cumuler sur plusieurs requêtes, appeler
 * `$client->disableReboot()`.
 *
 * @phpstan-require-extends KernelTestCase
 */
trait ReadsSecurityAuditLog
{
    private const string SECURITY_AUDIT_LOGGER = 'monolog.logger.security_audit';

    /**
     * @return list<LogRecord>
     */
    private static function securityAuditRecords(): array
    {
        // phpstan-symfony connaît déjà le type (Monolog\Logger) depuis le dump
        // du conteneur : pas d'assertInstanceOf, elle serait toujours vraie.
        $logger = self::getContainer()->get(self::SECURITY_AUDIT_LOGGER);

        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof TestHandler) {
                return array_values($handler->getRecords());
            }
        }

        self::fail('Aucun TestHandler sur le canal security_audit : voir monolog.yaml (when@test).');
    }

    /**
     * Les contextes des enregistrements portant l'événement donné.
     *
     * @return list<array<string, mixed>>
     */
    private static function securityAuditEvents(string $event): array
    {
        $contexts = [];

        foreach (self::securityAuditRecords() as $record) {
            /** @var array<string, mixed> $context */
            $context = $record->context;

            if (($context['event'] ?? null) === $event) {
                $contexts[] = $context;
            }
        }

        return $contexts;
    }

    /**
     * Exactement un enregistrement de l'événement donné, dont le contexte est renvoyé.
     *
     * @return array<string, mixed>
     */
    private static function singleSecurityAuditEvent(string $event): array
    {
        $events = self::securityAuditEvents($event);
        self::assertCount(1, $events, sprintf('Exactement un enregistrement « %s » attendu.', $event));

        return $events[0];
    }
}
