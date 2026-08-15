<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAdminAboutMeCards } from '../../../application/admin/about/useAdminAboutMeCards'
import BaseTextInput from '../../ui/BaseTextInput.vue'
import BaseTextarea from '../../ui/BaseTextarea.vue'
import BaseNumberInput from '../../ui/BaseNumberInput.vue'
import BaseSelect from '../../ui/BaseSelect.vue'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import type { AdminAboutMeCard, AdminAboutMeCardCategory } from '../../../domain/admin/about/entities/AdminAboutMeCard'

const props = defineProps<{ locale: Locale }>()

const { t } = useI18n()

const { cards, isLoading, hasError, errorMessage, load, create, update, remove } = useAdminAboutMeCards()

watch(() => props.locale, load, { immediate: true })

const categoryOptions = computed<readonly { value: AdminAboutMeCardCategory; label: string }[]>(() => [
  { value: 'technical', label: t('admin.about.meCard.categoryTechnical') },
  { value: 'personal', label: t('admin.about.meCard.categoryPersonal') },
  { value: 'hobby', label: t('admin.about.meCard.categoryHobby') },
])

const editingId = ref<number | null>(null)
const form = reactive({ category: 'technical' as AdminAboutMeCardCategory, title: '', description: '', iconKey: '', position: 0 })
const isSubmitting = ref(false)
const isEditing = computed(() => null !== editingId.value)
const errorText = computed(() => (errorMessage.value ? t(`admin.about.errors.${errorMessage.value.reason}`) : null))

function resetForm(): void {
  editingId.value = null
  form.category = 'technical'
  form.title = ''
  form.description = ''
  form.iconKey = ''
  form.position = 0
}

function startEdit(card: AdminAboutMeCard): void {
  editingId.value = card.id
  form.category = card.category
  form.title = card.title
  form.description = card.description
  form.iconKey = card.iconKey ?? ''
  form.position = card.position
}

async function handleSubmit(): Promise<void> {
  isSubmitting.value = true

  const input = {
    locale: props.locale,
    category: form.category,
    title: form.title,
    description: form.description,
    iconKey: '' === form.iconKey ? null : form.iconKey,
    position: form.position,
  }

  if (null !== editingId.value) {
    await update(editingId.value, input)
  } else {
    await create(input)
  }

  isSubmitting.value = false

  if (!errorMessage.value) {
    resetForm()
  }
}

async function handleDelete(card: AdminAboutMeCard): Promise<void> {
  if (!window.confirm(t('admin.about.meCard.confirmDelete', { title: card.title }))) {
    return
  }

  await remove(card.id)
}

function categoryLabel(category: AdminAboutMeCardCategory): string {
  return t(`admin.about.meCard.category${category.charAt(0).toUpperCase()}${category.slice(1)}`)
}
</script>

<template>
  <div class="surface-panel p-3 p-sm-4">
    <h2 class="h6 fw-bold text-white mb-3">
      {{ isEditing ? t('admin.about.meCard.edit') : t('admin.about.meCard.create') }}
    </h2>

    <form
      novalidate
      @submit.prevent="handleSubmit"
    >
      <BaseSelect
        id="admin-about-me-card-category"
        v-model="form.category"
        :label="t('admin.about.meCard.categoryLabel')"
        :options="categoryOptions"
      />
      <BaseTextInput
        id="admin-about-me-card-title"
        v-model="form.title"
        :label="t('admin.about.meCard.titleLabel')"
        required
      />
      <BaseTextarea
        id="admin-about-me-card-description"
        v-model="form.description"
        :label="t('admin.about.meCard.descriptionLabel')"
        required
      />
      <BaseTextInput
        id="admin-about-me-card-icon-key"
        v-model="form.iconKey"
        :label="t('admin.about.meCard.iconKeyLabel')"
      />
      <BaseNumberInput
        id="admin-about-me-card-position"
        v-model="form.position"
        :label="t('admin.about.meCard.positionLabel')"
        :step="1"
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
          {{ t('admin.about.save') }}
        </button>
        <button
          v-if="isEditing"
          type="button"
          class="btn btn-outline-light"
          @click="resetForm"
        >
          {{ t('admin.about.cancel') }}
        </button>
      </div>
    </form>

    <hr class="border-secondary my-4">

    <h3 class="h6 fw-bold text-white mb-3">
      {{ t('admin.about.meCard.listTitle') }}
    </h3>

    <p
      v-if="isLoading"
      class="text-body-secondary mb-0"
    >
      {{ t('admin.about.loading') }}
    </p>
    <p
      v-else-if="hasError"
      class="text-danger mb-0"
      role="alert"
    >
      {{ t('admin.about.loadError') }}
    </p>
    <p
      v-else-if="0 === cards.length"
      class="text-body-secondary mb-0"
    >
      {{ t('admin.about.meCard.empty') }}
    </p>
    <div
      v-else
      class="table-responsive"
    >
      <table class="table table-dark table-hover align-middle mb-0">
        <thead>
          <tr>
            <th scope="col">
              {{ t('admin.about.meCard.categoryLabel') }}
            </th>
            <th scope="col">
              {{ t('admin.about.meCard.titleLabel') }}
            </th>
            <th scope="col">
              {{ t('admin.about.meCard.iconKeyLabel') }}
            </th>
            <th scope="col">
              {{ t('admin.about.meCard.positionLabel') }}
            </th>
            <th scope="col">
              <span class="visually-hidden">{{ t('admin.about.actions') }}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="card in cards"
            :key="card.id"
          >
            <td>{{ categoryLabel(card.category) }}</td>
            <td>{{ card.title }}</td>
            <td>{{ card.iconKey ?? '—' }}</td>
            <td>{{ card.position }}</td>
            <td class="text-end">
              <button
                type="button"
                class="btn btn-sm btn-outline-light me-2"
                @click="startEdit(card)"
              >
                {{ t('admin.about.meCard.editAction') }}
              </button>
              <button
                type="button"
                class="btn btn-sm btn-outline-danger"
                @click="handleDelete(card)"
              >
                {{ t('admin.about.delete') }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
