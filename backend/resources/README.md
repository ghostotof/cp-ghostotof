# resources/

Ressources statiques utilisées par l'application, hors `public/` (donc jamais
servies directement par nginx — uniquement accessibles via une route API qui
applique ses propres règles d'accès).

## private/

Contenu **jamais commité** (voir `.gitignore` et `.dockerignore` racine) :
sensible ou personnellement identifiant, il ne doit exister ni dans
l'historique Git ni dans une image Docker construite.

- `private/cv/cv.pdf` — servi par `GET /api/cv` (`App\Portfolio\Cv`),
  réservé au palier nominatif (`ROLE_TRUSTED`, voir `config/packages/security.yaml`
  et l'ADR 0003). Le même fichier ouvre le corpus de l'assistant de parcours
  (`App\Ai\Assistant`, spec 0005 D7) : son texte en est extrait par
  `pdftotext` à chaque question, jamais mis en cache ni journalisé. Un PDF sans
  couche texte (un scan) y est traité comme absent.
  À déposer manuellement :
  - en local : copier le fichier à cet emplacement, `make sh` (bind mount)
    le voit immédiatement ;
  - en préprod/prod : monté par l'orchestrateur (volume/Secret Kubernetes) au
    chemin défini par la variable d'environnement `CV_FILE_PATH`, jamais
    copié dans l'image.
