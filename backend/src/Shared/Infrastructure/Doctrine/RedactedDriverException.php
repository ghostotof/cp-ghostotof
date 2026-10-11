<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Driver\Exception;

/**
 * L'exception du pilote, son message rédigé, sa chaîne coupée : le
 * `previous` d'origine (PDOException) porte le message entier.
 *
 * Ce qui part : les lignes `DETAIL:` et `CONTEXT:` (clé en conflit, ligne en
 * échec, paramètre lié refusé), et la valeur citée en fin de ligne après un
 * deux-points (`invalid input syntax for type uuid: "…"`, `invalid input
 * value for enum …: "…"`), comme celle d'un `value "…" is out of range`. Ce
 * qui reste : le SQLSTATE, le code, et les identifiants que PostgreSQL cite
 * sans deux-points — contrainte, colonne, table —, qui disent où chercher.
 */
final class RedactedDriverException extends AbstractException
{
    private const string REDACTED = '"(valeur masquée)"';

    public static function of(Exception $exception): self
    {
        return new self(self::redact($exception->getMessage()), $exception->getSQLState(), $exception->getCode());
    }

    public static function redact(string $message): string
    {
        $redacted = preg_replace(
            [
                // Une ligne de détail ou de contexte, jusqu'à sa fin.
                '/^[ \t]*(?:DETAIL|CONTEXT):[^\r\n]*(?:\R|\z)/m',
                // Une valeur citée après un deux-points, en fin de ligne.
                '/: "[^\r\n]*"[ \t]*(?=\R|\z)/',
                '/\bvalue "[^\r\n]*?" is out of range/',
            ],
            [
                '',
                ': '.self::REDACTED,
                'value '.self::REDACTED.' is out of range',
            ],
            $message,
        );

        // Un échec de PCRE (message en UTF-8 invalide) ne rend pas le message
        // d'origine : il le tait entier.
        return null === $redacted ? '(message du pilote masqué)' : rtrim($redacted);
    }
}
