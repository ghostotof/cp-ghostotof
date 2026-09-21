<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAccountPasswordSetup } from '../../application/account/useAccountPasswordSetup'
import { isSupportedLocale, type Locale } from '../../domain/portfolio/entities/Locale'
import BaseTextInput from '../ui/BaseTextInput.vue'

/** Doit rester cohérent avec CpgUser::MIN_PASSWORD_LENGTH côté backend. */
const MIN_PASSWORD_LENGTH = 8

const { t, locale: i18nLocale } = useI18n()
const route = useRoute()
const router = useRouter()
const { state, errorReason, validate, submit } = useAccountPasswordSetup()

const password = ref('')
const confirmation = ref('')
const localFormError = ref<string | null>(null)

/**
 * Jeton du lien d'invitation. Il ne vit QUE dans cette variable : ni
 * localStorage ni sessionStorage, et plus dans l'URL une fois lu (cf.
 * `takeTokenFromUrl`). Volontairement pas un `ref` : rien ne l'affiche.
 */
let setupToken = ''

/**
 * Lit le jeton dans l'URL d'arrivée. Le fragment d'abord (`set-password#<jeton>`,
 * audit A7 / décision D6 : jamais envoyé au serveur), le segment de chemin
 * sinon (repli de compatibilité des liens déjà envoyés, retiré en T4.4). Le
 * fragment prime si les deux sont présents. `route.hash` est le
 * `location.hash` vu par vue-router.
 */
function readTokenFromUrl(): string {
  const fromHash = route.hash.startsWith('#') ? route.hash.slice(1) : ''
  if ('' !== fromHash) {
    return fromHash
  }

  return typeof route.params.token === 'string' ? route.params.token : ''
}

/**
 * Lit le jeton puis l'efface de l'URL affichée : il ne doit survivre ni dans
 * la barre d'adresse, ni dans l'historique, ni dans un copier-coller de l'URL.
 *
 * `router.replace` plutôt que `history.replaceState` : vue-router range dans
 * `history.state` le `fullPath` courant (`current`), fragment et segment
 * compris. Un `replaceState` qui préserverait cet état — indispensable pour ne
 * pas casser le routeur — y préserverait donc aussi le jeton, et laisserait
 * `route.fullPath` / `route.params.token` le porter en mémoire. Passer par le
 * routeur réécrit l'URL, `history.state` et la route courante d'un seul geste
 * cohérent, et rejoue `afterEach` (canonical/hreflang recalculés). Même
 * enregistrement de route avant et après : le composant n'est pas remonté,
 * `setupToken` survit.
 */
async function takeTokenFromUrl(): Promise<string> {
  const found = readTokenFromUrl()
  const urlCarriesSomething = '' !== route.hash || ('' !== route.params.token && undefined !== route.params.token)

  if (urlCarriesSomething) {
    try {
      await router.replace({ name: 'set-password', params: { locale: currentLocale(), token: '' }, hash: '' })
    } catch {
      // Un échec de navigation ne doit pas priver la personne de son
      // formulaire : le jeton est lu, on continue avec.
    }
  }

  return found
}

function currentLocale(): Locale {
  const routeLocale = route.params.locale
  return typeof routeLocale === 'string' && isSupportedLocale(routeLocale) ? routeLocale : (i18nLocale.value as Locale)
}

const loginPath = computed(() => `/${currentLocale()}/login`)

const showForm = computed(() => 'ready' === state.value || 'submitting' === state.value)

/**
 * Message d'erreur venu du backend. `invalid` / `expired` sont portés par
 * `state` (écran dédié), pas affichés ici.
 */
const apiErrorText = computed(() => {
  const reason = errorReason.value
  if (null === reason || 'invalid' === reason || 'expired' === reason) {
    return null
  }
  return t(`account.setPassword.errors.${reason}`)
})

function retry(): void {
  void validate(setupToken)
}

onMounted(async () => {
  setupToken = await takeTokenFromUrl()
  // Jeton vide -> `invalid` sans appel réseau (cf. useAccountPasswordSetup).
  await validate(setupToken)
})

async function handleSubmit(): Promise<void> {
  localFormError.value = null

  if (password.value.length < MIN_PASSWORD_LENGTH) {
    localFormError.value = t('account.setPassword.errors.tooShort', { min: MIN_PASSWORD_LENGTH })
    return
  }
  if (password.value !== confirmation.value) {
    localFormError.value = t('account.setPassword.errors.mismatch')
    return
  }

  await submit(setupToken, password.value)
}
</script>

<template>
  <section class="container-xl py-5">
    <div
      class="surface-panel p-3 p-sm-4 mx-auto"
      style="max-width: 26rem"
    >
      <p
        v-if="'checking' === state"
        class="text-body-secondary mb-0"
      >
        {{ t('account.setPassword.checking') }}
      </p>

      <p
        v-else-if="'invalid' === state"
        class="text-danger mb-0"
        role="alert"
      >
        {{ t('account.setPassword.invalid') }}
      </p>

      <p
        v-else-if="'expired' === state"
        class="text-danger mb-0"
        role="alert"
      >
        {{ t('account.setPassword.expired') }}
      </p>

      <div v-else-if="'error' === state">
        <p
          class="text-danger"
          role="alert"
        >
          {{ apiErrorText ?? t('account.setPassword.errors.unknown') }}
        </p>
        <button
          type="button"
          class="btn btn-outline-light btn-sm"
          @click="retry"
        >
          {{ t('account.setPassword.retry') }}
        </button>
      </div>

      <div v-else-if="'done' === state">
        <h1 class="h3 fw-bold text-white mb-3">
          {{ t('account.setPassword.successHeading') }}
        </h1>
        <p class="text-body-secondary">
          {{ t('account.setPassword.successBody') }}
        </p>
        <RouterLink
          :to="loginPath"
          class="btn btn-gradient"
        >
          {{ t('account.setPassword.toLogin') }}
        </RouterLink>
      </div>

      <template v-else-if="showForm">
        <h1 class="h3 fw-bold text-white mb-4">
          {{ t('account.setPassword.heading') }}
        </h1>

        <form
          novalidate
          @submit.prevent="handleSubmit"
        >
          <BaseTextInput
            id="set-password-password"
            v-model="password"
            type="password"
            :label="t('account.setPassword.passwordLabel')"
            required
          />
          <BaseTextInput
            id="set-password-confirmation"
            v-model="confirmation"
            type="password"
            :label="t('account.setPassword.confirmationLabel')"
            required
          />

          <p class="text-body-secondary small">
            {{ t('account.setPassword.hint', { min: MIN_PASSWORD_LENGTH }) }}
          </p>

          <p
            v-if="localFormError"
            class="text-danger small"
            role="alert"
          >
            {{ localFormError }}
          </p>
          <p
            v-else-if="apiErrorText"
            class="text-danger small"
            role="alert"
          >
            {{ apiErrorText }}
          </p>

          <button
            type="submit"
            class="btn btn-gradient w-100"
            :disabled="'submitting' === state"
          >
            {{ 'submitting' === state ? t('account.setPassword.submitting') : t('account.setPassword.submit') }}
          </button>
        </form>
      </template>
    </div>
  </section>
</template>
