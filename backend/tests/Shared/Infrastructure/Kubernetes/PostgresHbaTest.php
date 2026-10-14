<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Kubernetes;

use App\Tests\Support\KubernetesManifests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le superutilisateur PostgreSQL n'est joignable que de l'intérieur du pod
 * `postgres` (issue #593).
 *
 * Depuis #575, l'application tourne sous `cpg_app` et les migrations sous
 * `cpg_migrator` ; le rôle bootstrap de l'image (`POSTGRES_USER`) n'a plus
 * d'usage réseau. Le `pg_hba.conf` de l'image lui ouvrait pourtant le TCP
 * depuis tout pod du namespace (`host all all all scram-sha-256`) : une fuite
 * de son mot de passe rendait ce que #575 retire. Le dépôt monte le sien,
 * `k8s/base/postgres-pg_hba.conf`, qui admet par le réseau les deux rôles
 * applicatifs **nommés** et refuse tout le reste — jamais par exclusion du
 * rôle bootstrap, dont le nom est dans un Secret, pas dans le dépôt. Ces deux
 * rôles n'atteignent que la base de l'application : celles que l'image crée
 * leur sont refusées par une règle placée avant (issue #597).
 *
 * Le socket local garde la confiance de l'image (sondes `pg_isready`,
 * entrypoint, procédure des rôles du runbook). Le loopback, lui, ne fait
 * confiance qu'au lecteur `cpg_reader` (le `SHOW` des keepalives par
 * `-h 127.0.0.1`, k8s/README.md) : un `kubectl port-forward` ou un conteneur
 * éphémère (`kubectl debug`) arrive aussi par 127.0.0.1, et `host all all
 * 127.0.0.1/32 trust`, la règle de l'image, y donnait le superutilisateur sans
 * mot de passe (relecture de #593).
 *
 * Ce test lit le fichier et son câblage ; tools/check-postgres-hba.sh (CI
 * `postgres-hba`) démarre l'image épinglée avec le fichier et mesure les refus.
 */
final class PostgresHbaTest extends TestCase
{
    private const string HBA_SOURCE = 'postgres-pg_hba.conf';

    private const string CONFIG_MAP = 'postgres-hba';

    private const string MOUNT_PATH = '/etc/postgresql/pg_hba.conf';

    /** Les seuls rôles admis par le réseau : ceux dont une URL est publiée. */
    private const array NETWORK_ROLES = ['cpg_app', 'cpg_migrator'];

    /** Le loopback : l'intérieur du pod, un port-forward ou un conteneur éphémère. */
    private const array LOOPBACK = ['127.0.0.1/32', '::1/128'];

    /** Le seul rôle admis sans mot de passe sur le loopback. */
    private const string LOOPBACK_ROLE = 'cpg_reader';

    /** Les types d'enregistrement qui portent une connexion TCP. */
    private const array TCP_TYPES = ['host', 'hostssl', 'hostnossl', 'hostgssenc', 'hostnogssenc'];

    /**
     * Les bases que l'image crée à côté de celle de l'application, triées
     * (issue #597). `PUBLIC` y garde `CONNECT` et `TEMP` ; `template0` refuse
     * déjà les connexions (`datallowconn`), nommée pour ne rien laisser au
     * catalogue.
     */
    private const array IMAGE_DATABASES = ['postgres', 'template0', 'template1'];

    public function testTheLastRuleRejectsEveryOtherTcpConnection(): void
    {
        $rules = $this->rules();

        self::assertSame(['host', 'all', 'all', 'all', 'reject'], array_last($rules), 'La dernière règle doit refuser toute autre connexion TCP : PostgreSQL refuse déjà ce qu\'aucune règle ne couvre, mais une règle finale explicite garde ce refus si une ligne est ajoutée au-dessus.');
    }

    public function testTheLocalSocketKeepsTheImageTrust(): void
    {
        self::assertSame(['local', 'all', 'all', 'trust'], $this->rules()[0] ?? null, 'Le socket local reste en `trust` : sondes `pg_isready`, entrypoint de l\'image, procédure des rôles et psql du runbook.');
    }

    /**
     * Toute règle TCP qui accepte : soit le loopback (l'intérieur du pod), soit
     * un des deux rôles nommés, par mot de passe SCRAM. Jamais `all`, un groupe
     * (`+…`), un fichier (`@…`) ni une expression (`/…`), qui pourraient
     * désigner le rôle bootstrap.
     *
     * @param list<string> $rule
     */
    #[DataProvider('acceptingTcpRules')]
    public function testAnAcceptingTcpRuleAdmitsOnlyTheLoopbackOrANamedApplicationRole(array $rule): void
    {
        [, , $users, $address, $method] = $rule + [null, null, null, null, null];

        if (\in_array($address, self::LOOPBACK, true)) {
            // cpg_reader n'a pas de mot de passe : le loopback lui reste en `trust`, à lui seul.
            self::assertSame('trust', $method, \sprintf('« %s » : le loopback admet cpg_reader sans mot de passe.', implode(' ', $rule)));
            self::assertSame(self::LOOPBACK_ROLE, $users, \sprintf('« %s » : sur le loopback, `trust` pour %s seul — un port-forward y arrive aussi.', implode(' ', $rule), self::LOOPBACK_ROLE));

            return;
        }

        self::assertSame('scram-sha-256', $method, \sprintf('« %s » : hors du loopback, une règle TCP n\'accepte que par SCRAM.', implode(' ', $rule)));
        self::assertIsString($users);
        $admitted = explode(',', $users);
        self::assertSame([], array_diff($admitted, self::NETWORK_ROLES), \sprintf('« %s » : seuls %s sont admis par le réseau.', implode(' ', $rule), implode(', ', self::NETWORK_ROLES)));
    }

    /**
     * Le jeu de données n'est jamais vide : sans règle pour cpg_app et
     * cpg_migrator, l'application et les migrations seraient coupées.
     */
    public function testBothApplicationRolesAreAdmittedOverTheNetwork(): void
    {
        $admitted = [];
        foreach (self::acceptingTcpRules() as [$rule]) {
            if (!\in_array($rule[3] ?? null, self::LOOPBACK, true)) {
                $admitted = [...$admitted, ...explode(',', $rule[2] ?? '')];
            }
        }

        sort($admitted);
        self::assertSame(self::NETWORK_ROLES, $admitted);
    }

    /**
     * Les rôles admis par TCP n'atteignent que la base de l'application
     * (issue #597). Son nom est dans un Secret, pas dans le dépôt : le fichier
     * refuse donc par leur nom les bases que l'image crée, AVANT toute règle
     * TCP qui accepte — PostgreSQL applique la première règle qui correspond.
     * Loopback compris : `cpg_reader` n'a rien à faire sur `postgres` ni
     * `template1`, où `PUBLIC` garde `TEMP`. C'est une exclusion de bases, pas
     * de rôles : une base créée plus tard par le superutilisateur lui
     * échapperait, d'où le contrôle de `pg_database` par verify.sql et le
     * `REVOKE CONNECT, TEMPORARY` de privileges.sql.
     *
     * Une règle `host` pour tous les rôles et toutes les adresses : `hostssl`
     * ou `hostnossl` laisseraient passer l'autre mode de chiffrement.
     */
    public function testTheImageDatabasesAreRejectedBeforeAnyTcpRuleAccepts(): void
    {
        $rules = $this->rules();
        $expected = self::IMAGE_DATABASES;
        sort($expected);
        $rejectPosition = array_find_key($rules, static function (array $rule) use ($expected): bool {
            $databases = explode(',', $rule[1] ?? '');
            sort($databases);

            return ['host', 'all', 'all', 'reject'] === [$rule[0], $rule[2] ?? null, $rule[3] ?? null, $rule[4] ?? null]
                && $expected === $databases;
        });
        self::assertIsInt($rejectPosition, \sprintf('Une règle « host %s all all reject » doit fermer au réseau les bases de l\'image.', implode(',', self::IMAGE_DATABASES)));

        foreach ($rules as $position => $rule) {
            if (self::acceptsOverTcp($rule)) {
                self::assertGreaterThan($rejectPosition, $position, \sprintf('« %s » : placée avant le refus des bases de l\'image, elle les ouvrirait.', implode(' ', $rule)));
            }
        }
    }

    /**
     * Rien d'autre que des règles lues ici : une inclusion (PostgreSQL 16+)
     * ajouterait des règles que ce test ne voit pas. La réplication n'a pas
     * d'usage (un seul serveur) : `all` ne la couvre pas, elle n'est donc
     * nommée que par sa propre règle de refus, jamais dans une liste.
     */
    public function testEveryRuleIsLocalOrTcpAndNothingIsIncluded(): void
    {
        foreach ($this->rules() as $rule) {
            self::assertContains($rule[0], ['local', ...self::TCP_TYPES], \sprintf('« %s » : type d\'enregistrement inattendu (inclusion ?).', implode(' ', $rule)));
            if (\in_array('replication', explode(',', $rule[1] ?? ''), true)) {
                self::assertSame(['host', 'replication', 'all', 'all', 'reject'], $rule, \sprintf('« %s » : la réplication n\'est nommée que pour être refusée.', implode(' ', $rule)));
            }
        }
    }

    /**
     * Le refus de la réplication est explicite : sans cette règle, PostgreSQL
     * la refuse déjà faute d'entrée, mais un `all` ajouté plus tard ne la
     * couvrirait pas et le refus resterait implicite.
     */
    public function testReplicationIsExplicitlyRejected(): void
    {
        self::assertContains(['host', 'replication', 'all', 'all', 'reject'], $this->rules());
    }

    /**
     * Par un `configMapGenerator` : le hachage du contenu entre dans le nom,
     * donc dans le gabarit du pod. Un changement du fichier redémarre
     * PostgreSQL, seul moyen pour un fichier monté par `subPath` d'être relu.
     */
    public function testTheFileIsGeneratedWithAContentHash(): void
    {
        $kustomization = KubernetesManifests::base()->resource('kustomization.yaml', 'Kustomization');
        $found = array_filter(
            KubernetesManifests::entries($kustomization, 'configMapGenerator'),
            static fn (array $generator): bool => self::CONFIG_MAP === ($generator['name'] ?? null),
        );
        self::assertCount(1, $found, 'configMapGenerator '.self::CONFIG_MAP.' absent.');
        $generator = array_first($found);

        self::assertSame(['pg_hba.conf='.self::HBA_SOURCE], $generator['files'] ?? null);
        self::assertArrayNotHasKey('options', $generator, 'Le hachage du nom doit rester actif.');
    }

    public function testTheServerReadsTheMountedFile(): void
    {
        $container = KubernetesManifests::base()->container('postgres.yaml', 'Deployment', 'postgres');
        $args = $container['args'] ?? null;
        self::assertIsArray($args);

        // Une paire « -c », « hba_file=… » : la forme que PostgresGhostSessionReleaseTest lit aussi.
        $pair = array_search('hba_file='.self::MOUNT_PATH, $args, true);
        self::assertIsInt($pair, 'k8s/base/postgres.yaml : « -c hba_file='.self::MOUNT_PATH.' » manque aux args du serveur.');
        self::assertSame('-c', $args[$pair - 1] ?? null);

        $mounts = array_filter(
            KubernetesManifests::entries($container, 'volumeMounts'),
            static fn (array $m): bool => self::MOUNT_PATH === ($m['mountPath'] ?? null),
        );
        self::assertCount(1, $mounts, self::MOUNT_PATH.' non monté.');
        $mount = array_first($mounts);
        self::assertSame('pg_hba.conf', $mount['subPath'] ?? null);
        self::assertTrue($mount['readOnly'] ?? false);

        $podSpec = KubernetesManifests::base()->podSpec('postgres.yaml', 'Deployment');
        $volume = KubernetesManifests::firstWith($podSpec, 'volumes', 'name', KubernetesManifests::text($mount['name'] ?? null));
        self::assertIsArray($volume);
        self::assertSame(['name' => self::CONFIG_MAP], $volume['configMap'] ?? null);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function acceptingTcpRules(): iterable
    {
        foreach (self::parse() as $rule) {
            if (self::acceptsOverTcp($rule)) {
                yield implode(' ', $rule) => [$rule];
            }
        }
    }

    /**
     * Une règle TCP qui accepte : la seule définition, partagée par le
     * fournisseur de données et le contrôle d'ordre de #597.
     *
     * @param list<string> $rule
     */
    private static function acceptsOverTcp(array $rule): bool
    {
        return \in_array($rule[0], self::TCP_TYPES, true) && 'reject' !== ($rule[4] ?? null);
    }

    /**
     * @return list<list<string>>
     */
    private function rules(): array
    {
        $rules = self::parse();
        self::assertNotSame([], $rules, 'k8s/base/'.self::HBA_SOURCE.' ne porte aucune règle.');

        return $rules;
    }

    /**
     * Les enregistrements du fichier, un par ligne, champs séparés par des
     * blancs, commentaires et lignes vides ôtés. Aucun champ entre guillemets
     * ni ligne continuée : le fichier n'en a pas besoin, et une telle ligne
     * serait lue de travers ici plutôt qu'ignorée.
     *
     * @return list<list<string>>
     */
    private static function parse(): array
    {
        $rules = [];
        foreach (explode("\n", KubernetesManifests::base()->source(self::HBA_SOURCE)) as $line) {
            $line = trim(preg_replace('/#.*/', '', $line) ?? '');
            if ('' === $line) {
                continue;
            }

            self::assertStringNotContainsString('"', $line, 'Un champ entre guillemets n\'est pas lu par ce test.');
            self::assertStringEndsNotWith('\\', $line, 'Une ligne continuée n\'est pas lue par ce test.');
            // PostgreSQL lit `a, b` comme la liste `a,b` (une virgule en fin de
            // jeton appelle le suivant) ; découpés aux blancs ici, ses champs
            // seraient décalés. Refusée plutôt que lue de travers.
            self::assertDoesNotMatchRegularExpression('/,\s|\s,/', $line, 'Une liste écrite avec des blancs autour d\'une virgule n\'est pas lue par ce test.');
            $fields = preg_split('/\s+/', $line);
            self::assertIsArray($fields);
            $rules[] = $fields;
        }

        return $rules;
    }
}
