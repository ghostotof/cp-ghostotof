<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\PathRequestMatcher;

/**
 * Les règles `access_control` de security.yaml sont des expressions régulières,
 * et Symfony n'applique que la **première** qui correspond. Sans ancre de fin,
 * `^/api/cv` capture aussi `/api/cv-export`, et `^/api/case-studies` capturerait
 * un futur `/api/case-studies-drafts` avec le rôle de la règle voisine, quel que
 * soit le palier que cette nouvelle route devait exiger (issue #78, pt 2).
 *
 * Ce test lit la carte d'accès telle qu'elle est compilée (pas le YAML) et
 * vérifie, pour chaque règle : qu'elle couvre bien sa route, son sous-arbre
 * (`/…/`), et **rien de plus** — un chemin qui prolonge le préfixe sans
 * séparateur ne doit être capturé par aucune règle. La protection d'une telle
 * route n'est alors jamais implicite : soit elle reçoit sa propre règle, soit
 * ApiRouteExposureTest la signale comme servie à qui n'y a pas droit.
 *
 * **Étendu aux patterns de firewalls** (audit 2026-09-16, constat A23). Le
 * `pattern` d'un firewall est la même sorte de regex, avec le même piège en
 * plus grave : il ne décide pas d'un rôle mais de *quel jeu d'authentificateurs*
 * s'applique. `^/api/login` (sans borne) plaçait tout chemin commençant par
 * `/api/login` dans le firewall du login — un firewall qui ne lit **aucun**
 * JWT et n'a qu'un `json_login` cloué sur `check_path`. Une route ajoutée
 * demain sous ce préfixe y serait donc traitée en anonyme, sans que le cookie
 * BEARER du visiteur soit seulement regardé. Aucune route de ce genre n'existe
 * aujourd'hui (le routeur, priorité 32, refuse avant le firewall, priorité 8),
 * c'est donc une correction de défense en profondeur — et cette classe est ce
 * qui empêche la borne de disparaître à nouveau.
 */
final class AccessControlAnchoringTest extends KernelTestCase
{
    /**
     * Les services de contexte de firewall sont nommés d'après le firewall.
     */
    private const string FIREWALL_CONTEXT_ID_PREFIX = 'security.firewall.map.context.';

    /**
     * Terminaisons acceptées pour un pattern de firewall : fin de chaîne, ou
     * séparateur ou fin. Toutes deux bornent le pattern sur la droite,
     * c'est-à-dire empêchent `^/api/login` de capturer `/api/loginfoo`.
     *
     * Le firewall `dev` du squelette (`^/(_profiler|_wdt|assets|build)/`,
     * `security: false`) était le seul à finir par un simple `/`, toléré pour
     * lui. Il est supprimé depuis l'arbitrage du 2026-09-22 (issue #239) — voir
     * testTheSkeletonDevToolingFirewallIsGone — et la tolérance avec lui.
     *
     * @var list<string>
     */
    private const array ACCEPTED_PATTERN_ENDINGS = ['$', '(/|$)'];

    /**
     * Nom du firewall du squelette Symfony réservé à l'outillage de dev.
     */
    private const string DEV_TOOLING_FIREWALL = 'dev';

    /**
     * Firewalls dispensés de la borne de fin, avec leur justification.
     *
     * `api` est l'attrape-tout de l'API, et son pattern nu est **délibéré** :
     * resserrer `^/api` en `^/api(/|$)` ferait sortir `/apix…` de *tout*
     * firewall, donc aussi de l'`access_control` (l'AccessListener est un
     * listener de firewall). Un chemin hors firewall n'est plus authentifié du
     * tout : le resserrement retirerait de la protection au lieu d'en ajouter,
     * à rebours de la règle « protégé par défaut ». Le pattern large est ici le
     * choix sûr, et le filet qui compte reste ApiRouteExposureTest, qui énumère
     * le routeur route par route.
     *
     * @var array<string, string> nom du firewall => raison
     */
    private const array UNBOUNDED_FIREWALL_ALLOW_LIST = [
        'api' => 'Attrape-tout de l\'API : un pattern plus étroit sortirait les chemins voisins de tout firewall, donc de tout access_control.',
    ];

    /**
     * @return iterable<string, array{0: string, 1: list<string>|null}>
     */
    public static function provideRequestPathsAndExpectedRoles(): iterable
    {
        // --- Chaque règle couvre sa route exacte et son sous-arbre.
        yield '/api/backoffice → ROLE_SUPER' => ['/api/backoffice', ['ROLE_SUPER']];
        yield '/api/backoffice/users/1 → ROLE_SUPER' => ['/api/backoffice/users/1', ['ROLE_SUPER']];
        yield '/api/me → ROLE_TRUSTED' => ['/api/me', ['ROLE_TRUSTED']];
        yield '/api/cv → ROLE_TRUSTED' => ['/api/cv', ['ROLE_TRUSTED']];
        yield '/api/cv/ → ROLE_TRUSTED' => ['/api/cv/', ['ROLE_TRUSTED']];
        yield '/api/case-studies/fr → ROLE_USER' => ['/api/case-studies/fr', ['ROLE_USER']];
        yield '/api/anonymous-cv/en → ROLE_USER' => ['/api/anonymous-cv/en', ['ROLE_USER']];
        yield '/api/assistant → ROLE_TRUSTED' => ['/api/assistant', ['ROLE_TRUSTED']];
        yield '/api/assistant/answers → ROLE_TRUSTED' => ['/api/assistant/answers', ['ROLE_TRUSTED']];
        // Consentement à une connexion MCP (#647) : il accorde la lecture du
        // corpus nominatif, donc le palier de confiance, comme /api/cv.
        yield '/api/oauth/consent → ROLE_TRUSTED' => ['/api/oauth/consent', ['ROLE_TRUSTED']];
        yield '/api/oauth/consent/ → ROLE_TRUSTED' => ['/api/oauth/consent/', ['ROLE_TRUSTED']];
        // Décidé sur le chemin décodé, comme le firewall et le routeur (#77).
        yield '/api/oauth/c%6Fnsent → ROLE_TRUSTED' => ['/api/oauth/c%6Fnsent', ['ROLE_TRUSTED']];

        // --- Un voisin par préfixe n'hérite d'aucune règle : sa protection
        // doit être écrite, jamais déduite d'une regex trop large.
        yield '/api/backoffice-preview → aucune règle' => ['/api/backoffice-preview', null];
        yield '/api/me-too → aucune règle' => ['/api/me-too', null];
        yield '/api/cv-export → aucune règle' => ['/api/cv-export', null];
        yield '/api/cvs → aucune règle' => ['/api/cvs', null];
        yield '/api/case-studies-drafts → aucune règle' => ['/api/case-studies-drafts', null];
        yield '/api/anonymous-cv-full → aucune règle' => ['/api/anonymous-cv-full', null];
        yield '/api/assistants → aucune règle' => ['/api/assistants', null];
        yield '/api/oauth/consents → aucune règle' => ['/api/oauth/consents', null];
        // Les voisins du consentement sous /api/oauth n'en héritent pas : les
        // trois points du serveur d'autorisation restent sans règle (leur
        // firewall `oauth` n'a aucun authentificateur), /api/oauth seul aussi.
        yield '/api/oauth/authorize → aucune règle' => ['/api/oauth/authorize', null];
        yield '/api/oauth → aucune règle' => ['/api/oauth', null];

        // --- Les deux documents de métadonnées OAuth (#647), hors /api :
        // publics, chacun sur son chemin exact. Ni un voisin par préfixe, ni
        // le chemin bien connu sans suffixe, ni un `.` pris pour n'importe
        // quel caractère n'en héritent.
        yield '/.well-known/oauth-protected-resource/api/mcp → PUBLIC_ACCESS' => ['/.well-known/oauth-protected-resource/api/mcp', ['PUBLIC_ACCESS']];
        yield '/.well-known/oauth-authorization-server/api/oauth → PUBLIC_ACCESS' => ['/.well-known/oauth-authorization-server/api/oauth', ['PUBLIC_ACCESS']];
        // L'access_control décide sur le chemin décodé, comme le firewall.
        yield '/.well-known/oauth-protected-resource/api/m%63p → PUBLIC_ACCESS' => ['/.well-known/oauth-protected-resource/api/m%63p', ['PUBLIC_ACCESS']];
        yield '/.well-known/oauth-authorization-server/api/o%61uth → PUBLIC_ACCESS' => ['/.well-known/oauth-authorization-server/api/o%61uth', ['PUBLIC_ACCESS']];
        yield '/.well-known/oauth-protected-resource → aucune règle' => ['/.well-known/oauth-protected-resource', null];
        yield '/.well-known/oauth-protected-resource/api/mcpx → aucune règle' => ['/.well-known/oauth-protected-resource/api/mcpx', null];
        yield '/.well-known/oauth-protected-resource/api/mcp/extra → aucune règle' => ['/.well-known/oauth-protected-resource/api/mcp/extra', null];
        yield '/.well-known/oauth-authorization-server → aucune règle' => ['/.well-known/oauth-authorization-server', null];
        yield '/.well-known/oauth-authorization-server/api/oauthx → aucune règle' => ['/.well-known/oauth-authorization-server/api/oauthx', null];
        yield '/.well-known/oauth-authorization-server/api/oauth/extra → aucune règle' => ['/.well-known/oauth-authorization-server/api/oauth/extra', null];
        yield '/xwell-known/oauth-protected-resource/api/mcp → aucune règle' => ['/xwell-known/oauth-protected-resource/api/mcp', null];
        yield '/xwell-known/oauth-authorization-server/api/oauth → aucune règle' => ['/xwell-known/oauth-authorization-server/api/oauth', null];
        yield '/.well-known/openid-configuration → aucune règle' => ['/.well-known/openid-configuration', null];
    }

    /**
     * @param list<string>|null $expectedRoles null : aucune règle ne doit correspondre
     */
    #[DataProvider('provideRequestPathsAndExpectedRoles')]
    public function testEachAccessControlRuleMatchesItsSubtreeAndNothingElse(string $path, ?array $expectedRoles): void
    {
        self::bootKernel();
        // Service privé, mais toujours présent dans le conteneur compilé (injecté
        // dans l'AccessListener) : le conteneur de test l'expose tel quel.
        [$attributes] = self::getContainer()->get('security.access_map')->getPatterns(Request::create($path));

        self::assertSame(
            $expectedRoles,
            $attributes,
            null === $expectedRoles
                ? \sprintf('%s est capturé par une règle access_control alors qu\'il ne fait que prolonger un préfixe : la regex manque une ancre de fin.', $path)
                : \sprintf('%s devrait relever de la règle %s.', $path, implode(',', $expectedRoles)),
        );
    }

    /**
     * Invariant générique : tout pattern de firewall est ancré à gauche (`^`)
     * **et** borné à droite, sauf allow-list justifiée.
     */
    public function testEveryFirewallPatternIsAnchoredAndBounded(): void
    {
        self::bootKernel();
        $patterns = $this->firewallPathPatterns();

        self::assertNotSame([], $patterns, 'Aucun firewall lu dans le conteneur compilé : le test ne prouve plus rien.');

        foreach ($patterns as $firewall => $regexps) {
            if (\array_key_exists($firewall, self::UNBOUNDED_FIREWALL_ALLOW_LIST)) {
                continue;
            }

            self::assertNotSame(
                [],
                $regexps,
                \sprintf('Le firewall "%s" n\'a aucun pattern de chemin : il capture tout. S\'il s\'agit d\'un attrape-tout voulu, inscrivez-le dans UNBOUNDED_FIREWALL_ALLOW_LIST avec sa justification.', $firewall),
            );

            foreach ($regexps as $regexp) {
                self::assertStringStartsWith(
                    '^',
                    $regexp,
                    \sprintf('Le pattern "%s" du firewall "%s" n\'est pas ancré au début : il capturerait n\'importe quel chemin le contenant.', $regexp, $firewall),
                );

                self::assertTrue(
                    $this->isBounded($regexp),
                    \sprintf(
                        'Le pattern "%s" du firewall "%s" n\'est borné par aucune de ces terminaisons : %s. Sans borne, il capture ses voisins par préfixe (« %sx ») et leur applique des authentificateurs qui ne les concernent pas.',
                        $regexp,
                        $firewall,
                        implode(', ', self::ACCEPTED_PATTERN_ENDINGS),
                        $regexp,
                    ),
                );
            }
        }
    }

    /**
     * Le firewall `dev` du squelette (`security: false` sur
     * `^/(_profiler|_wdt|assets|build)/`) existait dans **tous** les
     * environnements, prod comprise, alors que rien de ce qu'il nomme n'est
     * installé ici (ni WebProfilerBundle, ni AssetMapper) : aucune de ces
     * routes n'existe, dans aucun environnement. Le réserver à `dev` par un
     * bloc `when@dev` est impossible — SecurityBundle refuse qu'un firewall
     * soit ajouté depuis un second bloc de config (« define all elements for
     * this path in one config file »). Il est donc **supprimé** (arbitrage du
     * 2026-09-22, issue #239), et ce test l'empêche de revenir.
     *
     * Ce que cette suppression apporte, et ce qu'elle n'apporte **pas** : un
     * chemin `security: false` et un chemin hors de tout firewall sont servis
     * pareil — sans authentificateur ni access_control (l'AccessListener est
     * un listener de firewall). La config cesse simplement de déclarer un
     * firewall pour un outillage qu'elle n'a pas, et cette classe cesse de
     * tolérer la terminaison `/` que lui seul justifiait. Si WebProfilerBundle
     * était installé un jour, ses routes vivraient hors firewall, ce qui est
     * exactement ce que `security: false` leur donnait ; et une future route
     * hors `/api` se protège avec son propre firewall, jamais implicitement.
     */
    public function testTheSkeletonDevToolingFirewallIsGone(): void
    {
        self::bootKernel();

        self::assertArrayNotHasKey(
            self::DEV_TOOLING_FIREWALL,
            $this->firewallPathPatterns(),
            'Le firewall "dev" du squelette (outillage de développement absent du projet, security: false) est de retour dans security.yaml : il a été supprimé à dessein, voir la docblock de ce test.',
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function provideRequestPathsAndExpectedFirewall(): iterable
    {
        // --- Le login, et rien d'autre.
        yield '/api/login_check → login' => ['/api/login_check', 'login'];
        // Le firewall décide sur le chemin décodé, comme le routeur (issue #77) :
        // la borne `$` ne doit pas se laisser contourner par un `%5F`.
        yield '/api/login%5Fcheck → login' => ['/api/login%5Fcheck', 'login'];

        // --- Voisins par préfixe : ils relèvent du firewall JWT, pas du login.
        yield '/api/login_checkx → api' => ['/api/login_checkx', 'api'];
        yield '/api/loginfoo → api' => ['/api/loginfoo', 'api'];
        yield '/api/login → api' => ['/api/login', 'api'];
        yield '/api/login_check/extra → api' => ['/api/login_check/extra', 'api'];

        // --- Le serveur MCP (ADR 0006 D4) : son firewall ne lit aucun cookie,
        // il ne doit couvrir que sa route ; un voisin relève du firewall JWT.
        yield '/api/mcp → mcp' => ['/api/mcp', 'mcp'];
        yield '/api/m%63p → mcp' => ['/api/m%63p', 'mcp'];
        yield '/api/mcpx → api' => ['/api/mcpx', 'api'];
        yield '/api/mcp/extra → api' => ['/api/mcp/extra', 'api'];

        // --- Les points du serveur d'autorisation OAuth (#647) : leur firewall
        // ne lit aucun cookie, il ne couvre que ses trois chemins exacts. Le
        // consentement, qui lit la personne, et tout voisin relèvent du
        // firewall JWT.
        yield '/api/oauth/authorize → oauth' => ['/api/oauth/authorize', 'oauth'];
        yield '/api/oauth/token → oauth' => ['/api/oauth/token', 'oauth'];
        yield '/api/oauth/revoke → oauth' => ['/api/oauth/revoke', 'oauth'];
        yield '/api/oauth/%61uthorize → oauth' => ['/api/oauth/%61uthorize', 'oauth'];
        yield '/api/oauth/authorizex → api' => ['/api/oauth/authorizex', 'api'];
        yield '/api/oauth/authorize/x → api' => ['/api/oauth/authorize/x', 'api'];
        yield '/api/oauth/consent → api' => ['/api/oauth/consent', 'api'];
        yield '/api/oauth → api' => ['/api/oauth', 'api'];
        yield '/api/oauthx/authorize → api' => ['/api/oauthx/authorize', 'api'];

        // --- Le reste de l'API.
        yield '/api/me → api' => ['/api/me', 'api'];
        yield '/api/backoffice/users → api' => ['/api/backoffice/users', 'api'];

        // --- Les deux documents de métadonnées OAuth (#647) : chacun son
        // firewall, sans authentificateur, borné sur son chemin exact. Le
        // chemin décodé décide, comme pour /api/mcp.
        yield '/.well-known/oauth-protected-resource/api/mcp → oauth_resource_metadata' => ['/.well-known/oauth-protected-resource/api/mcp', 'oauth_resource_metadata'];
        yield '/.well-known/oauth-protected-resource/api/m%63p → oauth_resource_metadata' => ['/.well-known/oauth-protected-resource/api/m%63p', 'oauth_resource_metadata'];
        yield '/.well-known/oauth-authorization-server/api/oauth → oauth_server_metadata' => ['/.well-known/oauth-authorization-server/api/oauth', 'oauth_server_metadata'];
        yield '/.well-known/oauth-authorization-server/api/o%61uth → oauth_server_metadata' => ['/.well-known/oauth-authorization-server/api/o%61uth', 'oauth_server_metadata'];

        // --- Hors API : aucun firewall (aucune route n'y existe non plus).
        yield '/ → aucun firewall' => ['/', null];
        yield '/healthz → aucun firewall' => ['/healthz', null];
        // Les voisins des deux documents : ni le chemin bien connu sans
        // suffixe, ni un prolongement, ni un `.` lu comme n'importe quel
        // caractère, ni le document OIDC qu'aucun firewall ne doit couvrir.
        yield '/.well-known/oauth-authorization-server → aucun firewall' => ['/.well-known/oauth-authorization-server', null];
        yield '/.well-known/oauth-protected-resource → aucun firewall' => ['/.well-known/oauth-protected-resource', null];
        yield '/.well-known/oauth-protected-resource/api/mcpx → aucun firewall' => ['/.well-known/oauth-protected-resource/api/mcpx', null];
        yield '/.well-known/oauth-protected-resource/api/mcp/extra → aucun firewall' => ['/.well-known/oauth-protected-resource/api/mcp/extra', null];
        yield '/.well-known/oauth-authorization-server/api/oauthx → aucun firewall' => ['/.well-known/oauth-authorization-server/api/oauthx', null];
        yield '/.well-known/oauth-authorization-server/api/oauth/extra → aucun firewall' => ['/.well-known/oauth-authorization-server/api/oauth/extra', null];
        yield '/xwell-known/oauth-protected-resource/api/mcp → aucun firewall' => ['/xwell-known/oauth-protected-resource/api/mcp', null];
        yield '/xwell-known/oauth-authorization-server/api/oauth → aucun firewall' => ['/xwell-known/oauth-authorization-server/api/oauth', null];
        yield '/.well-known/openid-configuration → aucun firewall' => ['/.well-known/openid-configuration', null];
    }

    #[DataProvider('provideRequestPathsAndExpectedFirewall')]
    public function testEachPathFallsInTheExpectedFirewall(string $path, ?string $expectedFirewall): void
    {
        self::bootKernel();
        // Service privé, mais présent dans le conteneur compilé (injecté dans
        // le listener Firewall) : le conteneur de test l'expose tel quel.
        $map = self::getContainer()->get('security.firewall.map');

        self::assertSame(
            $expectedFirewall,
            $map->getFirewallConfig(Request::create($path))?->getName(),
            \sprintf('%s ne relève pas du firewall attendu.', $path),
        );
    }

    private function isBounded(string $regexp): bool
    {
        return array_any(self::ACCEPTED_PATTERN_ENDINGS, fn (string $ending): bool => str_ends_with($regexp, $ending));
    }

    /**
     * Lit les patterns de chemin dans le conteneur compilé — pas dans le YAML,
     * pour la même raison que la carte d'accès plus haut : c'est ce que Symfony
     * exécute qui fait foi. La `FirewallMap` de SecurityBundle garde la liste
     * `identifiant de contexte => RequestMatcher` dans une propriété privée ;
     * elle n'a pas d'accesseur, d'où la réflexion. Chaque étape est assertée :
     * si une version de Symfony change cette structure, le test échoue en le
     * disant, il ne devient pas silencieusement vide.
     *
     * @return array<string, list<string>> nom du firewall => patterns de chemin
     */
    private function firewallPathPatterns(): array
    {
        $map = self::getContainer()->get('security.firewall.map');

        $contexts = (new ReflectionProperty(FirewallMap::class, 'map'))->getValue($map);
        self::assertIsIterable($contexts, 'FirewallMap::$map n\'est plus itérable : structure interne changée, adapter ce test.');

        $patterns = [];

        foreach ($contexts as $contextId => $requestMatcher) {
            self::assertIsString($contextId);
            self::assertStringStartsWith(self::FIREWALL_CONTEXT_ID_PREFIX, $contextId);
            $firewall = substr($contextId, \strlen(self::FIREWALL_CONTEXT_ID_PREFIX));

            $patterns[$firewall] = $this->pathRegexpsOf($requestMatcher, $firewall);
        }

        return $patterns;
    }

    /**
     * @return list<string>
     */
    private function pathRegexpsOf(mixed $requestMatcher, string $firewall): array
    {
        // Un firewall sans `pattern` n'a aucun matcher : il capture tout.
        if (null === $requestMatcher) {
            return [];
        }

        self::assertInstanceOf(ChainRequestMatcher::class, $requestMatcher, \sprintf('Firewall "%s" : matcher inattendu, adapter ce test.', $firewall));

        $matchers = (new ReflectionProperty(ChainRequestMatcher::class, 'matchers'))->getValue($requestMatcher);
        self::assertIsIterable($matchers, 'ChainRequestMatcher::$matchers n\'est plus itérable : structure interne changée, adapter ce test.');

        $regexps = [];

        foreach ($matchers as $matcher) {
            // Un firewall peut aussi être borné par hôte ou par méthode ; seuls
            // les matchers de chemin nous concernent ici.
            if (!$matcher instanceof PathRequestMatcher) {
                continue;
            }

            $regexp = (new ReflectionProperty(PathRequestMatcher::class, 'regexp'))->getValue($matcher);
            self::assertIsString($regexp, 'PathRequestMatcher::$regexp n\'est plus une chaîne : structure interne changée, adapter ce test.');

            $regexps[] = $regexp;
        }

        return $regexps;
    }
}
