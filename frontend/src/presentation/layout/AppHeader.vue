<script setup lang="ts">
import { ref } from 'vue'
import type { SiteIdentity } from '../../domain/portfolio/entities/SiteIdentity'
import type { NavigationLink } from '../../domain/portfolio/entities/NavigationLink'
import IconDownload from '~icons/lucide/download'
import IconMenu from '~icons/lucide/menu'
import IconX from '~icons/lucide/x'

defineProps<{
  siteIdentity: SiteIdentity
  navigationLinks: readonly NavigationLink[]
}>()

const isMobileMenuOpen = ref(false)

function navLinkClass(link: NavigationLink) {
  return {
    'nav-link-portfolio--active': link.isEnabled && link.href === '#hero',
    'nav-link-portfolio--disabled': !link.isEnabled,
  }
}
</script>

<template>
  <header class="sticky-top border-bottom" style="background: rgba(11, 10, 20, 0.85); backdrop-filter: blur(8px)">
    <div class="container-xl d-flex align-items-center justify-content-between gap-3 py-3">
      <a href="#hero" class="d-flex align-items-center gap-2 text-white text-decoration-none fw-semibold">
        <span
          class="d-inline-flex align-items-center justify-content-center rounded-circle text-white small"
          style="width: 2rem; height: 2rem; background: linear-gradient(135deg, #7c3aed, #4338ca)"
        >
          &lt;/&gt;
        </span>
        {{ siteIdentity.brandName }}
      </a>

      <nav class="d-none d-md-flex align-items-center gap-4 small">
        <a
          v-for="link in navigationLinks"
          :key="link.label"
          :href="link.isEnabled ? link.href : undefined"
          class="nav-link-portfolio"
          :class="navLinkClass(link)"
          :aria-disabled="!link.isEnabled"
        >
          {{ link.label }}
        </a>
      </nav>

      <div class="d-flex align-items-center gap-2">
        <a
          :href="siteIdentity.cvDownloadHref"
          class="btn btn-outline-light btn-sm d-none d-sm-inline-flex align-items-center gap-2"
        >
          {{ siteIdentity.cvDownloadLabel }}
          <IconDownload width="16" height="16" aria-hidden="true" />
        </a>
        <a
          :href="siteIdentity.cvDownloadHref"
          class="btn btn-outline-light btn-sm d-sm-none d-inline-flex align-items-center"
          :aria-label="siteIdentity.cvDownloadLabel"
        >
          <IconDownload width="16" height="16" aria-hidden="true" />
        </a>

        <button
          type="button"
          class="btn btn-outline-light btn-sm d-md-none d-inline-flex align-items-center justify-content-center"
          aria-controls="mobile-nav"
          :aria-expanded="isMobileMenuOpen"
          :aria-label="isMobileMenuOpen ? 'Fermer le menu' : 'Ouvrir le menu'"
          @click="isMobileMenuOpen = !isMobileMenuOpen"
        >
          <IconX v-if="isMobileMenuOpen" width="18" height="18" aria-hidden="true" />
          <IconMenu v-else width="18" height="18" aria-hidden="true" />
        </button>
      </div>
    </div>

    <nav v-if="isMobileMenuOpen" id="mobile-nav" class="d-md-none border-top" style="background: rgba(11, 10, 20, 0.95)">
      <div class="container-xl d-flex flex-column py-2">
        <a
          v-for="link in navigationLinks"
          :key="link.label"
          :href="link.isEnabled ? link.href : undefined"
          class="nav-link-portfolio d-block py-2"
          :class="navLinkClass(link)"
          :aria-disabled="!link.isEnabled"
          @click="isMobileMenuOpen = false"
        >
          {{ link.label }}
        </a>
      </div>
    </nav>
  </header>
</template>
