---
paths:
  - "docker/**"
  - "frontend/scripts/**"
  - "frontend/public/**"
  - "Makefile"
  - ".dockerignore"
---

# Docker images (backend and frontend)

> Moved verbatim from `.claude/CLAUDE.md` (#346), section « Architecture › Dockerfile, Frontend build/deploy ». The index of every rule is at the top of `.claude/CLAUDE.md`.

### Dockerfile (`../docker/php/Dockerfile`) — multi-stage

`base` (PHP + extensions: intl, pdo_pgsql, zip, sockets, amqp, opcache) is shared by every target so dev and
prod run the identical PHP engine:

- **`dev`** — adds symfony-cli, bash/git/curl; creates a `dev` user matching the host `UID`/`GID` (from `../.env`)
  so bind-mounted files stay host-editable. Code is bind-mounted, not copied.
- **`vendor`** — isolated `composer install --no-dev` layer, cached on `composer.json`/`composer.lock` only.
- **`production`** — copies `vendor` + `../backend` source into the image (no mount), runs as a fixed non-root
  `app` user (UID 10001), read-only filesystem except `var/`. This is the real deployable artifact. It also
  **builds `config/watch/package-manifest.json`** here (`bin/build-package-manifest.php`, no Symfony kernel):
  the build context is the repo root, so this is the one place `composer.lock` and
  `frontend/package-lock.json` coexist — the manifest describes exactly what the image deploys and cannot
  drift from it. Keep that `COPY`/`RUN` pair *after* the big install layer so an npm bump doesn't invalidate it.
  **A failed warm-up fails the build** (issue #474). The stage replays `post-install-cmd` (`cache:clear` in
  `prod` with an empty `APP_SECRET`, then `assets:install`) under `set -e` and without `|| true`, then
  requires at least one **file** under `var/cache/prod/pools/system` (`find … -type f`, not `ls -A`:
  PhpFilesAdapter creates its namespace subdirectory before writing anything), failing with a message
  naming #474: the `seed-cache-system` initContainer copies that directory into every pod. The `|| true`
  used to swallow every failure — the first version of #468 (a key derived in a constructor) built an image
  without that directory, green. So a service the warm-up builds must not need a runtime secret in its
  constructor: derive it on first use. Nothing catches that before `build-images` on a `release/*` branch
  (feature branches never build the backend image). `ProductionImageWarmUpTest` reads the `RUN` as Docker
  runs it (comments dropped, continuations joined): `set -e`, no `||` after the script, the check on
  `SystemCachePodVolumeTest::MOUNT_PATH` after it. Proven both ways with a real `make build-prod` (the #468
  defect now fails the `RUN`).
  **The image loads a compiled `.env.local.php`, never a parsed `.env`** (issue #489): the same `RUN` runs
  `composer dump-env prod` after the warm-up, while Composer is still there (≈ 45 µs saved per request and
  per command, measured, the `.env` parse against an `include` served by OPcache). Three facts make it safe,
  each proven on the built image, FPM request included. **A pod variable still wins**, empty or not:
  `Dotenv::populate()` never replaces a variable already set, exactly as it did over `.env` — never set the
  runtime's `dotenv_overload`. **A `${VAR}` of `.env` stays resolved at runtime**: Flex compiles it into
  `($_ENV['VAR'] ?? $_SERVER['VAR'] ?? '')` (`MESSENGER_TRANSPORT_DSN`) — **but only for a variable absent
  from the compiling process**: Dotenv reads `$_ENV` and `$_SERVER` first, so an `ARG`, an `ENV` or a base
  image variable of the same name would be frozen into the image (reproduced: the dev container's real
  `RABBITMQ_PASSWORD` ended up in the file). Hence `env -i PATH="$PATH" HOME="$HOME"
  COMPOSER_ALLOW_SUPERUSER=1 composer dump-env prod`, exactly — no `--empty` either, which would keep
  `APP_ENV` alone and drop every default of `.env` —, and `.env` must never reference one of those three.
  **Only `backend/.env` feeds it**: `dump-env prod` also reads `.env.local`, `.env.prod` and
  `.env.prod.local`, all three excluded by `.dockerignore`, like a workstation's `.env.local.php` (no
  `.env.<env>` is versioned, see `.claude/CLAUDE.md`). Keep `.env` in the image: when the pod's `APP_ENV`
  differs from the compiled one, Dotenv falls back to parsing it. `ProductionImageCompilesDotenvTest` pins
  the command, its place, that no other instruction touches the file, the references of `.env` and the
  exclusions (the dev container mounts `.dockerignore` read-only for it: `up -d --force-recreate backend`
  after editing it); `CompiledDotenvNeverMasksPodVariablesTest` runs that very command, read from the
  Dockerfile, on a throwaway project holding the real `.env` with a trap value in the "build" environment,
  then boots the real `vendor/autoload_runtime.php` on the result in a child process whose environment is a
  pod's — needs `composer` on the `PATH`, as in the dev container and CI.
- **`preprod`** — built **`FROM production`** (not a parallel build) so its application layers are byte-identical
  to prod; only adds Xdebug (**inert in the image**, see `.claude/rules/deploiement.md`) and verbose logs.
  Pipeline order is dev → preprod → prod regardless of declaration order in the Dockerfile.
- **`php.prod.ini` is the only source of `zend.exception_ignore_args = On`** (issues #278, #616): the
  deployed traces carry no call arguments. `ExceptionArgumentsHiddenInProductionTest` pins the value,
  requires the file to be a loaded ini (directly under `conf.d/`) copied by `production` or one of its
  ancestors, and refuses every other way the runtime could contradict it. `tests/Support/PhpImageBuild`
  reads the deployed stages (`production`, `preprod`, their ancestors) and keeps **one copy per stage**
  (`ConfigurationCopy`): no other file they copy under `/usr/local/etc/` may name the directive —
  `xdebug.preprod.ini` loads *after* `zz-app.ini`, a pool's `php_admin_flag` beats any ini — nor
  `include` another; any other write there, by path or by `$PHP_INI_DIR` (`--from`, several sources,
  JSON form, a `RUN`, a `WORKDIR`), fails, as do a stage named by a variable, a stage name declared
  twice and a heredoc. Those stages never name `PHP_INI_SCAN_DIR` nor `PHPRC`; their `CMD`/`ENTRYPOINT`
  is JSON and **exactly `php-fpm` with `-F`**, an allow-list (a deny-list missed `-nF`, `-cx.ini`, `-p`,
  the shell form). In `k8s/`, every file but `*.md` (`KubernetesManifests::tree()->linesMatching()`,
  comments included) names neither the directive, `PHP_INI_SCAN_DIR`, `PHPRC`, nor a
  `PHP_VALUE`/`PHP_ADMIN_VALUE` FastCGI param. A new config file needs no change to the test: it is
  found through its `COPY`. Not seen: the official image's own files (`php-fpm.d/docker.conf`,
  `www.conf`) and the `docker-php-ext-*.ini` that `docker-php-ext-enable` writes (one `extension=`
  line each), a `-n`/`-c`/`-y`/`-p` in a pod's command, keys an ExternalSecret would import by `dataFrom`.

- **PHP parses no multipart body, in every image** (issue #686, follow-up of CVE-2026-109618): `php.prod.ini`
  and `php.dev.ini` set `max_multipart_body_parts = 0`, `file_uploads = Off`, `post_max_size = 1M` (nginx's
  `client_max_body_size`) and `user_ini.filename =` (empty), and name neither `upload_max_filesize` nor
  `enable_post_data_reading`. The second barrier behind the sidecar's 415 (`.claude/rules/deploiement-nginx.md`,
  « Multipart bodies never reach PHP »). `max_multipart_body_parts = 0` refuses multipart at its first named
  field on **both** paths PHP parses it on: the start of a POST, and `request_parse_body()`, which Symfony calls
  for PUT, DELETE, PATCH and QUERY in `Request::createFromGlobals()` (a `RequestParseBodyException` Symfony
  catches) — measured on PHP 8.5; the first part's headers and parts without `Content-Disposition` are still
  walked, bounded by `post_max_size` (audit of #686, `main/rfc1867.c`). `file_uploads = Off` drops the file parts
  (no temporary file); alone it leaves fields parsed. `post_max_size` bounds form parsing only: a JSON body read
  from `php://input` was never bounded by it (measured: 3 MB read under `1M`), nginx is its only bound. The empty
  `user_ini.filename` keeps a `.user.ini` from changing those `PHP_INI_PERDIR` settings (closed in production by
  the read-only root, open in dev where `public/` is mounted). **`application/x-www-form-urlencoded` stays parsed,
  on purpose** (decided on 2026-10-22, review of #686): not the advisory's parser, and the token endpoint of
  phase 2's OAuth 2.1 server (ADR 0006 D3) reads it from `$_POST` — hence no `enable_post_data_reading = Off`,
  which would empty `$_POST` and close nothing `max_multipart_body_parts` does not; never add it back without
  revisiting that endpoint. `max_input_vars` and `max_input_nesting_level` keep PHP's defaults: they also bound
  `$_GET` and `$_COOKIE`. `FormBodyParsingDisabledTest` pins the values in both files, the two absent
  directives, that no other configuration file of the deployed images (exact name, `php_admin_value[…]` form
  included) nor any manifest of `k8s/` names them (Adminer's own image exempted), and **measures the effect**:
  PHP's built-in server under each file alone (`-n -c`, launched through `ChildPhpProcess`) with the router
  `tests/Security/Fixtures/form-body-probe.php` — multipart refused on POST, PUT, DELETE and PATCH, JSON and
  urlencoded still read. The built-in server answers QUERY with a 501 without running the router, so QUERY is
  probed by nginx's guard only. The dev container picks `php.dev.ini` up at `make build` (it is copied, not
  mounted).

The build context is the **repo root**, so `.dockerignore` is what keeps things out of it, and two entries
are there for secrecy rather than size (audit A13/A22): `backend/config/jwt/` — a workstation that has
generated its dev RS256 keypair would otherwise ship `private.pem` in an image layer, and the deployed
keys come from the `jwt-keys` Secret at runtime anyway — and `backend/.env.test`, `.claude/`, `tasks/`,
`.superpowers/`, which carry local configuration, real identity (`CLAUDE.local.md`) or uncorrected audit
findings. Never `COPY` something out of one of those; widen the ignore list instead. Development tooling
files are also excluded: `backend/tests/`, PHPStan/Psalm/Rector/PHPUnit/LSP configuration files, and
Composer/Flex recipe files — the production image has no test suite, and adding a new quality tool means
adding its config file to the ignore list.

### Frontend build/deploy

`../docker/node/Dockerfile` mirrors the same idea: in dev the plain `node` image runs `npm install && npm run dev`
directly (no image build). For deployable images, the API URL is a **runtime** setting, not a build-time one:
`docker-entrypoint.sh` runs `envsubst` on `config.template.js` using the container's `API_URL` env var, producing
`/usr/share/nginx/html/config.js` (served no-cache, loaded by `index.html` before the app bundle) that the app reads
via `window.__APP_CONFIG__` (`frontend/src/infrastructure/config/getApiUrl.ts`, falling back to Vite's
`import.meta.env.VITE_API_URL` for `npm run dev`, which never serves `config.js`). This means a single frontend
image — like the backend — is built once and promoted from preprod to prod unchanged, only the `API_URL` env var
differs per environment; `make build-front-prod`/`build-front-preprod` no longer take an `API_URL` argument.

**The version shown in the footer is the opposite case, and deliberately so**: it is a property of the *image*,
not of the environment, so it is fixed at **build** time — `make build-front-*` passes `--build-arg
APP_VERSION=$(TAG)` (the pipeline's `<version>-<sha>`), `docker/node/Dockerfile` exports it as
`VITE_APP_VERSION`, Vite inlines it, and `infrastructure/config/getAppVersion.ts` (the mirror of `getApiUrl.ts`)
parses it into `{version, build, releaseUrl}`. `AppFooter.vue` renders `v0.17.0` as a link to the GitHub release
(build sha in the `title`); a tag that isn't a release (a local build on a bare sha) shows as plain text, and an
empty value (`npm run dev`) shows nothing. Don't move it to `config.js`: a promoted image *must* announce the same
version in preprod and prod, which is exactly what build-time gives for free.

**Share cards** (`frontend/scripts/og/`): one HTML template rendered by system Chrome + ImageMagick
(`npm run og:generate`, no Playwright — see the script's header) into **two variants** of the same design
that differ only by headline and size: `frontend/public/og.png` (1200×630, the site's `og:image`,
headline = real name, the owner's deliberate choice for the site) and `.github/social-preview.png`
(1280×640, headline = the `ghostotof` pseudonym, because the repo is pseudonymous end to end: URL,
LICENSE, README). Both are committed and both are regenerated by `test-frontend` (script control on
every push) and `build-images` (before the frontend image is built); the GitHub one is also uploaded
as the `social-preview` artifact of each release. **GitHub has no API for the social preview**: the
file is uploaded by hand in Settings → General → Social preview, so a change to the template means
re-uploading it — the artifact exists to make that one download away. Never put the real name in the
GitHub variant, and never serve it from the site (it lives under `.github/`, not `frontend/public/`).

