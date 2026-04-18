import { appConfig } from '@/lib/config'
import { phpGet, phpPost } from '@/lib/php-bridge'
import type { MemberCardResponse, MemberCheckResponse, MemberUpdatePayload } from '@/types/member'

export async function checkMember(lineUserId: string, displayName?: string, pictureUrl?: string) {
  const res = await phpGet<MemberCheckResponse & { error?: string }>('/api/member.php', {
    action: 'check',
    line_user_id: lineUserId,
    line_account_id: appConfig.lineAccountId,
    display_name: displayName,
    picture_url: pictureUrl
  })
  if (!res.success) {
    throw new Error(res.message || res.error || 'member check failed')
  }
  return res
}

export async function getMemberCard(lineUserId: string) {
  const res = await phpGet<MemberCardResponse & { error?: string }>('/api/member.php', {
    action: 'get_card',
    line_user_id: lineUserId,
    line_account_id: appConfig.lineAccountId
  })
  if (!res.success) {
    throw new Error(res.message || res.error || 'ไม่พบข้อมูลสมาชิก')
  }
  return res
}

export function updateMemberProfile(payload: MemberUpdatePayload) {
  return phpPost<{ success: boolean; message: string }>('/api/member.php', {
    action: 'update_profile',
    ...payload
  })
}

export type RegisterMemberPayload = {
  line_user_id: string
  first_name: string
  last_name?: string
  birthday: string
  gender: string
  phone?: string
  email?: string
  display_name?: string
  picture_url?: string
}

export function registerMember(payload: RegisterMemberPayload) {
  return phpPost<{ success: boolean; message: string; member_id?: string }>('/api/member.php', {
    action: 'register',
    line_account_id: appConfig.lineAccountId,
    ...payload
  })
}
