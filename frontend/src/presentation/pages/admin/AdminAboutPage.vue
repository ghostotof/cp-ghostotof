<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAdminAboutSettings } from '../../../application/admin/about/useAdminAboutSettings'
import { useAdminAboutSiteCards } from '../../../application/admin/about/useAdminAboutSiteCards'
import { useAdminAboutMeCards } from '../../../application/admin/about/useAdminAboutMeCards'
import BaseTextInput from '../../ui/BaseTextInput.vue'
import BaseTextarea from '../../ui/BaseTextarea.vue'
import BaseNumberInput from '../../ui/BaseNumberInput.vue'
import BaseSelect from '../../ui/BaseSelect.vue'
import { LOCALE_NATIVE_NAMES, SUPPORTED_LOCALES, type Locale } from '../../../domain/portfolio/entities/Locale'
import type { AdminAboutSiteCard } from '../../../domain/admin/about/entities/AdminAboutSiteCard'
import type { AdminAboutMeCard, AdminAboutMeCardCategory } from '../../../domain/admin/about/entities/AdminAboutMeCard'

const { t } = useI18n()

const { settings, isLoading: isLoadingSettings, hasError: hasSettingsError, errorMessage: settingsErrorMessage, load: loadSettings, save: saveSettings } =
  useAdminAboutSettings()

const {
  cards: siteCards,
  isLoading: isLoadingSiteCards,
  hasError: hasSiteCardsError,
  errorMessage: siteCardErrorMessage,
  load: loadSiteCards,
  create: createSiteCard,
  update: updateSiteCard,
  remove: removeSiteCard,
} = useAdminAboutSiteCards()

const {
  cards: meCards,
  isLoading: isLoadingMeCards,
  hasError: hasMeCardsError,
  errorMessage: meCardErrorMessage,
  load: loadMeCards,
  create: createMeCard,
  update: updateMeCard,
  remove: removeMeCard,
} = useAdminAboutMeCards()

const localeOptions = SUPPORTED_LOCALES.map((locale) => ({ value: locale, label: LOCALE_NATIVE_NAMES[locale] }))
const selectedLocale = ref<Locale>('fr')

watch(
  selectedLocale,
  (locale) => {
    loadSettings(locale)
    loadSiteCards(locale)
    loadMeCards(locale)
  },
  { immediate: true },
)

// Réglages (formulaire unique, pré-rempli dès que settings est chargé, pas de liste).
const settingsForm = reactive({ siteEyebrow: '', meEyebrow: '', technicalSubtitle: '', personalSubtitle: '', hobbiesSubtitle: '' })
const isSubmittingSettings = ref(false)
const settingsErrorText = computed(() => (settingsErrorMessage.value ? t(`admin.about.errors.${settingsErrorMessage.value.reason}`) : null))

watch(
  settings,
  (value) => {
    if (value) {
      settingsForm.siteEyebrow = value.siteEyebrow
      settingsForm.meEyebrow = value.meEyebrow
      settingsForm.technicalSubtitle = value.technicalSubtitle
      settingsForm.personalSubtitle = value.personalSubtitle
      settingsForm.hobbiesSubtitle = value.hobbiesSubtitle
    }
  },
  { immediate: true },
)

async function handleSubmitSettings(): Promise<void> {
  isSubmittingSettings.value = true
  await saveSettings(selectedLocale.value, { ...settingsForm })
  isSubmittingSettings.value = false
}

// Cartes "à propos de ce site".
const editingSiteCardId = ref<number | null>(null)
const siteCardForm = reactive({ title: '', description: '', iconKey: '', position: 0 })
const isSubmittingSiteCard = ref(false)
const isEditingSiteCard = computed(() => null !== editingSiteCardId.value)
const siteCardErrorText = computed(() => (siteCardErrorMessage.value ? t(`admin.about.errors.${siteCardErrorMessage.value.reason}`) : null))

function resetSiteCardForm(): void {
  editingSiteCardId.value = null
  siteCardForm.title = ''
  siteCardForm.description = ''
  siteCardForm.iconKey = ''
  siteCardForm.position = 0
}

function startEditSiteCard(card: AdminAboutSiteCard): void {
  editingSiteCardId.value = card.id
  siteCardForm.title = card.title
  siteCardForm.description = card.description
  siteCardForm.iconKey = card.iconKey ?? ''
  siteCardForm.position = card.position
}

async function handleSubmitSiteCard(): Promise<void> {
  isSubmittingSiteCard.value = true

  const input = {
    locale: selectedLocale.value,
    title: siteCardForm.title,
    description: siteCardForm.description,
    iconKey: '' === siteCardForm.iconKey ? null : siteCardForm.iconKey,
    position: siteCardForm.position,
  }

  if (null !== editingSiteCardId.value) {
    await updateSiteCard(editingSiteCardId.value, input)
  } else {
    await createSiteCard(input)
  }

  isSubmittingSiteCard.value = false

  if (!siteCardErrorMessage.value) {
    resetSiteCardForm()
  }
}

async function handleDeleteSiteCard(card: AdminAboutSiteCard): Promise<void> {
  if (!window.confirm(t('admin.about.siteCard.confirmDelete', { title: card.title }))) {
    return
  }

  await removeSiteCard(card.id)
}

// Cartes "à propos de moi" (technical/personal/hobby).
const meCardCategoryOptions = computed<readonly { value: AdminAboutMeCardCategory; label: string }[]>(() => [
  { value: 'technical', label: t('admin.about.meCard.categoryTechnical') },
  { value: 'personal', label: t('admin.about.meCard.categoryPersonal') },
  { value: 'hobby', label: t('admin.about.meCard.categoryHobby') },
])

const editingMeCardId = ref<number | null>(null)
const meCardForm = reactive({ category: 'technical' as AdminAboutMeCardCategory, title: '', description: '', iconKey: '', position: 0 })
const isSubmittingMeCard = ref(false)
const isEditingMeCard = computed(() => null !== editingMeCardId.value)
const meCardErrorText = computed(() => (meCardErrorMessage.value ? t(`admin.about.errors.${meCardErrorMessage.value.reason}`) : null))

function resetMeCardForm(): void {
  editingMeCardId.value = null
  meCardForm.category = 'technical'
  meCardForm.title = ''
  meCardForm.description = ''
  meCardForm.iconKey = ''
  meCardForm.position = 0
}

function startEditMeCard(card: AdminAboutMeCard): void {
  editingMeCardId.value = card.id
  meCardForm.category = card.category
  meCardForm.title = card.title
  meCardForm.description = card.description
  meCardForm.iconKey = card.iconKey ?? ''
  meCardForm.position = card.position
}

async function handleSubmitMeCard(): Promise<void> {
  isSubmittingMeCard.value = true

  const input = {
    locale: selectedLocale.value,
    category: meCardForm.category,
    title: meCardForm.title,
    description: meCardForm.description,
    iconKey: '' === meCardForm.iconKey ? null : meCardForm.iconKey,
    position: meCardForm.position,
  }

  if (null !== editingMeCardId.value) {
    await updateMeCard(editingMeCardId.value, input)
  } else {
    await createMeCard(input)
  }

  isSubmittingMeCard.value = false

  if (!meCardErrorMessage.value) {
    resetMeCardForm()
  }
}

async function handleDeleteMeCard(card: AdminAboutMeCard): Promise<void> {
  if (!window.confirm(t('admin.about.meCard.confirmDelete', { title: card.title }))) {
    return
  }

  await removeMeCard(card.id)
}

function categoryLabel(category: AdminAboutMeCardCategory): string {
  return t(`admin.about.meCard.category${category.charAt(0).toUpperCase()}${category.slice(1)}`)
}
</script>

<template>
  <div class="d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <BaseSelect
        id="admin-about-locale"
        v-model="selectedLocale"
        :label="t('admin.localeLabel')"
        :options="localeOptions"
      />
    </div>

    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ t('admin.about.settings.title') }}
      </h2>

      <p
        v-if="isLoadingSettings"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.about.loading') }}
      </p>
      <p
        v-else-if="hasSettingsError"
        class="text-danger mb-0"
        role="alert"
      >
        {{ t('admin.about.loadError') }}
      </p>
      <form
        v-else
        novalidate
        @submit.prevent="handleSubmitSettings"
      >
        <BaseTextInput
          id="admin-about-site-eyebrow"
          v-model="settingsForm.siteEyebrow"
          :label="t('admin.about.settings.siteEyebrowLabel')"
          required
        />
        <BaseTextInput
          id="admin-about-me-eyebrow"
          v-model="settingsForm.meEyebrow"
          :label="t('admin.about.settings.meEyebrowLabel')"
          required
        />
        <BaseTextInput
          id="admin-about-technical-subtitle"
          v-model="settingsForm.technicalSubtitle"
          :label="t('admin.about.settings.technicalSubtitleLabel')"
          required
        />
        <BaseTextInput
          id="admin-about-personal-subtitle"
          v-model="settingsForm.personalSubtitle"
          :label="t('admin.about.settings.personalSubtitleLabel')"
          required
        />
        <BaseTextInput
          id="admin-about-hobbies-subtitle"
          v-model="settingsForm.hobbiesSubtitle"
          :label="t('admin.about.settings.hobbiesSubtitleLabel')"
          required
        />

        <p
          v-if="settingsErrorText"
          class="text-danger small"
          role="alert"
        >
          {{ settingsErrorText }}
        </p>

        <button
          type="submit"
          class="btn btn-gradient"
          :disabled="isSubmittingSettings"
        >
          {{ t('admin.about.save') }}
        </button>
      </form>
    </div>

    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ isEditingSiteCard ? t('admin.about.siteCard.edit') : t('admin.about.siteCard.create') }}
      </h2>

      <form
        novalidate
        @submit.prevent="handleSubmitSiteCard"
      >
        <BaseTextInput
          id="admin-about-site-card-title"
          v-model="siteCardForm.title"
          :label="t('admin.about.siteCard.titleLabel')"
          required
        />
        <BaseTextarea
          id="admin-about-site-card-description"
          v-model="siteCardForm.description"
          :label="t('admin.about.siteCard.descriptionLabel')"
          required
        />
        <BaseTextInput
          id="admin-about-site-card-icon-key"
          v-model="siteCardForm.iconKey"
          :label="t('admin.about.siteCard.iconKeyLabel')"
        />
        <BaseNumberInput
          id="admin-about-site-card-position"
          v-model="siteCardForm.position"
          :label="t('admin.about.siteCard.positionLabel')"
          :step="1"
        />

        <p
          v-if="siteCardErrorText"
          class="text-danger small"
          role="alert"
        >
          {{ siteCardErrorText }}
        </p>

        <div class="d-flex gap-2">
          <button
            type="submit"
            class="btn btn-gradient"
            :disabled="isSubmittingSiteCard"
          >
            {{ t('admin.about.save') }}
          </button>
          <button
            v-if="isEditingSiteCard"
            type="button"
            class="btn btn-outline-light"
            @click="resetSiteCardForm"
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
        v-if="isLoadingSiteCards"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.about.loading') }}
      </p>
      <p
        v-else-if="hasSiteCardsError"
        class="text-danger mb-0"
        role="alert"
      >
        {{ t('admin.about.loadError') }}
      </p>
      <p
        v-else-if="0 === siteCards.length"
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
              v-for="card in siteCards"
              :key="card.id"
            >
              <td>{{ card.title }}</td>
              <td>{{ card.iconKey ?? '—' }}</td>
              <td>{{ card.position }}</td>
              <td class="text-end">
                <button
                  type="button"
                  class="btn btn-sm btn-outline-light me-2"
                  @click="startEditSiteCard(card)"
                >
                  {{ t('admin.about.siteCard.editAction') }}
                </button>
                <button
                  type="button"
                  class="btn btn-sm btn-outline-danger"
                  @click="handleDeleteSiteCard(card)"
                >
                  {{ t('admin.about.delete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ isEditingMeCard ? t('admin.about.meCard.edit') : t('admin.about.meCard.create') }}
      </h2>

      <form
        novalidate
        @submit.prevent="handleSubmitMeCard"
      >
        <BaseSelect
          id="admin-about-me-card-category"
          v-model="meCardForm.category"
          :label="t('admin.about.meCard.categoryLabel')"
          :options="meCardCategoryOptions"
        />
        <BaseTextInput
          id="admin-about-me-card-title"
          v-model="meCardForm.title"
          :label="t('admin.about.meCard.titleLabel')"
          required
        />
        <BaseTextarea
          id="admin-about-me-card-description"
          v-model="meCardForm.description"
          :label="t('admin.about.meCard.descriptionLabel')"
          required
        />
        <BaseTextInput
          id="admin-about-me-card-icon-key"
          v-model="meCardForm.iconKey"
          :label="t('admin.about.meCard.iconKeyLabel')"
        />
        <BaseNumberInput
          id="admin-about-me-card-position"
          v-model="meCardForm.position"
          :label="t('admin.about.meCard.positionLabel')"
          :step="1"
        />

        <p
          v-if="meCardErrorText"
          class="text-danger small"
          role="alert"
        >
          {{ meCardErrorText }}
        </p>

        <div class="d-flex gap-2">
          <button
            type="submit"
            class="btn btn-gradient"
            :disabled="isSubmittingMeCard"
          >
            {{ t('admin.about.save') }}
          </button>
          <button
            v-if="isEditingMeCard"
            type="button"
            class="btn btn-outline-light"
            @click="resetMeCardForm"
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
        v-if="isLoadingMeCards"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.about.loading') }}
      </p>
      <p
        v-else-if="hasMeCardsError"
        class="text-danger mb-0"
        role="alert"
      >
        {{ t('admin.about.loadError') }}
      </p>
      <p
        v-else-if="0 === meCards.length"
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
              v-for="card in meCards"
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
                  @click="startEditMeCard(card)"
                >
                  {{ t('admin.about.meCard.editAction') }}
                </button>
                <button
                  type="button"
                  class="btn btn-sm btn-outline-danger"
                  @click="handleDeleteMeCard(card)"
                >
                  {{ t('admin.about.delete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
