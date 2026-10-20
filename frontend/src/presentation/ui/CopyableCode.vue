<script lang="ts">
/**
 * Délai entre la région vidée et le message posé. Vidée puis remplie dans la
 * même image, la région ne change pas aux yeux des technologies d'assistance,
 * qui regroupent les mutations : la deuxième copie d'un même bloc passerait
 * sous silence.
 */
export const ANNOUNCEMENT_DELAY_MS = 100

/**
 * Durée d'affichage du message : un « Copié » resté sur un bloc dirait vrai
 * d'un texte que le presse-papiers ne contient plus, un autre bloc copié.
 */
export const ANNOUNCEMENT_LIFETIME_MS = 5000
</script>

<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue'
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
 * Numéro de la dernière copie demandée : le résultat d'une copie déjà remplacée
 * par un nouveau clic (une écriture lente, refusée tard) n'est jamais affiché.
 */
let latestCopy = 0
let pendingTimer: ReturnType<typeof setTimeout> | undefined

function schedule(callback: () => void, delay: number): void {
  clearTimeout(pendingTimer)
  pendingTimer = setTimeout(callback, delay)
}

onBeforeUnmount(() => clearTimeout(pendingTimer))

/**
 * Copie par l'API Clipboard, qui n'existe qu'en contexte sécurisé (HTTPS,
 * localhost) et peut être refusée par le navigateur. Dans les deux cas, l'échec
 * est annoncé, et le texte reste sélectionnable dans le bloc pour une copie à la
 * main — pas de repli sur `document.execCommand('copy')`, déprécié.
 *
 * La région est vidée avant chaque tentative, et le message n'y revient
 * qu'après `ANNOUNCEMENT_DELAY_MS` : un texte identique à celui déjà présent ne
 * serait pas réannoncé. Il en repart après `ANNOUNCEMENT_LIFETIME_MS`.
 */
async function copy(): Promise<void> {
  const thisCopy = ++latestCopy
  clearTimeout(pendingTimer)
  announcement.value = ''

  const copied = await writeToClipboard(props.code)
  if (thisCopy !== latestCopy) {
    return
  }

  schedule(() => {
    announcement.value = copied ? t('common.copied') : t('common.copyFailed')
    schedule(() => {
      announcement.value = ''
    }, ANNOUNCEMENT_LIFETIME_MS)
  }, ANNOUNCEMENT_DELAY_MS)
}

/** Vrai si le texte est dans le presse-papiers ; faux si l'API manque ou refuse. */
async function writeToClipboard(text: string): Promise<boolean> {
  if (!navigator.clipboard) {
    return false
  }

  try {
    await navigator.clipboard.writeText(text)

    return true
  } catch {
    return false
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
