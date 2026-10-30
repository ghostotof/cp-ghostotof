<script setup lang="ts">
import { useI18n } from 'vue-i18n'

/**
 * Le texte qui dit pourquoi le sélecteur de langue d'un formulaire par entrée est désactivé
 * (#748, décision D-a de l'auteur, 2026-10-30) : un contrôle désactivé reçoit un `aria-describedby`
 * vers un texte **rendu seulement pendant le verrou**, jamais un `title` (Bootstrap pose
 * `pointer-events: none` sur un contrôle désactivé) et jamais une référence vers un élément absent.
 *
 * Deux verrous, deux textes, partagés par les huit formulaires (une proposition de traduction ne
 * se produit que depuis une entrée en édition, les deux cas s'excluent) : en édition, la langue
 * d'une entrée existante ne change pas (un `PUT` ne la change pas, 409 `locale-immutable`) ; après
 * une proposition, la langue est celle de la proposition, groupe de l'entrée source gardé, et
 * « Annuler » la retire. La page désigne ce texte par `id`, après l'aide permanente de son
 * sélecteur quand il en a une (`aria-describedby="<aide> <id>"`, seulement pendant le verrou).
 */
defineProps<{
  id: string
  isEditing: boolean
  hasProposal: boolean
}>()

const { t } = useI18n()
</script>

<template>
  <p
    v-if="isEditing || hasProposal"
    :id="id"
    class="form-text mb-3"
  >
    {{ isEditing ? t('admin.localeLockedEditing') : t('admin.localeLockedProposal') }}
  </p>
</template>
