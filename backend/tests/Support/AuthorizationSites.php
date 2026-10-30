<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Recense, dans un code source, les endroits où une autorisation de `/api` est
 * déclarée ou refusée ailleurs que dans une règle d'`access_control`
 * (`AuthorizationLivesInAccessControlTest`, #748).
 *
 * Par jetons (`PhpSources::significantTokens()`), commentaires exclus : un site
 * nommé dans un docblock n'en est pas un. Chaque site est rendu avec sa ligne
 * et sa nature :
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
 * Limites assumées, sans cas dans `src/` aujourd'hui : un appel dynamique
 * (`$security->{$method}()`), une sous-classe des trois exceptions, une
 * autorisation posée par le vendor ou par la configuration, et une chaîne
 * `is_granted(` construite par concaténation (la configuration compilée d'API
 * Platform est lue par `ApiPlatformOperationsCarryNoSecurityTest`).
 */
final class AuthorizationSites
{
    /** Les arguments nommés par lesquels API Platform pose une autorisation sur une opération. */
    private const array NAMED_ARGUMENTS = ['security', 'securitypostdenormalize', 'securitypostvalidation'];

    /** Les jetons qui portent un nom de classe, court, qualifié ou de nom complet. */
    private const array NAMES = [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE];

    /** Les exceptions dont la levée à la main est un refus d'accès. */
    private const array REFUSALS = ['accessdeniedexception', 'accessdeniedhttpexception', 'unauthorizedhttpexception'];

    /**
     * @return list<array{int, string}> ligne et nature de chaque site, dans l'ordre du code
     */
    public static function in(string $code): array
    {
        $tokens = PhpSources::significantTokens($code);

        $sites = [];
        foreach ($tokens as $index => $token) {
            $previous = $tokens[$index - 1] ?? null;
            $next = $tokens[$index + 1] ?? null;

            // Une chaîne qui contient `is_granted(` : l'expression de sécurité d'API Platform ou d'un `#[Security]`.
            if ($token->is([\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE]) && 1 === preg_match('/is_granted\s*\(/i', $token->text)) {
                $sites[] = [$token->line, 'is_granted'];
                continue;
            }

            if ($token->is(self::NAMES)) {
                // Le dernier segment du nom : `IsGranted` écrit court, importé ou de nom complet.
                $short = strtolower(basename(str_replace('\\', '/', $token->text)));

                if ('isgranted' === $short && !($previous?->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION]) ?? false)) {
                    $sites[] = [$token->line, 'IsGranted'];
                    continue;
                }
                // `new` seul : attraper (`catch`), tester (`instanceof`) ou importer un refus n'en lève aucun.
                if (\in_array($short, self::REFUSALS, true) && ($previous?->is(\T_NEW) ?? false)) {
                    $sites[] = [$token->line, 'refusal'];
                    continue;
                }
                if ('accessdecisionmanagerinterface' === $short) {
                    $sites[] = [$token->line, 'decisionManager'];
                    continue;
                }
            }

            if (!$token->is(\T_STRING)) {
                continue;
            }

            $name = strtolower($token->text);
            $isCall = '(' === $next?->text;
            $isMethodCall = ($previous?->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR]) ?? false) && $isCall;
            $isStaticCall = ($previous?->is(\T_DOUBLE_COLON) ?? false) && $isCall;

            if ('denyaccessunlessgranted' === $name) {
                $sites[] = [$token->line, 'denyAccessUnlessGranted'];
            } elseif ('isgranted' === $name && ($isMethodCall || $isStaticCall)) {
                $sites[] = [$token->line, 'isGranted'];
            } elseif (\in_array($name, self::NAMED_ARGUMENTS, true) && ':' === $next?->text && \in_array($previous?->text, ['(', ','], true)) {
                $sites[] = [$token->line, 'security:'];
            }
        }

        return $sites;
    }
}
