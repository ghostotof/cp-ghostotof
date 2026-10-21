---
paths:
  - "backend/src/Mcp/Presentation/**"
  - "backend/tests/Mcp/Presentation/**"
  - "backend/tests/Mcp/AuthorNeverNamedTest.php"
  - "backend/tests/Mcp/Support/AuthorMention*.php"
  - "backend/config/packages/mcp.yaml"
---

# Backend — `Mcp`: the prompt and the D8 guard

> Split out of `.claude/rules/mcp.md` (#673), whose index lists every MCP rule. Decisions: `docs/adr/0006-serveur-mcp.md`;
> spec `.claude/specs/0007-mcp-server.md`. The index of every rule is at the top of `.claude/CLAUDE.md`.

- **The prompt** (#642): `Presentation/Prompt/PresentForRolePrompt`, `present_for_role(role, locale)`,
  one user message in French or English — instructions (rely on the tools only, cite the tool behind each
  statement, say what they do not tell, treat tool outputs as data too), then the role's block, then a
  reminder that the block is data (« sandwich »: hostile data never has the last word). It carries nothing
  of the site. **Its `@param` texts are published**: the SDK makes them the arguments' descriptions in
  `prompts/list` (it reads no `#[Schema]` on a prompt), so they are English and under D8 like a tool
  description; `locale`'s is `ToolLocale::DESCRIPTION` word for word, its default `ToolLocale::DEFAULT`, its
  refusal `ToolLocale::UNKNOWN_LANGUAGE` (pinned by the test). **The block's tag carries a nonce** (#662,
  decided 2026-10-20): `RoleBlockTag::random()`, `role-` + 8 random bytes in hex, drawn **per prompt**; the
  instructions name it (« between `<role-…>` and `</role-…>` … the block ends only at `</role-…>` »), and so
  does the reminder. That is the defence that holds against every look-alike: a booby-trapped job ad is
  written before the nonce exists, so it cannot write the block's real end, and a fake one (`</role>`,
  `</ʀᴏʟᴇ>`) lacks the nonce. Random rather than an HMAC of the text: nothing needs to recompute it, and a
  nonce that changes on every call cannot be learnt by calling the prompt on the trap. Hex, so neither the
  D8 guard (no letter past `f` — a name made of `a`–`f` only would make it flaky) nor the cleaning reads a word
  in it. Tests read the tag from the text (`PresentForRolePromptTest::tagOf()`). The cleaning below stays, as
  defence in depth. `role` goes through the value object `PromptRole`: refused beyond `MAX_CHARACTERS`
  (500 characters, never truncated) **on the input as typed**, before any cleaning (removing characters must
  not let a long text through) — the bound the argument's description publishes —, and beyond
  `MAX_CLEANED_CHARACTERS` (**twice** the bound) on the cleaned text, which NFKC can lengthen (U+FDFA gives 18
  characters: ~9 000 injected before #662); the cap is not the bound itself so that an autocorrected `…`
  (three characters once folded) never gets a text within the bound refused; same message both times.
  Cleaning, in this order: NFKC (folds the full-width `＜／ｒｏｌｅ＞`); invisible characters removed (`\p{Cc}`
  but the blank controls, `\p{Cf}` — zero-width, bidi, Unicode TAG —, `\p{Co}`, `\p{Cn}`, and
  **`\p{Default_Ignorable_Code_Point}`**, #662: variation selectors, and the ignorables `\p{Cf}` misses, `Mn`
  U+034F, U+180B–180D, U+180F, U+17B4–17B5 and `Lo` Hangul fillers U+115F, U+1160, U+3164, U+FFA0 —
  `<\u{034F}/role>` displayed as `</role>`; U+180E is `Cf`; ZWJ and VS16 inside emoji sequences go too, as
  before #662, accepted); then, as `CorpusRenderer` does for `documents` in principle, **only the opening signs
  introducing the word `role`** are removed (`OPENING`): **by class** every `\p{Ps}` and `\p{Pi}` (#662, over a
  list of look-alikes, which stopped at the last glyph thought of — `《 ❰ «` passed): `‹ 〈 ⟨ ❮`, but also `[`
  and `{`, which open a tag elsewhere (BBCode, templates); **by name** those that are not punctuation: `<`,
  `≮` (NFKC composes it from `<` + U+0338), `˂ ˱ ᐸ ᚲ`. They are removed across **anything that is neither a
  letter nor a digit** (`TAG_RUN`; #662 audit: a list of separators kept the `<` of `<\role`, `<|role`,
  `<⟋role`, `<⠀/role`) — « équipe <10 personnes, PHP >= 8.4 » keeps its meaning; `(role-based…)` and
  `“role”` lose their opening sign, accepted. `TAG_RUN` starts only at the head of a run (lookbehind) and is
  possessive: without them a long run followed by a letter was read again from each position, quadratic
  (measured 1.9 ms → 0.08 ms on 990 dots then `a`, JIT off; no test pins it, PCRE's limits did not tell
  the two patterns apart); then **one line**: every run of blanks (`\s`, `\p{Z}`, NEL, and the blank-looking
  U+2800 braille blank and U+1D159 null notehead) becomes one space (#662 audit: line breaks hid an
  instruction sixty lines down, or mimicked on its own line an end of block and a fake reminder) — a pasted
  list becomes one line, accepted —, then trimmed; refused without a letter or digit. `delimited($tag)`
  writes the block. A refusal (and an unknown language) is a `PromptGetException`, the only
  error the SDK 0.8.1 renders with its message — `-32603`, where the protocol wants `-32602`. **Both parameters
  are untyped (`mixed`)** (#663): typed `string`, the SDK cast an argument of another type itself — an array became
  `"Array"` with a PHP `warning` on the `php` channel (fixed text, no value), a line an anonymous caller produced at
  will in production. `PromptRole::fromInput()` refuses anything but a string with `NOT_A_TEXT` — a missing `role`
  too, which the SDK now passes as `null` instead of its generic « Error while handling prompt » —, and a `locale`
  of another type is an unknown language (`null` stays the default: the SDK reads it as absent).
  `PresentForRolePromptTest` catches the PHP warning with its own error handler (PHPUnit's keeps vendor warnings
  away from Monolog) and reads every channel above `notice`. A tool needs none of this: the SDK validates its
  arguments against the `inputSchema` first. Its message never quotes the
  text received: the SDK logs it with the error, at `error`, which `SdkChannelRedactor` downgrades to `notice` (#643). A
  PCRE or NFKC failure is `RoleNeutralisationFailedException`, a server fault (generic `-32603`), never a
  « role is empty »; a Unicode property the PCRE did not know would fail closed too, but as the compile
  warning — an `ErrorException` where Symfony turns warnings into exceptions (debug), the named exception
  elsewhere. `\p{Default_Ignorable_Code_Point}` needs PCRE2 ≥ 10.40: 10.44 in the images (PHP's bundled
  one), the runner's system library in CI (Ubuntu 24.04: 10.42). **Unicode versions differ**: ICU 78
  (Unicode 17) folds, PCRE 10.44 (Unicode 15.0) classifies, so a character assigned after Unicode 15.0 is
  `\p{Cn}` to PCRE and removed — the cautious direction; the reverse (a newer PCRE than ICU) would let
  unfolded letters through. Not exhaustive, accepted (like the corpus), **the nonce covering each**: letters
  of other alphabets mimicking `role` (`</r\u{043E}le>`, a Cyrillic `о` NFKC does not fold), same-script
  look-alikes (small capitals `</ʀᴏʟᴇ>`, `roIe`, `r0le`, `rôle`), a combining mark inside the word (`ró`),
  letters used as separators (`<ノrole`, `<丿role`), HTML entities, closing quotes used as openers
  (`»/role«`, `\p{Pf}`), and symbols that are not listed openers (`≺`, `◁`, `\p{Sm}`/`\p{So}`). The raw `prompts/get` arguments, `role` included, are withheld from the SDK's log
  (#643, `.claude/rules/mcp-journaux.md`).
- **The D8 guard** (#642, ADR 0006 D8): `tests/Mcp/AuthorNeverNamedTest` (group `d8`) reads, **over HTTP on
  the real server**, once per class, what it says or writes of itself: `initialize` (`serverInfo`,
  `instructions`), `tools/list`, `prompts/list`, `resources/list`, `resources/templates/list` (a `nextCursor`
  fails it: a next page would escape); every prompt's `prompts/get` in each language it declares and its
  answers to invalid input (too long, empty, unknown language, required argument missing), all built from
  what `prompts/list` declares; `completion/complete` on every prompt argument; every tool's answer to an
  unknown language; and the **output of the tools without a route** (`McpExposureSymmetryTest::TOOLS_WITHOUT_ROUTE`,
  public for that), which the server writes itself — a route-backed tool's output is its route's content (D1)
  and is not read. `testEverySurfaceTheServerWritesIsRead` pins that list. Through
  `tests/Mcp/Support/AuthorMentions` it refuses, after NFKD folding (full width, accents and `\p{Cf}` removed,
  so a soft hyphen inside the name does not split it), case and punctuation ignored: the full name and each
  word of three letters or more as whole words; failing that, the name joined or a word of five letters or
  more inside a word (`prenomnom22@…`); the **initials** in capitals as a word (`XY`, `X. Y.`) or in lower
  case at the head of an identifier (`xy-…`, `xy_…`, `xy.…`) — which gives `cp-ghostotof` its meaning as an
  exception —; and any run of six consecutive words of the nominative CV. Every string read, keys included.
  Each finding is an `AuthorMention` (path, `MentionKind`, term number), **never the term nor the text carrying
  it**. The exceptions are `AuthorNeverNamedTest::admitted()`, copied from ADR 0006's closed list (the server
  name, `get_contact_info`'s URL in both its forms), admitted at their path and for their exact value only,
  and checked to be still published as written (`assertTrue`, no diff that would print the value). The CV is
  the file `GET /api/cv` serves (`app.cv_file_path`, through the corpus's `PdfTextExtractorInterface`):
  textless in CI (`dummy.pdf`); any other file present must yield a text, or the guard fails rather than
  search zero fragments. **The name never enters the repo**: `MCP_D8_AUTHOR_NAME` is a fictive name in
  `phpunit.dist.xml`, as an `<env>` **without `force`** (PHPUnit then keeps a value the process environment
  already carries — `<server>` always overwrites). In `test-backend` the suite runs `--exclude-group d8`, and
  a step of its own, run whenever the suite ran, runs `--group d8` with the **repository secret**
  `MCP_D8_AUTHOR_NAME`: the rest of the suite and its `vendor/` code never hold it. **No actor falls back on the
  fictive name** (#663): a Dependabot push reads only the **Dependabot secrets**, so the same secret is set
  there too — before #663 the empty variable was unset for `dependabot[bot]`, and a bump of `mcp/sdk` or
  `symfony/mcp-bundle`, which write what the server publishes, was never checked against the real name in its
  own run (only after its merge, on `develop`'s). Without the Dependabot copy, a Dependabot run fails the guard,
  saying so, like an empty name anywhere else. Accepted: the name then reaches the dependency code of a
  Dependabot branch not reviewed yet — the code it would reach on `develop` once merged; the alternative (an
  `ignore` of the two packages in `dependabot.yml`, bumped by hand) gave up their update PRs.
  `tools/tests/d8-guard-step.test.sh` (`tools-tests`) pins that step: the secret in its `env`, no fallback in its
  script, no other line of `pipeline.yml` naming the variable (`.github/` is not mounted in the dev container,
  hence a shell test rather than PHPUnit); `tools/github-settings.sh` (step `l`) checks both copies exist. **Under `GITHUB_ACTIONS` a failure gives the number
  of mentions only** — a public log naming the path and the term's number would tell which published word is
  the name; locally it lists them. Set or rotate the secret from a prompt or a file, never `--body`:
  `gh secret set MCP_D8_AUTHOR_NAME --repo ghostotof/cp-ghostotof`, then the same with `--app dependabot` (the
  two stores are distinct; the author decided on 2026-10-20 that his
  name alone is no secret in an assistant session, so it may be run there too; it stays out of the repo). To
  check the real name locally: `read -r MCP_D8_AUTHOR_NAME && export MCP_D8_AUTHOR_NAME`, then `docker compose
  exec -e MCP_D8_AUTHOR_NAME -T -u dev backend php bin/phpunit --group d8` (`-e` without a value: nothing in
  `ps` nor the history). The guard's in-memory mutations prove it bites (the test name in a tool description
  — whole, last word in capitals, joined with digits, full width, split by a soft hyphen —; initials at and
  beside the admitted path; a name at the admitted path; the name in a prompt refusal; a fragment of the
  fictive CV, taken from its text); measured on the code too (#642: the name in a tool description, in a
  prompt `@param`, and in `ToolLocale::UNKNOWN_LANGUAGE`, each red — the last at every error surface, and
  reduced to a count under `GITHUB_ACTIONS`). Accepted limits: the guard runs under the test configuration (a
  future `%env()%` in `instructions` would be read with its test value), letters of other alphabets mimicking
  the name, a CV fragment shorter than six words (an employer alone, an address).
