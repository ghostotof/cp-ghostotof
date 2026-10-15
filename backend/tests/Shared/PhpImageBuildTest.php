<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\ConfigurationCopy;
use App\Tests\Support\PhpImageBuild;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ce que {@see PhpImageBuild} lit de `php.prod.ini` (issue #612) et de
 * `www.prod.conf` (issue #615) : les tests qui pincent une directive des
 * images déployées passent par lui, et le chemin du fichier ne dépend plus de
 * leur emplacement. La lecture des directives est éprouvée sur des textes
 * écrits pour l'occasion.
 */
final class PhpImageBuildTest extends TestCase
{
    /**
     * La valeur brute, comme le fichier l'écrit : `On` reste `On` (le scanner
     * normal le lirait `1`), un fuseau reste un fuseau. Lire le vrai fichier
     * prouve aussi que la racine résolue est celle du dépôt.
     */
    public function testADirectiveOfTheProductionIniIsReadRaw(): void
    {
        self::assertSame('On', PhpImageBuild::productionIniDirective('zend.exception_ignore_args'));
        self::assertSame('Europe/Paris', PhpImageBuild::productionIniDirective('date.timezone'));
    }

    /**
     * Le pool FPM des images (issue #615), lu comme `php.prod.ini` : le vrai
     * fichier, quel que soit l'emplacement du test qui le demande. Sa valeur
     * reste aux deux tests de borne du verrou, qui la pincent.
     */
    public function testTheProductionPoolIsReadFromTheBuildContext(): void
    {
        self::assertMatchesRegularExpression('/^env\[PGOPTIONS\] = "[^"]+"$/m', PhpImageBuild::productionPool());
    }

    /**
     * Une directive absente fait échouer le test qui la demande, en la
     * nommant, au lieu de rendre une chaîne vide qu'une assertion pourrait
     * accepter.
     */
    public function testAMissingDirectiveFailsTheTestNamingIt(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('php.prod.ini : aucune directive « no.such.directive ».');

        PhpImageBuild::productionIniDirective('no.such.directive');
    }

    /**
     * Écrite deux fois, une directive vaut sa dernière occurrence, celle que
     * PHP applique : la première, lue par une expression régulière, ferait
     * passer un `On` qu'un `Off` plus bas annule.
     */
    public function testTheLastOccurrenceWinsAsPhpAppliesIt(): void
    {
        self::assertSame('Off', PhpImageBuild::iniDirective("a = On\na = Off\n", 'a', 'essai.ini'));
    }

    /**
     * Le commentaire de fin de ligne et les guillemets sont retirés, la
     * valeur reste brute.
     */
    public function testTheValueIsReadRawWithoutCommentNorQuotes(): void
    {
        self::assertSame('On', PhpImageBuild::iniDirective("a = On ; commentaire\n", 'a', 'essai.ini'));
        self::assertSame('x y', PhpImageBuild::iniDirective("a = \"x y\"\n", 'a', 'essai.ini'));
    }

    public function testACommentedDirectiveIsAbsent(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('essai.ini : aucune directive « a ».');

        PhpImageBuild::iniDirective("; a = On\n", 'a', 'essai.ini');
    }

    public function testAListDirectiveIsRefused(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('essai.ini : « a » n\'est pas une valeur simple.');

        PhpImageBuild::iniDirective("a[] = x\n", 'a', 'essai.ini');
    }

    /**
     * Les fichiers de configuration de PHP et de FPM que les images déployées
     * reçoivent (issue #616), lus dans le vrai Dockerfile : ceux de
     * `production` et de `preprod`, jamais ceux du stage `dev`.
     */
    public function testTheDeployedConfigurationCopiesAreReadFromTheDockerfile(): void
    {
        self::assertEquals([
            new ConfigurationCopy('production', 'docker/php/php.prod.ini', '/usr/local/etc/php/conf.d/zz-app.ini'),
            new ConfigurationCopy('production', 'docker/php/www.prod.conf', '/usr/local/etc/php-fpm.d/zz-www.conf'),
            new ConfigurationCopy('preprod', 'docker/php/xdebug.preprod.ini', '/usr/local/etc/php/conf.d/zz-xdebug.ini'),
        ], PhpImageBuild::deployedConfigurationCopies());
    }

    /**
     * Deux stages qui copient vers la même destination gardent chacun leur
     * copie : rangée par destination, celle de `preprod` effaçait celle de
     * `production`, dont le fichier n'était alors jamais lu (relecture de
     * #616).
     */
    public function testEachStageKeepsItsCopyToTheSameDestination(): void
    {
        self::assertEquals(
            [
                new ConfigurationCopy('production', 'docker/php/other.ini', '/usr/local/etc/php/conf.d/zz-app.ini'),
                new ConfigurationCopy('preprod', 'docker/php/php.prod.ini', '/usr/local/etc/php/conf.d/zz-app.ini'),
            ],
            PhpImageBuild::configurationCopiesOf(<<<'DOCKERFILE'
                FROM php:8 AS production
                COPY docker/php/other.ini /usr/local/etc/php/conf.d/zz-app.ini
                FROM production AS preprod
                COPY docker/php/php.prod.ini /usr/local/etc/php/conf.d/zz-app.ini
                DOCKERFILE),
        );
    }

    /**
     * Le stage `production` du vrai Dockerfile et ceux dont il descend : ce
     * que l'image de production reçoit, sans ce que `preprod` ajoute.
     */
    public function testTheProductionImageIsBuiltFromItsAncestors(): void
    {
        self::assertSame(['production', 'base'], PhpImageBuild::stageAndAncestors('production'));
    }

    /**
     * PHP ne charge un ini que sous `conf.d/` et suffixé `.ini` (au-delà de
     * `php.ini`, que l'image ne fournit pas) ; un pool FPM n'en est pas un.
     */
    public function testOnlyAnIniUnderConfDIsLoadedByPhp(): void
    {
        self::assertTrue(new ConfigurationCopy('production', 'a.ini', '/usr/local/etc/php/conf.d/zz-app.ini')->isLoadedPhpIni());
        self::assertFalse(new ConfigurationCopy('production', 'a.ini', '/usr/local/etc/php/zz-app.ini')->isLoadedPhpIni());
        self::assertFalse(new ConfigurationCopy('production', 'a.ini', '/usr/local/etc/php/conf.d/sub/zz-app.ini')->isLoadedPhpIni());
        self::assertFalse(new ConfigurationCopy('production', 'a.ini', '/usr/local/etc/php/conf.d/zz-app.ini.bak')->isLoadedPhpIni());
        self::assertFalse(new ConfigurationCopy('production', 'a.conf', '/usr/local/etc/php-fpm.d/zz-www.conf')->isLoadedPhpIni());
    }

    /**
     * Un stage déployé emporte ses ancêtres (`FROM <stage>`), pas les stages
     * voisins : `dev` n'est l'ancêtre d'aucune image déployée.
     */
    public function testTheDeployedStagesIncludeTheirAncestorsOnly(): void
    {
        $dockerfile = <<<'DOCKERFILE'
            ARG TAG=1
            FROM php:8 AS base
            RUN echo base
            FROM base AS dev
            RUN echo dev
            FROM base AS production
            RUN echo production
            FROM production AS preprod
            RUN echo preprod
            DOCKERFILE;

        self::assertSame(
            ['FROM php:8 AS base', 'RUN echo base', 'FROM base AS production', 'RUN echo production', 'FROM production AS preprod', 'RUN echo preprod'],
            PhpImageBuild::deployedInstructionsOf($dockerfile),
        );
    }

    /**
     * Docker lit les noms de stage sans casse : `AS Production` est le stage
     * `production`.
     */
    public function testAStageNameIsReadCaseInsensitively(): void
    {
        self::assertSame(
            ['FROM php:8 AS Production', 'from Production as PrePROD'],
            PhpImageBuild::deployedInstructionsOf("FROM php:8 AS Production\nfrom Production as PrePROD\n"),
        );
    }

    public function testAMissingDeployedStageFailsTheTest(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Dockerfile : aucun stage « preprod ».');

        PhpImageBuild::deployedInstructionsOf("FROM php:8 AS production\n");
    }

    /**
     * Une destination en répertoire reçoit le nom du fichier source, comme
     * le fait Docker ; `ADD` copie comme `COPY`.
     */
    public function testACopyIntoTheConfigurationNamesItsDestinationFile(): void
    {
        self::assertEquals(
            [
                new ConfigurationCopy('production', 'docker/php/extra.ini', '/usr/local/etc/php/conf.d/extra.ini'),
                new ConfigurationCopy('production', 'docker/php/www.prod.conf', '/usr/local/etc/php-fpm.d/zz-www.conf'),
            ],
            PhpImageBuild::configurationCopiesOf(<<<'DOCKERFILE'
                FROM php:8 AS production
                COPY --chown=app:app docker/php/extra.ini /usr/local/etc/php/conf.d/
                ADD docker/php/www.prod.conf /usr/local/etc/php-fpm.d/zz-www.conf
                COPY docker/php/healthcheck.sh /usr/local/bin/healthcheck
                FROM production AS preprod
                DOCKERFILE),
        );
    }

    /**
     * Ce que le garde ne sait pas lire, il le refuse : une copie depuis un
     * autre stage (`--from`), plusieurs sources, la forme JSON, ou un `RUN`
     * qui écrit dans la configuration de PHP.
     *
     * @return iterable<string, array{string}>
     */
    public static function unreadableConfigurationWrites(): iterable
    {
        yield 'copie depuis un stage' => ['COPY --from=base /tmp/x.ini /usr/local/etc/php/conf.d/x.ini'];
        yield 'plusieurs sources' => ['COPY a.ini b.ini /usr/local/etc/php/conf.d/'];
        yield 'forme JSON' => ['COPY ["a.ini", "/usr/local/etc/php/conf.d/a.ini"]'];
        yield 'RUN' => ['RUN echo "zend.exception_ignore_args = Off" > /usr/local/etc/php/conf.d/x.ini'];
        // L'image officielle exporte `PHP_INI_DIR=/usr/local/etc/php`, que
        // Docker développe aussi dans la destination d'un `COPY`.
        yield 'destination par la variable' => ['COPY docker/php/extra.ini $PHP_INI_DIR/conf.d/'];
        yield 'RUN par la variable' => ['RUN rm "$PHP_INI_DIR/conf.d/zz-app.ini"'];
        yield 'variable redéfinie' => ['ENV PHP_INI_DIR=/tmp'];
        yield 'répertoire sans barre finale' => ['COPY docker/etc /usr/local/etc'];
        yield 'WORKDIR' => ['WORKDIR /usr/local/etc/php/conf.d'];
    }

    #[DataProvider('unreadableConfigurationWrites')]
    public function testAnUnreadableWriteIntoTheConfigurationIsRefused(string $instruction): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('écrit dans la configuration de PHP ou de FPM');

        PhpImageBuild::configurationCopiesOf("FROM php:8 AS production\n{$instruction}\nFROM production AS preprod\n");
    }

    /**
     * Les commandes des images déployées, lues dans le vrai Dockerfile : le
     * `CMD` de chaque stage déployé ou ancêtre, en forme JSON.
     */
    public function testTheDeployedCommandsAreReadFromTheDockerfile(): void
    {
        self::assertSame([['php-fpm', '-F'], ['php-fpm', '-F']], PhpImageBuild::deployedCommands());
    }

    /**
     * `CMD` et `ENTRYPOINT` sont lus élément par élément ; le `CMD` d'un
     * `HEALTHCHECK` n'est pas la commande de l'image.
     */
    public function testACommandIsReadFromItsJsonForm(): void
    {
        self::assertSame(
            [['docker-entrypoint', '--x'], ['php-fpm', '-F']],
            PhpImageBuild::deployedCommandsOf(<<<'DOCKERFILE'
                FROM php:8 AS production
                ENTRYPOINT ["docker-entrypoint", "--x"]
                HEALTHCHECK --interval=15s CMD ["/usr/local/bin/healthcheck"]
                FROM production AS preprod
                CMD ["php-fpm", "-F"]
                DOCKERFILE),
        );
    }

    /**
     * La forme shell passe par un `sh -c` implicite, qui développe des
     * variables que le garde ne lit pas : elle est refusée.
     */
    public function testAShellFormCommandIsRefused(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('n\'est pas en forme JSON');

        PhpImageBuild::deployedCommandsOf("FROM php:8 AS production\nCMD php-fpm -F \$FLAGS\nFROM production AS preprod\n");
    }

    /**
     * Un `COPY docker/php/ …` copierait tout un répertoire : le garde, qui lit
     * chaque fichier reçu, refuse une source qui n'est pas un fichier.
     */
    public function testAContextDirectoryIsNotReadAsAFile(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('docker/php n\'est pas un fichier.');

        PhpImageBuild::contextFile('docker/php');
    }

    /**
     * Toutes les clés d'un texte ini, à toute profondeur : une section, une
     * directive et la clé d'un tableau (`php_admin_flag[…]`, la forme du pool
     * FPM).
     */
    public function testEveryIniKeyIsListedAtEveryDepth(): void
    {
        self::assertSame(
            ['global', 'error_log', 'www', 'php_admin_flag', 'zend.exception_ignore_args', 'env', 'APP_ENV'],
            PhpImageBuild::iniKeys("[global]\nerror_log = x\n[www]\nphp_admin_flag[zend.exception_ignore_args] = off\nenv[APP_ENV] = \$APP_ENV\n", 'essai.conf'),
        );
    }
}
