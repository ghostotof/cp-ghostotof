<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\Messenger;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Rendu des templates de l'e-mail d'invitation (cf. tasks/plan.md, tâche 2.4) :
 * héritage du layout de marque, présence du bouton CTA et du lien de définition
 * de mot de passe, échappement du nom d'utilisateur.
 */
final class AccountInvitationTemplateTest extends KernelTestCase
{
    private const array STRINGS = [
        'heading' => 'Bienvenue',
        'intro' => 'Un accès vient d\'être créé pour vous.',
        'usernameLabel' => 'Votre identifiant de connexion',
        'cta' => 'Définir mon mot de passe',
        'expiryNote' => 'Ce lien est valable 48 heures.',
        'fallbackNote' => 'Si le bouton ne fonctionne pas, copiez ce lien :',
        'ignoreNote' => 'Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet e-mail.',
    ];

    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->twig = self::getContainer()->get(Environment::class);
    }

    public function testHtmlInvitationCarriesTheBrandCtaAndSetupLinkAndEscapesTheUsername(): void
    {
        $html = $this->twig->render('emails/account_invitation.html.twig', [
            'username' => '<b>jean</b>',
            'setupUrl' => 'https://front.test/fr/set-password/deadbeef',
            'strings' => self::STRINGS,
        ]);

        self::assertStringContainsString('CP-Ghostotof', $html);
        self::assertStringContainsString('Définir mon mot de passe', $html);
        self::assertStringContainsString('https://front.test/fr/set-password/deadbeef', $html);

        self::assertStringContainsString('&lt;b&gt;jean&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>jean</b>', $html);
    }

    public function testTextInvitationCarriesTheSetupLinkAndUsername(): void
    {
        $text = $this->twig->render('emails/account_invitation.txt.twig', [
            'username' => 'jean',
            'setupUrl' => 'https://front.test/fr/set-password/deadbeef',
            'strings' => self::STRINGS,
        ]);

        self::assertStringContainsString('CP-Ghostotof', $text);
        self::assertStringContainsString('jean', $text);
        self::assertStringContainsString('https://front.test/fr/set-password/deadbeef', $text);
        self::assertStringContainsString('48 heures', $text);
    }
}
