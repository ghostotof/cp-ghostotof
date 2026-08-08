<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import type { SiteIdentity } from '../../domain/portfolio/entities/SiteIdentity'
import type { NavigationLink } from '../../domain/portfolio/entities/NavigationLink'
import { isSupportedLocale, type Locale } from '../../domain/portfolio/entities/Locale'
import { useAuth } from '../../application/auth/useAuth'
import LocaleSwitcher from '../ui/LocaleSwitcher.vue'
import IconDownload from '~icons/lucide/download'
import IconMenu from '~icons/lucide/menu'
import IconX from '~icons/lucide/x'

defineProps<{
  siteIdentity: SiteIdentity
  navigationLinks: readonly NavigationLink[]
}>()

const isMobileMenuOpen = ref(false)
const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const { isAuthenticated, isChecking, logout } = useAuth()

async function handleLogout(): Promise<void> {
  await logout()
  await router.push(homeLink.value)
}

/**
 * Priorité au paramètre de route (source de vérité de l'URL) ; repli sur la locale
 * i18n courante quand la route active n'en a pas (ex. page 404, route catch-all
 * `/:pathMatch(.*)*` sans segment `:locale`).
 */
const homeLink = computed(() => {
  const routeLocale = route.params.locale
  const currentLocale = typeof routeLocale === 'string' && isSupportedLocale(routeLocale) ? routeLocale : (locale.value as Locale)
  return `/${currentLocale}`
})

/**
 * Un seul lien doit apparaître actif à la fois. Priorité à la correspondance
 * exacte (chemin + ancre, ex. "/#technologies"). À défaut, un lien de simple
 * page (ex. "/") n'est actif que si l'URL courante ne cible aucune ancre —
 * sinon "Accueil" et "Compétences" s'activeraient simultanément sur la landing page.
 */
function isActiveLink(link: NavigationLink): boolean {
  if (link.to === route.fullPath) {
    return true
  }
  if (route.hash) {
    return false
  }
  return link.to === route.path
}

function navLinkClass(link: NavigationLink) {
  return {
    'nav-link-portfolio--active': link.isEnabled && isActiveLink(link),
    'nav-link-portfolio--disabled': !link.isEnabled,
  }
}
</script>

<template>
  <header
    class="sticky-top border-bottom"
    style="background: rgba(11, 10, 20, 0.85); backdrop-filter: blur(8px)"
  >
    <div class="container-xl d-flex align-items-center justify-content-between gap-3 py-3">
      <RouterLink
        :to="homeLink"
        class="d-flex align-items-center gap-2 text-white text-decoration-none fw-semibold"
      >
        <!-- Glyphe décoratif du logo, pas du texte à traduire -->
        <span
          class="d-inline-flex align-items-center justify-content-center rounded-circle text-white small"
          style="width: 2rem; height: 2rem; background: linear-gradient(135deg, #7c3aed, #4338ca)"
          aria-hidden="true"
        >
          &lt;/&gt;
        </span>
        {{ siteIdentity.brandName }}
      </RouterLink>

      <nav class="d-none d-md-flex align-items-center gap-4 small">
        <template
          v-for="link in navigationLinks"
          :key="link.label"
        >
          <RouterLink
            v-if="link.isEnabled"
            :to="link.to"
            class="nav-link-portfolio"
            :class="navLinkClass(link)"
            aria-disabled="false"
          >
            {{ link.label }}
          </RouterLink>
          <a
            v-else
            class="nav-link-portfolio"
            :class="navLinkClass(link)"
            aria-disabled="true"
          >
            {{ link.label }}
          </a>
        </template>
      </nav>

      <div class="d-flex align-items-center gap-2">
        <LocaleSwitcher />

        <template v-if="!isChecking">
          <RouterLink
            v-if="!isAuthenticated"
            :to="`${homeLink}/login`"
            class="btn btn-outline-light btn-sm d-inline-flex align-items-center gap-2"
          >
            {{ t('common.login') }}
          </RouterLink>
          <template v-else>
            <!-- Lien vide pour le moment : le fichier réel sera servi par une ressource
                 protégée côté backend, pas encore branchée. -->
            <a
              href="#"
              class="btn btn-outline-light btn-sm d-none d-sm-inline-flex align-items-center gap-2"
            >
              {{ t('common.downloadCv') }}
              <IconDownload
                width="16"
                height="16"
                aria-hidden="true"
              />
            </a>
            <a
              href="#"
              class="btn btn-outline-light btn-sm d-sm-none d-inline-flex align-items-center"
              :aria-label="t('common.downloadCv')"
            >
              <IconDownload
                width="16"
                height="16"
                aria-hidden="true"
              />
            </a>
            <button
              type="button"
              class="btn btn-outline-light btn-sm d-inline-flex align-items-center"
              @click="handleLogout"
            >
              {{ t('common.logout') }}
            </button>
          </template>
        </template>

        <button
          type="button"
          class="btn btn-outline-light btn-sm d-md-none d-inline-flex align-items-center justify-content-center"
          aria-controls="mobile-nav"
          :aria-expanded="isMobileMenuOpen"
          :aria-label="isMobileMenuOpen ? t('common.closeMenu') : t('common.openMenu')"
          @click="isMobileMenuOpen = !isMobileMenuOpen"
        >
          <IconX
            v-if="isMobileMenuOpen"
            width="18"
            height="18"
            aria-hidden="true"
          />
          <IconMenu
            v-else
            width="18"
            height="18"
            aria-hidden="true"
          />
        </button>
      </div>
    </div>

    <nav
      v-if="isMobileMenuOpen"
      id="mobile-nav"
      class="d-md-none border-top"
      style="background: rgba(11, 10, 20, 0.95)"
    >
      <div class="container-xl d-flex flex-column py-2">
        <template
          v-for="link in navigationLinks"
          :key="link.label"
        >
          <RouterLink
            v-if="link.isEnabled"
            :to="link.to"
            class="nav-link-portfolio d-block py-2"
            :class="navLinkClass(link)"
            aria-disabled="false"
            @click="isMobileMenuOpen = false"
          >
            {{ link.label }}
          </RouterLink>
          <a
            v-else
            class="nav-link-portfolio d-block py-2"
            :class="navLinkClass(link)"
            aria-disabled="true"
            @click="isMobileMenuOpen = false"
          >
            {{ link.label }}
          </a>
        </template>
      </div>
    </nav>
  </header>
</template>
