---
paths:
  - "k8s/overlays/*/kustomization.yaml"
  - "docker/php/www.prod.conf"
  - "backend/config/packages/framework.yaml"
  - "docker/nginx/**"
  - "docker/node/**"
  - "k8s/base/backend-nginx.conf"
  - "k8s/base/backend-deployment.yaml"
  - "k8s/base/frontend-deployment.yaml"
  - "k8s/base/ingress.yaml"
  - "k8s/overlays/*/ingress-*.yaml"
  - "k8s/ingress-nginx-values.yaml"
  - "frontend/public/.well-known/**"
  - ".github/workflows/pipeline.yml"
  - "tools/audit-prod.sh"
  - "tools/check-backend-nginx-rate-limits.sh"
  - "tools/check-frontend-image-headers.sh"
  - "tools/lib/security-headers.sh"
  - "tools/smoke-login-throttling.sh"
  - "tools/tests/security-headers.test.sh"
  - "tools/tests/smoke-login-throttling.test.sh"
  - "tools/smoke-mcp.sh"
  - "tools/tests/smoke-mcp.test.sh"
  - "backend/config/packages/nelmio_cors.yaml"
  - "SECURITY.md"
---

# Deployment invariants — nginx (rate limits, headers, security.txt)

> Split out of `.claude/rules/deploiement.md` (#605), whose index lists every deployment rule. The index of every rule is at the top of `.claude/CLAUDE.md`.

- **Eight nginx rate-limit zones, two different jobs.** `contact` (10 r/m), `pwsetup` (20 r/m),
  `baseaccess` (20 r/m, issue #77 — each call signs an RS256 JWT), `login` (10 r/m, burst 10,
  ADR 0005 — the backstop under Symfony's `login_throttling`, which is the real ceiling) and
  `assistant` (10 r/m, burst 5, plus `limit_conn assistantconn 1` — each call is billed and each
  stream holds one of a pod's 8 php-fpm workers; the zones live in each sidecar, so an IP gets N times
  these ceilings with N backend pods) protect a *side effect* — sending mail, guessing a token,
  minting a token, guessing a password, spending money.
  `mcp` (60 r/m, burst 20, #644, ADR 0006 D6) and `mcpsession` (10 r/m, burst 5) sit on the **exact**
  `location = /api/mcp`, every method (the `GET` boots Symfony too; a CORS preflight costs a token as well).
  `mcp` protects the resource like `publicapi`, at a tenth of its rate, since each request reads and rewrites a
  session row in `cache_items` — it also bounds the `mcp_usage` lines. `mcpsession` counts **session creations
  only**: its key is the address for a `POST` **without a session as the SDK reads it** and empty otherwise (an
  empty key is not counted); an `initialize` carrying an invented id to escape it is refused by the SDK, nothing
  written (measured by the audit of #644). **« Without a session » is an allow-list** (#672): `$mcp_with_session`
  is `1` only for a well-formed RFC 4122 UUID (any case), anything else counts. nginx and the SDK do not read
  the header alike: nginx keeps tabs around the value and admits control characters, PHP's PSR-7 bridge trims
  spaces and tabs and **drops** a header carrying a control character, then `StreamableHttpTransport` tests the
  value's PHP truthiness (`""` and `0` read as no session). A deny-list of the empty forms (`""`, `0`, the first
  fix) let `<TAB>0`, `x<0x01>y` or a lone tab create sessions uncounted (reviews of #672, measured: twelve
  `initialize`s, twelve rows, no 429); `0` itself escaped the zone before #672. A client sends back the id the
  server gave it (`toRfc4122()`); an invented UUID is refused by the SDK without a write, any other value with a
  400. Accepted: a session id in a form `Uuid::fromString()` also takes (base 58, base 32) is counted, a token
  lost by a client nobody writes. Then `$mcp_new_session` keys
  `"$request_method:$mcp_with_session:$http_mcp_protocol_version"` (two maps so that the session header cannot
  fake the separator) and **exempts `MCP-Protocol-Version: 2026-07-28`** exactly: every POST of that sessionless
  revision carries no session, and the SDK serves it on an in-memory session, nothing written — counted, a
  legitimate modern client was capped at 10 calls a minute; `mcp` still counts it. The SDK files a request
  under that revision **by its body** (`params._meta`), the header being a cross-check it requires; a modern
  request without the header is refused (400) and stays counted, the cautious side. The exemption opens no path
  to a session: an `initialize` carrying that header gets a 400 (no claim in `params._meta`) or a 404 (claimed:
  no `initialize` in that revision), no row written — measured in dev behind nginx, and pinned by
  `McpEndpointTest` (`testAnInitializeUnderTheModernRevisionOpensNoSession`, `testAModernRequestIsServedWithoutWritingASession`,
  both red under a mutated SDK) with `testTheSessionZoneExemptsExactlyTheSdkModernRevisions`, which matches the
  conf's exemptions against `ProtocolVersion::modernVersions()`: a modern revision a SDK bump adds turns it red
  instead of being silently counted. It holds whatever #664 decides (refusing the modern era makes those
  requests cheap 400s). A handshake revision in the header (`2025-06-18`), or `2026-07-28` with a tab, stays
  counted. **`mcpconn`** (#672, `limit_conn mcpconn 2`, same location) bounds simultaneous connections per IP
  and per pod. It was set against the modern revision's `subscriptions/listen`, which held its php-fpm worker
  30 s: fourteen of them from one IP took a whole pod's pool (measured in dev, an unrelated route served 29.8 s
  later); `mcp` bounds the rate, not the duration. **Since #675 `listen` is refused before the SDK** and the
  server announces no notification (`.claude/rules/mcp.md`), so no legitimate request holds a worker beyond
  milliseconds; the zone is **kept at 2 by decision** (2026-10-21), as defence in depth: one IP never holds more
  than two of a pod's eight workers, whatever a request becomes (a slow tool, a future stream). The location also
  sets `fastcgi_ignore_client_abort on`, like `/api/assistant/`: a request writing nothing before its end keeps
  its worker after a client abort, and its `mcpconn` slot with it. A stream that writes — `listen` before #675,
  every 250 ms — aborted mid-stream makes nginx fail to write, close upstream and the SDK return the worker
  (measured: eight `listen`s aborted at 1.2 s, a pool of five, an unrelated route served at once). Measured too,
  before #675: the third simultaneous `listen` got the 429. Accepted: hosted connectors sharing an egress IP may
  meet the bound when calls overlap — they last milliseconds, and the `mcp` zone binds first; both bounds are to
  be measured again against the real clients in phase 2. The location has its own
  `client_max_body_size 16k` **and its own `fastcgi_pass`** (like `/api/assistant/`): through `try_files …
  /index.php`, a `Transfer-Encoding: chunked` body was read after the internal redirect, under `~ ^/index\.php`,
  which only has the server's 1 MB — the bound held for a `Content-Length` body only (a 480 KB session measured).
  `tools/check-backend-nginx-rate-limits.sh` probes both framings. **What the client can still make the database
  keep** (measured on 2026-10-20, dev): `InboundMessageRefusalListener` refuses batches, client responses and an
  `initialize` declaring more than 4 KiB (`.claude/rules/mcp.md`), so a fresh session row is a few KB; what is
  left is the last request's `_meta`, which the SDK keeps in the session, replaced by each request, up to the
  16 KiB body — re-encoded (an `é` becomes `\u00e9`). What one IP can make the database keep is therefore bounded by
  `mcpsession`'s rate, the two hours a row can live (one-hour TTL, hourly purge) and that body bound, several
  times less than before the audit fixes of #644 (the worst-case volume is deliberately not written in this
  public repository). Accepted: the zones are per IP and per pod, no global ceiling (a global one would
  let an attacker block every new session); `_meta` keeps the body bound, decided by the author on 2026-10-20 (a
  tighter bound would leave the session-bound tests without an HTTP path to it), confirmed by #672 and resting on
  the SDK replacing it on each request (`McpEndpointTest::testEachRequestMetaReplacesThePreviousOneInTheSession`,
  red when the SDK accumulates it — measured by mutation). **The site is IPv4 only** (no
  `AAAA` for the production or preprod host, checked on 2026-10-20): `$binary_remote_addr` is a whole address;
  the day an `AAAA` is published, an IPv6 /64 gives 2^64 keys — key the MCP zones by /64 first. A 413
  (`Content-Length` over the bound) is rendered at find-config, before any zone: an unlimited flow of them
  costs nginx only. Its 413 is nginx's HTML page, like the server's 1 MB one. Accepted: claude.ai and ChatGPT
  connectors call from their providers' egress addresses, which their users share — fine at this site's
  audience, revisit if phase 2's real clients get throttled. `tools/smoke-mcp.sh`, run by `smoke-test-preprod`
  (its own `timeout-minutes: 3`), proves it on the real pod: an `initialize` on the public host (a 403 there
  means `allowed_hosts`, derived from `APP_FRONTEND_BASE_URL`, misses it), the session closed by `DELETE` (its
  status checked: a session left open stays an hour), a 16 KiB + 1 body refused in 413 with `Content-Length` then in
  `chunked` (under a fake session id: no zone of session creations, no PHP), then `GET`s without a session (they
  write nothing) until the 429 — within 120 requests, so it can only be the `mcp`
  zone (`publicapi`'s burst is 200). Between the body bound and the `GET`s (#672), `ping`s under
  `Mcp-Session-Id: 0` (a 400 from the SDK, nothing written) until `mcpsession`'s 429, within 12 — `mcp` would
  admit more —, then a sessionless `tools/list` of revision 2026-07-28, which must not get a 429 (a token
  refilled meanwhile would let a counted one through: `check-backend-nginx-rate-limits.sh` is the exact proof,
  the smoke says the pod carries the conf). Measured in dev on 2026-10-20: the 429 at the 20th `GET` before
  #672 (the `initialize` and the `DELETE` took two tokens), at the 14th since; the seventh `initialize` in a
  row refused by `mcpsession`, and the sixth `0` POST after the `initialize`. The smoke's 429 there must be
  problem+json `/errors/rate-limited`, and the modern request must not be a 5xx; its bound assumes one pod, as in
  preprod (two pods behind the Service count apart). `check-backend-nginx-rate-limits.sh` probes the key on fresh
  counters, before any other probe (`check_session_key`): `0` counted (at least the burst admitted, read from
  `ZONES`, then a 429 within `MCPSESSION_MAX_ADMITTED`), then on the emptied zone each `SESSION_KEY_CASES` entry —
  an empty header, `<TAB>0`, `x<0x01>y` and a UUID followed by a tab counted; a well-formed UUID and a 2026-07-28
  POST not counted (the witnesses that the zone does not count everything); a 2025-06-18 one counted. The guard
  sees nginx only, never what PHP reads: the reading chain above is why the key is an allow-list.
  `publicapi` (600 r/m, burst 200, on
  `location /`) protects the *resource*: without it every public read reaches PHP and Postgres as
  often as asked. Its ceiling is deliberately far above real use — behind a mobile carrier's CGNAT
  thousands of visitors share one address, and a tight cap would cut them all off at once, which is
  the very DoS audit C7 was about. `/healthz` uses an exact-match `location =`, so kubelet probes are
  never capped.
- **`location ^~ /api/assistant/` does its own `fastcgi_pass`** (spec 0005 D9, both confs). The career
  assistant streams `text/event-stream`, so the location sets `fastcgi_buffering off`; through
  `try_files … /index.php` the internal redirect to `location ~ ^/index\.php` would leave that directive
  behind. Measured on 2026-09-26: `X-Accel-Buffering: no` (set by `EventStreamResponse`) already suffices
  for the sidecar, which consumes it — the ingress never sees it and relies on its own `proxy-buffering`,
  `off` by default. The location is kept as defence in depth, and carries the `assistant` zones above,
  `fastcgi_ignore_client_abort on` (PHP holds its worker after a client abort, so the `limit_conn`
  slot must too). Its 429s come out in problem+json like every other zone's, see the next bullet.
- **Every nginx 429 answers problem+json `/errors/rate-limited`** (issue #347, both confs). One
  `error_page 429 = @rate_limited` at `server` level covers every zone (`limit_req` and
  `limit_conn`); nginx's default HTML page used to leak out of all of them but `assistant`.
  `limit_req_status 429` / `limit_conn_status 429` sit at `server` level too: a refusal defaults to
  503, which that `error_page` would not catch. Two traps: a `location` that declares an
  `error_page` of its own loses the inherited one, and an `add_header` in `@rate_limited` would drop
  the seven security headers (A16). Symfony's own 429s pass through
  untouched (no `fastcgi_intercept_errors`), `Retry-After` included. **No CORS headers on these
  429s, on purpose** (issue #368): only `nelmio_cors` sets them, and none is needed — in preprod and
  prod the frontend and the API share **one origin** (single-host ingress, `/api` → `backend`,
  `API_URL` = the site's own origin), so the browser applies no CORS check and the frontend reads
  the 429 and its problem+json body (checked in a real browser on preprod, 2026-10-10: response
  `type` `basic`, nginx's own `/errors/rate-limited` body read by `fetch`). Only dev is cross-origin (Vite on
  `:5173`, nginx on `:8080`): there `fetch` throws a `TypeError` on these 429s. Don't add CORS to
  `@rate_limited` (a second source of truth for the origin, plus the A16 header trap) and don't map
  a `TypeError` to "rate-limited" — revisit only if the API ever moves to its own host. Guard:
  `tools/check-backend-nginx-rate-limits.sh` (`make back-nginx-rate-limits`, CI job
  `backend-nginx-rate-limits`) runs the pinned sidecar image on each conf, saturates every
  `limit_req` zone and checks the body, the type and the headers; it fails naming any `limit_req`
  zone of the confs missing from its `ZONES` list. Since #644 it also checks the two confs are mirrors where
  it matters — the whole `http` level identical (`real_ip`, `map`, zone declarations), and identical
  directives in each `MIRRORED_LOCATIONS` block (`= /api/mcp`, `fastcgi_pass`'s upstream masked; since #686
  `@rate_limited` and `@unsupported_media_type`, whose `title` and `detail` the probes do not read), comments
  ignored — and each `BODY_LIMITS` bound (413 one byte over, none at the bound, with `Content-Length` and in
  `chunked`).
  **Every `limit_conn` zone is exercised since #672** (`CONN_LIMITS`, `check_conn_limit`; a zone of the confs
  missing from the list fails it): with no php-fpm, a connection is held by leaving its body unfinished — nginx
  counts it once its headers are read and reads the body before reaching upstream —, the next request must get
  the problem+json 429 and pass again once they are closed. `assistantconn` was never exercised before.
- **Multipart bodies never reach PHP** (issue #686, follow-up of CVE-2026-109618, both confs). A `map` at `http`
  level sets `$refused_content_type` for a `Content-Type` starting — any case, after optional blanks — with
  `multipart/form-data`, and an `if ($refused_content_type) { return 415; }` at **`server` level** refuses it
  in the server-rewrite phase, before location matching, hence before every zone and every `fastcgi_pass` (the
  `/api/mcp` and `/api/assistant/` locations included), any method. Why every method: the advisory's OOM comes
  from the multipart parser PHP runs itself — at the start of a POST, and **for PUT, DELETE, PATCH and QUERY
  through Symfony's `Request::createFromGlobals()`, which calls `request_parse_body()`**, before the router and
  the firewall (measured on PHP 8.5: an anonymous multipart PUT on any URL ran the parser). No route reads
  multipart: API Platform declares `json` only and already answered it with a 415 `/errors/415`, the assistant
  demands `acceptFormat: 'json'`, `login_check` is `json_login`, MCP reads JSON, `logout` and `base-access` take
  no body; no frontend call nor smoke sends one. **`application/x-www-form-urlencoded` is let through on
  purpose** (decided on 2026-10-22, review of #686): it is not the advisory's parser, and the token endpoint of
  phase 2's OAuth 2.1 server (ADR 0006 D3) receives it by standard (RFC 6749) — refusing it, here or with
  `enable_post_data_reading = Off`, would break that endpoint in preprod only, since PHPUnit goes through
  neither nginx nor the image's ini. The 415 is rendered by `@unsupported_media_type`, problem+json
  `type: /errors/415` (API Platform's own), through `error_page 415` at `server` level. One trap only: an
  `add_header` there would drop the seven security headers (A16) — a location's own `error_page` cannot
  shadow it, the `return` happens before any location is chosen. A Symfony 415 (`text/plain` on an API
  Platform route) passes through untouched. No CORS headers, like the 429s above (#368): readable in preprod
  and prod (one origin), not in dev. `$content_type` is the very value PHP receives (`CONTENT_TYPE` in
  `fastcgi_params`); duplicated headers are joined by `, ` in order and PHP cuts at the first `,`, so both keep
  the first type (measured on nginx 1.30.4); the pattern refuses a superset of what PHP matches (lowercased,
  cut at the first `;`, `,` or space). Guard: `tools/check-backend-nginx-rate-limits.sh`, `CONTENT_TYPE_CASES`
  (mixed case, a leading tab, five methods QUERY included, both duplicate orders, the two `fastcgi_pass`
  locations, plus urlencoded, JSON and bodiless witnesses that must pass); the `map` is covered by the
  `http`-level mirror check, `@unsupported_media_type` and `@rate_limited` by `MIRRORED_LOCATIONS`. PHP
  itself is the second barrier (`.claude/rules/images-docker.md`, « PHP parses no multipart body »).
  **Accepted: a multipart request is counted by no zone** (issue #702, decided on 2026-10-25), neither
  `limit_req` nor `limit_conn`: the server-rewrite phase runs before preaccess, and before find-config, so a
  multipart body over a location's bound gets the 415, not the 413. Measured on nginx 1.30.4: twelve multipart
  POSTs on `/api/contact` (burst 5) got twelve 415s, and a multipart sent while `assistantconn` was full got a
  415. Why it costs nothing: none of them reaches PHP, which is what the zones protect, and **every refusal of
  a request whose body is still coming goes through the same lingering close**: nginx discards what the client
  keeps sending, up to `lingering_time` (nginx's default 30 s, `lingering_timeout` 5 s between reads — neither
  set in the confs; a change of either moves all three refusals alike) — measured at 31 s, with a client
  sending 1 KiB a second, for a multipart 415, for a 413 (accepted above, `/api/mcp`) and for a `limit_conn`
  429. A complete body is refused at once. Refusing after the zones would free nothing: each multipart would
  take a token of the zone first — of `contact` or `login`, draining the burst of that address's real JSON
  requests — and get a 429 once the zone is drained, the 413 still coming first past a `Content-Length` bound.
  And it cannot be done with an `if` in each location: a location's `return` runs in the rewrite phase, before
  preaccess too (measured). It would take a precontent refusal (`try_files` to a named location chosen by a
  `map`), which `= /api/mcp` and `/api/assistant/` cannot use without an internal redirect that reads the body
  under another location's bound (#644's trap). In preprod and prod the ingress-nginx in front buffers
  the whole body before reaching the sidecar (`proxy-request-buffering: "on"`, `proxy-body-size: "1m"`, the
  controller's defaults, **pinned in `k8s/ingress-nginx-values.yaml`** since #712 — read on the cluster on
  2026-10-26: the controller's ConfigMap set neither, no Ingress overrode them by annotation; `helm upgrade
  --reuse-values` keeps a value set by hand, so the runbook's prerequisite n°2 gives the read-only check), so a
  slow body never reaches the sidecar slowly, and an oversized one gets the ingress's 413.
  `IngressBuffersRequestBodiesTest` requires both values in the Helm file and refuses either key anywhere else
  under `k8s/` (an Ingress annotation replaces the ConfigMap for its Ingress; measured red with
  `proxy-request-buffering: "off"` on the base Ingress). It cannot see the cluster, nor a snippet annotation
  (`configuration-snippet`, disabled by the controller's default `allow-snippet-annotations: false`). In dev nothing fronts the `web`
  nginx: accepted, dev only. Guard: `check_refused_before_zone`, run as each `limit_req` zone has just
  refused and while each `limit_conn` zone is full: the multipart must still get the 415 problem+json, then a
  bodiless witness on the same path the 429 — a witness let through means the zone reopened meanwhile (a
  token refilled: `publicapi` every 100 ms, `mcp` every second; a held connection dropped), a false green
  otherwise: a failure for `limit_conn`, « non concluant » without failure for `limit_req`, whose refills
  would make the guard flaky. And `check_body_limit` sends a multipart one byte over the bound, which must get
  the 415, not the 413. Measured red with the refusal removed, and with it moved after the zones on
  `/api/contact` (the old `/api/contact` multipart line stays green there, the new one goes red). A 429 there
  means the refusal was moved: not wrong in itself, but read this bullet first.
- **nginx rate limits need `real_ip`** (audit C7). `limit_req_zone` keys on `$binary_remote_addr`, and behind
  the ingress the sidecar's TCP peer is the ingress-nginx pod — without the `set_real_ip_from` block, the whole
  internet shares one counter, which is a self-inflicted DoS. The trusted ranges mirror Symfony's
  `trusted_proxies: private_ranges` **including `100.64.0.0/10`** (RFC 6598, the Kapsule pod range —
  the ingress pod's actual IP; it was missing until v0.14.1, so `real_ip` never applied and every zone
  really was one global counter, audit 2026-09-16 A25), with `real_ip_recursive on`.
  `docker/nginx/default.conf` and `k8s/base/backend-nginx.conf` are mirrors of each other: change both.
  **And the client IP must survive the Scaleway Load Balancer**, which is a full proxy: without
  PROXY protocol, ingress-nginx sees one of the LB's two addresses as the client and forwards *that*
  in `X-Forwarded-For`, so Symfony's `login_throttling` and quotas keyed the whole internet on two
  addresses. `k8s/ingress-nginx-values.yaml` (`use-proxy-protocol` + the
  `scw-loadbalancer-proxy-protocol-v2` annotation, applied together by one `helm upgrade`) is the
  cluster prerequisite that fixes it — see ADR 0005 D6/D7. `tools/smoke-login-throttling.sh` is what
  proves the whole chain end to end: six attempts from one machine must land on one key.
- **An `add_header` inside a `location` cancels the inheritance of *every* `add_header` of the parent
  block**, not just the one it redefines (audit A16). That is how `/assets/`, `/config.js` and `/healthz`
  of the frontend came to be served with no CSP and no HSTS, and `/index.html` with no HSTS, while the
  `server` block declared all seven headers. The frontend's seven headers now live in **one file**,
  `docker/node/security-headers.conf` (copied to `/etc/nginx/security-headers.conf`, deliberately *not*
  under `conf.d/`, which the image already includes at `http` level), `include`d at `server` level **and**
  in every `location` that sets a header of its own. Adding such a `location` means adding the `include`,
  or it ships bare. The backend sidecar cannot share that file — `backend-nginx.conf` is a ConfigMap
  mounted by `subPath`, a second file would need a second mount — so there the headers are repeated
  explicitly on `location = /healthz`; keep the two in step. `tools/audit-prod.sh` checks `/`,
  `/config.js`, `/healthz` and an asset discovered from the home page, judging only the **final**
  response. A pre-merge guard now catches this before deploy: `tools/check-frontend-image-headers.sh`
  runs the built frontend image locally and checks the 7 headers on at least one path that really
  lands in each `location`, wired into the `frontend-image-headers` CI job on every push. `/` itself
  is served by `= /index.html` (`try_files … /index.html` is an internal rewrite that redoes location
  matching), so `location /` is probed with a root static file (`/favicon.svg`) instead. A new
  `location` added to `nginx.conf` gets its path added to that script, or it isn't covered.
- **`/.well-known/security.txt` is published and expires** (RFC 9116, audit A14):
  `frontend/public/.well-known/security.txt`, served `text/plain; charset=utf-8` by the `^~ /.well-known/`
  location (declared first and with `^~` so the hidden-files rule doesn't swallow it; `charset` is not an
  `add_header`, so header inheritance stays intact). `tools/audit-prod.sh` **fails on a past `Expires`** —
  that failure *is* the renewal reminder, there is no other. Contacts point at the repository's private
  advisory form and the site's contact page, the same policy as `SECURITY.md`.
