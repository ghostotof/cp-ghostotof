<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;
use DomainException;

/**
 * La décision a été prise sur la page d'un autre compte que celui dont le
 * cookie arrive avec elle (#650, F5 de la relecture) : la page a été ouverte
 * sous le compte A, la personne s'est reconnectée en B dans un autre onglet,
 * puis a cliqué dans le premier. Le nom affiché était celui de A ; accorder à
 * B aurait rendu cet affichage mensonger.
 *
 * Un 409 problem+json à son propre `type`, que la page reconnaît pour dire que
 * le compte connecté a changé et proposer de relire la demande — jamais
 * confondu avec une session expirée (401) ni avec une demande invalide (400,
 * que relire ne répare pas). Rien n'est accordé ni refusé : ni connexion, ni
 * code, ni ligne d'audit.
 *
 * Message constant, sans compte (le `detail` est rendu tel quel,
 * ProblemDetailStaysStaticTest ; deux UUID de comptes ne doivent pas fuir dans
 * un corps), `log_level: info` : l'issue ordinaire d'une page restée ouverte.
 */
final class ConsentAccountChangedException extends DomainException implements ProblemExceptionInterface
{
    use HasProblemType;

    public static function sinceThePageWasShown(): self
    {
        return new self('Le compte connecté a changé depuis l\'affichage de cette demande : relisez-la, puis décidez de nouveau.');
    }

    protected function problemType(): string
    {
        return 'consent-account-changed';
    }

    protected function problemStatus(): int
    {
        return 409;
    }
}
