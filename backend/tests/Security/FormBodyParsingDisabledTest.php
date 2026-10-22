<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\KubernetesManifests;
use App\Tests\Support\PhpImageBuild;
use App\Tests\Support\RepositoryFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * PHP n'analyse aucun corps multipart dans les images (issue #686, suivi de
 * l'avis GHSA-w8hw-w9fx-j79w, CVE-2026-109618, corrigé par PHP 8.5.12).
 *
 * L'avis déclenche son OOM surtout par l'analyse **multipart** que PHP fait
 * avant l'application. Aucune route ne lit de multipart, et le sidecar nginx
 * le refuse déjà en 415 (`tools/check-backend-nginx-rate-limits.sh`) : ces
 * réglages sont la seconde barrière, pour une requête qui atteindrait FPM sans
 * lui. Mesuré sous PHP 8.5 (#686) :
 *
 * - `max_multipart_body_parts = 0` fait refuser le multipart au premier champ
 *   nommé, sur les deux chemins : l'analyse du démarrage d'un POST, et
 *   `request_parse_body()`, que Symfony appelle pour PUT, DELETE, PATCH et
 *   QUERY dans `Request::createFromGlobals()` (une `RequestParseBodyException`,
 *   qu'il rattrape) — `-1`, la valeur intégrée, en admettait
 *   `max_input_vars + max_file_uploads`. Restent parcourus, bornés par
 *   `post_max_size` : les en-têtes de la première partie et les parties sans
 *   `Content-Disposition`, que le compteur ne voit pas (audit de #686, lu dans
 *   `main/rfc1867.c`) ;
 * - `file_uploads = Off` : plus aucun fichier temporaire écrit ;
 * - `post_max_size = 1M`, la borne du corps entier du nginx (16M ou 32M
 *   avant), pour l'analyse des corps de formulaire. Elle ne borne pas un corps
 *   JSON lu sur `php://input`, ni avant ni après (mesuré : 3 Mo lus sous 1M) ;
 *   nginx seul le borne ;
 * - `user_ini.filename` vide : aucun `.user.ini` ne peut changer les trois
 *   précédents (`PHP_INI_PERDIR`). Déjà fermé en production par la racine en
 *   lecture seule, ouvert en dev, où `public/` est monté.
 *
 * **L'urlencoded reste analysé, par décision** (2026-10-22, relectures de
 * #686) : ce n'est pas le parseur de l'avis, et le point de jeton du serveur
 * OAuth 2.1 de la phase 2 MCP (ADR 0006 D3) le reçoit par norme dans `$_POST`.
 * D'où l'absence voulue d'`enable_post_data_reading = Off`, qui viderait
 * `$_POST` sans rien fermer de plus. `max_input_vars` et
 * `max_input_nesting_level` gardent leur valeur intégrée : ils bornent aussi
 * `$_GET` et `$_COOKIE`.
 *
 * Les valeurs se lisent dans les fichiers ({@see PhpImageBuild}), comme
 * {@see SessionUploadProgressDisabledTest}, avec les mêmes limites acceptées ;
 * leur effet se mesure sur le serveur intégré de PHP, sous chaque fichier
 * chargé seul (`-n -c`) : même analyse des corps que FPM, qui n'est pas lancé.
 */
final class FormBodyParsingDisabledTest extends TestCase
{
    private const string DEV_INI = 'docker/php/php.dev.ini';

    /**
     * Valeurs brutes attendues, telles qu'écrites dans le fichier.
     */
    private const array DIRECTIVES = [
        'max_multipart_body_parts' => '0',
        'file_uploads' => 'Off',
        'post_max_size' => '1M',
        'user_ini.filename' => '',
    ];

    /**
     * Directives qu'aucun des deux fichiers ne doit plus nommer.
     */
    private const array ABSENT_DIRECTIVES = [
        'upload_max_filesize' => 'sans objet une fois file_uploads éteint',
        'enable_post_data_reading' => 'Off, il viderait $_POST pour le point de jeton OAuth de la phase 2, sans rien fermer que max_multipart_body_parts ne ferme déjà',
    ];

    private const string MULTIPART_BODY = "--x\r\n"
        ."Content-Disposition: form-data; name=\"a\"\r\n\r\n1\r\n"
        ."--x\r\n"
        ."Content-Disposition: form-data; name=\"f\"; filename=\"f.txt\"\r\n"
        ."Content-Type: text/plain\r\n\r\nhello\r\n"
        ."--x--\r\n";

    private const string MULTIPART = 'multipart/form-data; boundary=x';

    /**
     * Manifestes qui nomment ces réglages pour une autre image que celle du
     * backend : leur PHP n'est pas le nôtre.
     */
    private const array OTHER_IMAGES = [
        'base/adminer.yaml' => 'Adminer, image officielle à son propre PHP (ses limites d\'import citées en commentaire)',
    ];

    private ?Process $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop(0);
        $this->server = null;
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function directives(): iterable
    {
        foreach (self::DIRECTIVES as $name => $value) {
            yield $name => [$name, $value];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function iniFiles(): iterable
    {
        yield 'production' => [PhpImageBuild::PRODUCTION_INI];
        yield 'dev' => [self::DEV_INI];
    }

    #[DataProvider('directives')]
    public function testDeployedImagesSetIt(string $name, string $expected): void
    {
        self::assertSame($expected, PhpImageBuild::productionIniDirective($name));
    }

    /**
     * En dev aussi : le conteneur de dev sert l'API par le même FPM, et un
     * réglage qui ne vaudrait qu'en production cacherait en dev ce qu'il
     * change.
     */
    #[DataProvider('directives')]
    public function testTheDevImageSetsItToo(string $name, string $expected): void
    {
        self::assertSame(
            $expected,
            PhpImageBuild::iniDirective(PhpImageBuild::contextFile(self::DEV_INI), $name, basename(self::DEV_INI)),
        );
    }

    #[DataProvider('iniFiles')]
    public function testNeitherIniNamesTheAbsentDirectives(string $file): void
    {
        $names = array_map($this->directiveName(...), PhpImageBuild::iniKeys(PhpImageBuild::contextFile($file), $file));
        foreach (self::ABSENT_DIRECTIVES as $directive => $reason) {
            self::assertNotContains($directive, $names, \sprintf('%s nomme %s : %s (#686).', $file, $directive, $reason));
        }
    }

    /**
     * Aucun autre fichier de configuration des images déployées ne nomme ces
     * réglages : chargé après `zz-app.ini` (`xdebug.preprod.ini`) ou porté
     * par le pool FPM (`php_admin_value[…]`), il rouvrirait l'analyse. Le nom
     * est comparé exactement : `max_file_uploads` n'est pas `file_uploads`.
     */
    public function testNoOtherConfigurationFileOfTheImagesNamesThem(): void
    {
        $offenders = [];
        foreach (PhpImageBuild::deployedConfigurationCopies() as $copy) {
            if (PhpImageBuild::PRODUCTION_INI === $copy->source) {
                continue;
            }
            foreach (PhpImageBuild::iniKeys(PhpImageBuild::contextFile($copy->source), $copy->source) as $key) {
                if (\in_array($this->directiveName($key), $this->watchedDirectives(), true)) {
                    $offenders[] = \sprintf('%s (%s, %s) : %s', $copy->source, $copy->stage, $copy->destination, $key);
                }
            }
        }

        self::assertSame([], $offenders, 'Seul php.prod.ini règle l\'analyse des corps de formulaire dans les images déployées (#686).');
    }

    /**
     * Ni un manifeste : un `-d` dans les `args` du conteneur php-fpm, un
     * `PHP_VALUE` ou une ConfigMap les changerait sans toucher l'image (audit
     * de #686). Lu en texte, commentaires compris (#616).
     */
    public function testNoManifestNamesThem(): void
    {
        $pattern = '/\b(?:'.implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), $this->watchedDirectives())).')\b/i';
        $offenders = [];
        $exemptionsUsed = [];
        foreach (KubernetesManifests::tree()->linesMatching($pattern) as $line) {
            $file = explode(':', $line, 2)[0];
            if (\array_key_exists($file, self::OTHER_IMAGES)) {
                $exemptionsUsed[$file] = $file;

                continue;
            }
            $offenders[] = $line;
        }

        self::assertSame([], $offenders, 'Un manifeste règle l\'analyse des corps de formulaire du backend (#686).');
        self::assertSame(array_keys(self::OTHER_IMAGES), array_values($exemptionsUsed), 'Une exemption de OTHER_IMAGES ne sert plus : la retirer.');
    }

    /**
     * Le multipart n'est analysé sur aucun des deux chemins : ni au démarrage
     * d'un POST, ni par `request_parse_body()`. QUERY manque : le serveur
     * intégré y répond 501 sans exécuter le routeur, alors que FPM la transmet
     * comme les autres — même appel que pour PUT, et la sonde nginx la couvre.
     */
    #[DataProvider('iniFiles')]
    public function testPhpUnderTheIniParsesNoMultipartBody(string $file): void
    {
        $base = $this->startServer($file);

        $post = $this->probe($base, 'POST', self::MULTIPART, self::MULTIPART_BODY);
        self::assertSame([], $post['post'], 'POST multipart : $_POST rempli.');
        self::assertSame([], $post['files'], 'POST multipart : $_FILES rempli.');
        foreach (['PUT', 'DELETE', 'PATCH'] as $method) {
            self::assertSame('refused', $this->probe($base, $method, self::MULTIPART, self::MULTIPART_BODY)['parsed'] ?? null, $method.' multipart : analysé.');
        }
    }

    /**
     * Ce que l'API et la phase 2 lisent reste lisible : un corps JSON sur
     * `php://input`, un corps urlencoded dans `$_POST` (le point de jeton
     * OAuth) et par `request_parse_body()`.
     */
    #[DataProvider('iniFiles')]
    public function testJsonAndUrlencodedBodiesStayReadable(string $file): void
    {
        $base = $this->startServer($file);

        self::assertSame(\strlen('{"a":1}'), $this->probe($base, 'POST', 'application/json', '{"a":1}')['inputLength'] ?? null);
        self::assertSame(['a' => '1'], $this->probe($base, 'POST', 'application/x-www-form-urlencoded', 'a=1')['post']);
        self::assertSame(['a' => '1'], $this->probe($base, 'PUT', 'application/x-www-form-urlencoded', 'a=1')['parsed'] ?? null);
    }

    /**
     * @return list<string>
     */
    private function watchedDirectives(): array
    {
        return [...array_keys(self::DIRECTIVES), ...array_keys(self::ABSENT_DIRECTIVES)];
    }

    /**
     * Le nom d'une directive : la clé elle-même, ou ce qu'elle porte entre
     * crochets dans la forme du pool FPM (`php_admin_value[post_max_size]`).
     */
    private function directiveName(string $key): string
    {
        return strtolower(1 === preg_match('/\[([^\]]+)\]\z/', $key, $matches) ? $matches[1] : $key);
    }

    /**
     * Lance le serveur intégré sous le seul fichier `$file` et rend son URL.
     */
    private function startServer(string $file): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket, 'Aucun port libre.');
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $this->server = ChildPhpProcess::inTestEnvironment(
            30,
            '-n',
            '-c',
            RepositoryFiles::repository($file),
            '-S',
            $address,
            __DIR__.'/Fixtures/form-body-probe.php',
        );
        $this->server->start();

        for ($attempt = 0; $attempt < 50; ++$attempt) {
            $client = @stream_socket_client('tcp://'.$address, timeout: 0.1);
            if (\is_resource($client)) {
                fclose($client);

                return 'http://'.$address;
            }
            usleep(100_000);
        }

        self::fail(\sprintf('Le serveur intégré sous %s ne répond pas : %s', $file, $this->server->getErrorOutput()));
    }

    /**
     * @return array<string, mixed> ce que le routeur a constaté
     */
    private function probe(string $base, string $method, string $contentType, string $body): array
    {
        $response = file_get_contents($base.'/', context: stream_context_create(['http' => [
            'method' => $method,
            'header' => 'Content-Type: '.$contentType,
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 5,
        ]]));
        self::assertIsString($response, $method.' '.$contentType.' : aucune réponse.');

        $lines = explode("\n", trim($response));
        $decoded = json_decode(end($lines), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, $method.' '.$contentType.' : réponse illisible.');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
