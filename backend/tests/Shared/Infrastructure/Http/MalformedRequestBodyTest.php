<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\HttpJson;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Un corps de requête fautif est une **erreur du client**, jamais une panne du
 * serveur (constat du 2026-09-21, traité par T5.1).
 *
 * Avant correction, sur n'importe quel POST — public comme authentifié — un
 * corps qui n'est pas du JSON, ou un champ du mauvais type JSON
 * (`{"token":123}`, `{"token":null}`), répondait **500** : la clé
 * `exception_to_status` d'`api_platform.yaml` **remplace** les valeurs par
 * défaut d'API Platform au lieu de les compléter, et l'entrée
 * `Symfony\Component\Serializer\Exception\ExceptionInterface: 400` était donc
 * perdue. Un anonyme produisait des 500 à volonté (borné par les seuls quotas
 * par IP) et noyait les vraies erreurs serveur dans les journaux.
 *
 * Ce fichier n'est pas un test de validation : les 422 de validation sont
 * couverts par les tests de chaque ressource. Il épingle la frontière d'à
 * côté — le corps qui n'arrive même pas jusqu'à la validation.
 */
final class MalformedRequestBodyTest extends WebTestCase
{
    use HttpJson;

    private const string SUPER_USERNAME = 'super';

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
     * Les trois POST publics : formulaire de contact et les deux étapes du
     * parcours de définition de mot de passe. Ce sont les routes atteignables
     * sans le moindre identifiant, donc celles qui comptent le plus ici.
     *
     * @param array{0: string, 1: string} $endpoint
     */
    #[DataProvider('publicPostEndpointsAndMalformedBodies')]
    public function testAMalformedBodyOnAPublicPostAnswers400(array $endpoint, string $body): void
    {
        [$path, $label] = $endpoint;
        $client = $this->clientWithFreshQuotas();

        $client->request('POST', $path, server: ['CONTENT_TYPE' => 'application/json'], content: $body);

        self::assertSame(400, $client->getResponse()->getStatusCode(), $label);
        $this->assertProblemBodyWithoutInternals($client);
    }

    /**
     * @return iterable<string, array{array{0: string, 1: string}, string}>
     */
    public static function publicPostEndpointsAndMalformedBodies(): iterable
    {
        $endpoints = [
            'contact' => ['/api/contact', 'POST /api/contact'],
            'validation du jeton' => ['/api/account/password-setup/validate', 'POST /api/account/password-setup/validate'],
            'définition du mot de passe' => ['/api/account/password-setup', 'POST /api/account/password-setup'],
        ];

        $bodies = [
            'corps non JSON' => 'not-json',
            'JSON tronqué' => '{"token": ',
            'corps vide' => '',
        ];

        foreach ($endpoints as $name => $endpoint) {
            foreach ($bodies as $bodyName => $body) {
                yield $name.' — '.$bodyName => [$endpoint, $body];
            }
        }
    }

    /**
     * Le type JSON, lui, est vérifié à la dénormalisation, avant la validation :
     * un entier ou un `null` là où le DTO attend une chaîne ne produit pas de
     * violation Assert mais une NotNormalizableValueException. Elle relève du
     * même 400 — le client s'est trompé de type, le serveur n'a pas échoué.
     */
    #[DataProvider('wronglyTypedBodies')]
    public function testAWronglyTypedFieldOnAPublicPostAnswers400(string $path, string $body): void
    {
        $client = $this->clientWithFreshQuotas();

        $client->request('POST', $path, server: ['CONTENT_TYPE' => 'application/json'], content: $body);

        self::assertSame(400, $client->getResponse()->getStatusCode());
        $this->assertProblemBodyWithoutInternals($client);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function wronglyTypedBodies(): iterable
    {
        yield 'contact, name entier' => ['/api/contact', '{"name":123,"email":"jane@example.com","message":"Bonjour, un message assez long."}'];
        yield 'contact, message tableau' => ['/api/contact', '{"name":"Jane","email":"jane@example.com","message":["a","b"]}'];
        yield 'validate, token entier' => ['/api/account/password-setup/validate', '{"token":123}'];
        yield 'validate, token null' => ['/api/account/password-setup/validate', '{"token":null}'];
        yield 'setup, password entier' => ['/api/account/password-setup', '{"token":"abc","password":42}'];
    }

    /**
     * Le défaut n'était pas propre au périmètre public : la même
     * désérialisation sert les ressources de backoffice. Un compte ROLE_SUPER
     * authentifié (login + double-submit CSRF) obtient le même 400.
     */
    #[DataProvider('malformedBackofficeBodies')]
    public function testAMalformedBodyOnAnAuthenticatedBackofficePostAnswers400(string $body): void
    {
        $client = $this->clientWithFreshQuotas();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)
            ->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAsSuper($client);

        $client->request('POST', '/api/backoffice/experience/technologies', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: $body);

        self::assertSame(400, $client->getResponse()->getStatusCode());
        $this->assertProblemBodyWithoutInternals($client);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBackofficeBodies(): iterable
    {
        yield 'corps non JSON' => ['not-json'];
        yield 'name entier' => ['{"name":123,"years":1.0}'];
        yield 'years chaîne non numérique' => ['{"name":"PHP","years":"beaucoup"}'];
    }

    /**
     * Le contre-exemple, sans lequel le correctif pourrait n'être qu'un 400
     * posé sur tout : un corps **bien formé et bien typé** mais invalide reste
     * un 422 de validation, avec ses violations par champ.
     */
    public function testAWellTypedButInvalidBodyStillAnswers422(): void
    {
        $client = $this->clientWithFreshQuotas();

        $client->request('POST', '/api/account/password-setup/validate', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['token' => '']));

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Ce que voit un visiteur en production : `kernel.debug` y est faux, et le
     * corps d'erreur se réduit alors au document RFC 7807 — ni trace, ni nom
     * de classe, ni chemin de fichier. Le client est monté sans debug
     * expressément, l'environnement `test` le laissant actif.
     */
    public function testWithoutDebugTheBadRequestBodyCarriesNoInternals(): void
    {
        $client = self::createClient(['debug' => false]);
        self::getContainer()->get('cache.rate_limiter')->clear();

        $client->request('POST', '/api/contact', server: ['CONTENT_TYPE' => 'application/json'], content: 'not-json');

        self::assertSame(400, $client->getResponse()->getStatusCode());

        $raw = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('trace', $raw);
        self::assertStringNotContainsString('/var/www', $raw);
        self::assertStringNotContainsString('.php', $raw);
        self::assertStringNotContainsString('Serializer', $raw);
    }

    /**
     * Le corps reste un document problem+json analysable, et l'échec d'une
     * requête fautive n'a pas à raconter la pile interne.
     */
    private function assertProblemBodyWithoutInternals(KernelBrowser $client): void
    {
        $response = $client->getResponse();
        self::assertStringContainsString('json', (string) $response->headers->get('Content-Type'));

        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertArrayHasKey('status', $body);
        self::assertSame(400, $body['status']);
    }

    /**
     * Les quotas par IP (`contact_form`, `password_setup`) vivent dans le pool
     * `cache.rate_limiter`, adossé à Doctrine DBAL depuis l'ADR 0005 : ils
     * survivent au redémarrage de kernel et tous les tests fonctionnels
     * partagent l'IP 127.0.0.1. Sans ce `clear()`, ce fichier dépendrait de son
     * ordre d'exécution — et ses jeux de données, qui appellent plusieurs fois
     * la même route, franchiraient la limite.
     */
    private function clientWithFreshQuotas(): KernelBrowser
    {
        $client = self::createClient();
        self::getContainer()->get('cache.rate_limiter')->clear();

        return $client;
    }

    private function loginAsSuper(KernelBrowser $client): string
    {
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => self::SUPER_USERNAME,
            'password' => TestCredentials::superPassword(),
        ]));
        self::assertResponseIsSuccessful();

        $csrfCookie = $client->getCookieJar()->get('XSRF-TOKEN');
        self::assertNotNull($csrfCookie);

        return $csrfCookie->getValue();
    }
}
