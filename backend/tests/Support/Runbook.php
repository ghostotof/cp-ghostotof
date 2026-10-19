<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Le runbook (`k8s/README.md`) tel que les tests en lisent les commandes :
 * un bloc `bash` désigné par la phrase qui l'ouvre, les lignes continuées
 * (`\` en fin de ligne) rejointes, les `kubectl … exec`.
 *
 * Écrit une fois (relecture de #593) : OperatorExecBoundaryTest et
 * PostgresRolesProcedureTest en avaient chacun leur copie, qu'un changement
 * de forme du runbook aurait fait diverger. Une phrase d'ouverture doit être
 * **unique** : présente deux fois, le bloc lu dépendrait de l'ordre du texte,
 * et l'exemption de #593, qui porte sur la place d'un bloc, deviendrait
 * ambiguë. RunbookTest éprouve chaque cas.
 */
final readonly class Runbook
{
    /**
     * La phrase qui ouvre la procédure des rôles PostgreSQL (issue #593) :
     * l'unique `exec` du runbook qui écrit.
     */
    public const string ROLES_PROCEDURE = 'Pour créer ou rejouer les rôles';

    private function __construct(
        private string $text,
    ) {
    }

    /**
     * Le runbook du dépôt, à côté des manifestes.
     */
    public static function repository(): self
    {
        return new self(RepositoryFiles::read(RepositoryFiles::repository('k8s/README.md')));
    }

    /**
     * Un autre texte : celui qu'un test écrit pour éprouver la lecture.
     */
    public static function of(string $text): self
    {
        return new self($text);
    }

    public function text(): string
    {
        return $this->text;
    }

    /**
     * Le premier bloc `bash` qui suit la phrase, tel qu'écrit.
     */
    public function bashBlockAfter(string $opening): string
    {
        [$block] = $this->blockAfter($opening);

        return $block;
    }

    /**
     * Le runbook sans le bloc `bash` de la phrase, la phrase gardée.
     */
    public function withoutBashBlockAfter(string $opening): self
    {
        [, $fence, $at] = $this->blockAfter($opening);

        return new self(substr($this->text, 0, $at).substr($this->text, $at + \strlen($fence)));
    }

    /**
     * Le SQL qu'un bloc envoie à psql par un heredoc `<<'SQL'` … `SQL`, tel
     * qu'écrit, sans ses deux lignes de clôture.
     */
    public static function sqlHeredoc(string $block): string
    {
        Assert::assertSame(1, preg_match("/<<'SQL'\n(.*?)\nSQL$/ms", $block, $sql), 'Le bloc envoie un heredoc SQL à psql.');

        return $sql[1];
    }

    /**
     * Les lignes continuées d'un texte rejointes en une, par une seule espace
     * (les blancs autour de la coupure, indentation comprise, en font une).
     */
    public static function joined(string $text): string
    {
        return (string) preg_replace('/[ \t]*\\\\\n\s*/', ' ', $text);
    }

    /**
     * Les commandes `kubectl … exec` d'un texte, continuations rejointes.
     *
     * @return list<string>
     */
    public static function execLines(string $text): array
    {
        return array_values(array_filter(
            explode("\n", self::joined($text)),
            static fn (string $line): bool => 1 === preg_match('/\bkubectl\b.*\bexec\b/', $line),
        ));
    }

    /**
     * Le contenu du bloc, le bloc entier avec ses clôtures, et sa position.
     *
     * @return array{string, string, int}
     */
    private function blockAfter(string $opening): array
    {
        Assert::assertSame(1, substr_count($this->text, $opening), \sprintf('La phrase « %s » ouvre un bloc du runbook, une et une seule fois.', $opening));
        $start = (int) strpos($this->text, $opening);
        Assert::assertSame(1, preg_match('/```bash\n(.*?)```/s', $this->text, $match, PREG_OFFSET_CAPTURE, $start), \sprintf('Aucun bloc bash après « %s ».', $opening));

        return [$match[1][0], $match[0][0], $match[0][1]];
    }
}
