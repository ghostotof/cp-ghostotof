<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, useTemplateRef, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import IconCircleUser from '~icons/lucide/circle-user'
import IconChevronDown from '~icons/lucide/chevron-down'

/**
 * Menu du compte de l'en-tête à partir de 768 px (#650, décision de l'auteur
 * du 2026-10-28) : les actions d'un compte du palier de confiance — qui
 * s'alignaient à plat — et « Mes connexions MCP » y sont regroupées. Sous
 * 768 px, le menu du compte de `AppHeader` (une icône, mêmes entrées) reste
 * celui qui sert.
 *
 * La liste est **masquée** (`v-show`), pas retirée du DOM (#650, R5a) : le
 * bouton la désigne par `aria-controls`, et une référence vers un élément absent
 * n'est pas valide. Masquée par `display: none`, elle n'est ni lue ni
 * atteignable au clavier, comme si elle n'existait pas.
 *
 * Motif WAI-ARIA « disclosure », pas « menu » : un bouton `aria-expanded` +
 * `aria-controls` qui montre ou cache une liste de liens ordinaires. Le rôle
 * `menu` impose la navigation aux flèches, la gestion du focus d'une
 * application et un rôle `menuitem` à chaque entrée ; il sert aux menus
 * d'application, pas à des liens de site. Ici : Tab parcourt la liste comme
 * n'importe quelle suite de liens, sans `aria-haspopup`.
 *
 * Fermeture : un second clic sur le bouton, Échap (le focus revient alors au
 * bouton, pour que la tabulation reprenne là où la personne était), un clic en
 * dehors (le focus reste où elle l'a mis), une navigation quelle qu'en soit
 * l'origine, et le choix d'une entrée — y compris le lien vers la page où l'on
 * est déjà, qui ne navigue pas. Pas de fermeture à la sortie du focus : Safari
 * ne donne pas le focus à un bouton cliqué, un `focusout` fermerait la liste
 * avant que le clic n'arrive.
 *
 * Le composant ne connaît ni l'authentification ni le téléchargement du CV : il
 * affiche des entrées et émet `logout` / `downloadCv`, que `AppHeader` branche.
 */

const props = defineProps<{
  /** Préfixe de langue des liens (`/fr`, `/en`). */
  homeLink: string
  /** ROLE_SUPER : « Administration » prend la place du CV, qui vit dans le menu d'administration. */
  isSuperAdmin: boolean
  isDownloadingCv: boolean
}>()

const emit = defineEmits<{
  downloadCv: []
  logout: []
}>()

const { t } = useI18n()
const route = useRoute()

/** Identifiant distinct de celui du menu mobile (`account-menu`) : les deux existent dans le DOM. */
const MENU_ID = 'account-menu-desktop'

const isOpen = ref(false)
const container = useTemplateRef<HTMLElement>('container')
const toggle = useTemplateRef<HTMLButtonElement>('toggle')

function close(): void {
  isOpen.value = false
}

function onDocumentClick(event: MouseEvent): void {
  if (isOpen.value && null !== container.value && !container.value.contains(event.target as Node)) {
    close()
  }
}

function onDocumentKeydown(event: KeyboardEvent): void {
  if ('Escape' === event.key && isOpen.value) {
    close()
    toggle.value?.focus()
  }
}

function onLogout(): void {
  close()
  emit('logout')
}

function onDownloadCv(): void {
  close()
  emit('downloadCv')
}

// Un retour arrière, un lien de la page ou une redirection du garde de route
// changent aussi de page : le menu ouvert ne doit pas survivre au changement.
watch(
  () => route.fullPath,
  () => close(),
)

onMounted(() => {
  document.addEventListener('click', onDocumentClick)
  document.addEventListener('keydown', onDocumentKeydown)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
  document.removeEventListener('keydown', onDocumentKeydown)
})
</script>

<template>
  <div
    ref="container"
    class="account-menu-root"
  >
    <button
      ref="toggle"
      type="button"
      class="btn btn-outline-light btn-sm d-inline-flex align-items-center gap-2 text-nowrap"
      :aria-expanded="isOpen"
      :aria-controls="MENU_ID"
      @click="isOpen = !isOpen"
    >
      <IconCircleUser
        width="16"
        height="16"
        aria-hidden="true"
      />
      {{ t('common.myAccount') }}
      <IconChevronDown
        width="14"
        height="14"
        aria-hidden="true"
      />
    </button>
    <ul
      v-show="isOpen"
      :id="MENU_ID"
      class="dropdown-menu show mt-1"
      data-bs-theme="dark"
    >
      <li>
        <RouterLink
          :to="`${props.homeLink}/assistant`"
          class="dropdown-item"
          @click="close"
        >
          {{ t('nav.assistant') }}
        </RouterLink>
      </li>
      <li v-if="props.isSuperAdmin">
        <RouterLink
          :to="`${props.homeLink}/admin`"
          class="dropdown-item"
          @click="close"
        >
          {{ t('common.administration') }}
        </RouterLink>
      </li>
      <li v-else>
        <button
          type="button"
          class="dropdown-item"
          :disabled="props.isDownloadingCv"
          @click="onDownloadCv"
        >
          {{ t('common.downloadCv') }}
        </button>
      </li>
      <li>
        <RouterLink
          :to="`${props.homeLink}/mcp-connections`"
          class="dropdown-item"
          @click="close"
        >
          {{ t('nav.mcpConnections') }}
        </RouterLink>
      </li>
      <li>
        <button
          type="button"
          class="dropdown-item"
          @click="onLogout"
        >
          {{ t('common.logout') }}
        </button>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.account-menu-root {
  position: relative;
}

/*
 * Sans Popper (pas de bundle JS Bootstrap), le menu est ancré sous le bouton.
 * Le bouton est le dernier élément de la barre, au bord droit : la liste
 * s'ouvre vers la gauche (right: 0) et ne sort jamais de la fenêtre. Même
 * mécanique que les menus de AppHeader, dont le style est scopé à ce fichier.
 */
.account-menu-root .dropdown-menu {
  display: block;
  position: absolute;
  top: 100%;
  right: 0;
  left: auto;
  z-index: 1030;
  min-width: 14rem;
}
</style>
