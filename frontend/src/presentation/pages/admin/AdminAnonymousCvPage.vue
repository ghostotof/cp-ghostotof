<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAdminAnonymousCvSections } from '../../../application/admin/anonymousCv/useAdminAnonymousCvSections'
import BaseTextInput from '../../ui/BaseTextInput.vue'
import BaseTextarea from '../../ui/BaseTextarea.vue'
import BaseNumberInput from '../../ui/BaseNumberInput.vue'
import BaseSelect from '../../ui/BaseSelect.vue'
import { SUPPORTED_LOCALES, type Locale } from '../../../domain/portfolio/entities/Locale'
import type { AdminAnonymousCvSection } from '../../../domain/admin/anonymousCv/entities/AdminAnonymousCvSection'

const { t } = useI18n()
const { sections, isLoading, hasError, errorMessage, create, update, remove } = useAdminAnonymousCvSections()

const editingId = ref<number | null>(null)

/** Typé explicitement, sinon `reactive` infère `locale` au littéral de sa valeur initiale (cf. AdminIncidentsPage). */
interface SectionForm {
  locale: Locale
  title: string
  skills: string
  yearsOfExperience: number
  achievements: string
  position: number
}

const form = reactive<SectionForm>({
  locale: SUPPORTED_LOCALES[0],
  title: '',
  skills: '',
  yearsOfExperience: 0,
  achievements: '',
  position: 0,
})
const isSubmitting = ref(false)

const isEditing = computed(() => null !== editingId.value)

const errorText = computed(() => (errorMessage.value ? t(`admin.anonymousCv.errors.${errorMessage.value.reason}`) : null))

const localeOptions = computed(() => SUPPORTED_LOCALES.map((locale) => ({ value: locale, label: locale.toUpperCase() })))

function resetForm(): void {
  editingId.value = null
  form.locale = SUPPORTED_LOCALES[0]
  form.title = ''
  form.skills = ''
  form.yearsOfExperience = 0
  form.achievements = ''
  form.position = 0
}

function startEdit(section: AdminAnonymousCvSection): void {
  editingId.value = section.id
  form.locale = section.locale as Locale
  form.title = section.title
  form.skills = section.skills
  form.yearsOfExperience = section.yearsOfExperience
  form.achievements = section.achievements
  form.position = section.position
}

async function handleSubmit(): Promise<void> {
  isSubmitting.value = true

  const input = {
    locale: form.locale,
    title: form.title,
    skills: form.skills,
    yearsOfExperience: form.yearsOfExperience,
    achievements: form.achievements,
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

async function handleDelete(section: AdminAnonymousCvSection): Promise<void> {
  if (!window.confirm(t('admin.anonymousCv.confirmDelete', { title: section.title }))) {
    return
  }

  await remove(section.id)
}
</script>

<template>
  <div class="d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ isEditing ? t('admin.anonymousCv.edit') : t('admin.anonymousCv.create') }}
      </h2>

      <!-- Rappel de la règle éditoriale (ADR 0003 D5) : ce qui rend le contenu
           non identifiant se décide ici, à la saisie — pas par un filtre. -->
      <p class="form-text mb-3">
        {{ t('admin.anonymousCv.identityHelp') }}
      </p>

      <form
        novalidate
        @submit.prevent="handleSubmit"
      >
        <BaseSelect
          id="admin-anonymous-cv-locale"
          v-model="form.locale"
          :label="t('admin.anonymousCv.localeLabel')"
          :options="localeOptions"
        />
        <BaseTextInput
          id="admin-anonymous-cv-title"
          v-model="form.title"
          :label="t('admin.anonymousCv.titleLabel')"
          required
        />
        <BaseTextarea
          id="admin-anonymous-cv-skills"
          v-model="form.skills"
          :label="t('admin.anonymousCv.skillsLabel')"
          :rows="3"
          required
        />
        <BaseNumberInput
          id="admin-anonymous-cv-years"
          v-model="form.yearsOfExperience"
          :label="t('admin.anonymousCv.yearsLabel')"
          required
        />
        <BaseTextarea
          id="admin-anonymous-cv-achievements"
          v-model="form.achievements"
          :label="t('admin.anonymousCv.achievementsLabel')"
          :rows="6"
          required
          aria-describedby="admin-anonymous-cv-achievements-help"
        />
        <div
          id="admin-anonymous-cv-achievements-help"
          class="form-text mb-3"
        >
          {{ t('admin.anonymousCv.achievementsHelp') }}
        </div>
        <BaseNumberInput
          id="admin-anonymous-cv-position"
          v-model="form.position"
          :label="t('admin.anonymousCv.positionLabel')"
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
            {{ t('admin.anonymousCv.save') }}
          </button>
          <button
            v-if="isEditing"
            type="button"
            class="btn btn-outline-light"
            @click="resetForm"
          >
            {{ t('admin.anonymousCv.cancel') }}
          </button>
        </div>
      </form>
    </div>

    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ t('admin.anonymousCv.listTitle') }}
      </h2>

      <p
        v-if="isLoading"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.anonymousCv.loading') }}
      </p>
      <p
        v-else-if="hasError"
        class="text-danger mb-0"
        role="alert"
      >
        {{ t('admin.anonymousCv.loadError') }}
      </p>
      <p
        v-else-if="0 === sections.length"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.anonymousCv.empty') }}
      </p>
      <div
        v-else
        class="table-responsive"
      >
        <table class="table table-dark table-hover align-middle mb-0">
          <thead>
            <tr>
              <th scope="col">
                {{ t('admin.anonymousCv.localeLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.anonymousCv.titleLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.anonymousCv.yearsLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.anonymousCv.positionLabel') }}
              </th>
              <th scope="col">
                <span class="visually-hidden">{{ t('admin.anonymousCv.actions') }}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="section in sections"
              :key="section.id"
            >
              <td>{{ section.locale.toUpperCase() }}</td>
              <td>{{ section.title }}</td>
              <td>{{ section.yearsOfExperience }}</td>
              <td>{{ section.position }}</td>
              <td class="text-end">
                <button
                  type="button"
                  class="btn btn-sm btn-outline-light me-2"
                  @click="startEdit(section)"
                >
                  {{ t('admin.anonymousCv.editAction') }}
                </button>
                <button
                  type="button"
                  class="btn btn-sm btn-outline-danger"
                  @click="handleDelete(section)"
                >
                  {{ t('admin.anonymousCv.deleteAction') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
