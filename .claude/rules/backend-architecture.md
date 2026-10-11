---
paths:
  - "backend/src/**"
  - "backend/tests/**"
  - "backend/migrations/**"
  - "backend/config/**"
---

# Backend — architecture and UUID v7 keys

> Moved verbatim from `.claude/CLAUDE.md` (#346), section « Architecture › Backend architecture (introduction) ». The index of every rule is at the top of `.claude/CLAUDE.md`.

### Backend architecture (`../backend/src`)

DDD structure: each bounded context is a top-level folder under `src/`, itself split into `Domain/`,
`Application/`, `Infrastructure/`, `Presentation/` layers — only the layers a context actually needs, no empty
ceremonial folders. `config/packages/doctrine.yaml`'s mapping scans all of `src/` (not a single `Entity/`
folder), so entities live inside their bounded context instead of a shared top-level directory.

**Every entity has a UUID v7 primary key, assigned in its constructor** (spec 0003, `v0.11.0`):
`#[ORM\Id] #[ORM\Column(type: UuidType::NAME)] private Uuid $id;` with no `GeneratedValue`,
`$this->id = Uuid::v7();` as the first assignment, right after the constructor's guards (three entities
validate an invariant first: `CpgUser`, `WatchedProduct`, `WatchSnapshot`), `getId(): Uuid` non-nullable.
Never a `setId()`, never an id supplied from outside on creation. The column is PostgreSQL's native `uuid`
type (`symfony/doctrine-bridge`'s `UuidType`, `symfony/uid` `8.1.*` a direct dependency), never
`VARCHAR(36)`. Every item operation declares `requirements: ['id' => Requirement::UUID]`
(`Symfony\Component\Routing\Requirement`), so a malformed `{id}` is a 404 from the router before the
firewall or any Provider runs; Providers/Processors read it via `ResolvesUriVariables::uriVariableUuid()`.
`tests/Security/ItemRouteRequirementTest.php` pins this invariant by walking the compiled router and
asserting every `/api` route whose path contains `{id}` declares it, with an explicit, justified allow-list
for API Platform's internal `{id}` paths that aren't entities.
`Requirement::UUID` is case-sensitive (lowercase hex), so an upper-case UUID in a URL is a router 404 too —
`toRfc4122()` always emits lowercase, don't widen the regex. DTOs expose `id` as an RFC 4122 string
(`$entity->getId()->toRfc4122()`), never the `Uuid` object — a read/write DTO's `id` stays
`?string $id = null` (absent on creation), but an output-only DTO's is non-nullable
(`BackofficeUserResource`). **`===`/`!==` between two `Uuid` compares objects, not values — always use
`->equals()`** (`CpgUserAdministrator`, `CpgUserRoleAdministrator`, `ExperienceTechnologyAdministrator` are
the three sites that need it). The migration to UUID is irreversible and monotone: PostgreSQL 18's
`uuidv7(interval)` assigns each existing row a v7 id offset by its rank in the old integer order, so
`ORDER BY id` keeps producing today's order — one migration per task of phase A (five files,
`backend/migrations/Version202609141{2..6}0000.php`), each `down()` throws rather than pretend the
original integers are recoverable. `ApiRouteExposureTest` substitutes `{id}` with a fixed valid UUID (not
`'1'`): with `requirements` in place, an integer placeholder would 404 at the router and the test would
silently stop covering every item route. Two traps hit while migrating: the API Platform metadata pool
survives `cache:clear` after a DTO's `id` type changes — `rm -rf var/cache/<env>` instead; and the usual
bind-mount desync (`docker compose restart backend`) can make a container run a stale Provider/Processor
mid-migration.

**Every entity timestamp is stored with its offset** (issue #434): `#[ORM\Column(type:
Types::DATETIMETZ_IMMUTABLE)]` (`datetimetz` for a mutable object), PostgreSQL `TIMESTAMP(0) WITH TIME
ZONE` — never `datetime` nor `datetime_immutable`, the type Doctrine infers from a bare
`DateTimeImmutable` property. Those store the wall time of PHP's zone (`date.timezone = Europe/Paris`)
with no offset: the hour repeated when the clocks go back is read back one hour late (measured: an
invitation of 2026-10-25 02:30 CEST came back at 02:30 CET, +3600 s), and a value compared with a
column that has a zone is read in the PostgreSQL session's zone, **UTC**. Likewise a query parameter
compared with such a column is typed `Types::DATETIMETZ_IMMUTABLE` (`findAwaitingPasswordSetupInvitedBefore`'s
threshold, `claim()`'s `usedAt`): typed `DATETIME_IMMUTABLE`, Paris wall time would be read as UTC, two
hours off all year. A calendar day without a time (`Incident::$occurredAt`, `date_immutable`) has no
zone and stays a `DATE`. A value read back is in the session's zone (UTC): compare instants
(`getTimestamp()`), never `format('Y-m-d H:i:s')`, and convert before displaying a wall time
(`WatchProvider::utc()`). `tests/Shared/Infrastructure/Doctrine/TimestampColumnsCarryTheirOffsetTest.php`
walks every entity's metadata and fails on any `datetime`, `datetime_immutable`, `time` or
`time_immutable` field. Migration `Version20261011120000` converted the five existing timestamps with
`AT TIME ZONE 'Europe/Paris'`. Messenger's own tables are not entities and stay as the vendor made them:
`messenger_messages.created_at`/`available_at`/`delivered_at` are `TIMESTAMP WITHOUT TIME ZONE` holding
**UTC wall time** (the Doctrine transport writes `new DateTimeImmutable('UTC')`), so a value compared with
them in SQL is converted to UTC first — `PurgeFailedContactMessagesCommand` formatted its threshold in
Paris time and purged one to two hours early (issue #446).
**The database's sessions read wall times in PHP's zone** (`ALTER DATABASE … SET timezone TO
'Europe/Paris'`, same migration, settled in the review of #434): the second net, for a time without
offset that still reaches the database — an untyped query parameter, which the ORM infers as
`datetime_immutable`, or the old code during a rollout and **indefinitely after a rollback**, since
`rollout undo` never replays a migration's `down()` (two hours off: a 50-hour link, an invitation purge two
hours early, irreversible). The trade-off: it hides a typing mistake instead of revealing it — the
mapping guard above is what reveals it. The same test checks `SHOW timezone` equals
`date_default_timezone_get()`: changing `date.timezone` in `php.*.ini` means a migration that moves the
database with it. A value read back therefore carries Paris's offset; still compare instants.
**A duration in hours is added as elapsed hours** (issue #445): `add(new DateInterval('PT48H'))`, never
`modify('+48 hours')`, which counts wall-clock hours in PHP's zone — measured under PHP 8.5, 49 h on the eve
of the autumn change and 47 h on the eve of the spring one (the invitation link announced as "valid 48 hours",
the tech watch's 36-hour freshness threshold). `modify()` stays exact for minutes, and a number of days is
meant as calendar days. `tests/Shared/ElapsedDurationsTest.php` walks the tokens of `src/` and fails on
any string literal adding or subtracting hours (`'+48 hours'` in a constant, `'+%d hours'` for `sprintf()`),
comments excluded.

**Every queued envelope is authenticated before anything unserializes it** (issue #444). Both queues —
`async` on RabbitMQ, `failed` in the Doctrine table — are read back by `PhpSerializer`, that is
`unserialize()` with no class allow-list: writing into either would be an object injection into the
worker (or into an operator running `messenger:failed:show`). `framework.messenger.serializer.default_serializer`
is `Shared/Infrastructure/Messenger/AuthenticatedEnvelopeSerializer`: it adds an HMAC of the body
(header `X-Envelope-Mac`, key derived from `kernel.secret` through `SecretKeyDerivation` with its own context, an
empty secret refused at construction) and, on decoding, refuses an envelope whose MAC is wrong — or not
a string, which AMQP or the Doctrine transport's JSON headers can yield — **before** the inner
`PhpSerializer` sees it — any type, including the decoding-failure envelopes the retry puts back in
the queue. It must never implement `MessageTypeAwareSerializerInterface`: Messenger's `SigningSerializer`,
which decorates it (FrameworkBundle signs `RunCommandMessage` and `RunProcessMessage`), would then read
the envelope's type — a restricted unserialization, but one — before the MAC. **Never rely on `#[AsMessageHandler(sign: true)]` for this**: Messenger's `SigningSerializer`
only requires a signature for the listed types, and an unsigned envelope of any other type (a `stdClass`
carrying a gadget) is still unserialized — measured in the review of #444, a `Process::__unserialize()`
ran that way. A refusal is a decoding failure (`EnvelopeMacRefusedException`, holding the received
envelope, never an object of its body, and quoting its cause, never the body): final, straight to `failed` (see below). **Any decoding failure it returns keeps the
original encoded envelope, MAC included** (issue #465): `DecodeFailedMessageMiddleware` decodes that
envelope again with the same serializer, at reception and after every retry, so a failure of the inner
`PhpSerializer` — which wraps the envelope it was handed, header already removed — is re-wrapped around
the original. Without it, every undecodable signed message had its real cause replaced by « sans MAC
refusée » (`DecodingFailureKeepsItsMacTest`). **Only the failure of the decoding in progress is re-wrapped**,
recognised by its body (the one handed to `PhpSerializer`, or its base64-decoded form): a failure that a
retry carries **as its message** comes back untouched, stamps included — re-wrapped, it lost its
`RedeliveryStamp` (endless retries) and `SentToFailureTransportStamp` (`messenger:failed:*`), and nested
each retry inside the next (caught in the review of #465). **An envelope with no MAC is refused
unconditionally** (issue #450). The release of #444 (v0.23.0) still decoded one, with a `warning`, so
that messages queued by the old code would not be lost; #450 **removed** that transition mode rather
than switching it off — there is no parameter left that could reopen the injection, don't reintroduce
one. In the same change the envelope `KEY_CONTEXT` moved to **`…/v2`**: during the transition, a forged
unsigned envelope was decoded, then re-signed by `encode()` on retry and stored in `failed` with a valid
MAC, gadget included (reproduced in the audit of #465, also through `__PHP_Incomplete_Class`, which
defers the real class to the first decoding after signature) — no inspection of the queues can tell it
from a legitimate message, a new key invalidates every signature of that era. The cost, at each change
of the context, **every** envelope signed under the old key becomes unreadable — `async`, its delay
queues and `failed` alike. Before merging such a release: drain `async`, **read `failed` and replay or
purge it** (`messenger:failed:show` from a Job), and deploy with `DEPLOY_MAINTENANCE_WINDOW=true`
(`.claude/rules/deploiement.md`): otherwise an old API pod (`RollingUpdate`) still signs under the old key
while the worker (`Recreate`) already demands the new one, and a contact message sent then is lost — it
exists only in the queue. Say so in the release notes. A rollback past #444 followed by a redeploy
recreates unsigned messages, refused as such. `rollback-preprod` undoes `worker` together with `backend`
(#450): left on the refused release, the worker would demand a key the restored API does not sign with;
a manual rollback in prod follows the same rule (`k8s/README.md`, « Retour arrière applicatif »).
**A refused MAC is final** (`EnvelopeMacRefusedException`, a `MessageDecodingFailedException` that
implements `UnrecoverableExceptionInterface`, point 4 of #450): straight to `failed`, no retry. A retry
re-encodes the failure under the retrying worker's key, but the original envelope it keeps is still the
refused one, so no retry ever saves it — not even across a change of key (the reason first given to rule
point 4 out, « a retry may reach a pod with the other key », was wrong, caught by the reviews of #450);
the three retries were noise and four decodings per forged message. A failure of the inner
`PhpSerializer` stays an ordinary, retried `MessageDecodingFailedException`: a worker of another
version may read it (`DecodingFailureKeepsItsMacTest` runs the container's retry listener on both).
`AuthenticatedEnvelopeSerializerTest` (an `UnserializationProbe` fixture counts its
`__unserialize()` calls: zero for a tampered, unsigned, wrongly signed or `v1`-signed envelope) and
`QueuedEnvelopesAreAuthenticatedTest` (the real container's chain, an unsigned envelope refused there,
both transports' serializer, a round trip through the Doctrine `failed` transport, the interface above)
pin it. Accepted limits: an
authenticated message can be replayed by whoever writes into the queue (no nonce — an invitation of the
current generation replayed kills the link already received); **rotating `APP_SECRET` invalidates every
queued message** (order in `k8s/README.md`).

**Every value of a database cache pool is authenticated before anything unserializes it** (issue #468,
ADR 0005 D8). `cache_items` is read back by `DefaultMarshaller`, `unserialize()` with every class allowed:
whoever writes into the table (Adminer, an SQL injection, the database credentials) had an object
injection into the API (rate limiters) and the worker (Messenger's restart signal). The envelope MAC above
never covered it. `Shared/Infrastructure/Cache/AuthenticatedCacheMarshaller` decorates
`cache.default_marshaller` (`#[AsDecorator]`): a binary HMAC-SHA256 (32 bytes, key from `SecretKeyDerivation`,
context `cp-ghostotof/cache-value/v1`) prefixes each value, and a wrong or missing MAC throws
`CacheValueMacRefusedException`, which the adapter turns into a cache miss logged `warning`. That decoration
covers exactly the Doctrine DBAL pools (`cache.app`, the Doctrine result cache, the restart signal, the HTTP
client pool); `cache.system` (PhpFilesAdapter) does not use that marshaller. Measured: ≈ 2 µs per round
trip. **Each deployment that changes the key** (the first one, an `APP_SECRET` rotation, a new context)
drops the whole database cache once: rate-limiter counters start over. Say so in the release notes.
Accepted limit: the MAC does not cover the row's key, so a signed value can be copied to another key or
replayed, rolling a limiter counter back (a quota denial, never code execution). `SodiumMarshaller` was
ruled out: a sealed box authenticates nothing once its public key is known. `AuthenticatedCacheMarshallerTest`
and `CacheItemsAreAuthenticatedTest` (a real tampered row, every database pool's marshaller) pin it.

**`APP_SECRET` never keys an HMAC directly: each use derives its own key** (issue #455).
`Shared/Infrastructure/Security/SecretKeyDerivation::derive($secret, $context)` is HKDF-SHA256 (RFC 5869,
32 bytes, an empty secret refused with `EmptyApplicationSecretException`), and each class receiving
`kernel.secret` declares a versioned `KEY_CONTEXT` (`cp-ghostotof/<use>/v<n>`): the CSRF cookie signer, the
login-identifier fingerprint, the Messenger envelope MAC (`v2` since #450, see above), the database cache value MAC (#468). Keyed by the raw secret and told apart only in
the message (a prefix, or nothing), any future feature returning `HMAC(APP_SECRET, x)` for a client-chosen
`x` would have been a signing oracle for every other use. Changing a context is a targeted rotation. The
switch itself changed every login fingerprint and invalidated the `XSRF-TOKEN` cookies already issued, and
so does a **rolling update** (two prod replicas, old and new keys for a moment) or a **rollback**: writes,
`POST /api/logout` included, answer 403 until a new login. Since a refused logout used to leave the UI
"logged in" — and `/login` redirects a logged-in user home —, the frontend resets the local session to
anonymous on **any** logout failure and leads to the login page (`useAuth.logout()`, `AppHeader`); a new
login replaces both cookies, a reload reads the real tier from `/api/me`. HKDF stretches nothing: `APP_SECRET`
must be high-entropy (32 random bytes; CI uses `openssl rand -hex 16`). An empty secret raises
`EmptyApplicationSecretException` at first use — the CSRF signer used to accept it, which let anyone forge a
token. `tests/Shared/Infrastructure/Security/ApplicationSecretUsesTest.php` finds, by tokens, every file of
`src/` naming `%kernel.secret%`, `kernel.secret`, `APP_SECRET` or `%env(APP_SECRET)%` (either quotes),
requires an actual `SecretKeyDerivation::derive(…, self::KEY_CONTEXT)` call and a `KEY_CONTEXT` no other file
uses, and refuses any `config/**.yaml` naming the secret besides `framework.secret`. Not covered, accepted: a
class handing the raw secret to another, and Symfony's own uses (`login_throttling`, `SigningSerializer`),
which keep the raw secret but never return their output to a client. Unit tests take key material from
`tests/Support/TestKeys` (generated, like `TestCredentials`): a literal assigned to a name saying "secret"
gets flagged by GitGuardian.

**`src/` never instantiates a bare `\Exception`, `\LogicException`, `\RuntimeException` or
`\InvalidArgumentException`** (issues #338, #383).
Every failure gets its own class: a client error implements `ProblemExceptionInterface` (see
`.claude/rules/backoffice-api.md`, "Exceptions"), and a server fault (wiring defect, broken invariant, build
step) extends the generic class it replaces, without a mapping, so it stays a `critical` 500. The point is a
name that can be targeted in `framework.exceptions` and recognised in the logs. Create it next to the layer
that throws it, with a named factory (`forOperation()`, `forGroup()`, `forDirectory()`…), and never put a
client-supplied value in its message without bounding it (`InputContradictsValidationException::nonTextualField()`).
`tests/Shared/NoBareGenericExceptionTest.php` enforces it. It counts tokens through
`tests/Support/BareExceptionInstantiations`, which shares its file walk with `DeclaredClasses` via `PhpSources`, so
an import, an alias, a comma list or an anonymous subclass is seen too. It has **no exemption list**, and
that is deliberate: a site that seems to need one gets a class, shared if several sites throw the same
failure. **A console question's validator follows the same rule** (#383): the `QuestionHelper` catches
any `\Exception` a validator throws, shows its message and asks again, so the domain exception is the
right one when the rule has one (`InvalidUsernameException`, `InvalidExperienceYearsException`), and
`Shared/Presentation/Command/InvalidConsoleAnswerException` covers the rest. Its message never quotes
the answer, which can be anything (a password pasted into the wrong prompt): `InvalidUsernameException::invalidFormat()`,
not `forUsername()`. Three traps of the same commands. In non-interactive mode, `ask()` returns the default
(`null`) **without calling the validator**, so the command refuses `null` explicitly and names the option,
instead of relying on an `assert()` that production compiles out. On end of input after a refused answer,
`ask()` **rethrows the validator's last exception**, so `ask()` sits inside the `try` that turns it into
`$io->error()` — otherwise it leaves the command and the console's `ErrorListener` logs it `critical`. And a
`CommandTester` is **interactive by default**, so a `-n` test passes `['interactive' => false]`. A hidden
question that reads a password is `setTrimmable(false)` (a question is trimmed by default, `--password-stdin`
and `json_login` are not), with a normalizer that strips the one line ending the read keeps when untrimmed. **A password is never an
option value** (#386): `app:user:create` reads it from standard input (`--password-stdin`, which requires
`--username` — a prompt would read the password instead), never from `--password`, whose argv shows in `ps`,
the shell history and the `command` context the console's `ErrorListener` logs. An operator still typing
`--password` out of habit gets an `InvalidOptionException`, which that listener logs `critical` with the argv:
`Security/User/Infrastructure/Log/PasswordOptionRedactor` (Monolog processor, channel `console`) masks the
value in `command` and in the message, `=`, space and `escapeshellarg()` forms alike. The other SPL classes (`\DomainException`…) are out of the guard's scope:
none is thrown bare in `src/`, and adding one to `BareExceptionInstantiations::FORBIDDEN` is the step to
take the day one is.

**A parameter that receives a secret in clear carries `#[SensitiveParameter]`** (issues #411 for passwords,
#414 for the rest). `tests/Security/SecretParametersTest.php` fails on a parameter that may hold a string
(`string`, `mixed`, a union with one) whose name says token, JWT, secret, key, bearer, CSRF, credentials,
authorization, DSN, cookie, signature or nonce (`SECRET_NAME`, any case; never `key` alone) without it —
or whose parameter at the same position in an `App\…` interface or parent class says so: PHP only reads
the attribute of the implementation called, so the one on an interface is declarative, required for
consistency, and an implementation renaming `$clearToken` to `$value` is still caught. A digest is not a
secret: `hashed` (not `unhashed`) qualifying the next word (`$hashedPassword`, `$newHashedToken`,
`$HASHED_TOKEN`) or a trailing `hash` (not `toHash`) excludes the name (`$tokenHash`); a trailing `hashed`
does not (`$secretToBeHashed` is clear — name a digest `…Hash`), issue #428. The vocabulary also covers
`basicAuth` and a service URL that carries its credentials (`databaseUrl`, `amqpUrl`, `redisUrl`…). A
parent's **private** method is no prototype (it is not inherited); a parent's constructor still is, on
purpose: a child receiving the secret under another name for `parent::__construct()` would otherwise be
missed, and a false negative is invisible while a false positive gets renamed (review of #428).
`DeclaredClasses::classLikes()` is read once per directory and process (≈ 60 ms per walk of `src/`). Scope: methods of the classes, interfaces, traits and enums of `src/`
(`DeclaredClasses::classLikes()`, issue #421: an enum, an interface nothing implements or a trait nothing
uses was reached by no class) and of the classes' `App\…` interfaces and parents; a used trait is read
in each class using it, where its prototype lives, and reported under that class, never a second time
under its own name (`DeclaredClasses::withoutUsedTraits()`, the one place that rule is written — go
through it in any new guard on `classLikes()`). **`DeclaredClasses` has
two censuses, and each guard picks one explicitly**: `classes()` (abstract included) for a guard that
reasons on what is thrown or instantiated (`implementing()` builds on it — `is_subclass_of()` is true of an
interface extending the type), `classLikes()` for one that reads signatures, attributes or a file's source
(the secret and password guards, `RegexEndAnchorTest` — the Validator merges an interface's constraints
into its implementations). Never widen `classes()` silently. **Closures and arrow functions stay out,
an accepted limit** (#421, reasons in `SecretParametersTest`'s docblock): reflection cannot reach them
without running the code, and a token census by name would miss the very case that would justify it — a
callback gets its value under its contract's generic name (the `$value` that `app:user:create`'s password
validator and normalizer used to receive). **A callback that receives a secret is therefore a method,
never a closure** (issue #433): `CreateCpgUserCommand::validatePassword()` and `withoutLineEnding()`,
passed as `$this->validatePassword(...)`, name their parameter `$password`, so the password guard
requires their `#[SensitiveParameter]` like any other; `CreateCpgUserCommandTest` also reads the attribute
on the callbacks of the hidden questions as built, closure or method, and the username prompt's
`validateUsername($username)` is in `HIDDEN_BY_HAND` (a password pasted into the wrong prompt).
**Anonymous classes and free functions are forbidden in `src/`** (issue #432),
so that the census misses nothing else: name the class (`RedactingDriver`, extracted from
`DriverErrorRedactionMiddleware::wrap()` for that reason), make the function a method.
`tests/Shared/NoAnonymousClassNorFreeFunctionTest.php` (`tests/Support/AnonymousClassesAndFreeFunctions`,
by tokens through `PhpSources`) fails on any `new class` — `readonly` or under an attribute too — and on
any named `function` whose enclosing brace is not a class-like body, a function declared inside a method
or a closure included (PHP declares it global when it runs); an imported function (`use function`, grouped
import included) is not one. The same walk tells `MisqualifiedNativeCalls` (below) which functions a file
declares: one definition of "outside any class". No exemption list. A secret under
another name or in an `array` is hidden by hand and pinned in `HIDDEN_BY_HAND` (`AuthCookieFactory::create($value)`,
`ReplayRefusingHttpClient::request($options)` — the Scaleway key and the nominative CV —,
`PasswordOptionRedactor::redact($text)`); an entry the census already sees is refused there.
`CsrfCookieTokenSigner::signature($random)` stays visible on purpose (half the token, useless without
`APP_SECRET`). No exclusion list until a first false positive needs one. Why it matters although
production and, since #420, PHPUnit strip arguments (`zend.exception_ignore_args`, see
`.claude/rules/backend-outillage.md`): dev does not (the debug JSON error body, Messenger's
`ErrorDetailsStamp`, output pasted in a ticket or an assistant session), and `debug_backtrace()` never
does. The attribute only hides that frame's arguments: not a message, a local variable, nor a vendor call that
receives the value next (`Cookie::create()`, Lexik). The walk and the hash exclusion are shared with the
password guard (`tests/Support/SensitiveParameters`), so a change there moves both.

**A regex of `src/` ends on `\z`, never on `$`** (issues #386, #409). In PCRE `$` also matches before a
final `\n`, so `/^[a-z]+$/` accepts `"abc\n"`: a username, a watched-product slug and a translation field
name all went through that way. `\z` rather than the `D` modifier: the anchor reads where it acts, and
survives a copy of the pattern's body without its modifiers. `tests/Shared/RegexEndAnchorTest.php`
(`tests/Support/DollarAnchoredPatterns`) fails on any string literal of `src/` shaped like a regex that
holds an anchoring `$` (escaped, in a character class or a `Q…E` quote it is literal) without `D` or
`m` — constants, `#[Assert\Regex]`, inline `preg_*` calls alike. Not seen: a pattern built by
concatenation, a heredoc. **An `#[Assert\Regex]` anchored by `\z` declares its `htmlPattern`**: API
Platform publishes the OpenAPI `pattern` from `getHtmlPattern()`, which only strips a final `$`, and
ECMAScript reads a leftover `\z` as a literal `z` (`BackofficeWatchedProductResource::$slug`); the same
test checks every `Regex` constraint of `src/`, nested ones included. `security.yaml`'s path patterns
are out of scope and keep their `$`: the router refuses a path ending in `%0A` (404) before any
firewall or `access_control` rule is consulted, and on `access_control` the wider `$` is the
fail-closed side.

**Every class, interface, enum and trait is imported with `use` and written by its short name — native
ones included** (issue #391). `use LogicException;` then `new LogicException(…)`, `use Throwable;` then
`catch (Throwable $e)`, `use DateTimeImmutable;` then `DateTimeImmutable $at`, likewise `Stringable`,
`JsonSerializable`, `Closure`, `Generator`, `ReflectionClass`…: never `\LogicException`, never
`\App\…\Foo` inline, in code, attributes and phpdoc types alike (settled on 2026-10-10) — a `{@see}`
in a comment is not a type, see below.
**A comment never adds a `use`** (settled on 2026-10-10, review of #391). A class the file imports for
its code is cited by its short name — `{@see Foo}` in a docblock, `Foo` in a `//` comment. Any other class
is cited by **`{@see}` with its full name, without `use`**: `{@see \ValueError}`,
`{@see \App\Shared\Infrastructure\Http\RetryAfterListener}`, `{@see \DateInterval::createFromDateString()}`
— the leading `\` makes it unambiguous for the IDE, which keeps it clickable and renames it. Rector leaves
such a `{@see}` alone (verified on 2026-10-10). In a `//` comment, where `{@see}` means nothing, the full
name stands alone (`Monolog\Logger` in `ReadsSecurityAuditLog`). Two reasons. An import only a docblock needs
is checked by nothing — PHP does not resolve an unused `use`, PHPStan does not read `{@see}`, Rector keeps
it — so a deleted or renamed class leaves it dangling with a green CI; and it would make an inner layer
import an outer one (`Domain` → `Application`/`Infrastructure`/`Presentation`, `Application` →
`Infrastructure`/`Presentation`) for documentation's sake. The full name is checked instead:
`tests/Shared/CommentedClassNamesExistTest.php` (`tests/Support/CommentedClassNames`) fails on any full
name in a comment of `src/` or `tests/` that is neither a class, interface, enum or trait (nor its cited
`::method()`) nor a namespace — a name is full when it starts with `\` or with a root namespace the
autoloader knows. Not checked, and allowed: a name relative to its context, as the repository interfaces
write it (`Infrastructure\Doctrine\CpgUserRepository`), and the `Assert\…` shorthand. A comment that names
a class which does not exist (a removed vendor class, a hypothetical homonym) says so in words rather than
by a full name. Background, verified on 2026-10-10: Rector's `removeUnusedImports` keeps an import
referenced by `{@see}` in a `/** … */` docblock, but deletes one cited in plain prose or in a `//` /
`/* … */` comment, even through `{@see}`. Code quoted in a comment
(`` `new DateTimeImmutable('-'.$x)` ``, `` `#[SensitiveParameter]` ``) takes the short name without `{@see}`:
it shows code, it does not link a class. A quoted configuration key keeps its fully qualified name, the only
one the config knows (`Symfony\Component\Serializer\Exception\ExceptionInterface: 400`, `MalformedRequestBodyTest`). A
name clash gets an alias (`use UnexpectedValueException as NativeUnexpectedValueException;` in
`MalformedRequestBodyException`, which already imports the Serializer's), never a qualified name.
**`use` statements are sorted alphabetically**, case-insensitive, `\` as a segment separator — the order
of PhpStorm's "Optimize imports" and php-cs-fixer's `ordered_imports` (`alpha`). `rector:fix` inserts a
new import at the top of the block, so re-sort after it: `tests/Shared/ImportsStaySortedTest.php`
(`tests/Support/UnsortedImports`, issue #394) fails on any top-level import of `src/` or `tests/` out of
that order, naming the file, the line and the pair, and its failure message carries the one-off sort
command. It reproduces php-cs-fixer's key (the import as written, alias included, `\` read as a space,
`strcasecmp`), block by block, and **refuses** `use function`, `use const` and grouped imports
(`use Foo\{A, B};`) instead of sorting them, and its message says those are rewritten by hand.
php-cs-fixer is deliberately not a dependency (#391): the command, built by `tests/Support/PhpCsFixer`,
puts its phar under `var/` at a pinned version (`PhpCsFixer::VERSION`) and runs nothing unless the phar
matches `PhpCsFixer::SHA256` — the release publishes no checksum, only a GPG signature, verified once
when the sum was pinned. That phar is how #391 sorted the existing code. Out of scope, **by decision** (2026-10-10): native **functions and constants** are
never imported — no `use function` / `use const`, `importShortClasses` does not touch them; namespaces
cited in prose (`App\Shared`). **A native function is qualified exactly when the compiler optimizes it**
(settled on 2026-10-10, review of #391): a function of php-cs-fixer's `@compiler_optimized` set (`\count`,
`\in_array`, `\is_string`, `\strlen`, `\sprintf`, `\dirname`…) is written with its leading backslash, every
other one without (`array_map`, `trim`, `json_decode`: no dedicated opcode, nothing to gain). Measured for
`sprintf`: `\sprintf('x %s', $a)` compiles to a bare `FAST_CONCAT`, the unqualified call to a runtime
lookup and a function call. A first-class callable (`is_string(...)`) is not a call and stays as written.
#391 applied the split once (73 `sprintf` in `src/`, 147 in `tests/`, a dozen others) with the same phar,
rule `native_function_invocation` (`include: ['@compiler_optimized']`, `strict: false`).
`tests/Shared/NativeCallsQualificationTest.php` (`tests/Support/MisqualifiedNativeCalls`, issue #394)
keeps it: an unqualified call to a function of the set fails, and so does a qualified call to any other
single-segment global function, with the file, the line and the expected form. Its message gives the fix,
the same rule with `strict: true`, which also removes a superfluous `\`. Methods, declarations, `new`,
attribute classes and first-class callables are not calls, and, as in php-cs-fixer, an unqualified call
is left alone when the file declares a function of that name outside any class (a namespaced double of
the native, which `\count()` would bypass). The set is **copied** into
`MisqualifiedNativeCalls::COMPILER_OPTIMIZED` from php-cs-fixer at `PhpCsFixer::VERSION`, and a drift
test requires each name to be an internal function of the running PHP unless it is listed in
`ABSENT_FROM_PHP` (`is_real`, removed in PHP 8.0). It cannot see the other direction — a function PHP
starts compiling, as `sprintf` with PHP 8.4 —: on a php-cs-fixer bump (a new `VERSION` and `SHA256`),
copy the new list over and re-read both guards against the fixers they mirror.
Constants were not migrated: a leading backslash already there stays.
**The line is performance, and it was measured** (2026-10-10, PHP 8.5, OPcache dump after the optimizer): the
rule only covers natives whose import costs nothing. `\DateTimeImmutable` and an imported `DateTimeImmutable`
compile to **identical opcodes** — `use` is resolved at compile time — whereas an unqualified `count($a)`
loses the dedicated `COUNT` opcode for `INIT_NS_FCALL_BY_NAME` + `DO_FCALL_BY_NAME`, a runtime lookup that
falls back to the global function (constants share that fallback). A class-like native that ever proved
costly to import would keep its qualified name, with the measurement as its justification. Also out of scope:
`config/bundles.php` and `config/reference.php` (Flex-generated); class names held in strings as test data;
`migrations/` (frozen, outside Rector's paths); the `Assert\…` constraint shorthand, which mirrors the
`use …\Constraints as Assert;` alias the code itself writes. The guard is Rector's
`withImportNames(importShortClasses: true, removeUnusedImports: true)` in `backend/rector.php`, checked by
the blocking `rector-backend` job: it rejects a qualified class, native or namespaced, in code and phpdoc
types (issue #391, ≈ 200 files migrated). It does **not** see a `@template T of \Foo` bound, reviewed by
hand, nor any prose, where `CommentedClassNamesExistTest` checks the full names (see above), nor import
order or function qualification, which the two #394 guards above check.

