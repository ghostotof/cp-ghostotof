#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Résumé de job GitHub Actions pour PHPUnit, à partir de son rapport JUnit.
 *
 * Usage : php summary-phpunit.php var/log/junit.xml >> "$GITHUB_STEP_SUMMARY"
 *
 * Vitest écrit sa propre carte quand il tourne sous Actions (reporter
 * `github-actions` intégré) ; PHPUnit ne le fait pas. Ce script comble l'écart
 * sans dépendance : `--log-junit` est natif, et le Markdown tient en une table.
 *
 * Lancé en `if: always()` **après** l'étape PHPUnit : il ne décide jamais du
 * statut du job (toujours exit 0) — c'est l'étape PHPUnit qui échoue, ce script
 * ne fait que raconter. Sans rapport (PHPUnit tombé avant d'écrire le fichier),
 * il le dit plutôt que de se taire.
 *
 * Standalone, sans kernel Symfony ni autoload : PHP seul, comme
 * bin/build-package-manifest.php.
 */

$path = $argv[1] ?? null;

if (null === $path || !is_file($path)) {
    echo "## PHPUnit\n\n";
    echo "⚠️ Aucun rapport JUnit trouvé (`{$path}`) : PHPUnit ne s'est pas exécuté jusqu'au bout — voir le log de l'étape.\n";
    exit(0);
}

libxml_use_internal_errors(true);
$xml = simplexml_load_file($path);

if (false === $xml || !isset($xml->testsuite)) {
    echo "## PHPUnit\n\n";
    echo "⚠️ Rapport JUnit illisible (`{$path}`).\n";
    exit(0);
}

// PHPUnit imbrique tout sous une suite racine ("CLI Arguments" ou le nom de
// la suite XML) qui totalise ses enfants : ses attributs sont les totaux.
$root = $xml->testsuite[0];
$total = (int) $root['tests'];
$assertions = (int) $root['assertions'];
$errors = (int) $root['errors'];
$failures = (int) $root['failures'];
$skipped = (int) $root['skipped'];
$time = (float) $root['time'];
$passed = $total - $errors - $failures - $skipped;

$ok = 0 === $errors && 0 === $failures;
$icon = $ok ? '✅' : '❌';

echo "## {$icon} PHPUnit\n\n";
echo "| Tests | Réussis | Échecs | Erreurs | Ignorés | Assertions | Durée |\n";
echo "|---:|---:|---:|---:|---:|---:|---:|\n";
printf("| %d | %d | %d | %d | %d | %d | %.1f s |\n\n", $total, $passed, $failures, $errors, $skipped, $assertions, $time);

if ($ok) {
    exit(0);
}

// Les cas en échec, avec la première ligne de leur message : de quoi savoir
// quoi ouvrir sans dérouler le log. Plafonné pour garder la carte lisible.
$limit = 30;
$shown = 0;
$problems = $xml->xpath('//testcase[failure or error]') ?: [];

echo "### Cas en échec\n\n";
echo "| Test | Problème |\n";
echo "|---|---|\n";

foreach ($problems as $case) {
    if ($shown++ >= $limit) {
        break;
    }

    $node = isset($case->failure) ? $case->failure : $case->error;
    $id = (string) $case['class'].'::'.(string) $case['name'];

    // PHPUnit ouvre le corps de <failure> par l'identifiant du test, puis le
    // message, puis la trace : on garde la première ligne qui n'est ni vide ni
    // cet identifiant. L'attribut `message`, quand il existe, prime.
    $message = trim((string) ($node['message'] ?? ''));
    if ('' === $message) {
        foreach (explode("\n", (string) $node) as $line) {
            $line = trim($line);
            if ('' !== $line && $line !== $id) {
                $message = $line;
                break;
            }
        }
    }
    $message = str_replace('|', '\\|', strtok($message, "\n") ?: $message);

    $name = str_replace('|', '\\|', $id);

    echo "| `{$name}` | {$message} |\n";
}

if (count($problems) > $limit) {
    printf("\n… et %d autre(s), voir le log.\n", count($problems) - $limit);
}

echo "\n";
