<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Mcp\Infrastructure\Corpus\NominativeCorpusReader;
use App\Security\Authentication\Infrastructure\Http\CsrfCookieRequestSubscriber;
use App\Security\Authentication\Infrastructure\Http\LoginCsrfRequestListener;
use App\Tests\Support\AuthorizationSites;
use App\Tests\Support\PhpSources;
use App\Tests\Support\RepositoryFiles;
use PHPUnit\Framework\Attributes\DataProvider;
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
 * qui l'efface, ne viendrait plus). L'invariant n° 8 d'`ApiRouteExposureTest` ne le verrait
 * pas : il ne juge l'effacement que sur une route qui exige un rôle, et une route sans règle
 * n'en exige pas (R59). Hors de `PUBLIC_PATHS`, l'invariant n° 9 d'`ApiRouteExposureTest` voit à
 * l'exécution une route sans règle ; sur une route de `PUBLIC_PATHS`, seule une garde de source
 * voit un `#[IsGranted]`. Les témoins de cette prémisse sont listés une fois, dans
 * `.claude/rules/security-authentication-sessions.md` (puce de
 * `PublicRouteIgnoresRefusedSessionListener`).
 *
 * Refusés dans `src/` (par jetons, commentaires exclus, {@see AuthorizationSites}) : l'attribut
 * `IsGranted`, l'appel `denyAccessUnlessGranted`, les arguments nommés `security:`,
 * `securityPostDenormalize:` et `securityPostValidation:` (API Platform), tout appel
 * `->isGranted(`, `?->isGranted(` ou `::isGranted(`, un refus levé à la main (`new
 * AccessDeniedException`, `new AccessDeniedHttpException`, `new UnauthorizedHttpException`),
 * le nom `AccessDecisionManagerInterface` et toute chaîne qui contient `is_granted(`, hors de
 * {@see self::EXEMPTIONS}. Chaque exemption dit sa raison et le **nombre exact** de sites de
 * chaque nature qu'elle couvre : un site de plus dans un fichier exempté est refusé, un site de
 * moins rend l'entrée superflue, et les deux échouent. Le détecteur est éprouvé sur des extraits
 * (le recensement d'un code qui ne trouverait jamais rien rendrait le premier test vert sans
 * rien garder).
 *
 * Non vus, limite assumée : un appel dynamique (`$security->{$method}()`), une sous-classe des
 * trois exceptions, une autorisation posée par le vendor ou par la configuration (ce que
 * `access_control` est, justement), un `isGranted()` d'un autre objet que la sécurité, homonyme :
 * le renommer.
 */
final class AuthorizationLivesInAccessControlTest extends TestCase
{
    /**
     * Les fichiers qui portent des sites sans que ce soit l'autorisation d'une route de `/api`.
     *
     * @var array<class-string, array{string, array<string, int>}> classe => [raison, nature => nombre exact de sites]
     */
    private const array EXEMPTIONS = [
        NominativeCorpusReader::class => [
            'Pare-feu `mcp`, pas `api` : le connecteur MCP juge le palier de la connexion, qu\'aucune règle d\'`access_control` de `/api/mcp` ne peut exprimer (le jeton est opaque, vérifié en base).',
            ['isGranted' => 1],
        ],
        CsrfCookieRequestSubscriber::class => [
            'Garde du double-submit, priorité 20, avant le pare-feu : son 403 refuse une écriture sans jeton CSRF valide, il n\'autorise aucune route.',
            ['refusal' => 2],
        ],
        LoginCsrfRequestListener::class => [
            'Garde du login-CSRF, priorité 20, avant le pare-feu : son 403 refuse une requête sans `X-Requested-With`, il n\'autorise aucune route.',
            ['refusal' => 1],
        ],
    ];

    public function testNoAuthorizationIsDeclaredOutsideAccessControl(): void
    {
        $found = [];
        foreach (PhpSources::files(RepositoryFiles::backend('src')) as $path) {
            $exempted = $this->exemptedKindsOf($path);
            $seen = [];
            foreach (AuthorizationSites::in(RepositoryFiles::read($path)) as [$line, $kind]) {
                $seen[$kind] = ($seen[$kind] ?? 0) + 1;
                // Une exemption couvre un nombre exact de sites : le suivant est un site de trop.
                if ($seen[$kind] > ($exempted[$kind] ?? 0)) {
                    $found[] = $path.':'.$line.' ('.$kind.')';
                }
            }
        }

        self::assertSame(
            [],
            $found,
            'Une autorisation de /api est une règle d\'access_control de security.yaml, jamais un #[IsGranted], un denyAccessUnlessGranted, '
            .'un `security:` d\'API Platform, un isGranted(), un refus levé à la main ou un AccessDecisionManagerInterface : '
            .'PublicRouteIgnoresRefusedSessionListener tient « route publique = route sans règle ». Voir le docblock de ce test.',
        );
    }

    /**
     * @param list<array{int, string}> $expected ligne et nature de chaque site
     */
    #[DataProvider('formsTheDetectorSees')]
    public function testTheDetectorSeesEachForm(string $code, array $expected): void
    {
        self::assertSame($expected, AuthorizationSites::in($code));
    }

    /**
     * @return iterable<string, array{string, list<array{int, string}>}>
     */
    public static function formsTheDetectorSees(): iterable
    {
        yield 'attribut, nom court' => ["<?php\n#[IsGranted('X')]\nfinal class A {}\n", [[2, 'IsGranted']]];
        yield 'attribut, nom complet' => ["<?php\n#[\\Symfony\\Component\\Security\\Http\\Attribute\\IsGranted('X')]\nfinal class A {}\n", [[2, 'IsGranted']]];
        yield 'import de l\'attribut' => ["<?php\nuse Symfony\\Component\\Security\\Http\\Attribute\\IsGranted;\n", [[2, 'IsGranted']]];
        yield 'denyAccessUnlessGranted' => ["<?php\n\$this->denyAccessUnlessGranted('X');\n", [[2, 'denyAccessUnlessGranted']]];
        yield 'security: d\'API Platform' => ["<?php\n\$a = new Get(security: \"x\");\n", [[2, 'security:']]];
        yield 'securityPostDenormalize:' => ["<?php\n\$a = new Put(securityPostDenormalize: \"x\");\n", [[2, 'security:']]];
        yield 'securityPostValidation: après un autre argument' => ["<?php\n\$a = new Put(uriTemplate: '/x', securityPostValidation: \"x\");\n", [[2, 'security:']]];
        yield 'appel ->isGranted(' => ["<?php\n\$security->isGranted('X');\n", [[2, 'isGranted']]];
        yield 'appel ?->isGranted(' => ["<?php\n\$security?->isGranted('X');\n", [[2, 'isGranted']]];
        yield 'appel statique ::isGranted(' => ["<?php\nSecurity::isGranted('X');\n", [[2, 'isGranted']]];
        yield 'appel statique, nom complet' => ["<?php\n\\Symfony\\Bundle\\SecurityBundle\\Security::isGranted('X');\n", [[2, 'isGranted']]];
        yield 'refus levé, AccessDeniedHttpException' => ["<?php\nthrow new AccessDeniedHttpException();\n", [[2, 'refusal']]];
        yield 'refus levé, AccessDeniedException de nom complet' => ["<?php\nthrow new \\Symfony\\Component\\Security\\Core\\Exception\\AccessDeniedException();\n", [[2, 'refusal']]];
        yield 'refus levé, UnauthorizedHttpException' => ["<?php\nthrow new UnauthorizedHttpException('x');\n", [[2, 'refusal']]];
        yield 'gestionnaire de décision, propriété' => ["<?php\nfinal class A { private AccessDecisionManagerInterface \$m; }\n", [[2, 'decisionManager']]];
        yield 'gestionnaire de décision, import' => ["<?php\nuse Symfony\\Component\\Security\\Core\\Authorization\\AccessDecisionManagerInterface;\n", [[2, 'decisionManager']]];
        yield 'chaîne is_granted, guillemets simples' => ["<?php\n\$a = 'is_granted(\"ROLE_X\")';\n", [[2, 'is_granted']]];
        yield 'chaîne is_granted, guillemets doubles' => ["<?php\n\$a = \"is_granted('ROLE_X')\";\n", [[2, 'is_granted']]];
        yield 'chaîne is_granted, interpolée' => ["<?php\n\$a = \"is_granted('ROLE_X') and \$b\";\n", [[2, 'is_granted']]];
    }

    /**
     * @param string $code ce qui n'est pas une autorisation, même s'il y ressemble
     */
    #[DataProvider('formsTheDetectorIgnores')]
    public function testTheDetectorIgnoresWhatIsNotAnAuthorization(string $code): void
    {
        self::assertSame([], AuthorizationSites::in($code));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function formsTheDetectorIgnores(): iterable
    {
        yield 'attribut en commentaire' => ["<?php\n// #[IsGranted('X')]\n"];
        yield 'déclaration d\'une méthode isGranted' => ["<?php\nfinal class A { public function isGranted(): bool { return true; } }\n"];
        yield 'decide() du consentement, homonyme' => ["<?php\n\$consent->decide(\$d);\n"];
        yield 'propriété securityMessage' => ["<?php\n\$a->securityMessage;\n"];
        yield 'refus attrapé, pas levé' => ["<?php\ntry { x(); } catch (AccessDeniedHttpException \$e) {}\n"];
        yield 'refus testé par instanceof' => ["<?php\n\$ok = \$e instanceof AccessDeniedException;\n"];
        yield 'import d\'une exception de refus' => ["<?php\nuse Symfony\\Component\\HttpKernel\\Exception\\AccessDeniedHttpException;\n"];
        yield 'refus en commentaire' => ["<?php\n// throw new AccessDeniedHttpException();\n"];
        yield 'gestionnaire de décision en commentaire' => ["<?php\n/** @see AccessDecisionManagerInterface */\n"];
        yield 'classe homonyme d\'un refus' => ["<?php\n\$a = new AccessDeniedHttpExceptionFactory();\n"];
        yield 'is_granted sans appel' => ["<?php\n\$a = 'is_granted';\n"];
        yield 'is_granted en commentaire' => ["<?php\n// 'is_granted(\"X\")'\n"];
    }

    public function testEveryExemptionIsStillNeeded(): void
    {
        foreach (self::EXEMPTIONS as $class => [$reason, $expectedKinds]) {
            $found = [];
            foreach (AuthorizationSites::in(RepositoryFiles::read($this->pathOf($class))) as [, $kind]) {
                $found[$kind] = ($found[$kind] ?? 0) + 1;
            }
            ksort($found);
            ksort($expectedKinds);

            self::assertSame(
                $expectedKinds,
                $found,
                \sprintf('%s est exemptée (%s) pour des sites qui ne sont plus ceux d\'EXEMPTIONS : attendu %s, trouvé %s. Ajuster le nombre ou retirer l\'entrée.', $class, $reason, json_encode($expectedKinds), json_encode($found)),
            );
        }
    }

    /**
     * @return array<string, int> nature => nombre de sites que l'exemption du fichier couvre (vide s'il n'est pas exempté)
     */
    private function exemptedKindsOf(string $path): array
    {
        foreach (self::EXEMPTIONS as $class => [, $kinds]) {
            if ($this->pathOf($class) === $path) {
                return $kinds;
            }
        }

        return [];
    }

    /**
     * @param class-string $class
     */
    private function pathOf(string $class): string
    {
        return RepositoryFiles::backend('src/'.str_replace('\\', '/', substr($class, \strlen('App\\'))).'.php');
    }
}
