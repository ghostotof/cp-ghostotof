<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Mail;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Fondation e-mail (cf. tasks/plan.md, tâche 1.2) : tout e-mail sortant hérite
 * d'un layout de base à la charte graphique du site. Ce test vérifie qu'un
 * template enfant qui `extends 'emails/base.html.twig'` (resp. `.txt.twig`) se
 * rend sans erreur, reprend le bandeau de marque, expose un bloc de contenu et
 * échappe correctement les variables.
 */
final class BrandedEmailRenderingTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->twig = self::getContainer()->get(Environment::class);
    }

    public function testHtmlLayoutCarriesBrandHeaderAndEscapesContent(): void
    {
        $html = $this->twig->createTemplate(
            <<<'TWIG'
            {% extends 'emails/base.html.twig' %}
            {% block body %}
                <p>Bonjour {{ name }}</p>
                <a href="https://example.test/go">{{ cta }}</a>
            {% endblock %}
            TWIG
        )->render(['name' => '<script>x</script>', 'cta' => 'Définir mon mot de passe']);

        // Charte : nom de marque + couleur de marque dans l'en-tête.
        self::assertStringContainsString('CP-Ghostotof', $html);
        self::assertStringContainsString('#7c3aed', $html);

        // Document HTML complet, layout <table> (compatibilité clients mail).
        self::assertStringContainsString('<!DOCTYPE html', $html);
        self::assertStringContainsString('<table', $html);

        // Le contenu du bloc est rendu ; les variables sont échappées.
        self::assertStringContainsString('Définir mon mot de passe', $html);
        self::assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>x</script>', $html);
    }

    public function testTextLayoutCarriesBrandAndBlockContent(): void
    {
        $text = $this->twig->createTemplate(
            <<<'TWIG'
            {% extends 'emails/base.txt.twig' %}
            {% block body %}Bonjour {{ name }}{% endblock %}
            TWIG
        )->render(['name' => 'Jean']);

        self::assertStringContainsString('CP-Ghostotof', $text);
        self::assertStringContainsString('Bonjour Jean', $text);
        // Rendu texte : aucune balise HTML.
        self::assertStringNotContainsString('<', $text);
    }
}
