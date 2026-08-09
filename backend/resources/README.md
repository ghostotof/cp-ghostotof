# resources/

Ressources statiques utilisées par l'application, hors `public/` (donc jamais
servies directement par nginx — uniquement accessibles via une route API qui
applique ses propres règles d'accès).

## private/

Contenu **jamais commité** (voir `.gitignore` et `.dockerignore` racine) :
sensible ou personnellement identifiant, il ne doit exister ni dans
l'historique Git ni dans une image Docker construite.

- `private/cv/cv.pdf` — servi par `GET /api/cv` (`App\Portfolio\Cv`),
  protégé par authentification (`ROLE_USER`, voir `config/packages/security.yaml`).
  À déposer manuellement :
  - en local : copier le fichier à cet emplacement, `make sh` (bind mount)
    le voit immédiatement ;
  - en préprod/prod : monté par l'orchestrateur (volume/Secret Kubernetes) au
    chemin défini par la variable d'environnement `CV_FILE_PATH`, jamais
    copié dans l'image.
