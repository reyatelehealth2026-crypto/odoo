import { useState, useMemo } from 'react'
import { Search, ShoppingBag } from 'lucide-react'
import { useProducts } from '@/hooks/useProducts'
import { ProductCard } from '@/components/shared/ProductCard'
import { CartSummaryBar } from '@/components/shared/CartSummaryBar'
import { Skeleton } from '@/components/ui/Skeleton'

export function ShopPage() {
  const [search, setSearch] = useState('')
  const [category, setCategory] = useState<string | undefined>()
  const { data: products, isLoading } = useProducts(category)

  const categories = useMemo(() => {
    const cats = new Set(products?.map((p) => p.category).filter(Boolean) as string[])
    return ['ทั้งหมด', ...cats]
  }, [products])

  const filtered = useMemo(() => {
    if (!search) return products || []
    const q = search.toLowerCase()
    return (products || []).filter((p) => p.name.toLowerCase().includes(q))
  }, [products, search])

  return (
    <div className="pb-20">
      <div className="bg-white/95 backdrop-blur-lg px-4 pt-3 pb-3 safe-top sticky top-0 z-30 border-b border-slate-100/80">
        <div className="relative mb-3">
          <Search className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
          <input type="text" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="ค้นหาสินค้า..." className="w-full pl-10 pr-4 py-2.5 bg-slate-100 rounded-xl text-sm text-slate-800 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-primary/20 focus:bg-white transition-colors" />
        </div>
        <div className="flex gap-2 overflow-x-auto scrollbar-hide -mx-4 px-4">
          {categories.map((cat) => (
            <button key={cat} onClick={() => setCategory(cat === 'ทั้งหมด' ? undefined : cat)} className={`shrink-0 px-3.5 py-1.5 rounded-full text-[12px] font-medium transition-all duration-150 cursor-pointer ${(!category && cat === 'ทั้งหมด') || category === cat ? 'bg-primary text-white shadow-sm' : 'bg-slate-100 text-slate-600 active:bg-slate-200'}`}>
              {cat}
            </button>
          ))}
        </div>
      </div>
      <div className="p-4">
        {isLoading ? (
          <div className="grid grid-cols-2 gap-3">{[1, 2, 3, 4].map((i) => <div key={i} className="bg-white rounded-2xl overflow-hidden"><div className="aspect-square animate-shimmer" /><div className="p-3 space-y-2"><Skeleton height="12px" /><Skeleton height="14px" className="w-1/2" /></div></div>)}</div>
        ) : filtered.length > 0 ? (
          <div className="grid grid-cols-2 gap-3">{filtered.map((p) => <ProductCard key={p.id} product={p} />)}</div>
        ) : (
          <div className="flex flex-col items-center justify-center py-20 text-slate-400">
            <ShoppingBag className="w-12 h-12 mb-3 text-slate-300" />
            <p className="text-sm font-medium">ไม่พบสินค้า</p>
            {search && <p className="text-xs text-slate-400 mt-1">ลองค้นหาด้วยคำอื่น</p>}
          </div>
        )}
      </div>
      <CartSummaryBar />
    </div>
  )
}
