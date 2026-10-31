---
paths:
  - "tools/lib/smoke-http.sh"
  - "tools/smoke-*.sh"
  - "tools/audit-prod.sh"
  - "tools/tests/smoke-*.test.sh"
  - "tools/tests/audit-prod.test.sh"
  - "tools/tests/lib/smoke-cases.sh"
---

# Smokes and audit tooling — the shared library, the Basic Auth, the guards

> Moved from `.claude/rules/deploiement-nginx.md` (#748, D5 of the thirteenth review), the bullet « Nine nginx rate-limit zones »,
> which keeps what these scripts check of the zones and the headers. The index of every rule is at the top of `.claude/CLAUDE.md`.

The library `tools/lib/smoke-http.sh` is shared by `smoke-login-throttling.sh`, `smoke-dead-session-cookie.sh` and this one.
It reads headers (`smoke_header`, and `smoke_headers` for a repeated one such as `Set-Cookie`).
`smoke_use_auth_config <NAME> || exit 2` is the only Basic Auth call of the audit and the three smokes (R133): it writes, then requires.
The file writer `_smoke_write_auth_config` is private (R148): only `smoke_use_auth_config` calls it; the test calls it too, for its cases.
The name is validated: refused if it starts with `_smoke_` (the library's own locals) or `SMOKE_AUTH_` (what `smoke_cleanup` empties).
The value is read under `set +x` and written in a private directory; every `trap` runs `smoke_cleanup`; a second call replaces the first.
As soon as the value is read, before any validation and before the `mktemp`, the variable is no longer exported (`export -n`, C13-5):
no process started afterwards (`mktemp`, curl, dig, openssl) receives it, whatever the value, a refused one included.
The `rm -rf` of `smoke_cleanup`, run at the head of a second call before the reading, still receives the second name if it is exported:
the author's decision of 2026-10-31 keeps that order (a child of the same user, which logs nothing, for the time of the cleaning;
reordering would complicate the failure path of the name for no risk).
`export -n` does not wipe the shell's own copy of its environment, which `/proc/<pid>/environ` still shows (R148b).
A value with a line break, without « : », or with an empty user or password (`:`, `:p`, `u:`) exits 2 naming the variable, not quoting it.
A user or a password made only of blanks (` :p`, `u: `, a tab) is refused like an empty one, with the same message (R148).
A variable that is defined but empty exits 2 saying « secret de Basic Auth vide » (R116, the author's decision of 2026-10-31).
An undefined one sends no Basic Auth.
`smoke_fetch` calls `curl` (binary `SMOKE_CURL`) and reads the `-K` at each call, never from `SMOKE_CURL_OPTS`.
It adds `--proto =https --proto-redir =https` after the `-K`: curl then takes only `https://` URLs and redirects only to `https://`.
`--proto` binds the first request too: a URL without a scheme (`host/path`, which curl guesses as `http://`) is refused by curl,
and so is the `url` of a second `-K`.
It refuses a `-K` on a URL that is not `https://` (exit 2, « non https:// »): curl sends `Authorization: Basic` on the first request.
With a `-K`, it also refuses an argument of `"$@"` (exit 2, « option ou URL refusée », curl not called, the argument never quoted) that is:
- `--`, alone (what follows is a URL);
- a long option, with or without `=value`, whose lower-cased name is a prefix of `location`, `location-trusted`, `url`, `proto`,
  `proto-redir`, `config`, `next`, `variable`, `insecure`, `resolve`, `connect-to` or `expand-`, or starts with `expand-`;
- a short-option cluster carrying `L`, `K`, `k` or `:` (`-L`, `-0L`, `-4L`, `-#L`, `-sK`, `-k`, `-:`);
- a value that **starts** with a scheme other than `https://`; `-H 'Origin: https://…'` and a JSON body quoting `http://` pass.
Why a prefix: curl 8.5.0, the runner's (`ubuntu-latest`, Ubuntu 24.04), takes an unambiguous abbreviation and ignores the case.
`--proto-r` and `--LOCATION-T` ran; a prefix curl finds ambiguous is refused too. `--progress-bar` and `--proto-default` pass.
`--connect-timeout` and `--expect100-timeout` pass too.
Accepted limit: a value glued to a short option (`-HAccept-Language: fr`) holding `L`, `K`, `k` or `:` is refused wrongly.
No caller glues a value to a short option.
Each element of `SMOKE_CURL_OPTS` is judged the same way, with its own message (exit 2, « SMOKE_CURL_OPTS porte une option refusée »).
Why: a `--location-trusted` there would carry the `-K` to any `https` host. Not judged without a `-K`.
`--proto` and `--proto-redir` are refused in `"$@"`: curl keeps the last value given, so a caller's would win over the pair set before it.
A `-K` file gone exits 2 saying « fichier de la Basic Auth absent », and a `curl` without an answer exits 1.
Nothing was verified in those cases.
Measured on 2026-10-31, on `curlimages/curl@sha256:08e466006f0860e54fc299378de998935333e0e130a15f6f98482e9f8dab3058` (curl 8.5.0):
`--network none`, a dummy `-K -` read from stdin, no secret. `--proto-r =all --version` exits 0 (the abbreviation is taken, the threat is real).
With `-K … --proto =https --proto-r =all http://127.0.0.1:9/`, curl exits 1 « Protocol "http" not supported or disabled in libcurl ».
`--proto =https` holds whatever the abbreviation: it is what closes the scheme, the refusals above close the rest.
`audit-prod.sh` sources the library only when `AUDIT_BASIC_AUTH` is defined (`[[ -v ]]`, so the value is never expanded
outside the library's `set +x`).
It computes the library's directory with bash builtins (`${BASH_SOURCE[0]%/*}`, `cd`, `pwd`), never `dirname`: the variable is
still exported there, and no process may start before the library removes the export.
The three smokes do the same before their `source` (R165, the author's decision of 2026-10-31).
Each offline test, the audit's included, records the environment of a fake `dirname` (`write_env_noting_dirname`).
Of the audit, the smokes and the library, the library's header is the only file that describes the variable
and lists every refused value. This rule names it too; the `Env :` headers of the audit and the three smokes point to it.
`smoke-http.test.sh` holds two guards over the audit and the three smokes, read on every line, comments and heredocs included.
The first: the substring `AUDIT_BASIC_AUTH`, even inside a longer word, appears only as
`if [[ -v AUDIT_BASIC_AUTH ]]; then` (audit) and the library call, once per script.
The second: no line lists the environment or reads a variable by name, the library included (D3 of the thirteenth review).
It refuses `declare`, `typeset`, `local`, `export` and `readonly` with no name (bare, or followed by options only: `declare -x`)
and, with a `p` option, `declare -p`, `export -p`; `declare`, `typeset` and `local` with an `n` option (a nameref reads a variable by name);
`compgen` with `-v`, `-e`, `-A variable` or `-A export`; `set` bare or followed by a redirection or a pipe; `eval`; `printenv`;
`env` as a command; `${!`; `ps` with an option that carries `e` (`ps e`, `ps eww`, `ps -ef`); and the word `environ`,
however `/proc/<pid>/environ` is written (`cat /proc/self/environ`, `cd /proc/self && cat environ`, `"$p/environ"`; R148b).
`export -n -- NAME`, `readonly LIMIT=3`, `local name="$1"`, `set -e`, `set +x`, `x="${env}"` and `environment` pass.
**The exemption is one line**: `_smoke_value="${!_smoke_name:-}"`, the reader's one reading by name, in the body of
`_smoke_write_auth_config`, compared by equality. Anything else added in that body is judged like the rest of the library,
and so is that line anywhere else. A word of prose that is also one of these commands (`local` ending a sentence) is rephrased.
Limit: the guard aims at good-faith slips (a debug trace left behind). A list of patterns is never complete against a
deliberate bypass, which the review of the diff stops; « no line lists the environment » holds under that limit only.
Each guard is proven on altered copies of a script: every bypass is refused, its neighbours pass.
A library that is missing or does not parse exits 2 saying « introuvable ou invalide », with bash's own error left on stderr
(#748, C6-7); the production audit, with the variable undefined, never reads it.
Its redirection probes (section 3) leave without the Basic Auth (`CURL_OPTS_ANONYMOUS`): curl sends `Authorization: Basic`
on the first request whatever the scheme, so a `-K` there would put the preprod credential in clear on `http://` (#750, R115).
Every call that carries the `-K` also carries `--proto =https --proto-redir =https` (`CURL_OPTS` here), and none without it carries either.
The anonymous probes aim at `http://` URLs on purpose, so a `--proto =https` on them would refuse the very redirect they check.
A probe answered 401 says the host asks for the Basic Auth when the audit carries it (preprod; it leaves without it on purpose),
instead of reading as a broken redirect; against production, where nothing is carried, a 401 is printed without that hint (R149).
A probe is one `curl -w '%{http_code} %{redirect_url}'`, read in two fields: the code and the target come from the same response.
Offline test: `tools/tests/audit-prod.test.sh`, fake `dig`/`curl`/`openssl`.
The cases the offline tests share are `tools/tests/lib/smoke-cases.sh`'s, called as `run_case <case> <label> <rc> CASE_ERR=…`:
their variables are locals of `run_case`, never exported (a generated Basic Auth must not reach the processes a case starts),
and a variable a case needs that the call leaves out is a named `fail`, never an exit of the test.
