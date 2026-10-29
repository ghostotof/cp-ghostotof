<?php

use Symfony\Bridge\PhpUnit\ClockMock;
use Symfony\Component\Dotenv\Dotenv;

require \dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(\dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Issue #743 : le limiteur de Symfony (`microtime()`) et LoginThrottlingListener
// (`time()`) lisent l'heure réelle, sans horloge injectable, alors que les
// écouteurs du projet lisent `ClockInterface`. ClockMock redéfinit time(),
// microtime(), sleep(), usleep(), hrtime()… dans un espace de noms ; il ne
// change rien tant qu'un test ne fige pas l'horloge (FreezesTheWholeClock).
//
// Ici, au bootstrap, et pas dans une extension PHPUnit ni au premier test : PHP
// retient la fonction résolue à chaque site d'appel, un enregistrement après
// le premier `microtime()` de l'espace de noms resterait sans effet.
//
// Seuls les espaces de noms dont dépend le calcul d'une échéance : les fenêtres
// du limiteur, et le décompte en minutes du refus de login. Ni les verrous, ni
// les caches, ni le code du projet — leurs usleep() et hrtime() mesurent et
// attendent pour de vrai. Épinglé par WholeClockFreezeTest.
ClockMock::register('Symfony\Component\RateLimiter\Policy\DummyClass');
ClockMock::register('Symfony\Component\Security\Http\EventListener\DummyClass');
