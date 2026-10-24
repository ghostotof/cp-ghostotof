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
 * Construit par {@see NominativeCorpusReaderInterface::open()} seul, après la
 * décision d'accès : il n'existe pas de corpus « refusé ». Le constructeur
 * reste public (un objet de valeur `readonly`, que les tests construisent) :
 * rien ne l'impose donc au compilateur. Un corpus construit ailleurs ne
 * donne accès à rien — le texte, il faut déjà l'avoir —, il ne peut que
 * faire écrire une ligne d'audit de trop à {@see NominativeCorpusReaderInterface::recordServed()} :
 * limite acceptée (relecture de la vague de correction de #648). Il ne
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
