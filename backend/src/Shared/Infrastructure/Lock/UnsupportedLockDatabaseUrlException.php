<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Lock;

/**
 * `DATABASE_URL` n'est pas une URL PostgreSQL : aucun verrou partagé entre
 * pods ne peut en être dérivé (issue #272).
 *
 * Levée à la résolution de la variable d'environnement, c'est-à-dire à la
 * première instanciation du verrou — la première requête qui atteint un
 * limiteur, login compris : mieux vaut une 500 bruyante, que le smoke test de
 * la préprod attrape, qu'un limiteur qui tourne sans verrou. Le message ne
 * reprend jamais l'URL, qui porte le mot de passe de la base.
 */
final class UnsupportedLockDatabaseUrlException extends \InvalidArgumentException
{
    /**
     * La syntaxe d'un schéma d'URI (RFC 3986, § 3.1). Tout ce qui s'en écarte
     * n'est pas repris : une URL sans schéma ferait passer pour « schéma » ce
     * qui précède un `://` plus loin, identifiants compris.
     */
    private const string SCHEME_SYNTAX = '/^[a-z][a-z0-9+.-]*$/i';

    public static function forScheme(string $scheme): self
    {
        return new self(\sprintf(
            'Le verrou des limiteurs de débit exige une URL PostgreSQL (postgresql://, postgres:// ou pgsql://) ; schéma reçu : "%s".',
            1 === preg_match(self::SCHEME_SYNTAX, $scheme) ? $scheme : '<invalide>',
        ));
    }
}
