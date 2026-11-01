import { computed, ref, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { applyTranslationProposal, collectProseFields } from '../../../application/admin/translation/proseFieldCopy'
import {
  useAdminTranslation,
  type UseAdminTranslationResult,
} from '../../../application/admin/translation/useAdminTranslation'
import type { FormIdentity } from '../../../application/admin/translation/FormIdentity'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import type { TranslationProposal } from '../../../domain/admin/translation/entities/TranslationProposal'
import type { AdminTranslationErrorReason } from '../../../domain/admin/translation/errors/AdminTranslationError'
import type fr from '../../../infrastructure/i18n/locales/fr.json'
import { describedBy } from './describedBy'

/** Ce que `removeEntry` lit d'une entrée qu'on supprime : sa clé et son groupe de traduction. */
export type DeletableEntry = { readonly id: string; readonly translationGroup: string | null }

export interface UseTranslatedFormOptions<F extends string> {
  /**
   * Préfixe des identifiants des deux textes que le formulaire rend (`<préfixe>-prose-readonly-hint`,
   * `<préfixe>-translation-hint`) : unique dans la page, qui porte plusieurs formulaires.
   */
  readonly hintIdPrefix: string
  /**
   * L'objet réactif du formulaire, passé tel quel : le composable en lit la prose (`hasProse`, la prose envoyée) et la remplace
   * par la proposition (`proposeFrom`). La page le déclare donc **avant** l'appel : `vue-tsc` refuse un usage avant la
   * déclaration (TS2448), ce qu'un getter ne verrait pas.
   */
  readonly form: Record<F, string>
  /** Les champs de prose du formulaire, ceux que la traduction envoie et remplace (`presentation/pages/admin/proseFields.ts`). */
  readonly proseFields: readonly F[]
  /**
   * Ce qui fait l'identité du formulaire : l'entrée éditée, la langue, le groupe source (`FormIdentity` moins sa
   * génération, que le composable pose : `renewForm()`). Lu par `useAdminTranslation`, qui périme la traduction en vol et
   * efface l'échec quand elle change ; `locale` est la langue source d'une demande (`proposeFrom`).
   */
  readonly identity: () => Omit<FormIdentity, 'generation'>
  /**
   * Vrai quand la page ne veut aucune traduction : ne dépend que de sources réactives. Le composable y ajoute l'absence de
   * prose (`hasProse`) : la page n'écrit que ses propres conditions.
   */
  readonly isRefused: () => boolean
}

/** Ce que chaque champ de prose reçoit par `v-bind` : lecture seule et référence au texte d'aide, pendant la traduction. */
export interface ProseFieldAttrs {
  readonly readonly: boolean
  readonly 'aria-describedby': string | undefined
}

export interface UseTranslatedFormResult extends Omit<UseAdminTranslationResult, 'expire' | 'translate'> {
  /**
   * Fait avancer la génération du formulaire : à appeler en premier par **chaque** geste de la page qui remplit ou vide le
   * formulaire. Un tel geste peut ne changer ni l'entrée, ni la langue, ni le groupe (un formulaire déjà vierge, l'entrée déjà
   * éditée rechargée, la même ligne choisie deux fois), et la réponse d'une traduction partie avant lui poserait sa proposition
   * sur le formulaire vidé ou rechargé : la génération, elle, change, et périme la traduction en vol comme elle efface l'échec
   * (ce qu'aucune identité ne voit, la suppression d'une entrée, passe par `removeEntry`).
   */
  readonly renewForm: () => void
  /** Un champ de `proseFields` du formulaire n'est pas blanc : sans prose, rien à traduire (le refus exposé le contient). */
  readonly hasProse: Readonly<Ref<boolean>>
  /** Le texte, traduit, de l'échec de la dernière traduction (`admin.translation.errors.<raison>`), `null` sans échec. */
  readonly translationErrorText: Readonly<Ref<string | null>>
  /**
   * Demande une proposition dans `targetLocale` et la remet à `accept(proposal, sourceLocale)`, que `useAdminTranslation` appelle
   * dans le point de contrôle même qui juge la réponse courante (R172) : la page y pose ce qu'elle seule sait faire de la
   * proposition. La langue source est celle de l'identité du formulaire, lue à l'envoi ; la prose envoyée, celle de `proseFields`
   * (les champs blancs omis). Rend vrai si la proposition a été acceptée ; faux sur un refus, un échec (`translationErrorText`
   * le dit) ou une réponse périmée, `accept` n'étant alors pas appelée. `proposeFrom` en est l'usage des formulaires par entrée ;
   * les réglages « À propos », dont la proposition est différée, l'appellent eux-mêmes.
   */
  readonly requestProposal: (
    targetLocale: Locale,
    accept: (proposal: TranslationProposal, sourceLocale: Locale) => void,
  ) => Promise<boolean>
  /**
   * Demande une proposition dans `targetLocale` (`requestProposal`), puis bascule le formulaire en **création** avec elle : la
   * prose est remplacée, le reste gardé. Rien n'est enregistré (ADR 0004, D4). Sur un refus, un échec ou une réponse périmée, il
   * ne se passe rien : le formulaire reste intact et `translationErrorText` dit l'échec. Sinon, `switchForm(sourceLocale)` est appelée
   * **avant** que la prose soit remplacée : la page y pose ce qu'elle seule sait (l'entrée éditée vidée, la langue cible, le
   * groupe source, l'avis de proposition : `switchToProposedCreation`, pour les huit formulaires par entrée). Les deux gestes se
   * font dans le point de contrôle qui juge la réponse courante : la proposition est posée sous ce jugement, jamais une
   * micro-tâche plus tard (R172). Une exception de `switchForm` remonte au gestionnaire `@translate` de la page, dont Vue passe le
   * rejet à `app.config.errorHandler` : le formulaire peut rester à moitié basculé, une faute de programmation que le
   * gestionnaire d'erreurs rend visible (limite acceptée, R187).
   */
  readonly proposeFrom: (targetLocale: Locale, switchForm: (sourceLocale: Locale) => void) => Promise<void>
  /**
   * Supprime une entrée : périme d'abord la traduction qui lui est rattachée, puis attend `remove`. Une suppression ne change
   * rien au formulaire, aucune identité ne la voit (R140) : la traduction est rattachée à l'entrée qu'on supprime quand c'est
   * l'entrée éditée, ou la source d'une création rattachée à son groupe (`group` posé, aucune entrée éditée : « Créer la
   * version XX », ou une proposition) ; supprimer un autre membre du groupe d'une entrée éditée, ou une entrée d'un autre
   * groupe, ne change rien. La page garde le `window.confirm`, dont le texte lui est propre. Limite acceptée (D7) : une
   * suppression refusée périme quand même la traduction — la périmer après le succès laisserait une réponse arrivée pendant le
   * `DELETE` se poser sur une entrée supprimée.
   */
  readonly removeEntry: (entry: DeletableEntry, remove: () => Promise<unknown>) => Promise<void>
  /** Identifiant du texte d'aide de la prose (`TranslationReadonlyHint`), rendu seulement pendant la traduction. */
  readonly proseReadonlyHintId: string
  /** Identifiant du texte de l'attente après « Annuler » (`TranslateEntryButton`, `hintId`). */
  readonly translationHintId: string
  /**
   * Ce que chaque champ de prose reçoit, lié par `v-bind` : en lecture seule (`readonly`, pas `disabled` : lisible et
   * focusable) et décrit par le texte d'aide, pendant la traduction. `helpId` est l'aide propre du champ, gardée devant.
   */
  readonly proseFieldAttrs: (helpId?: string) => ProseFieldAttrs
}

/**
 * Ce qu'un formulaire à traduction assistée tient à part du formulaire lui-même (ADR 0004 D4) : l'identité qui périme la
 * traduction, le refus de la page, la demande de proposition et la bascule du formulaire, la suppression d'une entrée, le texte
 * de l'échec, et ce que la prose en tire — lecture seule pendant la traduction, décrite par un texte d'aide, jamais
 * d'identifiant pendant. Écrit une fois pour les neuf formulaires.
 *
 * Il appelle `useAdminTranslation` et en rend ce qu'une page lit, sans `expire` ni `translate` (`requestProposal` en est la
 * porte) : un geste de page périme par son identité (`renewForm`) ou par `removeEntry`. La page garde ce qui lui est propre : le
 * formulaire et ses gestes (`startEdit`, `startCreateVersion`, l'enregistrement), ses champs de prose (`proseFields.ts`), le
 * `window.confirm` de la suppression, et le bloc de gabarit — `TranslateEntryButton`, `TranslationReadonlyHint`, l'alerte
 * d'erreur —, que chaque page écrit : chacune de ses liaisons porte une valeur de la page (la langue du formulaire, l'aide du
 * verrou, son `describedBy`), et un composant qui les recevrait toutes ne ferait que déplacer la recopie. Les réglages
 * « À propos » (proposition différée) lisent `hasProse` et `translationErrorText` et appellent `requestProposal`, sans `proposeFrom`.
 */
export function useTranslatedForm<F extends string>(options: UseTranslatedFormOptions<F>): UseTranslatedFormResult {
  // Le numéro du formulaire : un geste qui le remplit ou le vide le fait avancer, et rien d'autre (voir `renewForm`).
  const generation = ref(0)
  const hasProse = computed(() => options.proseFields.some((field) => '' !== options.form[field].trim()))
  const { expire, translate, ...translation } = useAdminTranslation({
    isRefused: () => !hasProse.value || options.isRefused(),
    formIdentity: () => ({ ...options.identity(), generation: generation.value }),
  })
  const expireIfDeleted = (entry: DeletableEntry): void => {
    if (isTranslationOf(options.identity(), entry)) {
      expire()
    }
  }
  const proseReadonlyHintId = `${options.hintIdPrefix}-prose-readonly-hint`

  return {
    ...translation,
    ...proposalGestures(options, translate),
    renewForm: () => {
      generation.value += 1
    },
    hasProse,
    translationErrorText: useTranslationErrorText(translation.errorReason),
    removeEntry: async (entry, remove) => {
      // La péremption précède `remove` (D7) : une réponse arrivée pendant le `DELETE` ne doit pas se poser sur l'entrée supprimée.
      expireIfDeleted(entry)

      await remove()
    },
    proseReadonlyHintId,
    translationHintId: `${options.hintIdPrefix}-translation-hint`,
    proseFieldAttrs: (helpId) => proseFieldAttrsOf(translation.isTranslating, helpId, proseReadonlyHintId),
  }
}

/**
 * `requestProposal` (la demande, la prose collectée, la langue source de l'identité lue à l'envoi) et `proposeFrom` qui s'y
 * appuie : la proposition est posée dans le point de contrôle qui juge la réponse courante, jamais une micro-tâche plus tard.
 */
function proposalGestures<F extends string>(
  options: UseTranslatedFormOptions<F>,
  translate: UseAdminTranslationResult['translate'],
): Pick<UseTranslatedFormResult, 'requestProposal' | 'proposeFrom'> {
  const requestProposal: UseTranslatedFormResult['requestProposal'] = (targetLocale, accept) => {
    const sourceLocale = options.identity().locale

    return translate(sourceLocale, targetLocale, collectProseFields(options.form, options.proseFields), (proposal) =>
      accept(proposal, sourceLocale),
    )
  }
  const proposeFrom: UseTranslatedFormResult['proposeFrom'] = async (targetLocale, switchForm) => {
    await requestProposal(targetLocale, (proposal, sourceLocale) => {
      switchForm(sourceLocale)
      applyTranslationProposal(options.form, options.proseFields, proposal)
    })
  }

  return { requestProposal, proposeFrom }
}

/**
 * Vrai si la traduction en vol porte sur l'entrée qu'on supprime, d'après l'identité du formulaire : l'entrée supprimée est
 * celle qu'on édite, ou le formulaire est une création rattachée à un groupe et l'entrée supprimée est de ce groupe — sa source.
 */
function isTranslationOf(identity: Omit<FormIdentity, 'generation'>, deleted: DeletableEntry): boolean {
  const { entry: editingId, group } = identity
  // Une création rattachée : aucune entrée éditée, un groupe source. L'entrée de ce groupe qu'on supprime en est la source.
  const isSourceOfAttachedCreation = null === editingId && null !== group && deleted.translationGroup === group

  return deleted.id === editingId || isSourceOfAttachedCreation
}

/**
 * La clé i18n du texte de chaque raison d'échec. Une table fermée sur `AdminTranslationErrorReason`, dont chaque clé est lue dans
 * le fichier de messages : une raison sans entrée, ou une clé absente du fichier (`admin.translation.errors.<raison>`), ne compile
 * pas, là où une clé construite (`${reason}`) ne verrait rien.
 */
type TranslationErrorTexts = typeof fr.admin.translation.errors
const ERROR_TEXT_KEYS: {
  readonly [R in AdminTranslationErrorReason]: `admin.translation.errors.${R & keyof TranslationErrorTexts}`
} = {
  validation: 'admin.translation.errors.validation',
  'rate-limited': 'admin.translation.errors.rate-limited',
  unavailable: 'admin.translation.errors.unavailable',
  unknown: 'admin.translation.errors.unknown',
}

/** Le texte, traduit, de la raison de l'échec (`ERROR_TEXT_KEYS`), `null` sans échec. */
function useTranslationErrorText(
  errorReason: Readonly<Ref<AdminTranslationErrorReason | null>>,
): Readonly<Ref<string | null>> {
  const { t } = useI18n()

  return computed(() => {
    const reason = errorReason.value

    return null === reason ? null : t(ERROR_TEXT_KEYS[reason])
  })
}

/**
 * Les attributs d'un champ de prose : lecture seule pendant la traduction, décrit par l'aide du champ puis par le texte de la
 * lecture seule (`proseReadonlyHintId`).
 */
function proseFieldAttrsOf(isTranslating: Readonly<Ref<boolean>>, helpId: string | undefined, readonlyHintId: string): ProseFieldAttrs {
  return {
    readonly: isTranslating.value,
    // La référence n'existe que pendant la traduction, avec le texte : jamais pendante.
    'aria-describedby': describedBy(helpId, isTranslating.value ? readonlyHintId : undefined),
  }
}
