<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Infrastructure\Http;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use PHPUnit\Framework\TestCase;

/**
 * Contre-audit, point 11 : AssistantProblemResponseListener renvoie au client
 * le `detail` (le message) de toute ProblemExceptionInterface levée sous
 * /api/assistant. Un message construit avec ce que le client a envoyé (une
 * borne D6 qui citerait le message trop long, par exemple) le renverrait tel
 * quel. Garde-fou : dans src/, chaque construction d'une de ces exceptions ne
 * reçoit qu'une chaîne littérale, ou rien.
 */
final class ProblemDetailStaysStaticTest extends TestCase
{
    private const string SOURCES = __DIR__.'/../../../../../src';
    private const string ASSISTANT_EXCEPTIONS = self::SOURCES.'/Ai/Assistant/Domain/Exception';

    public function testEveryAssistantProblemIsBuiltWithALiteralMessageOnly(): void
    {
        $problems = $this->assistantProblemClasses();
        self::assertNotEmpty($problems, 'Aucune ProblemExceptionInterface trouvée : le garde-fou ne garderait rien.');

        $violations = [];
        foreach ($this->sourceFiles() as $path => $code) {
            foreach ($this->dynamicConstructions($code, $problems) as $construction) {
                $violations[] = $path.' : '.$construction;
            }
        }

        self::assertSame([], $violations);
    }

    /** Le détecteur lui-même : sans cette preuve, il pourrait être vert pour de mauvaises raisons. */
    public function testTheDetectorCatchesADynamicMessage(): void
    {
        $code = <<<'PHP'
            throw new InvalidConversationException('La conversation est vide.');
            throw new InvalidConversationException();
            throw new InvalidConversationException('Trop long : '.$content);
            throw new InvalidConversationException(sprintf('%s', $content));
            PHP;

        self::assertCount(2, $this->dynamicConstructions($code, ['InvalidConversationException']));
    }

    /**
     * @return list<string> noms courts des exceptions de l'assistant rendues au client
     */
    private function assistantProblemClasses(): array
    {
        $classes = [];
        foreach (glob(self::ASSISTANT_EXCEPTIONS.'/*.php') ?: [] as $file) {
            $short = basename($file, '.php');
            $class = 'App\\Ai\\Assistant\\Domain\\Exception\\'.$short;
            if (is_subclass_of($class, ProblemExceptionInterface::class)) {
                $classes[] = $short;
            }
        }

        return $classes;
    }

    /**
     * @return iterable<string, string>
     */
    private function sourceFiles(): iterable
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SOURCES, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                yield $file->getPathname() => (string) file_get_contents($file->getPathname());
            }
        }
    }

    /**
     * Les constructions dont l'argument n'est ni absent ni une seule chaîne
     * littérale sans interpolation.
     *
     * @param list<string> $classes
     *
     * @return list<string>
     */
    private function dynamicConstructions(string $code, array $classes): array
    {
        $dynamic = [];
        foreach ($classes as $class) {
            preg_match_all('/new\s+\\\\?(?:[\w\\\\]+\\\\)?'.preg_quote($class, '/').'\s*\((?<args>[^;]*?)\)\s*;/', $code, $matches);
            foreach ($matches['args'] as $index => $arguments) {
                if (1 !== preg_match('/^\s*(?:\'(?:[^\'\\\\]|\\\\.)*\'|"[^"$\\\\]*")?\s*$/', $arguments)) {
                    $dynamic[] = $matches[0][$index];
                }
            }
        }

        return $dynamic;
    }
}
