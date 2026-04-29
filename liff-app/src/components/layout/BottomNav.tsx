import { useLocation, useNavigate } from 'react-router-dom'
import { Home, ShoppingBag, ClipboardList, CreditCard, User } from 'lucide-react'

const tabs = [
  { path: '/', icon: Home, label: 'หน้าแรก' },
  { path: '/shop', icon: ShoppingBag, label: 'ร้านค้า' },
  { path: '/orders', icon: ClipboardList, label: 'ออเดอร์' },
  { path: '/member', icon: CreditCard, label: 'สมาชิก' },
  { path: '/profile', icon: User, label: 'โปรไฟล์' },
]

export function BottomNav() {
  const location = useLocation()
  const navigate = useNavigate()

  return (
    <nav className="bg-white/95 backdrop-blur-lg border-t border-slate-100 safe-bottom" aria-label="เมนูหลัก">
      <div className="flex items-stretch justify-around h-[56px]">
        {tabs.map((tab) => {
          const active = tab.path === '/' ? location.pathname === '/' : location.pathname.startsWith(tab.path)
          return (
            <button
              key={tab.path}
              type="button"
              onClick={() => navigate(tab.path)}
              aria-label={`ไปที่${tab.label}`}
              aria-current={active ? 'page' : undefined}
              className="flex flex-col items-center justify-center flex-1 min-w-0 cursor-pointer relative transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
            >
              {active && <span className="absolute top-0 left-1/2 -translate-x-1/2 w-6 h-[3px] bg-primary rounded-full" />}
              <tab.icon aria-hidden="true" className={`w-[22px] h-[22px] transition-colors duration-150 ${active ? 'text-primary' : 'text-slate-400'}`} strokeWidth={active ? 2.2 : 1.8} />
              <span className={`text-[10px] mt-0.5 transition-colors duration-150 ${active ? 'text-primary font-semibold' : 'text-slate-400 font-medium'}`}>{tab.label}</span>
            </button>
          )
        })}
      </div>
    </nav>
  )
}
