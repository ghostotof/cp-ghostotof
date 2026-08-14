<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAdminQualityPrinciples } from '../../../application/admin/quality/useAdminQualityPrinciples'
import { useAdminQualityTraits } from '../../../application/admin/quality/useAdminQualityTraits'
import BaseTextInput from '../../ui/BaseTextInput.vue'
import BaseTextarea from '../../ui/BaseTextarea.vue'
import BaseNumberInput from '../../ui/BaseNumberInput.vue'
import BaseSelect from '../../ui/BaseSelect.vue'
import { LOCALE_NATIVE_NAMES, SUPPORTED_LOCALES, type Locale } from '../../../domain/portfolio/entities/Locale'
import type { AdminQualityPrinciple } from '../../../domain/admin/quality/entities/AdminQualityPrinciple'
import type { AdminQualityTrait } from '../../../domain/admin/quality/entities/AdminQualityTrait'

const { t } = useI18n()

const {
  principles,
  isLoading: isLoadingPrinciples,
  hasError: hasPrinciplesError,
  errorMessage: principleErrorMessage,
  load: loadPrinciples,
  create: createPrinciple,
  update: updatePrinciple,
  remove: removePrinciple,
} = useAdminQualityPrinciples()

const {
  traits,
  isLoading: isLoadingTraits,
  hasError: hasTraitsError,
  errorMessage: traitErrorMessage,
  load: loadTraits,
  create: createTrait,
  update: updateTrait,
  remove: removeTrait,
} = useAdminQualityTraits()

const localeOptions = SUPPORTED_LOCALES.map((locale) => ({ value: locale, label: LOCALE_NATIVE_NAMES[locale] }))
const selectedLocale = ref<Locale>('fr')

watch(
  selectedLocale,
  (locale) => {
    loadPrinciples(locale)
    loadTraits(locale)
  },
  { immediate: true },
)

const editingPrincipleId = ref<number | null>(null)
const principleForm = reactive({ title: '', description: '', iconKey: '', position: 0 })
const isSubmittingPrinciple = ref(false)
const isEditingPrinciple = computed(() => null !== editingPrincipleId.value)
const principleErrorText = computed(() => (principleErrorMessage.value ? t(`admin.quality.errors.${principleErrorMessage.value.reason}`) : null))

function resetPrincipleForm(): void {
  editingPrincipleId.value = null
  principleForm.title = ''
  principleForm.description = ''
  principleForm.iconKey = ''
  principleForm.position = 0
}

function startEditPrinciple(principle: AdminQualityPrinciple): void {
  editingPrincipleId.value = principle.id
  principleForm.title = principle.title
  principleForm.description = principle.description
  principleForm.iconKey = principle.iconKey
  principleForm.position = principle.position
}

async function handleSubmitPrinciple(): Promise<void> {
  isSubmittingPrinciple.value = true

  const input = { locale: selectedLocale.value, ...principleForm }

  if (null !== editingPrincipleId.value) {
    await updatePrinciple(editingPrincipleId.value, input)
  } else {
    await createPrinciple(input)
  }

  isSubmittingPrinciple.value = false

  if (!principleErrorMessage.value) {
    resetPrincipleForm()
  }
}

async function handleDeletePrinciple(principle: AdminQualityPrinciple): Promise<void> {
  if (!window.confirm(t('admin.quality.principle.confirmDelete', { title: principle.title }))) {
    return
  }

  await removePrinciple(principle.id)
}

const editingTraitId = ref<number | null>(null)
const traitForm = reactive({ label: '', position: 0 })
const isSubmittingTrait = ref(false)
const isEditingTrait = computed(() => null !== editingTraitId.value)
const traitErrorText = computed(() => (traitErrorMessage.value ? t(`admin.quality.errors.${traitErrorMessage.value.reason}`) : null))

function resetTraitForm(): void {
  editingTraitId.value = null
  traitForm.label = ''
  traitForm.position = 0
}

function startEditTrait(trait: AdminQualityTrait): void {
  editingTraitId.value = trait.id
  traitForm.label = trait.label
  traitForm.position = trait.position
}

async function handleSubmitTrait(): Promise<void> {
  isSubmittingTrait.value = true

  const input = { locale: selectedLocale.value, ...traitForm }

  if (null !== editingTraitId.value) {
    await updateTrait(editingTraitId.value, input)
  } else {
    await createTrait(input)
  }

  isSubmittingTrait.value = false

  if (!traitErrorMessage.value) {
    resetTraitForm()
  }
}

async function handleDeleteTrait(trait: AdminQualityTrait): Promise<void> {
  if (!window.confirm(t('admin.quality.trait.confirmDelete', { label: trait.label }))) {
    return
  }

  await removeTrait(trait.id)
}
</script>

<template>
  <div class="d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <BaseSelect
        id="admin-quality-locale"
        v-model="selectedLocale"
        :label="t('admin.localeLabel')"
        :options="localeOptions"
      />
    </div>

    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ isEditingPrinciple ? t('admin.quality.principle.edit') : t('admin.quality.principle.create') }}
      </h2>

      <form
        novalidate
        @submit.prevent="handleSubmitPrinciple"
      >
        <BaseTextInput
          id="admin-quality-principle-title"
          v-model="principleForm.title"
          :label="t('admin.quality.principle.titleLabel')"
          required
        />
        <BaseTextarea
          id="admin-quality-principle-description"
          v-model="principleForm.description"
          :label="t('admin.quality.principle.descriptionLabel')"
          required
        />
        <BaseTextInput
          id="admin-quality-principle-icon-key"
          v-model="principleForm.iconKey"
          :label="t('admin.quality.principle.iconKeyLabel')"
          required
        />
        <BaseNumberInput
          id="admin-quality-principle-position"
          v-model="principleForm.position"
          :label="t('admin.quality.principle.positionLabel')"
          :step="1"
        />

        <p
          v-if="principleErrorText"
          class="text-danger small"
          role="alert"
        >
          {{ principleErrorText }}
        </p>

        <div class="d-flex gap-2">
          <button
            type="submit"
            class="btn btn-gradient"
            :disabled="isSubmittingPrinciple"
          >
            {{ t('admin.quality.save') }}
          </button>
          <button
            v-if="isEditingPrinciple"
            type="button"
            class="btn btn-outline-light"
            @click="resetPrincipleForm"
          >
            {{ t('admin.quality.cancel') }}
          </button>
        </div>
      </form>

      <hr class="border-secondary my-4">

      <h3 class="h6 fw-bold text-white mb-3">
        {{ t('admin.quality.principle.listTitle') }}
      </h3>

      <p
        v-if="isLoadingPrinciples"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.quality.loading') }}
      </p>
      <p
        v-else-if="hasPrinciplesError"
        class="text-danger mb-0"
        role="alert"
      >
        {{ t('admin.quality.loadError') }}
      </p>
      <p
        v-else-if="0 === principles.length"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.quality.principle.empty') }}
      </p>
      <div
        v-else
        class="table-responsive"
      >
        <table class="table table-dark table-hover align-middle mb-0">
          <thead>
            <tr>
              <th scope="col">
                {{ t('admin.quality.principle.titleLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.quality.principle.iconKeyLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.quality.principle.positionLabel') }}
              </th>
              <th scope="col">
                <span class="visually-hidden">{{ t('admin.quality.actions') }}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="principle in principles"
              :key="principle.id"
            >
              <td>{{ principle.title }}</td>
              <td>{{ principle.iconKey }}</td>
              <td>{{ principle.position }}</td>
              <td class="text-end">
                <button
                  type="button"
                  class="btn btn-sm btn-outline-light me-2"
                  @click="startEditPrinciple(principle)"
                >
                  {{ t('admin.quality.principle.editAction') }}
                </button>
                <button
                  type="button"
                  class="btn btn-sm btn-outline-danger"
                  @click="handleDeletePrinciple(principle)"
                >
                  {{ t('admin.quality.delete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ isEditingTrait ? t('admin.quality.trait.edit') : t('admin.quality.trait.create') }}
      </h2>

      <form
        novalidate
        @submit.prevent="handleSubmitTrait"
      >
        <BaseTextInput
          id="admin-quality-trait-label"
          v-model="traitForm.label"
          :label="t('admin.quality.trait.labelLabel')"
          required
        />
        <BaseNumberInput
          id="admin-quality-trait-position"
          v-model="traitForm.position"
          :label="t('admin.quality.trait.positionLabel')"
          :step="1"
        />

        <p
          v-if="traitErrorText"
          class="text-danger small"
          role="alert"
        >
          {{ traitErrorText }}
        </p>

        <div class="d-flex gap-2">
          <button
            type="submit"
            class="btn btn-gradient"
            :disabled="isSubmittingTrait"
          >
            {{ t('admin.quality.save') }}
          </button>
          <button
            v-if="isEditingTrait"
            type="button"
            class="btn btn-outline-light"
            @click="resetTraitForm"
          >
            {{ t('admin.quality.cancel') }}
          </button>
        </div>
      </form>

      <hr class="border-secondary my-4">

      <h3 class="h6 fw-bold text-white mb-3">
        {{ t('admin.quality.trait.listTitle') }}
      </h3>

      <p
        v-if="isLoadingTraits"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.quality.loading') }}
      </p>
      <p
        v-else-if="hasTraitsError"
        class="text-danger mb-0"
        role="alert"
      >
        {{ t('admin.quality.loadError') }}
      </p>
      <p
        v-else-if="0 === traits.length"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.quality.trait.empty') }}
      </p>
      <div
        v-else
        class="table-responsive"
      >
        <table class="table table-dark table-hover align-middle mb-0">
          <thead>
            <tr>
              <th scope="col">
                {{ t('admin.quality.trait.labelLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.quality.trait.positionLabel') }}
              </th>
              <th scope="col">
                <span class="visually-hidden">{{ t('admin.quality.actions') }}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="trait in traits"
              :key="trait.id"
            >
              <td>{{ trait.label }}</td>
              <td>{{ trait.position }}</td>
              <td class="text-end">
                <button
                  type="button"
                  class="btn btn-sm btn-outline-light me-2"
                  @click="startEditTrait(trait)"
                >
                  {{ t('admin.quality.trait.editAction') }}
                </button>
                <button
                  type="button"
                  class="btn btn-sm btn-outline-danger"
                  @click="handleDeleteTrait(trait)"
                >
                  {{ t('admin.quality.delete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
