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
 * **En deux temps, pour que l'audit suive la construction du résultat.** La
 * ligne d'audit ne doit être écrite que pour une réponse **que l'outil sert** :
 * {@see self::open()} décide l'accès et rend le corpus ; l'outil le met à la
 * forme MCP (le `CallToolResult`, une affaire de SDK, donc de Presentation) ;
 * {@see self::recordServed()} journalise alors ce qu'il sert. Jusqu'à #731,
 * l'outil mesurait aussi la réponse entre les deux et la refusait au-delà
 * d'une borne (la réponse passait par la session du SDK) : la mesure est
 * partie, l'ordre reste — une mise en forme qui échoue (un corpus qui ne
 * s'encode pas en JSON) ne laisse pas de ligne de lecture. Écartés : la mise
 * en forme dans l'Application (le SDK y entrerait, ses classes de réponse et
 * son encodage), un rappel passé à `open()` pour la faire (une abstraction
 * pour un seul appelant, qui cache l'ordre au lieu de l'écrire), et l'audit
 * dans l'outil (la décision et la trace d'une lecture nominative dans un
 * adaptateur, six dépendances). Le prix : rien n'empêche un appelant
 * d'oublier `recordServed()` — GetNominativeCorpusToolTest lit la ligne après
 * chaque lecture servie, et son absence après chaque refus.
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
     * que l'outil sert, à n'appeler qu'une fois le résultat construit.
     */
    public function recordServed(NominativeCorpus $corpus): void;
}
