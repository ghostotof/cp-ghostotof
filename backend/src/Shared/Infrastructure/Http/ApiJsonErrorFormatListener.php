<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Sous `/api`, une erreur sort en JSON, jamais en page HTML (audit A15, D8).
 *
 * Deux familles d'erreurs échappaient au format de l'API et renvoyaient la
 * page d'exception HTML de Symfony, avec sa mise en page, son favicon en
 * base64 et — hors production — la trace complète :
 *  - les 404 et 405 du **routeur** (`GET /api/inexistant`,
 *    `DELETE /api/contact`), levées avant qu'API Platform n'existe pour la
 *    requête, donc jamais converties par son ExceptionListener, qui ne traite
 *    que les requêtes portant `_api_respond` ;
 *  - les refus de nos propres listeners `kernel.request` sur les routes **hors
 *    API Platform** (`POST /api/logout` sans en-tête CSRF, `POST /api/login_check`
 *    sans `X-Requested-With`) : l'`AccessDeniedHttpException` y est rendue par
 *    Symfony, pas par API Platform.
 * Un client JSON recevait donc du HTML là où il attend un corps analysable.
 *
 * Mécanique : `Request::setRequestFormat('json')` renseigne le format de la
 * requête, que `SerializerErrorRenderer` relit (`Request::getPreferredFormat()`)
 * pour choisir l'encodage **et** l'en-tête `Content-Type` du rendu d'erreur. Le
 * corps produit par le `ProblemNormalizer` de Symfony est déjà un document
 * RFC 7807 (`type`, `title`, `status`, `detail`), et il n'expose ni classe, ni
 * trace, ni chemin de fichier dès que `kernel.debug` est faux — c'est-à-dire en
 * préproduction et en production.
 *
 * **Pourquoi `kernel.exception` et pas `kernel.request`.** La décision D8
 * prévoyait un listener `kernel.request` juste au-dessus du routeur (32), qui
 * aurait posé le format sur *toute* requête d'API. Mesuré : cela casse la
 * négociation de contenu d'API Platform. Quand l'appelant n'envoie pas
 * d'en-tête `Accept`, `ContentNegotiationTrait::getRequestFormat`
 * (vendor/api-platform/metadata/Util/ContentNegotiationTrait.php:117) relit le
 * format déjà posé sur la requête et lève une `NotAcceptableHttpException` si
 * son type MIME ne figure pas dans les formats de l'opération. `GET /api/docs`
 * et `GET /api` (dont les formats sont `application/vnd.openapi+json` et
 * `text/html`, jamais `application/json`) répondaient alors **406** au lieu de
 * leur documentation — et n'importe quelle future ressource servie en CSV ou
 * en PDF aurait subi le même sort, sans qu'aucun test ne le rappelle.
 *
 * Posé sur `kernel.exception`, le format n'est renseigné **que lorsqu'une
 * exception a déjà eu lieu** : le trajet d'une requête qui aboutit n'est pas
 * touché du tout, et la question de la négociation ne se pose plus.
 *
 * **Priorité -100**, entre l'`ExceptionListener` d'API Platform (-96) et
 * l'`ErrorListener` de Symfony (-128), et c'est ce qui rend ce listener
 * inoffensif pour l'API elle-même : `ExceptionEvent::setResponse()` interrompt
 * la propagation (il hérite de `RequestEvent`), donc quand API Platform a
 * traité l'erreur — tous ses `/api/...`, avec leur `application/problem+json`
 * — ce listener **n'est jamais appelé**. Il ne s'exécute que sur les erreurs
 * qu'API Platform a laissé passer, exactement celles qui finissaient en HTML,
 * et le rendu revient ensuite à l'`ErrorListener` de Symfony à -128.
 */
#[AsEventListener(event: ExceptionEvent::class, priority: -100)]
final readonly class ApiJsonErrorFormatListener
{
    /**
     * Préfixe de l'API. Ancré sur « `/api` exactement, ou `/api/…` » : un
     * `str_starts_with($path, '/api')` nu attraperait aussi un futur `/apix`
     * ou `/api-docs`, qui ne sont pas l'API.
     */
    private const string API_PATH = '/api';

    public function __invoke(ExceptionEvent $event): void
    {
        // Le forward vers le contrôleur d'erreur est une sous-requête : elle
        // hérite déjà du format de la requête principale, et son chemin interne
        // n'a pas à peser dans la décision.
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Chemin décodé (CanonicalPath, issue #77) : le routeur sert
        // `/%61pi/inexistant` comme `/api/inexistant`, sa 404 doit donc sortir
        // en JSON elle aussi — sinon un octet d'encodage suffit à récupérer la
        // page HTML sur le chemin qu'on vient de couvrir.
        $path = CanonicalPath::of($request);

        if (self::API_PATH !== $path && !str_starts_with($path, self::API_PATH.'/')) {
            return;
        }

        $request->setRequestFormat('json');
    }
}
