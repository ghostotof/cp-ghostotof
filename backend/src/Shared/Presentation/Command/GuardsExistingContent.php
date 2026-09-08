<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Empêche une commande de peuplement d'effacer du contenu déjà en place.
 *
 * Les cinq commandes `app:*:seed` **purgent avant de recréer** : c'est
 * volontaire, c'est ainsi qu'une entrée retirée du contenu de référence
 * disparaît vraiment. Mais cette même purge, jouée sur un environnement dont
 * le contenu a été édité au backoffice, le détruit sans avertissement — et rien
 * ne l'empêchait : ni confirmation, ni distinction entre préprod et production.
 *
 * La règle posée ici renverse la charge : **une base déjà peuplée est laissée
 * intacte**, et il faut `--force` pour la remplacer. Conséquences voulues :
 *
 *  - un environnement neuf se peuple tout seul, ce qui permet d'automatiser le
 *    peuplement de la préprod à chaque déploiement sans rien risquer ;
 *  - la production, qui a du contenu, devient intouchable par accident — y
 *    compris sur une erreur de namespace, le cas qui coûte le plus cher ;
 *  - la réinitialisation délibérée reste possible, mais elle s'écrit.
 *
 * **Le refus est un succès, pas un échec.** Ce détail porte tout le reste : un
 * code de sortie non nul ferait échouer le Job de peuplement — donc le
 * déploiement — à chaque passage après le premier. « Il y a déjà du contenu »
 * n'est pas une erreur, c'est la réponse attendue dans la quasi-totalité des
 * exécutions.
 */
trait GuardsExistingContent
{
    /**
     * À appeler depuis le `configure()` de la commande.
     */
    private function addForceOption(): void
    {
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Remplace le contenu existant par le contenu de référence (destructif).',
        );
    }

    /**
     * @param int $existing nombre d'entrées déjà présentes, toutes locales confondues
     *
     * @return bool vrai si la commande doit s'arrêter sans rien écrire
     */
    private function refusesToOverwrite(SymfonyStyle $io, InputInterface $input, int $existing): bool
    {
        if (0 === $existing || true === $input->getOption('force')) {
            return false;
        }

        $io->success(sprintf(
            '%d entrée(s) déjà en place : rien à faire. Relancer avec --force pour les remplacer par le contenu de référence (destructif).',
            $existing,
        ));

        return true;
    }
}
