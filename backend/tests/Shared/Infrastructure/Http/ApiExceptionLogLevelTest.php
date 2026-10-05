<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use App\Ai\Assistant\Domain\Exception\AssistantRateLimitExceededException;
use App\Ai\Assistant\Domain\Exception\AssistantUnavailableException;
use App\Ai\Assistant\Domain\Exception\InvalidConversationException;
use App\Ai\Assistant\Infrastructure\Http\RequestBodyTooLargeException;
use App\Ai\Translation\Domain\Exception\TranslationRateLimitExceededException;
use App\Ai\Translation\Domain\Exception\TranslationUnavailableException;
use App\Portfolio\About\Domain\Exception\AboutSettingsNotFoundException;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Domain\Exception\BaseAccessRateLimitExceededException;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Domain\Exception\InvalidPasswordSetupTokenException;
use App\Security\User\Domain\Exception\PasswordSetupRateLimitExceededException;
use App\Security\User\Domain\Exception\PasswordSetupTokenExpiredException;
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
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Yaml\Yaml;

/**
 * L'ErrorListener du noyau journalise en `critical` toute exception qui n'est
 * pas une HttpExceptionInterface, et en `error` un 4xx : trop fort pour une
 * erreur du client ou pour la panne d'un tiers dont la cause est déjà sur le
 * canal `ai_usage`. Les niveaux vivent dans `framework.exceptions` plutôt
 * qu'en attribut sur les exceptions, pour que le domaine ne dépende pas de
 * HttpKernel. Le listener est construit avec le mapping lu dans framework.yaml,
 * comme le fait le conteneur.
 *
 * Le cas du 415 dépasse l'assistant : la classe est aussi levée par API
 * Platform, son niveau vaut donc pour toute l'API (framework.yaml, issue #320).
 * Celui du quota de base-access aussi : rendu par ApiProblemResponseListener
 * depuis l'issue #322, il passe désormais par cette journalisation, que son
 * ancien écouteur dédié court-circuitait.
 *
 * Les exceptions rendues par API Platform passent par la même journalisation,
 * avant lui (issue #348) : quelques-unes sont épinglées ici, celles dont le
 * niveau est un choix plutôt que la règle (4xx en `info`). La présence d'une
 * entrée pour chacune relève d'ExceptionLogLevelCoverageTest.
 */
final class ApiExceptionLogLevelTest extends TestCase
{
    private const string FRAMEWORK_CONFIG = __DIR__.'/../../../../config/packages/framework.yaml';

    /**
     * @return iterable<string, array{\Throwable, Level}>
     */
    public static function exceptions(): iterable
    {
        yield 'conversation refusée (422)' => [new InvalidConversationException('La conversation est vide.'), Level::Info];
        yield 'fournisseur indisponible (503)' => [new AssistantUnavailableException(), Level::Warning];
        yield 'quota atteint (429)' => [new AssistantRateLimitExceededException(new \DateTimeImmutable('+1 hour')), Level::Info];
        yield 'corps trop volumineux (413)' => [new RequestBodyTooLargeException(), Level::Info];
        yield 'quota du palier de base atteint (429, issue #322)' => [new BaseAccessRateLimitExceededException(new \DateTimeImmutable('+1 hour')), Level::Info];
        yield 'format refusé (415), sur toute route de l\'API' => [new UnsupportedMediaTypeHttpException('Unsupported format.'), Level::Info];
        yield 'compte introuvable (404, API Platform, issue #348)' => [CpgUserNotFoundException::forId(Uuid::v7()), Level::Info];
        yield 'traduction indisponible (503, panne d\'un tiers)' => [new TranslationUnavailableException(), Level::Warning];
        yield 'jeton de mot de passe inconnu (404), visible en production' => [InvalidPasswordSetupTokenException::unknownToken(), Level::Warning];
        yield 'quota de définition de mot de passe (429), visible en production' => [new PasswordSetupRateLimitExceededException(new \DateTimeImmutable('+1 hour')), Level::Warning];
        yield 'lien de mot de passe expiré (410)' => [PasswordSetupTokenExpiredException::expiredOrAlreadyUsed(), Level::Info];
        yield 'quota du traducteur (429), visible en production' => [new TranslationRateLimitExceededException(new \DateTimeImmutable('+1 hour')), Level::Warning];
        yield 'réglages « À propos » absents pour une locale valide (404), visible en production' => [AboutSettingsNotFoundException::forLocale(Locale::FR), Level::Warning];
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
