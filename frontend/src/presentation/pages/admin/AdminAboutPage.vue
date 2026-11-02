<script setup lang="ts">
import { computed, ref, useTemplateRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { useUnsavedOrderGuard } from '../../../application/admin/shared/useUnsavedOrderGuard'
import BaseSelect from '../../ui/BaseSelect.vue'
import AdminAboutSettingsForm from './AdminAboutSettingsForm.vue'
import AdminAboutSiteCardsSection from './AdminAboutSiteCardsSection.vue'
import AdminAboutMeCardsSection from './AdminAboutMeCardsSection.vue'
import { isSupportedLocale, LOCALE_NATIVE_NAMES, SUPPORTED_LOCALES, type Locale } from '../../../domain/portfolio/entities/Locale'

/**
 * Quatre tableaux ordonnés indépendamment — les cartes « site », puis une
 * catégorie de cartes « moi » par tableau — et trois formulaires.
 *
 * **Une langue par formulaire, sauf pour les réglages.** Les deux sections de
 * cartes ont chacune leur sélecteur (spec 0004, D8 : leurs tableaux affichent
 * toutes les langues, la langue ne pilote plus que le formulaire et son
 * assistant). Le sélecteur de cette page ne gouverne plus que les réglages, qui
 * sont un singleton par locale : y choisir une langue, c'est choisir
 * l'enregistrement à éditer. Partagé par les trois, il permettait à un geste
 * fait dans un panneau — « Modifier » sur une entrée anglaise, « Créer la
 * version EN », une traduction — de réécrire la langue de l'entrée en cours
 * d'édition dans un autre, sans avertissement.
 *
 * **Verrouillage (D6) : global à la page.** Chaque section annonce son
 * brouillon d'ordre par `orderDirtyChange` ; la page en fait la somme, rend
 * l'aide une seule fois et la redistribue par `isLocked`/`lockedHintId`. Un
 * verrou par tableau serait plus fin — enregistrer une carte « site » ne
 * recharge que celles-là — mais afficherait jusqu'à quatre états désactivés
 * différents pour un seul geste en cours. Un état, une aide, une règle.
 *
 * La page ne possède aucune logique d'ordre : elle ne connaît que deux booléens.
 * Les brouillons vivent dans les sections, au plus près des tableaux qu'ils
 * réordonnent, sur les composables partagés (`useOrderDraft` & consorts).
 */

const { t } = useI18n()

const localeOptions = SUPPORTED_LOCALES.map((locale) => ({ value: locale, label: LOCALE_NATIVE_NAMES[locale] }))
const selectedLocale = ref<Locale>(SUPPORTED_LOCALES[0])
const settingsForm = useTemplateRef('settingsForm')

/**
 * **Les deux canaux entre cette page et le formulaire des réglages** (R187b, R201), en sens inverse, chacun pour une raison :
 *
 * - **Page → formulaire, le geste de la personne sur le sélecteur** (`defineExpose` : cette fonction appelle
 *   `dropPendingProposalNotFor`). La langue est prise, et la proposition que les réglages tenaient en attente pour une autre
 *   langue part aussitôt. Une prop ne porte pas ce geste : FR → EN → FR joué avant le rendu laisse `locale` inchangée, le `watch`
 *   du formulaire ne voit rien, et la proposition se serait posée plus tard, au choix d'EN.
 * - **Formulaire → page, la bascule demandée par la traduction** (`@switch-locale`, gabarit). Le formulaire demande la langue de
 *   sa cible pour la poser : ce n'est pas un geste de la personne, la proposition qu'il vient de parquer doit survivre, elle ne
 *   passe donc pas par ici.
 *
 * Règle `frontend-backoffice.md`, « About *settings* is the one singleton per locale ».
 *
 * La valeur reçue est une `string` (le sélecteur n'en émet pas d'autre) : une langue hors de `SUPPORTED_LOCALES` n'est ni
 * montrée, ni lue du serveur, ni cause du retrait d'une proposition — elle est ignorée, la langue montrée reste celle d'avant.
 */
function chooseLocale(value: string): void {
  if (!isSupportedLocale(value)) {
    return
  }

  selectedLocale.value = value

  // Le formulaire n'est pas monté : aucune proposition parquée, rien à retirer.
  const form = settingsForm.value
  if (null !== form) {
    form.dropPendingProposalNotFor(value)
  }
}

/**
 * L'aide du verrou est **rendue visible**, jamais portée par un `title` :
 * Bootstrap pose `pointer-events: none` sur `.btn:disabled`, donc l'infobulle
 * d'un bouton désactivé ne s'affiche jamais au survol. Les boutons la désignent
 * par `aria-describedby` — et seulement quand elle existe, sinon la référence
 * pendante serait elle-même une erreur d'accessibilité.
 */
const LOCKED_HINT_ID = 'admin-order-locked-hint'

const isSiteCardsOrderDirty = ref(false)
const isMeCardsOrderDirty = ref(false)

const isAnyOrderDirty = computed(() => isSiteCardsOrderDirty.value || isMeCardsOrderDirty.value)
const lockedHintId = computed(() => (isAnyOrderDirty.value ? LOCKED_HINT_ID : undefined))

useUnsavedOrderGuard(isAnyOrderDirty)
</script>

<template>
  <div class="d-flex flex-column gap-4">
    <p
      v-if="isAnyOrderDirty"
      :id="LOCKED_HINT_ID"
      class="alert alert-warning mb-0"
    >
      {{ t('admin.order.lockedHint') }}
    </p>

    <div class="surface-panel p-3 p-sm-4">
      <BaseSelect
        id="admin-about-locale"
        :model-value="selectedLocale"
        aria-describedby="admin-about-locale-help"
        :label="t('admin.about.settings.localeLabel')"
        :options="localeOptions"
        @update:model-value="chooseLocale"
      />
      <p
        id="admin-about-locale-help"
        class="form-text mb-0"
      >
        {{ t('admin.about.settings.localeHelp') }}
      </p>
    </div>

    <!--
      Les réglages sont un singleton par locale : l'assistant de traduction y
      demande la bascule de la langue de page, dont les réglages se rechargent
      (la proposition est appliquée après, cf. AdminAboutSettingsForm).
    -->
    <AdminAboutSettingsForm
      ref="settingsForm"
      :locale="selectedLocale"
      :is-locked="isAnyOrderDirty"
      :locked-hint-id="lockedHintId"
      @switch-locale="selectedLocale = $event"
    />
    <AdminAboutSiteCardsSection
      :is-locked="isAnyOrderDirty"
      :locked-hint-id="lockedHintId"
      @order-dirty-change="isSiteCardsOrderDirty = $event"
    />
    <AdminAboutMeCardsSection
      :is-locked="isAnyOrderDirty"
      :locked-hint-id="lockedHintId"
      @order-dirty-change="isMeCardsOrderDirty = $event"
    />
  </div>
</template>
