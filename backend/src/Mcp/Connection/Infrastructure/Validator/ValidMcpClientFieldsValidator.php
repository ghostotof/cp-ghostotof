<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Validator;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Exception\InvalidMcpClientIdException;
use App\Mcp\Connection\Domain\Exception\InvalidMcpClientNameException;
use App\Mcp\Connection\Domain\Exception\InvalidRedirectUriException;
use App\Mcp\Connection\Domain\ValueObject\AllowedClientDomains;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use App\Mcp\Connection\Domain\ValueObject\McpClientName;
use App\Mcp\Connection\Domain\ValueObject\RedirectUri;
use Override;
use ReflectionProperty;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Juge un client par les value objects du domaine, une violation par champ
 * fautif, au chemin du champ.
 *
 * Les messages sont ceux des exceptions du domaine : ils ne recopient jamais
 * la valeur fautive (texte fixe), donc rien de ce que l'administrateur a saisi
 * ne revient dans la réponse ni dans un journal.
 *
 * Le nombre de redirections se compte comme {@see McpClient::register()} : les
 * redirections admises, dédupliquées sur leur forme écrite (le domaine refuse
 * une forme non normalisée au lieu de la normaliser, deux écritures d'une même
 * adresse ne se confondent donc jamais en une), de une à
 * {@see McpClient::MAX_REDIRECT_URIS}. Les redirections forment une liste : un
 * tableau à clés est refusé d'un bloc, sans jamais citer la clé.
 *
 * Un identifiant qui est l'URL de métadonnées d'un domaine vérifié
 * ({@see CimdClientId} dont l'hôte est dans {@see AllowedClientDomains}) est
 * refusé : une ligne pré-enregistrée l'emporte sur CIMD (règle D-C de
 * l'admission), si bien que l'enregistrer masquerait le vrai client avant sa
 * première approbation, sous un nom et des redirections choisis ici. La règle
 * suit exactement celle de l'admission (`CimdClientId::tryFrom()` puis
 * `AllowedClientDomains::allows()`) : une URL d'un autre domaine, ou que
 * `CimdClientId` ne reconnaît pas (port, `http://`, hôte en majuscules), reste
 * un identifiant ordinaire.
 */
final class ValidMcpClientFieldsValidator extends ConstraintValidator
{
    public function __construct(private readonly AllowedClientDomains $allowedClientDomains)
    {
    }

    #[Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidMcpClientFields) {
            throw new UnexpectedTypeException($constraint, ValidMcpClientFields::class);
        }

        if (!\is_object($value)) {
            throw new UnexpectedValueException($value, 'object with public name and redirectUris');
        }

        // Lecture par réflexion : property_exists() est vrai d'une propriété
        // privée, protégée ou typée non initialisée, que `$value->name` ferait
        // échouer en \Error (un 500) au lieu de la refuser proprement.
        $name = $this->readPublicProperty($value, 'name');
        $redirectUris = $this->readPublicProperty($value, 'redirectUris');

        if (!\is_string($name) || !\is_array($redirectUris)) {
            throw new UnexpectedValueException($value, 'object with string name and array redirectUris');
        }

        // Absent (révision) ou null : l'identifiant est immuable, rien à juger.
        // Absent n'est pas illisible : une propriété déclarée mais privée ou non
        // initialisée est une forme inattendue, refusée par readPublicProperty().
        $clientId = property_exists($value, 'clientId') ? $this->readPublicProperty($value, 'clientId') : null;

        if (null !== $clientId) {
            if (!\is_string($clientId)) {
                throw new UnexpectedValueException($value, 'object with ?string clientId');
            }

            $this->validateClientId($clientId, $constraint);
        }

        $this->validateName($name);
        $this->validateRedirects($redirectUris, $constraint);
    }

    /**
     * Lit une propriété publique et initialisée, sinon refuse la forme.
     */
    private function readPublicProperty(object $value, string $property): mixed
    {
        if (!property_exists($value, $property)) {
            throw new UnexpectedValueException($value, 'object with public name and redirectUris');
        }

        $reflection = new ReflectionProperty($value, $property);

        if (!$reflection->isPublic() || !$reflection->isInitialized($value)) {
            throw new UnexpectedValueException($value, \sprintf('object with a public, initialized %s', $property));
        }

        return $reflection->getValue($value);
    }

    private function validateClientId(string $clientId, ValidMcpClientFields $constraint): void
    {
        try {
            McpClientId::fromString($clientId);
        } catch (InvalidMcpClientIdException $exception) {
            $this->addViolationAt('clientId', $exception->getMessage());

            return;
        }

        // Message fixe : l'URL saisie ne revient pas dans la réponse.
        $cimdClientId = CimdClientId::tryFrom($clientId);

        if (null !== $cimdClientId && $this->allowedClientDomains->allows($cimdClientId)) {
            $this->addViolationAt('clientId', $constraint->verifiedDomainClientIdMessage);
        }
    }

    private function validateName(string $name): void
    {
        try {
            McpClientName::fromString($name);
        } catch (InvalidMcpClientNameException $exception) {
            $this->addViolationAt('name', $exception->getMessage());
        }
    }

    /**
     * @param array<array-key, mixed> $redirectUris
     */
    private function validateRedirects(array $redirectUris, ValidMcpClientFields $constraint): void
    {
        if ([] === $redirectUris) {
            $this->addViolationAt('redirectUris', $constraint->noRedirectMessage);

            return;
        }

        // Une liste, ou rien : une clé d'appelant (`redirectUris[evil]`) irait
        // sinon dans le chemin de la violation, renvoyé tel quel dans la réponse.
        if (!array_is_list($redirectUris)) {
            $this->addViolationAt('redirectUris', $constraint->notAListMessage);

            return;
        }

        // Clé = forme écrite : la déduplication de McpClient::distinctBounded().
        $distinct = [];

        foreach ($redirectUris as $index => $redirectUri) {
            $path = \sprintf('redirectUris[%s]', $index);

            if (!\is_string($redirectUri)) {
                $this->addViolationAt($path, $constraint->notAStringMessage);

                continue;
            }

            try {
                $distinct[(string) RedirectUri::fromString($redirectUri)] = true;
            } catch (InvalidRedirectUriException $exception) {
                $this->addViolationAt($path, $exception->getMessage());
            }
        }

        if (\count($distinct) > McpClient::MAX_REDIRECT_URIS) {
            $this->addViolationAt('redirectUris', $constraint->tooManyRedirectsMessage);
        }
    }

    private function addViolationAt(string $path, string $message): void
    {
        $this->context->buildViolation($message)->atPath($path)->addViolation();
    }
}
