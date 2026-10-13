<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Support;

use App\Security\User\Domain\Entity\CpgUser;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les comptes ROLE_SUPER tels que la base les porte (issue #560, partagé par
 * les tests de l'administration des comptes) : lu en SQL, jamais par la carte
 * d'identité, que ces tests accusent. Écrit indépendamment de
 * `CpgUserRepository::countOtherSuperAdminsAbleToSignIn()` exprès : un test qui
 * reprendrait la requête du dépôt ne pourrait plus la contredire.
 *
 * Pour un `KernelTestCase` déjà démarré.
 */
trait ReadsStoredSuperAdmins
{
    /**
     * @return list<string> leurs identifiants, triés
     */
    private function storedSuperAdminUsernames(): array
    {
        $rows = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchFirstColumn(
            'SELECT username FROM cpg_user WHERE CAST(roles AS jsonb) @> CAST(:role AS jsonb) ORDER BY username',
            ['role' => json_encode([CpgUser::ROLE_SUPER], \JSON_THROW_ON_ERROR)],
        );

        $usernames = [];
        foreach ($rows as $username) {
            self::assertIsString($username);
            $usernames[] = $username;
        }

        return $usernames;
    }
}
