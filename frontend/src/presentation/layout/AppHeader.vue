<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import type { SiteIdentity } from '../../domain/portfolio/entities/SiteIdentity'
import type { NavigationLink } from '../../domain/portfolio/entities/NavigationLink'
import { isSupportedLocale, type Locale } from '../../domain/portfolio/entities/Locale'
import { useAuth } from '../../application/auth/useAuth'
import { useBaseAccess } from '../../application/baseAccess/useBaseAccess'
import { useCvDownload } from '../../application/cv/useCvDownload'
import LocaleSwitcher from '../ui/LocaleSwitcher.vue'
import IconDownload from '~icons/lucide/download'
import IconZap from '~icons/lucide/zap'
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
const { tier, isChecking, isSuperAdmin, logout } = useAuth()
const { isDownloading, hasError: hasCvDownloadError, downloadCv } = useCvDownload()
const { isGranting, errorReason: baseAccessErrorReason, grant: grantBaseAccess } = useBaseAccess()

async function handleLogout(): Promise<void> {
  await logout()
  await router.push(homeLink.value)
}

/**
 * ADR 0003 D6 : un clic, sans identifiants. Le CTA n'est proposé qu'à
 * l'anonyme — au palier de base l'accès est déjà là, et au palier de
 * confiance rappeler l'endpoint remplacerait le cookie du compte par un
 * jeton D6, c'est-à-dire une rétrogradation. En cas de succès on mène au
 * contenu que ce palier porte (les études de cas, D5) : un bouton qui ne
 * donnerait visiblement rien serait le défaut que l'ADR nomme.
 */
async function handleInstantAccess(): Promise<void> {
  if (await grantBaseAccess()) {
    await router.push(`${homeLink.value}/case-studies`)
  }
}

const baseAccessErrorMessage = computed(() => {
  if (null === baseAccessErrorReason.value) {
    return null
  }
  return 'rate-limited' === baseAccessErrorReason.value
    ? t('common.instantAccessRateLimited')
    : t('common.instantAccessError')
})

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

      <nav
        class="d-none d-md-flex align-items-center gap-4 small"
        :aria-label="t('common.mainNavigation')"
      >
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
            :aria-current="isActiveLink(link) ? 'page' : undefined"
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
          <!-- Trois paliers (ADR 0003 D1) : anonyme → CTA + connexion ;
               palier de base → badge + connexion (un compte de confiance
               peut toujours se connecter par-dessus) ; palier de confiance →
               administration (si ROLE_SUPER), CV, déconnexion. -->
          <template v-if="'anonymous' === tier">
            <button
              type="button"
              class="btn btn-gradient btn-sm d-none d-sm-inline-flex align-items-center gap-2"
              :disabled="isGranting"
              @click="handleInstantAccess"
            >
              {{ isGranting ? t('common.instantAccessGranting') : t('common.instantAccess') }}
              <IconZap
                width="16"
                height="16"
                aria-hidden="true"
              />
            </button>
            <button
              type="button"
              class="btn btn-gradient btn-sm d-sm-none d-inline-flex align-items-center"
              :disabled="isGranting"
              :aria-label="isGranting ? t('common.instantAccessGranting') : t('common.instantAccess')"
              @click="handleInstantAccess"
            >
              <IconZap
                width="16"
                height="16"
                aria-hidden="true"
              />
            </button>
            <RouterLink
              :to="`${homeLink}/login`"
              class="btn btn-outline-light btn-sm d-inline-flex align-items-center gap-2"
            >
              {{ t('common.login') }}
            </RouterLink>
          </template>
          <template v-else-if="'base' === tier">
            <span class="badge rounded-pill text-bg-secondary fw-normal d-inline-flex align-items-center gap-1">
              <IconZap
                width="12"
                height="12"
                aria-hidden="true"
              />
              {{ t('common.baseAccessBadge') }}
            </span>
            <RouterLink
              :to="`${homeLink}/login`"
              class="btn btn-outline-light btn-sm d-inline-flex align-items-center gap-2"
            >
              {{ t('common.login') }}
            </RouterLink>
          </template>
          <template v-else>
            <RouterLink
              v-if="isSuperAdmin"
              :to="`${homeLink}/admin`"
              class="btn btn-outline-light btn-sm d-inline-flex align-items-center gap-2"
            >
              {{ t('common.administration') }}
            </RouterLink>
            <button
              type="button"
              class="btn btn-outline-light btn-sm d-none d-sm-inline-flex align-items-center gap-2"
              :disabled="isDownloading"
              @click="downloadCv"
            >
              {{ t('common.downloadCv') }}
              <IconDownload
                width="16"
                height="16"
                aria-hidden="true"
              />
            </button>
            <button
              type="button"
              class="btn btn-outline-light btn-sm d-sm-none d-inline-flex align-items-center"
              :disabled="isDownloading"
              :aria-label="t('common.downloadCv')"
              @click="downloadCv"
            >
              <IconDownload
                width="16"
                height="16"
                aria-hidden="true"
              />
            </button>
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

    <p
      v-if="hasCvDownloadError"
      class="container-xl text-danger small mb-2"
      role="alert"
    >
      {{ t('common.downloadCvError') }}
    </p>

    <p
      v-if="baseAccessErrorMessage"
      class="container-xl text-danger small mb-2"
      role="alert"
    >
      {{ baseAccessErrorMessage }}
    </p>

    <nav
      v-if="isMobileMenuOpen"
      id="mobile-nav"
      class="d-md-none border-top"
      style="background: rgba(11, 10, 20, 0.95)"
      :aria-label="t('common.mobileNavigation')"
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
            :aria-current="isActiveLink(link) ? 'page' : undefined"
            @click="isMobileMenuOpen = false"
          >
            {{ link.label }}
          </RouterLink>
          <!-- Lien désactivé : ni href, ni gestionnaire. Il n'était pas
               focusable au clavier, si bien que son @click ne pouvait servir
               qu'à la souris — et fermer le menu sans naviguer nulle part
               n'avait de toute façon pas de sens. -->
          <span
            v-else
            class="nav-link-portfolio d-block py-2"
            :class="navLinkClass(link)"
            aria-disabled="true"
          >
            {{ link.label }}
          </span>
        </template>
      </div>
    </nav>
  </header>
</template>
