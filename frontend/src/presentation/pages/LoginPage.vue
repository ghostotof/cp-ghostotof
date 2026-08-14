<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuth } from '../../application/auth/useAuth'
import { InvalidCredentialsError } from '../../domain/auth/errors/InvalidCredentialsError'
import { isSupportedLocale, type Locale } from '../../domain/portfolio/entities/Locale'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const { login } = useAuth()

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
 * chemins relatifs internes (un seul "/" en tête) pour ne jamais rediriger
 * vers une origine externe (open redirect) à partir d'un query param.
 */
function redirectTarget(): string {
  const redirect = route.query.redirect
  if ('string' === typeof redirect && redirect.startsWith('/') && !redirect.startsWith('//')) {
    return redirect
  }
  return `/${currentLocale()}`
}

async function handleSubmit(): Promise<void> {
  errorMessage.value = ''
  isSubmitting.value = true

  try {
    await login(username.value, password.value)
    await router.push(redirectTarget())
  } catch (error) {
    errorMessage.value = error instanceof InvalidCredentialsError ? t('auth.invalidCredentials') : t('auth.genericError')
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
