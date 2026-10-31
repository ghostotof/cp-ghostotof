<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Mcp\Infrastructure\Corpus\NominativeCorpusReader;
use App\Security\Authentication\Infrastructure\Http\CsrfCookieRequestSubscriber;
use App\Security\Authentication\Infrastructure\Http\LoginCsrfRequestListener;
use App\Tests\Support\AuthorizationSite;
use App\Tests\Support\AuthorizationSiteKind;
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
 * {@see self::EXEMPTIONS}. Chaque exemption dit sa raison et le nombre de sites qu'elle couvre dans
 * son fichier, par nature : un site déplacé dans le fichier reste couvert, un site de plus d'une nature
 * est refusé, un site de moins rend l'entrée superflue, et ces deux-là échouent. Le détecteur est
 * éprouvé sur des extraits (le recensement d'un code qui ne trouverait jamais rien rendrait le premier
 * test vert sans rien garder).
 *
 * Ce que le détecteur ne voit pas, limite assumée (aucun élargissement, décision 1 de la
 * septième relecture) : la liste est dans le docblock d'{@see AuthorizationSites}, avec le renvoi
 * au contrôle à l'exécution (invariant n° 9 d'`ApiRouteExposureTest`) qui voit ces formes sur une
 * route hors de `PUBLIC_PATHS`.
 */
final class AuthorizationLivesInAccessControlTest extends TestCase
{
    /**
     * Les fichiers qui portent des sites sans que ce soit l'autorisation d'une route de `/api`.
     *
     * Une exemption compte les sites **par nature et par fichier**, pas site par site (C7-13) : un
     * site déplacé dans le fichier exempté, ou remplacé par un autre de même nature, reste couvert.
     * Chaque site couvert est écrit une fois dans `sites` : un de plus ou de moins se lit dans la liste.
     *
     * Classe => raison et natures des sites couverts.
     *
     * @var array<class-string, array{reason: string, sites: list<AuthorizationSiteKind>}>
     */
    private const array EXEMPTIONS = [
        NominativeCorpusReader::class => [
            'reason' => 'Pare-feu `mcp`, pas `api` : le connecteur MCP juge le palier de la connexion, '
                .'qu\'aucune règle d\'`access_control` de `/api/mcp` ne peut exprimer (le jeton est opaque, vérifié en base).',
            'sites' => [AuthorizationSiteKind::IsGrantedCall],
        ],
        CsrfCookieRequestSubscriber::class => [
            'reason' => 'Garde du double-submit, priorité 20, avant le pare-feu : '
                .'son 403 refuse une écriture sans jeton CSRF valide, il n\'autorise aucune route.',
            'sites' => [AuthorizationSiteKind::Refusal, AuthorizationSiteKind::Refusal],
        ],
        LoginCsrfRequestListener::class => [
            'reason' => 'Garde du login-CSRF, priorité 20, avant le pare-feu : '
                .'son 403 refuse une requête sans `X-Requested-With`, il n\'autorise aucune route.',
            'sites' => [AuthorizationSiteKind::Refusal],
        ],
    ];

    public function testNoAuthorizationIsDeclaredOutsideAccessControl(): void
    {
        $found = [];
        foreach (PhpSources::files(RepositoryFiles::backend('src')) as $path) {
            // Les natures que l'exemption du fichier couvre, une par site : chaque site trouvé en consomme une.
            $remaining = $this->exemptedKindsOf($path);
            foreach (AuthorizationSites::in(RepositoryFiles::read($path)) as $site) {
                $covered = array_search($site->kind, $remaining, true);
                if (false === $covered) {
                    // Plus aucun site de cette nature dans l'exemption du fichier : c'est un site de trop.
                    $found[] = $path.':'.$site->line.' ('.$site->kind->name.')';
                    continue;
                }
                unset($remaining[$covered]);
            }
        }

        self::assertSame(
            [],
            $found,
            'Une autorisation de /api est une règle d\'access_control de security.yaml, '
            .'jamais un #[IsGranted], un denyAccessUnlessGranted, un `security:` d\'API Platform, un isGranted(), '
            .'un refus levé à la main ou un AccessDecisionManagerInterface : '
            .'PublicRouteIgnoresRefusedSessionListener tient « route publique = route sans règle ». Voir le docblock de ce test.',
        );
    }

    /**
     * @param list<AuthorizationSite> $expected chaque site attendu, dans l'ordre du code
     */
    #[DataProvider('formsTheDetectorSees')]
    public function testTheDetectorSeesEachForm(string $code, array $expected): void
    {
        self::assertEquals($expected, AuthorizationSites::in($code));
    }

    /**
     * @return iterable<string, array{string, list<AuthorizationSite>}>
     */
    public static function formsTheDetectorSees(): iterable
    {
        yield 'attribut, nom court' => [
            "<?php\n#[IsGranted('X')]\nfinal class A {}\n",
            [new AuthorizationSite(2, AuthorizationSiteKind::IsGrantedAttribute)],
        ];
        yield 'attribut, nom complet' => [
            "<?php\n#[\\Symfony\\Component\\Security\\Http\\Attribute\\IsGranted('X')]\nfinal class A {}\n",
            [new AuthorizationSite(2, AuthorizationSiteKind::IsGrantedAttribute)],
        ];
        yield 'import de l\'attribut' => [
            "<?php\nuse Symfony\\Component\\Security\\Http\\Attribute\\IsGranted;\n",
            [new AuthorizationSite(2, AuthorizationSiteKind::IsGrantedAttribute)],
        ];
        yield 'denyAccessUnlessGranted' => [
            "<?php\n\$this->denyAccessUnlessGranted('X');\n",
            [new AuthorizationSite(2, AuthorizationSiteKind::DenyAccessUnlessGranted)],
        ];
        yield 'security: d\'API Platform' => [
            "<?php\n\$a = new Get(security: \"x\");\n",
            [new AuthorizationSite(2, AuthorizationSiteKind::SecurityArgument)],
        ];
        yield 'securityPostDenormalize:' => [
            "<?php\n\$a = new Put(securityPostDenormalize: \"x\");\n",
            [new AuthorizationSite(2, AuthorizationSiteKind::SecurityArgument)],
        ];
        yield 'securityPostValidation: après un autre argument' => [
            "<?php\n\$a = new Put(uriTemplate: '/x', securityPostValidation: \"x\");\n",
            [new AuthorizationSite(2, AuthorizationSiteKind::SecurityArgument)],
        ];
        yield 'appel ->isGranted(' => [
            "<?php\n\$security->isGranted('X');\n",
            [new AuthorizationSite(2, AuthorizationSiteKind::IsGrantedCall)],
        ];
        yield 'appel ?->isGranted(' => [
            "<?php\n\$security?->isGranted('X');\n",
            [new AuthorizationSite(2, AuthorizationSiteKind::IsGrantedCall)],
        ];
        yield 'appel statique ::isGranted(' => [
            "<?php\nSecurity::isGranted('X');\n",
            [new AuthorizationSite(2, AuthorizationSiteKind::IsGrantedCall)],
        ];
        yield 'appel statique, nom complet' => [
            "<?php\n\\Symfony\\Bundle\\SecurityBundle\\Security::isGranted('X');\n",
            [new AuthorizationSite(2, AuthorizationSiteKind::IsGrantedCall)],
        ];
        yield 'refus levé, AccessDeniedHttpException' => [
            "<?php\nthrow new AccessDeniedHttpException();\n",
            [new AuthorizationSite(2, AuthorizationSiteKind::Refusal)],
        ];
        yield 'refus levé, AccessDeniedException de nom complet' => [
            "<?php\nthrow new \\Symfony\\Component\\Security\\Core\\Exception\\AccessDeniedException();\n",
            [new AuthorizationSite(2, AuthorizationSiteKind::Refusal)],
        ];
        yield 'refus levé, UnauthorizedHttpException' => [
            "<?php\nthrow new UnauthorizedHttpException('x');\n",
            [new AuthorizationSite(2, AuthorizationSiteKind::Refusal)],
        ];
        yield 'gestionnaire de décision, propriété' => [
            "<?php\nfinal class A { private AccessDecisionManagerInterface \$m; }\n",
            [new AuthorizationSite(2, AuthorizationSiteKind::DecisionManager)],
        ];
        yield 'gestionnaire de décision, import' => [
            "<?php\nuse Symfony\\Component\\Security\\Core\\Authorization\\AccessDecisionManagerInterface;\n",
            [new AuthorizationSite(2, AuthorizationSiteKind::DecisionManager)],
        ];
        yield 'chaîne is_granted, guillemets simples' => [
            "<?php\n\$a = 'is_granted(\"ROLE_X\")';\n",
            [new AuthorizationSite(2, AuthorizationSiteKind::IsGrantedExpression)],
        ];
        yield 'chaîne is_granted, guillemets doubles' => [
            "<?php\n\$a = \"is_granted('ROLE_X')\";\n",
            [new AuthorizationSite(2, AuthorizationSiteKind::IsGrantedExpression)],
        ];
        yield 'chaîne is_granted, interpolée' => [
            "<?php\n\$a = \"is_granted('ROLE_X') and \$b\";\n",
            [new AuthorizationSite(2, AuthorizationSiteKind::IsGrantedExpression)],
        ];
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
        foreach (self::EXEMPTIONS as $class => ['reason' => $reason, 'sites' => $sites]) {
            $expectedKinds = $this->countByKind($sites);
            $found = $this->countByKind(array_map(
                static fn (AuthorizationSite $site): AuthorizationSiteKind => $site->kind,
                AuthorizationSites::in(RepositoryFiles::read($this->pathOf($class))),
            ));

            self::assertSame(
                $expectedKinds,
                $found,
                \sprintf(
                    '%s est exemptée (%s) pour des sites qui ne sont plus ceux d\'EXEMPTIONS : attendu %s, trouvé %s. '
                    .'Ajuster la liste des sites ou retirer l\'entrée.',
                    $class,
                    $reason,
                    json_encode($expectedKinds),
                    json_encode($found),
                ),
            );
        }
    }

    /**
     * @return list<AuthorizationSiteKind> un cas par site que l'exemption du fichier couvre (vide s'il n'est pas exempté)
     */
    private function exemptedKindsOf(string $path): array
    {
        foreach (self::EXEMPTIONS as $class => ['sites' => $sites]) {
            if ($this->pathOf($class) === $path) {
                return $sites;
            }
        }

        return [];
    }

    /**
     * @param list<AuthorizationSiteKind> $kinds
     *
     * @return array<string, int> nom du cas => nombre de sites, trié par nom
     */
    private function countByKind(array $kinds): array
    {
        $counts = [];
        foreach ($kinds as $kind) {
            $counts[$kind->name] = ($counts[$kind->name] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @param class-string $class
     */
    private function pathOf(string $class): string
    {
        return RepositoryFiles::backend('src/'.str_replace('\\', '/', substr($class, \strlen('App\\'))).'.php');
    }
}
