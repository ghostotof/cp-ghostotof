<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Lock;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Une session dont le client a disparu sans fermer son socket libère ses
 * verrous en moins de deux minutes (issue #279).
 *
 * Le verrou des limiteurs est un advisory lock PostgreSQL **de session** (#272).
 * Un pod perdu sans FIN — nœud tombé, partition réseau — laisse sa session
 * ouverte côté serveur, donc le verrou tenu, jusqu'à ce que le keepalive TCP
 * la déclare morte : 7 200 s d'inactivité avant la première sonde avec les
 * réglages Linux par défaut. `lock_timeout=5s` borne l'attente des autres
 * (#272, #525), pas la vie du verrou : la clé répondait 500 pendant deux heures.
 *
 * Les keepalives côté serveur ferment la session au bout de
 * `idle + interval × count` secondes sans réponse du client. Mesuré sur
 * l'image du dépôt, client coupé du réseau sans FIN : verrou encore tenu au
 * bout de 202 s sans ces paramètres, libéré en 96 s avec eux (90 s attendues,
 * relevé toutes les 5 s).
 *
 * Les paramètres sont passés en ligne de commande au serveur, `-c clé=valeur` :
 * pas de fichier de configuration à monter pour trois réglages. Le dev n'en a
 * pas besoin : un conteneur tué ferme ses sockets, le serveur reçoit le FIN.
 */
final class PostgresGhostSessionReleaseTest extends TestCase
{
    /** Délai au-delà duquel une clé de limiteur reste trop longtemps inutilisable. */
    private const int MAX_RELEASE_SECONDS = 120;

    /**
     * @return iterable<string, array{string}>
     */
    public static function keepaliveSettings(): iterable
    {
        yield 'tcp_keepalives_idle' => ['tcp_keepalives_idle'];
        yield 'tcp_keepalives_interval' => ['tcp_keepalives_interval'];
        yield 'tcp_keepalives_count' => ['tcp_keepalives_count'];
    }

    /**
     * Zéro veut dire « valeur du système », c'est-à-dire les deux heures que ce
     * test écarte : chaque réglage est un entier strictement positif.
     */
    #[DataProvider('keepaliveSettings')]
    public function testTheClusterServerSetsEveryKeepalive(string $setting): void
    {
        $settings = $this->settings($this->clusterServerArgs());

        self::assertArrayHasKey($setting, $settings, \sprintf('k8s/base/postgres.yaml : « -c %s=… » manque aux args du serveur (issue #279).', $setting));
        self::assertMatchesRegularExpression('/^[1-9]\d*\z/', $settings[$setting], \sprintf('%s doit être un nombre de secondes (ou de sondes) strictement positif.', $setting));
    }

    public function testAGhostSessionIsDeclaredDeadInTime(): void
    {
        $settings = $this->settings($this->clusterServerArgs());
        $release = (int) ($settings['tcp_keepalives_idle'] ?? 0)
            + (int) ($settings['tcp_keepalives_interval'] ?? 0) * (int) ($settings['tcp_keepalives_count'] ?? 0);

        self::assertGreaterThan(0, $release);
        self::assertLessThanOrEqual(self::MAX_RELEASE_SECONDS, $release, \sprintf('Une session fantôme garderait ses verrous %d s.', $release));
    }

    /**
     * Les args du conteneur `postgres` : la commande de l'image (`postgres`)
     * suivie de ses options, l'entrypoint officiel gardant son initialisation.
     *
     * @return list<string>
     */
    private function clusterServerArgs(): array
    {
        $path = \dirname(__DIR__, 5).'/k8s/base/postgres.yaml';
        self::assertFileIsReadable($path);
        $documents = preg_split('/^---\s*$/m', (string) file_get_contents($path));
        self::assertIsArray($documents);

        foreach ($documents as $document) {
            $resource = Yaml::parse($document);
            if (!\is_array($resource) || 'Deployment' !== ($resource['kind'] ?? null)) {
                continue;
            }
            $spec = $resource['spec'] ?? null;
            $template = \is_array($spec) ? ($spec['template'] ?? null) : null;
            $podSpec = \is_array($template) ? ($template['spec'] ?? null) : null;
            $containers = \is_array($podSpec) ? ($podSpec['containers'] ?? null) : null;
            self::assertIsArray($containers);

            foreach ($containers as $container) {
                if (\is_array($container) && 'postgres' === ($container['name'] ?? null)) {
                    self::assertArrayNotHasKey('command', $container, 'Un `command` remplacerait l\'entrypoint de l\'image, qui initialise la base.');

                    return $this->stringList($container['args'] ?? null, 'k8s/base/postgres.yaml, args du conteneur postgres');
                }
            }
        }

        self::fail('Conteneur postgres introuvable dans k8s/base/postgres.yaml.');
    }

    /**
     * Les paires `-c clé=valeur` d'une ligne de commande du serveur, qui doit
     * commencer par `postgres` et ne porter que de telles paires.
     *
     * @param list<string> $args
     *
     * @return array<string, string>
     */
    private function settings(array $args): array
    {
        self::assertSame('postgres', $args[0] ?? null, 'La ligne de commande commence par `postgres`, la commande de l\'image.');

        $settings = [];
        $options = \array_slice($args, 1);
        self::assertSame(0, \count($options) % 2, 'Chaque option du serveur est une paire « -c », « clé=valeur ».');
        foreach (array_chunk($options, 2) as [$flag, $pair]) {
            self::assertSame('-c', $flag);
            self::assertSame(1, preg_match('/^([a-z_]+)=(\S+)\z/', $pair, $matches), \sprintf('Option illisible : « %s ».', $pair));
            $settings[$matches[1]] = $matches[2];
        }

        return $settings;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value, string $where): array
    {
        self::assertIsArray($value, \sprintf('%s : une liste est attendue (issue #279).', $where));
        self::assertTrue(array_is_list($value), \sprintf('%s : une liste est attendue.', $where));
        $strings = array_values(array_filter($value, \is_string(...)));
        self::assertSame($value, $strings, \sprintf('%s : chaque élément est une chaîne.', $where));

        return $strings;
    }
}
