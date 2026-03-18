import type { Metadata } from 'next';
import { Inter } from 'next/font/google';
import './globals.css';

const inter = Inter({
  subsets: ['latin'],
  variable: '--font-inter',
});

export const metadata: Metadata = {
  title: 'Odoo Dashboard - LINE Telepharmacy Platform',
  description:
    'Modern dashboard for managing Odoo ERP integration with LINE Official Account',
  keywords: ['odoo', 'dashboard', 'line', 'telepharmacy', 'thailand'],
  authors: [{ name: 'LINE Telepharmacy Platform' }],
  viewport: 'width=device-width, initial-scale=1',
  themeColor: '#0ea5e9',
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="th" className={inter.variable}>
      <body className={`${inter.className} antialiased`}>
        <div id="root">{children}</div>
      </body>
    </html>
  );
}
