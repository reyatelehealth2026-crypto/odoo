import { useAuthStore } from '@/stores/useAuthStore'
import { useLiff } from '@/providers/LiffProvider'
import { formatNumber, formatMemberId } from '@/lib/format'
import { CreditCard, Sparkles } from 'lucide-react'

export function MemberCard() {
  const { isLoggedIn, profile, login } = useLiff()
  const member = useAuthStore((s) => s.member)
  const tier = useAuthStore((s) => s.tier)

  if (!isLoggedIn || !member) {
    return (
      <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-[#06C755] via-[#05B34D] to-[#049840] p-5 text-white">
        <div className="absolute -top-8 -right-8 w-32 h-32 bg-white/5 rounded-full" />
        <div className="absolute -bottom-4 -left-4 w-24 h-24 bg-white/5 rounded-full" />
        <div className="relative">
          <div className="flex items-center gap-2.5 mb-5">
            <div className="w-9 h-9 rounded-xl bg-white/15 flex items-center justify-center backdrop-blur-sm">
              <CreditCard className="w-4.5 h-4.5" />
            </div>
            <div>
              <p className="text-sm font-semibold tracking-wide">Re-Ya Member</p>
              <p className="text-[11px] text-white/60">สะสมแต้ม รับสิทธิพิเศษ</p>
            </div>
          </div>
          <button onClick={login} className="w-full bg-white text-primary text-sm font-semibold py-3 rounded-xl cursor-pointer hover:bg-white/95 active:scale-[0.98] transition-all duration-150 shadow-[0_2px_8px_rgba(0,0,0,0.1)]">
            เข้าสู่ระบบด้วย LINE
          </button>
        </div>
      </div>
    )
  }

  const progress = tier ? Math.min(100, (tier.current_tier_points / (tier.next_tier_points || 1)) * 100) : 0

  return (
    <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-[#06C755] via-[#05B34D] to-[#049840] p-5 text-white">
      <div className="absolute -top-8 -right-8 w-32 h-32 bg-white/5 rounded-full" />
      <div className="absolute bottom-0 right-0 w-40 h-40 bg-white/[0.03] rounded-full translate-x-10 translate-y-10" />
      <div className="relative">
        <div className="flex items-center justify-between mb-4">
          <div className="flex items-center gap-3">
            {profile?.pictureUrl ? (
              <img src={profile.pictureUrl} alt="" className="w-11 h-11 rounded-full border-2 border-white/30 shadow-lg" />
            ) : (
              <div className="w-11 h-11 rounded-full bg-white/20 flex items-center justify-center text-lg font-bold">{(member.display_name || '?').charAt(0)}</div>
            )}
            <div>
              <p className="font-semibold text-[15px] leading-tight">{member.display_name || member.first_name || profile?.displayName}</p>
              <p className="text-[11px] text-white/60 mt-0.5 font-mono tracking-wider">{formatMemberId(member.member_id)}</p>
            </div>
          </div>
          {tier && (
            <div className="flex items-center gap-1 bg-white/15 backdrop-blur-sm text-white text-[11px] font-bold px-3 py-1.5 rounded-full">
              <Sparkles className="w-3 h-3" />
              {tier.name}
            </div>
          )}
        </div>
        <div className="flex items-end justify-between mt-1">
          <div>
            <p className="text-[28px] font-bold leading-none tracking-tight">{formatNumber(member.points)}</p>
            <p className="text-[11px] text-white/60 mt-1">คะแนนสะสม</p>
          </div>
          {tier?.next_tier_name && (
            <div className="text-right">
              <div className="w-28 h-[6px] bg-white/15 rounded-full overflow-hidden mb-1.5">
                <div className="h-full bg-white rounded-full transition-all duration-500 ease-out" style={{ width: `${progress}%` }} />
              </div>
              <p className="text-[10px] text-white/60">อีก {formatNumber(Math.max(0, (tier.next_tier_points || 0) - (tier.current_tier_points || 0)))} ถึง {tier.next_tier_name}</p>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
