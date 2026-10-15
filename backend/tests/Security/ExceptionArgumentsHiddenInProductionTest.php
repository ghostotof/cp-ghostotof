<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\ConfigurationCopy;
use App\Tests\Support\KubernetesManifests;
use App\Tests\Support\PhpImageBuild;
use PHPUnit\Framework\TestCase;

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
 * Au-delà des fichiers, le garde lit ce qui pourrait les écarter : les
 * instructions et la commande des stages déployés, le sidecar nginx et les
 * pods (un test chacun, ses limites dans son docblock).
 *
 * Non couvert, et accepté : les fichiers que l'image officielle range
 * elle-même dans la configuration (`docker-php-ext-*.ini`,
 * `php-fpm.d/docker.conf`, `www.conf`), absents du dépôt — seule une image
 * construite les montrerait, au prix d'un `docker build` dans la suite.
 */
final class ExceptionArgumentsHiddenInProductionTest extends TestCase
{
    /**
     * Le motif du nom de la directive, sans son préfixe et sans casse : un garde qui
     * refuse doit refuser large.
     */
    private const string DIRECTIVE_PATTERN = '/exception_ignore_args/i';

    /**
     * La directive, ou une variable qui détourne PHP de ses fichiers ini.
     */
    private const string DIRECTIVE_OR_INI_REDIRECTION = '/exception_ignore_args|\bPHP_INI_SCAN_DIR\b|\bPHPRC\b/i';

    /**
     * Les options que la commande des images peut passer à `php-fpm` :
     * `-F`, rester au premier plan (le conteneur en dépend).
     */
    private const array ALLOWED_COMMAND_OPTIONS = ['-F'];

    public function testDeployedImagesDropCallArgumentsFromExceptionTraces(): void
    {
        self::assertSame('On', PhpImageBuild::productionIniDirective('zend.exception_ignore_args'));
    }

    /**
     * `php.prod.ini` est un ini que PHP charge (sous `conf.d/`), copié par le
     * stage `production` ou un de ses ancêtres : copié par `preprod` seul, ou
     * hors de `conf.d/`, la directive ne s'appliquerait pas en production.
     */
    public function testTheProductionImageLoadsTheProductionIni(): void
    {
        $productionStages = PhpImageBuild::stageAndAncestors('production');
        self::assertNotSame(
            [],
            array_filter(
                PhpImageBuild::deployedConfigurationCopies(),
                static fn (ConfigurationCopy $copy): bool => PhpImageBuild::PRODUCTION_INI === $copy->source
                    && $copy->isLoadedPhpIni()
                    && \in_array($copy->stage, $productionStages, true),
            ),
            'php.prod.ini n\'est plus chargé par l\'image de production : la directive qu\'il pose ne s\'y applique pas.',
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
        foreach (PhpImageBuild::deployedConfigurationCopies() as $copy) {
            if (PhpImageBuild::PRODUCTION_INI === $copy->source) {
                continue;
            }
            foreach (PhpImageBuild::iniKeys(PhpImageBuild::contextFile($copy->source), $copy->source) as $key) {
                if (1 === preg_match(self::DIRECTIVE_PATTERN, $key)) {
                    $offenders[] = \sprintf('%s (%s, %s) : %s', $copy->source, $copy->stage, $copy->destination, $key);
                }
            }
        }

        self::assertSame([], $offenders, 'Seul php.prod.ini règle zend.exception_ignore_args dans les images déployées (issue #616).');
    }

    /**
     * Aucun fichier de configuration des images n'en inclut un autre : un
     * `include =` de FPM charge un fichier, livré par exemple avec le code de
     * l'application, que le garde ne lirait jamais.
     */
    public function testNoConfigurationFileOfTheImagesIncludesAnother(): void
    {
        $offenders = [];
        foreach (PhpImageBuild::deployedConfigurationCopies() as $copy) {
            foreach (PhpImageBuild::iniKeys(PhpImageBuild::contextFile($copy->source), $copy->source) as $key) {
                if (0 === strcasecmp('include', $key)) {
                    $offenders[] = \sprintf('%s (%s, %s)', $copy->source, $copy->stage, $copy->destination);
                }
            }
        }

        self::assertSame([], $offenders, 'Un fichier de configuration des images en inclut un autre, que le garde ne lit pas (issue #616).');
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
                static fn (string $instruction): bool => 1 === preg_match(self::DIRECTIVE_OR_INI_REDIRECTION, $instruction),
            )),
            'Une instruction des stages déployés règle zend.exception_ignore_args ou détourne PHP de php.prod.ini (issue #616).',
        );
    }

    /**
     * La commande des images est `php-fpm` avec les seules options de
     * {@see self::ALLOWED_COMMAND_OPTIONS} : une liste de refus (`-n`, `-c`,
     * `-d`, `-y`, `-p`…) ratait toujours une variante — options groupées
     * (`-nF`), argument collé (`-cx.ini`), forme longue —, une liste autorisée
     * fait relire chaque ajout. La forme shell, qui passerait par un `sh -c`,
     * est refusée par {@see PhpImageBuild::deployedCommandsOf()}.
     */
    public function testTheImageCommandKeepsTheConfigurationFiles(): void
    {
        $offenders = [];
        foreach (PhpImageBuild::deployedCommands() as $command) {
            $options = \array_slice($command, 1);
            if ('php-fpm' !== ($command[0] ?? null) || [] !== array_diff($options, self::ALLOWED_COMMAND_OPTIONS)) {
                $offenders[] = json_encode($command, \JSON_UNESCAPED_SLASHES);
            }
        }

        self::assertSame([], $offenders, 'La commande des images déployées sort de php-fpm -F : relire ce qu\'elle fait de php.prod.ini avant de l\'autoriser (issue #616).');
    }

    /**
     * Le sidecar nginx ne passe à FPM aucun `PHP_VALUE` ni `PHP_ADMIN_VALUE` :
     * FPM applique ces deux paramètres FastCGI à la requête, par-dessus tout
     * fichier ini. Refusés en entier, et pas seulement quand ils nomment la
     * directive : leur valeur peut se composer de variables nginx qu'aucune
     * lecture du texte ne verrait, et aucun usage n'en a besoin. Lu dans
     * chaque fichier de `k8s/` hors `*.md`
     * ({@see KubernetesManifests::linesMatching()}), une conf portée par un
     * manifeste comprise ; `fastcgi_params`, le fichier de l'image nginx,
     * n'est pas dans le dépôt.
     */
    public function testTheSidecarPassesNoIniValueToFpm(): void
    {
        self::assertSame(
            [],
            KubernetesManifests::tree()->linesMatching('/\bPHP_(?:ADMIN_)?VALUE\b/i'),
            'Un PHP_VALUE ou PHP_ADMIN_VALUE passé à FPM l\'emporte sur php.prod.ini (issue #616).',
        );
    }

    /**
     * Aucun pod ne nomme la directive ni ne détourne PHP de ses fichiers
     * (`PHP_INI_SCAN_DIR`, `PHPRC`) : ni l'`env:` d'un conteneur, ni un
     * littéral de ConfigMap (`envFrom`), ni la clé d'un ExternalSecret, ni un
     * `-d` de commande, ni un `.env` de `configMapGenerator`. Chaque fichier
     * de `k8s/` hors `*.md` est lu, overlays et commentaires compris :
     * chacune de ces formes y écrit le nom en clair.
     *
     * Non vu : une option `-n`, `-c`, `-y` ou `-p` de `php` ou `php-fpm` dans
     * la commande d'un pod (souvent un script `sh -c` sur plusieurs lignes),
     * et les clés qu'un ExternalSecret importerait par `dataFrom` sans les
     * nommer (aucun n'en a).
     */
    public function testNoPodBypassesTheConfigurationFiles(): void
    {
        self::assertSame(
            [],
            KubernetesManifests::tree()->linesMatching(self::DIRECTIVE_OR_INI_REDIRECTION),
            'Un manifeste règle zend.exception_ignore_args ou détourne PHP de php.prod.ini (issue #616).',
        );
    }
}
