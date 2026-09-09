<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Watch\Domain\Service;

use App\Portfolio\Watch\Domain\Service\ExternalUrlFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test de non-régression d'une faille réelle, trouvée à la revue de sécurité du
 * 2026-09-08 : le lien de documentation fourni par endoflife.date arrivait
 * jusqu'au `href` de la page publique sans qu'aucune couche n'en vérifie le
 * schéma. Un `javascript:` publié chez le tiers devenait du script exécutable
 * chez le visiteur.
 */
final class ExternalUrlFilterTest extends TestCase
{
    private ExternalUrlFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new ExternalUrlFilter();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function republishableUrls(): iterable
    {
        yield 'lien réel du fournisseur' => ['https://endoflife.date/php'];
        yield 'chemin et fragment' => ['https://endoflife.date/php#8.5'];
        yield 'chaîne de requête' => ['https://endoflife.date/api?produit=php&format=json'];
        yield 'port explicite' => ['https://endoflife.date:8443/php'];
        yield 'schéma en majuscules' => ['HTTPS://endoflife.date/php'];
    }

    #[DataProvider('republishableUrls')]
    public function testARepublishableUrlIsReturnedUntouched(string $url): void
    {
        self::assertSame($url, $this->filter->keepIfSafe($url));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function refusedUrls(): iterable
    {
        yield 'schéma javascript' => ['javascript:alert(document.domain)'];
        yield 'schéma javascript en majuscules' => ['JavaScript:alert(1)'];
        // Une liste noire de `javascript:` aurait laissé passer les trois suivants.
        yield 'schéma data' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='];
        yield 'schéma vbscript' => ['vbscript:msgbox(1)'];
        yield 'schéma file' => ['file:///etc/passwd'];
        // Les navigateurs ignorent ces caractères en analysant un href : sans ce
        // refus, la charge utile s'exécute malgré un schéma en apparence inconnu.
        yield 'tabulation dans le schéma' => ["java\tscript:alert(1)"];
        yield 'retour à la ligne dans le schéma' => ["java\nscript:alert(1)"];
        yield 'espace en tête' => [' javascript:alert(1)'];
        yield 'caractère nul' => ["https://endoflife.date/php\0"];
        yield 'http en clair' => ['http://endoflife.date/php'];
        yield 'protocole relatif' => ['//endoflife.date/php'];
        yield 'chemin relatif' => ['/php'];
        yield 'schéma sans hôte' => ['https:/php'];
        yield 'texte quelconque' => ['endoflife.date'];
        yield 'chaîne vide' => [''];
        yield 'absence de lien' => [null];
    }

    #[DataProvider('refusedUrls')]
    public function testARefusedUrlBecomesAbsentRatherThanCleaned(?string $url): void
    {
        self::assertNull($this->filter->keepIfSafe($url));
    }
}
