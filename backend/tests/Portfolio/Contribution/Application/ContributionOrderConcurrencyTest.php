<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Contribution\Application;

use App\Portfolio\Contribution\Application\ContributionAdministratorInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\FlushInterleaving;
use App\Tests\Support\ProcessBarrier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * Une écriture du périmètre d'ordre ne peut pas en défaire une autre (issue #389),
 * ni une suppression valider sous elle (issue #499).
 *
 * Deux écritures concurrentes, A dans ce processus, B dans un processus fils
 * (Fixtures/create-contribution.php pour une création, Fixtures/delete-contribution.php
 * pour une suppression) : l'une tiendrait sinon le verrou que l'autre attend.
 * L'entrelacement est explicite, pas laissé au hasard :
 *
 *  1. B démarre le kernel puis attend la barrière (ProcessBarrier) ;
 *  2. A lit ce dont il a besoin et calcule son écriture ; au `preFlush` de son
 *     écriture — le dernier instant avant qu'elle n'atteigne la base —, le
 *     test relâche la barrière ;
 *  3. le test attend que B ait **fini** ou que sa session (pid annoncé avec
 *     son « ready ») soit **bloquée sur un verrou** PostgreSQL, puis laisse A
 *     écrire ;
 *  4. une fois B terminé, le test relit la table, ou ce que B a écrit.
 *
 * Sans verrou de périmètre, B lit l'état antérieur à A et termine pendant la
 * pause : le résultat est celui de la course. Avec, B attend A et relit après
 * lui. Dans les deux cas le verdict est le même à chaque passage.
 */
final class ContributionOrderConcurrencyTest extends KernelTestCase
{
    /** Borne de chaque attente : un verrou jamais relâché fait échouer le test au lieu de le suspendre. */
    private const int TIMEOUT_SECONDS = 30;

    private const string WRITER_SCRIPT = __DIR__.'/../Fixtures/create-contribution.php';

    private const string DELETER_SCRIPT = __DIR__.'/../Fixtures/delete-contribution.php';

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM contribution');
        parent::tearDown();
    }

    /**
     * Le scénario de l'issue : « Créer la version EN » de G pendant un
     * `reorder()` qui déplace G. La version EN doit hériter de la position
     * que le réordonnancement donne à G, pas de celle qu'il quitte.
     */
    public function testACreationInAGroupDuringAReorderInheritsTheGroupsNewPosition(): void
    {
        self::bootKernel();
        $administrator = $this->administrator();
        $moved = $administrator->create(Locale::FR, 'Déplacée', 'P', 'R', 'https://example.com/a', 'S', 'B');
        $other = $administrator->create(Locale::FR, 'Autre', 'P', 'R', 'https://example.com/b', 'S', 'B');
        $group = $moved->getTranslationGroup();

        $writer = $this->interleaveBeforeNextFlush(self::WRITER_SCRIPT, Locale::EN->value, $group->toRfc4122());
        $administrator->reorder([$other->getTranslationGroup()->toRfc4122(), $group->toRfc4122()]);
        $this->awaitSuccess($writer);

        self::assertSame(
            [1, 1],
            $this->positionsOf($group->toRfc4122()),
            'Le groupe déplacé porte deux positions : la version créée pendant le réordonnancement a hérité de la position que le groupe quittait (#389).',
        );
    }

    /**
     * La course voisine que l'issue demandait de confirmer : deux créations
     * sans groupe lisent le même `max`, et `atEndOf()` leur donne la même
     * position — deux clés sur une position.
     */
    public function testTwoSimultaneousCreationsWithoutAGroupGetDistinctPositions(): void
    {
        self::bootKernel();
        $administrator = $this->administrator();
        $administrator->create(Locale::FR, 'Existante', 'P', 'R', 'https://example.com/a', 'S', 'B');

        $writer = $this->interleaveBeforeNextFlush(self::WRITER_SCRIPT, Locale::FR->value);
        $administrator->create(Locale::FR, 'Créée par A', 'P', 'R', 'https://example.com/c', 'S', 'B');
        $this->awaitSuccess($writer);

        self::assertSame(
            [0, 1, 2],
            $this->allPositions(),
            'Deux créations simultanées sans groupe ont reçu la même position de fin de périmètre (#389).',
        );
    }

    /**
     * Le scénario de l'issue #499 : un `DELETE` de E pendant le `PUT` de E,
     * tenu au `preFlush` — relu sous verrou (#402), rien encore d'écrit.
     *
     * Hors du verrou, le `DELETE` validait pendant la pause : l'`UPDATE` du
     * `PUT` ne touchait plus aucune ligne, et le `PUT` répondait 200 en
     * décrivant une entrée disparue. Sous le verrou, le `DELETE` attend le
     * `PUT`, relit E sous verrou et supprime ce que le `PUT` a écrit : il
     * passe après lui, et le 200 décrivait bien une entrée en base.
     *
     * Les deux ordres laissent la même table (E supprimée) : le verdict est le
     * titre que portait E quand le `DELETE` l'a supprimée, que le fils écrit.
     */
    public function testADeletionDuringAnUpdateWaitsForItInsteadOfCommittingUnderIt(): void
    {
        self::bootKernel();
        $administrator = $this->administrator();
        $entry = $administrator->create(Locale::FR, 'Avant le PUT', 'P', 'R', 'https://example.com/a', 'S', 'B');
        $id = $entry->getId();

        $deleter = $this->interleaveBeforeNextFlush(self::DELETER_SCRIPT, $id->toRfc4122());
        $administrator->update($id, 'Écrit par le PUT', 'P', 'R', 'https://example.com/a', 'S', 'B', $entry->getTranslationGroup());
        $this->awaitSuccess($deleter);

        self::assertSame(
            'Écrit par le PUT',
            $this->deletedTitleReportedBy($deleter),
            "Le DELETE a validé pendant le PUT, qui a répondu 200 sur une entrée disparue : il n'a pas attendu le verrou du périmètre (#499).",
        );
        self::assertSame([], $this->allPositions(), "L'entrée n'a pas été supprimée.");
    }

    private function administrator(): ContributionAdministratorInterface
    {
        return self::getContainer()->get(ContributionAdministratorInterface::class);
    }

    /**
     * Démarre B — le script fils donné, avec ses arguments après la barrière —
     * et le relâche au prochain `preFlush` ({@see FlushInterleaving}).
     */
    private function interleaveBeforeNextFlush(string $script, string ...$arguments): Process
    {
        $barrier = ProcessBarrier::hold('order-barrier-');

        $child = ChildPhpProcess::inTestEnvironment(self::TIMEOUT_SECONDS, $script, $barrier->path(), ...$arguments);
        $child->start();
        FlushInterleaving::releaseBeforeNextFlush(self::getContainer()->get(EntityManagerInterface::class), $barrier, $child, self::TIMEOUT_SECONDS);

        return $child;
    }

    private function awaitSuccess(Process $child): void
    {
        $child->wait();
        self::assertSame(
            0,
            $child->getExitCode(),
            \sprintf("Le processus concurrent a échoué :\n%s%s", $child->getOutput(), $child->getErrorOutput()),
        );
    }

    /**
     * Le titre que le fils annonce avoir supprimé, sur sa ligne
     * « titre-supprimé: … » : ni le « ready » de la barrière ni une sortie
     * parasite (un avertissement à l'arrêt du kernel) ne le faussent.
     */
    private function deletedTitleReportedBy(Process $child): ?string
    {
        return 1 === preg_match('/^titre-supprimé: (.*)$/mu', $child->getOutput(), $match) ? $match[1] : null;
    }

    /**
     * @return list<int>
     */
    private function positionsOf(string $group): array
    {
        return $this->positions('SELECT position FROM contribution WHERE translation_group = ? ORDER BY locale', [$group]);
    }

    /**
     * @return list<int>
     */
    private function allPositions(): array
    {
        return $this->positions('SELECT position FROM contribution ORDER BY position');
    }

    /**
     * @param list<string> $parameters
     *
     * @return list<int>
     */
    private function positions(string $sql, array $parameters = []): array
    {
        $positions = [];
        foreach (self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchFirstColumn($sql, $parameters) as $position) {
            self::assertIsInt($position);
            $positions[] = $position;
        }

        return $positions;
    }
}
