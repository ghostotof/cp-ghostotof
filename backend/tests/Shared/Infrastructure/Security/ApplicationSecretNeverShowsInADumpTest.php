<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Security;

use App\Security\Authentication\Infrastructure\Http\CsrfCookieTokenSigner;
use App\Security\Authentication\Infrastructure\Log\LoginIdentifierFingerprint;
use App\Shared\Infrastructure\Cache\AuthenticatedCacheMarshaller;
use App\Shared\Infrastructure\Messenger\AuthenticatedEnvelopeSerializer;
use App\Tests\Support\Dumps;
use App\Tests\Support\TestKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Aucun usage d'`APP_SECRET` ne montre le secret brut quand on l'affiche
 * (issue #493) : `dump()` d'un service, le profiler, une sortie de débogage
 * collée dans un ticket ou une session d'assistant.
 *
 * Trois des quatre usages gardent le secret pour en dériver la clé plus tard
 * (à chaque appel, ou au premier usage) : ils le tiennent dans un
 * SensitiveParameterValue, comme KeyedMac sa clé dérivée. Chaque service est
 * mis en service avant d'être affiché, pour que ses dérivations paresseuses
 * aient eu lieu.
 */
final class ApplicationSecretNeverShowsInADumpTest extends TestCase
{
    #[DataProvider('usesInService')]
    public function testTheRawSecretNeverShows(object $use): void
    {
        // Un échec ne cite que les canaux : afficher le dump afficherait le secret.
        self::assertSame([], Dumps::channelsShowing($use, TestKeys::material()), 'Canaux qui affichent APP_SECRET.');
    }

    /**
     * @return iterable<string, array{object}>
     */
    public static function usesInService(): iterable
    {
        $signer = new CsrfCookieTokenSigner(TestKeys::material());
        $signer->isValid($signer->issue());

        $fingerprint = new LoginIdentifierFingerprint(TestKeys::material());
        $fingerprint->of('jane');

        $serializer = new AuthenticatedEnvelopeSerializer(new PhpSerializer(), TestKeys::material());
        $serializer->decode($serializer->encode(new Envelope(new stdClass())));

        $marshaller = new AuthenticatedCacheMarshaller(new DefaultMarshaller(), TestKeys::material());
        $marshaller->marshall(['key' => 'valeur'], $failed);

        yield 'cookie CSRF' => [$signer];
        yield 'empreinte de login' => [$fingerprint];
        yield 'enveloppes Messenger' => [$serializer];
        yield 'valeurs de cache' => [$marshaller];
    }
}
