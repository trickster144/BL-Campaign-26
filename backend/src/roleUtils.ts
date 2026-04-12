export type Team = 'blue' | 'red' | null;
export type AppRole = 'admin' | 'member' | 'observer' | 'gamemaster';
export type DbRole =
  | 'blue_admin'
  | 'red_admin'
  | 'blue_member'
  | 'red_member'
  | 'blue_observer'
  | 'red_observer'
  | 'gamemaster';

export function normalizeRole(role: string): AppRole {
  if (role === 'gamemaster') return 'gamemaster';
  if (role.endsWith('_admin')) return 'admin';
  if (role.endsWith('_member')) return 'member';
  return 'observer';
}

export function toDatabaseRole(team: Team, role: AppRole): DbRole | null {
  if (role === 'gamemaster') return 'gamemaster';
  if (!team) return null;
  return `${team}_${role}` as DbRole;
}
