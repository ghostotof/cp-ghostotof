# Laboratoire : découverte OAuth et autorisation différée vues par Claude Code (#646)

Faux serveur MCP local (`fake_mcp.py`, Python 3 sans dépendance) qui imite la topologie du site : sous `/api`
un 404 JSON, `/.well-known/*` à la racine en 404 HTML (le frontend), tout autre chemin en 200 HTML (la SPA). Il
sert un outil public (`get_public`) et un outil protégé (`get_protected`), et journalise chaque requête. Il
n'émet aucun jeton : l'expérience s'arrête à l'URL d'autorisation que le client construit. Les résultats
du 2026-10-22 (Claude Code 2.1.303) sont dans `../2026-10-22-mcp-decouverte-oauth.md`, section 3, et
`../2026-10-22-mcp-autorisation-differee.md`, section 2.1.

Tout se lance dans un dossier de travail vide, hors du dépôt.

```sh
# Le serveur, un scénario et ses variantes (voir l'en-tête de fake_mcp.py)
SCENARIO=deferred MODERN=1 OIDC_FULL=1 LOG=deferred.log python3 -I fake_mcp.py &

# Une session : le modèle appelle les deux outils, sans configuration MCP de l'utilisateur
cat > mcp.json <<'JSON'
{"mcpServers": {"fake": {"type": "http", "url": "http://127.0.0.1:8765/api/mcp"}}}
JSON
claude -p "Call the MCP tool get_public, then call the MCP tool get_protected. Report each raw result verbatim." \
  --mcp-config mcp.json --strict-mcp-config --model haiku \
  --allowedTools mcp__fake__get_public mcp__fake__get_protected < /dev/null

# L'authentification seule, à froid : le serveur doit être déclaré (portée locale, retirée à la fin)
claude mcp add --transport http --scope local fake http://127.0.0.1:8765/api/mcp
claude mcp logout fake                      # efface le pointeur appris d'un 401 précédent
timeout 20 claude mcp login fake --no-browser < /dev/null   # affiche l'URL d'autorisation, puis abandonne
claude mcp remove fake --scope local

# Les requêtes de découverte observées
python3 -I -c 'import json,sys; [print(e["m"], e["p"]) for e in map(json.loads, open(sys.argv[1]))]' deferred.log
```

`claude mcp add --scope local` écrit dans `~/.claude.json`, sous le dossier courant : le retirer après usage.
