---
paths:
  - "backend/src/Security/Authentication/Infrastructure/Security/FailedLoginTimingEqualizer.php"
  - "backend/src/Security/Authentication/Infrastructure/Security/LoginFailureCause.php"
  - "backend/src/Security/Authentication/Infrastructure/Security/LoginThrottlingRefusalListener.php"
  - "backend/src/Security/Authentication/Infrastructure/Security/OverlongPasswordRefusalListener.php"
  - "backend/src/Security/Authentication/Infrastructure/Security/SubmittedPasswordTooLongException.php"
  - "backend/config/packages/security.yaml"
  - "backend/tests/Security/Authentication/CpgUserPasswordHasherTest.php"
  - "backend/tests/Security/Authentication/LoginFailureTimingTest.php"
  - "backend/tests/Security/Authentication/StalePasswordHashRehashTest.php"
  - "backend/tests/Security/Authentication/Infrastructure/Security/FailedLoginTimingEqualizerTest.php"
  - "backend/tests/Security/Authentication/Infrastructure/Security/LoginFailureCauseTest.php"
  - "backend/tests/Security/Authentication/Infrastructure/Security/LoginThrottlingRefusalListener*Test.php"
  - "backend/tests/Security/Authentication/Infrastructure/Security/OverlongPasswordRefusalListener*Test.php"
---

# Backend — `Security/Authentication`: a failed login must not be timeable

> Split from `.claude/rules/security-authentication.md` (#650, S5; procedure of #605), which keeps the index of the parts. This part: the timing of the three login failures, the overlong password, the password hasher.

- **`Infrastructure/Security/FailedLoginTimingEqualizer.php` — a failed login must not be timeable**
  (audit A10). The three failures already return the same 401, but not in the same time: a known active
  account paid a full bcrypt/argon `verify()`, while an **unknown identifier** (the user is never loaded)
  and an **account without a password** (an invitation whose person has not chosen one yet: empty hash,
  `password_verify($p, '')` returns at once) cost nothing — the gap said which identifiers exist and which
  are pending invitations. On `LoginFailureEvent`,
  `login` firewall only, priority **-100** (after the throttling counter and the audit log, neither of
  which should wait on a hash), it pays a dummy `hash()` of a constant with `CpgUser`'s configured hasher
  in **those two cases only**: never for an account that has a password (the real hash already happened —
  a second one would recreate the gap the other way), never for the throttling (its answer must stay cheap), never for
  a blank password (refused before any DB access, identically for everyone). `hash()` of a constant, not
  `verify()` against a frozen hash, so it follows `password_hashers` if the algorithm or cost
  changes. The submitted password is never read. The response is untouched — the three 401s must stay
  byte-identical, which is the other half of non-enumeration. **What decides is the absence of a
  password, `CpgUser::hasPassword()`, not pending activation** (issue #560): a `ROLE_SUPER` can set the
  password of an invited account from the backoffice (#238, #460), which stays pending activation while
  `verify()` does the real hash — compensating it as well doubled its response time and singled it out as
  « an invitation whose password was set for the person ». Until #560 the listener read
  `isPendingActivation()`, written when pending meant an empty hash. **A password over the hasher's bound
  is refused before any account is loaded** (issue #588): past `CpgUser::MAX_PASSWORD_LENGTH` bytes
  (`PasswordHasherInterface::MAX_PASSWORD_LENGTH`, `strlen`), `verify()` returns `false` without hashing,
  so an existing account with a password answered one bcrypt faster than an unknown identifier — about
  270 ms at production cost, measured with `password_hash` on a workstation (#588) and by A10 (273 ms), not
  on a pod — enough for one request to tell. `Infrastructure/Security/OverlongPasswordRefusalListener` (`CheckPassportEvent`, priority
  **1500**: below `login_throttling`'s `checkPassport` at 2080, which sets the tried identifier — so the
  refusal counts per (IP, identifier) and a throttled pair still gets its 429 —, above
  `UserCheckerListener` at 256, the first to load the account) throws `SubmittedPasswordTooLongException`,
  a `BadCredentialsException` that overrides neither `getMessageKey()` nor the code: Lexik's 401, byte for
  byte. No account loaded, nothing hashed, the same path for every identifier. **This listener and the
  audit subscriber recognise that exception before calling `Passport::getUser()`**, which would load the
  account (a query, and the unknown identifier alone compensated — the gap back): no dummy hash,
  `login-failed` with the fingerprint and no `userId`. Only the length is read, never the value; it is
  not a validation of a password being set (see `.claude/rules/security-user.md`). Refusing rather than
  compensating (the equalizer would have had to read the submitted password, pay a bcrypt and keep the
  query) or truncating (a wrong input whose first 4096 bytes are the password would pass). **Accepted
  limits** (security review of #588): the refusal is faster than an ordinary failure (no bcrypt), so a
  stopwatch tells « too long » from « wrong », never which account exists; and below the bound,
  `verify()` pre-hashes in sha512 an input over 72 bytes or holding a NUL while the dummy `hash()` of a
  short constant does not — microseconds under bcrypt and network noise, out of reach under
  `login_throttling`, and equalizing it would mean reading the input. Which failures leave no account
  loaded is written once, `Infrastructure/Security/LoginFailureCause` (throttled, unknown identifier,
  overlong password, other), read by this listener and the audit subscriber — a new early refusal adds
  its case there. The
  exception has a `log_level: info` entry it never uses: it inherits `AuthenticationException`'s
  `#[WithHttpStatus(401)]`, which `ExceptionLogLevelCoverageTest` reads, but the `AuthenticatorManager`
  catches it before the kernel. `OverlongPasswordRefusalListenerPriorityTest` pins the window on the real
  dispatcher, and the exact list of listeners allowed above it (the throttling, the firewall's
  `UserProviderListener` at 2048, which only sets the loader): a new one there must be shown not to
  load the account. `CpgUser::MAX_PASSWORD_LENGTH` is pinned equal to the hasher's constant.
  **One derivation whatever the stored hash's age** (issue #590): `security.yaml` writes
  `algorithm: bcrypt` and `cost: 13` (`4` under `when@test`), **never `auto`**. Under `auto`,
  `MigratingPasswordHasher` tried `SodiumPasswordHasher` after a failure on a hash that `needsRehash()`,
  which falls back on `password_verify` — two bcrypt, the equalizer pays one. With `bcrypt` the
  secondaries left (Pbkdf2, MessageDigest) refuse any hash holding a `$` before computing anything; an
  argon hash still verifies and a successful login still rehashes (`StalePasswordHashRehashTest`); the
  `$2y$13$` hashes `auto` produced are not stale. The cost is written so that no new Symfony default
  changes it silently. `CpgUserPasswordHasherTest` proves no secondary verifies a stale bcrypt or argon
  hash (behaviour, with the right password), pins the exact list of secondary classes (a new one must be
  shown to refuse before deriving), and pins per environment (`EnvironmentConfigKernel`) the **whole**
  `CpgUser` entry (an `id` would replace everything), `CpgUser` as the only key of `password_hashers`, and
  no `PasswordHasherAwareInterface` on `CpgUser`. **Accepted limit** (decided on
  2026-10-13): that one derivation runs at the **stored** cost, the equalizer pays the configured one —
  after a change of cost or algorithm, each account with an older hash answers in a time of its own
  (34 ms at cost 10, 539 ms at 14, against 270 ms at 13, workstation) until its next successful login
  rehashes it; a dormant account keeps it. Rehashing ahead needs the plaintext; the two workarounds were
  not retained — an onion hash (bcrypt of the old hash, which no Symfony hasher verifies) or emptying the
  password and reinviting (the account becomes « without a password », compensated, but the person must
  choose a new one; never on the last `ROLE_SUPER` with a password). Paying the difference here would
  mean reading the stored hash and works one way only. **Before changing
  `password_hashers`**, read `k8s/README.md` « Avant de changer `password_hashers` » (read-only SQL count
  of hashes per format, a human's `exec`) and update the test in the same change. Also accepted: an
  unknown identifier matching `USERNAME_PATTERN` pays a query returning no row, an account one row and
  its hydration — about 100 µs against bcrypt's ~3 ms standard deviation, thousands of tries per
  identifier, weeks for a single IP under `login_throttling`, hours for an attacker spread over many
  addresses (the per-IP counters' limit, already accepted).
  `FailedLoginTimingEqualizerTest` + `tests/Security/Authentication/LoginFailureTimingTest.php` pin it.
  Related fact worth knowing: `login_throttling` answers a **429** `/errors/rate-limited` with
  `Retry-After` since issue #399, the only failure that leaves Lexik's 401 — it says nothing about the
  account. `LoginThrottlingRefusalListener` throws it at priority -200, below this listener; see "Every
  quota 429" in `.claude/rules/backoffice-api.md`.
