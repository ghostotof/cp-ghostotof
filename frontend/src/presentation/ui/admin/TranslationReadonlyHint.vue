<script setup lang="ts">
import { useI18n } from 'vue-i18n'

/**
 * Texte d'aide de la prose d'un formulaire pendant une traduction : les champs sont en lecture seule (`readonly`,
 * pas `disabled` : ils restent lisibles et focusables) jusqu'à la réponse, qui remplace la prose. Chaque champ le
 * désigne par `aria-describedby`, et seulement pendant la traduction : le texte n'est rendu que `shown`, comme la
 * référence (aucun identifiant pendant). `id` est unique dans la page (une page porte plusieurs formulaires).
 */
defineProps<{ id: string; shown: boolean }>()

const { t } = useI18n()
</script>

<template>
  <p
    v-if="shown"
    :id="id"
    class="form-text mb-3"
  >
    {{ t('admin.translation.proseReadonly') }}
  </p>
</template>
