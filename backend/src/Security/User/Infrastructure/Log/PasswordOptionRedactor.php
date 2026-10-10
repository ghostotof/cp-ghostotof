<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Log;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use SensitiveParameter;

/**
 * Masque la valeur de `--password` dans les lignes du canal `console`
 * (revue de #386).
 *
 * `app:user:create` lit le mot de passe sur l'entrée standard depuis #386 ;
 * l'option `--password` n'existe plus. Mais un opérateur qui la tape par
 * habitude provoque une InvalidOptionException, que le ErrorListener de la
 * console journalise en `critical` avec l'argv reconstitué (`command`), puis
 * la sortie non nulle en `debug` — le mot de passe en clair, à chaque fois,
 * dans `var/log/dev.log` en dev. Même chose pour une valeur donnée par erreur
 * à `--password-stdin`.
 *
 * Le contexte `command` et le message, au cas où un processeur l'aurait déjà
 * interpolé : une valeur qui n'est pas un mot y est entre apostrophes, passée
 * par `escapeshellarg()` (ArgvInput et ArrayInput), apostrophe interne
 * comprise (`'\''`). `--password-stdin` sans valeur, le bon usage, est laissé
 * tel quel : l'option qui le suit n'a rien de secret.
 */
#[AsMonologProcessor(channel: 'console')]
final class PasswordOptionRedactor
{
    private const string PASSWORD_VALUE = "/(--password(?:-stdin)?=|--password\\s+)(?:'(?:[^']|'\\\\'')*'|\\S+)/";

    private const string MASK = '${1}***';

    public function __invoke(LogRecord $record): LogRecord
    {
        $command = $record->context['command'] ?? null;

        if (!\is_string($command)) {
            return $record;
        }

        return $record->with(
            message: $this->redact($record->message),
            context: ['command' => $this->redact($command)] + $record->context,
        );
    }

    /** `$text` porte l'argv en clair, mot de passe compris (issue #414). */
    private function redact(#[SensitiveParameter] string $text): string
    {
        return (string) preg_replace(self::PASSWORD_VALUE, self::MASK, $text);
    }
}
