<?php

declare(strict_types=1);

/*
 * Charge un EntityManager reel pour phpstan-doctrine (parametre
 * doctrine.objectManagerLoader de phpstan.dist.neon) : c'est ce qui permet a
 * PHPStan de connaitre le mapping des entites, les types de colonnes et les
 * retours de repositories. Aucune connexion a la base n'est ouverte au
 * chargement du conteneur.
 */

use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

$env = \is_string($_SERVER['APP_ENV'] ?? null) ? $_SERVER['APP_ENV'] : 'dev';

$kernel = new Kernel($env, (bool) ($_SERVER['APP_DEBUG'] ?? false));
$kernel->boot();

/** @var EntityManagerInterface */
return $kernel->getContainer()->get('doctrine')->getManager();
