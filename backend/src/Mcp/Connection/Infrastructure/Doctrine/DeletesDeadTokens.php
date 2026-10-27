<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Doctrine;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;

/**
 * La suppression des jetons morts de la purge quotidienne (#649), écrite une
 * fois pour les codes, les jetons d'accès et les rafraîchissements (lot C des
 * relectures) : chaque entité porte `expiresAt` et `connection`, et ce qui la
 * rend morte est le même — échue (`expires_at <= $now`, exclusif comme son
 * jugement), ou d'une connexion révoquée, que plus rien n'accepte. Ce que
 * cela garde ou réécrit pour chacune (un code échangé, un rafraîchissement
 * tourné d'une connexion active ; le lien d'un rafraîchissement remis à NULL)
 * est dit sur son interface de dépôt.
 *
 * Une instruction DQL du QueryBuilder, sans instance chargée :
 * `DELETE FROM <table> WHERE expires_at <= ? OR connection_id IN (SELECT id
 * FROM mcp_connection WHERE revoked_at IS NOT NULL)`. Les mêmes lignes que le
 * `DELETE … USING mcp_connection` qu'elle remplace (la clé étrangère vers la
 * connexion est non nulle), et pas plus que lui elle ne verrouille de ligne
 * de connexion — la sous-requête est une lecture : l'ordre C → A → R de la
 * purge reste celui de ses appels. L'instant est typé comme sa colonne, avec
 * fuseau (#434). Aucun index sur `expires_at` : les tables restent bornées
 * par cette purge, à revoir si la volumétrie le demande.
 *
 * @phpstan-require-extends ServiceEntityRepository
 */
trait DeletesDeadTokens
{
    public function deleteDead(DateTimeImmutable $now): int
    {
        $revokedConnections = $this->getEntityManager()->createQueryBuilder()
            ->select('revoked.id')
            ->from(McpConnection::class, 'revoked')
            ->where('revoked.revokedAt IS NOT NULL');

        return $this->createQueryBuilder('token')
            ->delete()
            ->where('token.expiresAt <= :now')
            ->orWhere('IDENTITY(token.connection) IN ('.$revokedConnections->getDQL().')')
            ->setParameter('now', $now, Types::DATETIMETZ_IMMUTABLE)
            ->getQuery()
            ->execute();
    }
}
