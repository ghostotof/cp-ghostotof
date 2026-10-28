<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;
use DomainException;

/**
 * Accorder une connexion MCP exige une authentification récente (#647, F1 de
 * la relecture) : le jeton de session de la personne a été émis il y a plus
 * de ConsentService::MAX_AUTHENTICATION_AGE, ou son instant ne peut être
 * établi.
 *
 * Le cookie `BEARER` vit une heure et ne se révoque pas (ADR 0003) : volé, il
 * ne doit pas suffire à ouvrir une connexion de trente jours au corpus
 * nominatif. Un 401 problem+json à son propre `type`, que la page de
 * consentement reconnaît pour renvoyer la personne à la connexion puis à la
 * même demande — jamais confondu avec une session expirée (un autre 401, sans
 * ce `type`), qui n'invite pas à revenir accorder.
 *
 * Deux causes, un seul `type` : l'authentification trop ancienne
 * (toGrantAConnection()) et le mot de passe changé depuis (afterAPasswordChange()).
 *
 * Message constant (le `detail` est rendu tel quel, ProblemDetailStaysStaticTest),
 * `log_level: info` : une issue ordinaire d'une page restée ouverte.
 */
final class ReauthenticationRequiredException extends DomainException implements ProblemExceptionInterface
{
    use HasProblemType;

    public static function toGrantAConnection(): self
    {
        return new self('Accorder une connexion MCP demande de vous être authentifié récemment : reconnectez-vous, puis accordez de nouveau.');
    }

    /**
     * Le mot de passe du compte a changé à l'instant ou après l'authentification
     * de la session (#650, F2 de la relecture) : le cookie n'est plus celui de
     * la personne qui détient aujourd'hui le mot de passe. **Même `type`**, la
     * page n'en connaît qu'un (elle renvoie à la connexion, avec `reauth=1`) ;
     * seule la phrase change, constante elle aussi.
     */
    public static function afterAPasswordChange(): self
    {
        return new self('Le mot de passe de votre compte a changé depuis votre connexion : reconnectez-vous, puis recommencez.');
    }

    protected function problemType(): string
    {
        return 'reauthentication-required';
    }

    protected function problemStatus(): int
    {
        return 401;
    }
}
