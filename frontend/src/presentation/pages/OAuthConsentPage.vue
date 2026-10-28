<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useOAuthConsent } from '../../application/mcp/useOAuthConsent'
import { consentParamsFromQuery } from '../../domain/mcp/services/consentParams'
import { isSupportedLocale, type Locale } from '../../domain/portfolio/entities/Locale'
import { useDeliberateActivation } from '../ui/useDeliberateActivation'
import { REAUTH_QUERY, REAUTH_VALUE } from '../router/reauthentication'

/**
 * Consentement à une connexion MCP (#647, spec 0007 phase 2) : le point
 * d'autorisation renvoie ici la personne, la demande du client dans la requête.
 * La page en montre le résumé revalidé par le serveur, puis transmet la
 * décision et suit l'URL de retour vers le client.
 *
 * Ce que la personne doit pouvoir juger avant de cliquer : le nom du client
 * — celui sous lequel l'administrateur du site l'a enregistré (un client CIMD,
 * #651, déclarera le sien, que la page dira tel) —, l'hôte vers lequel elle
 * repartira, ce que la connexion ouvre (le corpus nominatif, en lecture) et
 * qu'il partira vers le fournisseur d'IA du client. Aucun texte ne nomme
 * l'auteur du site.
 *
 * Le compte qui accorde (#650) est celui que le serveur a lu sur le cookie de
 * CETTE requête, jamais un état gardé par la page. Un lien « Changer de
 * compte » mène à la connexion (`reauth=1`, comme après un refus pour
 * authentification trop ancienne), qui ramène à cette même demande ; il est
 * hors de la zone des boutons : ce n'est pas une décision.
 *
 * Les deux boutons ne s'activent qu'une fois la page visible et active depuis
 * un court délai (useDeliberateActivation, double-clickjacking). Accorder
 * exige une connexion au site de moins de dix minutes : au-delà, le serveur
 * refuse (`reauthentication-required`) et la page propose de se reconnecter,
 * la route de connexion honorant `reauth=1` pour une personne déjà connectée.
 */

const { t, locale: i18nLocale } = useI18n()
const route = useRoute()
const { state, request, failure, load, approve, deny } = useOAuthConsent()

const currentLocale = computed<Locale>(() => {
  const routeLocale = route.params.locale
  return typeof routeLocale === 'string' && isSupportedLocale(routeLocale) ? routeLocale : (i18nLocale.value as Locale)
})

const isSubmitting = computed(() => 'submitting' === state.value)
/** La demande et les boutons ne se montrent que tant qu'une décision est possible. */
const showRequest = computed(() => null !== request.value && ('ready' === state.value || isSubmitting.value))
// Le délai d'activation court depuis l'apparition des boutons, pas depuis le montage.
const { isArmed, guard } = useDeliberateActivation(showRequest)
const onApprove = guard(approve)
const onDeny = guard(deny)
/** Inactifs pendant la transmission, et tant que la page n'a pas été vue le délai voulu. */
const buttonsDisabled = computed(() => isSubmitting.value || !isArmed.value)

/** Le seul role="status" de la page : vide hors chargement et transmission. */
const statusMessage = computed<string>(() => {
  switch (state.value) {
    case 'loading':
      return t('oauthConsent.loading')
    case 'submitting':
      return t('oauthConsent.submitting')
    default:
      return ''
  }
})

const errorMessage = computed<string | null>(() => {
  if ('invalid' === state.value) {
    return t('oauthConsent.errors.invalid')
  }
  switch (failure.value) {
    case 'session-expired':
      return t('oauthConsent.errors.sessionExpired')
    case 'reauthentication-required':
      return t('oauthConsent.errors.reauthenticationRequired')
    case 'unsafe-redirect':
      return t('oauthConsent.errors.unsafeRedirect')
    case 'malformed-decision':
      return t('oauthConsent.errors.malformedDecision')
    case 'unknown':
      return t('oauthConsent.errors.unknown')
    default:
      return null
  }
})

/**
 * Se reconnecter ramène à cette même demande : `fullPath` garde toute la
 * requête. Après un refus pour authentification trop ancienne, la personne
 * est toujours connectée : `reauth=1` demande à la route de connexion de
 * s'ouvrir quand même (elle renvoie sinon une personne connectée à l'accueil).
 */
const reloginTarget = computed(() => ({
  name: 'login',
  params: { locale: currentLocale.value },
  query:
    'reauthentication-required' === failure.value
      ? { redirect: route.fullPath, [REAUTH_QUERY]: REAUTH_VALUE }
      : { redirect: route.fullPath },
}))

const reloginLabel = computed(() =>
  'reauthentication-required' === failure.value ? t('oauthConsent.errors.reauthenticate') : t('oauthConsent.errors.relogin'),
)

/**
 * « Changer de compte » : la connexion rouverte pour une personne déjà
 * connectée (`reauth=1`), avec toute la requête en retour (`fullPath`).
 */
const switchAccountTarget = computed(() => ({
  name: 'login',
  params: { locale: currentLocale.value },
  query: { redirect: route.fullPath, [REAUTH_QUERY]: REAUTH_VALUE },
}))

const offersRelogin = computed(() => 'session-expired' === failure.value || 'reauthentication-required' === failure.value)

onMounted(() => {
  // La requête telle que reçue ; une requête illisible donne `null`, que la
  // machine d'états lit comme une demande invalide, sans appel au serveur.
  void load(consentParamsFromQuery(route.query))
})
</script>

<template>
  <section class="container-xl py-5 d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <h1 class="h4 mb-3">
        {{ t('oauthConsent.title') }}
      </h1>
      <p class="text-body-secondary mb-0">
        {{ t('oauthConsent.intro') }}
      </p>
    </div>

    <p
      role="status"
      class="text-body-secondary small mb-0"
    >
      {{ statusMessage }}
    </p>

    <p
      v-if="null !== errorMessage"
      role="alert"
      class="text-danger mb-0"
    >
      {{ errorMessage }}
      <RouterLink
        v-if="offersRelogin"
        :to="reloginTarget"
      >
        {{ reloginLabel }}
      </RouterLink>
    </p>

    <template v-if="showRequest && null !== request">
      <section
        class="surface-panel p-3 p-sm-4"
        aria-labelledby="consent-account-heading"
      >
        <h2
          id="consent-account-heading"
          class="h5 mb-3"
        >
          {{ t('oauthConsent.account.heading') }}
        </h2>
        <!-- Texte interpolé, jamais v-html : l'identifiant vient d'une saisie. -->
        <p
          class="mb-2"
          data-testid="consent-account"
        >
          {{ t('oauthConsent.account.grantingAs', { username: request.account.username }) }}
        </p>
        <RouterLink
          :to="switchAccountTarget"
          data-testid="consent-switch-account"
        >
          {{ t('oauthConsent.account.switch') }}
        </RouterLink>
      </section>

      <section
        class="surface-panel p-3 p-sm-4"
        aria-labelledby="consent-client-heading"
      >
        <h2
          id="consent-client-heading"
          class="h5 mb-3"
        >
          {{ t('oauthConsent.client.heading') }}
        </h2>
        <dl class="mb-0">
          <dt>{{ t('oauthConsent.client.nameLabel') }}</dt>
          <dd data-testid="consent-client-name">
            <!-- Texte interpolé, jamais v-html : le nom vient d'une saisie, demain du client (CIMD). -->
            <span class="fw-semibold">{{ request.client.name }}</span>
            <span class="d-block small text-body-secondary">{{ t('oauthConsent.client.nameNotice') }}</span>
          </dd>
          <dt>{{ t('oauthConsent.client.registrationLabel') }}</dt>
          <dd>{{ t('oauthConsent.client.registration.preregistered') }}</dd>
          <dt>{{ t('oauthConsent.client.redirectLabel') }}</dt>
          <dd class="mb-0">
            <code data-testid="consent-redirect-host">{{ request.redirectHost }}</code>
            <span class="d-block small text-body-secondary">{{ t('oauthConsent.client.redirectNotice') }}</span>
          </dd>
        </dl>
      </section>

      <section
        class="surface-panel p-3 p-sm-4"
        aria-labelledby="consent-access-heading"
      >
        <h2
          id="consent-access-heading"
          class="h5 mb-3"
        >
          {{ t('oauthConsent.access.heading') }}
        </h2>
        <ul class="mb-0">
          <li>{{ t('oauthConsent.access.corpus') }}</li>
          <li>{{ t('oauthConsent.access.provider') }}</li>
        </ul>
      </section>

      <div
        class="d-flex flex-wrap gap-2"
        data-testid="consent-decision"
      >
        <button
          type="button"
          class="btn btn-gradient"
          data-testid="consent-approve"
          :disabled="buttonsDisabled"
          @click="onApprove"
        >
          {{ t('oauthConsent.approve') }}
        </button>
        <button
          type="button"
          class="btn btn-outline-light"
          data-testid="consent-deny"
          :disabled="buttonsDisabled"
          @click="onDeny"
        >
          {{ t('oauthConsent.deny') }}
        </button>
      </div>
    </template>
  </section>
</template>
