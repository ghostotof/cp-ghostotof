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
 * Q1 de la dix-huitième relecture). Une vraie clé d'API exportée dans le
 * conteneur ne doit gagner ni chez l'un ni chez l'autre : un test qui
 * atteindrait l'API réelle échouerait en 401, filet voulu, tant que la clé lue
 * est la factice.
 *
 * Verte dans la suite ordinaire, où rien n'exporte ces noms : c'est
 * ForcedTestValuesWinOverTheShellTest qui la relance sous un shell qui
 * exporte une valeur factice aléatoire pour chacun d'eux, et c'est là qu'elle
 * rougit si le shell gagne.
 *
 * Comparées par empreinte (sha256) : un échec n'écrit jamais une valeur.
 */
final class ForcedTestValuesProbeTest extends KernelTestCase
{
    /**
     * Les noms que phpunit.dist.xml force, par `<server>` et `<env force>` :
     * la seule liste, que lit le test du shell.
     */
    public const array NAMES = [
        'APP_ENV',
        'CONTACT_SENDER_EMAIL',
        'CONTACT_RECIPIENT_EMAIL',
        'ANTHROPIC_API_KEY',
        'SCALEWAY_AI_API_KEY',
        'SCALEWAY_AI_PROJECT_ID',
    ];

    private const string CHILD = __DIR__.'/Fixtures/ForcedTestValues/print-fingerprints.php';

    private const int TIMEOUT_SECONDS = 60;

    public function testTheKernelAndAChildReadTheValuesThatPhpunitDistForces(): void
    {
        $expected = $this->distFingerprints();
        // APP_ENV : l'environnement que KernelTestCase choisit (createKernel(),
        // `$_ENV` d'abord), lu sans démarrer le noyau, qu'un APP_ENV piégé
        // compilerait sous un nom aléatoire.
        $environment = self::createKernel()->getEnvironment();
        // Les autres noms : lus par le conteneur du noyau de test, comme il
        // résout `%env(NOM)%`. Démarré en `test` explicitement : le choix de
        // l'environnement est le cas d'APP_ENV, pas le leur.
        $parent = self::fingerprints($environment, self::bootKernel(['environment' => 'test'])->getContainer());
        $child = $this->childFingerprints();

        $mismatches = [];
        foreach (self::NAMES as $name) {
            foreach (['noyau du parent' => $parent, 'fils' => $child] as $reader => $read) {
                if (($read[$name] ?? null) !== $expected[$name]) {
                    $found = $read[$name] ?? 'absente';
                    $mismatches[] = \sprintf('%s, %s : empreinte %s, attendue %s', $name, $reader, $found, $expected[$name]);
                }
            }
        }

        self::assertSame([], $mismatches, 'Une valeur lue n\'est pas celle que phpunit.dist.xml force (empreintes seules).');
    }

    /**
     * L'empreinte de chaque valeur forcée, telle que la lit un noyau : son
     * environnement pour APP_ENV, et pour les autres la méthode par laquelle
     * le conteneur compilé résout `%env(NOM)%` (`Container::getEnv()`,
     * protégée : lue par réflexion, plutôt que recopier l'ordre `$_ENV`,
     * `$_SERVER`, getenv() de l'EnvVarProcessor). Partagée par le fils
     * (Fixtures/ForcedTestValues/print-fingerprints.php).
     *
     * @return array<string, string> nom => sha256 de la valeur, ou « non-chaîne »
     */
    public static function fingerprints(string $environment, ContainerInterface $container): array
    {
        self::assertInstanceOf(Container::class, $container);
        $getEnv = new ReflectionMethod(Container::class, 'getEnv');
        $fingerprints = [];
        foreach (self::NAMES as $name) {
            $value = 'APP_ENV' === $name ? $environment : $getEnv->invoke($container, $name);
            $fingerprints[$name] = \is_string($value) ? hash('sha256', $value) : 'non-chaîne';
        }

        return $fingerprints;
    }

    /**
     * L'empreinte de la valeur que phpunit.dist.xml pose pour chaque nom, par
     * son `<server>` ; un `<env force>` du même nom doit porter la même.
     *
     * @return array<string, string>
     */
    private function distFingerprints(): array
    {
        $configuration = new SimpleXMLElement(RepositoryFiles::read(RepositoryFiles::backend('phpunit.dist.xml')));
        $fingerprints = [];
        foreach (self::NAMES as $name) {
            $server = $this->valuesAt($configuration, '/phpunit/php/server[@name="'.$name.'"]/@value');
            self::assertCount(1, $server, $name.' : phpunit.dist.xml doit le poser par un seul <server>.');
            $env = $this->valuesAt($configuration, '/phpunit/php/env[@name="'.$name.'"][@force="true"]/@value');
            self::assertSame([], array_diff($env, $server), $name.' : son <env force> ne porte pas la valeur de son <server>.');
            $fingerprints[$name] = hash('sha256', $server[0]);
        }

        return $fingerprints;
    }

    /**
     * @return list<string> les valeurs des attributs que désigne le chemin
     */
    private function valuesAt(SimpleXMLElement $configuration, string $path): array
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
