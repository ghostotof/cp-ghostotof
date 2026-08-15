<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAdminAboutSiteCards } from '../../../application/admin/about/useAdminAboutSiteCards'
import BaseTextInput from '../../ui/BaseTextInput.vue'
import BaseTextarea from '../../ui/BaseTextarea.vue'
import BaseNumberInput from '../../ui/BaseNumberInput.vue'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import type { AdminAboutSiteCard } from '../../../domain/admin/about/entities/AdminAboutSiteCard'

const props = defineProps<{ locale: Locale }>()

const { t } = useI18n()

const { cards, isLoading, hasError, errorMessage, load, create, update, remove } = useAdminAboutSiteCards()

watch(() => props.locale, load, { immediate: true })

const editingId = ref<number | null>(null)
const form = reactive({ title: '', description: '', iconKey: '', position: 0 })
const isSubmitting = ref(false)
const isEditing = computed(() => null !== editingId.value)
const errorText = computed(() => (errorMessage.value ? t(`admin.about.errors.${errorMessage.value.reason}`) : null))

function resetForm(): void {
  editingId.value = null
  form.title = ''
  form.description = ''
  form.iconKey = ''
  form.position = 0
}

function startEdit(card: AdminAboutSiteCard): void {
  editingId.value = card.id
  form.title = card.title
  form.description = card.description
  form.iconKey = card.iconKey ?? ''
  form.position = card.position
}

async function handleSubmit(): Promise<void> {
  isSubmitting.value = true

  const input = {
    locale: props.locale,
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

async function handleDelete(card: AdminAboutSiteCard): Promise<void> {
  if (!window.confirm(t('admin.about.siteCard.confirmDelete', { title: card.title }))) {
    return
  }

  await remove(card.id)
}
</script>

<template>
  <div class="surface-panel p-3 p-sm-4">
    <h2 class="h6 fw-bold text-white mb-3">
      {{ isEditing ? t('admin.about.siteCard.edit') : t('admin.about.siteCard.create') }}
    </h2>

    <form
      novalidate
      @submit.prevent="handleSubmit"
    >
      <BaseTextInput
        id="admin-about-site-card-title"
        v-model="form.title"
        :label="t('admin.about.siteCard.titleLabel')"
        required
      />
      <BaseTextarea
        id="admin-about-site-card-description"
        v-model="form.description"
        :label="t('admin.about.siteCard.descriptionLabel')"
        required
      />
      <BaseTextInput
        id="admin-about-site-card-icon-key"
        v-model="form.iconKey"
        :label="t('admin.about.siteCard.iconKeyLabel')"
      />
      <BaseNumberInput
        id="admin-about-site-card-position"
        v-model="form.position"
        :label="t('admin.about.siteCard.positionLabel')"
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
      {{ t('admin.about.siteCard.listTitle') }}
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
      {{ t('admin.about.siteCard.empty') }}
    </p>
    <div
      v-else
      class="table-responsive"
    >
      <table class="table table-dark table-hover align-middle mb-0">
        <thead>
          <tr>
            <th scope="col">
              {{ t('admin.about.siteCard.titleLabel') }}
            </th>
            <th scope="col">
              {{ t('admin.about.siteCard.iconKeyLabel') }}
            </th>
            <th scope="col">
              {{ t('admin.about.siteCard.positionLabel') }}
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
            <td>{{ card.title }}</td>
            <td>{{ card.iconKey ?? '—' }}</td>
            <td>{{ card.position }}</td>
            <td class="text-end">
              <button
                type="button"
                class="btn btn-sm btn-outline-light me-2"
                @click="startEdit(card)"
              >
                {{ t('admin.about.siteCard.editAction') }}
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
