# Brouillon — entrée « Incidents » pour A1 (R.6, Q6)

À saisir dans le backoffice, `/admin/incidents`, **deux entrées liées par le même groupe de
traduction** (créer la française, puis « Créer la version EN » : `version` et `occurredAt` sont
recopiés, seules les proses sont à coller).

Champs communs : `version` = `v0.14.1` · `occurredAt` = `2026-09-16`

---

## Version française

**title**

```
Un verrou anti-force-brute qui n'a jamais rien verrouillé
```

**impact**

```
Pendant plusieurs semaines, aucune limitation de débit n'a fonctionné en production : ni le freinage des connexions échouées, ni les quotas du formulaire de contact, ni ceux du parcours de mot de passe. Quatorze tentatives de connexion erronées consécutives sur le compte d'administration n'ont déclenché aucun ralentissement. Aucun compte n'a été compromis, et la limitation nginx placée devant l'application a continué de tenir son rôle de garde-fou, mais la protection applicative sur laquelle reposait le raisonnement était inopérante.
```

**rootCause**

```
Les pods tournent avec un système de fichiers en lecture seule, ce qui est délibéré. Or le cache applicatif de Symfony restait configuré sur son adaptateur par défaut, qui écrit dans un répertoire du conteneur — et c'est ce cache qui stocke les compteurs des limiteurs de débit. L'écriture échouait donc à chaque fois. Le point décisif n'est pas l'échec lui-même, mais son silence : l'adaptateur renvoie `false` et n'émet rien. Chaque tentative repartait d'un compteur vide, sans la moindre trace. Le test automatisé du freinage, lui, était au vert depuis toujours : il s'exécute sur une machine d'intégration dont le disque est inscriptible, si bien qu'il vérifiait un mécanisme fonctionnant dans des conditions que la production ne reproduit jamais.
```

**resolution**

```
Le cache applicatif est passé en base de données, dans tous les environnements — y compris en développement, pour que la configuration testée soit celle qui est déployée. Une migration crée la table, une tâche planifiée quotidienne purge les entrées expirées. Trois garde-fous ont été ajoutés plutôt qu'un seul : un test qui refuse qu'un limiteur, quel qu'il soit, repose sur un stockage fichier ; un test de bout en bout qui enchaîne six connexions erronées contre un environnement réel de préproduction et exige le message de blocage ; et la limitation nginx, conservée comme filet si le stockage venait à défaillir de nouveau.
```

**invariant**

```
Un test vert ne prouve quelque chose que si son environnement ressemble à la production sur le point testé. Ici, la différence tenait à un détail d'infrastructure — un disque inscriptible — sans rapport apparent avec la fonctionnalité vérifiée. Et une écriture qui échoue en silence est pire qu'une écriture qui échoue bruyamment : la seconde réveille, la première laisse croire que tout va bien. Depuis, rien n'écrit plus sur le disque d'un pod : ce qui doit persister va en base, dans un service dédié, ou nulle part.
```

---

## Version anglaise

**title**

```
A brute-force lock that never locked anything
```

**impact**

```
For several weeks, no rate limiting worked in production: not the throttling of failed logins, not the contact form quotas, not those of the password-setup flow. Fourteen consecutive wrong login attempts on the admin account triggered no slowdown whatsoever. No account was compromised, and the nginx limit sitting in front of the application kept playing its backstop role, but the application-level protection the reasoning relied on was inert.
```

**rootCause**

```
The pods run with a read-only filesystem, which is deliberate. Yet Symfony's application cache was still configured on its default adapter, which writes to a directory inside the container — and that cache is where rate limiter counters are stored. Every write therefore failed. The decisive point is not the failure itself but its silence: the adapter returns `false` and emits nothing. Each attempt started from an empty counter, leaving no trace at all. The automated throttling test, meanwhile, had always been green: it runs on an integration machine whose disk is writable, so it verified a mechanism working under conditions production never reproduces.
```

**resolution**

```
The application cache moved to the database, in every environment — including development, so that the configuration under test is the one deployed. A migration creates the table, and a daily scheduled task prunes expired entries. Three guards were added rather than one: a test that refuses to let any rate limiter rely on file storage; an end-to-end test that fires six wrong logins against a real preproduction environment and demands the blocking message; and the nginx limit, kept as a net should the storage ever fail again.
```

**invariant**

```
A green test only proves something if its environment resembles production on the point being tested. Here the difference came down to an infrastructure detail — a writable disk — with no apparent connection to the feature under test. And a write that fails silently is worse than one that fails loudly: the latter wakes you up, the former lets you believe all is well. Since then, nothing writes to a pod's disk: whatever must persist goes to the database, to a dedicated service, or nowhere.
```
