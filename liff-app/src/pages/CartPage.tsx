import { useNavigate } from 'react-router-dom'
import { Minus, Plus, Trash2, ShoppingCart } from 'lucide-react'
import { useCartStore } from '@/stores/useCartStore'
import { formatCurrency } from '@/lib/format'
import { Button } from '@/components/ui/Button'
import { LazyImage } from '@/components/ui/LazyImage'
import { PageHeader } from '@/components/layout/PageHeader'

export function CartPage() {
  const navigate = useNavigate()
  const items = useCartStore((s) => s.items)
  const updateQuantity = useCartStore((s) => s.updateQuantity)
  const removeItem = useCartStore((s) => s.removeItem)
  const clearCart = useCartStore((s) => s.clearCart)
  const subtotal = useCartStore((s) => s.getSubtotal())

  if (items.length === 0) {
    return (
      <div>
        <PageHeader title="ตะกร้า" showBack={false} />
        <div className="flex flex-col items-center justify-center py-20 text-slate-400">
          <div className="w-20 h-20 rounded-full bg-slate-100 flex items-center justify-center mb-4"><ShoppingCart className="w-8 h-8 text-slate-300" /></div>
          <p className="text-sm font-semibold text-slate-500">ตะกร้าว่าง</p>
          <p className="text-xs text-slate-400 mt-1">เลือกสินค้าที่ชอบแล้วเพิ่มลงตะกร้า</p>
          <Button variant="primary" size="sm" className="mt-4" onClick={() => navigate('/shop')}>เลือกซื้อสินค้า</Button>
        </div>
      </div>
    )
  }

  return (
    <div className="pb-36">
      <PageHeader title={`ตะกร้า (${items.length})`} showBack={false} rightAction={<button onClick={clearCart} className="text-xs text-red-500 font-medium cursor-pointer">ล้างทั้งหมด</button>} />
      <div className="p-4 space-y-3">
        {items.map((item) => {
          const price = item.product.sale_price || item.product.price
          return (
            <div key={item.product.id} className="bg-white rounded-2xl p-3 flex gap-3 border border-slate-100">
              <LazyImage src={item.product.image_url || ''} alt={item.product.name} className="w-20 h-20 rounded-xl shrink-0" />
              <div className="flex-1 min-w-0">
                <p className="text-[13px] font-medium text-slate-800 line-clamp-2 leading-snug">{item.product.name}</p>
                <p className="text-sm font-bold text-primary mt-1.5">{formatCurrency(price)}</p>
                <div className="flex items-center justify-between mt-2">
                  <div className="flex items-center gap-1.5">
                    <button onClick={() => updateQuantity(item.product.id, item.quantity - 1)} className="w-8 h-8 rounded-xl bg-slate-100 flex items-center justify-center cursor-pointer active:bg-slate-200 transition-colors"><Minus className="w-3.5 h-3.5 text-slate-600" /></button>
                    <span className="text-sm font-semibold w-8 text-center text-slate-800">{item.quantity}</span>
                    <button onClick={() => updateQuantity(item.product.id, item.quantity + 1)} className="w-8 h-8 rounded-xl bg-primary/10 flex items-center justify-center cursor-pointer active:bg-primary/20 transition-colors"><Plus className="w-3.5 h-3.5 text-primary" /></button>
                  </div>
                  <button onClick={() => removeItem(item.product.id)} className="w-8 h-8 rounded-xl flex items-center justify-center text-slate-400 hover:text-red-500 hover:bg-red-50 cursor-pointer transition-colors"><Trash2 className="w-4 h-4" /></button>
                </div>
              </div>
            </div>
          )
        })}
      </div>
      <div className="fixed bottom-[72px] left-0 right-0 z-40">
        <div className="max-w-[430px] mx-auto bg-white/95 backdrop-blur-lg border-t border-slate-100 px-4 py-3 safe-bottom">
          <div className="flex items-center justify-between mb-3"><span className="text-sm text-slate-500">รวมทั้งหมด</span><span className="text-xl font-bold text-primary">{formatCurrency(subtotal)}</span></div>
          <Button className="w-full" size="lg" onClick={() => navigate('/checkout')}>ดำเนินการสั่งซื้อ</Button>
        </div>
      </div>
    </div>
  )
}
