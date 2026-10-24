<?php

declare(strict_types=1);

namespace App\Mcp\Application;

use App\Mcp\Connection\Application\McpConnectionPrincipal;

/**
 * Le corpus nominatif ouvert à une connexion MCP (#648), avant qu'il soit
 * servi : le texte rendu dans la langue demandée, l'adresse de la page du
 * site où se télécharge le CV, et la connexion à qui il a été ouvert — celle
 * que la ligne d'audit désignera s'il est servi
 * ({@see NominativeCorpusReaderInterface::recordServed()}).
 *
 * Ne se construit que par {@see NominativeCorpusReaderInterface::open()},
 * après la décision d'accès : il n'existe pas de corpus « refusé ». Il ne
 * porte ni jeton ni empreinte, seulement ce que le principal du firewall
 * désigne déjà (des UUID et l'identifiant public OAuth du client).
 */
final readonly class NominativeCorpus
{
    public function __construct(
        public string $text,
        public string $cvPageUrl,
        public McpConnectionPrincipal $reader,
    ) {
    }

    /**
     * Ce que l'outil rend, à la forme de son `outputSchema` fermé.
     *
     * @return array{corpus: string, cvPageUrl: string}
     */
    public function payload(): array
    {
        return ['corpus' => $this->text, 'cvPageUrl' => $this->cvPageUrl];
    }
}
