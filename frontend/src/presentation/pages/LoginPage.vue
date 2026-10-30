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
const { login, user, isAuthenticated } = useAuth()

/**
 * Un compte est affiché : la page ne s'ouvre pas pour se connecter mais pour confirmer son identité ou changer de
 * compte. Le critère est le compte affiché, pas `authState.sessionEnded` : le lien `expired=1` s'ouvre dans un nouvel
 * onglet, où l'application est neuve et `sessionEnded` toujours faux (A3-10, R30).
 */
const isSignedIn = computed(() => null !== user.value)

/**
 * L'annonce « confirmez votre identité » ne vaut que pour une session encore ouverte : `reauth=1` (posé par le
 * consentement MCP, #647, et « Changer de compte »), ou `expired=1` ouvert par une personne qui a encore un compte
 * affiché (B/F2 : un `BEARER` vivant sans `XSRF-TOKEN`, ou une session déjà rétablie dans un autre onglet) — à qui
 * « votre session a pris fin » serait faux. Une personne dont la session a expiré entre-temps se connecte, tout
 * simplement.
 */
const isReauthenticatingASession = computed(
  () => isSignedIn.value && (isReauthentication(route) || isSessionEnded(route)),
)

/**
 * La page s'ouvre parce que la session a pris fin (#650, R5a) : une page de la
 * personne ou du backoffice s'est vu refuser sa session et l'a menée ici avec
 * `expired=1`. Annoncé seulement au palier **anonyme** : avec un compte affiché, l'annonce neutre ci-dessus ; au
 * palier de base (« Accès instantané » obtenu dans un autre onglet depuis), l'accès tient et il n'y a aucun compte à
 * confirmer — ni l'une ni l'autre annonce, la page est la connexion ordinaire (#748, R40, CR4-7).
 */
const hasSessionEnded = computed(() => !isAuthenticated.value && isSessionEnded(route))

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
          class="text-danger-emphasis small"
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
