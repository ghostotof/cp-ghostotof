#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Résumé de job GitHub Actions pour Rector en `--dry-run`, à partir de sa
 * sortie `--output-format=json` (capturée par `tee`).
 *
 * Usage : php summary-rector.php rector.json >> "$GITHUB_STEP_SUMMARY"
 *
 * Le JSON porte `totals.changed_files`, `totals.errors`, et pour chaque fichier
 * à modifier son diff et les règles appliquées. La carte reprend ces diffs dans
 * des blocs repliés : c'est plus lisible que le log, et le log n'a plus à les
 * afficher. Toujours exit 0 — c'est l'étape Rector (code 2 en dry-run avec des
 * changements) qui décide du statut du job.
 *
 * Standalone, sans kernel ni autoload.
 */

$path = $argv[1] ?? null;

if (null === $path || !is_file($path)) {
    echo "## Rector\n\n";
    echo "⚠️ Aucune sortie JSON trouvée (`{$path}`) : Rector ne s'est pas exécuté — voir l'étape.\n";
    exit(0);
}

$raw = (string) file_get_contents($path);
// Rector peut préfixer le JSON d'avertissements PHP ; on repart de la première accolade.
$start = strpos($raw, '{');
$data = false === $start ? null : json_decode(substr($raw, $start), true);

if (!is_array($data) || !isset($data['totals']) || !is_array($data['totals'])) {
    echo "## Rector\n\n";
    echo "⚠️ Sortie JSON illisible (`{$path}`).\n";
    exit(0);
}

$changed = (int) ($data['totals']['changed_files'] ?? 0);
$errors = (int) ($data['totals']['errors'] ?? 0);
$ok = 0 === $changed && 0 === $errors;

echo '## '.($ok ? '✅' : '❌')." Rector (dry-run)\n\n";
echo "| Fichiers à modifier | Erreurs |\n|---:|---:|\n";
printf("| %d | %d |\n\n", $changed, $errors);

if ($ok) {
    echo "Le code est conforme à `rector.php`.\n\n";
    exit(0);
}

foreach ((array) ($data['errors'] ?? []) as $error) {
    if (!is_array($error)) {
        continue;
    }
    printf("- ❌ `%s` : %s\n", (string) ($error['file'] ?? '?'), (string) ($error['message'] ?? ''));
}

$limitFiles = 20;
$limitLines = 200;
$diffs = (array) ($data['file_diffs'] ?? []);

foreach (array_slice($diffs, 0, $limitFiles) as $fileDiff) {
    if (!is_array($fileDiff)) {
        continue;
    }

    $file = (string) ($fileDiff['file'] ?? '?');
    $rectors = array_map(
        static fn (string $class): string => substr($class, (int) strrpos($class, '\\') + 1),
        array_filter((array) ($fileDiff['applied_rectors'] ?? []), 'is_string'),
    );

    $lines = explode("\n", (string) ($fileDiff['diff'] ?? ''));
    $truncated = count($lines) > $limitLines;
    $diff = implode("\n", array_slice($lines, 0, $limitLines));

    echo "<details>\n<summary><code>{$file}</code> — ".implode(', ', $rectors)."</summary>\n\n";
    echo "```diff\n{$diff}\n```\n";
    if ($truncated) {
        printf("_… %d ligne(s) tronquée(s), lancer `composer rector` en local._\n", count($lines) - $limitLines);
    }
    echo "\n</details>\n\n";
}

if (count($diffs) > $limitFiles) {
    printf("… et %d autre(s) fichier(s), lancer `composer rector` en local.\n\n", count($diffs) - $limitFiles);
}
