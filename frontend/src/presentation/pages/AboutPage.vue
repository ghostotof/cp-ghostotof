<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useAboutContent } from '../../application/about/useAboutContent'
import { useAuth } from '../../application/auth/useAuth'
import BaseCard from '../ui/BaseCard.vue'

const { t } = useI18n()
const { aboutContent, isLoading, hasError } = useAboutContent()
const { isAuthenticated } = useAuth()
</script>

<template>
  <section class="container-xl py-5 d-flex flex-column gap-4">
    <p
      v-if="isLoading"
      class="text-body-secondary mb-0"
    >
      {{ t('about.loading') }}
    </p>

    <p
      v-else-if="hasError || !aboutContent"
      class="text-danger mb-0"
      role="alert"
    >
      {{ t('about.error') }}
    </p>

    <template v-else>
      <div class="surface-panel p-3 p-sm-4">
        <h1 class="d-flex align-items-center gap-2 text-eyebrow text-uppercase small fw-semibold mb-4">
          <span
            class="rounded-circle bg-primary"
            style="width: 0.4rem; height: 0.4rem"
            aria-hidden="true"
          />
          {{ aboutContent.site.eyebrow }}
        </h1>

        <div class="row row-cols-1 row-cols-sm-2 g-3 mt-1">
          <div
            v-for="card in aboutContent.site.cards"
            :key="card.title"
            class="col"
          >
            <BaseCard
              :title="card.title"
              :description="card.description"
              :icon-key="card.iconKey"
              :heading-level="2"
            />
          </div>
        </div>
      </div>

      <div class="surface-panel p-3 p-sm-4">
        <h2 class="d-flex align-items-center gap-2 text-eyebrow text-uppercase small fw-semibold mb-4">
          <span
            class="rounded-circle bg-primary"
            style="width: 0.4rem; height: 0.4rem"
            aria-hidden="true"
          />
          {{ aboutContent.me.eyebrow }}
        </h2>

        <h3 class="h6 text-body-secondary text-uppercase mt-4 mb-3">
          {{ aboutContent.me.technicalSubtitle }}
        </h3>
        <div class="row row-cols-1 row-cols-sm-3 g-3">
          <div
            v-for="card in aboutContent.me.technicalCards"
            :key="card.title"
            class="col"
          >
            <BaseCard
              :title="card.title"
              :description="card.description"
              :icon-key="card.iconKey"
            />
          </div>
        </div>

        <h3 class="h6 text-body-secondary text-uppercase border-top mt-4 pt-4 mb-3">
          {{ aboutContent.me.personalSubtitle }}
        </h3>
        <div class="row row-cols-1 row-cols-sm-3 g-3">
          <div
            v-for="card in aboutContent.me.personalCards"
            :key="card.title"
            class="col"
          >
            <BaseCard
              :title="card.title"
              :description="card.description"
              :icon-key="card.iconKey"
            />
          </div>
        </div>

        <template v-if="isAuthenticated">
          <h3 class="h6 text-body-secondary text-uppercase border-top mt-4 pt-4 mb-3">
            {{ aboutContent.me.hobbiesSubtitle }}
          </h3>
          <div class="row row-cols-1 row-cols-sm-3 g-3">
            <div
              v-for="card in aboutContent.me.hobbiesCards"
              :key="card.title"
              class="col"
            >
              <BaseCard
                :title="card.title"
                :description="card.description"
                :icon-key="card.iconKey"
              />
            </div>
          </div>
        </template>
      </div>
    </template>
  </section>
</template>
