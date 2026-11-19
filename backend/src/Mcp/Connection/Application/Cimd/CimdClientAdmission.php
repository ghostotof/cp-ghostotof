<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application\Cimd;

use App\Mcp\Connection\Application\FailSafeAudit;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Exception\ClientMetadataRefusedException;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\AllowedClientDomains;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataRefusal;
use App\Mcp\Connection\Domain\ValueObject\ClientRegistration;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use Psr\Clock\ClockInterface;

/**
 * L'admission d'un `client_id` CIMD (#651). Les étapes, dans cet ordre :
 *
 *  1. un identifiant qui n'a pas la forme d'une URL CIMD reste aux lignes en
 *     base (`null`) ;
 *  2. une ligne PRÉ-ENREGISTRÉE prime : elle est jugée par league, sans aucune
 *     récupération (un client déclaré à la main ne dépend jamais d'un hôte
 *     tiers) ;
 *  3. un hôte hors de la liste est refusé AVANT toute récupération et sans
 *     audit ;
 *  4. le résolveur rend le document, ou un refus, tracé avec l'hôte et la
 *     cause puis relancé tel quel ;
 *  5. le client rendu est issu du document, jamais de la ligne : une ligne déjà
 *     en mode `cimd` est donc rejugée par son document (et un domaine sorti de
 *     la liste est refusé à l'étape 3).
 *
 * `DomainNotAllowed` n'est jamais tracé, ni à l'étape 3 ni quand le résolveur
 * (ou le récupérateur, défense en profondeur) le lève : l'hôte d'un `client_id`
 * hors liste est choisi librement par un anonyme, et l'écrire au journal
 * donnerait à n'importe qui la plume sur ses lignes (contrat de
 * {@see SecurityAuditLoggerInterface::mcpClientMetadataRefused()}).
 *
 * L'audit `mcpClientMetadataRefused` passe par {@see FailSafeAudit}, et sa
 * panne ne remplace jamais le refus `ClientMetadataRefusedException`, relancé
 * tel quel. Deux pannes se distinguent : celle de l'écriture de l'audit de
 * sécurité sort en `critical` sur le canal principal ; celle du journal
 * principal lui-même, qui ne peut plus rien dire, laisse seulement un
 * `error_log()` de dernier recours (constante et classe de la panne du
 * journal, `$loggerFailure::class`, non celle de l'audit).
 */
final readonly class CimdClientAdmission implements CimdClientAdmissionInterface
{
    /** Le message d'une panne de l'audit du refus d'un document de métadonnées, constant (#651). */
    private const string METADATA_REFUSAL_NOT_AUDITED = 'Refused CIMD client metadata not audited.';

    public function __construct(
        private McpClientRepositoryInterface $clients,
        private AllowedClientDomains $allowed,
        private CimdClientResolverInterface $resolver,
        private SecurityAuditLoggerInterface $audit,
        private ClockInterface $clock,
        private FailSafeAudit $failSafeAudit,
    ) {
    }

    public function admit(string $clientId): ?McpClient
    {
        $id = CimdClientId::tryFrom($clientId);
        if (null === $id) {
            return null;
        }

        $row = $this->clients->findOneByClientId($clientId);
        if (null !== $row && ClientRegistration::Preregistered === $row->registration()) {
            return null;
        }

        if (!$this->allowed->allows($id)) {
            throw ClientMetadataRefusedException::because(ClientMetadataRefusal::DomainNotAllowed);
        }

        try {
            $document = $this->resolver->resolve($id);
        } catch (ClientMetadataRefusedException $refused) {
            if (ClientMetadataRefusal::DomainNotAllowed !== $refused->refusal) {
                // L'audit ne change jamais l'issue : sa panne ne doit pas
                // remplacer le refus (l'appelant n'attrape que celui-ci).
                $this->failSafeAudit->attempt(
                    fn () => $this->audit->mcpClientMetadataRefused($id->host(), $refused->refusal->value),
                    self::METADATA_REFUSAL_NOT_AUDITED,
                );
            }

            throw $refused;
        }

        return McpClient::discovered($document, $this->clock->now());
    }
}
