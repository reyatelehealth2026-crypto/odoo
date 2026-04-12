import type { ReactNode } from 'react'
import { BottomNav } from './BottomNav'

export function AppShell({ children }: { children: ReactNode }) {
  return (
    <div className="min-h-dvh bg-surface-dim">
      <div className="max-w-[430px] mx-auto bg-surface min-h-dvh pb-[72px] relative shadow-[0_0_40px_rgba(0,0,0,0.06)]">
        {children}
      </div>
      <div className="fixed bottom-0 left-0 right-0 z-50">
        <div className="max-w-[430px] mx-auto">
          <BottomNav />
        </div>
      </div>
    </div>
  )
}
