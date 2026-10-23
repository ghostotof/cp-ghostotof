<?php

declare(strict_types=1);

// Fixture de SecurityConfigEnvironmentParityTest (issue #659) : configuration
// PHP commune, au format tableau, dont un bloc when@dev change la stratégie
// de décision — une clé hors d'access_control, des firewalls et de la
// hiérarchie, comparée elle aussi. Une clé que packages/security.yaml ne
// déclare pas : ce fichier se charge avant lui, qui l'écraserait.

return [
    'when@dev' => [
        'security' => [
            'access_decision_manager' => ['strategy' => 'unanimous'],
        ],
    ],
];
