import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import liff from '@line/liff'
import { env } from '@/config/env'
import { apiClient } from '@/lib/api-client'
import { useAuthStore } from '@/stores/useAuthStore'
import { useAppStore } from '@/stores/useAppStore'
import type { MemberCardData } from '@/types/member'

interface LiffProfile { userId: string; displayName: string; pictureUrl?: string; statusMessage?: string }

interface LiffContextValue {
  isReady: boolean
  isInClient: boolean
  isLoggedIn: boolean
  isGuest: boolean
  profile: LiffProfile | null
  login: () => void
  logout: () => void
}

const LiffContext = createContext<LiffContextValue>({
  isReady: false, isInClient: false, isLoggedIn: false, isGuest: true, profile: null,
  login: () => {}, logout: () => {},
})

export function useLiff() { return useContext(LiffContext) }

export function LiffProvider({ children }: { children: ReactNode }) {
  const [isReady, setIsReady] = useState(false)
  const [isInClient, setIsInClient] = useState(false)
  const setProfile = useAuthStore((s) => s.setProfile)
  const setMember = useAuthStore((s) => s.setMember)
  const authLogout = useAuthStore((s) => s.logout)
  const profile = useAuthStore((s) => s.profile)
  const isLoggedIn = useAuthStore((s) => s.isLoggedIn)
  const setAppReady = useAppStore((s) => s.setReady)
  const accountId = useAppStore((s) => s.accountId)

  useEffect(() => {
    if (!env.LIFF_ID) { setIsReady(true); setAppReady(true); return }
    liff.init({ liffId: env.LIFF_ID }).then(async () => {
      setIsInClient(liff.isInClient())
      if (liff.isLoggedIn()) {
        const p = await liff.getProfile()
        const liffProfile = { userId: p.userId, displayName: p.displayName, pictureUrl: p.pictureUrl, statusMessage: p.statusMessage }
        setProfile(liffProfile)

        // Auto-check/register member
        try {
          const checkRes = await apiClient<MemberCardData>(`/api/member.php?action=check&line_user_id=${p.userId}&line_account_id=${accountId}`)
          if (checkRes.success && checkRes.data) {
            setMember(checkRes.data.member, checkRes.data.tier)
          } else {
            // Not a member yet → auto-register
            await apiClient('/api/member.php', {
              method: 'POST',
              body: JSON.stringify({
                action: 'register',
                line_user_id: p.userId,
                line_account_id: accountId,
                display_name: p.displayName,
                picture_url: p.pictureUrl,
              }),
            })
          }
        } catch (err) {
          console.error('Auto-register check failed:', err)
        }
      }
      setIsReady(true)
      setAppReady(true)
    }).catch((err) => {
      console.error('LIFF init failed:', err)
      setIsReady(true)
      setAppReady(true)
    })
  }, [])

  const login = () => { if (isReady && !liff.isLoggedIn()) liff.login() }
  const logout = () => { authLogout(); if (liff.isLoggedIn()) liff.logout() }

  return (
    <LiffContext.Provider value={{ isReady, isInClient, isLoggedIn, isGuest: isReady && !isLoggedIn, profile, login, logout }}>
      {children}
    </LiffContext.Provider>
  )
}
