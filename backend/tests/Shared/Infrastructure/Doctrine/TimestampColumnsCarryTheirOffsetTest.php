<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Doctrine;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Un horodatage d'entité garde son décalage en base (issue #434).
 *
 * `datetime_immutable` écrit l'heure murale du fuseau de PHP (Europe/Paris)
 * dans un `TIMESTAMP WITHOUT TIME ZONE`, sans décalage : l'heure répétée du
 * passage à l'heure d'hiver revient une heure plus tard, et un paramètre de
 * requête comparé à une colonne avec fuseau est lu dans le fuseau de la
 * session PostgreSQL (UTC). `datetimetz_immutable` écrit l'instant.
 *
 * Un jour de calendrier (`date_immutable`, `Incident::$occurredAt`) n'a ni
 * heure ni changement d'heure : il reste permis. Les tables de Messenger ne
 * sont pas des entités et sortent du périmètre.
 */
final class TimestampColumnsCarryTheirOffsetTest extends KernelTestCase
{
    /** Types sans décalage, que l'on refuse. */
    private const array WALL_CLOCK_TYPES = [
        Types::DATETIME_MUTABLE,
        Types::DATETIME_IMMUTABLE,
        Types::TIME_MUTABLE,
        Types::TIME_IMMUTABLE,
    ];

    public function testEveryEntityTimestampIsStoredWithItsOffset(): void
    {
        self::bootKernel();
        $metadata = self::getContainer()->get(EntityManagerInterface::class)->getMetadataFactory()->getAllMetadata();
        self::assertNotEmpty($metadata, 'Aucune entité lue : la garde ne garderait rien.');

        $offenders = [];
        foreach ($metadata as $classMetadata) {
            foreach ($classMetadata->fieldMappings as $field => $mapping) {
                if (\in_array($mapping->type, self::WALL_CLOCK_TYPES, true)) {
                    $offenders[] = \sprintf('%s::$%s (%s)', $classMetadata->getName(), $field, $mapping->type);
                }
            }
        }

        self::assertSame([], $offenders, 'Horodatage sans décalage : mapper la colonne en Types::DATETIMETZ_IMMUTABLE, migration comprise (#434).');
    }
}
