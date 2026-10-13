<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Command;

use Closure;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Empêche une commande de peuplement d'effacer du contenu déjà en place.
 *
 * Les commandes `app:*:seed` **purgent avant de recréer** : c'est
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
 *
 * **Un peuplement est atomique, par commande** (issue #567). Le décompte, la
 * purge et les recréations tournent dans **une** transaction, sous le verrou
 * d'ordre de chaque périmètre écrit
 * ({@see \App\Portfolio\Shared\Application\OrderScopeLockInterface::withLocks()}),
 * d'où {@see self::seedUnlessPopulated()}, le corps que la commande confie au
 * verrou. Avant, la purge validait ligne par ligne hors verrou et chaque
 * `Administrator::create()` dans sa propre transaction : une panne à
 * mi-parcours laissait une base partielle — avec `--force`, le contenu
 * d'avant détruit et quelques entrées neuves ; sur une base vide, quelques
 * lignes que le garde-fou tenait ensuite pour « du contenu en place », de
 * sorte que le Job de préprod relancé déclinait en code 0 sur une page à
 * moitié vide, et pour À propos et Qualité, qui écrivent deux tables, la
 * seconde restait vide pour de bon. Désormais un échec n'écrit rien, et la
 * relance repeuple. Le décompte se relit **sous le verrou** : lu avant, deux
 * seeds simultanés d'une base vide la peuplaient tous les deux. Le compte
 * rendu ({@see SeedOutcome}) s'affiche après le retour de la transaction.
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
     * Le corps d'un peuplement, à exécuter sous le verrou de ses périmètres :
     * relit le décompte, garde le contenu en place sans `--force`, sinon
     * purge puis recrée. La purge doit être écrite (`flush()`) avant toute
     * création : dans un même `flush()`, l'UnitOfWork exécute les `INSERT`
     * avant les `DELETE`, et un index unique (le `slug` des produits
     * surveillés) refuserait la recréation.
     *
     * @param Closure(): int          $countExisting nombre d'entrées déjà présentes, toutes locales confondues
     * @param Closure(): list<string> $seed          purge puis recrée ; rend les lignes du compte rendu
     */
    private function seedUnlessPopulated(InputInterface $input, Closure $countExisting, Closure $seed): SeedOutcome
    {
        $existing = $countExisting();

        if ($existing > 0 && !$this->forcesOverwrite($input)) {
            return SeedOutcome::kept($existing);
        }

        return SeedOutcome::seeded($seed());
    }

    /**
     * Vrai si l'opérateur a demandé le remplacement (`--force`). Seule lecture
     * de l'option : une commande qui doit aussi épargner une donnée exclue du
     * décompte — donc écrite même quand le garde-fou laisse passer sans
     * `--force` — la consulte ici plutôt que de relire `force` elle-même.
     */
    private function forcesOverwrite(InputInterface $input): bool
    {
        return true === $input->getOption('force');
    }
}
