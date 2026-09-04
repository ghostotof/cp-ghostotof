<?php

declare(strict_types=1);

namespace App\Tests\Contact\Infrastructure\Messenger;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Rendu des templates de l'e-mail de notification du formulaire de contact
 * (cf. tasks/plan.md, tâche 1.3). Vérifie que l'identité de l'expéditeur (nom
 * ET e-mail) figure dans le corps — HTML comme texte — en plus du message, et
 * que le message est échappé côté HTML (pas d'injection via le formulaire).
 */
final class ContactNotificationTemplateTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->twig = self::getContainer()->get(Environment::class);
    }

    public function testHtmlNotificationShowsSenderIdentityAndEscapesTheMessage(): void
    {
        $html = $this->twig->render('emails/contact_notification.html.twig', [
            'senderName' => 'Jane Doe',
            'senderEmail' => 'jane@example.com',
            'body' => "Première ligne\n<script>alert(1)</script>",
        ]);

        self::assertStringContainsString('Jane Doe', $html);
        self::assertStringContainsString('jane@example.com', $html);
        self::assertStringContainsString('Première ligne', $html);

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);

        // Layout de marque hérité (cf. tâche 1.2).
        self::assertStringContainsString('CP-Ghostotof', $html);
    }

    public function testTextNotificationShowsSenderIdentityAndMessage(): void
    {
        $text = $this->twig->render('emails/contact_notification.txt.twig', [
            'senderName' => 'Jane Doe',
            'senderEmail' => 'jane@example.com',
            'body' => 'Bonjour, ceci est un message.',
        ]);

        self::assertStringContainsString('Jane Doe', $text);
        self::assertStringContainsString('jane@example.com', $text);
        self::assertStringContainsString('Bonjour, ceci est un message.', $text);
    }
}
