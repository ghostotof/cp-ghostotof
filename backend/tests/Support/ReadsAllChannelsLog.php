<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Lecture de la sonde Monolog branchée sur tous les canaux en test
 * (monolog.yaml, `all_channels_test`), pour vérifier qu'un contenu ne sort
 * par aucun journal — le nôtre, celui du noyau, celui d'API Platform — y
 * compris par un canal ajouté plus tard (issue #269).
 *
 * Le service du handler n'existe qu'en test, alors que phpstan-symfony lit le
 * conteneur de dev : on le retrouve donc, comme ReadsSecurityAuditLog, parmi
 * les handlers du logger public `monolog.logger.security_audit` (la sonde
 * écoute ce canal comme les autres). Seul son niveau `debug` la distingue de
 * `security_audit_test` (`info`).
 *
 * Mêmes réserves que ReadsSecurityAuditLog sur le redémarrage du kernel :
 * sans `$client->disableReboot()`, la sonde ne garde que la dernière requête.
 *
 * @phpstan-require-extends KernelTestCase
 */
trait ReadsAllChannelsLog
{
    /**
     * @return list<LogRecord>
     */
    private static function allChannelsLogRecords(): array
    {
        foreach (self::getContainer()->get('monolog.logger.security_audit')->getHandlers() as $handler) {
            if ($handler instanceof TestHandler && Level::Debug === $handler->getLevel()) {
                return array_values($handler->getRecords());
            }
        }

        self::fail('Sonde all_channels_test introuvable : voir monolog.yaml (when@test).');
    }
}
