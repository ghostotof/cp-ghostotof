# Suivi — purge des invitations jamais activées (#238)

Branche `feature/purge-pending-invitations`, design validé le 2026-09-22, plan dans `plan.md`.

- [ ] Task 1 — repository (QueryBuilder) + `PendingInvitationPurger` + événement `user-purged` (acteur `system`)
- [ ] Task 2 — commande `app:user:purge-pending-invitations` (`--older-than`, `--dry-run`) + CronJob
- [ ] Task 3 — registre RGPD §3.2/§6, CLAUDE.md
- [ ] Revue finale de branche, PR vers `develop` avec archivage de `tasks/` sous `.claude/specs/archive/<date>-purge-pending-invitations/tasks/`

## Décisions
- Seuil : 30 jours après la dernière invitation (`invitedAt`, remis à jour par `reinvite`).
- Trace : événement d'audit `user-purged` par compte (`user`, `userId`, `reason: invitation-expired`, `actor: system`), jamais l'e-mail.
- `ROLE_SUPER` en attente : jamais purgé automatiquement (avertissement).
- Pas de notification à la personne.
