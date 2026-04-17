import { NextRequest, NextResponse } from 'next/server'

const PHP_API_BASE = (process.env.NEXT_PUBLIC_PHP_API_BASE_URL || 'https://cny.re-ya.com').replace(/\/$/, '')
const ACCOUNT_ID = process.env.NEXT_PUBLIC_LINE_ACCOUNT_ID || '3'

export async function GET(request: NextRequest) {
  try {
    const { searchParams } = request.nextUrl
    const action = searchParams.get('action') || 'home_all'

    const url = new URL(`${PHP_API_BASE}/api/miniapp-home-content.php`)
    url.searchParams.set('action', action)
    url.searchParams.set('line_account_id', ACCOUNT_ID)

    // Forward optional params
    const position = searchParams.get('position')
    if (position) url.searchParams.set('position', position)

    const sectionId = searchParams.get('section_id')
    if (sectionId) url.searchParams.set('section_id', sectionId)

    const limit = searchParams.get('limit')
    if (limit) url.searchParams.set('limit', limit)

    const surface = searchParams.get('surface')
    if (surface) url.searchParams.set('surface', surface)

    const response = await fetch(url.toString(), {
      method: 'GET',
      cache: 'no-store'
    })

    const rawText = await response.text()
    const contentType = response.headers.get('content-type') || ''

    // Safe JSON parse; on non-JSON responses surface upstream snippet for easier debugging
    let data: unknown
    try {
      data = JSON.parse(rawText)
    } catch {
      return NextResponse.json(
        {
          success: false,
          error: 'Upstream returned non-JSON response',
          upstream_status: response.status,
          upstream_content_type: contentType,
          upstream_url: url.toString(),
          upstream_snippet: rawText.slice(0, 500)
        },
        { status: 502 }
      )
    }

    return NextResponse.json(data, { status: response.status })
  } catch (error) {
    return NextResponse.json(
      {
        success: false,
        error: error instanceof Error ? error.message : 'Proxy error',
        phpBase: PHP_API_BASE
      },
      { status: 500 }
    )
  }
}
