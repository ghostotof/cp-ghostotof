<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Mcp\Infrastructure\Corpus\NominativeCorpusReader;
use App\Tests\Support\PhpSources;
use App\Tests\Support\RepositoryFiles;
use PHPUnit\Framework\TestCase;

/**
 * Toute autorisation de `/api` est une règle d'`access_control` (#748, C5-12).
 *
 * {@see \App\Security\Authentication\Infrastructure\Security\PublicRouteIgnoresRefusedSessionListener}
 * tient « route publique = route sans règle d'`access_control` » : sur une telle route, un
 * cookie `BEARER` refusé est ignoré et la requête servie comme à l'anonyme. Un
 * `#[IsGranted]` posé sur une route **sans** règle romprait cette prémisse en silence : il
 * refuserait bien l'anonyme, mais une session morte serait servie comme anonyme, puis
 * refusée par l'attribut, et le cookie refusé ne serait jamais effacé (le 401 du pare-feu,
 * qui l'efface, ne viendrait plus). Aucun autre garde ne le verrait : l'invariant n° 8 de
 * `ApiRouteExposureTest` ne compare que les statuts.
 *
 * Refusés dans `src/` (par jetons, commentaires exclus) : l'attribut `IsGranted`, l'appel
 * `denyAccessUnlessGranted`, les arguments nommés `security:`, `securityPostDenormalize:` et
 * `securityPostValidation:` (API Platform), et tout appel `->isGranted(` hors de
 * {@see self::ISGRANTED_OUTSIDE_API}. Cette liste est une exception justifiée par entrée, et
 * chaque entrée doit rester nécessaire.
 *
 * Non vus, limite assumée : un appel dynamique (`$security->{$method}()`), une autorisation
 * posée par le vendor ou par la configuration (ce que `access_control` est, justement), et
 * un `->isGranted(` d'un autre objet que la sécurité, homonyme : le renommer.
 */
final class AuthorizationLivesInAccessControlTest extends TestCase
{
    /** Les arguments nommés par lesquels API Platform pose une autorisation sur une opération. */
    private const array NAMED_ARGUMENTS = ['security', 'securitypostdenormalize', 'securitypostvalidation'];

    /**
     * Les fichiers qui appellent `->isGranted(` sans que ce soit l'autorisation d'une route de `/api`.
     *
     * @var array<class-string, string> classe => raison
     */
    private const array ISGRANTED_OUTSIDE_API = [
        NominativeCorpusReader::class => 'Pare-feu `mcp`, pas `api` : le connecteur MCP juge le palier de la connexion, qu\'aucune règle d\'`access_control` de `/api/mcp` ne peut exprimer (le jeton est opaque, vérifié en base).',
    ];

    public function testNoAuthorizationIsDeclaredOutsideAccessControl(): void
    {
        $sources = RepositoryFiles::backend('src');
        $allowed = array_map(static fn (string $class): string => self::pathOf($class), array_keys(self::ISGRANTED_OUTSIDE_API));

        $found = [];
        foreach (PhpSources::files($sources) as $path) {
            $isAllowedFile = \in_array($path, $allowed, true);
            foreach ($this->authorizationSites(RepositoryFiles::read($path)) as [$line, $kind]) {
                if ('isGranted' === $kind && $isAllowedFile) {
                    continue;
                }
                $found[] = $path.':'.$line.' ('.$kind.')';
            }
        }

        self::assertSame(
            [],
            $found,
            'Une autorisation de /api est une règle d\'access_control de security.yaml, jamais un #[IsGranted], un denyAccessUnlessGranted, '
            .'un `security:` d\'API Platform ou un isGranted() : PublicRouteIgnoresRefusedSessionListener tient « route publique = route sans '
            .'règle ». Voir le docblock de ce test.',
        );
    }

    public function testEveryAllowListEntryIsStillNeeded(): void
    {
        foreach (self::ISGRANTED_OUTSIDE_API as $class => $reason) {
            $path = self::pathOf($class);
            $kinds = array_column($this->authorizationSites(RepositoryFiles::read($path)), 1);

            self::assertContains(
                'isGranted',
                $kinds,
                \sprintf('%s est exemptée (%s) mais n\'appelle plus ->isGranted() : retirer l\'entrée de ISGRANTED_OUTSIDE_API.', $class, $reason),
            );
        }
    }

    /**
     * @return list<array{int, string}> ligne et nature (`IsGranted`, `denyAccessUnlessGranted`, `security:`, `isGranted`) de chaque site
     */
    private function authorizationSites(string $code): array
    {
        $tokens = PhpSources::significantTokens($code);

        $sites = [];
        foreach ($tokens as $index => $token) {
            $previous = $tokens[$index - 1] ?? null;
            $next = $tokens[$index + 1] ?? null;

            // L'attribut, son import ou son nom complet : le dernier segment du nom.
            if ($token->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE])
                && 'isgranted' === strtolower(basename(str_replace('\\', '/', $token->text)))
                && !($previous?->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION]) ?? false)
            ) {
                $sites[] = [$token->line, 'IsGranted'];
                continue;
            }

            if (!$token->is(\T_STRING)) {
                continue;
            }

            $name = strtolower($token->text);
            $isMethodCall = ($previous?->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR]) ?? false) && '(' === $next?->text;

            if ('denyaccessunlessgranted' === $name) {
                $sites[] = [$token->line, 'denyAccessUnlessGranted'];
            } elseif ('isgranted' === $name && $isMethodCall) {
                $sites[] = [$token->line, 'isGranted'];
            } elseif (\in_array($name, self::NAMED_ARGUMENTS, true) && ':' === $next?->text && \in_array($previous?->text, ['(', ','], true)) {
                $sites[] = [$token->line, 'security:'];
            }
        }

        return $sites;
    }

    /**
     * @param class-string $class
     */
    private static function pathOf(string $class): string
    {
        return RepositoryFiles::backend('src/'.str_replace('\\', '/', substr($class, \strlen('App\\'))).'.php');
    }
}
