<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection;

use App\Mcp\Connection\Application\ExchangedCodeConnectionInterface;
use App\Mcp\Connection\Application\TokenEndpointInterface;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use SensitiveParameter;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le premier échange d'un code est noté même quand le remplacement ne sait pas
 * relire ce code (#650, R4, CR3 de la relecture de #730).
 *
 * `code_exchanged_at` est ce qui met fin à l'échéance d'une heure d'un accord
 * abandonné. Écrit par `CodeExchangeSupersession`, il dépendait du déchiffrement
 * du code (le port `ExchangedCodeConnectionInterface`) et d'une relecture de la
 * connexion : si l'un des deux échouait **après** que league avait émis les
 * jetons, la connexion qui sert restait « jamais échangée » et expirait au bout
 * d'une heure, sans un mot. Il est écrit désormais par la révocation du code
 * (`AuthCodeRepository::revokeAuthCode()`), le dernier geste de league, sur la
 * connexion que league a verrouillée : ni lecture, ni déchiffrement.
 *
 * Le port est remplacé par un port qui ne lit rien, sur le vrai league, la vraie
 * base, le vrai point de jeton du conteneur.
 */
final class CodeExchangedAtTest extends WebTestCase
{
    use DrivesTheOAuthFlow;

    private const string FORM = 'application/x-www-form-urlencoded';

    protected function setUp(): void
    {
        $this->setUpOAuthFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownOAuthFlow();
        parent::tearDown();
    }

    /**
     * Le port ne lit aucune connexion (un code qu'il ne saurait déchiffrer) :
     * les jetons sont émis, la colonne est écrite quand même. **Mesuré** :
     * l'écriture retirée de `revokeAuthCode()` et remise dans le remplacement
     * (le chemin d'avant), ce test est rouge.
     */
    public function testTheExchangeIsNotedWhenTheSupersessionCannotReadTheCode(): void
    {
        $code = $this->grantedCode();
        self::assertSame(0, $this->countRows('SELECT count(*) FROM mcp_connection WHERE code_exchanged_at IS NOT NULL'));

        $response = $this->endpointWithAnUnreadableCode()->respond($this->exchangeRequest($code), new Response());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->countRows('SELECT count(*) FROM mcp_connection WHERE code_exchanged_at IS NOT NULL'), 'Les jetons sont émis, le premier échange n\'est pas noté : la connexion expirerait en une heure.');
    }

    /**
     * Un échange refusé n'écrit rien : league ne révoque le code qu'en dernier,
     * et l'écriture suit sa révocation — ici un vérificateur faux, refusé avant.
     */
    public function testARefusedExchangeNotesNothing(): void
    {
        $code = $this->grantedCode();

        $response = $this->exchange($code, ['code_verifier' => 'x'.self::CODE_VERIFIER]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, $this->countRows('SELECT count(*) FROM mcp_connection WHERE code_exchanged_at IS NOT NULL'));
    }

    /**
     * Le point de jeton du conteneur, son port de lecture du code remplacé par
     * un port qui ne désigne aucune connexion. Le noyau est redémarré : le
     * conteneur de test refuse de remplacer un service déjà initialisé.
     */
    private function endpointWithAnUnreadableCode(): TokenEndpointInterface
    {
        $kernel = $this->client->getKernel();
        $kernel->shutdown();
        $kernel->boot();

        $unreadable = self::createStub(ExchangedCodeConnectionInterface::class);
        $unreadable->method('connectionIdOf')->willReturn(null);
        self::getContainer()->set(ExchangedCodeConnectionInterface::class, $unreadable);

        return self::getContainer()->get(TokenEndpointInterface::class);
    }

    private function exchangeRequest(#[SensitiveParameter] string $code): ServerRequest
    {
        return new ServerRequest('POST', 'http://localhost/api/oauth/token', ['Content-Type' => self::FORM], self::formBody($this->exchangeParameters($code)));
    }
}
