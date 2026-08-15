<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAdminAboutSettings } from '../../../application/admin/about/useAdminAboutSettings'
import BaseTextInput from '../../ui/BaseTextInput.vue'
import type { Locale } from '../../../domain/portfolio/entities/Locale'

const props = defineProps<{ locale: Locale }>()

const { t } = useI18n()

const { settings, isLoading, hasError, errorMessage, load, save } = useAdminAboutSettings()

watch(() => props.locale, load, { immediate: true })

const form = reactive({ siteEyebrow: '', meEyebrow: '', technicalSubtitle: '', personalSubtitle: '', hobbiesSubtitle: '' })
const isSubmitting = ref(false)
const errorText = computed(() => (errorMessage.value ? t(`admin.about.errors.${errorMessage.value.reason}`) : null))

watch(
  settings,
  (value) => {
    if (value) {
      form.siteEyebrow = value.siteEyebrow
      form.meEyebrow = value.meEyebrow
      form.technicalSubtitle = value.technicalSubtitle
      form.personalSubtitle = value.personalSubtitle
      form.hobbiesSubtitle = value.hobbiesSubtitle
    }
  },
  { immediate: true },
)

async function handleSubmit(): Promise<void> {
  isSubmitting.value = true
  await save(props.locale, { ...form })
  isSubmitting.value = false
}
</script>

<template>
  <div class="surface-panel p-3 p-sm-4">
    <h2 class="h6 fw-bold text-white mb-3">
      {{ t('admin.about.settings.title') }}
    </h2>

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
    <form
      v-else
      novalidate
      @submit.prevent="handleSubmit"
    >
      <BaseTextInput
        id="admin-about-site-eyebrow"
        v-model="form.siteEyebrow"
        :label="t('admin.about.settings.siteEyebrowLabel')"
        required
      />
      <BaseTextInput
        id="admin-about-me-eyebrow"
        v-model="form.meEyebrow"
        :label="t('admin.about.settings.meEyebrowLabel')"
        required
      />
      <BaseTextInput
        id="admin-about-technical-subtitle"
        v-model="form.technicalSubtitle"
        :label="t('admin.about.settings.technicalSubtitleLabel')"
        required
      />
      <BaseTextInput
        id="admin-about-personal-subtitle"
        v-model="form.personalSubtitle"
        :label="t('admin.about.settings.personalSubtitleLabel')"
        required
      />
      <BaseTextInput
        id="admin-about-hobbies-subtitle"
        v-model="form.hobbiesSubtitle"
        :label="t('admin.about.settings.hobbiesSubtitleLabel')"
        required
      />

      <p
        v-if="errorText"
        class="text-danger small"
        role="alert"
      >
        {{ errorText }}
      </p>

      <button
        type="submit"
        class="btn btn-gradient"
        :disabled="isSubmitting"
      >
        {{ t('admin.about.save') }}
      </button>
    </form>
  </div>
</template>
