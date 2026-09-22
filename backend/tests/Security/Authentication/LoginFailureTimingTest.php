<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\Authentication\Infrastructure\Security\FailedLoginTimingEqualizer;
use App\Security\User\Application\CpgUserInviterInterface;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Tests\Support\HttpJson;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherAwareInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Constat A10 : `POST /api/login_check` ne doit pas distinguer un identifiant
 * inconnu, une invitation en attente d'activation et un compte actif au
 * mauvais mot de passe.
 *
 * Ce test couvre la moitié observable du problème — **la réponse elle-même**,
 * corps et en-têtes. L'autre moitié, le temps, est couverte par
 * FailedLoginTimingEqualizerTest : la preuve y est l'appel au hasher, pas une
 * durée. Un test de chronométrage serait instable par construction (charge de
 * la machine, JIT, premier appel), et le coût du hasher est délibérément
 * abaissé en environnement de test : il mesurerait surtout du bruit.
 */
final class LoginFailureTimingTest extends WebTestCase
{
    use HttpJson;

    /** Ce qui varie légitimement d'une réponse à l'autre, à retirer avant comparaison. */
    private const array VOLATILE_HEADERS = ['date', 'x-debug-token', 'x-debug-token-link'];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    /**
     * Les trois échecs doivent être indiscernables : même statut, même corps,
     * mêmes en-têtes. Les identifiants sont tirés au hasard pour que chaque
     * exécution reparte d'un compteur (IP, identifiant) vierge.
     */
    public function testTheThreeFailureCasesAnswerTheExactSameResponse(): void
    {
        $client = $this->client();
        $suffix = bin2hex(random_bytes(6));

        $active = 'active-'.$suffix;
        self::getContainer()->get(CpgUserRegistrarInterface::class)->register($active, TestCredentials::plainPassword());

        $pending = self::getContainer()->get(CpgUserInviterInterface::class)
            ->invite('pending.'.$suffix.'@example.test', Locale::FR)
            ->getUsername();
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $unknown = $this->attemptLogin($client, 'ghost-'.$suffix, 'wrong-password');
        $awaitingActivation = $this->attemptLogin($client, $pending, 'wrong-password');
        $wrongPassword = $this->attemptLogin($client, $active, 'wrong-password');

        foreach (['inconnu' => $unknown, 'en attente' => $awaitingActivation, 'mauvais mot de passe' => $wrongPassword] as $case => $response) {
            self::assertSame(401, $response->getStatusCode(), sprintf('Le cas « %s » n\'a pas répondu 401.', $case));
        }

        self::assertSame($unknown->getContent(), $awaitingActivation->getContent(), 'Un compte en attente d\'activation se distingue d\'un identifiant inconnu par le corps de la réponse.');
        self::assertSame($unknown->getContent(), $wrongPassword->getContent(), 'Un identifiant inconnu se distingue d\'un mot de passe faux par le corps de la réponse.');

        self::assertSame($this->comparableHeaders($unknown), $this->comparableHeaders($awaitingActivation), 'Un compte en attente d\'activation se distingue par ses en-têtes.');
        self::assertSame($this->comparableHeaders($unknown), $this->comparableHeaders($wrongPassword), 'Un identifiant inconnu se distingue par ses en-têtes.');

        // Le corps est bien celui de Lexik, pas une page d'erreur : sans cette
        // ancre, trois 500 identiques passeraient les assertions ci-dessus.
        self::assertStringContainsStringIgnoringCase('invalid credentials', (string) $unknown->getContent());
    }

    /**
     * Épingle la *forme* réelle de l'échec, telle que le compensateur la voit
     * au bout de la chaîne — c'est l'hypothèse la plus fragile du listener, et
     * la seule qu'un test unitaire ne peut pas établir :
     *
     *  - identifiant inconnu : `AuthenticatorManager::handleAuthenticationFailure()`
     *    masque l'UserNotFoundException en BadCredentialsException et la
     *    conserve en `previous` (`isSensitiveException()`) ;
     *  - compte en attente : l'échec est une BadCredentialsException nue, et
     *    le compte est déjà chargé dans le passeport.
     *
     * Les événements capturés sont rejoués dans un compensateur muni d'un
     * hasher espion : on compte les hachages factices sans jamais chronométrer.
     */
    public function testTheRealFailureFlowTriggersTheCompensationOnlyWhereExpected(): void
    {
        $client = $this->client();
        // Sans cela, KernelBrowser reconstruit le kernel entre deux requêtes et
        // l'écoute posée ci-dessous disparaît après la première.
        $client->disableReboot();

        $suffix = bin2hex(random_bytes(6));
        $active = 'active-'.$suffix;
        self::getContainer()->get(CpgUserRegistrarInterface::class)->register($active, TestCredentials::plainPassword());
        $pending = self::getContainer()->get(CpgUserInviterInterface::class)
            ->invite('pending.'.$suffix.'@example.test', Locale::FR)
            ->getUsername();
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        /** @var list<LoginFailureEvent> $failures */
        $failures = [];
        // Priorité -200 : après le compensateur (-100), donc dans l'état où il
        // l'a laissé.
        self::getContainer()->get('security.event_dispatcher.login')->addListener(LoginFailureEvent::class, static function (LoginFailureEvent $event) use (&$failures): void {
            $failures[] = $event;
        }, -200);

        $this->attemptLogin($client, 'ghost-'.$suffix, 'wrong-password');
        $this->attemptLogin($client, $pending, 'wrong-password');
        $this->attemptLogin($client, $active, 'wrong-password');
        $this->attemptLogin($client, $active, '');

        self::assertCount(4, $failures);
        [$unknown, $awaitingActivation, $wrongPassword, $emptyPassword] = $failures;

        self::assertInstanceOf(BadCredentialsException::class, $unknown->getException());
        self::assertInstanceOf(UserNotFoundException::class, $unknown->getException()->getPrevious(), 'Symfony ne conserve plus l\'UserNotFoundException en `previous` : le compensateur ne reconnaît plus l\'identifiant inconnu.');
        self::assertSame(1, $this->countDummyHashes($unknown));

        self::assertInstanceOf(BadCredentialsException::class, $awaitingActivation->getException());
        self::assertNull($awaitingActivation->getException()->getPrevious());
        self::assertSame(1, $this->countDummyHashes($awaitingActivation));

        self::assertSame(0, $this->countDummyHashes($wrongPassword), 'Un mot de passe faux sur un compte actif a déjà payé sa dérivation : une seconde doublerait le temps de réponse.');

        // Troisième chemin rapide, et il est sans fuite :
        // JsonLoginAuthenticator::getCredentials() refuse un mot de passe vide
        // *avant* de construire le passeport, donc avant toute requête en base
        // — identique pour tous les identifiants, connus comme inconnus.
        // Compenser là réintroduirait l'écart dans l'autre sens : l'inconnu
        // paierait une dérivation que le compte existant ne paie pas.
        self::assertNull($emptyPassword->getPassport(), 'Un mot de passe vide construit désormais un passeport : le chemin rapide n\'est plus uniforme, le compensateur doit être revu.');
        self::assertSame(0, $this->countDummyHashes($emptyPassword));
    }

    /**
     * Le compensateur ne doit rien changer au comptage de `login_throttling` :
     * la 6e tentative sur un identifiant *inconnu* reste bloquée. C'est aussi
     * la borne qui rend le hachage factice acceptable côté DoS.
     */
    public function testThrottlingStillTriggersOnTheSixthAttemptForAnUnknownIdentifier(): void
    {
        $client = $this->client();
        $unknown = 'ghost-'.bin2hex(random_bytes(6));

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $response = $this->attemptLogin($client, $unknown, 'wrong-password');
            self::assertSame(401, $response->getStatusCode(), sprintf('La tentative n°%d aurait dû répondre 401.', $attempt));
            self::assertStringNotContainsStringIgnoringCase('too many', (string) $response->getContent());
        }

        $response = $this->attemptLogin($client, $unknown, 'wrong-password');
        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsStringIgnoringCase('too many failed login attempts', (string) $response->getContent());
    }

    /**
     * Le chemin nominal n'est pas affecté : le compensateur n'écoute que les
     * échecs.
     */
    public function testANominalLoginStillSucceeds(): void
    {
        $client = $this->client();
        $username = 'nominal-'.bin2hex(random_bytes(6));
        self::getContainer()->get(CpgUserRegistrarInterface::class)->register($username, TestCredentials::plainPassword());

        $response = $this->attemptLogin($client, $username, TestCredentials::plainPassword());

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($client->getCookieJar()->get('BEARER'));
    }

    /**
     * Les quotas vivent dans `cache.rate_limiter`, durable depuis l'ADR 0005 :
     * sans purge, le compteur *par IP* de `login_throttling` (25 tentatives
     * par 15 min, soit 5 × max_attempts) serait partagé avec les autres tests
     * de login de la suite et finirait par couper celui-ci.
     */
    private function client(): KernelBrowser
    {
        $client = self::createClient();
        self::getContainer()->get('cache.rate_limiter')->clear();

        return $client;
    }

    private function attemptLogin(KernelBrowser $client, string $username, string $password): Response
    {
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => $username,
            'password' => $password,
        ]));

        return $client->getResponse();
    }

    /**
     * Rejoue l'événement dans un compensateur neuf, muni d'un hasher espion,
     * et renvoie le nombre de hachages factices qu'il a demandés.
     */
    private function countDummyHashes(LoginFailureEvent $event): int
    {
        $hasher = new class implements PasswordHasherInterface {
            public int $calls = 0;

            public function hash(#[\SensitiveParameter] string $plainPassword): string
            {
                ++$this->calls;

                return 'hashed';
            }

            public function verify(string $hashedPassword, #[\SensitiveParameter] string $plainPassword): bool
            {
                return false;
            }

            public function needsRehash(string $hashedPassword): bool
            {
                return false;
            }
        };

        $factory = new readonly class($hasher) implements PasswordHasherFactoryInterface {
            public function __construct(private PasswordHasherInterface $hasher)
            {
            }

            public function getPasswordHasher(string|PasswordAuthenticatedUserInterface|PasswordHasherAwareInterface $user): PasswordHasherInterface
            {
                return $this->hasher;
            }
        };

        (new FailedLoginTimingEqualizer($factory))($event);

        return $hasher->calls;
    }

    /**
     * @return array<string, list<string|null>>
     */
    private function comparableHeaders(Response $response): array
    {
        $headers = $response->headers->all();

        foreach (self::VOLATILE_HEADERS as $name) {
            unset($headers[$name]);
        }

        ksort($headers);

        return $headers;
    }
}
