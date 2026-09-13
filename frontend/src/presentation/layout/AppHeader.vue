<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import type { SiteIdentity } from '../../domain/portfolio/entities/SiteIdentity'
import type { NavigationLink } from '../../domain/portfolio/entities/NavigationLink'
import type { NavigationGroup } from '../../domain/portfolio/entities/NavigationGroup'
import { isNavigationGroup, type NavigationEntry } from '../../domain/portfolio/entities/NavigationEntry'
import { isSupportedLocale, type Locale } from '../../domain/portfolio/entities/Locale'
import { useAuth } from '../../application/auth/useAuth'
import { useBaseAccess } from '../../application/baseAccess/useBaseAccess'
import { useCvDownload } from '../../application/cv/useCvDownload'
import LocaleSwitcher from '../ui/LocaleSwitcher.vue'
import IconDownload from '~icons/lucide/download'
import IconZap from '~icons/lucide/zap'
import IconMenu from '~icons/lucide/menu'
import IconX from '~icons/lucide/x'
import IconChevronDown from '~icons/lucide/chevron-down'

defineProps<{
  siteIdentity: SiteIdentity
  navigationLinks: readonly NavigationEntry[]
}>()

const isMobileMenuOpen = ref(false)

/**
 * Sous-menu desktop d'un groupe (issue #70) : même mécanique que le menu
 * « Contenu » d'AdminLayout — Bootstrap n'est chargé qu'en CSS, l'ouverture
 * et la fermeture (clic extérieur, Échap, clic sur un lien) sont gérées ici.
 * Un seul groupe ouvert à la fois, identifié par son libellé.
 */
const openGroupLabel = ref<string | null>(null)
const groupRefs = ref<Map<string, HTMLElement>>(new Map())

function setGroupRef(label: string, element: Element | null): void {
  if (element instanceof HTMLElement) {
    groupRefs.value.set(label, element)
  } else {
    groupRefs.value.delete(label)
  }
}

function toggleGroup(group: NavigationGroup): void {
  openGroupLabel.value = openGroupLabel.value === group.label ? null : group.label
}

function closeGroups(): void {
  openGroupLabel.value = null
}

function onDocumentClick(event: MouseEvent): void {
  if (null === openGroupLabel.value) {
    return
  }
  const container = groupRefs.value.get(openGroupLabel.value)
  if (undefined !== container && !container.contains(event.target as Node)) {
    closeGroups()
  }
}

function onDocumentKeydown(event: KeyboardEvent): void {
  if ('Escape' === event.key) {
    closeGroups()
  }
}

onMounted(() => {
  document.addEventListener('click', onDocumentClick)
  document.addEventListener('keydown', onDocumentKeydown)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
  document.removeEventListener('keydown', onDocumentKeydown)
})

/** Un groupe est « actif » quand l'un de ses liens l'est : le bouton hérite du soulignement. */
function isActiveGroup(group: NavigationGroup): boolean {
  return group.links.some((link) => link.isEnabled && isActiveLink(link))
}

/** Id stable et sûr pour aria-controls, dérivé du libellé (les libellés sont uniques par construction). */
function groupMenuId(group: NavigationGroup): string {
  return `nav-group-${group.label.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`
}
const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const { tier, isChecking, isSuperAdmin, logout } = useAuth()
const { isDownloading, hasError: hasCvDownloadError, downloadCv } = useCvDownload()
const { isGranting, errorReason: baseAccessErrorReason, grant: grantBaseAccess } = useBaseAccess()

/**
 * Sert aux deux sorties : la déconnexion d'un compte (palier de confiance)
 * et « Terminer cet accès » au palier de base (ADR 0003 D6, issue #65).
 * Même mécanique — POST /api/logout expire le cookie quel que soit le
 * porteur du jeton — seul le libellé diffère : il n'y a pas eu de
 * connexion au sens propre, donc pas de « déconnexion » à afficher.
 */
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
        class="d-flex align-items-center gap-2 text-white text-decoration-none fw-semibold text-nowrap"
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

      <!-- flex-wrap + text-nowrap : si la barre déborde malgré le
           regroupement (#70), un lien entier passe à la ligne, jamais un
           libellé coupé en deux. -->
      <nav
        class="d-none d-md-flex flex-wrap align-items-center column-gap-3 row-gap-1 small"
        :aria-label="t('common.mainNavigation')"
      >
        <template
          v-for="entry in navigationLinks"
          :key="entry.label"
        >
          <div
            v-if="isNavigationGroup(entry)"
            :ref="(element) => setGroupRef(entry.label, element as Element | null)"
            class="nav-group"
          >
            <button
              type="button"
              class="nav-link-portfolio nav-group__toggle text-nowrap d-inline-flex align-items-center gap-1"
              :class="{ 'nav-link-portfolio--active': isActiveGroup(entry) }"
              aria-haspopup="true"
              :aria-expanded="openGroupLabel === entry.label"
              :aria-controls="groupMenuId(entry)"
              @click="toggleGroup(entry)"
            >
              {{ entry.label }}
              <IconChevronDown
                width="14"
                height="14"
                aria-hidden="true"
              />
            </button>
            <ul
              v-if="openGroupLabel === entry.label"
              :id="groupMenuId(entry)"
              class="dropdown-menu show mt-1"
              data-bs-theme="dark"
              :aria-label="entry.label"
            >
              <li
                v-for="link in entry.links"
                :key="link.label"
              >
                <RouterLink
                  v-if="link.isEnabled"
                  :to="link.to"
                  class="dropdown-item"
                  :class="{ active: isActiveLink(link) }"
                  :aria-current="isActiveLink(link) ? 'page' : undefined"
                  @click="closeGroups"
                >
                  {{ link.label }}
                </RouterLink>
                <span
                  v-else
                  class="dropdown-item disabled"
                  aria-disabled="true"
                >
                  {{ link.label }}
                </span>
              </li>
            </ul>
          </div>
          <RouterLink
            v-else-if="entry.isEnabled"
            :to="entry.to"
            class="nav-link-portfolio text-nowrap"
            :class="navLinkClass(entry)"
            aria-disabled="false"
            :aria-current="isActiveLink(entry) ? 'page' : undefined"
          >
            {{ entry.label }}
          </RouterLink>
          <a
            v-else
            class="nav-link-portfolio text-nowrap"
            :class="navLinkClass(entry)"
            aria-disabled="true"
          >
            {{ entry.label }}
          </a>
        </template>
      </nav>

      <div class="d-flex align-items-center gap-2">
        <LocaleSwitcher />

        <template v-if="!isChecking">
          <!-- Trois paliers (ADR 0003 D1) : anonyme → CTA + connexion ;
               palier de base → badge + fin d'accès + connexion (un compte de
               confiance peut toujours se connecter par-dessus) ; palier de
               confiance → administration (si ROLE_SUPER), CV, déconnexion. -->
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
            <!-- Sortir avant l'expiration du jeton (15 min) : sur un poste
                 partagé, fermer l'onglet laisserait le cookie httpOnly actif. -->
            <button
              type="button"
              class="btn btn-outline-light btn-sm d-inline-flex align-items-center text-nowrap"
              @click="handleLogout"
            >
              {{ t('common.endBaseAccess') }}
            </button>
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
        <!-- Sur mobile, un groupe se déplie à plat : un intitulé non
             cliquable puis ses liens en retrait. Un second niveau de menu
             déroulant n'apporterait rien dans une liste verticale. -->
        <template
          v-for="entry in navigationLinks"
          :key="entry.label"
        >
          <template v-if="isNavigationGroup(entry)">
            <span class="text-eyebrow text-uppercase small fw-semibold pt-2 pb-1">
              {{ entry.label }}
            </span>
            <template
              v-for="link in entry.links"
              :key="link.label"
            >
              <RouterLink
                v-if="link.isEnabled"
                :to="link.to"
                class="nav-link-portfolio d-block py-2 ps-3"
                :class="navLinkClass(link)"
                aria-disabled="false"
                :aria-current="isActiveLink(link) ? 'page' : undefined"
                @click="isMobileMenuOpen = false"
              >
                {{ link.label }}
              </RouterLink>
              <span
                v-else
                class="nav-link-portfolio d-block py-2 ps-3"
                :class="navLinkClass(link)"
                aria-disabled="true"
              >
                {{ link.label }}
              </span>
            </template>
          </template>
          <RouterLink
            v-else-if="entry.isEnabled"
            :to="entry.to"
            class="nav-link-portfolio d-block py-2"
            :class="navLinkClass(entry)"
            aria-disabled="false"
            :aria-current="isActiveLink(entry) ? 'page' : undefined"
            @click="isMobileMenuOpen = false"
          >
            {{ entry.label }}
          </RouterLink>
          <!-- Lien désactivé : ni href, ni gestionnaire. Il n'était pas
               focusable au clavier, si bien que son @click ne pouvait servir
               qu'à la souris — et fermer le menu sans naviguer nulle part
               n'avait de toute façon pas de sens. -->
          <span
            v-else
            class="nav-link-portfolio d-block py-2"
            :class="navLinkClass(entry)"
            aria-disabled="true"
          >
            {{ entry.label }}
          </span>
        </template>
      </div>
    </nav>
  </header>
</template>

<style scoped>
.nav-group {
  position: relative;
}

/* Le bouton reprend le style des liens : même famille visuelle, sans bordure de bouton. */
.nav-group__toggle {
  background: none;
  border: 0;
  border-bottom: 2px solid transparent;
  padding: 0 0 0.35rem;
  font: inherit;
  cursor: pointer;
}

/* Sans Popper (pas de bundle JS Bootstrap), on ancre le menu sous le bouton. */
.nav-group .dropdown-menu {
  display: block;
  position: absolute;
  top: 100%;
  left: 0;
  z-index: 1030;
  min-width: 12rem;
}
</style>
