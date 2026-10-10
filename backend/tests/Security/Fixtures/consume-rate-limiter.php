<?php

declare(strict_types=1);

/*
 * Processus fils de RateLimiterConcurrencyTest (issue #277) : consomme une
 * unité d'un limiteur de débit, en même temps que ses frères.
 *
 * Usage : php consume-rate-limiter.php <limiteur> <clé> <fichier-barrière>
 *
 * Déroulé :
 *  1. démarre le kernel de test, prépare le limiteur et ouvre ses deux
 *     connexions PostgreSQL (cache.app et le verrou advisory, toutes deux
 *     paresseuses) par un consume(0) : tout ce qui est lent ou de durée
 *     variable se fait avant la barrière, pour que les consume(1) se
 *     chevauchent ;
 *  2. écrit « ready » sur sa sortie, puis attend un verrou partagé sur le
 *     fichier-barrière, que le test tient en exclusif tant que tous les fils
 *     ne sont pas prêts : le relâcher les libère tous au même instant ;
 *  3. consume(1) et écrit le nombre d'unités restantes ;
 *  4. code de sortie 0 si l'unité a été accordée, 1 si elle a été refusée,
 *     2 si le fils n'a pas pu se mettre en place (rien n'a été consommé).
 *
 * Un processus séparé par consommateur est indispensable : un test qui
 * tiendrait lui-même le verrou du limiteur attendrait sans fin ses propres
 * fils (attente bloquante sur l'advisory lock).
 */

use App\Tests\Support\RateLimiterFactoryLocator;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

// Même amorçage que tous les tests (autoload, Dotenv, umask). Le test transmet
// APP_ENV=test : Dotenv charge .env.test.local (DATABASE_URL, KERNEL_CLASS),
// et le kernel retrouve le cache de conteneur que le test a déjà compilé.
require dirname(__DIR__, 2).'/bootstrap.php';

$arguments = is_array($_SERVER['argv'] ?? null) ? array_values($_SERVER['argv']) : [];
[, $limiterName, $key, $barrierPath] = $arguments + [null, null, null, null];
if (!is_string($limiterName) || !is_string($key) || !is_string($barrierPath)) {
    fwrite(\STDERR, "Usage : php consume-rate-limiter.php <limiteur> <clé> <fichier-barrière>\n");
    exit(2);
}

$factory = RateLimiterFactoryLocator::locate($limiterName);
if (!$factory instanceof RateLimiterFactoryInterface) {
    fwrite(\STDERR, sprintf("limiter.%s n'est pas une fabrique de limiteur.\n", $limiterName));
    exit(2);
}
$limiter = $factory->create($key);

// Ouvre les connexions sans rien décompter. Sans cela, la poignée de main TCP
// et l'authentification PostgreSQL auraient lieu après la barrière, et leur
// durée, variable d'un fils à l'autre sur un runner chargé, étalerait les
// consume(1) au point de masquer une course.
$limiter->consume(0);

$barrier = fopen($barrierPath, 'r');
if (false === $barrier) {
    fwrite(\STDERR, sprintf("Fichier-barrière illisible : %s\n", $barrierPath));
    exit(2);
}

echo "ready\n";
flush();

// Bloque tant que le test tient son verrou exclusif. Un échec (système de
// fichiers sans flock) laisserait passer ce fils sans synchronisation : le
// test pourrait alors réussir sans avoir mis les consommateurs en concurrence.
if (!flock($barrier, \LOCK_SH)) {
    fwrite(\STDERR, sprintf("Impossible d'attendre la barrière : flock(LOCK_SH) a échoué sur %s\n", $barrierPath));
    exit(2);
}

$rateLimit = $limiter->consume(1);

flock($barrier, \LOCK_UN);
fclose($barrier);

echo $rateLimit->getRemainingTokens()."\n";

exit($rateLimit->isAccepted() ? 0 : 1);
