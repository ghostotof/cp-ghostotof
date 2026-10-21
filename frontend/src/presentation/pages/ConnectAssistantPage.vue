<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePortfolioContent } from '../../application/portfolio/usePortfolioContent'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import { clientConfiguration, mcpServerUrl } from '../../domain/mcp/services/mcpClientConfiguration'
import CopyableCode from '../ui/CopyableCode.vue'
import RichText from '../ui/RichText.vue'

/**
 * Page « Brancher votre assistant » (spec 0007, phase 1, issue #645) : comment
 * ajouter le serveur MCP du site à un client MCP, ce qu'il sert et ce qu'il ne
 * sert jamais. Contenu statique (`infrastructure/portfolio/content`), sauf l'URL
 * du serveur, qui ne se connaît qu'à l'exécution.
 */
const { locale } = useI18n()
const { connectAssistantContent: content } = usePortfolioContent()

const currentLocale = computed(() => locale.value as Locale)

/**
 * L'origine de la page, celle que le serveur accepte (`allowed_hosts`) : la même
 * image sert la préprod et la prod, aucune URL n'est donc écrite au build. Lue
 * une fois : l'origine ne change pas sans rechargement.
 *
 * Juste en prod, où l'Ingress sert `/api` sur l'hôte de la page. En préprod,
 * l'URL est juste mais derrière Basic Auth, que claude.ai, ChatGPT et Cursor
 * n'envoient pas (seul Claude Code, par un en-tête, s'y branche ; levée sur les
 * chemins MCP à la tâche 14 de la spec 0007). Fausse sous le serveur de dev Vite
 * (`localhost:5173`, qui ne relaie pas `/api`). Accepté : la page s'adresse aux
 * visiteurs du site déployé, et la spec fixe l'origine comme source.
 */
const serverUrl = mcpServerUrl(window.location.origin)
</script>

<template>
  <section class="container-xl py-5 d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <p class="d-flex align-items-center gap-2 text-eyebrow text-uppercase small fw-semibold mb-3">
        <span
          class="rounded-circle bg-primary"
          style="width: 0.4rem; height: 0.4rem"
          aria-hidden="true"
        />
        {{ content.eyebrow }}
      </p>
      <h1 class="h3 fw-bold text-white mb-3">
        {{ content.title }}
      </h1>
      <p
        v-for="(paragraph, index) in content.introduction"
        :key="index"
        class="text-body-secondary mb-2"
      >
        {{ paragraph }}
      </p>
    </div>

    <section
      class="surface-panel p-3 p-sm-4"
      aria-labelledby="mcp-server-url-heading"
      data-testid="mcp-server-url"
    >
      <h2
        id="mcp-server-url-heading"
        class="h5 fw-bold text-white mb-2"
      >
        {{ content.serverUrl.heading }}
      </h2>
      <p class="text-body-secondary">
        {{ content.serverUrl.description }}
      </p>
      <CopyableCode
        :code="serverUrl"
        :label="content.serverUrl.copyLabel"
      />
    </section>

    <section
      class="surface-panel p-3 p-sm-4"
      aria-labelledby="mcp-served-heading"
    >
      <h2
        id="mcp-served-heading"
        class="h5 fw-bold text-white mb-2"
      >
        {{ content.served.heading }}
      </h2>
      <RichText :text="content.served.description" />

      <div
        v-for="tier in content.served.tiers"
        :key="tier.id"
        class="border-top mt-3 pt-3"
        :data-testid="`mcp-tier-${tier.id}`"
      >
        <h3 class="h6 fw-bold text-white mb-1">
          {{ tier.heading }}
        </h3>
        <p class="text-body-secondary small">
          {{ tier.description }}
        </p>
        <ul class="text-body-secondary mb-0">
          <li
            v-for="served in tier.tools"
            :key="served.tool"
          >
            <code>{{ served.tool }}</code> — {{ served.description }}
          </li>
        </ul>
      </div>

      <div
        class="border-top mt-3 pt-3"
        data-testid="mcp-prompt"
      >
        <h3 class="h6 fw-bold text-white mb-1">
          {{ content.served.prompt.heading }}
        </h3>
        <p class="text-body-secondary mb-0">
          <code>{{ content.served.prompt.name }}</code> — {{ content.served.prompt.description }}
        </p>
        <RichText
          :text="content.served.prompt.arguments"
          paragraph-class="text-body-secondary small mt-1 mb-0"
        />
      </div>
    </section>

    <section
      class="surface-panel p-3 p-sm-4"
      aria-labelledby="mcp-never-served-heading"
      data-testid="mcp-never-served"
    >
      <h2
        id="mcp-never-served-heading"
        class="h5 fw-bold text-white mb-2"
      >
        {{ content.neverServed.heading }}
      </h2>
      <ul class="text-body-secondary">
        <li
          v-for="(item, index) in content.neverServed.items"
          :key="index"
        >
          <RichText
            :text="item"
            paragraph-class="mb-1"
          />
        </li>
      </ul>
      <p class="text-body-secondary small mb-0">
        {{ content.privacy.text }}
        <RouterLink :to="`/${currentLocale}/privacy-policy`">
          {{ content.privacy.linkLabel }}
        </RouterLink>
      </p>
    </section>

    <section
      class="surface-panel p-3 p-sm-4"
      aria-labelledby="mcp-clients-heading"
    >
      <h2
        id="mcp-clients-heading"
        class="h5 fw-bold text-white mb-2"
      >
        {{ content.clients.heading }}
      </h2>
      <p class="text-body-secondary">
        {{ content.clients.description }}
      </p>

      <div
        v-for="guide in content.clients.guides"
        :key="guide.id"
        class="border-top mt-3 pt-3"
        :data-testid="`mcp-client-${guide.id}`"
      >
        <h3 class="h6 fw-bold text-white mb-2">
          {{ guide.name }}
        </h3>
        <ol class="text-body-secondary">
          <li
            v-for="(step, index) in guide.steps"
            :key="index"
          >
            <RichText
              :text="step"
              paragraph-class="mb-1"
            />
          </li>
        </ol>
        <CopyableCode
          :code="clientConfiguration(guide.configuration.kind, serverUrl)"
          :label="guide.configuration.copyLabel"
        />
        <p
          v-if="guide.note"
          class="text-body-secondary small mt-2 mb-0"
        >
          {{ guide.note }}
        </p>
      </div>
    </section>
  </section>
</template>
