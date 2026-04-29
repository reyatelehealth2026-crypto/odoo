import { NextResponse, type NextRequest } from 'next/server';

const isDemoDashboardEnabled = process.env.ENABLE_DEMO_DASHBOARD === 'true';

export function proxy(request: NextRequest) {
  if (
    request.nextUrl.pathname.startsWith('/dashboard') &&
    !isDemoDashboardEnabled
  ) {
    const url = request.nextUrl.clone();
    url.pathname = '/';
    url.searchParams.set('dashboard', 'disabled');
    return NextResponse.redirect(url);
  }

  return NextResponse.next();
}

export const config = {
  matcher: ['/dashboard/:path*'],
};
