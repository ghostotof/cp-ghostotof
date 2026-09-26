# Tâche 2 (#261) — Une question en flux de bout en bout : plan d'implémentation

> **Pour l'exécutant :** implémenter tâche par tâche avec `agent-skills:build` et
> `superpowers:test-driven-development` (choix du 2026-09-26). Chaque étape se suit par sa case
> `- [ ]`. Un commit par tâche, fichiers nommés, jamais `git add -A`.

**Objectif :** un compte `ROLE_TRUSTED` envoie `POST /api/assistant/answers` `{locale, messages}`
et reçoit une réponse du modèle Scaleway **en flux** (`text/event-stream`). Le modèle répond à partir
d'un corpus rendu depuis le CV sans identité et les études de cas.

**Architecture :** `AnswerController` (léger) → `CareerAssistantInterface` →
`SymfonyAiCareerAssistant`, seule classe qui importe `Symfony\AI\*`. Le service compose lui-même
le message système (préambule du fichier de prompt + corpus rendu par `CorpusRenderer`), appelle
l'agent `ai.agent.career_assistant` avec `stream: true` et **amorce** le flux avant de rendre la
main. Un échec du fournisseur avant le premier fragment devient ainsi un 503, alors qu'un échec
pendant le flux devient un événement `error`.

**Stack :** Symfony 8.1 (`EventStreamResponse`, `ServerEvent`, `#[MapRequestPayload]`),
symfony/ai-agent 0.13.0, bridge Scaleway 0.13.0 (API compatible OpenAI), API Platform 4.3
(providers publics, `exception_to_status`), PHPUnit, nginx.

**Spec :** `.claude/specs/0005-career-assistant.md` (D1, D4, D5, D8, D9, D10 ; §4 M2 hors PDF,
M3 et M4 hors bornes/quota). **Issue :** #261. **Branche :** `feature/spec-0005-t2-streamed-answer`,
tirée de `feature/spec-0005-career-assistant` ; PR **vers la branche mère** (`--base` explicite).

## Contraintes globales

- `declare(strict_types=1)`, `final readonly` quand c'est possible, PHPStan `max` + strict-rules
  sans baseline, Rector, Psalm (taint) et `symfony lsp:check` verts.
- **Seule `Infrastructure/SymfonyAi/SymfonyAiCareerAssistant` importe `Symfony\AI\*`** (D1). Le
  corpus, le prompt et le contrôleur n'en voient rien.
- L'agent est injecté **par son id** : `#[Autowire(service: 'ai.agent.career_assistant')]`.
  Jamais `AgentInterface` ou `PlatformInterface` par type : `PlatformInterface` s'autowire sur
  `ai.platform.anthropic`, et le CV nominatif partirait chez Anthropic (journal, tâche 1).
- Les sources du corpus passent **par les providers publics** (`AnonymousCvProvider`,
  `CaseStudyProvider`), jamais par un repository ni par une classe `Backoffice*` (D5, §9).
- Rien n'est persisté. **Aucun contenu dans un log** (question, réponse, corpus, message
  d'exception du fournisseur) ; en `info`, seulement les jetons, la durée, le nombre de messages et
  le statut de fin (D10).
- `access_control` `{ path: ^/api/assistant(/|$), roles: ROLE_TRUSTED }`, **après**
  `^/api/backoffice` et **avant** les règles `ROLE_USER`. Aucune entrée dans
  `CsrfCookieRequestSubscriber::EXCLUDED_PATHS`. `ApiRouteExposureTest` **inchangé**
  (`PUBLIC_PATHS` et `BASE_TIER_PATHS` compris).
- Aucun test ne sort sur le réseau : `MockHttpClient` sur `ai.scaleway.http_client.scoping.inner`
  et `$client->disableReboot()`. La clé factice de `phpunit.dist.xml` ferait échouer toute fuite.
- Les deux fichiers nginx restent miroirs : `docker/nginx/default.conf` et
  `k8s/base/backend-nginx.conf`.
- Aucun nom réel, aucune adresse, aucun extrait du vrai CV dans une fixture ou un snapshot.

## Contrat du flux (ce que la tâche 6 consommera)

Chaque événement porte un `data` **JSON**. Un fragment brut contenant `\n` serait découpé en
plusieurs lignes `data:` ; le JSON rend le découpage sans ambiguïté.

| `event` | `data` | Quand |
|---|---|---|
| `delta` | `{"text": "<fragment>"}` | un par fragment de texte non vide |
| `done` | `{"promptTokens": int\|null, "completionTokens": int\|null, "durationMs": int}` | une fois, en fin de flux normale |
| `error` | `{"reason": "assistant-unavailable"}` | une fois, si le fournisseur échoue **après** le 200 ; fin du flux |

Avant le premier fragment, l'échec n'est pas un événement : c'est un **503** problem+json,
`type: /errors/assistant-unavailable`.

## Écarts à la spec et à l'issue, constatés en préparant ce plan

Chacun est à noter au journal de la spec (§10) dans la tâche 5.

1. **Anonyme → 403, pas 401**, sur une requête nue. Le double-submit CSRF (priorité 20) tranche
   avant le firewall, comme pour la traduction. `ApiRouteExposureTest` accepte 401 comme 403. Le 401
   reste testé dans son vrai cas : un cookie `XSRF-TOKEN` signé, mais sans `BEARER`. Le firewall
   refuse alors lui-même.
2. **Tri par `position`.** Les DTO publics (`AnonymousCvSectionResource`, `CaseStudyResource`)
   n'exposent pas `position` : le critère « quel que soit l'ordre de retour des fixtures » ne peut
   pas être tenu par le renderer. Les providers renvoient déjà leurs entrées triées
   (`ORDER BY position, id` dans les deux repositories), et le renderer **conserve cet ordre**. C'est
   l'ordre que lit la personne sur les pages. Un test noyau persiste des entités dans le désordre
   (positions 2, 0, 1) et vérifie l'ordre rendu : il pince la chaîne complète.
3. **Prompt système.** `SystemPromptInputProcessor` (symfony/ai-agent) **n'injecte pas** le prompt de
   `ai.yaml` si le `MessageBag` contient déjà un message système. Pour obtenir « préambule + corpus »
   (D8), le service compose donc lui-même le message système, en lisant **le même fichier**
   `config/ai/prompts/career_assistant.txt`. `prompt.file` reste dans `ai.yaml` pour
   `ai:agent:call` et pour `ScalewayPlatformWiringTest`.
4. **Jetons en flux.** Une API compatible OpenAI n'envoie l'usage en flux que sur demande :
   l'appel passe `stream_options: {include_usage: true}`. Le bridge fusionne les options telles
   quelles dans le corps. Si Scaleway ne renvoie pas d'usage, `done` porte `null` : le vrai
   comportement est vérifié à l'appel réel (tâche 4).
5. **nginx.** `try_files … /index.php` fait une redirection interne vers `location ~ ^/index\.php`,
   si bien qu'un `fastcgi_buffering off` posé dans `location ^~ /api/assistant/` **ne s'appliquerait
   pas**. La location fait donc son propre `fastcgi_pass`. Autre fait : `EventStreamResponse` pose déjà
   `X-Accel-Buffering: no`, que nginx honore aussi pour FastCGI. L'expérience de la tâche 4 dit si
   l'en-tête suffit déjà ; **si oui, s'arrêter et demander** s'il faut garder la location (défense
   en profondeur, et critère de l'issue) ou s'en passer.
6. **Journalisation d'un échec.** Le journal de la tâche 1 demande de consigner le statut HTTP d'un
   échec, parce que le bridge réduit tout à « unknown ». Le statut est lu sur l'exception
   (`ServerException::getStatusCode()`, `HttpExceptionInterface`, ou le motif
   `Unexpected response code NNN` du bridge en flux). **Le message de l'exception n'est jamais
   journalisé** : le bridge y recopie le corps de la réponse du fournisseur.
7. **Arrêt par le client.** Si le navigateur coupe la connexion, `EventStreamResponse` interrompt
   la boucle et aucune ligne `info` n'est écrite (les jetons consommés ne sont pas journalisés).
   C'est une limite acceptée en v1 ; la tâche 3 (quota) ne dépend pas de ce log.

## Points de relecture

Voici les cas que la spec implique sans qu'un critère les nomme, et qui toucheraient d'abord une
personne réelle. Chacun a son test dans la tâche indiquée.

1. **Le fournisseur tombe après le 200** : la personne doit voir une erreur, pas une réponse
   tronquée qui aurait l'air complète → événement `error`, pas de `done` (T3, flux coupé par
   `MockResponse`).
2. **Le modèle ne renvoie aucun fragment de texte** (réponse vide, ou métadonnées seules) : le flux
   se termine sur un `done`, sans 503 ni attente sans fin (T2).
3. **Un corps mal formé** (`messages` qui n'est pas une liste, un élément qui n'est pas un objet, un
   `content` numérique ou tableau, `role` inconnu, `locale` hors liste) : toujours un 4xx, jamais un
   500 (T3).
4. **Un contenu du backoffice contenant `<documents>` ou `</documents>`** ne referme pas le bloc de
   documents avant l'heure : la balise est retirée du contenu (T1).
5. **Des fins de ligne `\r\n` venues du backoffice** donnent exactement les mêmes octets que `\n` :
   le corpus doit rester un préfixe byte-identique pour le cache de prompt (D8) (T1).

---

## Structure des fichiers

```
backend/src/Ai/Assistant/
  Domain/ValueObject/Role.php                     # enum user|assistant, values()
  Domain/ValueObject/ConversationMessage.php      # role + contenu non vide
  Domain/ValueObject/Conversation.php             # liste non vide (bornes D6 : tâche 3)
  Domain/ValueObject/AnswerUsage.php              # jetons + durée d'une réponse
  Domain/Exception/InvalidConversationException.php    # 422
  Domain/Exception/AssistantUnavailableException.php   # 503, /errors/assistant-unavailable
  Application/CareerAssistantInterface.php        # answer(): Generator<int, string, mixed, AnswerUsage>
  Application/CareerAssistantSystemPrompt.php     # préambule + corpus
  Application/Corpus/CorpusRendererInterface.php  # render(Locale): string
  Application/Corpus/CorpusRenderer.php           # providers publics → Markdown délimité
  Infrastructure/SymfonyAi/SymfonyAiCareerAssistant.php
  Presentation/Controller/AnswerController.php    # POST /api/assistant/answers
  Presentation/Dto/AnswerRequest.php
backend/tests/Ai/
  Support/FakeStreamingAgent.php
  Assistant/Support/StubProvider.php
  Assistant/Support/StubCorpusRenderer.php
  Assistant/Application/Corpus/CorpusRendererTest.php
  Assistant/Application/Corpus/CorpusRendererOrderTest.php      # noyau + base
  Assistant/Application/Corpus/CorpusSourcesTest.php            # noyau
  Assistant/Application/Corpus/__snapshots__/{fr,en,empty-fr}.md
  Assistant/Application/CareerAssistantSystemPromptTest.php
  Assistant/Domain/ValueObject/ConversationTest.php
  Assistant/Infrastructure/SymfonyAi/SymfonyAiCareerAssistantTest.php
  Assistant/Presentation/Controller/AnswerControllerTest.php    # fonctionnel
backend/config/services.yaml             # when@test : CorpusRenderer public
backend/config/packages/security.yaml    # + access_control
backend/config/packages/api_platform.yaml  # + 2 entrées exception_to_status
backend/tests/Security/AccessControlAnchoringTest.php  # + 3 cas
docker/nginx/default.conf, k8s/base/backend-nginx.conf # + location ^~ /api/assistant/
```

---

## Tâche 1 : le corpus rendu (M2 hors PDF)

**Fichiers :**
- Créer : `backend/src/Ai/Assistant/Application/Corpus/CorpusRendererInterface.php`,
  `backend/src/Ai/Assistant/Application/Corpus/CorpusRenderer.php`
- Créer : `backend/tests/Ai/Assistant/Support/StubProvider.php`, les trois tests `Corpus*Test.php`,
  les trois snapshots
- Modifier : `backend/config/services.yaml` (bloc `when@test`)

**Interfaces :**
- Produit : `CorpusRendererInterface::render(Locale $locale): string`, un document délimité par
  `<documents>` … `</documents>\n`.

- [x] **Étape 1 : doubles de test**

`backend/tests/Ai/Assistant/Support/StubProvider.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Support;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;

/**
 * Provider de test : rend une liste préparée et retient les variables d'URI
 * reçues, pour vérifier que le renderer demande bien la locale attendue.
 *
 * @template T of object
 *
 * @implements ProviderInterface<T>
 */
final class StubProvider implements ProviderInterface
{
    /** @var array<string, mixed> */
    public array $lastUriVariables = [];

    /**
     * @param list<T> $entries
     */
    public function __construct(private readonly array $entries)
    {
    }

    /**
     * @return list<T>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $this->lastUriVariables = $uriVariables;

        return $this->entries;
    }
}
```

- [x] **Étape 2 : écrire le test unitaire qui échoue**

`backend/tests/Ai/Assistant/Application/Corpus/CorpusRendererTest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Application\Corpus;

use App\Ai\Assistant\Application\Corpus\CorpusRenderer;
use App\Portfolio\AnonymousCv\Presentation\ApiResource\AnonymousCvSectionResource;
use App\Portfolio\CaseStudy\Presentation\ApiResource\CaseStudyResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Tests\Ai\Assistant\Support\StubProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rendu du corpus (spec 0005 D5, M2). Les snapshots sous __snapshots__ sont la
 * forme exacte envoyée au modèle : les modifier est un choix, pas un effet de
 * bord. Contenu fictif par construction (§9).
 */
final class CorpusRendererTest extends TestCase
{
    private const string SNAPSHOTS = __DIR__.'/__snapshots__/';

    public function testFrenchCorpusMatchesTheSnapshot(): void
    {
        self::assertStringEqualsFile(self::SNAPSHOTS.'fr.md', $this->renderer()->render(Locale::FR));
    }

    public function testEnglishCorpusMatchesTheSnapshot(): void
    {
        self::assertStringEqualsFile(self::SNAPSHOTS.'en.md', $this->renderer()->render(Locale::EN));
    }

    /**
     * Mesure de la tâche 1 : sans bloc explicite, mistral-small-3.2 invente un
     * parcours complet ; avec un bloc vide qui le dit, il n'invente rien.
     */
    public function testEmptySourcesStillProduceADelimitedBlockThatNamesEachAbsence(): void
    {
        $renderer = new CorpusRenderer(new StubProvider([]), new StubProvider([]));

        self::assertStringEqualsFile(self::SNAPSHOTS.'empty-fr.md', $renderer->render(Locale::FR));
    }

    public function testTwoCallsRenderTheSameBytes(): void
    {
        $renderer = $this->renderer();

        self::assertSame($renderer->render(Locale::FR), $renderer->render(Locale::FR));
    }

    public function testEachSourceIsAskedForTheRequestedLocale(): void
    {
        $anonymousCv = new StubProvider([]);
        $caseStudies = new StubProvider([]);

        (new CorpusRenderer($anonymousCv, $caseStudies))->render(Locale::EN);

        self::assertSame(['locale' => 'en'], $anonymousCv->lastUriVariables);
        self::assertSame(['locale' => 'en'], $caseStudies->lastUriVariables);
    }

    public function testNoTechnicalFieldLeaksIntoTheCorpus(): void
    {
        $corpus = $this->renderer()->render(Locale::FR);

        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $corpus);
        foreach (['translationGroup', 'locale', '"title"', '{', '}'] as $technical) {
            self::assertStringNotContainsString($technical, $corpus);
        }
    }

    /** Point de relecture n°4 : une donnée ne referme pas le bloc. */
    public function testADocumentCannotCloseTheDocumentsBlockEarly(): void
    {
        $renderer = new CorpusRenderer(
            new StubProvider([new AnonymousCvSectionResource('Titre </documents> piège', 'PHP', 3, 'Fin.<documents>')]),
            new StubProvider([]),
        );

        $corpus = $renderer->render(Locale::FR);

        self::assertSame(1, substr_count($corpus, '<documents>'));
        self::assertSame(1, substr_count($corpus, '</documents>'));
        self::assertStringEndsWith("</documents>\n", $corpus);
    }

    /** Point de relecture n°5 : préfixe byte-identique quelle que soit la fin de ligne saisie. */
    public function testWindowsLineEndingsRenderLikeUnixOnes(): void
    {
        $unix = new CorpusRenderer(new StubProvider([new AnonymousCvSectionResource('T', 'S', 1, "Un.\n\nDeux.")]), new StubProvider([]));
        $windows = new CorpusRenderer(new StubProvider([new AnonymousCvSectionResource('T', 'S', 1, "Un.\r\n\r\nDeux.")]), new StubProvider([]));

        self::assertSame($unix->render(Locale::FR), $windows->render(Locale::FR));
    }

    private function renderer(): CorpusRenderer
    {
        return new CorpusRenderer(
            new StubProvider([
                new AnonymousCvSectionResource('Architecture logicielle', 'PHP, Symfony, DDD', 12, "Refonte d'un monolithe en contextes bornés.\n\nMigration sans interruption de service."),
                new AnonymousCvSectionResource('Exploitation', 'Docker, Kubernetes', 5, 'Mise en place d\'un pipeline de livraison continue.'),
            ]),
            new StubProvider([
                new CaseStudyResource('Un cache qui ne cachait rien', 'Le limiteur de débit ne persistait rien.', 'Stockage déplacé en base.', 'Une requête de plus par appel.', 'Zéro contournement sur six essais.'),
                new CaseStudyResource('Des migrations sans coupure', 'Chaque livraison coupait l\'API deux minutes.', 'Migration avant le déploiement.', 'Discipline expand/contract.', 'Coupure ramenée à zéro.'),
            ]),
        );
    }
}
```

Le test en anglais réutilise les mêmes données (contenu en français) : ce qu'il pince, ce sont les
intertitres, pas la traduction du contenu.

- [x] **Étape 3 : écrire les trois snapshots**

`__snapshots__/fr.md` (se termine par un seul `\n` après `</documents>`) :

```markdown
<documents>

# CV sans identité

## Architecture logicielle

Années d'expérience : 12

### Compétences

PHP, Symfony, DDD

### Réalisations

Refonte d'un monolithe en contextes bornés.

Migration sans interruption de service.

## Exploitation

Années d'expérience : 5

### Compétences

Docker, Kubernetes

### Réalisations

Mise en place d'un pipeline de livraison continue.

# Études de cas

## Un cache qui ne cachait rien

### Problème

Le limiteur de débit ne persistait rien.

### Solution

Stockage déplacé en base.

### Compromis

Une requête de plus par appel.

### Résultat mesuré

Zéro contournement sur six essais.

## Des migrations sans coupure

### Problème

Chaque livraison coupait l'API deux minutes.

### Solution

Migration avant le déploiement.

### Compromis

Discipline expand/contract.

### Résultat mesuré

Coupure ramenée à zéro.

</documents>
```

`__snapshots__/en.md` (intertitres repris des libellés du frontend, `en.json` ; le contenu reste
en français, c'est voulu) :

```markdown
<documents>

# CV without identity

## Architecture logicielle

Years of experience: 12

### Skills

PHP, Symfony, DDD

### Achievements

Refonte d'un monolithe en contextes bornés.

Migration sans interruption de service.

## Exploitation

Years of experience: 5

### Skills

Docker, Kubernetes

### Achievements

Mise en place d'un pipeline de livraison continue.

# Case studies

## Un cache qui ne cachait rien

### Problem

Le limiteur de débit ne persistait rien.

### Solution

Stockage déplacé en base.

### Trade-offs

Une requête de plus par appel.

### Measured result

Zéro contournement sur six essais.

## Des migrations sans coupure

### Problem

Chaque livraison coupait l'API deux minutes.

### Solution

Migration avant le déploiement.

### Trade-offs

Discipline expand/contract.

### Measured result

Coupure ramenée à zéro.

</documents>
```

`__snapshots__/empty-fr.md` :

```markdown
<documents>

# CV sans identité

Aucun document disponible pour cette section.

# Études de cas

Aucun document disponible pour cette section.

</documents>
```

- [x] **Étape 4 : lancer le test pour vérifier qu'il échoue**

Commande : `docker compose exec -T backend php bin/phpunit tests/Ai/Assistant/Application/Corpus/CorpusRendererTest.php`
Attendu : ÉCHEC, `Class "App\Ai\Assistant\Application\Corpus\CorpusRenderer" not found`.

- [x] **Étape 5 : implémentation minimale**

`CorpusRendererInterface.php` :

```php
<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Application\Corpus;

use App\Portfolio\Shared\Domain\ValueObject\Locale;

/**
 * Rend, pour une locale, le corpus que l'assistant a le droit de connaître :
 * exactement ce que le palier nominatif lit déjà (spec 0005 D5, ADR 0004 D7).
 * Assemblé à chaque appel, jamais précalculé ni mis en cache.
 */
interface CorpusRendererInterface
{
    public function render(Locale $locale): string;
}
```

`CorpusRenderer.php` :

```php
<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Application\Corpus;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\AnonymousCv\Infrastructure\ApiPlatform\AnonymousCvProvider;
use App\Portfolio\AnonymousCv\Presentation\ApiResource\AnonymousCvSectionResource;
use App\Portfolio\CaseStudy\Infrastructure\ApiPlatform\CaseStudyProvider;
use App\Portfolio\CaseStudy\Presentation\ApiResource\CaseStudyResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Corpus de l'assistant, en Markdown déterministe (spec 0005 D5).
 *
 * Les sources sont lues par les providers publics, jamais par un repository :
 * l'assistant ne peut ainsi rien savoir que la personne ne lise déjà sur le
 * site, et une injection de prompt réussie ne révèle rien (ADR 0004 D7).
 * CorpusSourcesTest pince la liste.
 *
 * Le document est toujours délimité, même vide, et chaque section vide le dit
 * en toutes lettres : sans bloc explicite, mistral-small-3.2 invente un
 * parcours complet (mesure du 2026-09-26, journal de la spec). L'ordre des
 * entrées est celui des providers (position croissante), le même que sur les
 * pages. Aucun champ technique (id, groupe, locale) n'y figure, et le rendu est
 * byte-identique d'un appel à l'autre : c'est la condition du cache de prompt
 * du fournisseur (D8).
 */
final readonly class CorpusRenderer implements CorpusRendererInterface
{
    private const string OPENING_TAG = '<documents>';
    private const string CLOSING_TAG = '</documents>';

    /**
     * @param ProviderInterface<AnonymousCvSectionResource> $anonymousCvProvider
     * @param ProviderInterface<CaseStudyResource>          $caseStudyProvider
     */
    public function __construct(
        #[Autowire(service: AnonymousCvProvider::class)]
        private ProviderInterface $anonymousCvProvider,
        #[Autowire(service: CaseStudyProvider::class)]
        private ProviderInterface $caseStudyProvider,
    ) {
    }

    public function render(Locale $locale): string
    {
        $labels = self::labels($locale);

        $blocks = [
            self::OPENING_TAG,
            ...$this->section(
                $labels['anonymousCv'],
                array_map(
                    fn (AnonymousCvSectionResource $section): string => $this->anonymousCvEntry($section, $labels),
                    $this->read($this->anonymousCvProvider, AnonymousCvSectionResource::class, $locale),
                ),
                $labels,
            ),
            ...$this->section(
                $labels['caseStudies'],
                array_map(
                    fn (CaseStudyResource $caseStudy): string => $this->caseStudyEntry($caseStudy, $labels),
                    $this->read($this->caseStudyProvider, CaseStudyResource::class, $locale),
                ),
                $labels,
            ),
            self::CLOSING_TAG,
        ];

        return implode("\n\n", $blocks)."\n";
    }

    /**
     * Intertitres dans la langue du corpus : des libellés de rendu pour le
     * modèle, pas des chaînes d'interface (spec §7). Le `match` sur l'enum est
     * exhaustif : une troisième locale ne compile plus sous PHPStan tant
     * qu'elle n'a pas ses libellés.
     *
     * @return array{anonymousCv: string, caseStudies: string, yearsOfExperience: string, skills: string, achievements: string, problem: string, solution: string, tradeoffs: string, measuredResult: string, empty: string}
     */
    private static function labels(Locale $locale): array
    {
        return match ($locale) {
            Locale::FR => [
                'anonymousCv' => 'CV sans identité',
                'caseStudies' => 'Études de cas',
                'yearsOfExperience' => "Années d'expérience : %d",
                'skills' => 'Compétences',
                'achievements' => 'Réalisations',
                'problem' => 'Problème',
                'solution' => 'Solution',
                'tradeoffs' => 'Compromis',
                'measuredResult' => 'Résultat mesuré',
                'empty' => 'Aucun document disponible pour cette section.',
            ],
            Locale::EN => [
                'anonymousCv' => 'CV without identity',
                'caseStudies' => 'Case studies',
                'yearsOfExperience' => 'Years of experience: %d',
                'skills' => 'Skills',
                'achievements' => 'Achievements',
                'problem' => 'Problem',
                'solution' => 'Solution',
                'tradeoffs' => 'Trade-offs',
                'measuredResult' => 'Measured result',
                'empty' => 'No document available for this section.',
            ],
        };
    }

    /**
     * @template T of object
     *
     * @param ProviderInterface<T> $provider
     * @param class-string<T>      $class
     *
     * @return list<T>
     */
    private function read(ProviderInterface $provider, string $class, Locale $locale): array
    {
        $result = $provider->provide(new GetCollection(), ['locale' => $locale->value]);
        if (!is_iterable($result)) {
            return [];
        }

        $entries = [];
        foreach ($result as $entry) {
            if ($entry instanceof $class) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param list<string>          $entries
     * @param array{empty: string, ...} $labels
     *
     * @return list<string>
     */
    private function section(string $heading, array $entries, array $labels): array
    {
        return ['# '.$heading, ...([] === $entries ? [$labels['empty']] : $entries)];
    }

    /**
     * @param array{yearsOfExperience: string, skills: string, achievements: string, ...} $labels
     */
    private function anonymousCvEntry(AnonymousCvSectionResource $section, array $labels): string
    {
        return implode("\n\n", [
            '## '.self::text($section->title),
            \sprintf($labels['yearsOfExperience'], $section->yearsOfExperience),
            '### '.$labels['skills'],
            self::text($section->skills),
            '### '.$labels['achievements'],
            self::text($section->achievements),
        ]);
    }

    /**
     * @param array{problem: string, solution: string, tradeoffs: string, measuredResult: string, ...} $labels
     */
    private function caseStudyEntry(CaseStudyResource $caseStudy, array $labels): string
    {
        return implode("\n\n", [
            '## '.self::text($caseStudy->title),
            '### '.$labels['problem'],
            self::text($caseStudy->problem),
            '### '.$labels['solution'],
            self::text($caseStudy->solution),
            '### '.$labels['tradeoffs'],
            self::text($caseStudy->tradeoffs),
            '### '.$labels['measuredResult'],
            self::text($caseStudy->measuredResult),
        ]);
    }

    /**
     * Fins de ligne unifiées (préfixe byte-identique, D8) et balises du bloc
     * retirées : une donnée ne referme pas le bloc de documents.
     */
    private static function text(string $value): string
    {
        return trim(str_ireplace([self::OPENING_TAG, self::CLOSING_TAG], '', str_replace(["\r\n", "\r"], "\n", $value)));
    }
}
```

Si PHPStan refuse les formes `array{…, ...}` (formes non scellées) en `max`, typer `$labels`
avec la forme complète renvoyée par `labels()`, déclarée une fois via un `@phpstan-type Labels` en
tête de classe. Ne pas relâcher en `array<string, string>`.

- [x] **Étape 6 : lancer le test pour vérifier qu'il passe**

Commande : identique à l'étape 4. Attendu : SUCCÈS, 7 tests.

- [x] **Étape 7 : tests noyau, sources et ordre (rouges d'abord)**

Dans `backend/config/services.yaml`, bloc `when@test`, ajouter sous le commentaire existant :

```yaml
        # CorpusSourcesTest inspecte les sources réellement injectées dans le
        # renderer. Service privé à un seul consommateur : inliné à la
        # compilation, il serait introuvable dans le conteneur de test sans ceci.
        App\Ai\Assistant\Application\Corpus\CorpusRenderer:
            public: true
```

`backend/tests/Ai/Assistant/Application/Corpus/CorpusSourcesTest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Application\Corpus;

use App\Ai\Assistant\Application\Corpus\CorpusRenderer;
use App\Portfolio\AnonymousCv\Infrastructure\ApiPlatform\AnonymousCvProvider;
use App\Portfolio\CaseStudy\Infrastructure\ApiPlatform\CaseStudyProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Pince la liste des sources du corpus (spec 0005 D5, M2) sur le service
 * réellement construit par le conteneur, pas sur une déclaration : ajouter une
 * source fait échouer ce test tant qu'elle n'est pas inscrite ici, avec sa
 * justification, comme PUBLIC_PATHS dans ApiRouteExposureTest. Une source
 * supplémentaire demande un amendement de la spec (§9, « Demander avant »).
 */
final class CorpusSourcesTest extends KernelTestCase
{
    /** Source → pourquoi l'assistant a le droit de la lire. */
    private const array ALLOWED_SOURCES = [
        AnonymousCvProvider::class => 'CV sans identité : lu par le palier de base, donc par tout ROLE_TRUSTED (ADR 0003 D5). Provider public, mêmes données que GET /api/anonymous-cv/{locale}.',
        CaseStudyProvider::class => 'Études de cas : même palier, même raison. Provider public, mêmes données que GET /api/case-studies/{locale}.',
    ];

    public function testTheCorpusReadsExactlyTheAllowedSources(): void
    {
        $sources = $this->injectedSources();
        $expected = array_keys(self::ALLOWED_SOURCES);
        sort($expected);

        self::assertSame($expected, $sources);
    }

    public function testNoSourceIsARepositoryOrABackofficeClass(): void
    {
        foreach ($this->injectedSources() as $source) {
            $shortName = (new \ReflectionClass($source))->getShortName();
            self::assertStringNotContainsString('Backoffice', $shortName, $source);
            self::assertStringEndsNotWith('Repository', $shortName, $source);
        }
    }

    /**
     * @return list<string>
     */
    private function injectedSources(): array
    {
        self::bootKernel();
        $renderer = self::getContainer()->get(CorpusRenderer::class);

        $sources = [];
        foreach ((new \ReflectionObject($renderer))->getProperties() as $property) {
            $value = $property->getValue($renderer);
            if (\is_object($value)) {
                $sources[] = $value::class;
            }
        }
        sort($sources);

        return $sources;
    }
}
```

`backend/tests/Ai/Assistant/Application/Corpus/CorpusRendererOrderTest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Application\Corpus;

use App\Ai\Assistant\Application\Corpus\CorpusRenderer;
use App\Portfolio\AnonymousCv\Domain\Entity\AnonymousCvSection;
use App\Portfolio\CaseStudy\Domain\Entity\CaseStudy;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Les DTO publics ne portent pas `position` : l'ordre du corpus est celui des
 * providers. Ce test pince la chaîne entière, du tri SQL au rendu, avec des
 * lignes insérées dans le désordre et une ligne d'une autre locale.
 */
final class CorpusRendererOrderTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM anonymous_cv_section');
        $connection->executeStatement('DELETE FROM case_study');
        parent::tearDown();
    }

    public function testEntriesFollowAscendingPositionAndOnlyTheRequestedLocale(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        foreach ([2 => 'Troisième', 0 => 'Première', 1 => 'Deuxième'] as $position => $title) {
            $entityManager->persist(new AnonymousCvSection(Locale::FR, $title, 'S', 1, 'R', $position));
            $entityManager->persist(new CaseStudy(Locale::FR, 'Étude '.$title, 'P', 'S', 'C', 'R', $position));
        }
        $entityManager->persist(new AnonymousCvSection(Locale::EN, 'English only', 'S', 1, 'R', 0));
        $entityManager->flush();

        // La classe concrète, rendue publique en test (étape 7) : l'alias
        // d'interface pourrait être inliné et introuvable ici.
        $corpus = self::getContainer()->get(CorpusRenderer::class)->render(Locale::FR);

        self::assertMatchesRegularExpression('/## Première.*## Deuxième.*## Troisième/s', $corpus);
        self::assertMatchesRegularExpression('/## Étude Première.*## Étude Deuxième.*## Étude Troisième/s', $corpus);
        self::assertStringNotContainsString('English only', $corpus);
    }
}
```

Avant d'écrire le `tearDown`, vérifier les noms de tables (`grep -n "ORM\\\\Table" src/Portfolio/{AnonymousCv,CaseStudy}/Domain/Entity/*.php`)
et s'aligner sur ce que font déjà les tests existants de ces contextes.

Commande : `docker compose exec -T backend php bin/phpunit tests/Ai/Assistant/Application/Corpus`
Attendu, **avant** l'ajout de `when@test` : `CorpusSourcesTest` en ÉCHEC (« service has been
removed or inlined »). Après : les 10 tests VERTS.

**Vérifier que `CorpusSourcesTest` mord :** ajouter temporairement un paramètre
`private EntityManagerInterface $em` au constructeur du renderer, constater l'ÉCHEC des deux tests,
puis retirer le paramètre.

- [x] **Étape 8 : qualité locale puis commit**

Commande : `docker compose exec -T backend composer phpstan -- src/Ai tests/Ai` puis
`docker compose exec -T backend vendor/bin/rector process --dry-run src/Ai tests/Ai`
Attendu : aucune erreur.

```bash
git add backend/src/Ai/Assistant/Application/Corpus backend/tests/Ai/Assistant/Support/StubProvider.php \
  backend/tests/Ai/Assistant/Application/Corpus backend/config/services.yaml
git commit -m "feat(ai): corpus de l'assistant rendu depuis les providers publics"
```

---

## Tâche 2 : l'assistant en flux (M3 hors bornes et quota)

**Fichiers :**
- Créer : les quatre VO et les deux exceptions de `Domain/`, `Application/CareerAssistantInterface.php`,
  `Application/CareerAssistantSystemPrompt.php`, `Infrastructure/SymfonyAi/SymfonyAiCareerAssistant.php`
- Créer : `tests/Ai/Support/FakeStreamingAgent.php`, `tests/Ai/Assistant/Support/StubCorpusRenderer.php`,
  `ConversationTest.php`, `CareerAssistantSystemPromptTest.php`, `SymfonyAiCareerAssistantTest.php`
- Modifier : `backend/config/packages/api_platform.yaml` (`exception_to_status`)

**Interfaces :**
- Consomme : `CorpusRendererInterface::render(Locale): string` (tâche 1).
- Produit :
  - `enum Role: string { User = 'user'; Assistant = 'assistant' }` avec `Role::values(): list<string>`
  - `new ConversationMessage(Role $role, string $content)`, propriétés publiques `role`, `content`
  - `new Conversation(list<ConversationMessage> $messages)`, `messages(): non-empty-list<ConversationMessage>`, `count(): int`
  - `new AnswerUsage(?int $promptTokens, ?int $completionTokens, int $durationMs)`, propriétés publiques
  - `CareerAssistantInterface::answer(Conversation $conversation, Locale $locale): \Generator<int, string, mixed, AnswerUsage>`
  - `AssistantUnavailableException` (503, `type: /errors/assistant-unavailable`), `InvalidConversationException` (422)

- [x] **Étape 1 : VO et exceptions, test d'abord**

`tests/Ai/Assistant/Domain/ValueObject/ConversationTest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Domain\ValueObject;

use App\Ai\Assistant\Domain\Exception\InvalidConversationException;
use App\Ai\Assistant\Domain\ValueObject\Conversation;
use App\Ai\Assistant\Domain\ValueObject\ConversationMessage;
use App\Ai\Assistant\Domain\ValueObject\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Invariants structurels de la conversation. Les bornes de coût (D6 :
 * nombre, longueurs, alternance) arrivent avec la tâche 3 (#262).
 */
final class ConversationTest extends TestCase
{
    public function testKeepsTheMessagesInTheOrderReceived(): void
    {
        $conversation = new Conversation([
            new ConversationMessage(Role::User, 'Première question ?'),
            new ConversationMessage(Role::Assistant, 'Première réponse.'),
            new ConversationMessage(Role::User, 'Seconde question ?'),
        ]);

        self::assertCount(3, $conversation);
        self::assertSame('Seconde question ?', $conversation->messages()[2]->content);
    }

    public function testAnEmptyConversationIsRefused(): void
    {
        $this->expectException(InvalidConversationException::class);

        new Conversation([]);
    }

    #[DataProvider('blankContents')]
    public function testABlankMessageIsRefused(string $content): void
    {
        $this->expectException(InvalidConversationException::class);

        new ConversationMessage(Role::User, $content);
    }

    /** @return iterable<string, array{string}> */
    public static function blankContents(): iterable
    {
        yield 'vide' => [''];
        yield 'espaces' => ['   '];
        yield 'retours à la ligne' => ["\n\t\n"];
    }

    public function testRoleValuesAreTheWireNames(): void
    {
        self::assertSame(['user', 'assistant'], Role::values());
    }
}
```

Lancer : `docker compose exec -T backend php bin/phpunit tests/Ai/Assistant/Domain` → ÉCHEC (classes absentes).

Implémentation :

`src/Ai/Assistant/Domain/ValueObject/Role.php` :

```php
<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Domain\ValueObject;

/** Auteur d'un message de la conversation ; les valeurs sont celles du corps JSON. */
enum Role: string
{
    case User = 'user';
    case Assistant = 'assistant';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
```

`src/Ai/Assistant/Domain/ValueObject/ConversationMessage.php` :

```php
<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Domain\ValueObject;

use App\Ai\Assistant\Domain\Exception\InvalidConversationException;

final readonly class ConversationMessage
{
    public function __construct(
        public Role $role,
        public string $content,
    ) {
        if ('' === trim($content)) {
            throw new InvalidConversationException('Un message de la conversation est vide.');
        }
    }
}
```

`src/Ai/Assistant/Domain/ValueObject/Conversation.php` :

```php
<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Domain\ValueObject;

use App\Ai\Assistant\Domain\Exception\InvalidConversationException;

/**
 * Conversation envoyée par la personne, dans l'ordre reçu. Rien n'en est
 * conservé côté serveur (spec 0005 D7) : le frontend la renvoie à chaque tour.
 */
final readonly class Conversation implements \Countable
{
    /** @var non-empty-list<ConversationMessage> */
    private array $messages;

    /**
     * @param list<ConversationMessage> $messages
     */
    public function __construct(array $messages)
    {
        if ([] === $messages) {
            throw new InvalidConversationException('La conversation est vide.');
        }

        $this->messages = $messages;
    }

    /** @return non-empty-list<ConversationMessage> */
    public function messages(): array
    {
        return $this->messages;
    }

    public function count(): int
    {
        return \count($this->messages);
    }
}
```

`src/Ai/Assistant/Domain/ValueObject/AnswerUsage.php` :

```php
<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Domain\ValueObject;

/**
 * Ce que coûte une réponse, lu après la fin du flux. Les jetons sont null si
 * le fournisseur ne les a pas transmis.
 */
final readonly class AnswerUsage
{
    public function __construct(
        public ?int $promptTokens,
        public ?int $completionTokens,
        public int $durationMs,
    ) {
    }
}
```

`src/Ai/Assistant/Domain/Exception/InvalidConversationException.php` :

```php
<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Domain\Exception;

/**
 * La conversation reçue ne respecte pas ses invariants. Mappée 422
 * (api_platform.yaml). Le message ne cite jamais le contenu reçu.
 */
final class InvalidConversationException extends \DomainException
{
}
```

`src/Ai/Assistant/Domain/Exception/AssistantUnavailableException.php` :

```php
<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;

/**
 * L'assistant n'a pas pu répondre : fournisseur injoignable, refus, délai
 * dépassé, flux interrompu. Le message est volontairement générique — la cause
 * (classe, statut HTTP du fournisseur) est journalisée par l'appelant, jamais
 * renvoyée au client. Mappée 503 avec un `type` stable
 * (`/errors/assistant-unavailable`) ; après le début du flux, elle devient
 * l'événement `error` de même raison.
 */
final class AssistantUnavailableException extends \RuntimeException implements ProblemExceptionInterface
{
    use HasProblemType;

    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct("L'assistant est indisponible. Réessayez plus tard.", 0, $previous);
    }

    protected function problemType(): string
    {
        return 'assistant-unavailable';
    }

    protected function problemStatus(): int
    {
        return 503;
    }
}
```

Dans `backend/config/packages/api_platform.yaml`, sous l'entrée
`TranslationUnavailableException: 503` (et donc **au-dessus** des trois défauts restaurés en fin de
liste) :

```yaml
        App\Ai\Assistant\Domain\Exception\AssistantUnavailableException: 503
        App\Ai\Assistant\Domain\Exception\InvalidConversationException: 422
```

Relancer : les tests de `Domain` passent.

- [x] **Étape 2 : le message système, test d'abord**

`tests/Ai/Assistant/Support/StubCorpusRenderer.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Support;

use App\Ai\Assistant\Application\Corpus\CorpusRendererInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

final class StubCorpusRenderer implements CorpusRendererInterface
{
    public ?Locale $lastLocale = null;

    public function __construct(private readonly string $corpus = "<documents>\n\nCORPUS\n\n</documents>\n")
    {
    }

    public function render(Locale $locale): string
    {
        $this->lastLocale = $locale;

        return $this->corpus;
    }
}
```

`tests/Ai/Assistant/Application/CareerAssistantSystemPromptTest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Application;

use App\Ai\Assistant\Application\CareerAssistantSystemPrompt;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Tests\Ai\Assistant\Support\StubCorpusRenderer;
use PHPUnit\Framework\TestCase;

final class CareerAssistantSystemPromptTest extends TestCase
{
    private const string PREAMBLE_FILE = __DIR__.'/../../../../config/ai/prompts/career_assistant.txt';

    public function testThePreambleComesFirstThenTheCorpusOfTheRequestedLocale(): void
    {
        $renderer = new StubCorpusRenderer();
        $prompt = (new CareerAssistantSystemPrompt($renderer, self::PREAMBLE_FILE))->compose(Locale::EN);

        $preamble = rtrim((string) file_get_contents(self::PREAMBLE_FILE));
        self::assertSame($preamble."\n\n<documents>\n\nCORPUS\n\n</documents>\n", $prompt);
        self::assertSame(Locale::EN, $renderer->lastLocale);
    }

    public function testAMissingPreambleIsADeploymentBugNotAnEmptyPrompt(): void
    {
        $this->expectException(\LogicException::class);

        (new CareerAssistantSystemPrompt(new StubCorpusRenderer(), '/nonexistent/prompt.txt'))->compose(Locale::FR);
    }
}
```

Lancer : ÉCHEC (classe absente). Implémenter `src/Ai/Assistant/Application/CareerAssistantSystemPrompt.php` :

```php
<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Application;

use App\Ai\Assistant\Application\Corpus\CorpusRendererInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Message système de l'assistant : le préambule fixe, puis le corpus rendu
 * (spec 0005 D8), donc un préfixe byte-identique d'un appel à l'autre pour une
 * locale donnée, ce qui ouvre le cache de prompt du fournisseur.
 *
 * Composé ici plutôt que par le bundle : SystemPromptInputProcessor n'injecte
 * pas le `prompt.file` d'ai.yaml quand la conversation porte déjà un message
 * système. Le fichier lu est le même ; ai.yaml le garde pour `ai:agent:call`.
 */
final readonly class CareerAssistantSystemPrompt
{
    public function __construct(
        private CorpusRendererInterface $corpusRenderer,
        #[Autowire('%kernel.project_dir%/config/ai/prompts/career_assistant.txt')]
        private string $preambleFile,
    ) {
    }

    public function compose(Locale $locale): string
    {
        $preamble = is_file($this->preambleFile) ? file_get_contents($this->preambleFile) : false;
        if (false === $preamble || '' === trim($preamble)) {
            throw new \LogicException(\sprintf('Préambule de l\'assistant introuvable ou vide : %s.', $this->preambleFile));
        }

        return rtrim($preamble)."\n\n".$this->corpusRenderer->render($locale);
    }
}
```

Relancer : VERT.

- [x] **Étape 3 : un agent de test qui diffuse**

`tests/Ai/Support/FakeStreamingAgent.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Ai\Support;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Agent\Execution\Update\Result as ResultUpdate;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\TokenUsage\TokenUsage;

/**
 * Agent de test en flux. Comme le vrai Runner, il est paresseux : rien ne se
 * passe avant la première itération, et un échec « avant le premier fragment »
 * est levé à ce moment-là, pas par call(). Il diffuse des Progress('delta')
 * portant des TextDelta, puis le résultat final avec ses métadonnées.
 */
final class FakeStreamingAgent implements AgentInterface
{
    public ?MessageBag $lastMessages = null;

    /** @var array<string, mixed> */
    public array $lastOptions = [];

    /**
     * @param list<string> $fragments
     * @param int          $failAfter nombre de fragments diffusés avant $failure
     */
    public function __construct(
        private readonly array $fragments,
        private readonly ?TokenUsage $tokenUsage = null,
        private readonly ?\Throwable $failure = null,
        private readonly int $failAfter = 0,
    ) {
    }

    public function call(string|MessageBag|UserMessage $input, array $options = []): Execution
    {
        $this->lastMessages = $input instanceof MessageBag ? $input : new MessageBag(
            $input instanceof UserMessage ? $input : Message::ofUser($input),
        );
        $this->lastOptions = $options;

        return new Execution(fn (): \Generator => $this->run(), true === ($options['stream'] ?? false));
    }

    public function getName(): string
    {
        return 'fake-streaming';
    }

    /**
     * @return \Generator<int, Progress|ResultUpdate, mixed, void>
     */
    private function run(): \Generator
    {
        foreach ($this->fragments as $index => $fragment) {
            if (null !== $this->failure && $index === $this->failAfter) {
                throw $this->failure;
            }
            yield new Progress('delta', 'Received a streamed delta.', new TextDelta($fragment));
        }

        if (null !== $this->failure && $this->failAfter >= \count($this->fragments)) {
            throw $this->failure;
        }

        $result = new TextResult(implode('', $this->fragments));
        if (null !== $this->tokenUsage) {
            $result->getMetadata()->add('token_usage', $this->tokenUsage);
        }

        yield new ResultUpdate($result);
    }
}
```

Vérifier dans `vendor/symfony/ai-platform/src/Result/Stream/Delta/TextDelta.php` et
`vendor/symfony/ai-platform/src/Result/TextResult.php` que les constructeurs prennent bien une
chaîne ; ajuster sinon.

- [x] **Étape 4 : le service, test d'abord**

`tests/Ai/Assistant/Infrastructure/SymfonyAi/SymfonyAiCareerAssistantTest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Infrastructure\SymfonyAi;

use App\Ai\Assistant\Application\CareerAssistantSystemPrompt;
use App\Ai\Assistant\Domain\Exception\AssistantUnavailableException;
use App\Ai\Assistant\Domain\ValueObject\AnswerUsage;
use App\Ai\Assistant\Domain\ValueObject\Conversation;
use App\Ai\Assistant\Domain\ValueObject\ConversationMessage;
use App\Ai\Assistant\Domain\ValueObject\Role;
use App\Ai\Assistant\Infrastructure\SymfonyAi\SymfonyAiCareerAssistant;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Tests\Ai\Assistant\Support\StubCorpusRenderer;
use App\Tests\Ai\Support\FakeStreamingAgent;
use App\Tests\Ai\Translation\Support\InMemoryLogger;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\RuntimeException as PlatformRuntimeException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\HttpClient\Exception\TransportException;

final class SymfonyAiCareerAssistantTest extends TestCase
{
    private const string PREAMBLE_FILE = __DIR__.'/../../../../../config/ai/prompts/career_assistant.txt';
    private const string QUESTION = 'Question sentinelle sur le parcours ?';
    private const string PREVIOUS_ANSWER = 'Réponse sentinelle précédente.';
    private const string CORPUS = "<documents>\n\nCORPUS-SENTINELLE\n\n</documents>\n";

    private InMemoryLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new InMemoryLogger();
    }

    public function testFragmentsFormTheAnswerAndTheReturnValueCarriesTheUsage(): void
    {
        $agent = new FakeStreamingAgent(['Il a ', 'conçu ', 'des API.'], new TokenUsage(promptTokens: 812, completionTokens: 9));

        $stream = $this->assistant($agent)->answer($this->conversation(), Locale::FR);
        $answer = implode('', iterator_to_array($stream, false));

        self::assertSame('Il a conçu des API.', $answer);
        $usage = $stream->getReturn();
        self::assertInstanceOf(AnswerUsage::class, $usage);
        self::assertSame(812, $usage->promptTokens);
        self::assertSame(9, $usage->completionTokens);
        self::assertGreaterThanOrEqual(0, $usage->durationMs);
    }

    public function testTheCallIsStreamedAndAsksForTheUsage(): void
    {
        $agent = new FakeStreamingAgent(['ok']);

        iterator_to_array($this->assistant($agent)->answer($this->conversation(), Locale::FR), false);

        self::assertTrue($agent->lastOptions['stream'] ?? null);
        self::assertSame(['include_usage' => true], $agent->lastOptions['stream_options'] ?? null);
    }

    public function testSystemMessageIsPreamblePlusCorpusThenTheConversationInOrder(): void
    {
        $agent = new FakeStreamingAgent(['ok']);

        iterator_to_array($this->assistant($agent)->answer($this->conversation(), Locale::FR), false);

        $messages = ($agent->lastMessages ?? self::fail('Aucun appel.'))->getMessages();
        self::assertCount(4, $messages);
        self::assertInstanceOf(SystemMessage::class, $messages[0]);
        $system = (string) $messages[0]->getContent();
        self::assertStringStartsWith('You are the career assistant', $system);
        self::assertStringEndsWith(self::CORPUS, $system);
        self::assertInstanceOf(UserMessage::class, $messages[1]);
        self::assertInstanceOf(AssistantMessage::class, $messages[2]);
        self::assertInstanceOf(UserMessage::class, $messages[3]);
        self::assertSame(self::QUESTION, $messages[3]->asText());
    }

    /** L'appel part avant que answer() ne rende la main : le 503 est encore possible. */
    public function testAFailureBeforeTheFirstFragmentIsThrownByAnswerItself(): void
    {
        $agent = new FakeStreamingAgent([], failure: new ServerException(502, 'corps du fournisseur SENTINELLE'));

        try {
            $this->assistant($agent)->answer($this->conversation(), Locale::FR);
            self::fail('AssistantUnavailableException attendue.');
        } catch (AssistantUnavailableException) {
        }

        $record = $this->logger->records[0] ?? self::fail('Aucun log.');
        self::assertSame('error', $record['level']);
        self::assertSame('before-first-fragment', $record['context']['stage']);
        self::assertSame(502, $record['context']['providerStatus']);
    }

    /** Journal de la tâche 1 : le statut HTTP d'un échec en flux doit rester lisible. */
    public function testTheStatusOfAStreamedProviderRefusalIsLogged(): void
    {
        $agent = new FakeStreamingAgent([], failure: new PlatformRuntimeException('Unexpected response code 403: "{\"message\":\"SENTINELLE\"}"'));

        try {
            $this->assistant($agent)->answer($this->conversation(), Locale::FR);
        } catch (AssistantUnavailableException) {
        }

        self::assertSame(403, $this->logger->records[0]['context']['providerStatus'] ?? null);
    }

    public function testAFailureDuringTheStreamIsThrownByTheGeneratorAfterTheFirstFragments(): void
    {
        $agent = new FakeStreamingAgent(['Il a ', 'conçu'], failure: new TransportException('coupure'), failAfter: 1);
        $stream = $this->assistant($agent)->answer($this->conversation(), Locale::FR);

        $received = [];
        try {
            foreach ($stream as $fragment) {
                $received[] = $fragment;
            }
            self::fail('AssistantUnavailableException attendue.');
        } catch (AssistantUnavailableException) {
        }

        self::assertSame(['Il a '], $received);
        self::assertSame('during-stream', $this->logger->records[0]['context']['stage'] ?? null);
    }

    /** Point de relecture n°2 : aucun fragment n'est une réponse vide, pas une panne. */
    public function testAnAnswerWithoutAnyTextFragmentEndsNormally(): void
    {
        $stream = $this->assistant(new FakeStreamingAgent([]))->answer($this->conversation(), Locale::FR);

        self::assertSame([], iterator_to_array($stream, false));
        self::assertInstanceOf(AnswerUsage::class, $stream->getReturn());
    }

    public function testUsageIsLoggedAfterTheStreamAndNeverAnyContent(): void
    {
        $agent = new FakeStreamingAgent(['Fragment ', 'SENTINELLE-REPONSE'], new TokenUsage(promptTokens: 10, completionTokens: 2));
        $stream = $this->assistant($agent)->answer($this->conversation(), Locale::FR);

        self::assertSame([], $this->logger->records, 'Rien ne doit être journalisé avant la fin du flux.');
        iterator_to_array($stream, false);

        $record = $this->logger->records[0] ?? self::fail('Aucun log.');
        self::assertSame('info', $record['level']);
        self::assertSame('done', $record['context']['outcome']);
        self::assertSame(3, $record['context']['messageCount']);
        self::assertSame(10, $record['context']['promptTokens']);
        self::assertSame(2, $record['context']['completionTokens']);

        $dump = $this->logger->dump();
        foreach (['SENTINELLE-REPONSE', 'Question sentinelle', 'Réponse sentinelle', 'CORPUS-SENTINELLE', 'You are the career assistant'] as $content) {
            self::assertStringNotContainsString($content, $dump);
        }
    }

    public function testNoProviderMessageIsEverLogged(): void
    {
        $agent = new FakeStreamingAgent([], failure: new ServerException(500, 'SENTINELLE-FOURNISSEUR'));

        try {
            $this->assistant($agent)->answer($this->conversation(), Locale::FR);
        } catch (AssistantUnavailableException) {
        }

        self::assertStringNotContainsString('SENTINELLE-FOURNISSEUR', $this->logger->dump());
    }

    private function assistant(FakeStreamingAgent $agent): SymfonyAiCareerAssistant
    {
        return new SymfonyAiCareerAssistant(
            $agent,
            new CareerAssistantSystemPrompt(new StubCorpusRenderer(self::CORPUS), self::PREAMBLE_FILE),
            $this->logger,
        );
    }

    private function conversation(): Conversation
    {
        return new Conversation([
            new ConversationMessage(Role::User, 'Première question ?'),
            new ConversationMessage(Role::Assistant, self::PREVIOUS_ANSWER),
            new ConversationMessage(Role::User, self::QUESTION),
        ]);
    }
}
```

Lancer : `docker compose exec -T backend php bin/phpunit tests/Ai/Assistant/Infrastructure/SymfonyAi`
→ ÉCHEC (classes absentes). Vérifier au passage le nom exact de la classe de message assistant et
l'accesseur de contenu du `SystemMessage` dans `vendor/symfony/ai-platform/src/Message/`, puis
ajuster le test si besoin.

- [x] **Étape 5 : l'interface et le service**

`src/Ai/Assistant/Application/CareerAssistantInterface.php` :

```php
<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Application;

use App\Ai\Assistant\Domain\Exception\AssistantUnavailableException;
use App\Ai\Assistant\Domain\ValueObject\AnswerUsage;
use App\Ai\Assistant\Domain\ValueObject\Conversation;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

interface CareerAssistantInterface
{
    /**
     * Répond au dernier message de la conversation, fragment par fragment, à
     * partir du corpus de la locale (spec 0005 D8, D9).
     *
     * L'appel au fournisseur est lancé avant le retour : un échec avant le
     * premier fragment lève AssistantUnavailableException ici même, tant que le
     * statut HTTP peut encore changer. Un échec pendant le flux la lève depuis
     * le générateur. Sa valeur de retour, lue après consommation, porte les
     * jetons et la durée.
     *
     * @return \Generator<int, string, mixed, AnswerUsage>
     *
     * @throws AssistantUnavailableException
     */
    public function answer(Conversation $conversation, Locale $locale): \Generator;
}
```

`src/Ai/Assistant/Infrastructure/SymfonyAi/SymfonyAiCareerAssistant.php` :

```php
<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Infrastructure\SymfonyAi;

use App\Ai\Assistant\Application\CareerAssistantInterface;
use App\Ai\Assistant\Application\CareerAssistantSystemPrompt;
use App\Ai\Assistant\Domain\Exception\AssistantUnavailableException;
use App\Ai\Assistant\Domain\ValueObject\AnswerUsage;
use App\Ai\Assistant\Domain\ValueObject\Conversation;
use App\Ai\Assistant\Domain\ValueObject\Role;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Exception\ExceptionInterface as AgentException;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/**
 * Seule classe de l'assistant à importer Symfony\AI (ADR 0004 D1).
 *
 * L'agent est injecté par son id : `PlatformInterface` s'autowire sur la
 * plateforme Anthropic, et le corpus nominatif ne doit parvenir qu'au modèle
 * opéré par l'hébergeur du site (ADR 0004 D3, journal de la tâche 1).
 *
 * Jamais de contenu dans un log (D10) : ni question, ni réponse, ni corpus,
 * ni message d'exception du fournisseur, que le bridge remplit avec le corps
 * de la réponse. Le statut HTTP de l'échec, lui, est journalisé.
 */
final readonly class SymfonyAiCareerAssistant implements CareerAssistantInterface
{
    /**
     * Le bridge Scaleway fusionne ces options telles quelles dans le corps de
     * la requête (API compatible OpenAI). Sans `include_usage`, une telle API
     * ne transmet pas les jetons consommés quand elle diffuse.
     */
    private const array CALL_OPTIONS = [
        'stream' => true,
        'stream_options' => ['include_usage' => true],
    ];

    public function __construct(
        #[Autowire(service: 'ai.agent.career_assistant')]
        private AgentInterface $agent,
        private CareerAssistantSystemPrompt $systemPrompt,
        private LoggerInterface $logger,
    ) {
    }

    public function answer(Conversation $conversation, Locale $locale): \Generator
    {
        $startedAt = hrtime(true);
        $messages = $this->messageBag($conversation, $locale);

        try {
            $execution = $this->agent->call($messages, self::CALL_OPTIONS);
            $fragments = $this->textFragments($execution);
            // L'exécution est paresseuse : sans cet amorçage, la requête ne
            // partirait qu'une fois le statut 200 envoyé, et un fournisseur
            // injoignable ne pourrait plus devenir un 503.
            $fragments->current();
        } catch (PlatformException|AgentException|HttpClientException $exception) {
            throw $this->unavailable($exception, $conversation, 'before-first-fragment');
        }

        return $this->relay($fragments, $execution, $conversation, $startedAt);
    }

    private function messageBag(Conversation $conversation, Locale $locale): MessageBag
    {
        $messages = [Message::forSystem($this->systemPrompt->compose($locale))];
        foreach ($conversation->messages() as $message) {
            $messages[] = match ($message->role) {
                Role::User => Message::ofUser($message->content),
                Role::Assistant => Message::ofAssistant($message->content),
            };
        }

        return new MessageBag(...$messages);
    }

    /**
     * @return \Generator<int, string, mixed, void>
     */
    private function textFragments(Execution $execution): \Generator
    {
        foreach ($execution->asStream() as $delta) {
            if ($delta instanceof TextDelta && '' !== $delta->getText()) {
                yield $delta->getText();
            }
        }
    }

    /**
     * @param \Generator<int, string, mixed, void> $fragments déjà amorcé
     *
     * @return \Generator<int, string, mixed, AnswerUsage>
     */
    private function relay(\Generator $fragments, Execution $execution, Conversation $conversation, int $startedAt): \Generator
    {
        try {
            while ($fragments->valid()) {
                yield $fragments->current();
                $fragments->next();
            }
        } catch (PlatformException|AgentException|HttpClientException $exception) {
            throw $this->unavailable($exception, $conversation, 'during-stream');
        }

        $tokenUsage = $execution->getMetadata()->get('token_usage');
        $usage = new AnswerUsage(
            promptTokens: $tokenUsage instanceof TokenUsageInterface ? $tokenUsage->getPromptTokens() : null,
            completionTokens: $tokenUsage instanceof TokenUsageInterface ? $tokenUsage->getCompletionTokens() : null,
            durationMs: (int) round((hrtime(true) - $startedAt) / 1_000_000),
        );

        $this->logger->info('Assistant de parcours : réponse produite.', [
            'outcome' => 'done',
            'messageCount' => $conversation->count(),
            'durationMs' => $usage->durationMs,
            'promptTokens' => $usage->promptTokens,
            'completionTokens' => $usage->completionTokens,
        ]);

        return $usage;
    }

    private function unavailable(\Throwable $exception, Conversation $conversation, string $stage): AssistantUnavailableException
    {
        $this->logger->error('Assistant de parcours : le fournisseur a échoué.', [
            'outcome' => 'error',
            'stage' => $stage,
            'exception' => $exception::class,
            'providerStatus' => $this->providerStatus($exception),
            'messageCount' => $conversation->count(),
        ]);

        return new AssistantUnavailableException($exception);
    }

    /**
     * Statut HTTP du fournisseur, sans jamais lire le corps : le bridge 0.13.0
     * réduit sinon tout échec à « unknown » (journal de la tâche 1).
     */
    private function providerStatus(\Throwable $exception): ?int
    {
        if ($exception instanceof ServerException) {
            return $exception->getStatusCode();
        }

        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getResponse()->getStatusCode();
        }

        // RuntimeException du bridge en flux : « Unexpected response code 403: "…" ».
        if (1 === preg_match('/^Unexpected response code (\d{3})\b/', $exception->getMessage(), $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
```

- [x] **Étape 6 : lancer les tests pour vérifier qu'ils passent**

Commande : `docker compose exec -T backend php bin/phpunit tests/Ai`
Attendu : tous VERTS, y compris `ScalewayPlatformWiringTest` et les tests de la traduction.

Si `testFragmentsFormTheAnswerAndTheReturnValueCarriesTheUsage` trouve des jetons `null` : lire
comment `Execution::getMetadata()` fusionne ceux du `Result` en mode flux, et corriger le double de
test (pas le service) pour qu'il reproduise ce que fait le vrai Runner.

- [x] **Étape 7 : qualité locale puis commit**

Commande : `docker compose exec -T backend composer phpstan` et
`docker compose exec -T backend composer rector`. Attendu : aucune erreur, aucun diff.

```bash
git add backend/src/Ai/Assistant/Domain backend/src/Ai/Assistant/Application/CareerAssistantInterface.php \
  backend/src/Ai/Assistant/Application/CareerAssistantSystemPrompt.php backend/src/Ai/Assistant/Infrastructure \
  backend/tests/Ai/Support/FakeStreamingAgent.php backend/tests/Ai/Assistant/Support/StubCorpusRenderer.php \
  backend/tests/Ai/Assistant/Domain backend/tests/Ai/Assistant/Application/CareerAssistantSystemPromptTest.php \
  backend/tests/Ai/Assistant/Infrastructure/SymfonyAi backend/config/packages/api_platform.yaml
git commit -m "feat(ai): assistant de parcours en flux, amorcé avant la réponse HTTP"
```

---

## Tâche 3 : l'endpoint et son cloisonnement (M4 hors bornes et quota)

**Fichiers :**
- Créer : `src/Ai/Assistant/Presentation/Dto/AnswerRequest.php`,
  `src/Ai/Assistant/Presentation/Controller/AnswerController.php`,
  `tests/Ai/Assistant/Presentation/Controller/AnswerControllerTest.php`
- Modifier : `backend/config/packages/security.yaml`, `backend/tests/Security/AccessControlAnchoringTest.php`

**Interfaces :**
- Consomme : `CareerAssistantInterface::answer()`, `AnswerUsage`, `AssistantUnavailableException`,
  `Conversation`, `ConversationMessage`, `Role` (tâche 2).
- Produit : `POST /api/assistant/answers`, route `api_assistant_answers`, contrat du flux (en tête).

- [ ] **Étape 1 : la règle d'accès, test d'abord**

Dans `AccessControlAnchoringTest::provideRequestPathsAndExpectedRoles()`, après la ligne
`/api/anonymous-cv/en` :

```php
        yield '/api/assistant → ROLE_TRUSTED' => ['/api/assistant', ['ROLE_TRUSTED']];
        yield '/api/assistant/answers → ROLE_TRUSTED' => ['/api/assistant/answers', ['ROLE_TRUSTED']];
```

et dans le bloc des voisins :

```php
        yield '/api/assistants → aucune règle' => ['/api/assistants', null];
```

Lancer : `docker compose exec -T backend php bin/phpunit tests/Security/AccessControlAnchoringTest.php`
→ ÉCHEC sur les deux premiers cas (aucune règle ne correspond).

Dans `security.yaml`, juste après la règle `^/api/cv(/|$)` :

```yaml
        # Assistant de parcours (spec 0005 D4, ADR 0004 D2 amendée) : il répond
        # à partir du CV, donc du même palier que lui. Placée avant les règles
        # ROLE_USER ; le préfixe ne commence pas par /api/cv.
        - { path: ^/api/assistant(/|$), roles: ROLE_TRUSTED }
```

Relancer : VERT.

- [ ] **Étape 2 : le test fonctionnel qui échoue**

`tests/Ai/Assistant/Presentation/Controller/AnswerControllerTest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Presentation\Controller;

use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\HttpJson;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * POST /api/assistant/answers (spec 0005 M4). Le vrai bridge Scaleway est
 * traversé : seul le transport est simulé, en flux SSE au format compatible
 * OpenAI. Le client concret du bridge Anthropic est aussi remplacé, par un
 * piège : ADR 0004 D3, le corpus ne doit jamais y partir.
 */
final class AnswerControllerTest extends WebTestCase
{
    use HttpJson;

    private const string PATH = '/api/assistant/answers';
    private const string TRUSTED_USERNAME = 'trusted';
    private const string PLAIN_USERNAME = 'plain';
    private const string SUPER_USERNAME = 'super';
    private const string SCALEWAY_INNER = 'ai.scaleway.http_client.scoping.inner';
    private const string ANTHROPIC_INNER = 'ai.http_client.scoping.inner';
    private const string MODEL = 'mistral-small-3.2-24b-instruct-2506';

    /** @var list<array{url: string, body: mixed}> */
    private array $scalewayRequests = [];

    private int $anthropicCalls = 0;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testAnAnonymousRequestIsRefusedBeforeTheFirewallByTheCsrfCheck(): void
    {
        $client = self::createClient();

        $client->request('POST', self::PATH, server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody($this->payload()));

        self::assertResponseStatusCodeSame(403);
    }

    /** Écart n°1 du plan : le 401 du firewall, avec un XSRF signé mais sans BEARER. */
    public function testAValidCsrfTokenWithoutAnAuthenticationCookieIsUnauthorized(): void
    {
        $client = self::createClient();
        $csrfToken = $this->obtainBaseAccess($client);
        $client->getCookieJar()->expire('BEARER');

        $this->post($client, $csrfToken, $this->payload());

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheBaseTierIsForbidden(): void
    {
        $client = self::createClient();
        $csrfToken = $this->obtainBaseAccess($client);

        $this->post($client, $csrfToken, $this->payload());

        self::assertResponseStatusCodeSame(403);
    }

    public function testARealAccountWithoutRoleTrustedIsForbidden(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, TestCredentials::plainPassword());
        $csrfToken = $this->loginAs($client, self::PLAIN_USERNAME, TestCredentials::plainPassword());

        $this->post($client, $csrfToken, $this->payload());

        self::assertResponseStatusCodeSame(403);
    }

    public function testRoleTrustedWithoutTheCsrfHeaderIsForbidden(): void
    {
        [$client] = $this->trustedClient();

        $client->request('POST', self::PATH, server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody($this->payload()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testRoleTrustedReceivesTheAnswerAsAnEventStream(): void
    {
        [$client, $csrfToken] = $this->trustedClient();
        $this->stubProviders($client, new MockResponse($this->scalewayStream('Il a ', 'conçu des API.'), self::sseHeaders()));

        $this->post($client, $csrfToken, $this->payload());

        self::assertResponseStatusCodeSame(200);
        $response = $client->getInternalResponse();
        self::assertStringStartsWith('text/event-stream', (string) $response->getHeader('Content-Type'));
        self::assertSame('no', $response->getHeader('X-Accel-Buffering'));
        self::assertStringContainsString('no-store', (string) $response->getHeader('Cache-Control'));

        self::assertSame([
            ['event' => 'delta', 'data' => ['text' => 'Il a ']],
            ['event' => 'delta', 'data' => ['text' => 'conçu des API.']],
        ], \array_slice($this->events($response->getContent()), 0, 2));
        $done = $this->events($response->getContent())[2] ?? self::fail('Aucun événement done.');
        self::assertSame('done', $done['event']);
        self::assertSame(812, $done['data']['promptTokens']);
        self::assertSame(9, $done['data']['completionTokens']);
        self::assertIsInt($done['data']['durationMs']);
        self::assertCount(3, $this->events($response->getContent()));
    }

    /** Garde D3 (relecture de #260) : le service résolu parle à Scaleway, jamais à Anthropic. */
    public function testTheAnswerComesFromTheScalewayModelAndNeverFromAnthropic(): void
    {
        [$client, $csrfToken] = $this->trustedClient();
        $this->stubProviders($client, new MockResponse($this->scalewayStream('ok'), self::sseHeaders()));

        $this->post($client, $csrfToken, $this->payload());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $this->anthropicCalls);
        self::assertCount(1, $this->scalewayRequests);
        self::assertStringStartsWith('https://api.scaleway.ai/', $this->scalewayRequests[0]['url']);

        $body = $this->scalewayRequests[0]['body'];
        self::assertIsArray($body);
        self::assertSame(self::MODEL, $body['model']);
        self::assertTrue($body['stream']);
        self::assertSame(['include_usage' => true], $body['stream_options']);
        self::assertSame('system', $body['messages'][0]['role']);
        self::assertStringStartsWith('You are the career assistant', $body['messages'][0]['content']);
        self::assertStringContainsString('<documents>', $body['messages'][0]['content']);
        self::assertSame(['user', 'assistant', 'user'], array_column(\array_slice($body['messages'], 1), 'role'));
    }

    public function testRoleSuperInheritsTheAccess(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());
        $this->stubProviders($client, new MockResponse($this->scalewayStream('ok'), self::sseHeaders()));

        $this->post($client, $csrfToken, $this->payload());

        self::assertResponseStatusCodeSame(200);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function providerFailuresBeforeTheFirstFragment(): iterable
    {
        yield 'erreur serveur' => [500];
        yield 'refus (projet ou clé)' => [403];
    }

    #[DataProvider('providerFailuresBeforeTheFirstFragment')]
    public function testAProviderFailureBeforeTheFirstFragmentIsA503Problem(int $status): void
    {
        [$client, $csrfToken] = $this->trustedClient();
        $this->stubProviders($client, new MockResponse('{"status":'.$status.',"message":"refusé"}', ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]));

        $this->post($client, $csrfToken, $this->payload());

        self::assertResponseStatusCodeSame(503);
        $problem = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertStringEndsWith('/errors/assistant-unavailable', (string) ($problem['type'] ?? ''));
    }

    /** Point de relecture n°1 : une coupure après le 200 se voit, elle ne ressemble pas à une fin. */
    public function testAFailureDuringTheStreamEndsWithAnErrorEventAndNoDone(): void
    {
        [$client, $csrfToken] = $this->trustedClient();
        $this->stubProviders($client, new MockResponse([
            self::chunk(['choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Il a '], 'finish_reason' => null]]]),
            new TransportException('Coupure simulée.'),
        ], self::sseHeaders()));

        $this->post($client, $csrfToken, $this->payload());

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            ['event' => 'delta', 'data' => ['text' => 'Il a ']],
            ['event' => 'error', 'data' => ['reason' => 'assistant-unavailable']],
        ], $this->events($client->getInternalResponse()->getContent()));
    }

    /**
     * Point de relecture n°3 : un corps mal formé est un 4xx, jamais un 500.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function malformedPayloads(): iterable
    {
        yield 'locale hors liste' => [['locale' => 'de', 'messages' => [['role' => 'user', 'content' => 'x']]]];
        yield 'locale absente' => [['messages' => [['role' => 'user', 'content' => 'x']]]];
        yield 'messages absent' => [['locale' => 'fr']];
        yield 'messages vide' => [['locale' => 'fr', 'messages' => []]];
        yield 'messages non liste' => [['locale' => 'fr', 'messages' => 'x']];
        yield 'élément non objet' => [['locale' => 'fr', 'messages' => ['x']]];
        yield 'rôle inconnu' => [['locale' => 'fr', 'messages' => [['role' => 'system', 'content' => 'x']]]];
        yield 'contenu numérique' => [['locale' => 'fr', 'messages' => [['role' => 'user', 'content' => 42]]]];
        yield 'contenu tableau' => [['locale' => 'fr', 'messages' => [['role' => 'user', 'content' => ['x']]]]];
        yield 'contenu blanc' => [['locale' => 'fr', 'messages' => [['role' => 'user', 'content' => '   ']]]];
        yield 'clé en trop' => [['locale' => 'fr', 'messages' => [['role' => 'user', 'content' => 'x', 'name' => 'y']]]];
    }

    #[DataProvider('malformedPayloads')]
    public function testAMalformedBodyIsAClientErrorAndNeverReachesTheProvider(mixed $payload): void
    {
        [$client, $csrfToken] = $this->trustedClient();
        $this->stubProviders($client, new MockResponse($this->scalewayStream('ok'), self::sseHeaders()));

        $client->request('POST', self::PATH, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody($payload));

        $status = $client->getResponse()->getStatusCode();
        self::assertGreaterThanOrEqual(400, $status);
        self::assertLessThan(500, $status);
        self::assertSame([], $this->scalewayRequests);
    }

    /** @return array{KernelBrowser, string} */
    private function trustedClient(): array
    {
        $client = self::createClient();
        // Sans cela, le kernel est reconstruit entre la connexion et l'appel :
        // les clients simulés seraient perdus et la requête partirait réellement.
        $client->disableReboot();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::TRUSTED_USERNAME, TestCredentials::plainPassword(), [CpgUser::ROLE_TRUSTED]);

        return [$client, $this->loginAs($client, self::TRUSTED_USERNAME, TestCredentials::plainPassword())];
    }

    private function stubProviders(KernelBrowser $client, MockResponse $scalewayResponse): void
    {
        $client->getContainer()->set(self::SCALEWAY_INNER, new MockHttpClient(
            function (string $method, string $url, array $options) use ($scalewayResponse): MockResponse {
                $body = $options['body'] ?? null;
                $this->scalewayRequests[] = ['url' => $url, 'body' => \is_string($body) ? json_decode($body, true) : null];

                return $scalewayResponse;
            },
        ));
        $client->getContainer()->set(self::ANTHROPIC_INNER, new MockHttpClient(
            function (): MockResponse {
                ++$this->anthropicCalls;

                return new MockResponse('', ['http_code' => 500]);
            },
        ));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['locale' => 'fr', 'messages' => [
            ['role' => 'user', 'content' => 'Quel est son domaine ?'],
            ['role' => 'assistant', 'content' => "L'architecture logicielle."],
            ['role' => 'user', 'content' => 'Depuis combien de temps ?'],
        ]];
    }

    private function post(KernelBrowser $client, string $csrfToken, mixed $payload): void
    {
        $client->request('POST', self::PATH, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody($payload));
    }

    /** Flux Chat Completions compatible OpenAI, tel que Scaleway le diffuse. */
    private function scalewayStream(string ...$fragments): string
    {
        $body = '';
        foreach ($fragments as $index => $fragment) {
            $delta = 0 === $index ? ['role' => 'assistant', 'content' => $fragment] : ['content' => $fragment];
            $body .= self::chunk(['choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => null]]]);
        }
        $body .= self::chunk(['choices' => [['index' => 0, 'delta' => new \stdClass(), 'finish_reason' => 'stop']]]);
        $body .= self::chunk(['choices' => [], 'usage' => ['prompt_tokens' => 812, 'completion_tokens' => 9, 'total_tokens' => 821]]);

        return $body."data: [DONE]\n\n";
    }

    /** @param array<string, mixed> $data */
    private static function chunk(array $data): string
    {
        return 'data: '.json_encode(
            ['id' => 'chatcmpl-test', 'object' => 'chat.completion.chunk', 'created' => 0, 'model' => self::MODEL] + $data,
            \JSON_THROW_ON_ERROR,
        )."\n\n";
    }

    /** @return array{response_headers: array<string, string>} */
    private static function sseHeaders(): array
    {
        return ['response_headers' => ['content-type' => 'text/event-stream']];
    }

    /**
     * Événements SSE du corps, `data` décodé.
     *
     * @return list<array{event: string, data: mixed}>
     */
    private function events(string $body): array
    {
        $events = [];
        foreach (preg_split('/\n\n+/', trim($body)) ?: [] as $block) {
            $event = 'message';
            $data = [];
            foreach (explode("\n", $block) as $line) {
                if (str_starts_with($line, 'event:')) {
                    $event = trim(substr($line, 6));
                } elseif (str_starts_with($line, 'data:')) {
                    $data[] = ltrim(substr($line, 5));
                }
            }
            $events[] = ['event' => $event, 'data' => json_decode(implode("\n", $data), true, flags: \JSON_THROW_ON_ERROR)];
        }

        return $events;
    }

    private function obtainBaseAccess(KernelBrowser $client): string
    {
        self::getContainer()->get('cache.rate_limiter')->clear();
        $client->request('POST', '/api/account/base-access', server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);
        self::assertResponseIsSuccessful();

        return $client->getCookieJar()->get('XSRF-TOKEN')?->getValue() ?? self::fail('Aucun cookie XSRF-TOKEN.');
    }

    private function loginAs(KernelBrowser $client, string $username, string $password): string
    {
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => $username,
            'password' => $password,
        ]));
        self::assertResponseIsSuccessful();

        return $client->getCookieJar()->get('XSRF-TOKEN')?->getValue() ?? self::fail('Aucun cookie XSRF-TOKEN.');
    }
}
```

Avant de lancer : vérifier que `HttpJson::jsonBody()` accepte un `mixed` (sinon lui passer le
tableau) et que `TestCredentials` expose bien `plainPassword()` et `superPassword()`.

Lancer : `docker compose exec -T backend php bin/phpunit tests/Ai/Assistant/Presentation`
→ ÉCHEC : 404 partout où l'on attend 200/503/4xx (route absente). Les tests de refus passent déjà
(le CSRF, puis la règle d'accès de l'étape 1, s'appliquent avant le routage du contrôleur) : c'est
attendu, et ils resteront verts.

- [ ] **Étape 3 : le DTO**

`src/Ai/Assistant/Presentation/Dto/AnswerRequest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Presentation\Dto;

use App\Ai\Assistant\Domain\Exception\InvalidConversationException;
use App\Ai\Assistant\Domain\ValueObject\Conversation;
use App\Ai\Assistant\Domain\ValueObject\ConversationMessage;
use App\Ai\Assistant\Domain\ValueObject\Role;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de POST /api/assistant/answers, validé par le Validator avant tout
 * appel (spec 0005 D4). Les bornes de coût D6 (nombre de messages, longueurs,
 * alternance) arrivent avec la tâche 3 (#262), dans le VO Conversation.
 *
 * `Sequentially` partout où une contrainte suivante supposerait le type : sans
 * lui, NotBlank(normalizer: trim) sur un tableau serait une TypeError, donc un
 * 500 au lieu d'un 422.
 */
final class AnswerRequest
{
    /**
     * @param array<mixed> $messages
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(callback: [Locale::class, 'values'])]
        public string $locale = '',
        #[Assert\Count(min: 1)]
        #[Assert\All([
            new Assert\Sequentially([
                new Assert\Type('array'),
                new Assert\Collection(fields: [
                    'role' => new Assert\Sequentially([new Assert\Type('string'), new Assert\Choice(callback: [Role::class, 'values'])]),
                    'content' => new Assert\Sequentially([new Assert\Type('string'), new Assert\NotBlank(normalizer: 'trim')]),
                ]),
            ]),
        ])]
        public array $messages = [],
    ) {
    }

    public function toConversation(): Conversation
    {
        $messages = [];
        foreach ($this->messages as $message) {
            // Forme garantie par les contraintes ci-dessus ; la vérification la
            // rend lisible par PHPStan et refuse un appel qui aurait sauté la validation.
            if (!\is_array($message) || !\is_string($message['role'] ?? null) || !\is_string($message['content'] ?? null)) {
                throw new InvalidConversationException('Un message de la conversation est mal formé.');
            }

            $messages[] = new ConversationMessage(Role::from($message['role']), $message['content']);
        }

        return new Conversation($messages);
    }
}
```

Vérifier la signature de `Locale::values()` (`src/Portfolio/Shared/Domain/ValueObject/Locale.php:29`).

- [ ] **Étape 4 : le contrôleur**

`src/Ai/Assistant/Presentation/Controller/AnswerController.php` :

```php
<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Presentation\Controller;

use App\Ai\Assistant\Application\CareerAssistantInterface;
use App\Ai\Assistant\Domain\Exception\AssistantUnavailableException;
use App\Ai\Assistant\Domain\ValueObject\AnswerUsage;
use App\Ai\Assistant\Presentation\Dto\AnswerRequest;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\HttpFoundation\EventStreamResponse;
use Symfony\Component\HttpFoundation\ServerEvent;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Assistant de parcours (spec 0005 D4, D9), réservé à ROLE_TRUSTED par
 * l'access_control `^/api/assistant(/|$)`, double-submit CSRF exigé comme sur
 * toute mutation /api. Un contrôleur plutôt qu'une ressource API Platform : la
 * réponse est un flux d'événements, qu'un processeur ne produit pas.
 *
 * Léger par construction : validation (MapRequestPayload), conversion en VO,
 * appel de l'interface, mise en forme des événements. L'assistant a déjà lancé
 * l'appel au fournisseur quand answer() rend la main : un échec avant le
 * premier fragment remonte d'ici en 503. Après le 200, il ne reste qu'à le dire
 * par un événement `error`.
 *
 * EventStreamResponse pose lui-même `X-Accel-Buffering: no` (honoré par nginx
 * et l'ingress-nginx, qui cessent de mettre la réponse en tampon) et un
 * Cache-Control `no-store`.
 */
final readonly class AnswerController
{
    public function __construct(
        private CareerAssistantInterface $assistant,
    ) {
    }

    #[Route('/api/assistant/answers', name: 'api_assistant_answers', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] AnswerRequest $request): EventStreamResponse
    {
        // `from` et non `fromString` : la valeur est bornée par Assert\Choice,
        // elle ne vient pas d'une URL (spec §9).
        $fragments = $this->assistant->answer($request->toConversation(), Locale::from($request->locale));

        return new EventStreamResponse(fn (): \Generator => $this->events($fragments));
    }

    /**
     * @param \Generator<int, string, mixed, AnswerUsage> $fragments
     *
     * @return \Generator<int, ServerEvent, mixed, void>
     */
    private function events(\Generator $fragments): \Generator
    {
        try {
            foreach ($fragments as $fragment) {
                yield new ServerEvent(self::json(['text' => $fragment]), type: 'delta');
            }
        } catch (AssistantUnavailableException) {
            yield new ServerEvent(self::json(['reason' => 'assistant-unavailable']), type: 'error');

            return;
        }

        $usage = $fragments->getReturn();

        yield new ServerEvent(self::json([
            'promptTokens' => $usage->promptTokens,
            'completionTokens' => $usage->completionTokens,
            'durationMs' => $usage->durationMs,
        ]), type: 'done');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data): string
    {
        return json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }
}
```

- [ ] **Étape 5 : lancer les tests pour vérifier qu'ils passent**

Commande : `docker compose exec -T backend php bin/phpunit tests/Ai/Assistant/Presentation`
Attendu : VERT.

Trois écarts possibles, à traiter sur preuve et pas par supposition :
- **Corps vide dans `getInternalResponse()`** : `KernelBrowser` ne capturerait pas le contenu
  diffusé. Lire `HttpKernelBrowser::filterResponse()` ; si besoin, lire le corps par
  `ob_start()` + `$client->getResponse()->sendContent()` + `ob_get_clean()` dans un helper du test.
  Noter l'écart au journal de la spec (§8 le prévoit).
- **503 sans `type: /errors/assistant-unavailable`** (la route n'est pas une opération API
  Platform) : lire `vendor/api-platform/symfony/EventListener/ErrorListener.php` (branche
  `apiOperation === null`) et vérifier que `ApiJsonErrorFormatListener` a bien posé le format `json`.
  Si le `type` reste absent, **ne pas l'imiter dans le contrôleur** : s'arrêter et exposer les faits,
  car la même question se posera pour les 422 et 429 de la tâche 3.
- **Un cas de `malformedPayloads` en 500** : lire l'exception dans
  `var/log/test.log`, puis corriger la contrainte ou le type du DTO. Ne jamais attraper l'erreur
  dans le contrôleur.

- [ ] **Étape 6 : régressions de cloisonnement et route unique**

Commandes :

```bash
docker compose exec -T backend php bin/phpunit tests/Security
docker compose exec -T backend php bin/console debug:router | grep assistant
git diff --stat -- backend/tests/Security/ApiRouteExposureTest.php
```

Attendu : `tests/Security` VERT ; `debug:router` liste **exactement une** ligne,
`api_assistant_answers  POST  /api/assistant/answers` ; aucun diff sur `ApiRouteExposureTest.php`.

- [ ] **Étape 7 : qualité puis commit**

Commande : `make back-quality`. Attendu : PHPStan, Rector, Psalm et `lsp:check` verts (les six
avertissements `config.unknown_key` connus sur `ai.yaml` restent les seuls).

```bash
git add backend/src/Ai/Assistant/Presentation backend/tests/Ai/Assistant/Presentation \
  backend/config/packages/security.yaml backend/tests/Security/AccessControlAnchoringTest.php
git commit -m "feat(ai): POST /api/assistant/answers en flux, réservé à ROLE_TRUSTED"
```

---

## Tâche 4 : les tampons nginx et l'appel réel en flux (D9)

Ici, pas de test automatisé possible : le RED est une expérience sur la stack de dev, avec le vrai
modèle (clé et projet déjà dans `backend/.env.local`, tâche 1).

**Fichiers :**
- Modifier : `docker/nginx/default.conf`, `k8s/base/backend-nginx.conf`
- Créer, hors dépôt : `$SCRATCH/assistant-login.sh`, `$SCRATCH/ask.sh` (dans le dossier scratchpad
  de la session)

- [ ] **Étape 1 : un compte de dev et une session, sans secret dans la conversation**

Le compte de dev `ROLE_TRUSTED` et sa connexion sont faits **par Christophe, dans un terminal
séparé** (règle : jamais de mot de passe en argument ni dans une session d'agent). Le script
`assistant-login.sh` à lui fournir :

```bash
#!/usr/bin/env bash
# Crée (si besoin) puis connecte un compte de dev ROLE_TRUSTED. Le mot de passe
# est demandé sans écho et passe par stdin, jamais en argument.
set -euo pipefail
jar="${1:?chemin du cookie jar}"
read -rp 'Nom du compte de dev : ' username
read -rsp 'Mot de passe : ' password; echo
umask 077
printf '{"username":"%s","password":"%s"}' "$username" "$password" \
  | curl -sS -c "$jar" -H 'Content-Type: application/json' -H 'X-Requested-With: fetch' \
      --data @- http://localhost:8080/api/login_check -o /dev/null -w 'login : %{http_code}\n'
```

S'il faut créer le compte : `make sh` puis `php bin/console app:user:create --role=ROLE_TRUSTED`,
en mode interactif (mot de passe demandé sans écho).

`ask.sh`, lancé par l'agent avec le cookie jar produit (le JWT vaut 1 h) :

```bash
#!/usr/bin/env bash
# Pose une question en flux à l'assistant de dev. Usage : ask.sh <jar> <locale> <question>
set -euo pipefail
jar="$1"; locale="$2"; question="$3"
xsrf=$(awk '$6 == "XSRF-TOKEN" {print $7}' "$jar")
jq -n --arg l "$locale" --arg q "$question" '{locale: $l, messages: [{role: "user", content: $q}]}' \
  | curl -sS -N -b "$jar" -H 'Content-Type: application/json' -H "X-XSRF-TOKEN: $xsrf" \
      --data @- http://localhost:8080/api/assistant/answers
```

- [ ] **Étape 2 : l'expérience rouge, avant de toucher nginx**

S'assurer que le corpus de dev est peuplé : `app:anonymous-cv:seed` et `app:case-studies:seed`
(ils déclinent si la base a déjà du contenu).

Commande : `ts '%.s' < <(bash $SCRATCH/ask.sh $SCRATCH/jar fr "Quelles sont ses compétences principales ?")`
(`ts` vient de `moreutils` ; sinon `while IFS= read -r l; do printf '%s %s\n' "$(date +%s.%N)" "$l"; done`).

Observer les horodatages : les `event: delta` arrivent-ils **étalés** (flux) ou **tous dans la
même milliseconde** en fin de réponse (tampon) ? Noter le résultat.

- Si les fragments arrivent déjà étalés, c'est que `X-Accel-Buffering: no` suffit.
  **S'arrêter et demander** s'il faut quand même ajouter la location (écart n°5).
- S'ils arrivent groupés, poursuivre.

Vérifier aussi que `done` porte des jetons non nuls (sinon : Scaleway ignore `stream_options`, le
noter au journal).

- [ ] **Étape 3 : la location, dans les deux fichiers**

Dans `docker/nginx/default.conf`, après `location ^~ /api/login_check { … }` :

```nginx
    # Assistant de parcours (spec 0005 D9) : réponse en flux, text/event-stream.
    # La location fait son propre fastcgi_pass : avec `try_files … /index.php`,
    # la redirection interne vers `location ~ ^/index\.php` laisserait derrière
    # elle le `fastcgi_buffering off`. La réponse pose aussi
    # `X-Accel-Buffering: no`, qui coupe le tampon de l'ingress. Même filet de
    # débit que `location /`, que celle-ci remplace pour ce chemin.
    location ^~ /api/assistant/ {
        limit_req zone=publicapi burst=200 nodelay;
        limit_req_status 429;

        fastcgi_pass backend:9000;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_buffering off;
    }
```

Dans `k8s/base/backend-nginx.conf`, le même bloc au même endroit, avec `fastcgi_pass 127.0.0.1:9000;`
et le commentaire « Aligné sur docker/nginx/default.conf ». Le `configMapGenerator` hache le
fichier : le sidecar redémarrera au déploiement (issue #18).

Commandes :

```bash
docker compose exec web nginx -t && docker compose exec web nginx -s reload
docker compose exec web nginx -T | grep -A12 'location ^~ /api/assistant/'
```

- [ ] **Étape 4 : l'expérience verte**

Relancer la commande de l'étape 2. Attendu : des `delta` étalés dans le temps, puis un `done`.
Vérifier aussi que les autres routes répondent toujours (`curl -s -o /dev/null -w '%{http_code}'
http://localhost:8080/api/watch` → 200) et qu'une question anonyme sur l'assistant reste refusée
(403).

- [ ] **Étape 5 : commit**

```bash
git add docker/nginx/default.conf k8s/base/backend-nginx.conf
git commit -m "feat(nginx): réponses de l'assistant sans tampon, dans les deux confs miroirs"
```

---

## Tâche 5 : le contrôle d'invention, la documentation, la clôture

- [ ] **Étape 1 : contrôle d'invention avec le vrai modèle**

Avec `ask.sh` et le corpus de dev, poser en **trois passes** chacune de ces questions, dont la
réponse **n'est pas** dans le corpus (en fr et en en) :

1. « Pour quels employeurs a-t-il travaillé ? » / "Which companies has he worked for?"
2. « Quel diplôme a-t-il obtenu, et dans quelle école ? » / "What degree does he hold, and from which school?"
3. « En quelle année a-t-il commencé sa carrière ? » / "What year did he start his career?"
4. « Dans quelle ville a-t-il fait ses études ? » / "Where did he study?"

Exigence : **zéro fait inventé**. Une réponse qui dit « les documents ne le précisent pas » passe.
Un employeur, un diplôme, une date, une ville ou une section inexistante citée échouent.

Ajouter deux questions dont la réponse **est** dans le corpus, pour vérifier que l'assistant cite la
bonne section (règle 4 du préambule).

Si `mistral-small-3.2` invente : **s'arrêter**, comparer les prix de `qwen3-235b-a22b-instruct-2507`
et `llama-3.3-70b-instruct` (console Scaleway), et proposer la bascule à Christophe. C'est un
changement de `model.name` dans `ai.yaml` (et dans `ScalewayPlatformWiringTest` et
`AnswerControllerTest`), à refaire passer par le même contrôle.

- [ ] **Étape 2 : journal de la spec**

Ajouter à `.claude/specs/0005-career-assistant.md` §10 une entrée « **2026-09-26 (tâche 2, #261)** »
avec : le résultat du contrôle d'invention (questions, passes, nombre d'inventions, modèle retenu) ;
le résultat de l'expérience nginx (tampon constaté ou non, avant et après) ; la présence des jetons
en flux ; les écarts 1 à 7 de ce plan ; la lecture du flux par `KernelBrowser`, si elle a demandé un
contournement. Aucun extrait de réponse qui citerait le vrai CV : le corpus de dev est fictif, mais
on vérifie avant de copier.

- [ ] **Étape 3 : CLAUDE.md**

Dans le paragraphe `Ai/` : la tâche 2 livrée ; le service qui compose lui-même son message système
(écart n°3) ; l'agent injecté par id (garde D3) ; le contrat du flux (`delta`/`done`/`error`, JSON)
; le 503 avant le premier fragment. Dans « Deployment invariants » : la location nginx
`/api/assistant/` et la raison de son `fastcgi_pass` propre. Une phrase chacun ; on ne recopie pas le
plan.

- [ ] **Étape 4 : vérification complète**

Invoquer `superpowers:verification-before-completion`, puis :

```bash
make back-test
make back-quality
```

Attendu : les deux verts, sortie lue et citée. Les cases de l'issue #261 sont cochées **une par
une**, contre la preuve correspondante.

- [ ] **Étape 5 : commit, puis la suite du parcours**

```bash
git add .claude/specs/0005-career-assistant.md .claude/CLAUDE.md tasks/todo.md tasks/plan-t2-streamed-answer.md
git commit -m "docs(spec-0005): tâche 2, contrôle d'invention et écarts constatés"
```

`tasks/todo.md` : cocher la tâche 2 **après** la fusion de la PR, comme pour la tâche 1.

Ensuite, étape 07 de `/cpg-dev` : relecture (`/code-review` puis `mattpocock-skills:code-review`).
**Proposer l'agent `agent-skills:security-auditor`** : la PR touche l'authentification (règle
d'accès, CSRF, nouvelle route authentifiée). Puis PR vers `feature/spec-0005-career-assistant`
(`gh pr create --base feature/spec-0005-career-assistant`), qui ferme #261.
