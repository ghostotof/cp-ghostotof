---
paths:
  - "backend/src/Mcp/Connection/Application/McpClientAdministrator*.php"
  - "backend/src/Mcp/Connection/Application/AdministeredMcpConnection*.php"
  - "backend/src/Mcp/Connection/Application/McpConnectionStatus.php"
  - "backend/src/Mcp/Connection/Presentation/ApiResource/BackofficeMcp*.php"
  - "backend/src/Mcp/Connection/Infrastructure/ApiPlatform/BackofficeMcp*.php"
  - "backend/src/Mcp/Connection/Infrastructure/Validator/ValidMcpClientFields*.php"
  - "frontend/src/presentation/pages/admin/AdminMcp*.vue"
  - "frontend/src/**/admin/mcp/**"
---

# Backend and frontend — `Mcp/Connection`: the backoffice of MCP clients and connections (#652)

> Decisions: `docs/adr/0006-serveur-mcp.md`; spec `.claude/specs/0007-mcp-server.md`, phase 2 (issue #652: the
> list of every connection, the CRUD of pre-registered clients, `ROLE_SUPER` only). Vocabulary: `CONTEXT.md`,
> « Accès MCP » (« Client pré-enregistré », « Suppression d'un client MCP »). The index of every MCP rule is at the
> top of `.claude/rules/mcp.md`, the index of every rule at the top of `.claude/CLAUDE.md`. The audit events are
> described in `.claude/rules/security-authentication-audit.md`; the backoffice pattern in `.claude/rules/backoffice-api.md`.

- **The routes.** `BackofficeMcpClientResource`: `GET` and `POST /api/backoffice/mcp/clients` (`POST` answers 201),
  `GET`, `PUT` (200) and `DELETE` (204) `/api/backoffice/mcp/clients/{id}` — five operations, flat DTOs, each item
  operation with `requirements: ['id' => Requirement::UUID]` and its explicit provider. `BackofficeMcpConnectionResource`:
  `GET /api/backoffice/mcp/connections`, no item route. **No guard of their own**: the `access_control` entry
  `^/api/backoffice(/|$)` (`ROLE_SUPER`) is the only one, none of them in `PUBLIC_PATHS` nor in `BASE_TIER_PATHS`
  (`ApiRouteExposureTest`). On the frontend, `/admin/mcp/clients` and `/admin/mcp/connections`
  (`AdminMcpClientsPage.vue`, `AdminMcpConnectionsPage.vue`).

## Decisions (one line each)

- **D-A — Removing a client deletes it for real.** `McpClientAdministrator::remove()` locks the client
  (`findOneByIdForUpdate()`), then its active connections (`McpConnectionRepositoryInterface::lockActiveOfClient()`,
  `FOR UPDATE … ORDER BY id`; « active » means `revoked_at IS NULL`, an expired connection the purge has not marked yet included), reads what the audit
  needs, then deletes the client: `ON DELETE CASCADE` takes its connections, codes and tokens. A CIMD client can be
  removed too. Retried once on `40P01` (`DeadlockRetryInterface`), a `55P03` surfaces; after the COMMIT, one
  `mcp-connection-revoked` per cut connection, then `mcp-client-removed`, through `FailSafeAudit`.
- **D-B — The cause `client-removed` lives in the log only** (`McpClientAdministrator::REMOVAL_CAUSE`). The rows it
  would describe are deleted (D-A), so no row ever carries it: no case of `RevocationCause`, no widening of
  `chk_mcp_connection_revocation_cause`, **no migration for this cause** (#652's two migrations,
  `Version20261120120000` and `Version20261120130000`, only add the partial indexes of D-D's reads).
- **D-C — The consent reads a pre-registered client under a shared lock.** `ConsentService::grant()` re-reads it by
  `McpClientRepositoryInterface::findOneByClientIdForShare()` (`LockMode::PESSIMISTIC_READ`, `FOR SHARE`, with
  `HINT_REFRESH`) and re-checks `allowsRedirect()` on that instance; a client removed meanwhile is refused
  (`AuthorizationRequestRejectedException::clientNoLongerRegistered()`), so no connection is inserted against a
  client being deleted. `FOR SHARE`, not `RereadUnderLock` (which only takes `FOR UPDATE`): two consents never wait
  on each other, only the removal waits. A CIMD client goes through `saveDiscovered()` (`ON CONFLICT`) instead.
- **D-D — No API Platform pagination.** `AdministeredMcpConnections::list()` returns every live connection plus the
  `TERMINATED_LIMIT` (200) most recently ended, read in one snapshot (`ReadSnapshotInterface`): three reads of the
  ended ones, each on its partial index — `findTerminatedForAdministration()`, `findAbandonedForAdministration()`, and
  the paged `findExchangedExpiredForAdministration()` (pages of `EXCHANGED_PAGE_SIZE`, keyset cursor
  `ExchangedExpiredCursor` on the activity `COALESCE(last_used_at, granted_at)` then `id`) — merged on their real end,
  cut at 200. An expired connection's real end is computed in PHP, not stored, so the paged read stops early only
  once 200 are held and the next page's best possible end (`idleDeadline(activity) + P1D`, the invariant told in
  `AdministeredMcpConnections`' docblock) is older than the 200th: a change to any of the three reads, to the cursor or
  to `ConnectionLifetimes::idleDeadline()` is judged against that bound.
  The state (`active`, `expired`, `revoked`) is computed in one place, `McpConnectionStatus`, on the deadlines of
  `ConnectionLifetimes` (`mcp-oauth-cycle-de-vie.md`); « Mes connexions MCP » keeps its own bound of 50 (`OwnMcpConnections`).
- **D-E — `PUT` replaces the name and the redirections; the identifier is immutable.** `McpClient::revise()` applies
  the invariants of `register()`. A CIMD client is refused (`McpClientDeclaredByDocumentException::cannotBeRevised()`,
  409 `client-declared-by-document`, its status from `getStatus()`, no `exception_to_status` entry by design); a
  pre-registered row whose identifier is a verified domain's CIMD URL is refused
  (`McpClientIdOfVerifiedDomainException::cannotBeRevised()`, 422 `client-id-of-verified-domain`), judged by the
  administrator through `AllowedClientDomains`, never by the entity. A revision cuts no connection.
- **D-F — The validation delegates to the domain's value objects.** The class constraint `ValidMcpClientFields`, on
  both input DTOs, calls `McpClientId`, `McpClientName`, `RedirectUri::fromString()` and the bound
  `McpClient::MAX_REDIRECT_URIS` (10, `ClientMetadataDocument`'s): one 422 violation per path (`clientId`, `name`,
  `redirectUris[2]`), never a second copy of a rule. A redirection holds at most `RedirectUri::MAX_LENGTH` (2 048
  bytes) and `MAX_QUERY_PARAMETERS` (50); a client 1 to 10 distinct redirections.
- **D-G — Three new audit events, and an explicit actor for the console.** `mcp-client-registered` (`clientId`,
  `registration`), `mcp-client-revised` (`clientId`, `redirectUriCount`, `addedRedirectCount`, `removedRedirectCount`,
  counted on deduplicated sets), `mcp-client-removed` (`clientId`, `registration`, `cutConnections`); never a URI nor
  a client name. `AuditOrigin` (`Security/Authentication/Application`) says who acts: `Request` from the backoffice
  (the token storage's account), `Console` from `app:mcp:client:register` (actor `console`).
- **D-H — One helper for the violated unique index.** `Shared/Infrastructure/Doctrine/ViolatedUniqueIndex::of()`
  holds the `QUOTED_IDENTIFIER` regex once, for `CpgUserUniqueViolation` and `McpClientRegistrar` (a duplicate
  `client_id` is a 409 `McpClientAlreadyRegisteredException`, even when two registrations race).
- **D-I — One frontend port.** `domain/admin/mcp/repositories/AdminMcpRepository` for clients and connections, one
  adapter `infrastructure/admin/mcp/HttpAdminMcpRepository` (`BackofficeHttpClient`), two composables
  (`useAdminMcpClients`, `useAdminMcpConnections`); the 409s are told apart by `hasProblemType()`.

## Lock order

**`mcp_client` before `mcp_connection`, always**, the order of every path that holds both. The consent takes the
client `FOR SHARE`, then inserts its connection (whose foreign key takes `FOR KEY SHARE` on the client); the revision
takes the client `FOR UPDATE` and touches no connection; the removal takes the client `FOR UPDATE`, then its active
connections `FOR UPDATE` by id, then deletes. A removal and a consent of the same client therefore serialize on the
client's row: the consent waits and is refused, or commits first and its connection is cut and audited by the removal.

## The cause `client-removed`

Present in the security log only: `mcp-connection-revoked` with `cause` = `client-removed` (`RevokedConnectionAudit`,
one line per connection the removal cut, written after the COMMIT, a failure of the audit logged `critical` without
changing the answer). It is **not** a `RevocationCause`, nothing in the database carries it, and neither « Mes
connexions MCP » nor the backoffice ever display it — the connections it names no longer exist.

## Accepted limits

1. **A connection cut by the removal of its client disappears from « Mes connexions MCP »** of its person; only the
   audit log keeps its trace (D-A).
2. **A removed CIMD client comes back** at the next authorization of a trusted person, as long as its domain is in
   `app.mcp.cimd.allowed_domains`. Removing is not banning; banning means taking the domain out of the list, out of
   scope of #652.
3. **Taking a domain out of the list does not stop the connections already granted** (ADR 0006, accepted limit of
   #651, `mcp-oauth-cimd.md`); the backoffice cuts them by removing the client.
4. **The connections page does not say whether it truncates** the ended connections: it announces the bound of 200 (D-D).
5. **`mcp-client-revised` does not record the redirections**, only their counts (D-G).
6. **A client already outside the new bounds** (D-F) stays in service and readable; only its revision has to respect
   them. Before a release, a read-only query in preprod checks that no row exceeds them.
7. **One mutation at a time** on the clients page: every action button is disabled while a mutation is in flight.
8. **The fields are `readonly` while the outcome in flight may empty the form** (`isSubmitting || removingId === editingId`).
9. **The focus is moved only when it is lost, or when it sits in what disappeared**; otherwise the outcome is only
   announced.
10. **The form's return to creation is said**: the focus goes to the form's heading, or `admin.mcpClients.backToCreation`
    is announced.
11. **A refusal that proves the row stale** (`not-found`, `declared-by-document`, `id-of-verified-domain`) re-reads the
    list and leaves the revision, at the cost of one more `GET` — a `POST` receiving the typed 422 re-reads it for
    nothing, accepted. A row that shadows a verified domain (`shadowsVerifiedDomain`) carries a text marker and offers
    no « Modifier », its removal stays offered.
12. **Removing a client can deadlock with the daily purge** (`app:mcp:purge`, `mcp-oauth-purge.md`): `remove()`
    locks only the client's *active* connections by id; the `ON DELETE CASCADE` then takes its revoked connections,
    codes and tokens in its plan's order, while the purge deletes old revoked connections, then tokens, in its own.
    PostgreSQL aborts one: `remove()` retries once (D-A), a second `40P01` answers 500 and nothing is removed — the
    administrator retries; the purge's Job retries on its side. The same limit the purge accepts against an
    account's cascade. Locking the revoked rows too was not done: the window is the purge's run, once a day.
13. **The live connections and the clients are read without a bound** (D-D bounds only the ended ones): a portfolio's
    volume; a client with thousands of live connections would make `GET /api/backoffice/mcp/connections` heavy.
14. **« Connexions actives » on the clients page counts the non-revoked connections** (D-A's « active »), an expired
    one the purge has not marked yet included, while the connections page already shows it « Échue »
    (`McpConnectionStatus`): the two pages can disagree until the next purge.

Three more limits of #652 concern the token endpoint, not this backoffice, and are written where that code lives:
`.claude/rules/mcp-oauth-cycle-de-vie.md`, « Accepted limits of #649 … tracked by issue #726 », points 2 to 4 (the
code exchange reads the client without a lock; the redirection refusal precedes the PKCE check; a code refused
`redirect-no-longer-registered` is not consumed). The comparison with the client's list itself is
`LoopbackAwareAuthCodeGrant`'s, in the same rule.
