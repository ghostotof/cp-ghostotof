import type { Component } from 'vue'
import IconSymfony from '~icons/simple-icons/symfony'
import IconDocker from '~icons/simple-icons/docker'
import IconPostgresql from '~icons/simple-icons/postgresql'
import IconVuedotjs from '~icons/simple-icons/vuedotjs'
import IconTypescript from '~icons/simple-icons/typescript'
import IconCode from '~icons/lucide/code'
import IconLayers from '~icons/lucide/layers'
import IconZap from '~icons/lucide/zap'
import IconShield from '~icons/lucide/shield'
import IconArrowRight from '~icons/lucide/arrow-right'
import IconMessageCircle from '~icons/lucide/message-circle'
import IconBoxes from '~icons/lucide/boxes'
import IconColumns3 from '~icons/lucide/columns-3'
import IconPuzzle from '~icons/lucide/puzzle'
import IconBox from '~icons/lucide/box'
import IconUsers from '~icons/lucide/users'
import IconInfinity from '~icons/lucide/infinity'
import IconMail from '~icons/lucide/mail'
import IconCheck from '~icons/lucide/check'

/**
 * Le domaine ne connaît que des identifiants (iconKey) sous forme de chaînes,
 * indépendants de Vue et d'unplugin-icons. Cette table de correspondance,
 * propre à la couche présentation, est le seul endroit qui relie un iconKey
 * à un composant d'icône concret.
 */
const iconRegistry: Record<string, Component> = {
  symfony: IconSymfony,
  docker: IconDocker,
  postgresql: IconPostgresql,
  vuejs: IconVuedotjs,
  typescript: IconTypescript,
  code: IconCode,
  layers: IconLayers,
  zap: IconZap,
  shield: IconShield,
  'arrow-right': IconArrowRight,
  'message-circle': IconMessageCircle,
  boxes: IconBoxes,
  'columns-3': IconColumns3,
  puzzle: IconPuzzle,
  box: IconBox,
  users: IconUsers,
  infinity: IconInfinity,
  mail: IconMail,
  check: IconCheck,
}

export function resolveIcon(iconKey: string | undefined): Component | undefined {
  if (!iconKey) {
    return undefined
  }

  return iconRegistry[iconKey]
}
