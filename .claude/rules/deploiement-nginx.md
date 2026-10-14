---
paths:
  - "k8s/**"
  - "docker/**"
  - ".github/workflows/**"
  - "tools/**"
  - "backend/migrations/**"
  - "backend/config/packages/cache.yaml"
  - "backend/config/packages/lock.yaml"
  - "backend/config/packages/rate_limiter.yaml"
  - "backend/config/packages/monolog.yaml"
  - "backend/src/Shared/Infrastructure/Lock/**"
  - "frontend/public/.well-known/**"
---

# Deployment invariants — nginx (rate limits, headers, security.txt)

> Split out of `.claude/rules/deploiement.md` (#605), whose index lists every deployment rule. The index of every rule is at the top of `.claude/CLAUDE.md`.

- **Six nginx rate-limit zones, two different jobs.** `contact` (10 r/m), `pwsetup` (20 r/m),
  `baseaccess` (20 r/m, issue #77 — each call signs an RS256 JWT), `login` (10 r/m, burst 10,
  ADR 0005 — the backstop under Symfony's `login_throttling`, which is the real ceiling) and
  `assistant` (10 r/m, burst 5, plus `limit_conn assistantconn 1` — each call is billed and each
  stream holds one of a pod's 8 php-fpm workers; the zones live in each sidecar, so an IP gets N times
  these ceilings with N backend pods) protect a *side effect* — sending mail, guessing a token,
  minting a token, guessing a password, spending money.
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
  zone of the confs missing from its `ZONES` list. **`limit_conn` is not exercised** (it would need
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
