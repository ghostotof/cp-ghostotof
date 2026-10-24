<?php

declare(strict_types=1);

namespace App\Mcp\Application;

use App\Portfolio\Shared\Domain\ValueObject\Locale;

/**
 * Ouvre le corpus nominatif à la connexion MCP de la requête et journalise sa
 * lecture (#648, ADR 0006 D3, D4, D5) : ce que l'outil `get_nominative_corpus`
 * ({@see \App\Mcp\Presentation\Tool\GetNominativeCorpusTool}) délègue, comme
 * les outils adossés délèguent à {@see RoutePayloadReaderInterface}.
 *
 * **En deux temps, parce que la borne de la réponse est une affaire du SDK.**
 * L'outil doit mesurer la réponse telle que le SDK la mettra en file dans la
 * session (`QueuedAnswer::bytes()`, sur le `CallToolResult`), et la ligne
 * d'audit ne doit être écrite que pour une réponse **effectivement servie**,
 * donc après cette mesure. {@see self::open()} décide l'accès et rend le
 * corpus ; l'outil met en forme et mesure ; {@see self::recordServed()}
 * journalise ce qu'il sert. Écartés : une mesure dans l'Application (le SDK y
 * entrerait, ses classes de réponse et son encodage), un rappel passé à
 * `open()` pour mesurer (une abstraction pour un seul appelant, qui cache
 * l'ordre au lieu de l'écrire), et l'audit dans l'outil (la décision et la
 * trace d'une lecture nominative dans un adaptateur, six dépendances).
 * Le prix : rien n'empêche un appelant d'oublier `recordServed()` —
 * GetNominativeCorpusToolTest lit la ligne après chaque lecture servie, et
 * son absence après chaque refus.
 */
interface NominativeCorpusReaderInterface
{
    /**
     * Le corpus dans `$language`, ou `null` quand la requête n'a pas le droit
     * de le lire — **rien n'est alors lu ni journalisé**.
     *
     * Le droit se lit sur l'identité que le firewall `mcp` a posée, et sur
     * elle seule : une connexion MCP ({@see \App\Mcp\Connection\Application\McpConnectionPrincipal}),
     * dont le scope accorde `nominative:read` et dont le compte atteint le
     * palier de confiance. L'authentificateur refuse déjà toute autre
     * connexion ; le revérifier ici est une défense en profondeur, pour que
     * l'assouplissement de l'un ne serve pas le corpus en silence. Les trois
     * refus sont un seul refus : l'appelant n'apprend pas lequel.
     */
    public function open(Locale $language): ?NominativeCorpus;

    /**
     * La ligne `nominative-corpus-read` du journal de sécurité pour un corpus
     * que l'outil sert, à n'appeler qu'une fois la réponse acceptée.
     */
    public function recordServed(NominativeCorpus $corpus): void;
}
