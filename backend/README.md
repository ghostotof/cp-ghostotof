# backend/ — API Symfony 8

API seule (aucune page rendue côté serveur), consommée par le frontend Vue de `../frontend/`.

- **Framework** : Symfony 8.1, API Platform 4.3, Doctrine ORM 3 sur PostgreSQL, Messenger sur
  RabbitMQ, LexikJWT (cookie httpOnly), Symfony AI (assistant de traduction du backoffice).
- **Structure** : un dossier par contexte borné sous `src/` (`Security/`, `Portfolio/*`, `Ai/`,
  `Contact/`, `Shared/`), chacun découpé en `Domain/Application/Infrastructure/Presentation`. Les
  tests miroitent cet arbre sous `tests/`.
- **Qualité** : PHPStan niveau `max` + `strict-rules` sans baseline sur `src/` et `tests/`, Rector,
  Psalm en analyse de flux de données seulement, Symfony Language Tools (`lsp:check`), PHPUnit.

Tout se lance depuis la racine du dépôt, dans le conteneur :

```bash
make sh              # shell dans le conteneur backend
make back-test       # PHPUnit
make back-quality    # PHPStan + Rector + Psalm + lsp:check
make db-migrate      # migrations Doctrine
```

Les décisions structurantes (paliers d'accès, veille technique, assistance IA, provisionnement des
comptes) sont dans [`../docs/adr/`](../docs/adr/) ; les invariants à respecter en travaillant
ici, dans [`../.claude/CLAUDE.md`](../.claude/CLAUDE.md).
