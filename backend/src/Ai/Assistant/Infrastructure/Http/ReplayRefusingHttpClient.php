<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Infrastructure\Http;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Refuse de renvoyer une requête déjà envoyée au fournisseur de l'assistant.
 *
 * Le bridge Scaleway (symfony/ai 0.13.0) enveloppe son client dans un
 * EventSourceHttpClient créé en dur. Sur une coupure réseau en plein flux, ce
 * dernier attend 10 s puis renvoie la même requête : pour un POST de chat
 * completion, c'est une seconde génération facturée, hors quota (spec 0005 D6),
 * dont le texte s'ajouterait à la réponse partielle déjà affichée — et la
 * reprise peut se répéter (constaté le 2026-09-26, test de coupure de
 * AnswerControllerTest).
 *
 * Une requête est identifiée par sa méthode, son URL et son corps. L'assistant
 * n'appelle le modèle qu'une fois par requête HTTP : un second envoi identique
 * ne peut être qu'une reprise. Il échoue ici en erreur de transport, que
 * l'assistant traduit en événement `error`. La mémoire est vidée entre deux
 * requêtes (ResetInterface, `kernel.reset`).
 */
#[AsDecorator('ai.scaleway.http_client')]
final class ReplayRefusingHttpClient implements HttpClientInterface, ResetInterface
{
    use DecoratorTrait {
        reset as private resetDecorated;
    }

    /** @var array<string, true> empreintes des requêtes déjà envoyées */
    private array $sent = [];

    public function __construct(
        #[AutowireDecorated]
        HttpClientInterface $client,
    ) {
        $this->client = $client;
    }

    /**
     * `$options` porte la clé d'API (`auth_bearer`) et le corps, donc le
     * corpus du CV nominatif : caché des traces, dont celle de la
     * TransportException levée ici (issue #414).
     *
     * @param array<mixed> $options
     */
    public function request(string $method, string $url, #[SensitiveParameter] array $options = []): ResponseInterface
    {
        $body = $options['body'] ?? null;
        if (\is_string($body)) {
            $fingerprint = hash('sha256', $method."\n".$url."\n".$body);
            if (isset($this->sent[$fingerprint])) {
                throw new TransportException('Renvoi refusé : cette requête a déjà été envoyée au fournisseur de l\'assistant.');
            }
            $this->sent[$fingerprint] = true;
        }

        return $this->client->request($method, $url, $options);
    }

    public function reset(): void
    {
        $this->sent = [];
        $this->resetDecorated();
    }
}
