'use client';

import React, { useState } from 'react';
import { CustomerFilters } from '@/types/customers';

interface CustomerSearchProps {
  onSearch: (filters: CustomerFilters) => void;
  loading?: boolean;
}

export const CustomerSearch: React.FC<CustomerSearchProps> = ({ onSearch, loading = false }) => {
  const [searchTerm, setSearchTerm] = useState('');
  const [tier, setTier] = useState<string>('');
  const [lineConnected, setLineConnected] = useState<string>('');
  const [dateFrom, setDateFrom] = useState<string>('');
  const [dateTo, setDateTo] = useState<string>('');
  const [showAdvanced, setShowAdvanced] = useState(false);

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();

    const filters: CustomerFilters = {};

    if (searchTerm) filters.search = searchTerm;
    if (tier) filters.tier = tier;
    if (lineConnected === 'true') filters.lineConnected = true;
    if (lineConnected === 'false') filters.lineConnected = false;
    if (dateFrom) filters.dateFrom = new Date(dateFrom);
    if (dateTo) filters.dateTo = new Date(dateTo);

    onSearch(filters);
  };

  const handleReset = () => {
    setSearchTerm('');
    setTier('');
    setLineConnected('');
    setDateFrom('');
    setDateTo('');
    onSearch({});
  };

  return (
    <div className="bg-white rounded-lg shadow p-6 space-y-4">
      <form onSubmit={handleSearch} className="space-y-4">
        {/* Main search bar */}
        <div className="flex gap-2">
          <div className="flex-1">
            <input
              type="text"
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              placeholder="ค้นหาลูกค้า (ชื่อ, เบอร์โทร, อีเมล, รหัสสมาชิก)"
              className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
              disabled={loading}
            />
          </div>
          <button
            type="submit"
            disabled={loading}
            className="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:bg-gray-400 disabled:cursor-not-allowed transition-colors"
          >
            {loading ? (
              <span className="flex items-center gap-2">
                <svg className="animate-spin h-5 w-5" viewBox="0 0 24 24">
                  <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
                  <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                </svg>
                ค้นหา...
              </span>
            ) : (
              'ค้นหา'
            )}
          </button>
          <button
            type="button"
            onClick={() => setShowAdvanced(!showAdvanced)}
            className="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors"
          >
            {showAdvanced ? 'ซ่อนตัวกรอง' : 'ตัวกรองเพิ่มเติม'}
          </button>
        </div>

        {/* Advanced filters */}
        {showAdvanced && (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 pt-4 border-t">
            {/* Tier filter */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                ระดับสมาชิก
              </label>
              <select
                value={tier}
                onChange={(e) => setTier(e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                disabled={loading}
              >
                <option value="">ทั้งหมด</option>
                <option value="bronze">Bronze</option>
                <option value="silver">Silver</option>
                <option value="gold">Gold</option>
                <option value="platinum">Platinum</option>
              </select>
            </div>

            {/* LINE connection filter */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                เชื่อมต่อ LINE
              </label>
              <select
                value={lineConnected}
                onChange={(e) => setLineConnected(e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                disabled={loading}
              >
                <option value="">ทั้งหมด</option>
                <option value="true">เชื่อมต่อแล้ว</option>
                <option value="false">ยังไม่เชื่อมต่อ</option>
              </select>
            </div>

            {/* Date from */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                วันที่เริ่มต้น
              </label>
              <input
                type="date"
                value={dateFrom}
                onChange={(e) => setDateFrom(e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                disabled={loading}
              />
            </div>

            {/* Date to */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                วันที่สิ้นสุด
              </label>
              <input
                type="date"
                value={dateTo}
                onChange={(e) => setDateTo(e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                disabled={loading}
              />
            </div>
          </div>
        )}

        {/* Reset button */}
        {(searchTerm || tier || lineConnected || dateFrom || dateTo) && (
          <div className="flex justify-end">
            <button
              type="button"
              onClick={handleReset}
              className="text-sm text-gray-600 hover:text-gray-800 underline"
              disabled={loading}
            >
              ล้างตัวกรอง
            </button>
          </div>
        )}
      </form>
    </div>
  );
};
