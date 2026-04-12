import { useNavigate } from 'react-router-dom'
import { Bell, Bot, Sparkles, ChevronRight, Stethoscope } from 'lucide-react'
import { MemberCard } from '@/components/shared/MemberCard'
import { ServiceGrid } from '@/components/shared/ServiceGrid'
import { PharmacistCard } from '@/components/shared/PharmacistCard'
import { CartSummaryBar } from '@/components/shared/CartSummaryBar'
import { useMember } from '@/hooks/useMember'
import { usePharmacists } from '@/hooks/usePharmacists'

export function HomePage() {
  const navigate = useNavigate()
  useMember()
  const { data: pharmacists } = usePharmacists()
  const onlinePharmacists = pharmacists?.filter((p) => p.is_online).slice(0, 3) || []

  return (
    <div>
      {/* Header */}
      <div className="bg-gradient-to-b from-primary to-primary-dark px-5 pt-4 pb-14 safe-top">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-white text-lg font-bold tracking-tight">Re-Ya Pharmacy</h1>
            <p className="text-white/60 text-[11px] mt-0.5">สุขภาพดี เริ่มต้นที่นี่</p>
          </div>
          <button onClick={() => navigate('/notifications')} className="w-10 h-10 rounded-full bg-white/15 backdrop-blur-sm flex items-center justify-center cursor-pointer active:scale-90 transition-transform">
            <Bell className="w-[18px] h-[18px] text-white" strokeWidth={1.8} />
          </button>
        </div>
      </div>

      {/* Content - pulled up over header */}
      <div className="px-4 -mt-10 space-y-4 pb-6">
        <MemberCard />

        {/* Service Grid */}
        <div>
          <div className="flex items-center justify-between mb-2.5">
            <h2 className="text-[13px] font-semibold text-slate-800">บริการ</h2>
          </div>
          <ServiceGrid />
        </div>

        {/* AI CTA */}
        <button
          onClick={() => navigate('/ai-chat')}
          className="w-full relative overflow-hidden bg-gradient-to-r from-violet-600 via-purple-600 to-indigo-600 text-white rounded-2xl p-4 flex items-center gap-3.5 cursor-pointer active:scale-[0.98] transition-all duration-150 shadow-[0_4px_16px_rgba(124,58,237,0.3)]"
        >
          <div className="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(255,255,255,0.15),transparent_60%)]" />
          <div className="w-12 h-12 rounded-2xl bg-white/15 backdrop-blur-sm flex items-center justify-center shrink-0">
            <Bot className="w-6 h-6" />
          </div>
          <div className="text-left flex-1 relative">
            <div className="flex items-center gap-1.5 mb-0.5">
              <p className="font-semibold text-sm">ปรึกษาเภสัชกร AI</p>
              <Sparkles className="w-3.5 h-3.5 text-amber-300" />
            </div>
            <p className="text-[11px] text-white/70">ถามเรื่องยาและสุขภาพได้ตลอด 24 ชม.</p>
          </div>
          <ChevronRight className="w-4 h-4 text-white/40 shrink-0" />
        </button>

        {/* Online Pharmacists */}
        {onlinePharmacists.length > 0 && (
          <div>
            <div className="flex items-center justify-between mb-2.5">
              <div className="flex items-center gap-2">
                <Stethoscope className="w-4 h-4 text-primary" />
                <h2 className="text-[13px] font-semibold text-slate-800">เภสัชกรออนไลน์</h2>
              </div>
              <button onClick={() => navigate('/video-call')} className="text-[11px] text-primary font-medium cursor-pointer">
                ดูทั้งหมด
              </button>
            </div>
            <div className="space-y-2.5">
              {onlinePharmacists.map((p) => <PharmacistCard key={p.id} pharmacist={p} onBook={(id) => navigate(`/video-call/${id}`)} />)}
            </div>
          </div>
        )}
      </div>

      <CartSummaryBar />
    </div>
  )
}
