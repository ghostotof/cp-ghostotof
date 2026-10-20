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
  only**: its key, from a `map` on `"$request_method:$http_mcp_session_id"`, is the address for a `POST` without
  `Mcp-Session-Id` and empty otherwise (an empty key is not counted); an `initialize` carrying an invented id to
  escape it is refused by the SDK, nothing written (measured by the audit of #644). The location has its own
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
  tighter bound would leave the session-bound tests without an HTTP path to it). **The site is IPv4 only** (no
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
  zone (`publicapi`'s burst is 200). Measured in dev on 2026-10-20: the 429 at the 20th `GET` (the `initialize`
  and the `DELETE` took two tokens), and the seventh `initialize` in a row refused by `mcpsession`.
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
  directives in each `MIRRORED_LOCATIONS` block (`= /api/mcp`, `fastcgi_pass`'s upstream masked), comments
  ignored — and each `BODY_LIMITS` bound (413 one byte over, none at the bound, with `Content-Length` and in
  `chunked`).
  **`limit_conn` is not exercised** (it would need
  an upstream that holds the connection, and there is no php-fpm in the job).
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
