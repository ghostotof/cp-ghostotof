import type { AdminWatchedProduct } from '../../../domain/admin/watch/entities/AdminWatchedProduct'
import type {
  AdminWatchedProductInput,
  AdminWatchedProductRepository,
} from '../../../domain/admin/watch/repositories/AdminWatchedProductRepository'
import {
  AdminWatchedProductError,
  type AdminWatchedProductErrorReason,
} from '../../../domain/admin/watch/errors/AdminWatchedProductError'
import { BackofficeHttpClient, violationsMessage } from '../shared/BackofficeHttpClient'

interface BackofficeWatchedProductApiResponse {
  id: number
  slug: string
  label: string
  versionSource: string
  version: string | null
  position: number
}

const BASE_PATH = '/api/backoffice/watch/products'

/**
 * Implémentation HTTP de AdminWatchedProductRepository. Toutes les méthodes
 * exigent le cookie httpOnly BEARER, et les mutations le header CSRF
 * (cf. BackofficeHttpClient).
 */
export class HttpAdminWatchedProductRepository implements AdminWatchedProductRepository {
  private readonly client: BackofficeHttpClient

  constructor(apiBaseUrl: string) {
    this.client = new BackofficeHttpClient(apiBaseUrl)
  }

  async list(): Promise<readonly AdminWatchedProduct[]> {
    const response = await this.client.get(BASE_PATH)

    if (!response.ok) {
      throw await this.toError(response)
    }

    const products = (await response.json()) as BackofficeWatchedProductApiResponse[]

    return products.map(this.toEntity)
  }

  async create(input: AdminWatchedProductInput): Promise<AdminWatchedProduct> {
    const response = await this.mutate('POST', BASE_PATH, input)

    return this.toEntity((await response.json()) as BackofficeWatchedProductApiResponse)
  }

  async update(id: number, input: AdminWatchedProductInput): Promise<AdminWatchedProduct> {
    const response = await this.mutate('PUT', `${BASE_PATH}/${id}`, input)

    return this.toEntity((await response.json()) as BackofficeWatchedProductApiResponse)
  }

  async remove(id: number): Promise<void> {
    await this.mutate('DELETE', `${BASE_PATH}/${id}`)
  }

  private async mutate(method: string, path: string, body?: unknown): Promise<Response> {
    const response = await this.client.mutate(method, path, body)

    if (!response.ok) {
      throw await this.toError(response)
    }

    return response
  }

  private toEntity(product: BackofficeWatchedProductApiResponse): AdminWatchedProduct {
    return {
      id: product.id,
      slug: product.slug,
      label: product.label,
      versionSource: product.versionSource,
      version: product.version,
      position: product.position,
    }
  }

  private async toError(response: Response): Promise<AdminWatchedProductError> {
    const body = await this.client.parseProblem(response)

    if (404 === response.status) {
      return new AdminWatchedProductError('not-found', 'Watched product not found')
    }

    // 409 : slug déjà suivi, ou tentative de changer le slug d'une entrée
    // existante. Deux refus que l'auteur corrige lui-même, et qui méritent
    // donc un message distinct d'une erreur de saisie ordinaire.
    if (409 === response.status) {
      return new AdminWatchedProductError('conflict', violationsMessage(body))
    }

    const reason: AdminWatchedProductErrorReason = 422 === response.status ? 'validation' : 'unknown'

    return new AdminWatchedProductError(reason, violationsMessage(body))
  }
}
