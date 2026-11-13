<?php

declare(strict_types=1);

namespace App\Mcp\Presentation\Tool;

use App\Mcp\Application\NominativeCorpusReaderInterface;
use App\Mcp\Application\ProtectedTools;
use App\Mcp\Application\ToolSecuritySchemes;
use App\Mcp\Connection\Application\BearerChallenge;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;

/**
 * `get_nominative_corpus` : le corpus nominatif, réservé au palier de
 * confiance par une connexion MCP (ADR 0006 D3, D5 ; #648).
 *
 * **Toujours listé**, et sa description dit qu'il exige une connexion MCP :
 * c'est ce sur quoi s'appuie l'authentification différée des clients. Un
 * adaptateur mince : la décision d'accès, le rendu et l'audit sont ceux de
 * {@see NominativeCorpusReaderInterface} ; l'outil lit la langue, met le
 * corpus à la forme MCP et traduit chaque issue en `CallToolResult`. Deux
 * issues :
 *
 *  - **Corpus ouvert** : le corpus et l'adresse de la page du CV, puis
 *    `recordServed()` — la ligne d'audit **après** la construction du
 *    résultat : une lecture journalisée est une réponse que l'outil sert,
 *    pas forcément celle que le client reçoit. Le SDK valide ensuite la
 *    sortie contre son `outputSchema` : refusée là, elle reste journalisée
 *    comme lue (sur-compte accepté par l'auteur le 2026-11-10, mcp.md).
 *  - **Refusé** (pas de connexion, ou une connexion sans le scope ou le
 *    palier) : l'erreur d'outil que ChatGPT sait suivre — le défi
 *    `insufficient_scope` dans `_meta["mcp/www_authenticate"]`, qui lui fait
 *    ouvrir le parcours OAuth, et un texte qui dit quoi faire. Un appel
 *    anonyme non marqué `openai/` a déjà reçu le 401 différé avant le SDK
 *    (DeferredAuthorizationListener) : ce chemin ne l'atteint que si
 *    l'écouteur manque, et il ne sert alors pas davantage — marqué ou non, le
 *    même refus, sans rien lire.
 *
 * Aucun `RequestContext` du SDK n'est demandé (accepté par le contrôleur de la
 * tâche 9) : rien ne le lirait, la branche refusée répondant la même chose
 * avec ou sans le marqueur, et l'identité venant de `Security`.
 *
 * **Aucune borne de taille** (#731, effet métier accepté par l'auteur le
 * 2026-11-10) : un corpus très gros est servi en entier, jamais remplacé par
 * un renvoi à la page du CV. Tant que le SDK mettait la réponse d'un outil en
 * file dans la session avant de la rendre (0.8.1), l'outil la mesurait et la
 * refusait au-delà de la moitié de la borne de session, faute de quoi la
 * session était détruite et le client ne recevait rien. Depuis la 0.9 la
 * réponse reste au transport et ne passe plus par la session — prémisse
 * épinglée par
 * {@see \App\Tests\Mcp\Presentation\Tool\GetNominativeCorpusToolTest::testTheAnswerNeverTransitsThroughTheSession()} —,
 * et le refus est parti avec la borne. Ce qui borne encore le corpus est en
 * amont : `PopplerPdfTextExtractor::MAX_CHARACTERS` pour le texte du CV ; le
 * CV sans identité et les études de cas n'ont aucune borne de longueur.
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
        private NominativeCorpusReaderInterface $corpusReader,
        private BearerChallenge $challenge,
    ) {
    }

    #[McpTool(
        name: ProtectedTools::NOMINATIVE_CORPUS,
        title: 'Nominative CV',
        description: 'The full CV of the site\'s author: identity, employers and career history. Requires an MCP connection, granted to a person of the site\'s trusted tier; without one, the call fails and returns nothing of the CV.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        // Lié à un compte (ADR 0006 D3) : ce que ChatGPT lit pour offrir la
        // liaison ; SecuritySchemesRegistry le publie aussi au premier niveau.
        meta: [ToolSecuritySchemes::KEY => [['type' => 'oauth2', 'scopes' => [McpConnection::SCOPE_NOMINATIVE_READ]]]],
        outputSchema: self::OUTPUT_SCHEMA,
    )]
    public function __invoke(
        #[Schema(description: ToolLocale::DESCRIPTION)]
        string $locale = ToolLocale::DEFAULT,
    ): CallToolResult {
        // La langue est lue d'abord, comme sur les autres outils : une valeur
        // inconnue reste l'erreur que le modèle sait corriger, connexion ou non.
        $language = ToolLocale::parse($locale);

        $corpus = $this->corpusReader->open($language);
        if (null === $corpus) {
            return CallToolResult::error(
                [new TextContent(self::CONNECTION_REQUIRED)],
                meta: [self::WWW_AUTHENTICATE_META_KEY => [$this->challenge->insufficientScope(self::CONNECTION_REQUIRED_DESCRIPTION)]],
            );
        }

        $result = JsonToolResult::forObject($corpus->payload());

        // Après la construction du résultat : si elle échoue, rien n'est
        // servi, et rien n'est journalisé comme lu.
        $this->corpusReader->recordServed($corpus);

        return $result;
    }
}
