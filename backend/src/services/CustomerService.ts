import { BaseService } from './BaseService';
import { PrismaClient, Prisma } from '@prisma/client';

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

export interface PaginationOptions {
  page: number;
  limit: number;
  sort?: string;
  order?: 'asc' | 'desc';
}

export interface Customer {
  id: string;
  lineAccountId: string;
  lineUserId: string;
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

export interface CustomerProfile extends Customer {
  address: string | null;
  province: string | null;
  district: string | null;
  postalCode: string | null;
  birthday: Date | null;
  gender: string | null;
  notes: string | null;
  tags: string | null;
  customerScore: number;
  medicalConditions: string | null;
  drugAllergies: string | null;
  currentMedications: string | null;
  emergencyContact: string | null;
  bloodType: string | null;
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

export interface PaginatedCustomers {
  data: Customer[];
  meta: {
    page: number;
    limit: number;
    total: number;
    totalPages: number;
  };
}

export interface PaginatedOrders {
  data: CustomerOrder[];
  meta: {
    page: number;
    limit: number;
    total: number;
    totalPages: number;
  };
}

export class CustomerService extends BaseService {
  constructor(prisma: PrismaClient) {
    super(prisma);
  }

  /**
   * Search customers by various criteria
   * Validates: Requirements FR-3.1
   */
  async searchCustomers(
    lineAccountId: string,
    filters: CustomerFilters = {},
    pagination: PaginationOptions = { page: 1, limit: 20 }
  ): Promise<PaginatedCustomers> {
    try {
      const { page, limit, sort = 'updatedAt', order = 'desc' } = pagination;
      const skip = (page - 1) * limit;

      // Build where clause using raw SQL since users table is not in Prisma schema
      let whereConditions: string[] = ['line_account_id = ?'];
      const params: any[] = [lineAccountId];

      if (filters.search) {
        whereConditions.push(
          '(display_name LIKE ? OR real_name LIKE ? OR phone LIKE ? OR email LIKE ? OR member_id LIKE ?)'
        );
        const searchPattern = `%${filters.search}%`;
        params.push(searchPattern, searchPattern, searchPattern, searchPattern, searchPattern);
      }

      if (filters.name) {
        whereConditions.push('(display_name LIKE ? OR real_name LIKE ?)');
        const namePattern = `%${filters.name}%`;
        params.push(namePattern, namePattern);
      }

      if (filters.reference) {
        whereConditions.push('member_id LIKE ?');
        params.push(`%${filters.reference}%`);
      }

      if (filters.lineConnected !== undefined) {
        whereConditions.push('line_user_id IS NOT NULL');
      }

      if (filters.tier) {
        whereConditions.push('tier = ?');
        params.push(filters.tier);
      }

      if (filters.dateFrom) {
        whereConditions.push('created_at >= ?');
        params.push(filters.dateFrom);
      }

      if (filters.dateTo) {
        whereConditions.push('created_at <= ?');
        params.push(filters.dateTo);
      }

      const whereClause = whereConditions.join(' AND ');

      // Get total count
      const countQuery = `SELECT COUNT(*) as total FROM users WHERE ${whereClause}`;
      const countResult: any = await this.prisma.$queryRawUnsafe(countQuery, ...params);
      const total = Number(countResult[0]?.total || 0);

      // Get customers
      const orderByClause = `ORDER BY ${sort} ${order.toUpperCase()}`;
      const query = `
        SELECT 
          id,
          line_account_id as lineAccountId,
          line_user_id as lineUserId,
          display_name as displayName,
          real_name as realName,
          phone,
          email,
          total_orders as totalOrders,
          total_spent as totalSpent,
          available_points as availablePoints,
          tier,
          membership_level as membershipLevel,
          last_order_at as lastOrderAt,
          last_interaction as lastInteractionAt,
          is_blocked as isBlocked,
          created_at as createdAt,
          updated_at as updatedAt
        FROM users 
        WHERE ${whereClause}
        ${orderByClause}
        LIMIT ? OFFSET ?
      `;

      const customers: any[] = await this.prisma.$queryRawUnsafe(
        query,
        ...params,
        limit,
        skip
      );

      return {
        data: customers.map(c => ({
          ...c,
          id: String(c.id),
          totalOrders: Number(c.totalOrders),
          totalSpent: Number(c.totalSpent),
          availablePoints: Number(c.availablePoints),
          isBlocked: Boolean(c.isBlocked),
        })),
        meta: {
          page,
          limit,
          total,
          totalPages: Math.ceil(total / limit),
        },
      };
    } catch (error) {
      this.handleError(error, 'CustomerService.searchCustomers');
    }
  }

  /**
   * Get customer profile details
   * Validates: Requirements FR-3.2
   */
  async getCustomerById(
    customerId: string,
    lineAccountId: string
  ): Promise<CustomerProfile | null> {
    try {
      const query = `
        SELECT 
          id,
          line_account_id as lineAccountId,
          line_user_id as lineUserId,
          display_name as displayName,
          real_name as realName,
          phone,
          email,
          address,
          province,
          district,
          postal_code as postalCode,
          birthday,
          gender,
          notes,
          tags,
          total_orders as totalOrders,
          total_spent as totalSpent,
          available_points as availablePoints,
          tier,
          membership_level as membershipLevel,
          customer_score as customerScore,
          medical_conditions as medicalConditions,
          drug_allergies as drugAllergies,
          current_medications as currentMedications,
          emergency_contact as emergencyContact,
          blood_type as bloodType,
          last_order_at as lastOrderAt,
          last_interaction as lastInteractionAt,
          is_blocked as isBlocked,
          created_at as createdAt,
          updated_at as updatedAt
        FROM users 
        WHERE id = ? AND line_account_id = ?
      `;

      const result: any[] = await this.prisma.$queryRawUnsafe(
        query,
        customerId,
        lineAccountId
      );

      if (result.length === 0) {
        return null;
      }

      const customer = result[0];
      return {
        ...customer,
        id: String(customer.id),
        totalOrders: Number(customer.totalOrders),
        totalSpent: Number(customer.totalSpent),
        availablePoints: Number(customer.availablePoints),
        customerScore: Number(customer.customerScore),
        isBlocked: Boolean(customer.isBlocked),
      };
    } catch (error) {
      this.handleError(error, 'CustomerService.getCustomerById');
    }
  }

  /**
   * Get customer order history
   * Validates: Requirements FR-3.2
   */
  async getCustomerOrders(
    customerId: string,
    lineAccountId: string,
    pagination: PaginationOptions = { page: 1, limit: 20 }
  ): Promise<PaginatedOrders> {
    try {
      const { page, limit, sort = 'createdAt', order = 'desc' } = pagination;
      const skip = (page - 1) * limit;

      // First verify customer exists and belongs to the account
      const customer = await this.getCustomerById(customerId, lineAccountId);
      if (!customer) {
        throw new Error('Customer not found');
      }

      // Get customer reference for matching orders
      const customerRefQuery = `
        SELECT member_id, display_name, real_name 
        FROM users 
        WHERE id = ?
      `;
      const customerData: any[] = await this.prisma.$queryRawUnsafe(
        customerRefQuery,
        customerId
      );

      if (customerData.length === 0) {
        return {
          data: [],
          meta: { page, limit, total: 0, totalPages: 0 },
        };
      }

      const customerRef = customerData[0].member_id;
      const customerName = customerData[0].real_name || customerData[0].display_name;

      // Build where clause for orders
      const whereConditions: string[] = ['line_account_id = ?'];
      const params: any[] = [lineAccountId];

      if (customerRef) {
        whereConditions.push('(customer_ref = ? OR customer_name = ?)');
        params.push(customerRef, customerName);
      } else if (customerName) {
        whereConditions.push('customer_name = ?');
        params.push(customerName);
      } else {
        // No way to match orders
        return {
          data: [],
          meta: { page, limit, total: 0, totalPages: 0 },
        };
      }

      const whereClause = whereConditions.join(' AND ');

      // Get total count
      const countQuery = `SELECT COUNT(*) as total FROM odoo_orders WHERE ${whereClause}`;
      const countResult: any = await this.prisma.$queryRawUnsafe(countQuery, ...params);
      const total = Number(countResult[0]?.total || 0);

      // Get orders
      const orderByClause = `ORDER BY ${sort} ${order.toUpperCase()}`;
      const query = `
        SELECT 
          id,
          odoo_order_id as odooOrderId,
          status,
          total_amount as totalAmount,
          currency,
          order_date as orderDate,
          delivery_date as deliveryDate,
          created_at as createdAt
        FROM odoo_orders 
        WHERE ${whereClause}
        ${orderByClause}
        LIMIT ? OFFSET ?
      `;

      const orders: any[] = await this.prisma.$queryRawUnsafe(
        query,
        ...params,
        limit,
        skip
      );

      return {
        data: orders.map(o => ({
          ...o,
          id: String(o.id),
          totalAmount: Number(o.totalAmount),
        })),
        meta: {
          page,
          limit,
          total,
          totalPages: Math.ceil(total / limit),
        },
      };
    } catch (error) {
      this.handleError(error, 'CustomerService.getCustomerOrders');
    }
  }

  /**
   * Update LINE account connection for customer
   * Validates: Requirements FR-3.3
   */
  async updateLineConnection(
    customerId: string,
    lineAccountId: string,
    lineUserId: string | null
  ): Promise<Customer> {
    try {
      // Verify customer exists
      const customer = await this.getCustomerById(customerId, lineAccountId);
      if (!customer) {
        throw new Error('Customer not found');
      }

      // Update LINE connection
      const updateQuery = `
        UPDATE users 
        SET line_user_id = ?, updated_at = NOW()
        WHERE id = ? AND line_account_id = ?
      `;

      await this.prisma.$executeRawUnsafe(
        updateQuery,
        lineUserId,
        customerId,
        lineAccountId
      );

      // Get updated customer
      const updatedCustomer = await this.getCustomerById(customerId, lineAccountId);
      if (!updatedCustomer) {
        throw new Error('Failed to retrieve updated customer');
      }

      // Create audit log
      await this.prisma.auditLog.create({
        data: {
          userId: 'system',
          action: 'update_line_connection',
          resourceType: 'customer',
          resourceId: customerId,
          oldValues: { lineUserId: customer.lineUserId },
          newValues: { lineUserId },
        },
      });

      return updatedCustomer;
    } catch (error) {
      this.handleError(error, 'CustomerService.updateLineConnection');
    }
  }

  /**
   * Get customer statistics
   */
  async getCustomerStatistics(
    lineAccountId: string,
    dateFrom?: Date,
    dateTo?: Date
  ): Promise<{
    totalCustomers: number;
    newCustomers: number;
    activeCustomers: number;
    lineConnected: number;
    averageOrderValue: number;
    topTiers: Record<string, number>;
  }> {
    try {
      let whereConditions: string[] = ['line_account_id = ?'];
      const params: any[] = [lineAccountId];

      if (dateFrom) {
        whereConditions.push('created_at >= ?');
        params.push(dateFrom);
      }

      if (dateTo) {
        whereConditions.push('created_at <= ?');
        params.push(dateTo);
      }

      const whereClause = whereConditions.join(' AND ');

      // Get statistics
      const statsQuery = `
        SELECT 
          COUNT(*) as totalCustomers,
          SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as newCustomers,
          SUM(CASE WHEN last_interaction >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as activeCustomers,
          SUM(CASE WHEN line_user_id IS NOT NULL THEN 1 ELSE 0 END) as lineConnected,
          AVG(CASE WHEN total_orders > 0 THEN total_spent / total_orders ELSE 0 END) as averageOrderValue
        FROM users 
        WHERE ${whereClause}
      `;

      const statsResult: any[] = await this.prisma.$queryRawUnsafe(statsQuery, ...params);
      const stats = statsResult[0];

      // Get tier breakdown
      const tierQuery = `
        SELECT tier, COUNT(*) as count
        FROM users 
        WHERE ${whereClause} AND tier IS NOT NULL
        GROUP BY tier
      `;

      const tierResult: any[] = await this.prisma.$queryRawUnsafe(tierQuery, ...params);
      const topTiers = tierResult.reduce((acc, row) => {
        acc[row.tier] = Number(row.count);
        return acc;
      }, {} as Record<string, number>);

      return {
        totalCustomers: Number(stats.totalCustomers || 0),
        newCustomers: Number(stats.newCustomers || 0),
        activeCustomers: Number(stats.activeCustomers || 0),
        lineConnected: Number(stats.lineConnected || 0),
        averageOrderValue: Number(stats.averageOrderValue || 0),
        topTiers,
      };
    } catch (error) {
      this.handleError(error, 'CustomerService.getCustomerStatistics');
    }
  }
}
