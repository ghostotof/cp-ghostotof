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
 * échec qui le nomme. Tout autre nom de `<server>` ou d'`<env>` du fichier
 * figure, sous sa forme exacte et avec sa raison, dans
 * {@see self::NOT_FORCED_ON_BOTH_SIDES} ; un nom hors de cette liste, ou sous
 * une autre forme, est aussi un échec qui le nomme (R242 de la vingtième
 * relecture).
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
     * Les noms de phpunit.dist.xml qui ne sont pas forcés des deux côtés, à
     * dessein, chacun avec la forme exacte sous laquelle le fichier le pose
     * (R242 de la vingtième relecture, C20-1) : un nom hors de cette liste,
     * ou posé sous une autre forme, est un échec qui le nomme.
     */
    private const array NOT_FORCED_ON_BOTH_SIDES = [
        // La verbosité de la console : le shell peut la régler, elle n'ouvre rien.
        'SHELL_VERBOSITY' => '<server>',
        // Une base d'URL publique, forcée à vide dans `$_ENV`, ce que lit Symfony ;
        // `$_SERVER` garde celle du conteneur de dev, ChildPhpProcess donne aux fils celle de `$_ENV`.
        'APP_PUBLIC_API_BASE_URL' => '<env force>',
        // Le nom de la garde D8 : la CI le remplace par son secret, qu'un `force` écraserait.
        'MCP_D8_AUTHOR_NAME' => '<env>',
    ];

    /**
     * Une configuration de test : un nom forcé des deux côtés, un `<server>`
     * sans `force`, un nom dont l'`<env force>` porte une autre valeur, un nom
     * posé par deux `<server>`, et, en dernier, un nom dont l'`<env>` n'est pas
     * forcé.
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
                <server name="SERVER_TWICE" value="f" force="true" />
                <server name="SERVER_TWICE" value="f" force="true" />
                <env name="SERVER_TWICE" value="f" force="true" />
                <server name="ENV_NOT_FORCED" value="e" force="true" />
                <env name="ENV_NOT_FORCED" value="e" />
            </php>
        </phpunit>
        XML;

    /**
     * Une configuration de test pour la liste d'autorisation : un nom forcé
     * des deux côtés, deux noms admis sous leur forme, un nom admis sous une
     * autre forme, et quatre noms non forcés hors de la liste.
     */
    private const string CONFIGURATION_WITH_UNFORCED_NAMES = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <phpunit>
            <php>
                <server name="FORCED_BOTH_SIDES" value="a" force="true" />
                <env name="FORCED_BOTH_SIDES" value="a" force="true" />
                <server name="ALLOWED_SERVER" value="b" />
                <env name="ALLOWED_ENV_FORCE" value="" force="true" />
                <env name="ALLOWED_BUT_UNFORCED" value="c" />
                <server name="SERVER_ONLY" value="d" />
                <env name="ENV_FORCE_ONLY" value="e" force="true" />
                <env name="ENV_ONLY" value="f" />
                <server name="SERVER_AND_ENV_FORCE" value="g" />
                <env name="SERVER_AND_ENV_FORCE" value="g" force="true" />
            </php>
        </phpunit>
        XML;

    /**
     * La liste d'autorisation de la configuration de test : deux formes
     * justes, une fausse, et un nom que la configuration ne pose pas.
     */
    private const array ALLOWED_IN_TEST_CONFIGURATION = [
        'ALLOWED_SERVER' => '<server>',
        'ALLOWED_ENV_FORCE' => '<env force>',
        'ALLOWED_BUT_UNFORCED' => '<env force>',
        'ALLOWED_ABSENT' => '<env>',
    ];

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

        $mismatches = [
            ...$this->envForceDefects($configuration),
            ...$this->unforcedNameDefects($configuration, self::NOT_FORCED_ON_BOTH_SIDES),
        ];
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

        self::assertSame(
            ['FORCED_BOTH_SIDES', 'ENV_FORCE_DIVERGES', 'SERVER_TWICE', 'ENV_NOT_FORCED'],
            self::forcedNamesIn($configuration),
        );
        self::assertSame(
            [
                'ENV_FORCE_DIVERGES : son <env force> ne porte pas la valeur de son <server>.',
                'SERVER_TWICE : posé par plusieurs <server>.',
                'ENV_NOT_FORCED : aucun <env force> ne le pose.',
            ],
            $this->envForceDefects($configuration),
        );
    }

    public function testEveryNameNotForcedOnBothSidesIsAllowListedUnderItsExactForm(): void
    {
        $configuration = new SimpleXMLElement(self::CONFIGURATION_WITH_UNFORCED_NAMES);

        self::assertSame(
            [
                'ALLOWED_BUT_UNFORCED : posé par <env>, la liste d\'autorisation admet <env force>.',
                'SERVER_ONLY : posé par <server>, ni forcé des deux côtés ni dans la liste d\'autorisation.',
                'ENV_FORCE_ONLY : posé par <env force>, ni forcé des deux côtés ni dans la liste d\'autorisation.',
                'ENV_ONLY : posé par <env>, ni forcé des deux côtés ni dans la liste d\'autorisation.',
                'SERVER_AND_ENV_FORCE : posé par <server> + <env force>, ni forcé des deux côtés ni dans la liste d\'autorisation.',
                'ALLOWED_ABSENT : dans la liste d\'autorisation, absent de la configuration.',
            ],
            $this->unforcedNameDefects($configuration, self::ALLOWED_IN_TEST_CONFIGURATION),
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
     * Ce qui sort de la liste d'autorisation : un nom de `<server>` ou d'`<env>`
     * qui n'est pas forcé par un `<server force>` et n'y figure pas, un nom qui
     * y figure sous une autre forme que celle du fichier (forcé depuis, ou
     * `force` retiré), et une entrée dont la configuration ne pose plus le nom.
     * Un message par nom, qui le nomme avec sa forme, sans citer de valeur.
     *
     * @param array<string, string> $allowed nom => forme admise, par exemple `<env force>`
     *
     * @return list<string>
     */
    private function unforcedNameDefects(SimpleXMLElement $configuration, array $allowed): array
    {
        $forced = self::forcedNamesIn($configuration);
        $defects = [];
        $names = array_unique(self::valuesAt($configuration, '/phpunit/php/server/@name | /phpunit/php/env/@name'));
        foreach ($names as $name) {
            $form = $this->formOf($configuration, $name);
            if (!\array_key_exists($name, $allowed)) {
                if (!\in_array($name, $forced, true)) {
                    $defects[] = $name.' : posé par '.$form.', ni forcé des deux côtés ni dans la liste d\'autorisation.';
                }
            } elseif ($allowed[$name] !== $form) {
                $defects[] = $name.' : posé par '.$form.', la liste d\'autorisation admet '.$allowed[$name].'.';
            }
        }
        foreach (array_keys(array_diff_key($allowed, array_flip($names))) as $name) {
            $defects[] = $name.' : dans la liste d\'autorisation, absent de la configuration.';
        }

        return $defects;
    }

    /**
     * Les éléments qui posent le nom, dans l'ordre du fichier : `<server>`,
     * `<server force>`, `<env>`, `<env force>`, joints par « + ».
     */
    private function formOf(SimpleXMLElement $configuration, string $name): string
    {
        $elements = $configuration->xpath('/phpunit/php/*[(self::server or self::env) and @name="'.$name.'"]');
        $forms = [];
        foreach (\is_array($elements) ? $elements : [] as $element) {
            $forms[] = '<'.$element->getName().('true' === (string) $element['force'] ? ' force' : '').'>';
        }

        return implode(' + ', $forms);
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
