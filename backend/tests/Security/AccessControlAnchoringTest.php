<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
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
     * Terminaisons acceptées pour un pattern de firewall : fin de chaîne,
     * séparateur ou fin, séparateur seul. Toutes bornent le pattern sur la
     * droite, c'est-à-dire empêchent `^/api/login` de capturer `/api/loginfoo`.
     *
     * Le simple `/` final est celui du firewall `dev`
     * (`^/(_profiler|_wdt|assets|build)/`) : il borne bel et bien — `/_profilerx`
     * n'est pas capturé — au prix de ne pas couvrir le chemin nu `/_profiler`,
     * ce qui est sans conséquence pour un firewall `security: false`.
     *
     * @var list<string>
     */
    private const array ACCEPTED_PATTERN_ENDINGS = ['$', '(/|$)', '/'];

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

        // --- Un voisin par préfixe n'hérite d'aucune règle : sa protection
        // doit être écrite, jamais déduite d'une regex trop large.
        yield '/api/backoffice-preview → aucune règle' => ['/api/backoffice-preview', null];
        yield '/api/me-too → aucune règle' => ['/api/me-too', null];
        yield '/api/cv-export → aucune règle' => ['/api/cv-export', null];
        yield '/api/cvs → aucune règle' => ['/api/cvs', null];
        yield '/api/case-studies-drafts → aucune règle' => ['/api/case-studies-drafts', null];
        yield '/api/anonymous-cv-full → aucune règle' => ['/api/anonymous-cv-full', null];
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
                ? sprintf('%s est capturé par une règle access_control alors qu\'il ne fait que prolonger un préfixe : la regex manque une ancre de fin.', $path)
                : sprintf('%s devrait relever de la règle %s.', $path, implode(',', $expectedRoles)),
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
                sprintf('Le firewall "%s" n\'a aucun pattern de chemin : il capture tout. S\'il s\'agit d\'un attrape-tout voulu, inscrivez-le dans UNBOUNDED_FIREWALL_ALLOW_LIST avec sa justification.', $firewall),
            );

            foreach ($regexps as $regexp) {
                self::assertStringStartsWith(
                    '^',
                    $regexp,
                    sprintf('Le pattern "%s" du firewall "%s" n\'est pas ancré au début : il capturerait n\'importe quel chemin le contenant.', $regexp, $firewall),
                );

                self::assertTrue(
                    $this->isBounded($regexp),
                    sprintf(
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

        // --- Le reste de l'API.
        yield '/api/me → api' => ['/api/me', 'api'];
        yield '/api/backoffice/users → api' => ['/api/backoffice/users', 'api'];

        // --- Hors API : aucun firewall (aucune route n'y existe non plus).
        yield '/ → aucun firewall' => ['/', null];
        yield '/healthz → aucun firewall' => ['/healthz', null];
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
            sprintf('%s ne relève pas du firewall attendu.', $path),
        );
    }

    private function isBounded(string $regexp): bool
    {
        return array_any(self::ACCEPTED_PATTERN_ENDINGS, fn(string $ending): bool => str_ends_with($regexp, $ending));
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

        $contexts = (new \ReflectionProperty(FirewallMap::class, 'map'))->getValue($map);
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

        self::assertInstanceOf(ChainRequestMatcher::class, $requestMatcher, sprintf('Firewall "%s" : matcher inattendu, adapter ce test.', $firewall));

        $matchers = (new \ReflectionProperty(ChainRequestMatcher::class, 'matchers'))->getValue($requestMatcher);
        self::assertIsIterable($matchers, 'ChainRequestMatcher::$matchers n\'est plus itérable : structure interne changée, adapter ce test.');

        $regexps = [];

        foreach ($matchers as $matcher) {
            // Un firewall peut aussi être borné par hôte ou par méthode ; seuls
            // les matchers de chemin nous concernent ici.
            if (!$matcher instanceof PathRequestMatcher) {
                continue;
            }

            $regexp = (new \ReflectionProperty(PathRequestMatcher::class, 'regexp'))->getValue($matcher);
            self::assertIsString($regexp, 'PathRequestMatcher::$regexp n\'est plus une chaîne : structure interne changée, adapter ce test.');

            $regexps[] = $regexp;
        }

        return $regexps;
    }
}
