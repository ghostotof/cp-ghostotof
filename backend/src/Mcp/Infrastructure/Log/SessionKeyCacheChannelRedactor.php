<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Log;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Throwable;

/**
 * Retire l'identifiant de session MCP des lignes que Symfony Cache écrit sur
 * son canal `cache` (#731, constat C2 de la relecture de la tâche 4, décision
 * du 2026-11-10).
 *
 * Les sessions vivent dans le pool DBAL `cache.mcp_sessions`, sous la clé
 * PSR-16 `mcp-portfolio-<identifiant de session>` : le store du SDK colle
 * l'identifiant derrière le préfixe que le bundle lui donne. Quand une
 * opération du pool échoue (PostgreSQL injoignable, verrou dépassé), Symfony
 * Cache l'écrit en `warning` — « Failed to fetch key "{key}" », « Failed to
 * check if key "{key}" is cached », « Failed to save key "{key}" », « Failed
 * to delete key "{key}" » —, la clé dans le contexte (`key`, ou `keys` pour
 * une lecture groupée) avec l'exception. Le handler `main` de production
 * reçoit ce canal (monolog.yaml), à `warning` en prod et à `debug` en
 * préprod : l'identifiant, qui vaut accès à la session, partait dans les
 * journaux, contre la règle du contexte (`.claude/rules/mcp-journaux.md` :
 * jamais d'identifiant de session dans un journal).
 *
 * Seuls les enregistrements qui désignent une session MCP sont réécrits, et
 * ils restent écrits : la clé devient `mcp-portfolio-` suivi de
 * {@see self::WITHHELD}, le préfixe disant encore quel pool a échoué.
 * L'identifiant est retiré partout où l'enregistrement le porte :
 *
 *  - `key` et chaque entrée de `keys` ;
 *  - le message. `{key}` n'y est pas encore remplacé — l'interpolation est
 *    celle du handler (PsrLogMessageProcessor), après les processeurs du
 *    canal, donc sur la clé déjà réécrite —, mais Symfony Cache y colle le
 *    message de l'exception ;
 *  - toute exception du contexte : elle devient sa forme normalisée (classe,
 *    message, code, fichier:ligne, `previous`, celle du formateur JSON sans
 *    la pile), chaque message réécrit. Toute, et pas seulement celle dont un
 *    message cite l'identifiant : le formateur normalise une exception
 *    `JsonSerializable` par ce que renvoie `jsonSerialize()`, et un
 *    `SoapFault` avec ses champs (faultcode, faultactor, detail), qui peuvent
 *    porter la clé sans que son message la cite (constat n°1 de l'audit de
 *    sécurité de la PR 2 de #731). La forme normalisée n'en garde que le
 *    message, réécrit. PostgreSQL cite les paramètres liés dans le
 *    `CONTEXT:` de son erreur dès que `log_parameter_max_length_on_error`
 *    n'est pas nul (mesuré), et le paramètre est la clé ; sur les connexions
 *    Doctrine,
 *    {@see \App\Shared\Infrastructure\Doctrine\DriverErrorRedactionMiddleware}
 *    retire déjà cette ligne.
 *
 * Tout autre enregistrement sort tel quel, le même objet : la clé d'un
 * limiteur ou du signal de redémarrage des workers est le signal
 * d'exploitation d'une panne de cache. C'est pourquoi un processeur plutôt
 * que l'exclusion `!cache` du handler `main`, qui aurait fait taire toutes
 * les pannes de tous les pools. Et il ne coûte rien à ces enregistrements :
 * une lecture de `key` et de `keys`, un test de préfixe, aucune expression
 * régulière.
 *
 * Le préfixe ne peut pas être lu du conteneur : le bundle de la version
 * épinglée (composer.json) le calcule dans une méthode privée
 * (`mcp-<nom du serveur>-`, faute de `session.prefix` dans mcp.yaml) et le
 * passe en argument au store, sans paramètre du conteneur.
 * {@see self::SESSION_KEY_PREFIX} l'écrit donc une seconde fois, dérivé de la
 * même source — le nom du serveur, `portfolio` —, et
 * {@see \App\Tests\Mcp\Infrastructure\Log\SessionKeyCacheChannelRedactorTest}
 * le tient : il fait échouer le vrai store du conteneur sur le vrai pool et
 * lit tous les canaux. Un préfixe que le bundle changerait ne serait plus
 * reconnu, l'identifiant sortirait, et ce test rougirait.
 *
 * Non couvert : une ligne du canal qui citerait une clé de session sans la
 * porter dans `key` ni dans `keys`. Aucun site de Symfony Cache n'écrit de
 * clé autrement, à la version installée (composer.lock).
 */
#[AsMonologProcessor(channel: 'cache')]
final class SessionKeyCacheChannelRedactor
{
    /**
     * Le préfixe des clés du store de sessions du serveur `portfolio`, tel
     * que le bundle le forme.
     */
    public const string SESSION_KEY_PREFIX = 'mcp-portfolio-';

    public const string WITHHELD = '[withheld, see issue #731]';

    public function __invoke(LogRecord $record): LogRecord
    {
        $identifiers = $this->sessionIdentifiers($record->context);
        if ([] === $identifiers) {
            return $record;
        }

        $context = [];
        foreach ($record->context as $name => $value) {
            $context[$name] = match (true) {
                \is_string($value) => self::without($identifiers, $value),
                \is_array($value) => array_map(static fn (mixed $entry): mixed => \is_string($entry) ? self::without($identifiers, $entry) : $entry, $value),
                $value instanceof Throwable => self::normalizedWithout($identifiers, $value),
                default => $value,
            };
        }

        return $record->with(message: self::without($identifiers, $record->message), context: $context);
    }

    /**
     * Les identifiants de session que l'enregistrement désigne : ce qui suit
     * le préfixe dans `key` et dans chaque entrée de `keys`.
     *
     * @param array<array-key, mixed> $context
     *
     * @return list<string>
     */
    private function sessionIdentifiers(array $context): array
    {
        $keys = \is_array($context['keys'] ?? null) ? $context['keys'] : [];
        $keys[] = $context['key'] ?? null;

        $identifiers = [];
        foreach ($keys as $key) {
            // Le préfixe seul ne désigne aucune session, et il n'y a rien à
            // en retirer.
            if (\is_string($key) && str_starts_with($key, self::SESSION_KEY_PREFIX) && self::SESSION_KEY_PREFIX !== $key) {
                $identifiers[] = substr($key, \strlen(self::SESSION_KEY_PREFIX));
            }
        }

        return $identifiers;
    }

    /**
     * @param list<string> $identifiers
     */
    private static function without(array $identifiers, string $text): string
    {
        return str_replace($identifiers, self::WITHHELD, $text);
    }

    /**
     * La forme que JsonFormatter donne à une exception (sans la pile, comme
     * en production), l'identifiant retiré de chaque message.
     *
     * @param list<string> $identifiers
     *
     * @return array<string, mixed>
     */
    private static function normalizedWithout(array $identifiers, Throwable $exception): array
    {
        $normalized = [
            'class' => $exception::class,
            'message' => self::without($identifiers, $exception->getMessage()),
            'code' => $exception->getCode(),
            'file' => $exception->getFile().':'.$exception->getLine(),
        ];

        $previous = $exception->getPrevious();
        if (null !== $previous) {
            $normalized['previous'] = self::normalizedWithout($identifiers, $previous);
        }

        return $normalized;
    }
}
