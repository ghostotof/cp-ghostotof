<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * L'issue d'un `app:*:seed`, rendue par la transaction qui l'a décidée et
 * affichée **après** son COMMIT (issue #567) : un compte rendu écrit pendant
 * la transaction annoncerait des lignes qu'un refus du COMMIT, ou une panne
 * plus loin dans la commande, aurait ensuite annulées.
 *
 * Deux issues, toutes deux un succès de la commande (le refus de remplacer du
 * contenu sort en code 0, voir {@see GuardsExistingContent}) : le contenu en
 * place a été gardé, ou le contenu de référence a été posé.
 */
final readonly class SeedOutcome
{
    /**
     * @param int          $kept  les entrées laissées en place, 0 si le contenu a été posé
     * @param list<string> $lines le compte rendu du contenu posé, une ligne par locale ou par catalogue
     */
    private function __construct(
        private int $kept,
        private array $lines,
    ) {
    }

    /**
     * Du contenu était en place et `--force` n'a pas été demandé : rien n'a
     * été écrit.
     */
    public static function kept(int $existing): self
    {
        return new self($existing, []);
    }

    /**
     * @param list<string> $lines
     */
    public static function seeded(array $lines): self
    {
        return new self(0, $lines);
    }

    /**
     * @return int le code de sortie de la commande
     */
    public function report(SymfonyStyle $io): int
    {
        if ($this->kept > 0) {
            $io->success(\sprintf(
                '%d entrée(s) déjà en place : rien à faire. Relancer avec --force pour les remplacer par le contenu de référence (destructif).',
                $this->kept,
            ));

            return Command::SUCCESS;
        }

        foreach ($this->lines as $line) {
            $io->success($line);
        }

        return Command::SUCCESS;
    }
}
