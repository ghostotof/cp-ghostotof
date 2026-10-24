---
paths:
  - "backend/src/Ai/**"
  - "backend/tests/Ai/**"
  - "backend/config/packages/ai.yaml"
  - "backend/config/ai/**"
  - "frontend/src/*/assistant/**"
  - "frontend/src/*/admin/translation/**"
  - "frontend/src/presentation/pages/AssistantPage.vue"
---

# Backend — `Ai` (language models)

> Moved verbatim from `.claude/CLAUDE.md` (#346), section « Architecture › Backend architecture › `Ai/` ». The index of every rule is at the top of `.claude/CLAUDE.md`.

- **`Ai/`** — everything that talks to a language model, and nothing else does (ADR 0004,
  `docs/adr/0004-assistance-ia.md`; spec `.claude/specs/archive/2026-09-14-spec-0002-assistant-traduction/0002-ai-translation-assistant.md`). Sub-context per
  usage: `Ai/Translation/` (phase 1, **delivered 2026-09-14**, v0.10.0 then v0.10.1: the backoffice FR/EN
  translation assistant, `POST /api/backoffice/translations`, `ROLE_SUPER`) and `Ai/Assistant/` (phase 2,
  **D7 amended on 2026-09-15**: a conversational "ask about my career" assistant on the site, reserved to
  `ROLE_TRUSTED`, on Scaleway Generative APIs — the site's own host, `fr-par` — which is the one operator the
  site sends the nominative CV to (D3 amended; the same corpus also leaves through the MCP tool
  `get_nominative_corpus` to the provider a trusted person chose, ADR 0006 D5, `.claude/rules/mcp.md`); corpus injected in the context from the tier's existing providers,
  no tools, no vector store, nothing persisted, streamed response; the MCP server originally planned is
  now an *alternative écartée*; **delivered in v0.19.0 on 2026-10-04** (closed in `develop` by PR #267 — all six tasks and the
  review follow-ups #318–#320/#323 merged —, released by #341; the remaining release checks live in #324),
  spec archived at
  `.claude/specs/archive/2026-10-04-spec-0005-assistant-parcours/0005-career-assistant.md`, task 1 (#260) done: the `career_assistant` agent in `ai.yaml`,
  `mistral-small-3.2-24b-instruct-2506`, `max_tokens` 1024, `tools: false`, prompt preamble in
  `config/ai/prompts/career_assistant.txt`, key `SCALEWAY_AI_API_KEY` routed exactly like `ANTHROPIC_API_KEY`,
  plus `SCALEWAY_AI_PROJECT_ID` on the same route; task 5 (#264) done: both are read by the `backend-secrets` `ExternalSecret` of the two overlays
  (`<env>-backend-scaleway-ai-{api-key,project-id}`), published **before** the `release/*` push — creation recipe
  (dedicated IAM application, Generative APIs inference only, one project) and the preprod checks of the release
  in `k8s/README.md`; task 2 (#261) done: `POST /api/assistant/answers`,
  `ROLE_TRUSTED`, `AnswerController` → `CareerAssistantInterface` → `SymfonyAiCareerAssistant`; task 3 (#262)
  done: D6 bounds in the `Conversation`/`ConversationMessage` VOs → 422 `/errors/invalid-conversation` — strict
  alternation between a first and a last `user` message makes the count odd, so the bound is **11** (D6
  amended from 12, which no valid conversation could reach — keep it odd, and keep the frontend's sliding
  window at the same odd count, or its first message is an answer and every full-window send is a 422), plus
  **16 000 characters for the whole conversation** (audit F2 — the bill is in tokens over everything sent);
  `AnswerRequest` caps `messages` at 50 in a `Sequentially` *before* `All` (validating ~4 000 tiny messages
  cost 113 ms), wide on purpose so a merely too-long conversation keeps the VO's typed 422; quota `career_assistant` (30/h, keyed on the account's **UUID**, never its username — issue #363, read through `App\Shared\Domain\Security\AccountReference`, so `Ai` depends on no other context; a user that is not an `AccountReference` is a wiring defect, `UnauthenticatedAssistantCallException`) consumed by
  `QuotaGuardedCareerAssistant`, an `#[AsDecorator]` of `CareerAssistantInterface` — which is why
  `services.yaml` aliases the interface explicitly: with two implementations the automatic single-impl alias
  disappears and the decorator has nothing to decorate — so a 422 never costs quota, 429
  `/errors/rate-limited` + `Retry-After`, the refusal logged on `ai_usage` with the account's `userId`. **`#[WithMonologChannel]`
  is lost on an `#[AsDecorator]` service** (decoration rewrites its tags, the record silently went to the app
  channel): inject `monolog.logger.<channel>` by id there. The quota takes the shared PostgreSQL advisory lock like
  every limiter (#272, v0.18.2, ADR 0005 D8–D10 — see the ADR 0005 invariant in `.claude/rules/deploiement-etat-hors-pod.md`), so a burst of synchronised
  calls counts one unit each; never consume it inside a Doctrine transaction; body over 128 KiB (the longest valid conversation, 16 000 characters in all, is ~64 kB in 4-byte
  characters; 64 KiB, the spec's first figure, predates that total and refused the 104 kB the per-message
  bounds alone then allowed. 128 KiB also lets through a conversation whose every message respects its own
  bound but not the total — 6 × 1 000 + 5 × 5 000 since #406, 124 372 bytes measured — so it gets the VO's
  typed 422, not a 413: an assistant bound above 5 335, measured, would break that) → 413 `/errors/request-too-large`, judged by
  `AssistantRequestSizeListener` at priority 4, *after* the firewall, so an anonymous or base-tier caller only
  ever learns it is refused); task 6 (#265) done: the `/(fr|en)/assistant` page
  (`ROLE_TRUSTED`/`ROLE_SUPER`), see "API-backed content" in `.claude/rules/frontend.md`; task 4 (#263) done: the **nominative CV opens
  the corpus** (« CV détaillé » / « Detailed CV »), extracted from the very file `GET /api/cv` serves
  (`app.cv_file_path`) by **`pdftotext`** — `poppler-utils` in the Dockerfile's `base` stage (16 MB),
  called through `spatie/pdf-to-text` by `Infrastructure/Pdf/PopplerPdfTextExtractor`, behind
  `Application/Corpus/PdfTextExtractorInterface`. `smalot/pdfparser`, the spec's first choice, was
  dropped (D7 amended): on the real CV it gave no blank line between paragraphs, hundreds of `<>`
  artefacts and detached bullets. Re-extracted on **every** render, never cached (`cache.app` is the
  DB, ADR 0005) — ~13 ms measured; absent file → the section stays and says the CV is not available
  (not an error); **present but unusable** (binary missing, unreadable PDF, > 5 s, no text layer,
  > 30 000 characters — `MAX_CHARACTERS`, the cost bound) → **degraded mode**, decided by the owner:
  same "not available" section, the assistant still answers, and a `warning` carries a stable
  `reason` (+ exit code or length), **never** the text, stdout or stderr (`ProcessFailedException`
  copies both, so it is neither logged nor rethrown). `pdftotext` runs **without the worker's
  environment** (every inherited variable set to `false`): Symfony Process would otherwise hand it
  `DATABASE_URL`, `APP_SECRET` and the API keys. `ExtractedTextNormalizer` drops page numbers at page
  edges, keeps a line repeated **identically** at the edge of every page **once** rather than
  deleting it (a CV carrying the first name only in its header would lose it — and the comparison is
  exact, or two date lines at two page edges would pass for one footer), and joins a line to the next
  only if it fills its column (≥ 75 % of the paragraph's longest line **and** ≥ 40 characters) and
  doesn't end a sentence (pdftotext leaves headings glued to their paragraph). CI's `test-backend` installs `poppler-utils` (bounded
  apt step), so the extraction tests pin structure, not an exact string (Ubuntu's poppler ≠ Alpine's).
  The test env's `CV_FILE_PATH` is `dummy.pdf` (no text): functional tests that need a real CV swap
  the public-in-test `CorpusRenderer` for one built on the fictional fixture
  (`tests/Ai/Assistant/Infrastructure/Pdf/Fixtures/cv-fictif.{html,pdf}`, regeneration command in the
  HTML). **Never print the real CV's text in an agent session** — inspect it by counts only. Task 2 facts
  to keep: **the service composes its own system message** (preamble file + corpus rendered by
  `CorpusRenderer`, D8) because `SystemPromptInputProcessor` skips `ai.yaml`'s prompt as soon as the
  `MessageBag` carries one; **the agent is injected by id** (`ai.agent.career_assistant`), never
  `PlatformInterface` by type, which autowires to Anthropic (D3); the stream is `text/event-stream` with JSON
  `data` — `delta` `{text}`, then `done` `{promptTokens, completionTokens, durationMs}` or `error`
  `{reason}` — and a provider failure **before the first fragment** is a 503 problem+json
  (`/errors/assistant-unavailable`, rendered by the shared `ApiProblemResponseListener`, the route not being an
  API Platform operation), the call being primed before the 200 is sent; `ReplayRefusingHttpClient` stops the
  bridge's `EventSourceHttpClient` from replaying a cut stream (a second, billed generation). Its refusal is a
  `TransportException` whose trace keeps, below it, the vendor frame `AsyncContext::replaceRequest($method,
  $url, $options)` — the Scaleway key and the nominative CV as arguments in dev, where
  `zend.exception_ignore_args` is Off and `#[SensitiveParameter]` cannot reach the vendor. **Left as is, on
  purpose (issue #428, pt 1)**: the trace is rendered nowhere, because that exception never leaves
  `SymfonyAiCareerAssistant` — neither thrown nor as a `previous` (`AssistantUnavailableException` takes none)
  — and only its class is logged. `SymfonyAiCareerAssistantTest::testARefusedReplayNeverLeavesTheServiceNorReachesTheLogs`
  pins it at both stages; a change that let it out would need the trace stripped first. Two rules from
  the task's security review: **`AssistantUnavailableException` never chains the bridge's exception** — the
  kernel's `ErrorListener` logs the whole `previous` chain, and the bridge copies the provider's response
  body into its message (issue #269 tracks the same defect on the translator); and every assistant exception
  a client can cause implements `ProblemExceptionInterface`, since `exception_to_status` has no effect on
  this route — with a **literal** message only, since the listener returns it as `detail`
  (`ProblemDetailStaysStaticTest` parses every `ProblemExceptionInterface` of `src/` by tokens, with the
  traits and parents it gets its methods from, whose `getDetail()` must be `return $this->getMessage();`,
  #507, #514; a
  dynamic message elsewhere needs a justified entry in its allow-list), and a `log_level` in
  `framework.exceptions` — never `#[WithLogLevel]`, which would make the domain depend on HttpKernel —
  or the kernel logs it `critical`. **The corpus's `<documents>` neutralisation is the MCP role's**
  (#667): `Shared/Domain/Text/TagWordNeutraliser`, written once and parameterised by the tag's word, used by
  `CorpusRenderer` (`documents`) and `PromptRole` (`role`, `.claude/rules/mcp-prompt-d8.md`) — the two copies had
  drifted, the corpus left behind #662's hardening (`《/documents》`, `<\u{034F}/documents>`, `<\documents>`,
  `</d\u{200B}ocuments>` passed). NFKC, invisible characters removed, then every opening sign (`\p{Ps}`,
  `\p{Pi}`, `<≮˂˱ᐸᚲ`) stripped across anything that is neither letter nor digit before the word; never a
  copy of it in a context again. **Line breaks are kept** (the Markdown lives on them; flattening is the
  role's own step), so the render stays byte-identical across calls (D8) and **linear by construction**:
  a regex in a loop was quadratic on cascading chevrons, a non-possessive run quadratic on a long run of
  punctuation before a letter, and nothing but a timing test sees that (both are pinned, `CorpusRendererTest`).
  Every line break Unicode mandates (`\r\n`, `\r`, VT, FF, NEL, U+2028, U+2029: `CorpusRenderer::LINE_BREAKS`)
  becomes `\n` first, so that `prose()`'s `#` escaping and `title()`'s flattening see it (#667 review). **A number
  in superscript, subscript or fraction is not folded** (`10⁶`, `m²`, `CO₂`, `½` kept): NFKC read `10⁶` as `106`,
  a measured result quoted wrong — the decomposition type is read through `IntlChar` on each `\p{No}`; letters in
  superscript are still folded. **Accepted** (decided 2026-10-21, completed by the review): NFKC otherwise changes what
  the model reads (`ﬁ` → `fi`, `…` → `...`, `①` → `1`, a no-break space → a space; a full-width `＃` is then escaped
  like `#`); an opening sign before `documents` is lost in plain text too (`[documents](…)`, `« documents »` keeps
  only its `»`), **across line breaks** too, since the corpus keeps them (`{` then `"documents"` on the next line loses
  its `{`); the invisible characters go from the corpus as from the role (`pdftotext`'s private-use bullets, U+F0B7,
  ZWJ and VS16 inside emoji sequences), and `\p{Cn}` follows the bundled PCRE's Unicode version, so a PHP image
  bump may change the rendered prefix once (the prompt cache restarts); and **no nonce on `<documents>`**, unlike the role —
  one drawn per call would break the provider's prompt cache, one derived from the content (an HMAC through
  `KeyedMac`) would bring `APP_SECRET` into the render for content only `ROLE_SUPER` writes —, so the
  look-alikes the neutraliser does not see (other alphabets, small capitals, a combining mark inside the
  word, HTML entities, closing quotes used as openers) pass: the preamble treats the corpus as data
  (rule 6) and ADR 0004 D7 bounds what a successful injection reveals. `ai.scaleway.http_client` also carries `max_duration: 50`,
  `timeout` being an idle timeout only, kept under the 60 s of `fastcgi_read_timeout` and the ingress
  so their HTML 504 never beats the typed 503. The endpoint takes JSON only (`acceptFormat`, 415). **The project id is part of the platform's `baseUrl`**
  (`https://api.scaleway.ai/<project>/v1/...`): without it the API targets the organisation's default project,
  and a key held by an IAM application whose policy is scoped to another project gets a **403** — which the
  0.13.0 bridge reports as `Error "unknown": "Unknown error"`, hiding the status. When that message shows up,
  call the API directly and read the status before suspecting anything else.
  **The Scaleway platform is declared in `services.yaml` (`app.ai.platform.scaleway`, the bridge's
  `Factory::createPlatform`), not in `ai.yaml`** (spec 0005 D2): `symfony/ai-bundle` 0.13.0 hard-codes the
  default `http_client` for a `scaleway` platform and ignores its `http_client` option, so the ADR's dedicated
  client (`ai.scaleway.http_client`, `framework.yaml`: timeout 40 s, `max_redirects: 0`) can only reach it that
  way. Don't move it back into `ai.yaml` until a bundle version honours the option;
  `tests/Ai/Assistant/Infrastructure/ScalewayPlatformWiringTest` pins the wiring (mock behind
  `ai.scaleway.http_client.scoping.inner`, asserts URL, bearer, timeout, `max_redirects`, model, bounds). The
  Scaleway recipe's `ai_scaleway_platform.yaml`/`ai_generic_platform.yaml` (the latter from the transitive
  `symfony/ai-generic-platform`) were deleted — delete them again if a recipe update recreates them. The
  shared test agent is `tests/Ai/Support/FakeAgent`. The bundle is **Symfony AI**, pinned in **exact version** (`symfony/ai-bundle`,
  `symfony/ai-anthropic-platform`, `symfony/ai-scaleway-platform`, `symfony/ai-agent`, all `0.13.0`, no `^` while 0.x); the platform and the
  `translator` agent (`claude-sonnet-5`, `max_tokens` 4096 — the Anthropic wire name, the bridge merges
  options as-is —, `tools: false`, system prompt in `config/ai/prompts/translator.txt`) are declared in
  `config/packages/ai.yaml` on a dedicated scoped client `ai.http_client` (`framework.yaml`: timeout 40 s,
  `max_redirects: 0`). Rules that must hold, in the ADR's words: **only one class imports `Symfony\AI\*`**
  (`Infrastructure/SymfonyAi/…`, behind an application interface) — plus one shared reader,
  `Ai/Shared/Infrastructure/SymfonyAi/ProviderFailure` (D1 amended, issue #308), the only class that reads
  the bridge's exception messages: a provider failure is logged through its `toLogContext()`
  (`exception`, `providerStatus`, `providerErrorType`, `providerFailure` — a `ProviderFailureReason` value,
  provider-independent, readable even in a stream where no error type survives —, `origin`), never with the
  message, by both services; **no model call from a public render
  path**, a visitor-triggered Messenger handler or a render CronJob — backoffice only, synchronous, with a
  timeout; **only backoffice-authored content meant for publication may be sent** to a provider, never
  `cpg_user`, a token, the nominative CV or a contact message; **a suggestion is never persisted** without a
  human action (the endpoint reads and writes nothing, the frontend fills a *new* form); **cost is bounded by
  construction** (per-account quota `translation_assistant`, 30/h keyed on the account's UUID — issue #363, like the assistant's —, `max_tokens`,
  timeout); **no test goes on the wire** (unit tests: a `FakeAgent`; functional tests: the concrete client
  behind the scoped one, `ai.http_client.scoping.inner`, replaced by a `MockHttpClient` answering in the
  Messages API format — **with `$client->disableReboot()`**, otherwise `KernelBrowser` rebuilds the kernel
  between the login and the call and the request really leaves for `api.anthropic.com`; dummy
  `ANTHROPIC_API_KEY` forced in `phpunit.dist.xml`, so such a leak fails 401 → 503 instead of costing money).
  An anonymous `POST` there answers **403, not 401**: the CSRF subscriber runs before the firewall. Token
  usage and duration are logged, the content never is — **on `ai_usage`** since issue #356, like the
  career assistant (as an `info` of the app channel the line never left a production pod), each line
  with an `outcome` (`done`, `error`); the quota refusal (`rate-limited`, `userId`, `retryAfter`) is
  written by `SymfonyTranslationRateLimiter`, the one place that knows both the key and the deadline, so
  `TranslationRateLimitExceededException` is `info` like the assistant's. Functional tests read the
  channel through `tests/Support/ReadsAiUsageLog`. Two side effects, accepted: the translator's `error`
  lines (provider failure, refused answer) moved with it from the app channel to `ai_usage` — still
  emitted in prod, but a filter on the app channel no longer sees them —, and the two `rate-limited`
  lines differ: the translator's carries `retryAfter`, the assistant's does not; the `outcome` values are
  plain string literals in both services. `claude-sonnet-5` rejects `temperature`/`top_p`/`top_k`
  (400): no sampling option anywhere. The Flex recipes come from the official `symfony/recipes` (they apply
  despite `allow-contrib: false`); the `ai_anthropic_platform.yaml` they generate is merged into `ai.yaml`,
  delete it again if a recipe update recreates it.

