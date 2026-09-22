<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Http\ApiJsonErrorFormatListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Test unitaire de l'ancrage du listener (audit A15, D8). Le rendu réel des
 * erreurs est couvert par ApiErrorFormatTest (fonctionnel).
 */
final class ApiJsonErrorFormatListenerTest extends TestCase
{
    #[DataProvider('apiPaths')]
    public function testApiPathsGetTheJsonRequestFormat(string $path): void
    {
        $request = Request::create($path);

        (new ApiJsonErrorFormatListener())->__invoke($this->mainRequestEvent($request));

        self::assertSame('json', $request->getRequestFormat());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function apiPaths(): iterable
    {
        yield 'racine de l\'API' => ['/api'];
        yield 'route existante' => ['/api/contact'];
        yield 'route inexistante' => ['/api/inexistant'];
        yield 'sous-arbre profond' => ['/api/backoffice/users/whatever'];
        // Régression issue #77 : `%61` = 'a'. Le routeur décode avant de
        // décider, le listener doit décoder aussi.
        yield 'chemin encodé' => ['/%61pi/inexistant'];
    }

    /**
     * Hors `/api`, la requête garde son format par défaut : la page HTML
     * d'erreur reste la bonne réponse pour un navigateur, et ce listener n'a
     * pas à trancher pour le reste de l'application.
     */
    #[DataProvider('nonApiPaths')]
    public function testOtherPathsKeepTheirDefaultFormat(string $path): void
    {
        $request = Request::create($path);

        (new ApiJsonErrorFormatListener())->__invoke($this->mainRequestEvent($request));

        self::assertSame('html', $request->getRequestFormat());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonApiPaths(): iterable
    {
        yield 'racine' => ['/'];
        yield 'chemin quelconque' => ['/inexistant'];
        // L'ancrage « /api ou /api/… » : un voisin qui commence par les mêmes
        // quatre caractères n'est pas l'API.
        yield 'voisin sans séparateur' => ['/apix'];
        yield 'voisin avec tiret' => ['/api-docs'];
        yield '/api en milieu de chemin' => ['/public/api/contact'];
    }

    /**
     * Le forward vers le contrôleur d'erreur est lui-même une sous-requête :
     * elle hérite du format de la requête principale, et son chemin interne
     * n'a pas à peser dans la décision.
     */
    public function testSubRequestsAreIgnored(): void
    {
        $request = Request::create('/api/inexistant');
        $event = new ExceptionEvent(
            self::createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::SUB_REQUEST,
            new NotFoundHttpException(),
        );

        (new ApiJsonErrorFormatListener())->__invoke($event);

        self::assertSame('html', $request->getRequestFormat());
    }

    /**
     * Le listener ne répond jamais lui-même : il renseigne le format et laisse
     * l'ErrorListener de Symfony (-128) rendre la réponse. S'il posait une
     * réponse, il interromprait la propagation et il n'y aurait plus de rendu
     * du tout.
     */
    public function testTheListenerSetsNoResponse(): void
    {
        $event = $this->mainRequestEvent(Request::create('/api/inexistant'));

        (new ApiJsonErrorFormatListener())->__invoke($event);

        self::assertNull($event->getResponse());
    }

    private function mainRequestEvent(Request $request): ExceptionEvent
    {
        return new ExceptionEvent(
            self::createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new NotFoundHttpException(),
        );
    }
}
