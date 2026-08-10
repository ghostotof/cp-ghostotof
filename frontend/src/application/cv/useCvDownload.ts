import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { CvRepository } from '../../domain/cv/repositories/CvRepository'

export const CV_REPOSITORY: InjectionKey<CvRepository> = Symbol('CvRepository')

export interface UseCvDownloadResult {
  isDownloading: Ref<boolean>
  hasError: Ref<boolean>
  downloadCv: () => Promise<void>
}

export function useCvDownload(): UseCvDownloadResult {
  const repository = inject(CV_REPOSITORY)

  if (!repository) {
    throw new Error(
      "CvRepository n'a pas été fourni. Vérifiez que app.provide(CV_REPOSITORY, ...) est bien appelé dans main.ts.",
    )
  }

  const isDownloading = ref(false)
  const hasError = ref(false)

  const downloadCv = async (): Promise<void> => {
    hasError.value = false
    isDownloading.value = true

    try {
      const { blob, filename } = await repository.download()
      triggerBrowserDownload(blob, filename)
    } catch {
      hasError.value = true
    } finally {
      isDownloading.value = false
    }
  }

  return { isDownloading, hasError, downloadCv }
}

/**
 * Le blob récupéré via fetch() n'a pas d'URL propre (contrairement à un lien
 * statique classique) : on crée une URL objet temporaire pour simuler un clic
 * de téléchargement, révoquée juste après pour ne pas fuiter de mémoire.
 */
function triggerBrowserDownload(blob: Blob, filename: string): void {
  const objectUrl = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = objectUrl
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(objectUrl)
}
