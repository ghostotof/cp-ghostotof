<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger;

use App\Shared\Application\Message\MessengerProbeMessage;
use App\Shared\Application\Message\QueuedMessageInterface;
use App\Tests\Support\DeclaredClasses;
use App\Tests\Support\EnvironmentConfigKernel;
use App\Tests\Support\PhpSources;
use App\Tests\Support\RepositoryFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Tout message que le bus publie dans une file nomme l'exception de son
 * contexte (issue #491) : il implémente {@see QueuedMessageInterface}, que
 * {@see \App\Shared\Infrastructure\Messenger\QueueUnavailableMiddleware}
 * lève quand la file ne le prend pas. Sans elle, un nouveau producteur
 * renverrait le 500 `critical` générique de la
 * {@see \Symfony\Component\Messenger\Exception\TransportException}, que #485
 * a supprimé, et rien ne rougirait.
 *
 * La garde porte sur le routage, pas sur la forme du code des producteurs.
 * Messenger choisit les transports d'un message de trois façons
 * ({@see \Symfony\Component\Messenger\Transport\Sender\SendersLocator}), et
 * chacune est lue :
 *
 *  - `framework.messenger.routing` : chaque clé envoyée vers un transport
 *    publiant (tout DSN autre que `sync://`, `async` comme `failed`) doit être
 *    une classe ou une interface sous-type de QueuedMessageInterface. Un
 *    joker, d'espace de noms ou universel (`*`), est refusé : les messages
 *    qu'il atteint ne se listent pas ;
 *  - `#[AsMessage(transport: …)]`, que SendersLocator lit à l'envoi sur la
 *    classe, ses parents et ses interfaces : toute déclaration de `src/` qui
 *    le porte vers un transport publiant obéit à la même règle ;
 *  - un `TransportNamesStamp` passé à `dispatch()` l'emporte sur les deux :
 *    il ne se lit qu'à l'exécution, donc `src/` ne le nomme jamais (lecture
 *    par jetons, commentaires exclus). Sans liste d'exceptions : un message
 *    se route par la configuration ou par son attribut, gardés ici.
 *
 * Seule issue pour un message publié sans conversion, une entrée justifiée de
 * NO_CONVERSION. La configuration déclarée est lue dans **chaque**
 * environnement que le noyau admet ({@see EnvironmentConfigKernel}, sans
 * compiler), comme SendEmailMessageStaysSynchronousTest : préprod et prod
 * tournent en `prod`, et le DSN d'un transport, donc le fait qu'il publie,
 * peut changer de l'un à l'autre.
 */
final class QueuedMessagesNameTheirQueueFailureTest extends TestCase
{
    private const string FIXTURE_PROJECT = __DIR__.'/Fixtures/AsyncRoutingProject';
    private const string FIXTURE_CLASSES = __DIR__.'/Fixtures';

    /**
     * Messages publiés sans conversion, chacun justifié.
     */
    private const array NO_CONVERSION = [
        MessengerProbeMessage::class => 'Témoin du smoke préprod (#470), publié par app:messenger:probe, jamais depuis une requête HTTP : la commande rattrape elle-même la TransportException pour la citer dans les journaux du Job.',
    ];

    public function testEveryPublishedMessageOfTheApplicationNamesItsQueueFailure(): void
    {
        self::assertSame([], $this->unconvertedRoutings(null, RepositoryFiles::backend('src')), 'Un message routé vers une file doit implémenter QueuedMessageInterface (issue #491), sinon une panne du broker redevient un 500 critical.');
    }

    /**
     * Une garde qui ne verrait rien serait verte à vide : chaque forme de
     * routage fautive de la fixture, dans son environnement, doit ressortir,
     * et aucune des formes admises.
     */
    public function testEveryFormOfUnconvertedRoutingIsCaughtInItsEnvironment(): void
    {
        self::assertSame([
            'dev : #[AsMessage] App\Tests\Shared\Infrastructure\Messenger\Fixtures\AttributeRoutedMessage → async',
            'dev : App\Tests\Shared\Infrastructure\Messenger\Fixtures\* → async',
            'dev : App\Tests\Shared\Infrastructure\Messenger\Fixtures\UnconvertedQueuedMessage → async',
            'prod : #[AsMessage] App\Tests\Shared\Infrastructure\Messenger\Fixtures\AttributeRoutedMessage → async',
            'prod : App\Tests\Shared\Infrastructure\Messenger\Fixtures\UnconvertedQueuedMessage → failed',
            'test : #[AsMessage] App\Tests\Shared\Infrastructure\Messenger\Fixtures\AttributeRoutedMessage → async',
            'test : * → async',
        ], $this->unconvertedRoutings(self::FIXTURE_PROJECT, self::FIXTURE_CLASSES));
    }

    /**
     * Une exception qui n'excuse plus rien se retire : le message est encore
     * routé vers une file, et ne nomme toujours aucune exception.
     */
    public function testEveryJustifiedExceptionIsStillNeeded(): void
    {
        $published = [];
        foreach (EnvironmentConfigKernel::allowedEnvironments() as $environment) {
            foreach ($this->publishingRoutes($environment, null, RepositoryFiles::backend('src')) as [$message]) {
                $published[] = $message;
            }
        }

        foreach (array_keys(self::NO_CONVERSION) as $message) {
            // class_implements() plutôt que is_a() : PHPStan juge ce dernier d'avance
            // sur une clé littérale, alors que le test doit lire la classe telle qu'elle est.
            self::assertNotContains(QueuedMessageInterface::class, class_implements($message), \sprintf('%s nomme son exception : le retirer de NO_CONVERSION.', $message));
            self::assertContains($message, $published, \sprintf('%s n\'est plus publié dans une file : le retirer de NO_CONVERSION.', $message));
        }
    }

    public function testNoSourceChoosesItsTransportAtDispatch(): void
    {
        $sources = [];
        foreach (PhpSources::files(RepositoryFiles::backend('src')) as $path) {
            $sources[$path] = RepositoryFiles::read($path);
        }

        self::assertSame([], $this->transportNamesStamps($sources), 'Un TransportNamesStamp route le message hors de la garde (issue #491) : router par framework.messenger.routing ou par #[AsMessage].');
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function stampSnippets(): iterable
    {
        $header = "<?php\nnamespace App\\X;\n";

        yield 'import' => [$header."use Symfony\\Component\\Messenger\\Stamp\\TransportNamesStamp;\n", 1];
        yield 'nom complet' => [$header."\$bus->dispatch(\$m, [new \\Symfony\\Component\\Messenger\\Stamp\\TransportNamesStamp(['async'])]);", 1];
        yield 'nom court' => [$header."\$bus->dispatch(\$m, [new TransportNamesStamp('async')]);", 1];
        yield 'alias' => [$header."use Symfony\\Component\\Messenger\\Stamp\\TransportNamesStamp as Route;\n", 1];
        yield 'commentaire' => [$header."// new TransportNamesStamp('async')\n/** {@see TransportNamesStamp} */\n", 0];
        yield 'chaîne' => [$header."\$doc = 'TransportNamesStamp';", 0];
        yield 'autre tampon' => [$header."\$bus->dispatch(\$m, [new DelayStamp(10)]);", 0];
    }

    /** Le détecteur lui-même : sans cette preuve, il pourrait être vert pour de mauvaises raisons. */
    #[DataProvider('stampSnippets')]
    public function testTheStampDetector(string $code, int $expected): void
    {
        self::assertCount($expected, $this->transportNamesStamps(['extrait.php' => $code]));
    }

    /**
     * @return list<string> « env : clé → transport » publiant sans conversion, triées
     */
    private function unconvertedRoutings(?string $projectDirectory, string $classesDirectory): array
    {
        $found = [];
        foreach (EnvironmentConfigKernel::allowedEnvironments() as $environment) {
            foreach ($this->publishingRoutes($environment, $projectDirectory, $classesDirectory) as [$message, $route, $transport]) {
                if (!is_a($message, QueuedMessageInterface::class, true) && !\array_key_exists($message, self::NO_CONVERSION)) {
                    $found[] = \sprintf('%s : %s → %s', $environment, $route, $transport);
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * Les routes d'un environnement dont le transport publie : tout transport
     * déclaré hors `sync://`, et tout nom qui n'est pas un transport déclaré
     * (un service émetteur), par prudence. Celles de la configuration, puis
     * celles des attributs `#[AsMessage]` des déclarations du répertoire.
     *
     * @return list<array{string, string, string}> (message ou clé, route lisible, transport)
     */
    private function publishingRoutes(string $environment, ?string $projectDirectory, string $classesDirectory): array
    {
        $messenger = new EnvironmentConfigKernel($environment, $projectDirectory)->declaredMapping('framework', 'messenger');
        $routing = $messenger['routing'] ?? [];
        $transports = $messenger['transports'] ?? [];
        self::assertIsArray($routing);
        self::assertIsArray($transports);

        $routes = [];
        foreach ($routing as $key => $senders) {
            self::assertIsArray($senders);
            foreach ($senders as $sender) {
                self::assertIsString($sender);
                $routes[] = [(string) $key, (string) $key, $sender];
            }
        }
        foreach ($this->attributeRoutes($classesDirectory) as [$class, $transport]) {
            $routes[] = [$class, '#[AsMessage] '.$class, $transport];
        }

        return array_values(array_filter(
            $routes,
            fn (array $route): bool => !$this->isSynchronous($transports[$route[2]] ?? null),
        ));
    }

    /**
     * Interfaces et classes abstraites comprises : SendersLocator lit
     * l'attribut sur les parents et les interfaces du message.
     *
     * @return list<array{class-string, string}>
     */
    private function attributeRoutes(string $directory): array
    {
        $routes = [];
        foreach (DeclaredClasses::classLikes($directory) as $class) {
            foreach (new ReflectionClass($class)->getAttributes(AsMessage::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                foreach ((array) $attribute->newInstance()->transport as $transport) {
                    self::assertIsString($transport);
                    $routes[] = [$class, $transport];
                }
            }
        }

        return $routes;
    }

    private function isSynchronous(mixed $transport): bool
    {
        $dsn = \is_array($transport) ? ($transport['dsn'] ?? null) : $transport;

        return \is_string($dsn) && str_starts_with($dsn, 'sync://');
    }

    /**
     * @param array<string, string> $sources chemin → code
     *
     * @return list<string> « chemin:ligne » de chaque nom de code qui désigne TransportNamesStamp
     */
    private function transportNamesStamps(array $sources): array
    {
        $found = [];
        foreach ($sources as $path => $code) {
            foreach (PhpSources::significantTokens($code) as $token) {
                // Le dernier segment du nom : `TransportNamesStamp`, qualifié ou non.
                $segments = explode('\\', $token->text);
                if ($token->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED])
                    && 'TransportNamesStamp' === array_last($segments)) {
                    $found[] = \sprintf('%s:%d', $path, $token->line);
                }
            }
        }

        return $found;
    }
}
