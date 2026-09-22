<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\CpgUserInviterInterface;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Security\User\Infrastructure\Messenger\SendAccountInvitationHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

/**
 * Depuis la décision D3 (audit C2), SendAccountInvitationMessage ne transporte
 * plus le jeton en clair : il est créé par SendAccountInvitationHandler, et
 * seul l'e-mail d'invitation le contient.
 *
 * En test, le transport « async » est in-memory (le handler ne s'exécute pas
 * tout seul au dispatch) et le mailer est `null://` : ce helper déclenche
 * explicitement le handler, puis relit le jeton en clair dans le lien de
 * l'e-mail capturé par `mailer.message_logger_listener` (via
 * MailerAssertionsTrait).
 *
 * @phpstan-require-extends KernelTestCase
 */
trait InvitesUsers
{
    use MailerAssertionsTrait;

    /**
     * Invite l'adresse donnée via le cas d'usage (hors requête HTTP), exécute
     * le handler d'invitation et renvoie le jeton de définition de mot de passe
     * en clair.
     */
    private function inviteAndCollectSetupToken(string $email, Locale $locale): string
    {
        self::getContainer()->get(CpgUserInviterInterface::class)->invite($email, $locale);
        // Détache les entités persistées : la requête HTTP suivante réutilise ce
        // kernel/EM et doit relire l'état depuis la base.
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return $this->runInvitationHandlerAndCollectSetupToken();
    }

    /**
     * Exécute le handler sur le dernier message d'invitation dispatché et
     * renvoie le jeton en clair extrait de l'e-mail produit.
     */
    private function runInvitationHandlerAndCollectSetupToken(): string
    {
        $handler = self::getContainer()->get(SendAccountInvitationHandler::class);
        $handler($this->lastDispatchedInvitationMessage());

        // Le handler laisse le PasswordSetupToken qu'il vient de créer dans le
        // cache d'identité de l'EM ; sans ce clear(), une requête HTTP servie
        // par le même kernel (avant tout reboot de KernelBrowser) lirait cette
        // instance obsolète au lieu de l'état réel de la base (ex. après un
        // UPDATE SQL brut de expires_at dans un test « jeton expiré »).
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return $this->setupTokenFromLastInvitationEmail();
    }

    private function lastDispatchedInvitationMessage(): SendAccountInvitationMessage
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $envelopes = $transport->getSent();
        self::assertNotEmpty($envelopes, 'Aucun message d\'invitation dispatché.');

        $message = end($envelopes)->getMessage();
        self::assertInstanceOf(SendAccountInvitationMessage::class, $message);

        return $message;
    }

    private function setupTokenFromLastInvitationEmail(): string
    {
        $messages = self::getMailerMessages();
        self::assertNotEmpty($messages, 'Aucun e-mail d\'invitation envoyé.');

        $email = end($messages);
        self::assertInstanceOf(Email::class, $email);

        // Le jeton voyage dans le fragment du lien (audit A7, décision D6),
        // plus dans un segment de chemin. Les deux versions de l'e-mail sont
        // vérifiées séparément : concaténer les corps laisserait passer un
        // template (texte ou HTML) qui aurait perdu son lien.
        $tokens = [];
        foreach (['texte' => $email->getTextBody(), 'HTML' => $email->getHtmlBody()] as $label => $body) {
            self::assertIsString($body, sprintf('Version %s de l\'e-mail d\'invitation absente.', $label));
            self::assertSame(
                1,
                preg_match('~/set-password#([0-9a-f]{64})~', $body, $matches),
                sprintf('Lien de définition de mot de passe introuvable dans la version %s de l\'e-mail d\'invitation.', $label),
            );
            self::assertStringNotContainsString(
                '/set-password/',
                $body,
                sprintf('La version %s de l\'e-mail porte encore le jeton dans un chemin d\'URL.', $label),
            );
            $tokens[] = $matches[1];
        }

        self::assertSame($tokens[0], $tokens[1], 'Les versions texte et HTML de l\'e-mail ne portent pas le même jeton.');

        return $tokens[0];
    }
}
