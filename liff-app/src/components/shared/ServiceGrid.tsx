import { useNavigate } from 'react-router-dom'
import { ShoppingBag, ShoppingCart, ClipboardList, Bot, Calendar, Gift } from 'lucide-react'

const services = [
  { path: '/shop', icon: ShoppingBag, label: 'ร้านค้า', bg: 'bg-emerald-50', fg: 'text-emerald-600' },
  { path: '/cart', icon: ShoppingCart, label: 'ตะกร้า', bg: 'bg-cyan-50', fg: 'text-cyan-600' },
  { path: '/orders', icon: ClipboardList, label: 'ออเดอร์', bg: 'bg-orange-50', fg: 'text-orange-500' },
  { path: '/ai-chat', icon: Bot, label: 'AI ช่วย', bg: 'bg-violet-50', fg: 'text-violet-600' },
  { path: '/appointments', icon: Calendar, label: 'นัดหมาย', bg: 'bg-rose-50', fg: 'text-rose-500' },
  { path: '/redeem', icon: Gift, label: 'แลกแต้ม', bg: 'bg-amber-50', fg: 'text-amber-600' },
]

export function ServiceGrid() {
  const navigate = useNavigate()
  return (
    <div className="grid grid-cols-3 gap-2.5">
      {services.map((s) => (
        <button
          key={s.path}
          onClick={() => navigate(s.path)}
          className="flex flex-col items-center gap-1.5 py-4 bg-white rounded-2xl cursor-pointer active:scale-[0.96] transition-all duration-150 hover:shadow-sm border border-slate-50"
        >
          <div className={`w-11 h-11 rounded-2xl flex items-center justify-center ${s.bg}`}>
            <s.icon className={`w-[22px] h-[22px] ${s.fg}`} strokeWidth={1.8} />
          </div>
          <span className="text-[11px] font-medium text-slate-700">{s.label}</span>
        </button>
      ))}
    </div>
  )
}
