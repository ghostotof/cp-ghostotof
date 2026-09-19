# v0.15.1 — L'intitulé du site devient « Ingénieur logiciel senior PHP / Symfony »

Correctif de libellé. Aucun changement de comportement, aucune migration, backend inchangé.

## Un seul intitulé, partout

La page d'accueil annonçait « Développeur PHP / Symfony senior », et « Senior PHP / Symfony
Developer » en anglais. Elle annonce désormais « Ingénieur logiciel senior PHP / Symfony » et
« Senior Software Engineer (PHP/Symfony) ».

Le même intitulé vivait à quatre endroits, tous alignés dans cette version — un lien partagé
n'annonce pas autre chose que la page qu'il ouvre :

- le titre en tête de la page d'accueil, dans les deux langues ;
- le titre de l'onglet et du résultat de recherche (`seo.home.title`), qui portait encore un
  troisième libellé hérité, « Développeur Web Senior » ;
- les métadonnées Open Graph (`og:title`, `og:image:alt`) et le `<title>` statique qui sert de
  repli avant que le routeur n'ait appliqué le titre de la page ;
- la carte de partage elle-même, régénérée depuis son gabarit `frontend/scripts/og/template.html`.

## Ce que cette version ne change pas

La carte « À propos de moi » de la page À propos porte encore l'ancien intitulé : c'est du contenu
éditorial, administré depuis le backoffice, que le code ne pilote pas.
