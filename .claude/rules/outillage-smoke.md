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

The library `tools/lib/smoke-http.sh` is shared by `smoke-login-throttling.sh`, `smoke-dead-session-cookie.sh`, `smoke-mcp.sh` and
`audit-prod.sh`. It reads headers (`smoke_header`, and `smoke_headers` for a repeated one such as `Set-Cookie`).
`smoke_use_auth_config <NAME> || exit 2` is the only Basic Auth call of the audit and the three smokes (R133): it writes, then requires.
The writer `_smoke_write_auth_config` is private (R148): `smoke_use_auth_config` is its only production caller (St14-27); tests call it too.
The name is validated: refused if it starts with `_smoke_` (the library's own locals) or `SMOKE_AUTH_` (every variable `smoke_cleanup`
empties, `SMOKE_AUTH_DATA_FILE` included: emptied before the reading, it would answer « secret de Basic Auth vide »).
The value is read under `set +x` and written in a private directory; every `trap` runs `smoke_cleanup`; a second call replaces the first.
As soon as the value is read, before any validation and before the `mktemp`, the variable is unset (D3 of the fourteenth review):
once the unset has worked, no `set`, `declare -p` nor child process (`mktemp`, curl, dig, openssl) started afterwards sees it, whatever
the value, a refused one included; a private flag, `_smoke_auth_defined`, keeps “defined” for the empty-secret check.
If the variable is still defined afterwards (read-only, or a global a caller's local was hiding), the call exits 2 before anything is
written, naming the variable and never its value (R182). That refusal hides nothing (Sp16-23): a read-only variable stays visible to a
dump of the shell, and the exported global a local was hiding stays visible to child processes. Limit (C16-3): under
`shopt -s localvar_unset` the unset of a caller's local leaves it defined and empty, `[[ -v ]]` answers false, the removal passes for done
and the exported global stays visible to child processes (measured on 2026-11-01, bash 5.2.37); none of the four scripts sets that option.
An invalid name exits 2 before the reading, so the variable stays
exported: a limit, since the first guard below forces the literal call, and the `trap` of the four scripts starts no process then
(`audit-prod.test.sh` proves it for the audit).
The flag is set before the reading, read by `_smoke_require_auth_config` alone, and reset to 0 by `smoke_cleanup` and at the source.
The `rm -rf` of `smoke_cleanup`, run at the head of a second call before the reading, still receives the second name if it is exported:
the author's decision of 2026-10-31 keeps that order (a child of the same user, which logs nothing, for the time of the cleaning;
reordering would complicate the failure path of the name for no risk).
`unset` does not wipe the copy of the environment the process received at its launch, which `/proc/<pid>/environ` still shows (R148b).
The three smokes and the audit run no program before `smoke_use_auth_config` (the `source "$(CDPATH='' cd -- … && pwd)/…"` that precedes
it is builtins only: a `$(…)` copies the shell, it executes nothing): the `trap` first, then the call, then `mktemp`, `smoke_data_file`,
`openssl` and `date` (D2 of the fourteenth review). The smokes' `trap` removes the `headers_file`, empty until `mktemp` made it, then runs
`smoke_cleanup`, which removes the `-K` directory and the files of `smoke_data_file` (`smoke-mcp.sh`'s body file); the audit's is
`smoke_cleanup` alone, it has no `headers_file`. The only program left before the reading is that head `rm -rf` of a second call, above.
A value with a line break, without « : », or with an empty user or password (`:`, `:p`, `u:`) exits 2 naming the variable, not quoting it
(`_smoke_judge_value`: 0 for a value admitted, empty included, 2 for a value refused).
A user or a password made only of blanks (` :p`, `u: `, a tab) is refused like an empty one, with the same message (R148).
A variable that is defined but empty exits 2 saying « secret de Basic Auth vide » (R116, the author's decision of 2026-10-31).
An undefined one sends no Basic Auth.
`smoke_fetch` calls `curl` (binary `SMOKE_CURL`) and reads the `-K` at each call, never from `SMOKE_CURL_OPTS`; the refusals it
says before the call are judged by `_smoke_judge_auth_call` (the URL, `SMOKE_CURL_OPTS`, `"$@"`, the `-K` file, in that order).
`-q` is the **first** argument of every call, with or without the Basic Auth: curl then reads no `.curlrc` (under `CURL_HOME`,
`XDG_CONFIG_HOME` or `HOME`), which could carry `location-trusted` or a proxy. It only holds in first position.
Measured on 2026-11-01 on `curlimages/curl@sha256:08e466006f0860e54fc299378de998935333e0e130a15f6f98482e9f8dab3058` (curl 8.5.0,
the runner's, `ubuntu-latest`): `--network none`, a dummy `-K -` read from stdin, a `.curlrc` with `location-trusted` written inside
the container, `--libcurl -` printing the options. `CURLOPT_UNRESTRICTED_AUTH` shows once without `-q`, not at all with `-q` first,
and once again with `-q` second. `--libcurl -` also prints `CURLOPT_USERPWD`, which is why it cannot be admitted.
It adds `--proto =https --proto-redir =https` after the `-K`: curl then takes only `https://` URLs and redirects only to `https://`.
`--proto` binds the first request too: a URL without a scheme (`host/path`, which curl guesses as `http://`) is refused by curl.
Measured on 2026-10-31, same image: `-K … --proto =https` on `http://127.0.0.1:9/` exits 1 « Protocol "http" not supported or disabled ».
It refuses a `-K` on a URL that is not `https://` (exit 2, « non https:// »): curl sends `Authorization: Basic` on the first request.
With a `-K`, `"$@"` and each element of `SMOKE_CURL_OPTS` are judged by an **allow-list** (D1 of the fourteenth review):
`_smoke_option_arity`, a `case`, so that no variable can be changed by a caller and a second `source` leaves it intact.
It admits 9 options, the ones the three smokes really pass (found by `grep` of their calls): `-sS` (no value) and
`--connect-timeout`, `--max-time`, `-D`, `-X`, `-H`, `-d`, `--data-binary`, `-b` (one value each).
The match is exact: no prefix (curl 8.5.0 takes `--proto-r` for `--proto-redir`), no case folding, no `--option=value`,
no cluster but `-sS` (`-s`, `-sSv`), no glued value (`-HAccept: x`). A value is never judged as an option: `-H -v` sends the header « -v ».
Anything else exits 2, curl not called: `-v`, `--verbose`, `--trace*`, `--libcurl`, a second URL (the only URL is the one judged),
`--`, `-x`, `--proxy`, `--doh-url`, `--cacert`, `--unix-socket`, `--proto-default`, a redirection, a configuration file,
an option with no value after it, and an option no line of the `case` knows.
The value of 5 options is judged for one thing only: `-H`, `-d`, `--data-binary`, `-b`, `-D`.
It must not make curl read or write a local file (D2 of the fifteenth review, the author's decision of 2026-11-01, then C16-1, C16-4,
C17-3, C17-4 and CR17-7), whose content would go to the host, or whose overwriting would drop the Basic Auth or alter a body file.
`-H @…`, `-d @…` and `-b @…` (a value starting with `@`: `@f`, `@-`) exit 2; **`-b '@a=b'` too**, whatever follows the `@`: curl 8.5.0
reads the cookie file « a=b » (`CURLOPT_COOKIEFILE`, measured on 2026-11-01 on the pinned image, `--libcurl -`, `--network none`).
So does `--data-binary @<path>` unless `<path>` is, **in full**, a file `smoke_data_file` created (a string equality: no `..`, no prefix,
never the `-K` file nor another file of its directory); and so does `-b` with no « = », which curl reads as a cookie file (`-b ''` too).
So does `-D` with a value that makes curl overwrite a file the call relies on (C16-4, Q1 of the sixteenth review, then C17-4, CR17-7):
the `-K` file, its directory, a file of that directory, a file `smoke_data_file` created (the same message, `writes`), or any value with
a `..` component. Curl writes the response headers there. The `-K` is truncated to 0 bytes (measured, the transfer failing as well),
which the next call reads as an empty configuration, with no Basic Auth; a body file is replaced by the headers, `Set-Cookie` included,
which a later `--data-binary @…` would send. Measured on 2026-11-02 on the host's curl 8.14.1 (the pinned 8.5.0 image is not on this
machine and nothing else is pulled; limit: the behaviour is curl's old `-D` writing, not an 8.5.0 measure), against `127.0.0.1:9` and a
local server: `-D <body file>` leaves 0 bytes after a refused connection and the headers after a success; `-D <directory>` exits 23, the
`-K` intact; `-D <directory>/absent/../curl-auth.conf` exits 23 too (curl cannot open a path through a missing component).
The test is bash's `-ef` (same device, same inode: it follows links, with no process and no path computation), against the `-K` file,
the directory, each element of `SMOKE_AUTH_DATA_FILES`, then the value's directory (`${2%/*}`, `/` for a file of the root, `.` for a
name with no `/`, judged from the current directory) and the Basic Auth's. `-ef` follows `..` only through existing components, so a
value with `..` as a whole component is refused without computing it (`[[ "/$1/" == */../* ]]`): `a..b` and `...` are names, they pass.
`-D -` (the standard output) passes. Limits: a link to a file of that directory that does not exist yet is not seen; a relative value is
judged from the current directory, which curl reads too, since `smoke_fetch` changes none between the judgment and the call (CR17-10):
a `cd` placed between them would break that equivalence. A `TMPDIR` with a `..` component would get the smokes' own
`-D "$headers_file"` refused: exit 2, said, never silent.
A blank before the `@` changes nothing for `-H` and `-d`, measured on 2026-11-01: ` @f` is a header or a body, neither reads a file.
For `-b`, ` @a=b` is a cookie, but ` @f` has no « = »: curl reads the cookie file « ␣@f » (C17-3, measured in the seventeenth review),
which the refusal of a `-b` with no « = » above already covers.
`-b 'BEARER=x'`, `-H 'X: @y'`, `-d '{"a":1}'`, `-d ''`, `--data-binary abc`, `-D "$headers_file"`, `-D -`, `-D headers.txt` and
`-D a..b` pass: no `@` leads the value, it is a cookie, or it writes outside the files the call relies on.
`smoke_data_file`, called **after** `smoke_use_auth_config`, runs `mktemp`, keeps the path in the array `SMOKE_AUTH_DATA_FILES` and
puts it in `SMOKE_AUTH_DATA_FILE`; it prints nothing, since `"@$(smoke_data_file)"` would run it in a subshell that loses the record.
`smoke_cleanup` removes those files and empties both variables, and a second `smoke_use_auth_config` calls it first: a path from before
it is no longer admitted. Both variables are empty at the source. `smoke-mcp.sh` makes its body file with it, not with `mktemp`.
Limit (Sp16-25): `smoke_fetch` judges the file's name, not the file: a link that replaced one of those files between its creation and the
call would be read by curl as the body file, unseen. Only the smoke's own user can put it there, under `TMPDIR`: outside the model.
The message gives the **rank** of the argument, never its text (a glued value could be a secret):
« argument n° N hors des options admises avec la Basic Auth » for `"$@"`, « SMOKE_CURL_OPTS, élément n° N hors des options… » for the other;
for a value that reads a file, the rank is the value's:
« argument n° N : une valeur qui lit un fichier local est refusée avec la Basic Auth »; for a `-D` that overwrites a file of the call:
« argument n° N : une valeur qui écrit sur un fichier de la Basic Auth ou de son corps est refusée »
(R212: one text for the `-K`, its directory and a body file).
Without a `-K` nothing is judged: no identifier, nothing to leak, and production keeps its freedom.
To admit a new option, **eleven places** (St16-25, St17-24), written here once; the docblock of `_smoke_option_arity` points here:
(1) a line of its `case`, with the arity; (2) the list of that docblock; (3) the sentence « It admits <n> options » above, its number, in
digits, and its list (a case of `smoke-http.test.sh` compares the three lists and the number: a drift turns it red); (4) the measure, on
the pinned curl 8.5.0 image (`curl --manual`, then `--libcurl -`), of what the value makes curl read or write, written here; (5) if it
reads or writes a file, a line of the `case` of `_smoke_value_touches_file`, a case `refuse_file_args` (`refuse_write_args` for a write)
and a case `refuse_curl_opts_file` (`refuse_write_curl_opts`) in `smoke-http.test.sh`; (6) an « accepted » case there (`accept_args`:
the call leaves, the option and its value in the fake curl's call); (7) the docblock of `smoke_fetch`, if the option says a new
refusal; (8) the list of `audit_call_problems` in `audit-prod.test.sh`, only if the audit passes the option; (9) if its value is judged,
the sentence « The value of <n> options is judged » above, its number in digits and its list (the same case compares them with the
`case` of `_smoke_value_touches_file`); (10) the list of that function's docblock, read by the same case; (11) the enumeration of the
values refused and admitted above, which no test reads: the review of the diff does.
Once the Basic Auth is written, the library unsets the variables curl reads that would move or expose the identifier: `http_proxy`,
`HTTPS_PROXY`, `https_proxy`, `ALL_PROXY`, `all_proxy`, `NO_PROXY`, `no_proxy`, `CURL_CA_BUNDLE`, `CURL_SSL_BACKEND`, `QLOGDIR`,
`SSL_CERT_DIR`, `SSL_CERT_FILE`, `SSLKEYLOGFILE` (a TLS-inspecting proxy, a key log of TLS or QUIC left by a debugging session, the
choice of the TLS backend). Not without a Basic Auth. The names come from curl 8.5.0's `ENVIRONMENT` section (`curl --manual`); the others
of that section are left, each for a reason: `CURL_HOME`, `XDG_CONFIG_HOME`, `HOME`, `APPDATA` and `USERPROFILE` only serve the `.curlrc`,
which curl does not read under `-q`; `COLUMNS` sizes the progress bar; `SHELL` is read on VMS only.
The lower-case spellings outside the proxies (`curl_ca_bundle`, `ssl_cert_dir`, `ssl_cert_file`, `sslkeylogfile`) are not unset: curl 8.5.0
does not read them. Measured on 2026-11-01 on the pinned image (`--network none`, a dummy `-K -`, `--libcurl -`): `curl_ca_bundle`,
`ssl_cert_file` and `ssl_cert_dir` change neither `CURLOPT_CAINFO` nor `CURLOPT_CAPATH` (`CURL_CA_BUNDLE` does), and `sslkeylogfile`
creates no file where `SSLKEYLOGFILE` creates one. `curl_ssl_backend` and `qlogdir` cannot be measured there (one TLS backend, no HTTP/3).
The unset happens once, so a proxy exported **after** the call would reach curl: the call that carries the `-K` also carries
`--noproxy '*'` after `--proto-redir =https` (measured: `HTTPS_PROXY` set, `--libcurl -` prints `CURLOPT_NOPROXY, "*"`), the audit's
`CURL_OPTS` too, never `CURL_OPTS_ANONYMOUS`. Limit: the library removes what a workstation or a runner acting in good faith leaves
behind. An environment an attacker controls (`OPENSSL_CONF`, `LD_PRELOAD`, `PATH`, `SMOKE_CURL`, a `SSLKEYLOGFILE` exported again after
the call) is outside the model: it would see the identifier in the process itself, not through curl.
A `-K` file gone exits 2 saying « fichier de la Basic Auth absent », and a `curl` without an answer exits 1.
Nothing was verified in those cases.
`audit-prod.sh` sources the library only when `AUDIT_BASIC_AUTH` is defined (`[[ -v ]]`,
so the value is never expanded outside the library's `set +x`).
It computes the library's directory with bash builtins (`${BASH_SOURCE[0]%/*}`, `cd`, `pwd`), never `dirname`: the variable is
still exported there, and no process may start before the library unsets it. The call is `CDPATH='' cd --` (an inherited `CDPATH` would
make `cd` print the directory it found and break the `source`), and a script at the root, whose directory is empty, reads `/` (C14-11).
The three smokes do the same before their `source` (R165, the author's decision of 2026-10-31). The four tests share both checks:
`check_finds_library_under_cdpath` (a decoy `CDPATH`, a relative path) and `check_root_script_dir_is_slash`, which re-reads the block
of the script between its two marks, `<name>="${BASH_SOURCE[0]}"` and the `# shellcheck source=` line, runs it with `/script.sh` and
requires `/` (Sp15-22): keep the marks where they are, or the case fails naming the missing block.
Each offline test but `smoke-http.test.sh` runs its script behind fake `dirname`, `mktemp`, `date`, `openssl` and `rm` that record their
environment (`write_env_noting`; a tool it cannot find is a named `fail`, never a fake that runs `exec ""`, CR15-7). The audit's own fake
`dig`, `openssl` and `curl` record theirs in the same file, and its test requires a `== curl` line (St15-25). The shared case
`check_no_child_sees_basic_auth` requires that the record is not empty, that the script returned the expected code and that no line
carries the variable; a failure names the tools that received it.
Of the audit, the smokes and the library, the library's header is the only file that describes the variable
and lists every refused value. This rule names it too; the `Env :` headers of the audit and the three smokes point to it.
`smoke-http.test.sh` holds two guards over the audit and the three smokes, read on every line, comments and heredocs included.
The first: the substring `AUDIT_BASIC_AUTH`, even inside a longer word, appears only as
`if [[ -v AUDIT_BASIC_AUTH ]]; then` (audit) and the library call, once per script.
The second: no line lists the environment or reads a variable by name, the library included (D3 of the thirteenth and fourteenth reviews).
It refuses `declare`, `typeset`, `local`, `export` and `readonly` with no name (bare, or followed by options in `-` or `+`, or `--`, only:
`declare -x`, `declare +x`, `export --`, `declare -x --`, `typeset +r`)
and, with a `p` option in **any** cluster of options, `declare -p`, `export -p`, `declare -g -p NAME`; `declare`, `typeset` and `local`
with an `n` option in any cluster (a nameref reads a variable by name: `local -r -n ref=NAME`);
`compgen` with `-v`, `-e`, `-A variable` or `-A export`; `set` after any word (`if set`, `then set`, `! set`, `time set`, `do set`) or a
quote, a backtick or a backslash (`bash -c 'set'`, `` x=`set` ``, `\set`), bare or followed by a redirection, a pipe, `;`, `&&`, `||`, a
quote, a backtick or a backslash (`set\`); `eval`; `printenv`; `env` as a command; `${!`; `ps` with the BSD option `e` in any position
(`ps e`, `ps eww`, `ps -p $$ e`, `ps -o args= -p $$ ewww`) **or with any option in `-` outside an allow-list** (C17-1): `-o` and `-p`
(their value is skipped), `-e`, `-f`, `-ef` and `-aux` pass (every process, not its environment: `ps -ef`, `ps -aux` and
`ps -p $$ -o comm=` pass), every other one refuses the line, the long ones too (`ps -auxe` and `ps -eaux`, which procps reads as the
BSD options `aux` and `e` behind a dash, `ps -A e`, `ps -www`, `ps --cols 200`); a quote, a backtick or a backslash glued to a word is
dropped first, and a final backslash refuses the line (`bash -c 'ps e'`, `` x=`ps e` ``, `ps -p $$ \`); and the word `environ`, in any
case (`ENVIRON` of awk, C17-2), prose included, however `/proc/<pid>/environ` is written (`cat /proc/self/environ`,
`cd /proc/self && cat environ`, `"$p/environ"`; R148b, St14-29).
French prose writes « à peu près » or « près de », never that word, in a comment of these files.
Such a bare word ends its command at the end of the line, `;`, `&`, `|`, `)`, `#`, a redirection, a backslash (`declare\`: the next line
carries on the command), or at a quote or a backtick **glued** to the word or to its last option (C16-2: `bash -c 'declare'`,
`bash -c "export"`, `bash -c 'declare -x'`, `` x=`export` ``, `'declare'`, `\declare`). After a blank, a quote ends it only if a word end
follows (`bash -c 'declare -x '`): `export -n -- "$n"` opens the name, it passes.
`export -n -- NAME`, `declare +x NAME=1`, `readonly LIMIT=3`, `local -r name="$1"`, `set -e`, `set +x`, `bash -c 'set -e'`,
`if set -o | grep -q xtrace`, `echo 'declare keyword'`, `` x=`set -e` ``,
`bash -c 'export -n -- NAME'`, `x="${env}"` and `environment` pass.
The forms of C16-2 and `ps -auxe` / `ps -eaux` that print are **measured** in `smoke-http.test.sh`: run by `bash -c` under `env -i` with
the one dummy variable (a random value, never an argument of a process: the output goes through a pattern file, `grep -F -f`, and is read
by `values_found_in`), each prints the value, and the same list is then refused by the guard. A witness variable the test itself exports
must not appear in that output (C17-8): the measure inherits nothing. Never run such a form in a session outside `env -i` with a dummy.
**The exemption is one line**: `_smoke_value="${!_smoke_name:-}"`, the reader's one reading by name, in the body of
`_smoke_write_auth_config`, compared by equality. Anything else added in that body is judged like the rest of the library,
and so is that line anywhere else. A word of prose that is also one of these commands (`local` ending a sentence, a `set` alone between
quotes: `echo 'set'`, `echo "declare"`) is rephrased. Limit: the guard aims at good-faith slips (a debug trace left behind). A list of
patterns is never complete against a deliberate bypass, which the review of the diff stops: a word cut inside by a quote or a backslash
(`dec""lare`, `d\eclare`), an alias, a name assembled before an `eval`, perl's `%ENV` and PHP's `getenv()` and `$_ENV` (no guarded
script runs either); « no line lists the environment » holds under that limit only.
Each guard is proven on altered copies of a script: every bypass is refused, its neighbours pass.
A library that is missing or does not parse exits 2 saying « introuvable ou invalide », with bash's own error left on stderr
(#748, C6-7); the production audit, with the variable undefined, never reads it.
Its redirection probes (section 3) leave without the Basic Auth (`CURL_OPTS_ANONYMOUS`): curl sends `Authorization: Basic`
on the first request whatever the scheme, so a `-K` there would put the preprod credential in clear on `http://` (#750, R115).
Every call that carries the `-K` also carries `--proto =https --proto-redir =https --noproxy '*'` (the audit's
`CURL_OPTS`), and none without it carries any. Every `curl` of the audit, the anonymous probes included, starts with
`-q` (R169): `audit-prod.test.sh` reads each call of the fake curl (`-q` first, only the audit's options `-sS`,
`--connect-timeout`, `--max-time`, `-K`, `--proto`, `--proto-redir`, `--noproxy`, `-D`, `-o`, `-L`, `-w`, `-H`, one URL
last, `https://` when the call carries a `-K`) and the source (every `curl` command followed by `-q`).
The anonymous probes aim at `http://` URLs on purpose, so a `--proto =https` on them would refuse the very redirect they check.
A probe answered 401 says the host asks for the Basic Auth when the audit carries it (preprod; it leaves without it on purpose),
instead of reading as a broken redirect; against production, where nothing is carried, a 401 is printed without that hint (R149).
The hint reads the audit's own flag `audit_carries_basic_auth`, set after `smoke_use_auth_config` succeeds, never `SMOKE_AUTH_CONFIG`:
an inherited value would set that one with nothing carried (CR14-3).
A probe is one `curl -w '%{http_code} %{redirect_url}'`, read in two fields: the code and the target come from the same response.
Offline test: `tools/tests/audit-prod.test.sh`, fake `dig`/`curl`/`openssl`.
The cases the offline tests share are `tools/tests/lib/smoke-cases.sh`'s, called as `run_case <case> <label> <rc|-> CASE_ERR=…`: their
variables are locals of `run_case`, declared `local +x`. What `+x` guarantees: a value `run_case` sets, a generated Basic Auth included,
is not exported, so no process a case starts receives it (a local keeps the export attribute of the global it hides, which `+x`
removes). What it does not guarantee (CR15-1): a name the caller already exported still reaches a child, with the caller's value, never
the case's. Measured on the host's bash 5.2.37; the runner's, 5.2.21, cannot be run offline, so there only the last case of
`smoke-http.test.sh` stands for it: it marks every value it gives `run_case` with a generated token and requires that no child sees one.
The names a call may give are the array `CASE_NAMES`. A variable a case needs that the call leaves out is a named `fail`, never an exit
of the test; a case that reads no exit code takes `-` for `<rc>` (`CASE_RC` stays empty), never a dummy `0`.
`case_vars_set <err> <calls> <out> [<value>]` sets the array `CASE_VARS` that most calls pass as `"${CASE_VARS[@]}"`, so the names are
written once. A generated value is never an argument of a process (`ps` would show it): `values_found_in <value>… -- <file>…` searches it
through a mode-600 pattern file (`grep -F -f`), one pattern per non-empty line (a value with a leading, trailing or doubled line break
would write an empty pattern, which matches every line; a value that is empty or only line breaks exits 2, CR17-5), and
`call_carrying <file> <rank> <fragment>…` finds a fake curl call by what it carries, never by its line number.
`check_hollow_basic_auth_values` (R212) runs the script itself, for the three hollow values (`:`, `<token>:`, `:<token>`): the call names
`CASE_RUNNER`, a function of the test that launches the script with `AUDIT_BASIC_AUTH` set to its first argument and leaves the exit
code in `rc`, as each test's `run` does; `check_http_base_served_without_basic_auth` is the case of an `http://` base without a Basic Auth.
