<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PhpToken;

/**
 * Recense, dans un code source, les endroits où une autorisation de `/api` est
 * déclarée ou refusée ailleurs que dans une règle d'`access_control`
 * (`AuthorizationLivesInAccessControlTest`, #748).
 *
 * Par jetons (`PhpSources::significantTokens()`), commentaires exclus : un site
 * nommé dans un docblock n'en est pas un. Chaque site est rendu avec sa ligne
 * et sa nature ({@see \App\Tests\Support\AuthorizationSiteKind}) :
 * - `IsGranted` : l'attribut, son import ou son nom complet ;
 * - `denyAccessUnlessGranted` : l'appel du contrôleur de base ;
 * - `security:` : les arguments nommés par lesquels API Platform pose une
 *   autorisation sur une opération ;
 * - `isGranted` : un appel `->isGranted(`, `?->isGranted(` ou `::isGranted(` ;
 * - `refusal` : `new AccessDeniedException`, `new AccessDeniedHttpException` ou
 *   `new UnauthorizedHttpException`, nom court ou complet : un refus levé à la
 *   main décide à la place de `access_control` ;
 * - `decisionManager` : le nom `AccessDecisionManagerInterface`, sans lequel
 *   aucun `decide()` d'autorisation n'est injectable (refuser le nom plutôt que
 *   `->decide(`, que le consentement OAuth porte aussi, homonyme) ;
 * - `is_granted` : une chaîne littérale qui contient `is_granted(`, l'expression
 *   de sécurité d'API Platform ou d'un `#[Security]`.
 *
 * **Ne voit pas** (le détecteur n'est pas élargi, décision 1 de la septième
 * relecture de #748) : un `#[CurrentUser]` non nullable,
 * `createAccessDeniedException()`, `new HttpException(403)`,
 * `InsufficientAuthenticationException`, `isGrantedForUser()` et
 * `UserAuthorizationCheckerInterface`, une exception de refus importée sous un
 * alias (`use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException as Denied`, C7-2), un callable
 * `[$security, 'isGranted']` (C7-2), un appel dynamique (`$security->{$method}()`),
 * une sous-classe des trois exceptions, une réponse 403 écrite à la main, une
 * exception métier rendue en 401 ou 403 (`exception_to_status`), une chaîne
 * `is_granted(` construite par concaténation, un `isGranted()` d'un autre objet
 * que la sécurité (homonyme : le renommer), le vendor et la configuration (la
 * configuration compilée d'API Platform est lue par
 * `ApiPlatformOperationsCarryNoSecurityTest`).
 *
 * Sur une route hors de `PUBLIC_PATHS`, l'invariant n° 9 d'`ApiRouteExposureTest`
 * voit toutes ces formes à l'exécution (une route sans règle y rougit) ; sur une
 * route de `PUBLIC_PATHS`, aucun témoin : limite écrite dans la source des témoins,
 * `.claude/rules/security-authentication-sessions.md` (puce de
 * `PublicRouteIgnoresRefusedSessionListener`).
 */
final class AuthorizationSites
{
    /** Les arguments nommés par lesquels API Platform pose une autorisation sur une opération. */
    private const array NAMED_ARGUMENTS = ['security', 'securitypostdenormalize', 'securitypostvalidation'];

    /** Les jetons qui portent un nom de classe, court, qualifié ou de nom complet. */
    private const array NAMES = [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE];

    /** Les exceptions dont la levée à la main est un refus d'accès. */
    private const array REFUSALS = ['accessdeniedexception', 'accessdeniedhttpexception', 'unauthorizedhttpexception'];

    /** Ce qui précède un nom `IsGranted` sans en faire l'attribut : un appel, ou la déclaration d'une méthode. */
    private const array NOT_AN_ATTRIBUTE_AFTER = [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION];

    /**
     * @return list<array{int, AuthorizationSiteKind}> ligne et nature de chaque site, dans l'ordre du code
     */
    public static function in(string $code): array
    {
        $tokens = PhpSources::significantTokens($code);

        $sites = [];
        foreach ($tokens as $index => $token) {
            $kind = self::kindAt($tokens, $index);
            if (null !== $kind) {
                $sites[] = [$token->line, $kind];
            }
        }

        return $sites;
    }

    /**
     * La nature du site que porte le jeton, s'il en porte un. Les trois lectures sont exclusives :
     * une chaîne n'est pas un nom, et un appel est un `T_STRING` que les noms n'ont pas retenu.
     *
     * @param list<PhpToken> $tokens
     */
    private static function kindAt(array $tokens, int $index): ?AuthorizationSiteKind
    {
        $token = $tokens[$index];
        $previous = $tokens[$index - 1] ?? null;
        $next = $tokens[$index + 1] ?? null;

        return self::ofStringLiteral($token)
            ?? self::ofName($token, $previous)
            ?? self::ofCall($token, $previous, $next);
    }

    /**
     * Une chaîne qui contient `is_granted(` : l'expression de sécurité d'API Platform ou d'un `#[Security]`.
     */
    private static function ofStringLiteral(PhpToken $token): ?AuthorizationSiteKind
    {
        if (!$token->is([\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE])) {
            return null;
        }

        return 1 === preg_match('/is_granted\s*\(/i', $token->text) ? AuthorizationSiteKind::IsGrantedExpression : null;
    }

    /**
     * Un nom de classe, court, importé ou de nom complet : l'attribut, un refus levé, le gestionnaire de décision.
     */
    private static function ofName(PhpToken $token, ?PhpToken $previous): ?AuthorizationSiteKind
    {
        if (!$token->is(self::NAMES)) {
            return null;
        }

        // Le dernier segment du nom : `IsGranted` écrit court, importé ou de nom complet.
        $short = strtolower(basename(str_replace('\\', '/', $token->text)));

        if ('isgranted' === $short && !($previous?->is(self::NOT_AN_ATTRIBUTE_AFTER) ?? false)) {
            return AuthorizationSiteKind::IsGrantedAttribute;
        }
        // `new` seul : attraper (`catch`), tester (`instanceof`) ou importer un refus n'en lève aucun.
        if (\in_array($short, self::REFUSALS, true) && ($previous?->is(\T_NEW) ?? false)) {
            return AuthorizationSiteKind::Refusal;
        }
        if ('accessdecisionmanagerinterface' === $short) {
            return AuthorizationSiteKind::DecisionManager;
        }

        return null;
    }

    /**
     * Un appel (`denyAccessUnlessGranted`, `->isGranted(`, `::isGranted(`) ou un argument nommé d'API Platform.
     */
    private static function ofCall(PhpToken $token, ?PhpToken $previous, ?PhpToken $next): ?AuthorizationSiteKind
    {
        if (!$token->is(\T_STRING)) {
            return null;
        }

        $name = strtolower($token->text);
        $isCall = '(' === $next?->text;
        $isMethodCall = ($previous?->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR]) ?? false) && $isCall;
        $isStaticCall = ($previous?->is(\T_DOUBLE_COLON) ?? false) && $isCall;

        return match (true) {
            'denyaccessunlessgranted' === $name => AuthorizationSiteKind::DenyAccessUnlessGranted,
            'isgranted' === $name && ($isMethodCall || $isStaticCall) => AuthorizationSiteKind::IsGrantedCall,
            \in_array($name, self::NAMED_ARGUMENTS, true)
                && ':' === $next?->text
                && \in_array($previous?->text, ['(', ','], true) => AuthorizationSiteKind::SecurityArgument,
            default => null,
        };
    }
}
