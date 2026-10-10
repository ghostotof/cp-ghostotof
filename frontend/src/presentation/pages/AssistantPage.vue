<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAssistant } from '../../application/assistant/useAssistant'
import { useCvDownload } from '../../application/cv/useCvDownload'
import { MAX_QUESTION_LENGTH } from '../../domain/assistant/services/conversationWindow'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import BaseTextarea from '../ui/BaseTextarea.vue'
import RichText from '../ui/RichText.vue'
import { retryAfterMinutes } from '../ui/retryAfterMinutes'

const { t, locale } = useI18n()
const { messages, state, error, draft, draftLength, canSend, send, reset } = useAssistant()
const { downloadCv, isDownloading, hasError: hasCvDownloadError } = useCvDownload()

const currentLocale = computed(() => locale.value as Locale)
const isStreaming = computed(() => 'streaming' === state.value)
const needsLogin = computed(() => 'unauthenticated' === error.value?.reason || 'forbidden' === error.value?.reason)

/** Un message par raison ; le 429 se lit avec ou sans délai (nginx n'en fournit pas). */
const errorMessage = computed<string>(() => {
  const failure = error.value
  if (null === failure) {
    return ''
  }
  switch (failure.reason) {
    case 'rate-limited': {
      if (null === failure.retryAfterSeconds) {
        return t('assistant.errors.rateLimited')
      }
      const minutes = retryAfterMinutes(failure.retryAfterSeconds)
      return t('assistant.errors.rateLimitedIn', { minutes }, minutes)
    }
    case 'too-large':
      return t('assistant.errors.tooLarge')
    case 'unauthenticated':
      return t('assistant.errors.unauthenticated')
    case 'forbidden':
      return t('assistant.errors.forbidden')
    case 'unavailable':
      return t('assistant.errors.unavailable')
    case 'network':
      return t('assistant.errors.network')
    case 'validation':
      return t('assistant.errors.validation')
    default:
      return t('assistant.errors.unknown')
  }
})

/**
 * Entrée envoie, Maj+Entrée saute une ligne. Pendant une composition IME,
 * Entrée valide le candidat de saisie : l'intercepter enverrait un texte à moitié écrit.
 * Safari valide un candidat avec `isComposing === false` mais `keyCode === 229` : même garde.
 */
function handleKeydown(event: KeyboardEvent): void {
  if ('Enter' !== event.key || event.shiftKey || event.isComposing || 229 === event.keyCode) {
    return
  }
  event.preventDefault()
  void send(currentLocale.value)
}
</script>

<template>
  <section class="container-xl py-5 d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <h1 class="h4 mb-3">
        {{ t('assistant.title') }}
      </h1>
      <p class="text-body-secondary mb-0">
        {{ t('assistant.intro') }}
      </p>
    </div>

    <!-- Permanent : ni alerte ni statut, c'est une consigne, pas un événement. -->
    <div
      class="surface-panel p-3 d-flex flex-column gap-2"
      data-testid="assistant-disclaimer"
    >
      <p class="fw-semibold mb-0">
        {{ t('assistant.disclaimer.notice') }}
      </p>
      <p class="text-body-secondary mb-0 d-flex flex-wrap align-items-center gap-3">
        {{ t('assistant.disclaimer.lead') }}
        <RouterLink :to="`/${currentLocale}/case-studies`">
          {{ t('assistant.disclaimer.caseStudies') }}
        </RouterLink>
        <RouterLink :to="`/${currentLocale}/anonymous-cv`">
          {{ t('assistant.disclaimer.anonymousCv') }}
        </RouterLink>
        <button
          type="button"
          class="btn btn-link p-0"
          :disabled="isDownloading"
          @click="downloadCv"
        >
          {{ t('assistant.disclaimer.downloadCv') }}
        </button>
      </p>
    </div>

    <div
      role="log"
      aria-live="polite"
      :aria-label="t('assistant.log')"
      :aria-busy="isStreaming"
      class="surface-panel p-3 p-sm-4 d-flex flex-column gap-3"
    >
      <p
        v-if="0 === messages.length"
        class="text-body-secondary mb-0"
      >
        {{ t('assistant.empty') }}
      </p>
      <div
        v-for="(message, index) in messages"
        :key="index"
      >
        <p class="text-eyebrow small fw-semibold text-uppercase mb-1">
          {{ t(`assistant.roles.${message.role}`) }}
        </p>
        <RichText :text="message.content" />
        <p
          v-if="'incomplete' === message.status"
          class="text-warning small mb-0"
        >
          {{ t('assistant.incomplete') }}
        </p>
      </div>
    </div>

    <!-- Le seul role="status" de la page : vide hors flux. -->
    <p
      role="status"
      class="text-body-secondary small mb-0"
    >
      {{ isStreaming ? t('assistant.answering') : '' }}
    </p>

    <!-- Second role="alert", distinct de celui de l'assistant : un échec de téléchargement n'est pas une erreur de conversation. -->
    <p
      v-if="hasCvDownloadError"
      class="text-danger small mb-0"
      role="alert"
    >
      {{ t('common.downloadCvError') }}
    </p>

    <p
      v-if="null !== error"
      role="alert"
      class="text-danger mb-0"
    >
      {{ errorMessage }}
      <RouterLink
        v-if="needsLogin"
        :to="{ name: 'login', params: { locale: currentLocale }, query: { redirect: `/${currentLocale}/assistant` } }"
      >
        {{ t('assistant.errors.relogin') }}
      </RouterLink>
    </p>

    <form
      class="surface-panel p-3 p-sm-4"
      @submit.prevent="send(currentLocale)"
    >
      <!-- Pas de maxlength HTML : il compte en UTF-16, le backend en points de code. -->
      <BaseTextarea
        id="assistant-question"
        v-model="draft"
        :label="t('assistant.question')"
        :rows="3"
        aria-describedby="assistant-counter"
        @keydown="handleKeydown"
      />
      <p
        id="assistant-counter"
        class="small mb-3"
        :class="draftLength > MAX_QUESTION_LENGTH ? 'text-danger' : 'text-body-secondary'"
      >
        {{ t('assistant.counter', { count: draftLength, max: MAX_QUESTION_LENGTH }) }}
      </p>
      <div class="d-flex flex-wrap gap-2">
        <button
          type="submit"
          class="btn btn-gradient"
          :disabled="!canSend"
          :aria-busy="isStreaming"
        >
          {{ t('assistant.send') }}
        </button>
        <button
          type="button"
          class="btn btn-outline-light assistant-reset"
          @click="reset"
        >
          {{ t('assistant.reset') }}
        </button>
      </div>
    </form>
  </section>
</template>
