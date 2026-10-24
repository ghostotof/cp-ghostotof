<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Corpus;

use App\Ai\Assistant\Application\Corpus\CorpusRendererInterface;
use App\Mcp\Application\NominativeCorpus;
use App\Mcp\Application\NominativeCorpusReaderInterface;
use App\Mcp\Application\ProtectedTools;
use App\Mcp\Connection\Application\McpConnectionPrincipal;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Le corpus nominatif ouvert par la connexion MCP (#648) : en
 * Infrastructure parce que l'identité se lit par `Security`, celle que le
 * firewall `mcp` a posée (ADR 0006 D4) — jamais `_meta`, le jeton ni les
 * middlewares d'autorisation du SDK.
 *
 *  - **L'accès** : un {@see McpConnectionPrincipal} (un jeton d'accès relu en
 *    base à cette requête, connexion vivante), son scope accordant
 *    `nominative:read`, et `ROLE_TRUSTED` atteint par la `role_hierarchy`
 *    (`isGranted()`, comme partout sur le site : un ROLE_SUPER y atteint le
 *    palier) ;
 *  - **le corpus** : le texte que rend le renderer de l'assistant de parcours
 *    (les mêmes sources, que `CorpusSourcesTest` fixe : le texte du CV que
 *    sert `GET /api/cv`, le CV sans identité, les études de cas), et
 *    l'adresse de la page du site où se télécharge le CV (spec 0007) ; jamais
 *    le PDF ;
 *  - **l'audit** : la ligne `nominative-corpus-read`, écrite par
 *    {@see self::recordServed()} une fois la réponse acceptée — une lecture
 *    journalisée est une lecture servie par l'outil. Elle consigne le corpus
 *    **tel que rendu** : un CV détaillé indisponible (fichier absent,
 *    extraction en échec) laisse un corpus qui le dit à sa place, et la ligne
 *    est écrite quand même — limite acceptée (relecture finale de #648) :
 *    elle dit qu'une connexion a lu ce que le serveur avait à servir, pas que
 *    le CV y était. Elle ne dit pas non plus que le client l'a reçue : une
 *    écriture de session perdue après elle sur-compte (`.claude/rules/mcp.md`).
 *
 * La dépendance à SecurityAuditLoggerInterface est la seconde porte de
 * l'exception admise de `.claude/rules/mcp-oauth.md` : l'unique écrivain de
 * `security_audit`. Le compte est désigné par le principal du firewall, un
 * AccountReference, jamais un CpgUser.
 */
final readonly class NominativeCorpusReader implements NominativeCorpusReaderInterface
{
    public function __construct(
        private Security $security,
        private CorpusRendererInterface $corpusRenderer,
        private SecurityAuditLoggerInterface $auditLogger,
        #[Autowire(param: 'app.frontend_base_url')]
        private string $frontendBaseUrl,
    ) {
    }

    public function open(Locale $language): ?NominativeCorpus
    {
        // Ce que l'authentificateur a déjà jugé, rejugé ici : la connexion,
        // son scope, le palier de son compte. Un seul refus pour les trois.
        $reader = $this->security->getUser();
        if (!$reader instanceof McpConnectionPrincipal
            || !$reader->grantsNominativeRead()
            || !$this->security->isGranted(McpConnectionPrincipal::TRUSTED_TIER)) {
            return null;
        }

        return new NominativeCorpus(
            $this->corpusRenderer->render($language),
            \sprintf('%s/%s/assistant', rtrim($this->frontendBaseUrl, '/'), $language->value),
            $reader,
        );
    }

    public function recordServed(NominativeCorpus $corpus): void
    {
        $reader = $corpus->reader;

        $this->auditLogger->nominativeCorpusRead($reader, $reader->connectionId(), $reader->clientId(), ProtectedTools::NOMINATIVE_CORPUS);
    }
}
