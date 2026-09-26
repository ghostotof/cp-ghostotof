<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Infrastructure;

use App\Ai\Assistant\Domain\Exception\AssistantUnavailableException;
use App\Ai\Assistant\Domain\Exception\InvalidConversationException;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\EventListener\ErrorListener;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * L'ErrorListener du noyau journalise en `critical` toute exception qui n'est
 * pas une HttpExceptionInterface, et en `error` un 4xx : trop fort pour une
 * erreur du client ou pour la panne d'un tiers dont la cause est déjà sur le
 * canal `ai_usage`. Les niveaux vivent dans `framework.exceptions` plutôt
 * qu'en attribut sur les exceptions, pour que le domaine ne dépende pas de
 * HttpKernel. Le listener est construit avec le mapping lu dans framework.yaml,
 * comme le fait le conteneur.
 */
final class AssistantExceptionLogLevelTest extends TestCase
{
    private const string FRAMEWORK_CONFIG = __DIR__.'/../../../../config/packages/framework.yaml';

    /**
     * @return iterable<string, array{\Throwable, Level}>
     */
    public static function exceptions(): iterable
    {
        yield 'conversation refusée (422)' => [new InvalidConversationException('La conversation est vide.'), Level::Info];
        yield 'fournisseur indisponible (503)' => [new AssistantUnavailableException(), Level::Warning];
        yield 'format refusé (415)' => [new UnsupportedMediaTypeHttpException('Unsupported format.'), Level::Info];
    }

    #[DataProvider('exceptions')]
    public function testTheKernelLogsItAtTheConfiguredLevel(\Throwable $exception, Level $expected): void
    {
        $handler = new TestHandler();
        $listener = new ErrorListener(null, new Logger('request', [$handler]), false, $this->exceptionsMapping());

        $listener->logKernelException(new ExceptionEvent(
            self::createStub(HttpKernelInterface::class),
            Request::create('/api/assistant/answers', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        ));

        self::assertCount(1, $handler->getRecords());
        self::assertSame($expected, $handler->getRecords()[0]->level);
    }

    /**
     * @return array<class-string, array{log_level: ?string, status_code: null, log_channel: null}>
     */
    private function exceptionsMapping(): array
    {
        /** @var array{framework: array{exceptions?: array<class-string, array{log_level?: string}>}} $config */
        $config = Yaml::parseFile(self::FRAMEWORK_CONFIG);

        $mapping = [];
        foreach ($config['framework']['exceptions'] ?? [] as $class => $options) {
            $mapping[$class] = ['log_level' => $options['log_level'] ?? null, 'status_code' => null, 'log_channel' => null];
        }

        return $mapping;
    }
}
