<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { EntryFormLockState } from './useEntryFormLock'

/**
 * Le texte qui dit pourquoi le sélecteur de langue d'un formulaire par entrée est désactivé
 * (#748, décision D-a de l'auteur, 2026-10-30) : un contrôle désactivé reçoit un `aria-describedby`
 * vers un texte **rendu seulement pendant le verrou**, jamais un `title` (Bootstrap pose
 * `pointer-events: none` sur un contrôle désactivé) et jamais une référence vers un élément absent.
 *
 * Un texte par état (`useEntryFormLock`) : en édition, la langue d'une entrée existante ne change
 * pas ; pendant une proposition, elle est celle de la proposition et « Annuler » la retire. La page
 * désigne ce texte par `id` (`lock.hintId`), après l'aide permanente de son sélecteur quand il en a
 * une (`lock.localeDescribedBy`).
 */
defineProps<{
  id: string
  state: EntryFormLockState
}>()

const { t } = useI18n()
</script>

<template>
  <p
    v-if="'none' !== state"
    :id="id"
    class="form-text mb-3"
  >
    {{ 'editing' === state ? t('admin.localeLockedEditing') : t('admin.localeLockedProposal') }}
  </p>
</template>
