<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { useContactForm } from '../../application/contact/useContactForm'
import { isSupportedLocale, type Locale } from '../../domain/portfolio/entities/Locale'

const { t, locale } = useI18n()
const route = useRoute()
const { name, email, message, honeypot, isSubmitting, isSuccess, errorReason, fieldErrors, submit } = useContactForm()

/** Même repli route → i18n que AppHeader.homeLink, pour construire le lien vers la politique de confidentialité. */
const currentLocale = computed<Locale>(() => {
  const routeLocale = route.params.locale
  return typeof routeLocale === 'string' && isSupportedLocale(routeLocale) ? routeLocale : (locale.value as Locale)
})

/** Les champs auxquels la page sait rattacher un libellé, sous le champ concerné. */
const KNOWN_FIELDS = ['name', 'email', 'message'] as const

const hasFlaggedField = computed(() => KNOWN_FIELDS.some((field) => fieldErrors.value.has(field)))

/**
 * Un champ signalé le reste jusqu'à la soumission suivante : on ne retire pas
 * le message à la frappe. Choix assumé — l'acceptation d'une saisie est une
 * décision du serveur (contraintes normalisées, `trim`), la deviner côté page
 * afficherait « c'est bon » sur une valeur qui sera refusée de nouveau. Le
 * composable remet l'ensemble à zéro au début de chaque `submit()`.
 */
const isFlagged = (field: (typeof KNOWN_FIELDS)[number]): boolean => fieldErrors.value.has(field)

/**
 * `aria-describedby` porte en permanence l'aide de saisie et lui ajoute le
 * message d'erreur quand le champ est signalé — dans cet ordre : la règle
 * d'abord, le refus ensuite. Remplacer l'aide par l'erreur priverait le
 * lecteur d'écran de la borne au moment précis où elle lui sert.
 */
const describedBy = (field: (typeof KNOWN_FIELDS)[number], hasHint: boolean): string | undefined => {
  const ids = [
    ...(hasHint ? [`contact-${field}-hint`] : []),
    ...(isFlagged(field) ? [`contact-${field}-error`] : []),
  ]

  return 0 === ids.length ? undefined : ids.join(' ')
}

/**
 * Message global, sous le formulaire. Les libellés sont ceux de la page, pas
 * ceux du backend : celui-ci écrit ses messages de contrainte en français en
 * dur (cf. ContactValidationError), un visiteur anglophone lirait du français.
 *
 * Le repli de `validation` compte : un 422 sans violation exploitable (corps
 * absent, ou `propertyPath` inconnu comme le honeypot) ne doit pas afficher
 * « vérifiez les champs signalés » alors qu'aucun ne l'est.
 */
const globalErrorText = computed<string | null>(() => {
  const reason = errorReason.value

  if (null === reason) {
    return null
  }
  if ('rate-limited' === reason) {
    return t('contact.form.errors.rateLimited')
  }
  if ('validation' === reason) {
    return hasFlaggedField.value ? t('contact.form.errors.validation') : t('contact.form.errors.validationGeneric')
  }

  return t('contact.form.errors.unknown')
})
</script>

<template>
  <section class="container-xl py-5 d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <h1 class="d-flex align-items-center gap-2 text-eyebrow text-uppercase small fw-semibold mb-4">
        <span
          class="rounded-circle bg-primary"
          style="width: 0.4rem; height: 0.4rem"
          aria-hidden="true"
        />
        {{ t('contact.eyebrow') }}
      </h1>

      <p class="text-body-secondary mb-0">
        {{ t('contact.intro') }}
      </p>
    </div>

    <div
      class="surface-panel p-3 p-sm-4 mx-auto w-100"
      style="max-width: 32rem"
    >
      <h2 class="h5 fw-bold text-white mb-4">
        {{ t('contact.form.heading') }}
      </h2>

      <!--
        `novalidate` est volontaire : les bulles de validation natives sont
        rendues dans la langue du navigateur, pas dans celle du site — un
        visiteur anglophone du site lirait un message en français, ou
        l'inverse. Conséquence à assumer pour les trois champs : sous
        `novalidate`, `minlength`/`maxlength` ne bloquent plus rien, ils
        restent déclaratifs (bornes du Validator backend, lisibles par les
        outils et le remplissage automatique). Ce qui prévient réellement la
        saisie trop courte, c'est l'aide visible sous le champ (`*-hint`),
        reliée en permanence par `aria-describedby`.
      -->
      <form
        novalidate
        @submit.prevent="submit"
      >
        <div class="mb-3">
          <label
            for="contact-name"
            class="form-label text-body-secondary"
          >
            {{ t('contact.form.nameLabel') }}
          </label>
          <!-- Bornes du Validator backend (Length(min: 2, max: 100) sur ContactMessageResource). -->
          <input
            id="contact-name"
            v-model="name"
            type="text"
            class="form-control"
            autocomplete="name"
            minlength="2"
            maxlength="100"
            :aria-invalid="isFlagged('name') ? 'true' : undefined"
            :aria-describedby="describedBy('name', true)"
            required
          >
          <p
            id="contact-name-hint"
            class="form-text text-body-secondary small mt-1 mb-0"
          >
            {{ t('contact.form.hints.name') }}
          </p>
          <!-- text-danger-emphasis, pas text-danger : même raison que .text-eyebrow (style.css), le contraste AA. -->
          <p
            v-if="isFlagged('name')"
            id="contact-name-error"
            class="text-danger-emphasis small mt-1 mb-0"
          >
            {{ t('contact.form.fieldErrors.name') }}
          </p>
        </div>

        <div class="mb-3">
          <label
            for="contact-email"
            class="form-label text-body-secondary"
          >
            {{ t('contact.form.emailLabel') }}
          </label>
          <input
            id="contact-email"
            v-model="email"
            type="email"
            class="form-control"
            autocomplete="email"
            maxlength="255"
            :aria-invalid="isFlagged('email') ? 'true' : undefined"
            :aria-describedby="describedBy('email', false)"
            required
          >
          <!-- Pas d'aide de saisie ici : « une adresse e-mail valide » n'ajoute rien au libellé du champ. -->
          <!-- text-danger-emphasis, pas text-danger : même raison que .text-eyebrow (style.css), le contraste AA. -->
          <p
            v-if="isFlagged('email')"
            id="contact-email-error"
            class="text-danger-emphasis small mt-1 mb-0"
          >
            {{ t('contact.form.fieldErrors.email') }}
          </p>
        </div>

        <div class="mb-3">
          <label
            for="contact-message"
            class="form-label text-body-secondary"
          >
            {{ t('contact.form.messageLabel') }}
          </label>
          <textarea
            id="contact-message"
            v-model="message"
            class="form-control"
            rows="5"
            minlength="10"
            maxlength="5000"
            :aria-invalid="isFlagged('message') ? 'true' : undefined"
            :aria-describedby="describedBy('message', true)"
            required
          />
          <p
            id="contact-message-hint"
            class="form-text text-body-secondary small mt-1 mb-0"
          >
            {{ t('contact.form.hints.message') }}
          </p>
          <!-- text-danger-emphasis, pas text-danger : même raison que .text-eyebrow (style.css), le contraste AA. -->
          <p
            v-if="isFlagged('message')"
            id="contact-message-error"
            class="text-danger-emphasis small mt-1 mb-0"
          >
            {{ t('contact.form.fieldErrors.message') }}
          </p>
        </div>

        <!--
          Honeypot anti-spam : masqué visuellement et retiré des technologies
          d'assistance (jamais rempli par un humain, jamais atteint au clavier),
          mais présent dans le DOM pour les bots de formulaire qui remplissent
          aveuglément tous les champs. Voir App\Contact\Presentation\ApiResource\ContactMessageResource
          côté backend pour le traitement (silencieusement ignoré).
        -->
        <div
          class="visually-hidden"
          aria-hidden="true"
        >
          <label for="contact-website">{{ t('contact.form.honeypotLabel') }}</label>
          <input
            id="contact-website"
            v-model="honeypot"
            type="text"
            tabindex="-1"
            autocomplete="off"
          >
        </div>

        <p class="text-body-secondary small mb-3">
          {{ t('contact.form.privacyNoticePrefix') }}
          <RouterLink :to="`/${currentLocale}/privacy-policy`">
            {{ t('common.privacyPolicyLink') }}
          </RouterLink>
        </p>

        <!-- text-success-emphasis, pas text-success : même raison que .text-eyebrow (style.css), le contraste AA. -->
        <p
          v-if="isSuccess"
          class="text-success-emphasis small"
          role="status"
        >
          {{ t('contact.form.success') }}
        </p>
        <!--
          Pas de déplacement du focus après un refus : `role="alert"` fait
          annoncer le message par le lecteur d'écran sans voler le focus au
          visiteur, qui peut être en train de relire un champ. text-danger-emphasis,
          pas text-danger : même raison que .text-eyebrow (style.css), le contraste AA.
        -->
        <p
          v-if="globalErrorText"
          class="text-danger-emphasis small"
          role="alert"
        >
          {{ globalErrorText }}
        </p>

        <button
          type="submit"
          class="btn btn-gradient w-100"
          :disabled="isSubmitting"
        >
          {{ isSubmitting ? t('contact.form.submitting') : t('contact.form.submit') }}
        </button>
      </form>
    </div>
  </section>
</template>
