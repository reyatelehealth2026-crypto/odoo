import { useNavigate } from 'react-router-dom'
import { ShoppingBag, ShoppingCart, ClipboardList, Bot, Calendar, Gift } from 'lucide-react'

const services = [
  { path: '/shop', icon: ShoppingBag, label: 'ร้านค้า', hint: 'เลือกสินค้า', bg: 'bg-emerald-50', fg: 'text-emerald-600' },
  { path: '/cart', icon: ShoppingCart, label: 'ตะกร้า', hint: 'เช็กก่อนจ่าย', bg: 'bg-cyan-50', fg: 'text-cyan-600' },
  { path: '/orders', icon: ClipboardList, label: 'ออเดอร์', hint: 'ติดตามสถานะ', bg: 'bg-orange-50', fg: 'text-orange-500' },
  { path: '/ai-chat', icon: Bot, label: 'ถามเรื่องยา', hint: 'ตอบไว 24 ชม.', bg: 'bg-violet-50', fg: 'text-violet-600' },
  { path: '/appointments', icon: Calendar, label: 'นัดหมาย', hint: 'จองคิวปรึกษา', bg: 'bg-rose-50', fg: 'text-rose-500' },
  { path: '/redeem', icon: Gift, label: 'แลกแต้ม', hint: 'รับสิทธิพิเศษ', bg: 'bg-amber-50', fg: 'text-amber-600' },
]

export function ServiceGrid() {
  const navigate = useNavigate()
  return (
    <div className="grid grid-cols-3 gap-2.5">
      {services.map((s) => (
        <button
          key={s.path}
          type="button"
          onClick={() => navigate(s.path)}
          aria-label={`${s.label}: ${s.hint}`}
          className="flex flex-col items-center gap-1.5 py-4 px-1 bg-white rounded-2xl cursor-pointer active:scale-[0.96] transition-all duration-150 hover:shadow-sm border border-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
        >
          <div className={`w-11 h-11 rounded-2xl flex items-center justify-center ${s.bg}`}>
            <s.icon aria-hidden="true" className={`w-[22px] h-[22px] ${s.fg}`} strokeWidth={1.8} />
          </div>
          <span className="text-[11px] font-medium text-slate-700">{s.label}</span>
          <span className="text-[9px] text-slate-400 leading-none">{s.hint}</span>
        </button>
      ))}
    </div>
  )
}
