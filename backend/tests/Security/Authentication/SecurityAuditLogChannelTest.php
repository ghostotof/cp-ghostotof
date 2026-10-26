<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication;

use App\Tests\Support\ProductionMonologHandlers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le journal de sécurité (`security_audit`, 3e audit A5/D5) et le canal
 * `security` de Symfony doivent sortir en production à `info` : une série de
 * logins refusés sans erreur derrière est justement la trace à garder, et un
 * handler qui la retiendrait ou la filtrerait rendrait les actes de
 * l'administration et les refus non attribuables (répudiation). Les gardes de
 * parité entre environnements ne comparent pas les handlers Monolog, qui
 * diffèrent à bon droit (un handler de test en `test`) : relevé par l'audit
 * de #698, ce test fige ceux de `when@prod`, comme AiUsageLogChannelTest le
 * fait pour `ai_usage`.
 *
 * `when@prod` couvre la production et la préprod (même APP_ENV) ; il n'est
 * chargé par aucun test fonctionnel, d'où la lecture du fichier.
 */
final class SecurityAuditLogChannelTest extends TestCase
{
    private const string DEDICATED_HANDLER = 'security';

    /**
     * @return iterable<string, array{string}>
     */
    public static function channels(): iterable
    {
        yield 'journal de sécurité' => ['security_audit'];
        yield 'canal de Symfony' => ['security'];
    }

    /**
     * Un et un seul handler le reçoit — par son nom ou parce qu'il prend tous
     * les canaux qu'il n'exclut pas (`main`, `console`, un handler sans
     * `channels`) : un enregistrement ne sort qu'une fois, et jamais par un
     * `fingers_crossed` qui jetterait la série si aucune erreur ne la suit.
     * Ce handler est à niveau fixe, en JSON sur stderr, seul flux que
     * Kubernetes recueille.
     */
    #[DataProvider('channels')]
    public function testTheChannelHasItsOwnInfoLevelHandlerInProduction(string $channel): void
    {
        $receiving = array_filter(
            ProductionMonologHandlers::all(),
            static fn (array $handler): bool => ProductionMonologHandlers::receives($handler, $channel),
        );

        self::assertSame([self::DEDICATED_HANDLER], array_keys($receiving), \sprintf('Seul le handler %s doit recevoir le canal %s en production.', self::DEDICATED_HANDLER, $channel));
        self::assertSame(
            ['type' => 'stream', 'path' => 'php://stderr', 'level' => 'info', 'formatter' => 'monolog.formatter.json'],
            array_intersect_key($receiving[self::DEDICATED_HANDLER], array_flip(['type', 'path', 'level', 'formatter'])),
        );
    }
}
