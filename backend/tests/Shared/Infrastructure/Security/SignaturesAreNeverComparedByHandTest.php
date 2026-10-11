<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Security;

use App\Tests\Support\PhpSources;
use PHPUnit\Framework\TestCase;

/**
 * Un MAC de {@see \App\Shared\Infrastructure\Security\KeyedMac} ne se vérifie
 * que par `verifies()`, en temps constant, jamais en comparant soi-même le
 * résultat de `sign()` (issue #493) : `$mac->sign($x) === $fourni` dirait, au
 * temps qu'il met à échouer, combien de caractères du MAC fourni sont justes.
 *
 * `sign()` reste public — le cookie CSRF, l'empreinte, les enveloppes et le
 * cache en émettent —, et `ApplicationSecretUsesTest` ne voit que
 * `hash_equals()` : cette garde lit tout `src/` par jetons, instruction par
 * instruction (bornée par `;`, `{` ou `}`), et refuse une comparaison
 * ({@see self::COMPARISONS}) dans une instruction qui appelle `->sign(`, ou
 * qui lit une variable ou une propriété affectée depuis `->sign(` ailleurs
 * dans le même fichier.
 *
 * Ne voit pas, limite acceptée : un MAC passé à une autre méthode ou rendu à
 * l'appelant, puis comparé là-bas ; une méthode `sign()` d'une autre classe
 * y serait prise pour celle de KeyedMac (aucune aujourd'hui), au prix d'un
 * faux positif, jamais d'un faux négatif. La lecture ignore la portée : une
 * variable de même nom affectée depuis `sign()` dans une autre méthode
 * suffit à refuser, à renommer.
 */
final class SignaturesAreNeverComparedByHandTest extends TestCase
{
    /**
     * Les opérateurs et les fonctions qui comparent deux chaînes sans garantie
     * de temps constant. `match` compare son sujet à chaque bras par `===`.
     */
    private const array COMPARISONS = ['===', '!==', '==', '!=', '<>', '<=>', 'strcmp', 'strcasecmp', 'strncmp', 'strncasecmp', 'in_array', 'array_search', 'match'];

    /** Ce qui borne une instruction pour la garde. */
    private const array BOUNDARIES = [';', '{', '}'];

    public function testNoSignatureOfSrcIsComparedByHand(): void
    {
        $offenders = [];
        foreach (PhpSources::files(\dirname(__DIR__, 4).'/src') as $file) {
            $code = file_get_contents($file);
            self::assertIsString($code);
            if ($this->comparesASignature($code)) {
                $offenders[] = $file;
            }
        }

        self::assertSame([], $offenders, 'MAC de KeyedMac::sign() comparé à la main : vérifier par KeyedMac::verifies(), en temps constant (#493).');
    }

    public function testTheGuardSeesASignatureComparedDirectlyOrThroughAVariable(): void
    {
        self::assertTrue($this->comparesASignature('<?php if ($mac->sign($x) === $given) {}'));
        self::assertTrue($this->comparesASignature('<?php $expected = $mac->sign($x); if ($expected === $given) {}'));
        self::assertTrue($this->comparesASignature('<?php $expected = $mac->sign($x); return $given != $expected;'));
        self::assertTrue($this->comparesASignature('<?php return 0 === strcmp($mac->sign($x), $given);'));
        self::assertTrue($this->comparesASignature('<?php $expected = $mac->sign($x); return in_array($given, [$expected], true);'));
        self::assertTrue($this->comparesASignature('<?php $this->expected = $this->mac()->sign($x); return $this->expected == $given;'));
        self::assertTrue($this->comparesASignature('<?php $expected = $mac?->sign($x); return match ($given) { $expected => true, default => false };'));
        self::assertTrue($this->comparesASignature('<?php $ok = $mac->sign($x) <=> $given;'));
    }

    public function testTheGuardLetsTheRealUsesThrough(): void
    {
        self::assertFalse($this->comparesASignature('<?php return $mac->verifies($given, $x);'));
        self::assertFalse($this->comparesASignature("<?php return \$random.'.'.\$this->mac()->sign(\$random);"));
        self::assertFalse($this->comparesASignature('<?php if ($a === $b) { return $mac->sign($x); }'));
        self::assertFalse($this->comparesASignature('<?php $mac = KeyedMac::fromSecret($s, self::KEY_CONTEXT, MacEncoding::Hex); return substr($mac->sign($x), 0, 6);'));
        self::assertFalse($this->comparesASignature('<?php $signed[$id] = $this->mac()->sign($value).$value; return $signed;'));
        self::assertFalse($this->comparesASignature('<?php // if ($mac->sign($x) === $given) {}'));
        self::assertFalse($this->comparesASignature('<?php return $mac->signature() === $given;'));
    }

    /**
     * Une instruction qui compare un résultat de `->sign(` : directement, ou
     * par une cible (variable, propriété, case) qu'une autre instruction du
     * fichier affecte depuis `->sign(`.
     */
    private function comparesASignature(string $code): bool
    {
        $statements = $this->statements($code);
        $holders = [];
        foreach ($statements as $statement) {
            $target = $this->assignmentTarget($statement);
            if (null !== $target && $this->callsSign($statement)) {
                $holders[] = $target;
            }
        }

        return array_any(
            $statements,
            fn (array $statement): bool => $this->compares($statement)
                && ($this->callsSign($statement) || array_any($holders, fn (array $holder): bool => $this->contains($statement, $holder))),
        );
    }

    /**
     * Le code en instructions, chacune une liste de jetons significatifs. Le
     * bloc d'un `match` reste dans l'instruction qui l'ouvre : ses bras sont
     * comparés au sujet.
     *
     * @return list<list<string>>
     */
    private function statements(string $code): array
    {
        $statements = [[]];
        $matchDepth = 0;
        foreach (PhpSources::significantTokens($code) as $token) {
            $current = array_key_last($statements);
            if ($matchDepth > 0 || ('{' === $token->text && \in_array('match', $statements[$current], true))) {
                $statements[$current][] = $token->text;
                $matchDepth += match ($token->text) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };

                continue;
            }
            // Une balise PHP borne aussi : `<?php` est un jeton significatif.
            if (\in_array($token->text, self::BOUNDARIES, true) || $token->is([\T_OPEN_TAG, \T_CLOSE_TAG])) {
                $statements[] = [];

                continue;
            }
            $statements[$current][] = $token->text;
        }

        return array_values(array_filter($statements, static fn (array $statement): bool => [] !== $statement));
    }

    /**
     * Ce qu'affecte l'instruction (`$x`, `$this->x`, `$x[$id]`…), jetons
     * avant le premier `=`, ou null si elle n'affecte rien.
     *
     * @param list<string> $statement
     *
     * @return list<string>|null
     */
    private function assignmentTarget(array $statement): ?array
    {
        $equals = array_search('=', $statement, true);

        return false === $equals || 0 === $equals ? null : \array_slice($statement, 0, $equals);
    }

    /**
     * @param list<string> $statement
     */
    private function callsSign(array $statement): bool
    {
        return array_any(
            array_keys($statement),
            static fn (int $i): bool => \in_array($statement[$i], ['->', '?->'], true) && 'sign' === ($statement[$i + 1] ?? null) && '(' === ($statement[$i + 2] ?? null),
        );
    }

    /**
     * @param list<string> $statement
     */
    private function compares(array $statement): bool
    {
        return [] !== array_intersect($statement, self::COMPARISONS);
    }

    /**
     * @param list<string> $statement
     * @param list<string> $needle
     */
    private function contains(array $statement, array $needle): bool
    {
        return array_any(
            array_keys($statement),
            static fn (int $i): bool => \array_slice($statement, $i, \count($needle)) === $needle,
        );
    }
}
