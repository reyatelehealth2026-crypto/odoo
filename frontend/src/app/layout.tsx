import type { Metadata } from 'next';
import { Inter } from 'next/font/google';
import { Providers } from './providers';
import './globals.css';

const inter = Inter({
  subsets: ['latin'],
  variable: '--font-inter',
});

export const metadata: Metadata = {
  title: 'CLINICYA Admin Dashboard',
  description:
    'แดชบอร์ดสำหรับจัดการคำสั่งซื้อ การชำระเงิน ลูกค้า และการเชื่อมต่อ LINE ของ CLINICYA',
  keywords: ['clinicya', 'dashboard', 'line', 'telepharmacy', 'thailand'],
  authors: [{ name: 'CLINICYA' }],
  viewport: 'width=device-width, initial-scale=1',
  themeColor: '#11B0A6',
  robots: {
    follow: false,
    index: false,
  },
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="th" className={inter.variable}>
      <body className={`${inter.className} antialiased`}>
        <div id="root">
          <Providers>{children}</Providers>
        </div>
      </body>
    </html>
  );
}
