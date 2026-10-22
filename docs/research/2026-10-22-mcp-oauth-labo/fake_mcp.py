"""Faux serveur MCP qui imite la topologie du site (#646) : /api va au backend,
/.well-known/* racine répond 404 HTML (frontend), tout autre chemin 200 HTML (SPA).

Scénarios (variable SCENARIO) :
  initial   -> 401 dès l'initialize, sans resource_metadata (ordre de repli)
  initialrm -> 401 dès l'initialize, avec resource_metadata sous /api
  deferred  -> initialize et tools/list anonymes, 401 sur tools/call de l'outil protégé
  metaerr   -> tools/call protégé : résultat isError + _meta["mcp/www_authenticate"]
Variantes (variables d'environnement, valeur quelconque pour activer) :
  OIDC_FULL -> le document de /api/oauth/.well-known/openid-configuration porte les champs OIDC requis
  SPA200    -> /.well-known/* racine répond 200 HTML, comme la SPA sans la location du frontend
  ROOTDOCS  -> les deux documents servis aux URL canoniques de la racine (piste B)
  DCR       -> les métadonnées annoncent un registration_endpoint
  MODERN    -> répond au server/discover de la révision 2026-07-28 (sinon -32601, repli sur initialize)
Chaque requête est journalisée en JSON Lines dans LOG (méthode, chemin, en-têtes utiles, corps tronqué ;
l'en-tête Authorization réduit à ses 16 premiers caractères).
Usage et résultats : README.md de ce dossier et docs/research/2026-10-22-mcp-decouverte-oauth.md, section 3.
"""
import json
import os
import sys
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

PORT = int(os.environ.get("PORT", "8765"))
SCENARIO = os.environ.get("SCENARIO", "deferred")
LOG = os.environ.get("LOG", "requests.log")
BASE = f"http://127.0.0.1:{PORT}"
ISSUER = f"{BASE}/api/oauth"
PRM_URL = f"{BASE}/api/.well-known/oauth-protected-resource"
WWW = f'Bearer resource_metadata="{PRM_URL}", scope="nominative:read"'

TOOLS = [
    {"name": "get_public", "description": "Public tool.", "inputSchema": {"type": "object", "properties": {}},
     "securitySchemes": [{"type": "noauth"}]},
    {"name": "get_protected", "description": "Protected tool, needs an MCP connection.",
     "inputSchema": {"type": "object", "properties": {}},
     "securitySchemes": [{"type": "oauth2", "scopes": ["nominative:read"]}]},
]


def log(entry):
    with open(LOG, "a") as f:
        f.write(json.dumps(entry) + "\n")


class H(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *a):
        pass

    def _send(self, code, body, ctype="application/json", extra=None):
        data = body.encode() if isinstance(body, str) else body
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(data)))
        for k, v in (extra or {}).items():
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(data)

    def _record(self, body=b""):
        keep = ("authorization", "mcp-protocol-version", "mcp-session-id", "accept", "user-agent", "content-type", "mcp-method", "mcp-name")
        log({"m": self.command, "p": self.path,
             "h": {k: (v if k != "authorization" else v[:16] + "…") for k, v in self.headers.items() if k.lower() in keep},
             "b": body[:6000].decode(errors="replace")})

    def _route_static(self):
        p = self.path.split("?")[0]
        if os.environ.get("ROOTDOCS") and p == "/.well-known/oauth-authorization-server/api/oauth":
            p = "/api/oauth/.well-known/openid-configuration"
        if p == "/api/.well-known/oauth-protected-resource" or (os.environ.get("ROOTDOCS") and p == "/.well-known/oauth-protected-resource/api/mcp"):
            return self._send(200, json.dumps({
                "resource": f"{BASE}/api/mcp",
                "authorization_servers": [ISSUER],
                "scopes_supported": ["nominative:read"],
                "bearer_methods_supported": ["header"],
            }))
        if p == "/api/oauth/.well-known/openid-configuration":
            return self._send(200, json.dumps({
                "issuer": ISSUER,
                "authorization_endpoint": f"{ISSUER}/authorize",
                "token_endpoint": f"{ISSUER}/token",
                "response_types_supported": ["code"],
                "grant_types_supported": ["authorization_code", "refresh_token"],
                "code_challenge_methods_supported": ["S256"],
                "token_endpoint_auth_methods_supported": ["none"],
                "scopes_supported": ["nominative:read"],
                "client_id_metadata_document_supported": True,
                **({"jwks_uri": f"{ISSUER}/jwks", "subject_types_supported": ["public"],
                    "id_token_signing_alg_values_supported": ["RS256"]} if os.environ.get("OIDC_FULL") else {}),
                **({"registration_endpoint": f"{ISSUER}/register"} if os.environ.get("DCR") else {}),
            }))
        if p.startswith("/api/"):
            return self._send(404, json.dumps({"title": "Not Found", "status": 404}), "application/problem+json")
        if p.startswith("/.well-known/") and not os.environ.get("SPA200"):
            return self._send(404, "<html><body>404 Not Found</body></html>", "text/html; charset=utf-8")
        return self._send(200, "<!doctype html><html><body><div id=app></div></body></html>", "text/html")

    def do_GET(self):
        self._record()
        if self.path.split("?")[0] == "/api/mcp":
            # Flux SSE vide aussitôt fermé, comme le site.
            return self._send(200, "retry: 300000\n\n", "text/event-stream")
        return self._route_static()

    def do_DELETE(self):
        self._record()
        return self._send(200, "")

    def do_POST(self):
        n = int(self.headers.get("Content-Length", "0") or 0)
        body = self.rfile.read(n)
        self._record(body)
        if self.path.split("?")[0] != "/api/mcp":
            return self._route_static()
        msg = json.loads(body or b"{}")
        method, mid = msg.get("method"), msg.get("id")
        authed = (self.headers.get("Authorization") or "").startswith("Bearer ")
        if self.path.split("?")[0] == "/api/oauth/register":
            return self._send(400, json.dumps({"error": "invalid_client_metadata"}))
        if SCENARIO in ("initial", "initialrm") and not authed:
            hdr = WWW if SCENARIO == "initialrm" else "Bearer"
            return self._send(401, json.dumps({"error": "invalid_token"}), extra={"WWW-Authenticate": hdr})
        if mid is None:
            return self._send(202, "")
        if method == "server/discover":
            if not os.environ.get("MODERN"):
                return self._send(200, json.dumps({"jsonrpc": "2.0", "id": mid, "error": {"code": -32601, "message": "Method not found"}}))
            return self._send(200, json.dumps({"jsonrpc": "2.0", "id": mid, "result": {
                "supportedVersions": ["2026-07-28"], "capabilities": {"tools": {}},
                "resultType": "complete", "ttlMs": 0, "cacheScope": "private",
                "_meta": {"io.modelcontextprotocol/serverInfo": {"name": "fake", "version": "0.0.1"}}}}))
        if method == "initialize":
            ver = msg.get("params", {}).get("protocolVersion", "2025-06-18")
            res = {"protocolVersion": ver, "capabilities": {"tools": {}},
                   "serverInfo": {"name": "fake", "version": "0.0.1"}}
            return self._send(200, json.dumps({"jsonrpc": "2.0", "id": mid, "result": res}),
                              extra={"Mcp-Session-Id": str(uuid.uuid4())})
        if method == "tools/list":
            return self._send(200, json.dumps({"jsonrpc": "2.0", "id": mid, "result": {"tools": TOOLS, "resultType": "complete"}}))
        if method == "tools/call":
            name = msg.get("params", {}).get("name")
            if name == "get_protected" and not authed:
                if SCENARIO == "deferred":
                    return self._send(401, json.dumps({"error": "invalid_token"}), extra={"WWW-Authenticate": WWW})
                if SCENARIO == "metaerr":
                    return self._send(200, json.dumps({"jsonrpc": "2.0", "id": mid, "result": {
                        "isError": True,
                        "content": [{"type": "text", "text": "Authentication required: connect your account."}],
                        "_meta": {"mcp/www_authenticate": [WWW]}}}))
            text = "secret-ok" if name == "get_protected" else "public-ok"
            return self._send(200, json.dumps({"jsonrpc": "2.0", "id": mid, "result": {
                "content": [{"type": "text", "text": text}], "isError": False, "resultType": "complete"}}))
        return self._send(200, json.dumps({"jsonrpc": "2.0", "id": mid, "result": {}}))


if __name__ == "__main__":
    print(f"scenario={SCENARIO} port={PORT}", file=sys.stderr)
    ThreadingHTTPServer(("127.0.0.1", PORT), H).serve_forever()
