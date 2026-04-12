import { useNavigate } from 'react-router-dom'
import { useLiff } from '@/providers/LiffProvider'
import { useAuthStore } from '@/stores/useAuthStore'
import { useMember } from '@/hooks/useMember'
import { formatNumber } from '@/lib/format'
import { ChevronRight, Heart, Bell, FileText, Shield, LogOut, Package, Star, Coins } from 'lucide-react'

const menuItems = [
  { icon: Heart, label: 'รายการโปรด', path: '/wishlist', color: 'text-rose-500', bg: 'bg-rose-50' },
  { icon: Bell, label: 'การแจ้งเตือน', path: '/notifications', color: 'text-amber-500', bg: 'bg-amber-50' },
  { icon: FileText, label: 'ข้อมูลสุขภาพ', path: '/health-profile', color: 'text-cyan-600', bg: 'bg-cyan-50' },
  { icon: Shield, label: 'นโยบายความเป็นส่วนตัว', path: '', color: 'text-slate-500', bg: 'bg-slate-50' },
]

export function ProfilePage() {
  const navigate = useNavigate()
  const { profile, logout } = useLiff()
  const member = useAuthStore((s) => s.member)
  useMember()

  return (
    <div>
      {/* Header */}
      <div className="bg-gradient-to-b from-primary to-primary-dark px-5 pt-5 pb-16 safe-top">
        <h1 className="text-white text-[15px] font-semibold mb-5">โปรไฟล์</h1>
        <div className="flex items-center gap-4">
          <div className="w-16 h-16 rounded-full bg-white/20 overflow-hidden border-2 border-white/30 shadow-lg">
            {profile?.pictureUrl ? (
              <img src={profile.pictureUrl} alt="" className="w-full h-full object-cover" />
            ) : (
              <div className="w-full h-full flex items-center justify-center text-white text-2xl font-bold">{profile?.displayName?.charAt(0) || '?'}</div>
            )}
          </div>
          <div>
            <h2 className="text-white text-lg font-bold">{profile?.displayName || 'Guest'}</h2>
            {member && <p className="text-white/60 text-xs mt-0.5">{member.phone || member.email || 'สมาชิก Re-Ya'}</p>}
          </div>
        </div>
      </div>

      <div className="px-4 -mt-10 pb-6 space-y-4">
        {/* Stats */}
        {member && (
          <div className="bg-white rounded-2xl shadow-sm p-4">
            <div className="grid grid-cols-3 gap-3">
              <button onClick={() => navigate('/points')} className="text-center cursor-pointer active:scale-95 transition-transform">
                <div className="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center mx-auto mb-1.5">
                  <Coins className="w-5 h-5 text-emerald-600" />
                </div>
                <p className="text-lg font-bold text-slate-900">{formatNumber(member.points)}</p>
                <p className="text-[10px] text-slate-500">คะแนน</p>
              </button>
              <button onClick={() => navigate('/member')} className="text-center cursor-pointer active:scale-95 transition-transform">
                <div className="w-10 h-10 rounded-xl bg-violet-50 flex items-center justify-center mx-auto mb-1.5">
                  <Star className="w-5 h-5 text-violet-600" />
                </div>
                <p className="text-lg font-bold text-slate-900">{member.tier}</p>
                <p className="text-[10px] text-slate-500">ระดับ</p>
              </button>
              <button onClick={() => navigate('/orders')} className="text-center cursor-pointer active:scale-95 transition-transform">
                <div className="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center mx-auto mb-1.5">
                  <Package className="w-5 h-5 text-blue-600" />
                </div>
                <p className="text-lg font-bold text-slate-900">ดู</p>
                <p className="text-[10px] text-slate-500">ออเดอร์</p>
              </button>
            </div>
          </div>
        )}

        {/* Menu */}
        <div className="bg-white rounded-2xl shadow-sm overflow-hidden">
          {menuItems.map((item, i) => (
            <button
              key={item.label}
              onClick={() => item.path && navigate(item.path)}
              className={`w-full flex items-center gap-3.5 px-4 py-3.5 cursor-pointer active:bg-slate-50 transition-colors ${i > 0 ? 'border-t border-slate-50' : ''}`}
            >
              <div className={`w-9 h-9 rounded-xl flex items-center justify-center ${item.bg}`}>
                <item.icon className={`w-[18px] h-[18px] ${item.color}`} strokeWidth={1.8} />
              </div>
              <span className="flex-1 text-[14px] text-slate-700 text-left">{item.label}</span>
              <ChevronRight className="w-4 h-4 text-slate-300" />
            </button>
          ))}
        </div>

        {/* Logout */}
        <button
          onClick={logout}
          className="w-full flex items-center gap-3.5 px-4 py-3.5 bg-white rounded-2xl shadow-sm cursor-pointer active:bg-red-50 transition-colors"
        >
          <div className="w-9 h-9 rounded-xl bg-red-50 flex items-center justify-center">
            <LogOut className="w-[18px] h-[18px] text-red-500" strokeWidth={1.8} />
          </div>
          <span className="flex-1 text-[14px] text-red-500 font-medium text-left">ออกจากระบบ</span>
        </button>
      </div>
    </div>
  )
}
