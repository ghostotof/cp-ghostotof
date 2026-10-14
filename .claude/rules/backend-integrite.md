---
paths:
  - "backend/src/**"
  - "backend/tests/**"
  - "backend/migrations/**"
  - "backend/config/**"
---

# Backend — integrity: authenticated envelopes and cache values, derived keys

> Split out of `.claude/rules/backend-architecture.md` (#605), whose index lists every backend-wide rule. The index of every rule is at the top of `.claude/CLAUDE.md`.

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
purge it** (`messenger:failed:show` by a human `kubectl exec`, replay and removal by Job, issue #521,
`k8s/README.md` « File d'échec Messenger »), and deploy with `DEPLOY_MAINTENANCE_WINDOW=true`
(`.claude/rules/deploiement-pipeline.md`): otherwise an old API pod (`RollingUpdate`) still signs under the old key
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
**An envelope over `AuthenticatedEnvelopeSerializer::MAX_ENVELOPE_BYTES` (256 KiB, body and headers, header
names included) is refused before its MAC is even computed** (issue #469): whoever writes into the broker
could publish several MB — in the body, or in a header, which a MAC refusal kept and copied into `failed`
too — HMACed whole, then re-encoded into `failed`: enough to loop the worker (`--memory-limit=120M`,
192 Mi). The bound was measured on the real messages; the figures live in the constant's docblock only.
A message grows by one `ErrorDetailsStamp` per distinct failure (Symfony empties its trace), so a
legitimate one could only cross the bound after many distinct failures and `failed:retry` cycles: it would
then be lost, accepted — raise the bound, never add an exception to it. The refusal,
`EnvelopeTooLargeException` (final, like a refused MAC), **keeps nothing of the envelope**: a marker (empty
body, size in `X-Envelope-Refused-Size`) that the serializer decodes back into the same refusal, since
`DecodeFailedMessageMiddleware` decodes the kept envelope again and an empty unsigned body would otherwise be
refused « sans MAC », its real cause lost. The marker counts only on an empty body and above the bound;
forged, it only yields that refusal with a made-up size. Its **trace is emptied** at creation (reflection on
`Exception::$trace`): without `zend.exception_ignore_args`, as in dev, the trace kept `decode()`'s and the
transports' arguments — the body — and copied it into `failed` (measured: 800 KB for a 300 KB body).
**The transport has already loaded the whole message** before `decode()` runs: RabbitMQ caps a body at
1 MiB at publication (`max_message_size`, issue #477, `.claude/rules/deploiement.md`).
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
`CacheValueMacRefusedException`, which the adapter turns into a cache miss logged `warning`. The decoration
reaches every adapter given `cache.default_marshaller` (filesystem, APCu, Redis, Memcached and PDO too):
today, only the Doctrine DBAL pools; `cache.system` (PhpFilesAdapter) does not use it. **The key is derived
on first use, never in the constructor**: the `docker build` warm-up (`cache:clear`, empty `APP_SECRET`)
builds the service through the EntityManager, and an eager derivation failed it behind the Dockerfile's
`|| true` (caught in review); since #474 such a failure fails the image build (`.claude/rules/images-docker.md`). Measured: ≈ 2 µs per round trip. **A value signed under another key is
refused**: the deployment of #468, an `APP_SECRET` rotation or a new context drop the database cache, and
during the rollout old and new pods keep refusing and overwriting each other's values, so limiter counters
restart at each alternation. Say so in the release notes. A refused row is never deleted:
`Version20261011140000` empties `cache_items` once, and after an `APP_SECRET` rotation
`app:messenger:stop-workers` (its Job, `k8s/base/messenger-stop-workers-job.yaml`) rewrites the restart signal, which never expires,
**and reads it back by value** (issue #528): Symfony's `messenger:stop-workers` ignores `save()`'s result and answered `[OK]`, exit 0,
when PostgreSQL refused the write. Ours (`Shared/Infrastructure/Messenger/CacheWorkerRestartSignal`, behind
`Shared/Application/WorkerRestartSignalInterface`) writes the same signal — pool `cache.messenger.restart_workers_signal`, key
`StopWorkerOnRestartSignalListener::RESTART_REQUESTED_TIMESTAMP_KEY`, a float timestamp — and throws
`WorkerRestartSignalNotSavedException` (exit 1) when `save()` is false or the value read back is not the one written (an earlier
signal satisfies `isHit()`). Symfony's command stays registered; the Job and the runbook use ours. `WorkerRestartSignalReachesTheWorkerTest`
runs a real `Worker` with the container's listener on what ours writes (red when it writes to `cache.app`, measured), and pins Symfony's
`[OK]` under a read-only session: if that one turns red, Symfony fixed it. Accepted limit: the
MAC covers neither the row's key nor its pool, so a signed value can be copied across keys and pools or
replayed: a limiter counter rolled back, or an array on the restart-signal row that stops the worker on
every loop. That is a denial of service by someone who can already rewrite every row, never code
execution. `AuthenticatedCacheMarshallerTest` and `CacheItemsAreAuthenticatedTest` (a real tampered row;
every database pool found through `cache.global_clearer` must carry the marshaller) pin it.

**`APP_SECRET` never keys an HMAC directly: each use derives its own key** (issue #455).
`Shared/Infrastructure/Security/SecretKeyDerivation::derive($secret, $context)` is HKDF-SHA256 (RFC 5869,
32 bytes, an empty secret refused with `EmptyApplicationSecretException`), and each class receiving
`kernel.secret` declares a versioned `KEY_CONTEXT` (`cp-ghostotof/<use>/v<n>`): the CSRF cookie signer, the
login-identifier fingerprint, the Messenger envelope MAC (`v2` since #450, see above), the database cache
value MAC (#468). Keyed by the raw secret and told apart only in the message (a prefix, or nothing), any
future feature returning `HMAC(APP_SECRET, x)` for a client-chosen `x` would have been a signing oracle for every other use. Changing a context is a targeted rotation. The
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
or its expression-language forms (`parameter('kernel.secret')`, `env('APP_SECRET')`), and since #487 every file
deriving a key at all (a secret received from another class); in each, **every** `KeyedMac::fromSecret()` /
`SecretKeyDerivation::derive()` call must take exactly `self::KEY_CONTEXT` as its second argument with its
exact argument count (swapped arguments, an extra one, a concatenated context or a second call under a literal
context turn it red), the `KEY_CONTEXT` must match `SecretKeyDerivation::CONTEXT_PATTERN` and no other file may
use it, and no MAC is computed by hand (see the next paragraph); it refuses any `config/**.yaml` naming the
secret besides `framework.secret`. Not covered, accepted: a
class handing the raw secret to another that uses it without deriving, an obfuscated name
(`getenv('APP_'.'SECRET')`), and Symfony's own uses (`login_throttling`, `SigningSerializer`),
which keep the raw secret but never return their output to a client. Unit tests take key material from
`tests/Support/TestKeys` (generated, like `TestCredentials`): a literal assigned to a name saying "secret"
gets flagged by GitGuardian.

**The HMAC under a derived key is written once, in `Shared/Infrastructure/Security/KeyedMac`** (issue #487).
`KeyedMac::fromSecret($secret, self::KEY_CONTEXT, MacEncoding::Hex|Binary)` — a named constructor, the only
entry point — derives the key (an empty secret refused there, and since #487 a context outside
`cp-ghostotof/<use>/v<n>`, `InvalidKeyContextException`, checked by `SecretKeyDerivation::derive()` itself), then `sign($data)` and `verifies($mac, $data)` (HMAC-SHA256, `hash_equals()`) — the four uses
above go through it, and none calls `hash_hmac()` or `hash_equals()` itself any more. An immutable object, not a
service (not a value object strictly: it carries a key and has no equality to offer): each use has its own key, context and encoding, fixed at creation, so a use cannot sign in hex and
verify in binary. **The derivation happens in `fromSecret()`, the laziness stays with the caller**, each
keeping its moment: the cache derives on first use (`??=`, #474 — the build's warm-up has no secret), the CSRF
signer and the login fingerprint on every call (a refusal at first use, not at every request's
construction), the Messenger serializer in its constructor. `KeyedMac::BINARY_LENGTH` is the length the cache
splits its stored value on. Truncation stays with the caller (the fingerprint's 6 hex chars). Rejected: a lazy
`KeyedMac` holding the raw secret (mutable, and it would have moved Messenger's refusal), and a pair of
methods per encoding (sign one way, verify the other). Going through it is no rotation:
`DerivedMacsSurviveKeyedMacTest` writes, per use, the whole computation from before #487 — the #455
derivation as `hash_hkdf('sha256', …, 32, …)`, never a call to `SecretKeyDerivation`, its **literal** context
and its `hash_hmac()` — and checks both directions (a MAC signed before is accepted, the class signs what it would have
signed); a changed `KEY_CONTEXT` turns it red, which is intended — a rotation is decided and announced in the
release notes, never slipped in by a refactoring. `sign()`'s `$data` and both of `verifies()`'s parameters carry
`#[SensitiveParameter]`, pinned in `HIDDEN_BY_HAND` (see the parameters rule of `.claude/rules/backend-conventions.md`).
**The derived key never shows in a dump**: it is held in a `SensitiveParameterValue`, which `var_dump()`,
`print_r()`, `var_export()`, an array cast, `json_encode()` and Symfony's VarDumper (`dump()`, the profiler) all
show empty, and which PHP refuses to serialize. Not a `__debugInfo()`: VarDumper reads the private properties
first and only adds what it returns (`Caster::castObject`, measured in the review of #487). **Nor does the raw
`APP_SECRET`** (issue #493): the CSRF signer, the fingerprint and the cache marshaller keep it to derive later,
in a `SensitiveParameterValue` too. `ApplicationSecretNeverShowsInADumpTest` dumps the four services, put to
use first so that their lazy derivations have run, through every channel (`tests/Support/Dumps`, shared with
`KeyedMacTest`), and `ApplicationSecretUsesTest` refuses a `string` / `?string` property, declared or promoted,
whose name says "secret" in a class of the census. **A MAC is only ever checked by `verifies()`** (issue
#493): `sign()` stays public, so `tests/Shared/Infrastructure/Security/SignaturesAreNeverComparedByHandTest.php`
reads all of `src/` statement by statement (bounded by `;`, `{`, `}` and the PHP tags; a `match` block stays
with its subject) and refuses a comparison (`===`, `!==`, `==`, `!=`, `<>`, `<=>`, `strcmp` and its variants,
`in_array`, `array_search`, `match`) in a statement that calls `->sign(` or reads a target (variable, property,
offset) another statement of the file assigns from `->sign(`. Not seen: a MAC handed to another method or
returned, then compared there. The reading ignores scope: a homonym assigned from `sign()` elsewhere in the
file is enough to refuse — rename it. `ApplicationSecretUsesTest` refuses, in any file of the census, `hash_hmac()`,
`hash_equals()`, `hash_hmac_file()`, `hash_init()` and `sodium_crypto_auth(_verify)()` — called, qualified or
not, imported by `use function`, taken as a first-class callable or named in a string for `call_user_func()`;
never a method or constant of the same name, its declaration nor a comment: such a class has no other key than
the one it derives. `hash_equals()` elsewhere (the double-submit comparison of `CsrfCookieRequestSubscriber`) is not a
MAC and stays.

