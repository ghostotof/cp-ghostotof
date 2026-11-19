<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Un audit dont la panne ne change jamais l'issue (#725, la règle de
 * l'auteur du 2026-10-27, appliquée par #726 au point de jeton et à la
 * purge), **écrit une fois** (#726, S1) : l'issue reste celle que
 * l'appelant rend (le refus de league, les jetons émis, la purge menée à son
 * terme, le refus d'une admission — 401 `invalid_client` s'il est définitif,
 * 429 ou 503 `temporarily_unavailable` s'il est passager —, le 401 d'un
 * garde, la révocation, l'inscription et la révision d'un client). La liste exacte des sites se relève,
 * depuis `backend/`, par `grep -rn -- '->attempt(' src | grep -v '^src/Mcp/Connection/Application/FailSafeAudit.php'`.
 *
 * La ligne est tentée ; une panne, quelle qu'elle soit (`Throwable` : une
 * erreur du pilote, du chiffrement, du journal lui-même), sort en `critical`
 * sur le canal principal — l'audit manqué reste visible — avec **la classe
 * seule**, jamais le message ni la trace : une erreur du pilote ou du
 * chiffrement pourrait citer l'artefact ou la connexion. Le message est une
 * constante de l'appelant, qui nomme ce qui n'a pas été audité.
 *
 * La panne du journal lui-même est avalée aussi : l'audit ne change jamais
 * l'issue, même quand il ne peut plus dire qu'il a échoué (elle laisse alors
 * un `error_log()` de dernier recours, la classe seule). Ce dernier recours
 * n'est pas une garantie de trace : `error_log` et le gestionnaire `main` de
 * Monolog (`php://stderr`) écrivent tous deux sur le descripteur 2 du process
 * (`php.prod.ini`, `monolog.yaml`). Sous FPM c'est un tube vers le maître
 * (`catch_workers_output = yes`, `www.prod.conf`), qui le relaie sur le stderr
 * du conteneur `php-fpm` : le dernier recours couvre une panne du processeur,
 * du formateur ou du gestionnaire, non celle de ce descripteur. S'il est
 * fermé, ni le `critical` ni la trace n'existent ; s'il est bloqué (tube
 * plein), le client reçoit un 504 à 60 s (le délai de lecture de nginx et de
 * l'ingress, 60 s par défaut : aucune directive ne le change dans
 * `docker/nginx` ni `k8s/`, et `www.prod.conf` le compte pour 60 s), puis le
 * maître tue le worker à `request_terminate_timeout = 65s` (`www.prod.conf`),
 * encore bloqué dans l'écriture : le `critical` et la trace sont perdus, et
 * l'issue n'est plus celle que l'appelant rend.
 *
 * Un des sites, `McpConnectionPurger`, tourne en ligne de commande
 * (`app:mcp:purge`, CronJob `k8s/base/messenger-purge-cronjob.yaml`), sans
 * maître ni borne de 65 s. Sa seule borne est `activeDeadlineSeconds: 300` du
 * Job, qui couvre le Job entier et non la purge : les trois commandes enchaînées
 * par le conteneur (la purge MCP est la troisième) et les relances `OnFailure`
 * comptent dans ces 300 s, la purge peut donc être tuée bien avant 300 s de son
 * propre temps. Passé ce délai, Kubernetes tue le pod encore bloqué dans
 * l'écriture, et le `critical` comme la trace sont perdus de la même
 * façon. La CLI charge le même `php.prod.ini` (copié dans `conf.d`), donc
 * `error_log` et `php://stderr` écrivent directement sur le stderr du
 * conteneur `purge` du Job, dont les journaux sont ceux du pod du Job et non
 * ceux de `deploy/backend -c php-fpm`.
 *
 * Un service plutôt qu'un trait : le journal est injecté une fois (le canal
 * principal, celui de l'autowiring), et chaque site garde sa boucle et son
 * message — une panne tentée par ligne, pour qu'aucune n'en taise une autre.
 */
final readonly class FailSafeAudit
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param Closure(): void  $write          l'écriture de la ligne d'audit
     * @param non-empty-string $failureMessage constant, sans rien de l'artefact
     */
    public function attempt(Closure $write, string $failureMessage): void
    {
        try {
            $write();
        } catch (Throwable $failure) {
            try {
                $this->logger->critical($failureMessage, ['exceptionClass' => $failure::class]);
            } catch (Throwable $loggerFailure) {
                // Le journal lui-même en panne : l'issue reste celle que
                // l'appelant va rendre, jamais un 500 né de l'audit.
                // Ce chemin vaut pour TOUS les appelants (les sites
                // d'appel, que la commande `grep` de la docblock de la classe
                // énumère). Dernier recours,
                // sans contexte : un message constant et la classe de la panne
                // du journal (pas son message, qui peut porter des données).
                // `error_log()` écrit sur le flux d'erreurs de PHP, sans passer
                // par le journal défaillant ; sa propre panne est sans suite.
                error_log('Audit failure could not be logged. Logger failure: '.$loggerFailure::class);
            }
        }
    }
}
