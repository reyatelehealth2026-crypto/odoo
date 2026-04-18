import { apiUrl } from '@/lib/config'

async function parseResponse<T>(response: Response): Promise<T> {
  // Read as text first so we can give a useful error even when the server
  // returns JSON body with a wrong content-type header (common with PHP).
  const text = await response.text()
  try {
    return JSON.parse(text) as T
  } catch {
    const contentType = response.headers.get('content-type') || 'unknown'
    const snippet = text.slice(0, 200).replace(/\s+/g, ' ').trim()
    throw new Error(
      `PHP API returned non-JSON response (status=${response.status}, content-type=${contentType}): ${snippet}`
    )
  }
}

export async function phpGet<T>(path: string, params?: Record<string, string | number | undefined>) {
  const url = new URL(apiUrl(path))

  if (params) {
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        url.searchParams.set(key, String(value))
      }
    })
  }

  const response = await fetch(url.toString(), {
    method: 'GET',
    cache: 'no-store'
  })

  return parseResponse<T>(response)
}

export async function phpPost<T>(path: string, body: Record<string, unknown>) {
  const response = await fetch(apiUrl(path), {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(body)
  })

  return parseResponse<T>(response)
}
