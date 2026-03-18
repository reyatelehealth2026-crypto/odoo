import { PrismaClient, SlipStatus } from '@prisma/client';
import { BaseService } from './BaseService';

export interface MatchingResult {
  success: boolean;
  message: string;
  matchedOrderId?: string;
  confidence?: number;
}

export interface AutoMatchingResult {
  totalProcessed: number;
  successfulMatches: number;
  failedMatches: number;
  ambiguousMatches: number;
  matches: Array<{
    slipId: string;
    orderId: string;
    confidence: number;
  }>;
}

export interface MatchingStatistics {
  totalSlips: number;
  matchedSlips: number;
  pendingSlips: number;
  rejectedSlips: number;
  matchingRate: number;
  averageProcessingTime: number;
}

export class PaymentMatchingService extends BaseService {
  private readonly TOLERANCE_PERCENTAGE = 0.05; // 5% tolerance

  constructor(prisma: PrismaClient) {
    super(prisma);
  }

  /**
   * Find potential order matches for a payment slip amount
   * Uses 5% tolerance for automatic matching
   */
  async findPotentialMatches(
    amount: number,
    lineAccountId: string,
    excludeOrderIds: string[] = []
  ): Promise<Array<{ orderId: string; amount: number; confidence: number }>> {
    try {
      const toleranceAmount = amount * this.TOLERANCE_PERCENTAGE;
      const minAmount = amount - toleranceAmount;
      const maxAmount = amount + toleranceAmount;

      const orders = await this.prisma.odooOrder.findMany({
        where: {
          lineAccountId,
          status: 'pending',
          totalAmount: {
            gte: minAmount,
            lte: maxAmount,
          },
          id: {
            notIn: excludeOrderIds,
          },
        },
        select: {
          id: true,
          totalAmount: true,
          orderDate: true,
        },
        orderBy: {
          orderDate: 'desc',
        },
      });

      return orders.map(order => {
        const orderAmount = Number(order.totalAmount);
        const difference = Math.abs(orderAmount - amount);
        const percentageDiff = difference / amount;
        const confidence = Math.max(0, 1 - (percentageDiff / this.TOLERANCE_PERCENTAGE));

        return {
          orderId: order.id,
          amount: orderAmount,
          confidence: Math.round(confidence * 100) / 100,
        };
      }).sort((a, b) => b.confidence - a.confidence);
    } catch (error) {
      this.handleError(error, 'PaymentMatchingService.findPotentialMatches');
    }
  }

  /**
   * Manually match a payment slip to a specific order
   */
  async matchPaymentSlip(
    slipId: string,
    orderId: string,
    lineAccountId: string
  ): Promise<MatchingResult> {
    try {
      // Validate line account access
      this.validateLineAccountAccess(lineAccountId);

      // Check if slip exists and is pending
      const slip = await this.prisma.odooSlipUpload.findFirst({
        where: {
          id: slipId,
          lineAccountId,
        },
      });

      if (!slip) {
        return {
          success: false,
          message: 'Payment slip not found',
        };
      }

      if (slip.status !== SlipStatus.PENDING) {
        return {
          success: false,
          message: 'Payment slip is already processed',
        };
      }

      // Check if order exists and is pending
      const order = await this.prisma.odooOrder.findFirst({
        where: {
          id: orderId,
          lineAccountId,
          status: 'pending',
        },
      });

      if (!order) {
        return {
          success: false,
          message: 'Order not found or not available for matching',
        };
      }

      // Perform the match
      await this.prisma.$transaction(async (tx) => {
        // Update slip status
        await tx.odooSlipUpload.update({
          where: { id: slipId },
          data: {
            status: SlipStatus.MATCHED,
            matchedOrderId: orderId,
            processedAt: new Date(),
          },
        });

        // Update order status to processing
        await tx.odooOrder.update({
          where: { id: orderId },
          data: {
            status: 'processing',
            updatedAt: new Date(),
          },
        });
      });

      return {
        success: true,
        message: 'Payment slip matched successfully',
        matchedOrderId: orderId,
      };
    } catch (error) {
      this.handleError(error, 'PaymentMatchingService.matchPaymentSlip');
    }
  }

  /**
   * Perform automatic matching for all pending payment slips
   * Only matches when there's a single, high-confidence match
   */
  async performAutomaticMatching(lineAccountId: string): Promise<AutoMatchingResult> {
    try {
      this.validateLineAccountAccess(lineAccountId);

      const pendingSlips = await this.prisma.odooSlipUpload.findMany({
        where: {
          lineAccountId,
          status: SlipStatus.PENDING,
          amount: { not: null },
        },
        select: {
          id: true,
          amount: true,
        },
      });

      const result: AutoMatchingResult = {
        totalProcessed: pendingSlips.length,
        successfulMatches: 0,
        failedMatches: 0,
        ambiguousMatches: 0,
        matches: [],
      };

      for (const slip of pendingSlips) {
        if (!slip.amount) continue;

        const potentialMatches = await this.findPotentialMatches(
          Number(slip.amount),
          lineAccountId
        );

        // Only auto-match if there's exactly one high-confidence match
        if (potentialMatches.length === 1 && potentialMatches[0]!.confidence >= 0.95) {
          const matchResult = await this.matchPaymentSlip(
            slip.id,
            potentialMatches[0]!.orderId,
            lineAccountId
          );

          if (matchResult.success) {
            result.successfulMatches++;
            result.matches.push({
              slipId: slip.id,
              orderId: potentialMatches[0]!.orderId,
              confidence: potentialMatches[0]!.confidence,
            });
          } else {
            result.failedMatches++;
          }
        } else if (potentialMatches.length > 1) {
          result.ambiguousMatches++;
        } else {
          result.failedMatches++;
        }
      }

      return result;
    } catch (error) {
      this.handleError(error, 'PaymentMatchingService.performAutomaticMatching');
    }
  }

  /**
   * Get matching statistics for the dashboard
   */
  async getMatchingStatistics(
    lineAccountId: string,
    dateFrom?: Date,
    dateTo?: Date
  ): Promise<MatchingStatistics> {
    try {
      this.validateLineAccountAccess(lineAccountId);

      const whereClause: any = { lineAccountId };
      
      if (dateFrom || dateTo) {
        whereClause.createdAt = {};
        if (dateFrom) whereClause.createdAt.gte = dateFrom;
        if (dateTo) whereClause.createdAt.lte = dateTo;
      }

      const [totalSlips, matchedSlips, pendingSlips, rejectedSlips] = await Promise.all([
        this.prisma.odooSlipUpload.count({ where: whereClause }),
        this.prisma.odooSlipUpload.count({
          where: { ...whereClause, status: SlipStatus.MATCHED },
        }),
        this.prisma.odooSlipUpload.count({
          where: { ...whereClause, status: SlipStatus.PENDING },
        }),
        this.prisma.odooSlipUpload.count({
          where: { ...whereClause, status: SlipStatus.REJECTED },
        }),
      ]);

      // Calculate average processing time for matched slips
      const processedSlips = await this.prisma.odooSlipUpload.findMany({
        where: {
          ...whereClause,
          status: SlipStatus.MATCHED,
          processedAt: { not: null },
        },
        select: {
          createdAt: true,
          processedAt: true,
        },
      });

      let averageProcessingTime = 0;
      if (processedSlips.length > 0) {
        const totalProcessingTime = processedSlips.reduce((sum, slip) => {
          if (slip.processedAt) {
            return sum + (slip.processedAt.getTime() - slip.createdAt.getTime());
          }
          return sum;
        }, 0);
        averageProcessingTime = Math.round(totalProcessingTime / processedSlips.length / 1000 / 60); // minutes
      }

      const matchingRate = totalSlips > 0 ? (matchedSlips / totalSlips) * 100 : 0;

      return {
        totalSlips,
        matchedSlips,
        pendingSlips,
        rejectedSlips,
        matchingRate: Math.round(matchingRate * 100) / 100,
        averageProcessingTime,
      };
    } catch (error) {
      this.handleError(error, 'PaymentMatchingService.getMatchingStatistics');
    }
  }

  /**
   * Reject a payment slip (mark as invalid)
   */
  async rejectPaymentSlip(
    slipId: string,
    lineAccountId: string,
    reason?: string
  ): Promise<MatchingResult> {
    try {
      this.validateLineAccountAccess(lineAccountId);

      const slip = await this.prisma.odooSlipUpload.findFirst({
        where: {
          id: slipId,
          lineAccountId,
          status: SlipStatus.PENDING,
        },
      });

      if (!slip) {
        return {
          success: false,
          message: 'Payment slip not found or already processed',
        };
      }

      await this.prisma.odooSlipUpload.update({
        where: { id: slipId },
        data: {
          status: SlipStatus.REJECTED,
          processedAt: new Date(),
          notes: reason || 'Rejected by user',
        },
      });

      return {
        success: true,
        message: 'Payment slip rejected successfully',
      };
    } catch (error) {
      this.handleError(error, 'PaymentMatchingService.rejectPaymentSlip');
    }
  }
}