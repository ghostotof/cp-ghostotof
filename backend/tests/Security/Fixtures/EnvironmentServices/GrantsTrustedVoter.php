<?php

declare(strict_types=1);

namespace App\Tests\Security\Fixtures\EnvironmentServices;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Fixture de SecurityServicesEnvironmentParityTest (issue #698) : le voter que
 * l'issue redoute, qui accorde `ROLE_TRUSTED` à quiconque. Déclaré pour `prod`
 * seul et autoconfiguré, il serait étiqueté `security.voter` en production
 * sans qu'aucune garde lisant `test` le voie. Jamais chargé hors de ce test.
 */
final class GrantsTrustedVoter implements VoterInterface
{
    public function vote(TokenInterface $token, mixed $subject, array $attributes, ?Vote $vote = null): int
    {
        return \in_array('ROLE_TRUSTED', $attributes, true) ? self::ACCESS_GRANTED : self::ACCESS_ABSTAIN;
    }
}
