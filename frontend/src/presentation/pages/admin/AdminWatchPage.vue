<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAdminWatchedProducts } from '../../../application/admin/watch/useAdminWatchedProducts'
import { useAdminVulnerabilities } from '../../../application/admin/watch/useAdminVulnerabilities'
import BaseTextInput from '../../ui/BaseTextInput.vue'
import BaseNumberInput from '../../ui/BaseNumberInput.vue'
import BaseSelect from '../../ui/BaseSelect.vue'
import type { AdminWatchedProduct } from '../../../domain/admin/watch/entities/AdminWatchedProduct'

const { t } = useI18n()
const { products, isLoading, hasError, errorMessage, create, update, remove } = useAdminWatchedProducts()
const {
  vulnerabilities,
  isLoading: isLoadingVulnerabilities,
  hasError: hasVulnerabilityError,
} = useAdminVulnerabilities()

/** L'adresse de la fiche publiée par la base, pour vérifier à la source. */
function osvUrl(id: string): string {
  return `https://osv.dev/vulnerability/${encodeURIComponent(id)}`
}

const VERSION_SOURCES = ['manual', 'runtime_php', 'runtime_symfony'] as const

const editingId = ref<number | null>(null)

interface WatchedProductForm {
  slug: string
  label: string
  versionSource: string
  version: string
  position: number
}

const form = reactive<WatchedProductForm>({
  slug: '',
  label: '',
  versionSource: 'manual',
  version: '',
  position: 0,
})
const isSubmitting = ref(false)

const isEditing = computed(() => null !== editingId.value)

/**
 * Le champ version n'a de sens que pour une source saisie à la main : pour PHP
 * et Symfony, la valeur est lue dans le processus au rafraîchissement
 * (décision D2). On masque le champ plutôt que de le griser — un champ absent
 * ne pose pas la question qu'un champ désactivé poserait.
 */
const isManualVersion = computed(() => 'manual' === form.versionSource)

const errorText = computed(() => (errorMessage.value ? t(`admin.watch.errors.${errorMessage.value.reason}`) : null))

const versionSourceOptions = computed(() =>
  VERSION_SOURCES.map((source) => ({ value: source, label: t(`admin.watch.sources.${source}`) })),
)

function sourceLabel(source: string): string {
  return VERSION_SOURCES.includes(source as (typeof VERSION_SOURCES)[number])
    ? t(`admin.watch.sources.${source}`)
    : source
}

function resetForm(): void {
  editingId.value = null
  form.slug = ''
  form.label = ''
  form.versionSource = 'manual'
  form.version = ''
  form.position = 0
}

function startEdit(product: AdminWatchedProduct): void {
  editingId.value = product.id
  form.slug = product.slug
  form.label = product.label
  form.versionSource = product.versionSource
  form.version = product.version ?? ''
  form.position = product.position
}

async function handleSubmit(): Promise<void> {
  isSubmitting.value = true

  const input = {
    slug: form.slug,
    label: form.label,
    versionSource: form.versionSource,
    // Une version saisie puis abandonnée au profit d'une source runtime ne doit
    // pas être renvoyée : le domaine la refuserait, à juste titre.
    version: isManualVersion.value && '' !== form.version.trim() ? form.version : null,
    position: form.position,
  }

  if (null !== editingId.value) {
    await update(editingId.value, input)
  } else {
    await create(input)
  }

  isSubmitting.value = false

  if (null === errorMessage.value) {
    resetForm()
  }
}

async function handleDelete(product: AdminWatchedProduct): Promise<void> {
  if (!window.confirm(t('admin.watch.confirmDelete', { label: product.label }))) {
    return
  }

  await remove(product.id)
}
</script>

<template>
  <div class="d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ isEditing ? t('admin.watch.edit') : t('admin.watch.create') }}
      </h2>

      <form
        novalidate
        @submit.prevent="handleSubmit"
      >
        <!-- Le slug construit l'URL interrogée chez le fournisseur : le changer
             reviendrait à suivre un autre produit, ce que le domaine refuse.
             En édition, il est donc affiché plutôt que proposé à la saisie. -->
        <div
          v-if="isEditing"
          class="mb-3"
        >
          <p class="form-label text-body-secondary mb-1">
            {{ t('admin.watch.slugLabel') }}
          </p>
          <p class="mb-1 fw-semibold text-white">
            {{ form.slug }}
          </p>
          <p class="form-text mb-0">
            {{ t('admin.watch.slugImmutableHelp') }}
          </p>
        </div>
        <BaseTextInput
          v-else
          id="admin-watch-slug"
          v-model="form.slug"
          :label="t('admin.watch.slugLabel')"
          required
        />

        <BaseTextInput
          id="admin-watch-label"
          v-model="form.label"
          :label="t('admin.watch.labelLabel')"
          required
        />

        <BaseSelect
          id="admin-watch-version-source"
          v-model="form.versionSource"
          :label="t('admin.watch.versionSourceLabel')"
          :options="versionSourceOptions"
          required
        />

        <BaseTextInput
          v-if="isManualVersion"
          id="admin-watch-version"
          v-model="form.version"
          :label="t('admin.watch.versionLabel')"
          required
        />
        <p
          v-else
          class="form-text mb-3"
        >
          {{ t('admin.watch.versionFromRuntimeHelp') }}
        </p>

        <BaseNumberInput
          id="admin-watch-position"
          v-model="form.position"
          :label="t('admin.watch.positionLabel')"
          required
        />

        <p
          v-if="errorText"
          class="text-danger small"
          role="alert"
        >
          {{ errorText }}
        </p>

        <div class="d-flex gap-2">
          <button
            type="submit"
            class="btn btn-gradient"
            :disabled="isSubmitting"
          >
            {{ t('admin.watch.save') }}
          </button>
          <button
            v-if="isEditing"
            type="button"
            class="btn btn-outline-light"
            @click="resetForm"
          >
            {{ t('admin.watch.cancel') }}
          </button>
        </div>
      </form>
    </div>

    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ t('admin.watch.listTitle') }}
      </h2>

      <p
        v-if="isLoading"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.watch.loading') }}
      </p>
      <p
        v-else-if="hasError"
        class="text-danger mb-0"
        role="alert"
      >
        {{ t('admin.watch.loadError') }}
      </p>
      <p
        v-else-if="0 === products.length"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.watch.empty') }}
      </p>
      <div
        v-else
        class="table-responsive"
      >
        <table class="table table-dark table-hover align-middle mb-0">
          <thead>
            <tr>
              <th scope="col">
                {{ t('admin.watch.slugLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.watch.labelLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.watch.versionSourceLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.watch.versionLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.watch.positionLabel') }}
              </th>
              <th scope="col">
                <span class="visually-hidden">{{ t('admin.watch.actions') }}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="product in products"
              :key="product.id"
            >
              <td>{{ product.slug }}</td>
              <td>{{ product.label }}</td>
              <td>{{ sourceLabel(product.versionSource) }}</td>
              <td>{{ product.version ?? '—' }}</td>
              <td>{{ product.position }}</td>
              <td class="text-end">
                <button
                  type="button"
                  class="btn btn-sm btn-outline-light me-2"
                  @click="startEdit(product)"
                >
                  {{ t('admin.watch.editAction') }}
                </button>
                <button
                  type="button"
                  class="btn btn-sm btn-outline-danger"
                  @click="handleDelete(product)"
                >
                  {{ t('admin.watch.deleteAction') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Détail des vulnérabilités : visible ici et nulle part ailleurs. La
         page publique n'en montre qu'un décompte (décision D4). -->
    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-1">
        {{ t('admin.watch.vulnerabilities.title') }}
      </h2>
      <p class="form-text mb-3">
        {{ t('admin.watch.vulnerabilities.help') }}
      </p>

      <p
        v-if="isLoadingVulnerabilities"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.watch.vulnerabilities.loading') }}
      </p>
      <p
        v-else-if="hasVulnerabilityError"
        class="text-danger mb-0"
        role="alert"
      >
        {{ t('admin.watch.vulnerabilities.loadError') }}
      </p>
      <p
        v-else-if="0 === vulnerabilities.length"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.watch.vulnerabilities.empty') }}
      </p>
      <div
        v-else
        class="table-responsive"
      >
        <table class="table table-dark table-hover align-middle mb-0">
          <thead>
            <tr>
              <th scope="col">
                {{ t('admin.watch.vulnerabilities.identifier') }}
              </th>
              <th scope="col">
                {{ t('admin.watch.vulnerabilities.package') }}
              </th>
              <th scope="col">
                {{ t('admin.watch.vulnerabilities.severity') }}
              </th>
              <th scope="col">
                {{ t('admin.watch.vulnerabilities.fixedIn') }}
              </th>
              <th scope="col">
                {{ t('admin.watch.vulnerabilities.summary') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="vulnerability in vulnerabilities"
              :key="vulnerability.id"
            >
              <th
                scope="row"
                class="fw-semibold text-white"
              >
                <a
                  :href="osvUrl(vulnerability.id)"
                  rel="noreferrer"
                  class="link-light"
                >{{ vulnerability.id }}</a>
                <span
                  v-if="vulnerability.aliases.length > 0"
                  class="d-block form-text"
                >{{ vulnerability.aliases.join(', ') }}</span>
              </th>
              <td>
                {{ vulnerability.packageName }}
                <span class="d-block form-text">{{ vulnerability.packageVersion }}</span>
              </td>
              <td>{{ vulnerability.severity ?? '—' }}</td>
              <td>{{ vulnerability.fixedIn ?? '—' }}</td>
              <td>{{ vulnerability.summary ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
