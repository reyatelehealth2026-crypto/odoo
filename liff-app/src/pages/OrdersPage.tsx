import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, ClipboardList } from 'lucide-react'
import { useOrders } from '@/hooks/useOrders'
import { formatCurrency, formatDate } from '@/lib/format'
import { Badge } from '@/components/ui/Badge'
import type { OrderStatus } from '@/types/product'

const statusFilters: { key: OrderStatus | 'all'; label: string }[] = [
  { key: 'all', label: 'ทั้งหมด' }, { key: 'pending', label: 'รอดำเนินการ' }, { key: 'confirmed', label: 'ยืนยันแล้ว' }, { key: 'shipped', label: 'จัดส่งแล้ว' }, { key: 'delivered', label: 'สำเร็จ' }, { key: 'cancelled', label: 'ยกเลิก' },
]
const statusBadgeVariant: Record<OrderStatus, 'default' | 'success' | 'warning' | 'danger' | 'info'> = { pending: 'warning', confirmed: 'info', processing: 'info', shipped: 'info', delivered: 'success', cancelled: 'danger' }
const statusLabel: Record<OrderStatus, string> = { pending: 'รอดำเนินการ', confirmed: 'ยืนยันแล้ว', processing: 'กำลังจัดเตรียม', shipped: 'จัดส่งแล้ว', delivered: 'สำเร็จ', cancelled: 'ยกเลิก' }

export function OrdersPage() {
  const navigate = useNavigate()
  const [filter, setFilter] = useState<OrderStatus | 'all'>('all')
  const { data: orders, isLoading } = useOrders()
  const filtered = orders?.filter((o) => filter === 'all' || o.status === filter)

  return (
    <div className="pb-4">
      <div className="bg-white/95 backdrop-blur-lg px-4 pt-3 pb-3 safe-top sticky top-0 z-30 border-b border-slate-100/80">
        <h1 className="text-[15px] font-semibold text-slate-900 mb-3">ออเดอร์ของฉัน</h1>
        <div className="flex gap-2 overflow-x-auto scrollbar-hide -mx-4 px-4">
          {statusFilters.map((f) => (<button key={f.key} onClick={() => setFilter(f.key)} className={`shrink-0 px-3.5 py-1.5 rounded-full text-[12px] font-medium transition-all duration-150 cursor-pointer ${filter === f.key ? 'bg-primary text-white shadow-sm' : 'bg-slate-100 text-slate-600 active:bg-slate-200'}`}>{f.label}</button>))}
        </div>
      </div>
      <div className="p-4 space-y-3">
        {isLoading ? ([1, 2, 3].map((i) => <div key={i} className="bg-white rounded-2xl p-4 border border-slate-100"><div className="animate-shimmer h-14 rounded-xl" /></div>))
        : filtered && filtered.length > 0 ? filtered.map((order) => (
          <div key={order.id} className="bg-white rounded-2xl p-4 border border-slate-100 cursor-pointer active:scale-[0.99] active:bg-slate-50 transition-all duration-150" onClick={() => navigate(`/order/${order.id}`)}>
            <div className="flex items-center justify-between mb-2.5"><span className="text-xs text-slate-500 font-mono">#{order.order_number}</span><Badge variant={statusBadgeVariant[order.status]}>{statusLabel[order.status]}</Badge></div>
            <div className="flex items-center justify-between">
              <div><p className="text-[13px] font-medium text-slate-800">{order.items.length} รายการ</p><p className="text-[11px] text-slate-400 mt-0.5">{formatDate(order.created_at)}</p></div>
              <div className="flex items-center gap-2"><span className="text-sm font-bold text-slate-900">{formatCurrency(order.total)}</span><ChevronRight className="w-4 h-4 text-slate-300" /></div>
            </div>
          </div>
        )) : (
          <div className="flex flex-col items-center justify-center py-20 text-slate-400">
            <div className="w-20 h-20 rounded-full bg-slate-100 flex items-center justify-center mb-4"><ClipboardList className="w-8 h-8 text-slate-300" /></div>
            <p className="text-sm font-semibold text-slate-500">ไม่มีออเดอร์</p>
            {filter !== 'all' && <button onClick={() => setFilter('all')} className="mt-3 text-xs text-primary font-medium cursor-pointer">ดูทั้งหมด</button>}
          </div>
        )}
      </div>
    </div>
  )
}
