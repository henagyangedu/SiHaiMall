import { useUserStore } from '@/stores/user'

/**
 * 是否拥有权限。
 * p 可以是 'setting:admin:add'，多个用逗号分隔表示任一即可；'super' 表示仅超级管理员。
 */
export function hasPerm(p) {
  const s = useUserStore()
  if (p === 'super') return s.isSuper
  if (s.perms.includes('*')) return true
  const list = Array.isArray(p) ? p : String(p).split(',')
  return list.some((x) => s.perms.includes(x.trim()))
}
