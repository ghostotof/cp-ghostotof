<?php

declare(strict_types=1);

namespace App\Mcp\Presentation\Tool;

use App\Ai\Assistant\Application\Corpus\CorpusRendererInterface;
use App\Mcp\Application\ProtectedTools;
use App\Mcp\Connection\Application\BearerChallenge;
use App\Mcp\Connection\Application\McpConnectionPrincipal;
use App\Mcp\Infrastructure\Session\BoundedSessionStore;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * `get_nominative_corpus` : le corpus nominatif, réservé au palier de
 * confiance par une connexion MCP (ADR 0006 D3, D5 ; #648).
 *
 * **Toujours listé**, et sa description dit qu'il exige une connexion MCP :
 * c'est ce sur quoi s'appuie l'authentification différée des clients. L'accès
 * se décide sur l'identité que le firewall `mcp` a posée (D4) et sur elle
 * seule, lue par `Security` : un {@see McpConnectionPrincipal}, c'est un jeton
 * d'accès relu en base à cette requête — connexion vivante, compte encore du
 * palier de confiance. L'outil ne lit ni `_meta`, ni le jeton, ni les
 * middlewares d'autorisation du SDK.
 *
 *  - **Avec une connexion** : le corpus, rendu par le renderer de l'assistant
 *    de parcours (les mêmes sources, que `CorpusSourcesTest` fixe : le texte du
 *    CV que sert `GET /api/cv`, le CV sans identité, les études de cas), et
 *    l'adresse de la page du site où se télécharge le CV (spec 0007) ;
 *    jamais le PDF. Puis la ligne `nominative-corpus-read` du journal de
 *    sécurité, **après** le rendu : une lecture journalisée est une lecture
 *    faite. Au-delà de {@see self::QUEUED_ANSWER_MAX_BYTES}, une erreur d'outil qui
 *    donne l'adresse de la page du CV, un `warning` aux tailles seules, et
 *    aucune ligne d'audit : rien n'a été servi.
 *  - **Sans** : l'erreur d'outil que ChatGPT sait suivre — le défi
 *    `insufficient_scope` dans `_meta["mcp/www_authenticate"]`, qui lui fait
 *    ouvrir le parcours OAuth, et un texte qui dit quoi faire. Un appel non
 *    marqué `openai/` a déjà reçu le 401 différé avant le SDK
 *    (DeferredAuthorizationListener) : ce chemin ne l'atteint que si
 *    l'écouteur manque, et il ne sert alors pas davantage — marqué ou non, le
 *    même refus, sans rien lire.
 *
 * Aucun `RequestContext` du SDK n'est demandé (accepté par le contrôleur de la
 * tâche 9) : rien ne le lirait, la branche sans connexion répondant la même
 * chose avec ou sans le marqueur, et l'identité venant de `Security`.
 *
 * La valeur de `_meta["mcp/www_authenticate"]` est une **liste** de défis RFC
 * 7235 : la référence de l'Apps SDK d'OpenAI (« Error tool result »,
 * https://developers.openai.com/apps-sdk/reference, lue le 2026-10-24) la
 * type « string or string[] » ; la liste d'un défi est la forme qui reste
 * juste le jour où un second schéma s'ajoute.
 *
 * L'un des deux outils sans route adossée, dans la liste fermée du test
 * d'exposition symétrique (ADR 0006 D1). La description et les textes de
 * refus ne nomment pas l'auteur (D8) ; le corpus le nomme, c'est son objet, et
 * il n'est servi qu'à une connexion.
 */
final readonly class GetNominativeCorpusTool
{
    /**
     * Le texte du refus sans connexion, lu par le modèle (et par la personne
     * à qui il le répète) : ce qu'il faut faire, pas pourquoi.
     */
    public const string CONNECTION_REQUIRED = 'An MCP connection is required to read the nominative corpus: connect your account on this site from your MCP client, then call this tool again.';

    /**
     * L'`error_description` du défi : l'alphabet de RFC 6749 A.2, sans
     * guillemet ni barre oblique inverse, ce que BearerChallenge vérifie.
     */
    public const string CONNECTION_REQUIRED_DESCRIPTION = 'An MCP connection to the site is required to read the nominative corpus';

    /** Le refus d'un corpus trop grand ; `%s` reçoit l'adresse de la page du CV. */
    public const string CORPUS_TOO_LARGE = 'The nominative corpus is too large to be served over MCP. The CV can be downloaded from the site: %s';

    /**
     * La taille maximale de la réponse **telle que le SDK la met en file dans
     * la session** ({@see QueuedAnswer::bytes()} : la réponse JSON-RPC encodée
     * sans drapeau, puis réencodée par `Session::save()`), en octets : la
     * moitié de la borne de session, l'autre moitié restant au reste de la
     * session (décision de l'auteur du 2026-10-24, tâche 9, puis de la
     * relecture de la tâche 5).
     *
     * Au-delà de {@see BoundedSessionStore::MAX_BYTES}, la session est
     * détruite et le client ne reçoit rien. Or la réponse grossit bien plus que
     * le texte, et pas d'un rapport fixe : le corpus y est **deux fois**
     * (`structuredContent`, et le bloc texte de JsonToolResult, déjà du JSON),
     * le SDK encode la réponse sans drapeau (tout caractère hors ASCII en
     * `\uXXXX`, 12 octets pour une paire de substitution ; `/` en `\/`), et
     * `Session::save()` réencode la file, chaque barre oblique inverse et
     * chaque guillemet doublés. Rien ne borne le corpus entier :
     * `PopplerPdfTextExtractor::MAX_CHARACTERS` (30 000) ne borne que le texte
     * du CV, le CV sans identité et les études de cas n'ont aucune borne de
     * longueur. Mesuré le 2026-10-24 sur la pile réelle, taille en file pour
     * 30 000 caractères de CV (borne 524 288) :
     *
     *  - CV fictif des tests : 2 880 octets ;
     *  - ASCII : 60 806 ;
     *  - 2 ou 3 octets : 420 806 ;
     *  - 4 octets : 841 076 — session détruite.
     *
     * Rapporté au payload `{corpus, cvPageUrl}` encodé sans drapeau, la file
     * pèse 2 × (ASCII), ~2,3 × (accents, emoji), 4 × (`/`, saut de ligne) et
     * 6 × (`"`, `\`) ; en octets de texte, jusqu'à 12 par guillemet. Une borne
     * sur le payload laissait donc passer une réponse qui détruisait la
     * session (relecture de la tâche 5 : 786 432 octets pour un corpus de
     * guillemets à la borne d'alors) : c'est la file elle-même qui est
     * mesurée. GetNominativeCorpusToolTest compare la mesure à la file réelle
     * et pince la relation avec la borne de session par la pire composition.
     */
    public const int QUEUED_ANSWER_MAX_BYTES = BoundedSessionStore::MAX_BYTES / 2;

    /** La clé `_meta` du défi dans un résultat d'outil en erreur (Apps SDK d'OpenAI). */
    private const string WWW_AUTHENTICATE_META_KEY = 'mcp/www_authenticate';

    private const array OUTPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'corpus' => ['type' => 'string', 'description' => 'The nominative corpus, in Markdown: the full CV of the site\'s author, the anonymous CV and the case studies.'],
            'cvPageUrl' => ['type' => 'string', 'description' => 'The site\'s page where the CV is downloaded, by a person signed in at the trusted tier.'],
        ],
        'required' => ['corpus', 'cvPageUrl'],
        'additionalProperties' => false,
    ];

    public function __construct(
        private Security $security,
        private CorpusRendererInterface $corpusRenderer,
        private SecurityAuditLoggerInterface $auditLogger,
        private BearerChallenge $challenge,
        private LoggerInterface $logger,
        #[Autowire(param: 'app.frontend_base_url')]
        private string $frontendBaseUrl,
    ) {
    }

    #[McpTool(
        name: ProtectedTools::NOMINATIVE_CORPUS,
        title: 'Nominative CV',
        description: 'The full CV of the site\'s author: identity, employers and career history. Requires an MCP connection, granted to a person of the site\'s trusted tier; without one, the call fails and returns nothing of the CV.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        outputSchema: self::OUTPUT_SCHEMA,
    )]
    public function __invoke(
        #[Schema(description: ToolLocale::DESCRIPTION)]
        string $locale = ToolLocale::DEFAULT,
    ): CallToolResult {
        // La langue est lue d'abord, comme sur les autres outils : une valeur
        // inconnue reste l'erreur que le modèle sait corriger, connexion ou non.
        $language = ToolLocale::parse($locale);

        $connection = $this->security->getUser();
        if (!$connection instanceof McpConnectionPrincipal) {
            return CallToolResult::error(
                [new TextContent(self::CONNECTION_REQUIRED)],
                meta: [self::WWW_AUTHENTICATE_META_KEY => [$this->challenge->insufficientScope(self::CONNECTION_REQUIRED_DESCRIPTION)]],
            );
        }

        $cvPageUrl = \sprintf('%s/%s/assistant', rtrim($this->frontendBaseUrl, '/'), $language->value);
        $result = JsonToolResult::forObject(['corpus' => $this->corpusRenderer->render($language), 'cvPageUrl' => $cvPageUrl]);

        // Mesurée telle que le SDK la met en file : c'est elle, pas le texte,
        // qui doit tenir dans la session.
        $queuedBytes = QueuedAnswer::bytes($result);
        if ($queuedBytes > self::QUEUED_ANSWER_MAX_BYTES) {
            // Les tailles seules : jamais le texte, qui nomme l'auteur.
            $this->logger->warning('Nominative corpus refused over MCP: queued answer over its bound.', ['queuedBytes' => $queuedBytes, 'maxBytes' => self::QUEUED_ANSWER_MAX_BYTES]);

            return CallToolResult::error([new TextContent(\sprintf(self::CORPUS_TOO_LARGE, $cvPageUrl))]);
        }

        $this->auditLogger->nominativeCorpusRead($connection, $connection->connectionId(), $connection->clientId(), ProtectedTools::NOMINATIVE_CORPUS);

        return $result;
    }
}
