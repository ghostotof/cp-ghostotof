export interface CvFile {
  blob: Blob
  filename: string
}

/**
 * Abstraction (DIP) dont dépend l'application. L'implémentation concrète
 * (HttpCvRepository) est injectée au niveau du composition root (main.ts),
 * jamais instanciée directement par un composant.
 */
export interface CvRepository {
  /**
   * Le fichier n'est jamais accessible par une URL directe (protégé par
   * authentification côté backend, cf. App\Portfolio\Cv) : il n'y a donc pas
   * de simple lien statique possible, uniquement un appel authentifié qui
   * renvoie le contenu binaire.
   */
  download(): Promise<CvFile>
}
