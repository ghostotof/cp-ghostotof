<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger;

use App\Kernel;
use App\Tests\Support\EnvironmentConfigKernel;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Mime\Email;

/**
 * `SendEmailMessage`, le message du bus du mailer, n'est routé vers aucun
 * transport : `MailerInterface::send()` le traite en synchrone (issue #426).
 *
 * Routé vers `async`, l'e-mail partirait **non rendu**, avec son contexte —
 * l'URL de définition du mot de passe et son jeton en clair, cf.
 * {@see \App\Security\User\Infrastructure\Messenger\SendAccountInvitationHandler} —,
 * dans RabbitMQ puis, en cas d'échec, dans la table du `failure_transport` :
 * exactement ce que l'audit C2 interdit. Et `send()` rendrait la main dès la
 * mise en file : l'échec de l'envoi n'atteindrait plus le handler, qui ne
 * pourrait ni le convertir (#419) ni retirer le jeton non remis (#425).
 *
 * Aucun routage du tout, `sync` compris : il n'apporterait rien, et une seule
 * règle se lit mieux qu'une liste de transports admis.
 *
 * La configuration déclarée est lue dans **chaque** environnement que le
 * noyau admet ({@see EnvironmentConfigKernel}, sans compiler) : préprod et
 * prod tournent en `prod`, que le conteneur de test ne voit pas. Un routage
 * y est rapproché du message comme Messenger le fait
 * ({@see HandlersLocator::listTypes()} : la classe, ses parents, ses
 * interfaces, les jokers d'espace de noms et `*`). Le conteneur compilé de
 * `test` est lu en plus, pour ce que la configuration ne dit pas.
 */
final class SendEmailMessageStaysSynchronousTest extends KernelTestCase
{
    private const string FIXTURE_PROJECT = __DIR__.'/Fixtures/MailerRoutingProject';

    public function testNoEnvironmentRoutesTheMailerMessage(): void
    {
        self::assertSame([], $this->routings(null), 'SendEmailMessage ne doit être routé vers aucun transport (issue #426) : l\'e-mail partirait non rendu, jeton d\'invitation compris, dans la file puis le failure_transport.');
    }

    /**
     * Une garde qui ne verrait rien serait verte à vide : chaque forme de
     * routage de la fixture, dans son environnement, doit ressortir.
     */
    public function testEveryFormOfRoutingIsCaughtInItsEnvironment(): void
    {
        self::assertSame([
            'dev : Symfony\Component\Mailer\*',
            'prod : Symfony\Component\Mailer\Messenger\SendEmailMessage',
            'test : *',
        ], $this->routings(self::FIXTURE_PROJECT));
    }

    public function testTheCompiledSendersLocatorHasNoSenderForIt(): void
    {
        $senders = self::getContainer()->get('messenger.senders_locator')->getSenders($this->envelope());

        self::assertSame([], array_keys(iterator_to_array($senders)));
    }

    /**
     * @return list<string> « env : type routé » qui atteignent SendEmailMessage, triées
     */
    private function routings(?string $projectDirectory): array
    {
        $types = HandlersLocator::listTypes($this->envelope());

        $found = [];
        foreach ($this->environments() as $environment) {
            $messenger = new EnvironmentConfigKernel($environment, $projectDirectory)->declaredMapping('framework', 'messenger');
            $routing = $messenger['routing'] ?? [];
            self::assertIsArray($routing);

            foreach (array_keys(array_intersect_key($routing, $types)) as $type) {
                $found[] = \sprintf('%s : %s', $environment, $type);
            }
        }
        sort($found);

        return $found;
    }

    /**
     * Lus dans {@see Kernel::getAllowedEnvs()} plutôt que recopiés, pour qu'un
     * environnement ajouté soit vérifié sans qu'on y pense.
     *
     * @return list<string>
     */
    private function environments(): array
    {
        /** @var list<string> $allowed */
        $allowed = new ReflectionMethod(Kernel::class, 'getAllowedEnvs')->invoke(new Kernel('test', false));
        self::assertContains('prod', $allowed, 'Le noyau n\'admet plus `prod` : revoir ce test.');

        return $allowed;
    }

    private function envelope(): Envelope
    {
        return new Envelope(new SendEmailMessage(new Email()));
    }
}
