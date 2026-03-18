// Customer management types

export interface Customer {
  id: string;
  lineAccountId: string;
  lineUserId: string | null;
  displayName: string | null;
  realName: string | null;
  phone: string | null;
  email: string | null;
  address: string | null;
  province: string | null;
  district: string | null;
  postalCode: string | null;
  birthday: Date | null;
  gender: string | null;
  notes: string | null;
  tags: string | null;
  totalOrders: number;
  totalSpent: number;
  availablePoints: number;
  tier: string | null;
  membershipLevel: string | null;
  customerScore: number;
  medicalConditions: string | null;
  drugAllergies: string | null;
  currentMedications: string | null;
  emergencyContact: string | null;
  bloodType: string | null;
  lastOrderAt: Date | null;
  lastInteractionAt: Date | null;
  isBlocked: boolean;
  createdAt: Date;
  updatedAt: Date;
}

export interface CustomerListItem {
  id: string;
  lineAccountId: string;
  lineUserId: string | null;
  displayName: string | null;
  realName: string | null;
  phone: string | null;
  email: string | null;
  totalOrders: number;
  totalSpent: number;
  availablePoints: number;
  tier: string | null;
  membershipLevel: string | null;
  lastOrderAt: Date | null;
  lastInteractionAt: Date | null;
  isBlocked: boolean;
  createdAt: Date;
  updatedAt: Date;
}

export interface CustomerFilters {
  search?: string;
  name?: string;
  reference?: string;
  partnerId?: string;
  lineConnected?: boolean;
  tier?: string;
  dateFrom?: Date;
  dateTo?: Date;
}

export interface PaginatedCustomers {
  data: CustomerListItem[];
  meta: {
    page: number;
    limit: number;
    total: number;
    totalPages: number;
  };
}

export interface CustomerStatistics {
  totalCustomers: number;
  newCustomers: number;
  activeCustomers: number;
  lineConnected: number;
  averageOrderValue: number;
  topTiers: Record<string, number>;
}

export interface CustomerOrder {
  id: string;
  odooOrderId: string;
  status: string;
  totalAmount: number;
  currency: string;
  orderDate: Date | null;
  deliveryDate: Date | null;
  createdAt: Date;
}

export interface PaginatedCustomerOrders {
  data: CustomerOrder[];
  meta: {
    page: number;
    limit: number;
    total: number;
    totalPages: number;
  };
}

export interface LineConnectionUpdate {
  lineUserId: string | null;
}
