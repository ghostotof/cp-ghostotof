import { nextTick, type Component } from 'vue'
import type { VueWrapper } from '@vue/test-utils'

/** Ce que l'état du `setup` d'une page par entrée expose à un test : le geste « Modifier ». */
interface PageWithStartEdit<Entry> {
  readonly startEdit: (entry: Entry) => void
}

function hasStartEdit<Entry>(vm: object): vm is PageWithStartEdit<Entry> {
  return 'startEdit' in vm && 'function' === typeof vm.startEdit
}

/**
 * Appelle le `startEdit` d'une page par entrée **sans passer par le tableau**, pour lui donner une entrée que le tableau ne montre
 * jamais : `groupByTranslationGroup` ne garde que les langues de `SUPPORTED_LOCALES`, aucun bouton « Modifier » n'existe donc pour
 * une entrée d'une autre langue (#748, R204). Le geste reste testable ici, et la garde de langue de `startEdit` avec lui.
 * Le `vm` d'un composant `<script setup>` rend, sous Vue Test Utils, l'état du `setup`.
 */
export async function startEditThroughSetup<Entry>(wrapper: VueWrapper, page: Component, entry: Entry): Promise<void> {
  const vm: object = wrapper.findComponent(page).vm
  if (!hasStartEdit<Entry>(vm)) {
    throw new Error("la page n'expose pas `startEdit` à son `vm` : le helper ne sait pas ouvrir cette entrée")
  }
  vm.startEdit(entry)
  await nextTick()
}
