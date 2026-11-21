<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\ValueObject;

use App\Mcp\Connection\Domain\Exception\InvalidAllowedClientDomainException;

/**
 * Les domaines dont un client CIMD est admis (#651, paramètre versionné
 * `app.mcp.cimd.allowed_domains` de `config/services.yaml`).
 *
 * L'hôte du `client_id` doit figurer dans la liste **tel quel** : aucun
 * sous-domaine implicite (`evil.claude.ai`), aucune comparaison de suffixe ni
 * de préfixe (`notclaude.ai`, `claude.ai.evil.test`). Seconde moitié de la
 * couche 1 contre le SSRF (plan de la tâche 12, D-D) : hors de la
 * liste, aucune récupération ne part, et le client se confond avec un client
 * inconnu. Une liste vide éteint CIMD.
 */
final readonly class AllowedClientDomains
{
    /**
     * @param list<string> $domains
     */
    private function __construct(private array $domains)
    {
    }

    /**
     * @param list<string> $domains
     *
     * @throws InvalidAllowedClientDomainException une entrée n'est pas un nom
     *                                             d'hôte qu'un `client_id`
     *                                             CIMD peut porter
     */
    public static function fromList(array $domains): self
    {
        foreach ($domains as $domain) {
            if (1 !== preg_match(CimdClientId::HOST_PATTERN, $domain)) {
                throw InvalidAllowedClientDomainException::malformed();
            }
        }

        return new self($domains);
    }

    public function allows(CimdClientId $clientId): bool
    {
        return \in_array($clientId->host(), $this->domains, true);
    }

    /**
     * Cet identifiant public est-il l'URL de métadonnées d'un client CIMD d'un
     * domaine vérifié ? `CimdClientId::tryFrom()` puis {@see self::allows()} :
     * la règle de l'admission, que le pré-enregistrement refuse d'usurper
     * (registrar et validateur du backoffice). Ce que `CimdClientId` ne
     * reconnaît pas (port, `http://`, hôte en majuscules) est un identifiant
     * ordinaire.
     */
    public function isVerifiedClientId(string $clientId): bool
    {
        $cimdClientId = CimdClientId::tryFrom($clientId);

        return null !== $cimdClientId && $this->allows($cimdClientId);
    }
}
