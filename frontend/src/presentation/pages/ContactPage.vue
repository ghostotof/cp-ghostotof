<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useContactForm } from '../../application/contact/useContactForm'
import BaseButton from '../ui/BaseButton.vue'

const { t } = useI18n()
const { name, email, message, honeypot, isSubmitting, isSuccess, hasError, submit } = useContactForm()

const CONTACT_EMAIL = 'contact@cp-ghostotof.com'
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

      <p class="text-body-secondary mb-4">
        {{ t('contact.intro') }}
      </p>

      <BaseButton
        :href="`mailto:${CONTACT_EMAIL}`"
        icon-key="mail"
        variant="secondary"
      >
        {{ CONTACT_EMAIL }}
      </BaseButton>
    </div>

    <div
      class="surface-panel p-3 p-sm-4 mx-auto w-100"
      style="max-width: 32rem"
    >
      <h2 class="h5 fw-bold text-white mb-4">
        {{ t('contact.form.heading') }}
      </h2>

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
          <input
            id="contact-name"
            v-model="name"
            type="text"
            class="form-control"
            autocomplete="name"
            maxlength="100"
            required
          >
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
            required
          >
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
            maxlength="5000"
            required
          />
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

        <p
          v-if="isSuccess"
          class="text-success small"
          role="status"
        >
          {{ t('contact.form.success') }}
        </p>
        <p
          v-if="hasError"
          class="text-danger small"
          role="alert"
        >
          {{ t('contact.form.error') }}
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
