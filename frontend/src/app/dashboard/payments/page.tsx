import { PaymentProcessingDashboard } from '@/components/payments/PaymentProcessingDashboard';

export default function PaymentsPage() {
  return (
    <div className="container mx-auto px-4 py-8">
      <div className="mb-8">
        <h1 className="text-3xl font-bold text-gray-900">จัดการใบเสร็จการชำระเงิน</h1>
        <p className="text-gray-600 mt-2">
          อัปโหลด ตรวจสอบ และจับคู่ใบเสร็จการชำระเงินกับออเดอร์
        </p>
      </div>
      
      <PaymentProcessingDashboard />
    </div>
  );
}