/**
 * A technology ranked by cumulative time spent on it across a career, most experienced first.
 * `years` drives sorting and the proportional bar; `duration` is the derived, locale-specific
 * label shown to the user (e.g. "~9,5 ans" / "~9.5 years"), computed from `years` — see
 * `domain/experience/services/formatExperienceDuration`.
 */
export interface ExperienceTechnology {
  readonly name: string
  readonly years: number
  readonly duration: string
  readonly iconKey?: string
  /**
   * A technology always used alongside this one but not ranked on its own — shown as a
   * muted label next to it instead of its own entry (e.g. HTML/CSS/JS next to PHP).
   */
  readonly relatedTechnology?: ExperienceRelatedTechnology
}

export interface ExperienceRelatedTechnology {
  readonly name: string
}
