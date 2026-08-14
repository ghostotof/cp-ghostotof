export interface AdminUser {
  readonly id: number
  readonly username: string
  readonly roles: readonly string[]
}
