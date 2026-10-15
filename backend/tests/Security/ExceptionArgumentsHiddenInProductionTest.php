<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\KubernetesManifests;
use App\Tests\Support\PhpImageBuild;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Les traces d'exception des environnements déployés ne portent pas les
 * arguments des appels (issue #278, audit du correctif #272, constat I8).
 *
 * La valeur intégrée de PHP est `zend.exception_ignore_args = Off` : chaque
 * frame d'une trace garde alors ses arguments, un DSN avec son mot de passe, un
 * mot de passe soumis, un jeton. Aujourd'hui rien ne fuit — les signatures qui
 * les reçoivent portent `#[SensitiveParameter]` et le formateur JSON de
 * Monolog n'écrit pas les stacktraces — mais cette protection tient signature
 * par signature : la première qui l'oublie suffit. La directive la rend globale.
 *
 * Seul `php.prod.ini` la pose (production et préprod, l'image `preprod`
 * étant construite `FROM production`) : en dev, les arguments restent visibles,
 * c'est précisément ce qu'on veut en déboguant.
 *
 * Le test lit les fichiers ({@see PhpImageBuild}) : la configuration de
 * l'image n'est pas celle du conteneur de dev qui exécute la suite.
 * `docker/php` y est monté en lecture seule pour cela.
 *
 * Poser la directive ne suffit pas : toute autre source de réglages que
 * l'image applique peut la contredire (issue #616). Le garde porte donc sur
 * chaque fichier que le Dockerfile copie dans la configuration de PHP ou de
 * FPM des stages déployés — liste lue dans le Dockerfile, jamais écrite ici :
 * un fichier ajouté demain est vu sans toucher au test. `xdebug.preprod.ini`,
 * chargé après `zz-app.ini` dans l'image `preprod`, l'emporterait sur lui ;
 * un `php_admin_flag[…]` du pool FPM l'emporterait sur tout fichier ini.
 *
 * Non couvert, et accepté : les fichiers que l'image officielle range
 * elle-même dans la configuration (`docker-php-ext-*.ini`,
 * `php-fpm.d/docker.conf`, `www.conf`), absents du dépôt — seule une image
 * construite les montrerait, au prix d'un `docker build` dans la suite.
 */
final class ExceptionArgumentsHiddenInProductionTest extends TestCase
{
    private const string PRODUCTION_INI = 'docker/php/php.prod.ini';

    /**
     * Le nom de la directive, sans son préfixe et sans casse : un garde qui
     * refuse doit refuser large.
     */
    private const string DIRECTIVE_NAME = '/exception_ignore_args/i';

    /**
     * La directive, ou une variable qui détourne PHP de ses fichiers ini.
     */
    private const string INI_LOOKUP_OVERRIDE = '/exception_ignore_args|\bPHP_INI_SCAN_DIR\b|\bPHPRC\b/i';

    /**
     * Une option de `php` ou de `php-fpm` qui écarte ou remplace leurs
     * fichiers de configuration, isolée ou en tête d'élément JSON.
     */
    private const string CONFIGURATION_OPTION = '/(?:\A|[\s"\[,])(?:-[ncdy](?![A-Za-z-])|--(?:no-php-ini|php-ini|define|fpm-config)\b)/';

    public function testDeployedImagesDropCallArgumentsFromExceptionTraces(): void
    {
        self::assertSame('On', PhpImageBuild::productionIniDirective('zend.exception_ignore_args'));
        self::assertContains(
            self::PRODUCTION_INI,
            PhpImageBuild::deployedConfigurationCopies(),
            'php.prod.ini n\'est plus copié dans la configuration des images déployées : la directive qu\'il pose ne s\'y applique pas.',
        );
    }

    /**
     * Aucun autre fichier de configuration des images ne nomme la directive :
     * ni un ini, ni le pool FPM (`php_value`, `php_flag`, `php_admin_value`,
     * `php_admin_flag`), qu'ils la confirment ou la contredisent — une seule
     * source, `php.prod.ini`.
     */
    public function testNoOtherConfigurationFileOfTheImagesNamesTheDirective(): void
    {
        $offenders = [];
        foreach (PhpImageBuild::deployedConfigurationCopies() as $destination => $source) {
            if (self::PRODUCTION_INI === $source) {
                continue;
            }
            foreach (PhpImageBuild::iniKeys(PhpImageBuild::contextFile($source), $source) as $key) {
                if (1 === preg_match(self::DIRECTIVE_NAME, $key)) {
                    $offenders[] = \sprintf('%s (%s) : %s', $source, $destination, $key);
                }
            }
        }

        self::assertSame([], $offenders, 'Seul php.prod.ini règle zend.exception_ignore_args dans les images déployées (issue #616).');
    }

    /**
     * Aucune instruction des stages déployés ne nomme la directive (un `ENV`,
     * un `-d` de la commande) ni ne détourne PHP de ses fichiers :
     * `PHP_INI_SCAN_DIR` remplace `conf.d/` en entier, `zz-app.ini` compris,
     * `PHPRC` déplace `php.ini`. Une écriture dans `/usr/local/etc/` par un
     * `RUN` est déjà refusée par {@see PhpImageBuild::configurationCopiesOf()}.
     */
    public function testNoInstructionOfTheDeployedStagesBypassesTheConfigurationFiles(): void
    {
        self::assertSame(
            [],
            array_values(array_filter(
                PhpImageBuild::deployedInstructions(),
                static fn (string $instruction): bool => 1 === preg_match(self::INI_LOOKUP_OVERRIDE, $instruction),
            )),
            'Une instruction des stages déployés règle zend.exception_ignore_args ou détourne PHP de php.prod.ini (issue #616).',
        );
    }

    /**
     * La commande des images ne passe à PHP ni à FPM aucune option qui écarte
     * ou remplace leurs fichiers de configuration : `-n` (aucun ini), `-c`
     * (un autre `php.ini`), `-d` (une directive), `-y` (une autre
     * configuration de FPM), ni leurs formes longues. Un `sh -c …` y serait
     * refusé aussi, et c'est voulu : la commande passerait par un shell que le
     * garde ne lit pas.
     */
    public function testTheImageCommandKeepsTheConfigurationFiles(): void
    {
        self::assertSame(
            [],
            array_values(array_filter(
                PhpImageBuild::deployedInstructions(),
                static fn (string $instruction): bool => 1 === preg_match('/\A\s*(?:CMD|ENTRYPOINT)\b/i', $instruction)
                    && 1 === preg_match(self::CONFIGURATION_OPTION, $instruction),
            )),
            'La commande des images déployées écarte leurs fichiers de configuration (issue #616).',
        );
    }

    /**
     * Le sidecar nginx ne passe à FPM aucun `PHP_VALUE` ni `PHP_ADMIN_VALUE` :
     * FPM applique ces deux paramètres FastCGI à la requête, par-dessus tout
     * fichier ini. Refusés en entier, et pas seulement quand ils nomment la
     * directive : leur valeur peut se composer de variables nginx qu'aucune
     * lecture du texte ne verrait, et aucun usage n'en a besoin. Lu dans tout
     * `k8s/`, une conf portée par un manifeste comprise ; `fastcgi_params`,
     * le fichier de l'image nginx, n'est pas dans le dépôt.
     */
    public function testTheSidecarPassesNoIniValueToFpm(): void
    {
        self::assertSame(
            [],
            $this->deploymentLinesMatching('/\bPHP_(?:ADMIN_)?VALUE\b/i'),
            'Un PHP_VALUE ou PHP_ADMIN_VALUE passé à FPM l\'emporte sur php.prod.ini (issue #616).',
        );
    }

    /**
     * Les lignes hors commentaire des manifestes et des confs de `k8s/` qui
     * répondent à `$pattern`, chacune préfixée de son fichier et de son
     * numéro.
     *
     * @return list<string>
     */
    private function deploymentLinesMatching(string $pattern): array
    {
        $root = \dirname(KubernetesManifests::base()->directory());
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        $matches = [];
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || !\in_array($file->getExtension(), ['yaml', 'yml', 'conf'], true)) {
                continue;
            }
            $lines = file($file->getPathname(), \FILE_IGNORE_NEW_LINES);
            self::assertIsArray($lines, \sprintf('%s illisible.', $file->getPathname()));
            foreach ($lines as $index => $line) {
                if (!str_starts_with(ltrim($line), '#') && 1 === preg_match($pattern, $line)) {
                    $matches[] = \sprintf('%s:%d: %s', substr($file->getPathname(), \strlen($root) + 1), $index + 1, trim($line));
                }
            }
        }
        sort($matches);

        return $matches;
    }
}
