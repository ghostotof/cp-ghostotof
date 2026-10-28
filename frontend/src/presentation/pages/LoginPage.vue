<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuth } from '../../application/auth/useAuth'
import { InvalidCredentialsError } from '../../domain/auth/errors/InvalidCredentialsError'
import { LoginRateLimitedError } from '../../domain/auth/errors/LoginRateLimitedError'
import { retryAfterMinutes } from '../ui/retryAfterMinutes'
import { isSupportedLocale, type Locale } from '../../domain/portfolio/entities/Locale'
import { isReauthentication } from '../router/reauthentication'
import { isSessionEnded } from '../router/sessionEnded'
import { internalRedirectTarget } from '../router/internalRedirect'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const { login, user } = useAuth()

/**
 * L'annonce « confirmez votre identité » ne vaut que pour une session encore
 * ouverte (`reauth=1` posé par le consentement MCP, #647) : une personne dont
 * la session a expiré entre-temps se connecte, tout simplement.
 */
const isReauthenticatingASession = computed(() => null !== user.value && isReauthentication(route))

/**
 * La page s'ouvre parce que la session a pris fin (#650, R5a) : une page de la
 * personne ou du backoffice s'est vu refuser sa session et l'a menée ici avec
 * `expired=1`. La personne n'est plus connectée (le drapeau n'ouvre rien).
 */
const hasSessionEnded = computed(() => isSessionEnded(route))

const username = ref('')
const password = ref('')
const isSubmitting = ref(false)
const errorMessage = ref('')

function currentLocale(): Locale {
  const routeLocale = route.params.locale
  return typeof routeLocale === 'string' && isSupportedLocale(routeLocale) ? routeLocale : (locale.value as Locale)
}

/**
 * Posé par le garde d'authentification (presentation/router/index.ts) quand
 * une route protégée (ex. /admin) redirige ici faute de session : ramène
 * l'utilisateur là où il voulait aller plutôt qu'à l'accueil. Restreint aux
 * chemins internes (`internalRedirectTarget`, qui dit pourquoi et comment) pour
 * ne jamais rediriger vers une origine externe (open redirect) à partir d'un
 * paramètre d'URL.
 */
function redirectTarget(): string {
  return internalRedirectTarget(route.query.redirect) ?? `/${currentLocale()}`
}

/** Un message par cause : ce que la personne doit faire n'est pas le même (corriger, attendre, réessayer). */
function loginErrorMessage(error: unknown): string {
  if (error instanceof InvalidCredentialsError) {
    return t('auth.invalidCredentials')
  }
  if (error instanceof LoginRateLimitedError) {
    if (null === error.retryAfterSeconds) {
      return t('auth.rateLimited')
    }
    const minutes = retryAfterMinutes(error.retryAfterSeconds)
    return t('auth.rateLimitedIn', { minutes }, minutes)
  }
  return t('auth.genericError')
}

async function handleSubmit(): Promise<void> {
  errorMessage.value = ''
  isSubmitting.value = true

  try {
    await login(username.value, password.value)
    await router.push(redirectTarget())
  } catch (error) {
    errorMessage.value = loginErrorMessage(error)
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <section class="container-xl py-5">
    <div
      class="surface-panel p-3 p-sm-4 mx-auto"
      style="max-width: 24rem"
    >
      <h1 class="h3 fw-bold text-white mb-4">
        {{ t('auth.heading') }}
      </h1>

      <!-- La personne est déjà connectée : la page dit pourquoi elle s'ouvre. -->
      <p
        v-if="isReauthenticatingASession"
        class="text-body-secondary small"
        data-testid="login-reauth-notice"
      >
        {{ t('auth.reauthNotice') }}
      </p>

      <!--
        La session a pris fin : la page dit pourquoi elle s'ouvre. Une région d'état
        (role="status"), pas une alerte : rien n'est cassé, la personne se reconnecte.
      -->
      <p
        v-if="hasSessionEnded"
        role="status"
        class="text-body-secondary small"
        data-testid="login-session-ended-notice"
      >
        {{ t('auth.sessionEnded') }}
      </p>

      <form
        novalidate
        @submit.prevent="handleSubmit"
      >
        <div class="mb-3">
          <label
            for="login-username"
            class="form-label text-body-secondary"
          >
            {{ t('auth.usernameLabel') }}
          </label>
          <input
            id="login-username"
            v-model="username"
            type="text"
            class="form-control"
            autocomplete="username"
            required
          >
        </div>

        <div class="mb-3">
          <label
            for="login-password"
            class="form-label text-body-secondary"
          >
            {{ t('auth.passwordLabel') }}
          </label>
          <input
            id="login-password"
            v-model="password"
            type="password"
            class="form-control"
            autocomplete="current-password"
            required
          >
        </div>

        <p
          v-if="errorMessage"
          class="text-danger small"
          role="alert"
        >
          {{ errorMessage }}
        </p>

        <button
          type="submit"
          class="btn btn-gradient w-100"
          :disabled="isSubmitting"
        >
          {{ t('auth.submit') }}
        </button>
      </form>
    </div>
  </section>
</template>
