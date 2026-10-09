<?php

declare(strict_types=1);

/*
 * Processus fils de RateLimiterConcurrencyTest (issue #277) : consomme une
 * unité d'un limiteur de débit, en même temps que ses frères.
 *
 * Usage : php consume-rate-limiter.php <limiteur> <clé> <fichier-barrière>
 *
 * Déroulé :
 *  1. démarre le kernel de test et prépare le limiteur (tout ce qui est lent
 *     se fait avant la barrière, pour que les consume() se chevauchent) ;
 *  2. écrit « ready » sur sa sortie, puis attend un verrou partagé sur le
 *     fichier-barrière, que le test tient en exclusif tant que tous les fils
 *     ne sont pas prêts : le relâcher les libère tous au même instant ;
 *  3. consume(1) et écrit le nombre d'unités restantes ;
 *  4. code de sortie 0 si l'unité a été accordée, 1 sinon.
 *
 * Un processus séparé par consommateur est indispensable : un test qui
 * tiendrait lui-même le verrou du limiteur attendrait sans fin ses propres
 * fils (attente bloquante sur l'advisory lock).
 */

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

require dirname(__DIR__, 3).'/vendor/autoload.php';

/**
 * Donne au processus fils le même accès au conteneur qu'un test : KernelTestCase
 * résout la classe du kernel (KERNEL_CLASS) et expose les services privés,
 * dont `limiter.<nom>`. Jamais exécutée comme un test (pas de suffixe Test.php).
 */
final class RateLimiterFactoryLocator extends KernelTestCase
{
    public static function locate(string $limiterName): ?RateLimiterFactoryInterface
    {
        self::bootKernel();
        $factory = self::getContainer()->get('limiter.'.$limiterName);

        return $factory instanceof RateLimiterFactoryInterface ? $factory : null;
    }
}

$arguments = is_array($_SERVER['argv'] ?? null) ? array_values($_SERVER['argv']) : [];
[, $limiterName, $key, $barrierPath] = $arguments + [null, null, null, null];
if (!is_string($limiterName) || !is_string($key) || !is_string($barrierPath)) {
    fwrite(\STDERR, "Usage : php consume-rate-limiter.php <limiteur> <clé> <fichier-barrière>\n");
    exit(2);
}

// Même amorçage que tests/bootstrap.php : le test transmet APP_ENV=test, ce
// qui charge .env.test.local (DATABASE_URL, KERNEL_CLASS) et le même cache de
// conteneur.
(new Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');

$factory = RateLimiterFactoryLocator::locate($limiterName);
if (!$factory instanceof RateLimiterFactoryInterface) {
    fwrite(\STDERR, sprintf("limiter.%s n'est pas une fabrique de limiteur.\n", $limiterName));
    exit(2);
}
$limiter = $factory->create($key);

$barrier = fopen($barrierPath, 'r');
if (false === $barrier) {
    fwrite(\STDERR, sprintf("Fichier-barrière illisible : %s\n", $barrierPath));
    exit(2);
}

echo "ready\n";
flush();

// Bloque tant que le test tient son verrou exclusif.
flock($barrier, \LOCK_SH);

$rateLimit = $limiter->consume(1);

flock($barrier, \LOCK_UN);
fclose($barrier);

echo $rateLimit->getRemainingTokens()."\n";

exit($rateLimit->isAccepted() ? 0 : 1);
