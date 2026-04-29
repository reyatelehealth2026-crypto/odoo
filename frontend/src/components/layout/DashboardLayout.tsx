'use client';

import React from 'react';
import { User } from '@/types';
import { cn } from '@/lib/utils';

export interface NavigationItem {
  id: string;
  label: string;
  href: string;
  icon?: React.ReactNode;
  badge?: string | number;
  children?: NavigationItem[];
}

export interface DashboardLayoutProps {
  children: React.ReactNode;
  user: User;
  navigation: NavigationItem[];
  currentPath?: string;
}

export function DashboardLayout({
  children,
  user,
  navigation,
  currentPath = '',
}: DashboardLayoutProps) {
  const [sidebarOpen, setSidebarOpen] = React.useState(false);

  return (
    <div className="flex h-screen bg-secondary-50">
      {/* Sidebar */}
      <div
        className={cn(
          'fixed inset-y-0 left-0 z-50 w-64 transform bg-white shadow-lg transition-transform duration-300 ease-in-out lg:static lg:translate-x-0',
          sidebarOpen ? 'translate-x-0' : '-translate-x-full'
        )}
      >
        <div className="flex h-full flex-col">
          {/* Logo */}
          <div className="flex h-16 items-center justify-center border-b border-secondary-200 px-6">
            <h1 className="text-xl font-bold text-primary-600">
              CLINICYA Admin
            </h1>
          </div>

          {/* Navigation */}
          <nav className="flex-1 space-y-1 px-4 py-6">
            {navigation.map(item => (
              <NavigationLink
                key={item.id}
                item={item}
                currentPath={currentPath}
              />
            ))}
          </nav>

          {/* User info */}
          <div className="border-t border-secondary-200 p-4">
            <div className="flex items-center space-x-3">
              <div className="flex h-8 w-8 items-center justify-center rounded-full bg-primary-600">
                <span className="text-sm font-medium text-white">
                  {user.username.charAt(0).toUpperCase()}
                </span>
              </div>
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium text-secondary-900">
                  {user.username}
                </p>
                <p className="text-xs capitalize text-secondary-500">
                  {user.role.replace('_', ' ')}
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Mobile sidebar overlay */}
      {sidebarOpen && (
        <div
          className="fixed inset-0 z-40 bg-black bg-opacity-50 lg:hidden"
          onClick={() => setSidebarOpen(false)}
        />
      )}

      {/* Main content */}
      <div className="flex flex-1 flex-col lg:ml-0">
        {/* Header */}
        <header className="border-b border-secondary-200 bg-white shadow-sm">
          <div className="flex h-16 items-center justify-between px-6">
            <button
              type="button"
              className="text-secondary-500 hover:text-secondary-600 lg:hidden"
              onClick={() => setSidebarOpen(true)}
            >
              <span className="sr-only">เปิดเมนู</span>
              <svg
                className="h-6 w-6"
                fill="none"
                viewBox="0 0 24 24"
                strokeWidth="1.5"
                stroke="currentColor"
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"
                />
              </svg>
            </button>

            <div className="flex items-center space-x-4">
              {/* Notifications */}
              <button
                type="button"
                className="text-secondary-400 hover:text-secondary-500"
              >
                <span className="sr-only">การแจ้งเตือน</span>
                <svg
                  className="h-6 w-6"
                  fill="none"
                  viewBox="0 0 24 24"
                  strokeWidth="1.5"
                  stroke="currentColor"
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"
                  />
                </svg>
              </button>
            </div>
          </div>
        </header>

        {/* Page content */}
        <main className="flex-1 overflow-auto">
          <div className="p-6">{children}</div>
        </main>
      </div>
    </div>
  );
}

interface NavigationLinkProps {
  item: NavigationItem;
  currentPath: string;
}

function NavigationLink({ item, currentPath }: NavigationLinkProps) {
  const isActive = currentPath === item.href;

  return (
    <a
      href={item.href}
      className={cn(
        'group flex items-center rounded-lg px-3 py-2 text-sm font-medium transition-colors',
        isActive
          ? 'bg-primary-50 text-primary-700'
          : 'text-secondary-700 hover:bg-secondary-50 hover:text-secondary-900'
      )}
    >
      {item.icon && (
        <span
          className={cn(
            'mr-3 h-5 w-5',
            isActive ? 'text-primary-500' : 'text-secondary-400'
          )}
        >
          {item.icon}
        </span>
      )}
      <span className="flex-1">{item.label}</span>
      {item.badge && (
        <span
          className={cn(
            'ml-2 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
            isActive
              ? 'bg-primary-100 text-primary-700'
              : 'bg-secondary-100 text-secondary-600'
          )}
        >
          {item.badge}
        </span>
      )}
    </a>
  );
}
