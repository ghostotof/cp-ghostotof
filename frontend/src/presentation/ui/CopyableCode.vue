<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import IconCopy from '~icons/lucide/copy'

const props = defineProps<{
  /** Texte affiché et copié, à l'identique. */
  code: string
  /**
   * Ce que le bouton copie, lu par les seuls lecteurs d'écran après le mot
   * visible (« Copier » + « la commande Claude Code ») : sur une page à
   * plusieurs blocs, « Copier » seul ne dirait pas lequel.
   */
  label: string
}>()

const { t } = useI18n()

/**
 * Message de la région `role="status"`, rendue vide dès le montage : un lecteur
 * d'écran n'annonce pas une région live insérée en même temps que son texte.
 */
const announcement = ref('')

/**
 * Copie par l'API Clipboard, qui n'existe qu'en contexte sécurisé (HTTPS,
 * localhost) et peut être refusée par le navigateur. Dans les deux cas, l'échec
 * est annoncé, et le texte reste sélectionnable dans le bloc pour une copie à la
 * main — pas de repli sur `document.execCommand('copy')`, déprécié.
 *
 * La région est vidée avant chaque tentative : un texte identique à celui déjà
 * présent ne serait pas réannoncé, et la deuxième copie passerait sous silence.
 */
async function copy(): Promise<void> {
  announcement.value = ''

  try {
    if (!navigator.clipboard) {
      throw new Error('Clipboard API unavailable')
    }
    await navigator.clipboard.writeText(props.code)
    announcement.value = t('common.copied')
  } catch {
    announcement.value = t('common.copyFailed')
  }
}
</script>

<template>
  <div class="d-flex flex-column gap-2">
    <!-- pre-wrap plutôt qu'un défilement horizontal : un bloc qui défile devrait
         être focusable pour être lu au clavier, un bloc qui se replie, non. -->
    <pre
      class="surface-panel p-3 mb-0 small text-break"
      style="white-space: pre-wrap"
    ><code>{{ code }}</code></pre>
    <div class="d-flex flex-wrap align-items-center gap-2">
      <button
        type="button"
        class="btn btn-sm btn-outline-light d-inline-flex align-items-center gap-2"
        @click="copy"
      >
        <IconCopy
          width="14"
          height="14"
          aria-hidden="true"
        />
        {{ t('common.copy') }}
        <span class="visually-hidden">{{ label }}</span>
      </button>
      <span
        class="small text-body-secondary"
        role="status"
      >{{ announcement }}</span>
    </div>
  </div>
</template>
