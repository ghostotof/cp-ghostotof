<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\Runbook;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * La lecture du runbook (`k8s/README.md`) par les tests qui en pincent les
 * commandes, écrite une fois (relecture de #593 : OperatorExecBoundaryTest et
 * PostgresRolesProcedureTest en avaient chacun leur copie). Chaque cas est
 * éprouvé sur un texte que le test écrit.
 */
final class RunbookTest extends TestCase
{
    private const string TEXT = <<<'MD'
        # Titre

        Pour faire une chose :

        ```bash
        NS=
        kubectl -n "${NS:?ns}" exec -i deploy/x -c x -- \
          sh -c 'echo un'
        kubectl -n "${NS:?ns}" get pods
        ```

        Pour en faire une autre :

        ```bash
        kubectl -n "${NS:?ns}" exec deploy/y -- true
        ```
        MD;

    public function testTheRepositoryRunbookIsTheReadmeBesideTheManifests(): void
    {
        self::assertStringStartsWith('#', Runbook::repository()->text());
        self::assertStringContainsString(Runbook::ROLES_PROCEDURE, Runbook::repository()->text());
    }

    public function testTheBlockAfterAnOpeningPhraseIsTheFirstBashBlockFollowingIt(): void
    {
        $block = Runbook::of(self::TEXT)->bashBlockAfter('Pour en faire une autre');

        self::assertSame("kubectl -n \"\${NS:?ns}\" exec deploy/y -- true\n", $block);
    }

    public function testAnAbsentOpeningPhraseIsRefused(): void
    {
        $this->expectException(AssertionFailedError::class);

        Runbook::of(self::TEXT)->bashBlockAfter('Pour une chose absente');
    }

    /** Deux occurrences : le bloc lu dépendrait de l'ordre du texte. */
    public function testAnOpeningPhraseWrittenTwiceIsRefused(): void
    {
        $this->expectException(AssertionFailedError::class);

        Runbook::of(self::TEXT."\nPour faire une chose :\n")->bashBlockAfter('Pour faire une chose');
    }

    public function testAContinuedLineIsJoined(): void
    {
        self::assertSame("a b\nc", Runbook::joined("a \\\n   b\nc"));
    }

    /**
     * Les `kubectl … exec`, continuations rejointes ; un autre verbe n'en est
     * pas un.
     */
    public function testExecLinesAreTheKubectlExecCommandsOnly(): void
    {
        self::assertSame(
            [
                'kubectl -n "${NS:?ns}" exec -i deploy/x -c x -- sh -c \'echo un\'',
                'kubectl -n "${NS:?ns}" exec deploy/y -- true',
            ],
            Runbook::execLines(self::TEXT),
        );
    }

    /** Le bloc retiré est celui de la phrase, et lui seul. */
    public function testTheRunbookWithoutABlockKeepsEveryOtherOne(): void
    {
        $without = Runbook::of(self::TEXT)->withoutBashBlockAfter('Pour faire une chose');

        self::assertSame(['kubectl -n "${NS:?ns}" exec deploy/y -- true'], Runbook::execLines($without->text()));
        self::assertStringContainsString('Pour faire une chose', $without->text());
    }
}
