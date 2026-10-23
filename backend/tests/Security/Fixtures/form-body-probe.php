<?php

declare(strict_types=1);

/*
 * Routeur du serveur intégré (`php -S`) de FormBodyParsingDisabledTest
 * (issue #686) : dit ce que PHP a fait du corps de la requête sous le fichier
 * ini de l'image chargé avec `-n -c`, sans application ni Symfony.
 *
 * - POST : ce que l'analyse du démarrage a mis dans `$_POST` et `$_FILES`, et
 *   la longueur lue sur `php://input` (un corps JSON doit rester lisible) ;
 * - toute autre méthode : ce que `request_parse_body()` en tire — l'appel de
 *   `Request::createFromGlobals()` pour PUT, DELETE, PATCH et QUERY —, ou
 *   `refused`. Le flux n'est pas lu avant : lu, il serait consommé, et
 *   l'analyse n'y trouverait plus rien.
 *
 * La réponse est la dernière ligne de la sortie : sous `php.dev.ini`
 * (`display_errors = On`), l'avertissement d'un corps refusé au démarrage la
 * précède.
 */

$probe = ['post' => $_POST, 'files' => array_keys($_FILES)];

if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? null)) {
    $probe['inputLength'] = \strlen((string) file_get_contents('php://input'));
} else {
    try {
        [$probe['parsed']] = request_parse_body();
    } catch (RequestParseBodyException) {
        $probe['parsed'] = 'refused';
    }
}

echo "\n".json_encode($probe, \JSON_THROW_ON_ERROR)."\n";
