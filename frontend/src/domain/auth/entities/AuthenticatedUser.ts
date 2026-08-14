export interface AuthenticatedUser {
  readonly username: string
  readonly roles: readonly string[]
}
