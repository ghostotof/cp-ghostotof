<?php

declare(strict_types=1);

namespace App\Tests\Ai\Translation\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Post;
use App\Ai\Translation\Application\ContentTranslatorInterface;
use App\Ai\Translation\Application\TranslationRateLimiterInterface;
use App\Ai\Translation\Infrastructure\ApiPlatform\BackofficeTranslationProcessor;
use App\Ai\Translation\Presentation\ApiResource\BackofficeTranslationResource;
use App\Shared\Infrastructure\ApiPlatform\UnexpectedActingUserException;
use App\Tests\Support\FixedSecurity;
use PHPUnit\Framework\TestCase;

/**
 * Le quota de traduction se décompte par compte (spec 0002). Sans compte, il
 * n'y a rien à décompter : c'est un défaut de câblage (issue #338), refusé
 * avant de consommer le quota et avant tout appel au fournisseur.
 */
final class BackofficeTranslationProcessorTest extends TestCase
{
    public function testNeitherTheQuotaNorTheProviderIsReachedWithoutAnAccount(): void
    {
        $rateLimiter = $this->createMock(TranslationRateLimiterInterface::class);
        $rateLimiter->expects(self::never())->method(self::anything());
        $translator = $this->createMock(ContentTranslatorInterface::class);
        $translator->expects(self::never())->method(self::anything());
        $processor = new BackofficeTranslationProcessor($translator, $rateLimiter, FixedSecurity::holding(null));

        $this->expectException(UnexpectedActingUserException::class);
        $this->expectExceptionMessage(BackofficeTranslationProcessor::class.'::process()');

        $processor->process(new BackofficeTranslationResource(sourceLocale: 'fr', targetLocale: 'en', fields: ['title' => 'Bonjour']), new Post());
    }
}
