<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Fixtures\Traits;

use App\Security\User\Application\CpgUserAdministratorInterface;

/**
 * Fixture de {@see \App\Tests\Security\User\PlainPasswordInputsTest} (issue
 * #421) : nomme l'interface, et laisse {@see CallsThroughAPropertyFixture}
 * l'appeler. Ni son fichier ni celui du trait ne suffit seul.
 */
final readonly class DesignatesTheAdministratorFixture
{
    use CallsThroughAPropertyFixture;

    public function __construct(private CpgUserAdministratorInterface $administrator)
    {
    }
}
