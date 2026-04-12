import { useNavigate } from 'react-router-dom'
import { ChevronLeft } from 'lucide-react'
import type { ReactNode } from 'react'

interface PageHeaderProps {
  title: string
  showBack?: boolean
  rightAction?: ReactNode
}

export function PageHeader({ title, showBack = true, rightAction }: PageHeaderProps) {
  const navigate = useNavigate()

  return (
    <div className="bg-white/95 backdrop-blur-lg px-4 h-[52px] flex items-center gap-2 border-b border-slate-100/80 sticky top-0 z-40 safe-top">
      {showBack && (
        <button onClick={() => navigate(-1)} className="w-9 h-9 -ml-1 rounded-full flex items-center justify-center cursor-pointer hover:bg-slate-100 active:scale-90 transition-all duration-150">
          <ChevronLeft className="w-5 h-5 text-slate-700" strokeWidth={2} />
        </button>
      )}
      <h1 className="text-[15px] font-semibold text-slate-900 flex-1 truncate">{title}</h1>
      {rightAction}
    </div>
  )
}
