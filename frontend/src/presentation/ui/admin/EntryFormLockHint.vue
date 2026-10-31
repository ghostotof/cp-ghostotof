<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { EntryFormLockState } from './useEntryFormLock'

/**
 * Le texte du verrou d'un formulaire par entrée (#748, décision D-a de l'auteur, 2026-10-30) : un
 * contrôle désactivé reçoit un `aria-describedby` vers un texte **rendu seulement pendant le verrou**,
 * jamais un `title` (Bootstrap pose `pointer-events: none` sur un contrôle désactivé) et jamais une
 * référence vers un élément absent.
 *
 * Un texte par état (`useEntryFormLock`) : en édition, il dit que la langue d'une entrée existante ne
 * change pas ; pendant une proposition, il dit ce qu'elle fige (la langue, « Version de »,
 * « Proposer la version XX » et, dans une carte « moi », la catégorie) et qu'« Annuler » abandonne la
 * proposition et vide le formulaire. La page désigne ce texte par `id` (`lock.hintId`), après l'aide
 * permanente du sélecteur de langue quand il en a une (`lock.localeDescribedBy`).
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
    {{ 'editing' === state ? t('admin.entryLock.editing') : t('admin.entryLock.proposal') }}
  </p>
</template>
