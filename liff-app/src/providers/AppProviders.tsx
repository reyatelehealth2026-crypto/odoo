import type { ReactNode } from 'react'
import { useEffect } from 'react'
import { BrowserRouter, useNavigate } from 'react-router-dom'
import { QueryProvider } from './QueryProvider'
import { LiffProvider } from './LiffProvider'

function HashDeepLinkRedirect() {
  const navigate = useNavigate()

  useEffect(() => {
    const hashPath = window.location.hash
    if (!hashPath.startsWith('#/')) return

    navigate(hashPath.slice(1), { replace: true })
    window.history.replaceState(null, '', window.location.pathname + window.location.search)
  }, [navigate])

  return null
}

export function AppProviders({ children }: { children: ReactNode }) {
  return (
    <BrowserRouter basename="/app">
      <HashDeepLinkRedirect />
      <QueryProvider>
        <LiffProvider>
          {children}
        </LiffProvider>
      </QueryProvider>
    </BrowserRouter>
  )
}
