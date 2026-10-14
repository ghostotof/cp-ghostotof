---
paths:
  - "backend/src/**"
  - "backend/tests/**"
  - "backend/migrations/**"
  - "backend/config/**"
---

# Backend — code conventions: exceptions, secrets in parameters, regexes, imports

> Split out of `.claude/rules/backend-architecture.md` (#605), whose index lists every backend-wide rule. The index of every rule is at the top of `.claude/CLAUDE.md`.

**`src/` never instantiates a bare `\Exception`, `\LogicException`, `\RuntimeException` or
`\InvalidArgumentException`** (issues #338, #383).
Every failure gets its own class: a client error implements `ProblemExceptionInterface` (see
`.claude/rules/backoffice-api.md`, "Exceptions"), and a server fault (wiring defect, broken invariant, build
step) extends the generic class it replaces, without a mapping, so it stays a `critical` 500. The point is a
name that can be targeted in `framework.exceptions` and recognised in the logs. Create it next to the layer
that throws it, with a named factory (`forOperation()`, `forGroup()`, `forDirectory()`…), and never put a
client-supplied value in its message without bounding it (`InputContradictsValidationException::nonTextualField()`).
`tests/Shared/NoBareGenericExceptionTest.php` enforces it. It counts tokens through
`tests/Support/BareExceptionInstantiations`, which shares its file walk with `DeclaredClasses` via `PhpSources`, and
its name resolution with `ProblemDetailStaysStaticTest` via `tests/Support/ClassNameResolver` (#507), so
an import, an alias, a comma list or an anonymous subclass is seen too. It has **no exemption list**, and
that is deliberate: a site that seems to need one gets a class, shared if several sites throw the same
failure. (`ProblemDetailStaysStaticTest`, its sibling for the client's `detail`, does have one,
`DYNAMIC_DETAIL_ALLOWED`. It judges the methods PHP retains (#514): the class's, then its traits' — `insteadof`
and `as` aliases read —, then its parent's, a parent that is no `ProblemExceptionInterface` included; a
`new static` written in a parent and a `new parent` count too, and a message follows the constructor chain:
a parameter passed through as is leaves it to the `new` sites, a literal fixes it, a composition anywhere
on the way from the constructor called is refused. A class constant (`self::`, `parent::`, `static::`, `Foo::`,
inherited or from a trait, a private one only where PHP lets it be read) counts as a literal when its value
is a string literal (#523); a composed one, one inherited through `implements`, an enum case or a bare global
constant stays refused. It stays a token reading so that each case is proven on
a string; `testTheTokenModelResolvesMethodsAsPhpDoes` checks by reflection that on `src/` it retains the
same `getDetail()` and constructor as PHP.) **A console question's validator follows the same rule** (#383): the `QuestionHelper` catches
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

**A message queue that does not take a message is a named 503, worded per context** (issue #485).
`Shared/Domain/Exception/MessageQueueUnavailableException` is a **Template Method**: the abstract class holds
what every publishing context shares — status 503 and `type` `/errors/message-queue-unavailable` (through
`HasProblemType`, `final`) —, and each context adds a `final` subclass in its own `Domain/Exception` whose
constructor takes the cause and passes its own **literal** sentence (`ContactMessageQueueUnavailableException`,
`InvitationQueueUnavailableException`). Literal, not composed by a shared factory: the message is the client's
`detail`, and `ProblemDetailStaysStaticTest` refuses any composed one — a factory building it from a
`subject()` hook was written, then dropped for that reason, rather than allow-listed. A context's `Domain`
depends on `Shared/Domain` only, and `Shared` never lists the contexts (the rejected alternative, a single
class with an enum of contexts, would change `Shared` for every new producer). **The conversion is written
once, in the bus** (issue #491, not #485's `try`/`catch` copied at each dispatch site, which nothing forces a new
producer to write): every message routed to a publishing transport implements
`Shared/Application/Message/QueuedMessageInterface`, whose `queueUnavailableException(Throwable $cause)` returns
`new <Context>QueueUnavailableException($cause)` — a **Factory Method**: the middleware, written once in
`Shared`, cannot know which exception to build, each message builds its own. An interface rather than an
attribute (#491): typed end to end, where an attribute would need `new $class($failure)`, which PHPStan checks against
`RuntimeException`'s constructor and refuses. `Shared/Infrastructure/Messenger/QueueUnavailableMiddleware`,
declared on `messenger.bus.default` (Symfony places it before `send_message`, which it wraps), catches
Messenger's `TransportException` — which does **not** implement `TransportExceptionInterface`, verified —
and throws what the message names, keeping the cause as `previous` (an AMQP channel error quotes only the
code and the transport, never the body, unlike an SMTP answer); the message is the client's `detail` and
never quotes the failure. The producers (`ContactMessageSender`, `CpgUserInviter`) just dispatch;
`CpgUserInviter::invite()` still catches `InvitationQueueUnavailableException` to remove the account.
**It leaves the worker's messages alone**: a received envelope (`ReceivedStamp`) is left as is, and the
retries and the `failure_transport` call their sender without going back through the bus; only a **new**
publication made from the worker (a handler dispatching one, none today) would be converted, as from a
request. A message that does not implement the interface keeps the bare `TransportException`. Accepted
limit: a message routed to several senders (none is) is converted when the second refuses, even if the
first took it.
`tests/Shared/Infrastructure/Messenger/QueuedMessagesNameTheirQueueFailureTest.php` covers the three ways
Messenger picks a message's transports (`SendersLocator`), in **every** allowed environment
(`EnvironmentConfigKernel`, like `SendEmailMessageStaysSynchronousTest`; the list comes from
`EnvironmentConfigKernel::allowedEnvironments()`, the one reflective read of `Kernel::getAllowedEnvs()`),
since a transport publishes or not depending on its DSN there. It fails on any key of the declared
`framework.messenger.routing` sent to a transport whose DSN is not `sync://` (`async`, `failed`, an unknown
sender name) that is not a subtype of the interface — a wildcard (namespace or `*`) included, since the
messages it reaches cannot be listed —; on any declaration of `src/` (class, interface or abstract class:
`SendersLocator` reads the attribute on the parents and interfaces too) carrying `#[AsMessage(transport: …)]`
towards such a transport under the same rule; and on any **`TransportNamesStamp`** named in the code of
`src/` (tokens, comments and strings excluded), with no exemption list: passed to `dispatch()`, it overrides
both and is only known at run time, so a message is routed by the configuration or by its attribute,
never at dispatch. Its fixture project `Fixtures/AsyncRoutingProject` and the fixture messages
(`AttributeRoutedMessage`, `SynchronousAttributeMessage`) prove each form is caught in its environment,
and the stamp detector is proven on snippets. Its only way out is
`NO_CONVERSION`, with a justification and a check that the entry is still needed: the #470 probe
(`MessengerProbeMessage`), published by `app:messenger:probe`, never from an HTTP request, whose command
catches the `TransportException` itself to quote it in the Job's logs. `AsyncPublishFailureTest` drives
the container's bus behind `tests/Support/UnavailableTransport`, and the #485 end-to-end tests
(`ContactMessageResourceTest`, `BackofficeUserInviteResourceTest`) turn red when the middleware is left
off the bus (checked). Each concrete class has its own `log_level: warning` in
`framework.exceptions` (`ExceptionLogLevelCoverageTest` refuses an entry on the abstract one); no
`exception_to_status`, `getStatus()` already gives 503 on an API Platform operation. A new producer of
messages adds its subclass, its entry and `implements QueuedMessageInterface` on its message, nothing else.

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
missed, and a false negative is invisible while a false positive gets renamed (#428).
`DeclaredClasses::classLikes()` is read once per directory and process (≈ 60 ms per walk of `src/`). Scope: methods of the classes, interfaces, traits and enums of `src/`
(`DeclaredClasses::classLikes()`, issue #421: an enum, an interface nothing implements or a trait nothing
uses would be reached by no class) and of the classes' `App\…` interfaces and parents; a used trait is read
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
callback gets its value under its contract's generic name (as the `$value` of the closures that once validated and
normalized `app:user:create`'s password, which the guard missed). **A callback that receives a secret is therefore a method,
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
`KeyedMac::sign($data)` and both parameters of `verifies($mac, $data)` are hidden and pinned there (issue
#487): the tried login identifier, the CSRF token's two halves — together a valid token — and the Messenger
envelope body go through them under generic names — `hash_equals()`, which received them directly,
hides its own, so routing them through `KeyedMac` must keep them hidden. No exclusion list until a first false positive needs one. Why it matters although
production and PHPUnit (#420) strip arguments (`zend.exception_ignore_args`, see
`.claude/rules/backend-outillage.md`): dev does not (the debug JSON error body, Messenger's
`ErrorDetailsStamp`, output pasted in a ticket or an assistant session), and `debug_backtrace()` never
does. The attribute only hides that frame's arguments: not a message, a local variable, nor a vendor call that
receives the value next (`Cookie::create()`, Lexik). The walk and the hash exclusion are shared with the
password guard (`tests/Support/SensitiveParameters`), so a change there moves both.

**A regex of `src/` ends on `\z`, never on `$`** (issues #386, #409). In PCRE `$` also matches before a
final `\n`, so `/^[a-z]+$/` accepts `"abc\n"` — a username, a watched-product slug and a translation field
name of `src/` were accepted that way. `\z` rather than the `D` modifier: the anchor reads where it acts, and
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
`\App\…\Foo` inline, in code, attributes and phpdoc types alike — a `{@see}`
in a comment is not a type, see below.
**A comment never adds a `use`** (#391). A class the file imports for
its code is cited by its short name — `{@see Foo}` in a docblock, `Foo` in a `//` comment. Any other class
is cited by **`{@see}` with its full name, without `use`**: `{@see \ValueError}`,
`{@see \App\Shared\Infrastructure\Http\RetryAfterListener}`, `{@see \DateInterval::createFromDateString()}`
— the leading `\` makes it unambiguous for the IDE, which keeps it clickable and renames it. Rector leaves
such a `{@see}` alone (verified). In a `//` comment, where `{@see}` means nothing, the full
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
by a full name. Background, verified: Rector's `removeUnusedImports` keeps an import
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
that order, naming the file, the line and the pair, and its failure message names the one-off fix,
**`make back-style-fix`** (issue #398) — run it after any `rector:fix` that adds an import. It reproduces php-cs-fixer's key (the import as written, alias included, `\` read as a space,
`strcasecmp`), block by block, and **refuses** `use function`, `use const` and grouped imports
(`use Foo\{A, B};`) instead of sorting them, and its message says those are rewritten by hand.
php-cs-fixer is deliberately not a dependency (#391): the target runs `docker/php/php-cs-fixer.sh` in the
container, which puts the phar under `var/php-cs-fixer-<version>.phar` at the version `PHP_CS_FIXER_VERSION`
of the root `.env` and runs nothing unless it matches `PHP_CS_FIXER_SHA256` (exit 3, the phar is never
executed) — the release publishes no checksum, only a GPG signature, verified once when the sum was
pinned (provenance in the `.env` comment). Both values have **one source**, `.env`; `versions.lock`
copies them and `tools/tests/php-cs-fixer.test.sh` (offline, `tools-tests` job) fails when the two
disagree. The Makefile reads them from the **host's** `.env` and passes them with `exec -e`: the
container's `/var/www/.env` is a file mount, which keeps the old content after a `git pull` replaces the
file, and the old phar would then pass the old sum unnoticed; the mounted file is only the fallback of a
script run by hand. The version is in the phar's name so that a version bump whose sum was not updated fails
(exit 3) instead of silently reusing the old, valid phar; the name must keep `php-cs-fixer`, which the
fixer's parallel runner looks for in `argv[0]` to find itself (`Cannot determine Fixer executable`
otherwise). One target applies both rules in one pass per directory, `src/` then `tests/` (php-cs-fixer
takes several paths only with a config file). No check-only mode: the two PHPUnit guards are that, and
stricter (they refuse `use function`). Verified under PHP 8.5.11: a misordered `use`, a qualified `\array_map(` and an unqualified `count(`
in `src/` and `tests/` → the script → empty `git diff`. Out of scope, **by decision** (#391): native **functions and constants** are
never imported — no `use function` / `use const`, `importShortClasses` does not touch them; namespaces
cited in prose (`App\Shared`). **A native function is qualified exactly when the compiler optimizes it**
(#391): a function of php-cs-fixer's `@compiler_optimized` set (`\count`,
`\in_array`, `\is_string`, `\strlen`, `\sprintf`, `\dirname`…) is written with its leading backslash, every
other one without (`array_map`, `trim`, `json_decode`: no dedicated opcode, nothing to gain). Measured for
`sprintf`: `\sprintf('x %s', $a)` compiles to a bare `FAST_CONCAT`, the unqualified call to a runtime
lookup and a function call. A first-class callable (`is_string(...)`) is not a call and stays as written.
`tests/Shared/NativeCallsQualificationTest.php` (`tests/Support/MisqualifiedNativeCalls`, issue #394)
keeps it: an unqualified call to a function of the set fails, and so does a qualified call to any other
single-segment global function, with the file, the line and the expected form. Its message names the same
`make back-style-fix`, which applies php-cs-fixer's rule `native_function_invocation`
(`include: ['@compiler_optimized']`) with `strict: true`, also removing a superfluous `\`. Methods, declarations, `new`,
attribute classes and first-class callables are not calls, and, as in php-cs-fixer, an unqualified call
is left alone when the file declares a function of that name outside any class (a namespaced double of
the native, which `\count()` would bypass). The set is **copied** into
`MisqualifiedNativeCalls::COMPILER_OPTIMIZED` from php-cs-fixer at `PHP_CS_FIXER_VERSION`, and a drift
test requires each name to be an internal function of the running PHP unless it is listed in
`ABSENT_FROM_PHP` (`is_real`, removed in PHP 8.0). It cannot see the other direction — a function PHP
starts compiling, as `sprintf` with PHP 8.4 —: on a php-cs-fixer bump (a new `PHP_CS_FIXER_VERSION` and
`PHP_CS_FIXER_SHA256` in `.env` and `versions.lock`),
copy the new list over and re-read both guards against the fixers they mirror.
Constants are left as written: a leading backslash already there stays.
**The line is performance, and it was measured** (PHP 8.5, OPcache dump after the optimizer): the
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
types (issue #391). It does **not** see a `@template T of \Foo` bound, reviewed by
hand, nor any prose, where `CommentedClassNamesExistTest` checks the full names (see above), nor import
order or function qualification, which the two #394 guards above check.

