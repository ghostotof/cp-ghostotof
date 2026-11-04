<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\RepositoryFiles;
use ReflectionMethod;
use SimpleXMLElement;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sonde : chaque valeur que phpunit.dist.xml force à une valeur de test est
 * celle que lisent le noyau du test et un fils de ChildPhpProcess (#748, D1 et
 * Q1 de la dix-huitième relecture). Une vraie clé d'API ou un vrai DSN exporté
 * dans le conteneur ne doit gagner ni chez l'un ni chez l'autre : un test qui
 * atteindrait l'API réelle échouerait en 401, filet voulu, tant que la clé lue
 * est la factice, et aucun courriel ne part tant que le mailer lu est
 * `null://null`.
 *
 * Les noms sont ceux des `<server force="true">` de phpunit.dist.xml, lus du
 * fichier ({@see self::forcedNames()}, choix 6 de la dix-neuvième relecture) :
 * un nom forcé demain est sondé sans rien écrire ici. Chacun doit aussi être
 * posé par un `<env force="true">` de même valeur ; un nom sans lui est un
 * échec qui le nomme. N'y entrent pas, à dessein : un `<server>` sans `force`
 * (SHELL_VERBOSITY, que le shell peut régler), un `<env force>` sans `<server>`
 * (APP_PUBLIC_API_BASE_URL, une valeur publique que `$_SERVER` garde du
 * conteneur) et un `<env>` sans `force` (MCP_D8_AUTHOR_NAME, que la CI
 * remplace par son secret).
 *
 * Verte quand rien n'exporte ces noms ; relancée sous un shell qui exporte une
 * valeur factice aléatoire pour chacun d'eux, elle rougit si le shell gagne.
 *
 * Comparées par empreinte (sha256) : un échec n'écrit jamais une valeur.
 */
final class ForcedTestValuesProbeTest extends KernelTestCase
{
    private const string CHILD = __DIR__.'/Fixtures/ForcedTestValues/print-fingerprints.php';

    private const int TIMEOUT_SECONDS = 60;

    /**
     * Une configuration de test : un nom forcé des deux côtés, un `<server>`
     * sans `force`, un nom dont l'`<env force>` porte une autre valeur, et, en
     * dernier, un nom dont l'`<env>` n'est pas forcé.
     */
    private const string CONFIGURATION_WITH_DEFECTS = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <phpunit>
            <php>
                <server name="FORCED_BOTH_SIDES" value="a" force="true" />
                <env name="FORCED_BOTH_SIDES" value="a" force="true" />
                <server name="SERVER_WITHOUT_FORCE" value="b" />
                <server name="ENV_FORCE_DIVERGES" value="c" force="true" />
                <env name="ENV_FORCE_DIVERGES" value="d" force="true" />
                <server name="ENV_NOT_FORCED" value="e" force="true" />
                <env name="ENV_NOT_FORCED" value="e" />
            </php>
        </phpunit>
        XML;

    public function testTheKernelAndAChildReadTheValuesThatPhpunitDistForces(): void
    {
        $configuration = self::distConfiguration();
        $expected = $this->serverFingerprints($configuration);
        // APP_ENV : l'environnement que KernelTestCase choisit (createKernel(),
        // `$_ENV` d'abord), lu sans démarrer le noyau, qu'un APP_ENV piégé
        // compilerait sous un nom aléatoire.
        $environment = self::createKernel()->getEnvironment();
        // Les autres noms : lus par le conteneur du noyau de test, comme il
        // résout `%env(NOM)%`. Démarré en `test` explicitement : le choix de
        // l'environnement est le cas d'APP_ENV, pas le leur.
        $parent = self::fingerprints($environment, self::bootKernel(['environment' => 'test'])->getContainer());
        $child = $this->childFingerprints();

        $mismatches = $this->envForceDefects($configuration);
        foreach ($expected as $name => $fingerprint) {
            foreach (['noyau du parent' => $parent, 'fils' => $child] as $reader => $read) {
                if (($read[$name] ?? null) !== $fingerprint) {
                    $found = $read[$name] ?? 'absente';
                    $mismatches[] = \sprintf('%s, %s : empreinte %s, attendue %s', $name, $reader, $found, $fingerprint);
                }
            }
        }

        self::assertSame([], $mismatches, 'Nom forcé d\'un seul côté, ou valeur lue autre que la forcée (empreintes seules).');
    }

    public function testEveryForcedServerValueIsForcedInEnvToo(): void
    {
        $configuration = new SimpleXMLElement(self::CONFIGURATION_WITH_DEFECTS);

        self::assertSame(['FORCED_BOTH_SIDES', 'ENV_FORCE_DIVERGES', 'ENV_NOT_FORCED'], self::forcedNamesIn($configuration));
        self::assertSame(
            [
                'ENV_FORCE_DIVERGES : son <env force> ne porte pas la valeur de son <server>.',
                'ENV_NOT_FORCED : aucun <env force> ne le pose.',
            ],
            $this->envForceDefects($configuration),
        );
    }

    /**
     * Les noms que phpunit.dist.xml pose par un `<server force="true">`, dans
     * l'ordre du fichier.
     *
     * @return list<string>
     */
    public static function forcedNames(): array
    {
        return self::forcedNamesIn(self::distConfiguration());
    }

    /**
     * L'empreinte de chaque valeur forcée, telle que la lit un noyau : son
     * environnement pour APP_ENV, et pour les autres la méthode par laquelle
     * le conteneur compilé résout `%env(NOM)%` (`Container::getEnv()`,
     * protégée : lue par réflexion, plutôt que recopier l'ordre `$_ENV`,
     * `$_SERVER`, getenv() de l'EnvVarProcessor).
     *
     * @return array<string, string> nom => sha256 de la valeur, ou « non-chaîne »
     */
    public static function fingerprints(string $environment, ContainerInterface $container): array
    {
        self::assertInstanceOf(Container::class, $container);
        $getEnv = new ReflectionMethod(Container::class, 'getEnv');
        $fingerprints = [];
        foreach (self::forcedNames() as $name) {
            $value = 'APP_ENV' === $name ? $environment : $getEnv->invoke($container, $name);
            $fingerprints[$name] = \is_string($value) ? hash('sha256', $value) : 'non-chaîne';
        }

        return $fingerprints;
    }

    private static function distConfiguration(): SimpleXMLElement
    {
        return new SimpleXMLElement(RepositoryFiles::read(RepositoryFiles::backend('phpunit.dist.xml')));
    }

    /**
     * @return list<string>
     */
    private static function forcedNamesIn(SimpleXMLElement $configuration): array
    {
        return array_values(array_unique(self::valuesAt($configuration, '/phpunit/php/server[@force="true"]/@name')));
    }

    /**
     * Ce qui manque pour qu'un nom forcé par `<server>` le soit aussi chez
     * Symfony, qui lit `$_ENV` d'abord : un seul `<server>`, et un
     * `<env force>` de même valeur. Un message par nom, qui le nomme sans
     * citer de valeur.
     *
     * @return list<string>
     */
    private function envForceDefects(SimpleXMLElement $configuration): array
    {
        $defects = [];
        foreach (self::forcedNamesIn($configuration) as $name) {
            $server = self::valuesAt($configuration, '/phpunit/php/server[@name="'.$name.'"]/@value');
            $env = self::valuesAt($configuration, '/phpunit/php/env[@name="'.$name.'"][@force="true"]/@value');
            if (1 !== \count($server)) {
                $defects[] = $name.' : posé par plusieurs <server>.';
            } elseif ([] === $env) {
                $defects[] = $name.' : aucun <env force> ne le pose.';
            } elseif ([] !== array_diff($env, $server)) {
                $defects[] = $name.' : son <env force> ne porte pas la valeur de son <server>.';
            }
        }

        return $defects;
    }

    /**
     * L'empreinte de la valeur que pose le `<server>` de chaque nom forcé.
     *
     * @return array<string, string>
     */
    private function serverFingerprints(SimpleXMLElement $configuration): array
    {
        $fingerprints = [];
        foreach (self::forcedNamesIn($configuration) as $name) {
            $server = self::valuesAt($configuration, '/phpunit/php/server[@name="'.$name.'"]/@value');
            $fingerprints[$name] = hash('sha256', $server[0] ?? '');
        }

        return $fingerprints;
    }

    /**
     * @return list<string> les valeurs des attributs que désigne le chemin
     */
    private static function valuesAt(SimpleXMLElement $configuration, string $path): array
    {
        $attributes = $configuration->xpath($path);

        return \is_array($attributes) ? array_values(array_map(strval(...), $attributes)) : [];
    }

    /**
     * @return array<array-key, mixed> ce que le fils écrit : nom => empreinte
     */
    private function childFingerprints(): array
    {
        $child = ChildPhpProcess::inTestEnvironment(self::TIMEOUT_SECONDS, self::CHILD);
        $child->run();
        self::assertSame(0, $child->getExitCode(), 'Le fils ne rend pas ses empreintes : '.$child->getErrorOutput());
        $read = json_decode($child->getOutput(), true);
        self::assertIsArray($read, 'Le fils n\'écrit pas un objet JSON.');

        return $read;
    }
}
