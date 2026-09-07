<script setup lang="ts">
/**
 * Rend un texte saisi depuis le backoffice : paragraphes séparés par une ligne
 * vide, et `passages entre accents graves` en <code>.
 *
 * Jamais de `v-html` : le contenu provient d'un champ de saisie et l'interpréter
 * comme du HTML échangerait une mise en forme contre une XSS stockée sur une
 * page publique. Tout reste interpolé en nœuds texte.
 *
 * Composant partagé plutôt que dupliqué dans chaque page : une logique de
 * rendu qui porte une garantie de sécurité ne doit exister qu'à un seul
 * endroit, sinon une correction n'en couvre qu'une moitié.
 */
withDefaults(
  defineProps<{
    text: string
    /** Classes appliquées à chaque paragraphe rendu. */
    paragraphClass?: string
  }>(),
  { paragraphClass: 'text-body-secondary' },
)

function paragraphsOf(text: string): string[] {
  return text
    .split(/\n\s*\n/)
    .map((paragraph) => paragraph.trim())
    .filter((paragraph) => paragraph.length > 0)
}

/**
 * String.split avec un groupe capturant place les captures aux index impairs,
 * d'où l'alternance texte / code.
 */
function segmentsOf(paragraph: string): { text: string, isCode: boolean }[] {
  return paragraph
    .split(/`([^`]+)`/)
    .map((text, index) => ({ text, isCode: 1 === index % 2 }))
    .filter((segment) => segment.text.length > 0)
}
</script>

<template>
  <p
    v-for="(paragraph, index) in paragraphsOf(text)"
    :key="index"
    class="rich-text__paragraph"
    :class="paragraphClass"
  >
    <template
      v-for="(segment, segmentIndex) in segmentsOf(paragraph)"
      :key="segmentIndex"
    >
      <code
        v-if="segment.isCode"
        class="rich-text__code"
      >{{ segment.text }}</code>
      <!--
        Contenu collé aux balises volontairement : ces segments sont des
        fragments d'une même phrase. Les aérer ferait apparaître une espace de
        part et d'autre de chaque passage en `code` dans le texte rendu.
      -->
      <!-- eslint-disable-next-line vue/singleline-html-element-content-newline -->
      <template v-else>{{ segment.text }}</template>
    </template>
  </p>
</template>

<style scoped>
.rich-text__paragraph:last-child {
  margin-bottom: 0;
}

.rich-text__code {
  padding: 0.1rem 0.35rem;
  border-radius: 0.3rem;
  background: rgba(124, 58, 237, 0.12);
  /* Pas `--bs-link-color` : ce jeton sert aux liens, et un code n'en est pas
     un — le lecteur ne doit pas croire qu'il peut cliquer dessus. */
  color: #c4b5fd;
  font-size: 0.9em;
}
</style>
