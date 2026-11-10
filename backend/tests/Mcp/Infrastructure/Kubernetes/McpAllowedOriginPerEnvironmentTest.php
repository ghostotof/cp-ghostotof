<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Kubernetes;

use App\Tests\Support\EnvironmentConfigKernel;
use App\Tests\Support\KubernetesManifests;
use App\Tests\Support\RepositoryFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;
use Symfony\Component\Dotenv\Dotenv;

/**
 * L'`Origin` admis sur `/api/mcp` est l'origine du site, dans chaque
 * environnement (R4 de #731, décision de l'auteur du 2026-11-10).
 *
 * `allowed_origins` de mcp.yaml lit `APP_FRONTEND_BASE_URL`, la variable dont
 * `allowed_hosts` tire déjà l'hôte : une seule source, donc aucun écart
 * possible entre l'URL du site et l'origine admise. La contrepartie : la
 * valeur de cette variable gouverne un contrôle de sécurité, et doit rester
 * une **origine** — schéma, hôte, port éventuel, rien d'autre.
 *
 * Ce que le SDK fait de la valeur configurée, lu dans sa version épinglée
 * (composer.json), {@see \Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware} :
 * il la met en minuscules, lui retire sa barre finale et son port par défaut
 * (`normalizeOrigin()`), puis lui compare l'`Origin` reçu **par égalité
 * stricte**, mis en minuscules seulement. D'où ce que le garde refuse :
 *
 * - une valeur vide : elle admettrait un `Origin` vide (`'' === ''`) ;
 * - `*` : le SDK admet alors tout `Origin` ;
 * - un chemin ou une requête (`https://site/fr`) : aucun navigateur n'envoie
 *   un tel `Origin`, la page du site serait refusée en 403 ;
 * - `http` en préprod et en prod : la page du site y est servie en `https`.
 *
 * - le port par défaut du schéma écrit en clair (`https://site:443`,
 *   `http://site:80`) : le SDK le retire, mais lui seul.
 *   {@see \App\Mcp\Connection\Application\OAuthEndpoints} ne retire que la
 *   barre finale, et l'émetteur OAuth comme la ressource MCP, qui retombent
 *   sur cette variable en préprod et en prod, porteraient ce port — or un
 *   client compare la ressource à l'URL qu'il a appelée, où il n'est pas
 *   écrit : la connexion OAuth échouerait (côté fermé, mais cassée).
 *
 * Et ce qu'il admet : **une barre finale** (`https://site/`), que le SDK
 * retire lui-même, comme chaque autre lecteur de la variable (`rtrim` dans
 * les liens composés, {@see \App\Mcp\Connection\Application\OAuthEndpoints}
 * compris) — vrai de la barre finale seulement, d'où le refus ci-dessus.
 *
 * Limites, acceptées : le garde lit les valeurs écrites dans le dépôt —
 * `backend/.env` pour dev et test, le littéral du `backend-config` de chaque
 * overlay pour la préprod et la prod. Un `.env.local` de poste ou une
 * variable exportée dans un conteneur lui échappent.
 */
final class McpAllowedOriginPerEnvironmentTest extends TestCase
{
    private const string VARIABLE = 'APP_FRONTEND_BASE_URL';

    /**
     * Schéma, hôte en minuscules, port éventuel, barre finale éventuelle :
     * ni `*`, ni identifiants, ni chemin, ni requête, ni fragment. Un port
     * ne commence pas par zéro : `:0443` serait le port par défaut sous une
     * forme que ni le SDK ni {@see self::EXPLICIT_DEFAULT_PORT} ne lisent.
     */
    private const string ORIGIN = '~\A(https?)://[a-z0-9.-]+(:[1-9][0-9]{0,4})?/?\z~';

    /** Le port par défaut du schéma, écrit en clair : `:443` en https, `:80` en http. */
    private const string EXPLICIT_DEFAULT_PORT = '~\A(?:https://.+:443|http://.+:80)/?\z~';

    /** Les environnements déployés derrière l'Ingress, servis en `https` seulement. */
    private const array SERVED_OVER_HTTPS = ['preprod', 'prod'];

    private const string WHY = 'Cette valeur gouverne `allowed_origins` et `allowed_hosts` de /api/mcp (mcp.yaml) : elle doit rester une origine — schéma, hôte, port éventuel —, sans chemin ni requête.';

    /**
     * Rien en dur, aucune autre entrée, et la même variable que
     * `allowed_hosts` — dans chaque environnement que le noyau admet : un
     * bloc `when@prod` qui ajouterait une origine ne serait vu d'aucun test
     * fonctionnel, qui ne lisent que `test`.
     */
    public function testMcpYamlDerivesTheOriginFromTheSiteUrl(): void
    {
        foreach (EnvironmentConfigKernel::allowedEnvironments() as $environment) {
            $portfolio = new EnvironmentConfigKernel($environment)->declaredMapping('mcp', 'servers')['portfolio'] ?? null;
            self::assertIsArray($portfolio, $environment.' : serveur `portfolio` absent de la configuration `mcp`.');
            $http = $portfolio['http'] ?? null;
            self::assertIsArray($http, $environment.' : `mcp.servers.portfolio.http` absent.');

            self::assertSame(
                ['%env('.self::VARIABLE.')%'],
                $http['allowed_origins'] ?? null,
                $environment.' : `allowed_origins` doit porter l\'origine du site et elle seule, lue dans '.self::VARIABLE.' — rien en dur, aucune autre entrée.',
            );
            self::assertSame(
                ['%env(key:host:url:'.self::VARIABLE.')%'],
                $http['allowed_hosts'] ?? null,
                $environment.' : `allowed_hosts` et `allowed_origins` doivent dériver de la même variable, '.self::VARIABLE.'.',
            );
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function environments(): iterable
    {
        yield 'dev' => ['dev'];
        yield 'test' => ['test'];
        yield 'preprod' => ['preprod'];
        yield 'prod' => ['prod'];
    }

    #[DataProvider('environments')]
    public function testEachEnvironmentSiteUrlIsAnOrigin(string $environment): void
    {
        $siteUrl = $this->siteUrlOf($environment);

        self::assertNotSame('', $siteUrl, \sprintf('%s : %s est vide. %s', $environment, self::VARIABLE, self::WHY));
        self::assertMatchesRegularExpression(
            self::ORIGIN,
            $siteUrl,
            \sprintf('%s : %s n\'est pas une origine. %s', $environment, self::VARIABLE, self::WHY),
        );
        self::assertDoesNotMatchRegularExpression(
            self::EXPLICIT_DEFAULT_PORT,
            $siteUrl,
            \sprintf(
                '%s : %s écrit le port par défaut de son schéma. Le SDK le retire, pas OAuthEndpoints : l\'émetteur OAuth et la ressource MCP le porteraient, alors qu\'un client compare la ressource à l\'URL qu\'il a appelée. %s',
                $environment,
                self::VARIABLE,
                self::WHY,
            ),
        );
        if (\in_array($environment, self::SERVED_OVER_HTTPS, true)) {
            self::assertStringStartsWith(
                'https://',
                $siteUrl,
                \sprintf('%s : %s doit être en https, le schéma de la page du site que le SDK compare à l\'`Origin`. %s', $environment, self::VARIABLE, self::WHY),
            );
        }
    }

    /**
     * La ligne `test` du fournisseur lit `backend/.env` : ce n'est la valeur
     * de la suite que si phpunit.dist.xml ne la redéfinit pas. Le jour où il
     * le fait, c'est là que le garde doit la lire.
     */
    public function testTheTestSuiteReadsTheSiteUrlFromDotEnv(): void
    {
        $redefinitions = new SimpleXMLElement(RepositoryFiles::read(RepositoryFiles::backend('phpunit.dist.xml')))
            ->xpath('/phpunit/php/*[(self::server or self::env) and @name="'.self::VARIABLE.'"]');

        self::assertSame(
            [],
            $redefinitions,
            'phpunit.dist.xml redéfinit '.self::VARIABLE.' : la valeur de la suite n\'est plus celle de backend/.env, que ce garde lit pour `test`.',
        );
    }

    /**
     * En préprod et en prod, la valeur lue est celle du pod seulement si rien
     * d'autre sous `k8s/` ne pose la variable : un `env:` de conteneur
     * l'emporte sur l'`envFrom` du `backend-config`.
     */
    public function testNoManifestSetsTheSiteUrlBesideTheOverlayLiterals(): void
    {
        $expected = [];
        foreach (self::SERVED_OVER_HTTPS as $overlay) {
            $expected[] = \sprintf('overlays/%s/kustomization.yaml: - %s=%s', $overlay, self::VARIABLE, $this->overlayLiteral($overlay));
        }

        $written = array_map(
            static fn (string $line): string => (string) preg_replace('~\A([^:]+):[0-9]+: ~', '$1: ', $line),
            KubernetesManifests::tree()->linesMatching('~'.self::VARIABLE.'~'),
        );

        self::assertSame($expected, $written, 'Sous k8s/, seuls les littéraux `backend-config` des overlays nomment '.self::VARIABLE.' (commentaires compris).');
    }

    private function siteUrlOf(string $environment): string
    {
        if (\in_array($environment, self::SERVED_OVER_HTTPS, true)) {
            return $this->overlayLiteral($environment);
        }

        $path = RepositoryFiles::backend('.env');
        $siteUrl = new Dotenv()->parse(RepositoryFiles::read($path), $path)[self::VARIABLE] ?? null;
        self::assertIsString($siteUrl, \sprintf('%s : %s absente de backend/.env. %s', $environment, self::VARIABLE, self::WHY));

        return $siteUrl;
    }

    /**
     * Le littéral de la variable dans le `configMapGenerator` `backend-config`
     * de l'overlay, écrit une fois et une seule.
     */
    private function overlayLiteral(string $overlay): string
    {
        $kustomization = KubernetesManifests::overlay($overlay)->resource('kustomization.yaml', 'Kustomization');
        $generator = KubernetesManifests::firstWith($kustomization, 'configMapGenerator', 'name', 'backend-config');
        self::assertNotNull($generator, $overlay.' : configMapGenerator backend-config absent.');
        $literals = $generator['literals'] ?? null;
        self::assertIsArray($literals, $overlay.' : backend-config sans `literals`.');

        $values = [];
        foreach ($literals as $literal) {
            self::assertIsString($literal, $overlay.' : un littéral de backend-config n\'est pas une chaîne.');
            if (str_starts_with($literal, self::VARIABLE.'=')) {
                $values[] = substr($literal, \strlen(self::VARIABLE) + 1);
            }
        }
        self::assertCount(1, $values, \sprintf('%s : %s doit être posée une fois, et une seule, dans backend-config. %s', $overlay, self::VARIABLE, self::WHY));

        return $values[0];
    }
}
