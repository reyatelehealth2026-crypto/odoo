# Customer Management API Documentation

## Overview

The Customer Management API provides endpoints for searching, viewing, and managing customer profiles in the LINE Telepharmacy Platform. This API is part of the Odoo Dashboard modernization project (Task 10.2).

**Base URL**: `/api/v1/customers`

**Authentication**: All endpoints require JWT authentication via `Authorization: Bearer <token>` header.

**Requirements Validated**:
- FR-3.1: Customer search and filtering
- FR-3.2: Customer profile and order history
- FR-3.3: LINE account connection management

---

## Endpoints

### 1. Search Customers

Search and filter customers with pagination support.

**Endpoint**: `GET /api/v1/customers`

**Query Parameters**:

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `page` | number | No | 1 | Page number (minimum: 1) |
| `limit` | number | No | 20 | Records per page (max: 100) |
| `sort` | string | No | updatedAt | Sort field |
| `order` | string | No | desc | Sort order (asc/desc) |
| `search` | string | No | - | Search across name, phone, email, member ID |
| `name` | string | No | - | Filter by customer name |
| `reference` | string | No | - | Filter by member ID/reference |
| `partnerId` | string | No | - | Filter by Odoo Partner ID |
| `lineConnected` | boolean | No | - | Filter by LINE connection status |
| `tier` | string | No | - | Filter by membership tier |
| `dateFrom` | string | No | - | Filter by creation date (ISO 8601) |
| `dateTo` | string | No | - | Filter by creation date (ISO 8601) |
| `lineAccountId` | string | No | - | LINE account scope |

**Response**: `200 OK`

```json
{
  "success": true,
  "data": {
    "data": [
      {
        "id": "123",
        "lineAccountId": "1",
        "lineUserId": "U1234567890abcdef",
        "displayName": "สมชาย ใจดี",
        "realName": "นายสมชาย ใจดี",
        "phone": "0812345678",
        "email": "somchai@example.com",
        "totalOrders": 15,
        "totalSpent": 45000.00,
        "availablePoints": 450,
        "tier": "gold",
        "membershipLevel": "premium",
        "lastOrderAt": "2024-01-15T10:30:00.000Z",
        "lastInteractionAt": "2024-01-20T14:22:00.000Z",
        "isBlocked": false,
        "createdAt": "2023-06-01T08:00:00.000Z",
        "updatedAt": "2024-01-20T14:22:00.000Z"
      }
    ],
    "meta": {
      "page": 1,
      "limit": 20,
      "total": 1247,
      "totalPages": 63
    }
  }
}
```

**Example Requests**:

```bash
# Search by name
curl -H "Authorization: Bearer <token>" \
  "https://api.example.com/api/v1/customers?search=สมชาย"

# Filter by tier and LINE connection
curl -H "Authorization: Bearer <token>" \
  "https://api.example.com/api/v1/customers?tier=gold&lineConnected=true"

# Date range filter
curl -H "Authorization: Bearer <token>" \
  "https://api.example.com/api/v1/customers?dateFrom=2024-01-01&dateTo=2024-01-31"
```

---

### 2. Get Customer Profile

Retrieve detailed customer profile including credit info, points, and medical data.

**Endpoint**: `GET /api/v1/customers/:id`

**Path Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | string | Yes | Customer ID |

**Response**: `200 OK`

```json
{
  "success": true,
  "data": {
    "id": "123",
    "lineAccountId": "1",
    "lineUserId": "U1234567890abcdef",
    "displayName": "สมชาย ใจดี",
    "realName": "นายสมชาย ใจดี",
    "phone": "0812345678",
    "email": "somchai@example.com",
    "address": "123 ถนนสุขุมวิท",
    "province": "กรุงเทพมหานคร",
    "district": "วัฒนา",
    "postalCode": "10110",
    "birthday": "1985-05-15",
    "gender": "male",
    "notes": "ลูกค้าประจำ VIP",
    "tags": "vip,regular",
    "totalOrders": 15,
    "totalSpent": 45000.00,
    "availablePoints": 450,
    "tier": "gold",
    "membershipLevel": "premium",
    "customerScore": 85,
    "medicalConditions": "โรคความดันโลหิตสูง",
    "drugAllergies": "Penicillin",
    "currentMedications": "Amlodipine 5mg",
    "emergencyContact": "0898765432 (คุณสมหญิง)",
    "bloodType": "O+",
    "lastOrderAt": "2024-01-15T10:30:00.000Z",
    "lastInteractionAt": "2024-01-20T14:22:00.000Z",
    "isBlocked": false,
    "createdAt": "2023-06-01T08:00:00.000Z",
    "updatedAt": "2024-01-20T14:22:00.000Z"
  }
}
```

**Error Response**: `404 Not Found`

```json
{
  "success": false,
  "error": {
    "code": "CUSTOMER_NOT_FOUND",
    "message": "Customer not found"
  }
}
```

**Example Request**:

```bash
curl -H "Authorization: Bearer <token>" \
  "https://api.example.com/api/v1/customers/123"
```

---

### 3. Get Customer Order History

Retrieve paginated order history for a specific customer.

**Endpoint**: `GET /api/v1/customers/:id/orders`

**Path Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | string | Yes | Customer ID |

**Query Parameters**:

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `page` | number | No | 1 | Page number (minimum: 1) |
| `limit` | number | No | 20 | Records per page (max: 100) |
| `sort` | string | No | createdAt | Sort field |
| `order` | string | No | desc | Sort order (asc/desc) |

**Response**: `200 OK`

```json
{
  "success": true,
  "data": {
    "data": [
      {
        "id": "456",
        "odooOrderId": "SO-2024-001",
        "status": "sale",
        "totalAmount": 3500.00,
        "currency": "THB",
        "orderDate": "2024-01-15T10:30:00.000Z",
        "deliveryDate": "2024-01-17T14:00:00.000Z",
        "createdAt": "2024-01-15T10:30:00.000Z"
      }
    ],
    "meta": {
      "page": 1,
      "limit": 20,
      "total": 15,
      "totalPages": 1
    }
  }
}
```

**Error Response**: `404 Not Found`

```json
{
  "success": false,
  "error": {
    "code": "CUSTOMER_NOT_FOUND",
    "message": "Customer not found"
  }
}
```

**Example Request**:

```bash
curl -H "Authorization: Bearer <token>" \
  "https://api.example.com/api/v1/customers/123/orders?page=1&limit=10"
```

---

### 4. Update LINE Account Connection

Link or unlink a LINE user ID to a customer profile.

**Endpoint**: `PUT /api/v1/customers/:id/line`

**Path Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | string | Yes | Customer ID |

**Request Body**:

```json
{
  "lineUserId": "U1234567890abcdef"
}
```

To disconnect LINE account, set `lineUserId` to `null`:

```json
{
  "lineUserId": null
}
```

**Response**: `200 OK`

```json
{
  "success": true,
  "data": {
    "id": "123",
    "lineUserId": "U1234567890abcdef",
    "displayName": "สมชาย ใจดี",
    "updatedAt": "2024-01-20T15:30:00.000Z"
  }
}
```

**Error Response**: `404 Not Found`

```json
{
  "success": false,
  "error": {
    "code": "CUSTOMER_NOT_FOUND",
    "message": "Customer not found"
  }
}
```

**Example Requests**:

```bash
# Link LINE account
curl -X PUT \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"lineUserId":"U1234567890abcdef"}' \
  "https://api.example.com/api/v1/customers/123/line"

# Unlink LINE account
curl -X PUT \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"lineUserId":null}' \
  "https://api.example.com/api/v1/customers/123/line"
```

**Real-time Updates**: This endpoint broadcasts WebSocket updates to connected clients when a LINE connection is modified.

---

### 5. Get Customer Statistics

Get customer statistics for dashboard display.

**Endpoint**: `GET /api/v1/customers/statistics`

**Query Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `dateFrom` | string | No | Filter by date (ISO 8601) |
| `dateTo` | string | No | Filter by date (ISO 8601) |
| `lineAccountId` | string | No | LINE account scope |

**Response**: `200 OK`

```json
{
  "success": true,
  "data": {
    "totalCustomers": 1247,
    "newCustomers": 23,
    "activeCustomers": 189,
    "lineConnected": 1089,
    "averageOrderValue": 2850.50,
    "topTiers": {
      "gold": 145,
      "silver": 320,
      "bronze": 782
    }
  }
}
```

**Example Request**:

```bash
curl -H "Authorization: Bearer <token>" \
  "https://api.example.com/api/v1/customers/statistics?dateFrom=2024-01-01&dateTo=2024-01-31"
```

---

## Error Codes

| Code | HTTP Status | Description |
|------|-------------|-------------|
| `CUSTOMER_NOT_FOUND` | 404 | Customer does not exist or doesn't belong to the account |
| `CUSTOMER_SEARCH_ERROR` | 500 | Failed to search customers |
| `CUSTOMER_PROFILE_ERROR` | 500 | Failed to retrieve customer profile |
| `CUSTOMER_ORDERS_ERROR` | 500 | Failed to retrieve customer orders |
| `LINE_CONNECTION_UPDATE_ERROR` | 500 | Failed to update LINE connection |
| `CUSTOMER_STATISTICS_ERROR` | 500 | Failed to retrieve customer statistics |
| `UNAUTHORIZED` | 401 | Missing or invalid authentication token |
| `FORBIDDEN` | 403 | Insufficient permissions |

---

## Authentication

All endpoints require a valid JWT access token in the `Authorization` header:

```
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

To obtain an access token, use the authentication endpoint:

```bash
POST /api/v1/auth/login
Content-Type: application/json

{
  "username": "admin",
  "password": "password123",
  "lineAccountId": "1"
}
```

---

## Rate Limiting

API endpoints are rate-limited to prevent abuse:

- **General endpoints**: 30 requests per second per IP
- **Search endpoints**: 10 requests per second per IP

Rate limit headers are included in responses:

```
X-RateLimit-Limit: 30
X-RateLimit-Remaining: 25
X-RateLimit-Reset: 1640000000
```

---

## Pagination

All list endpoints support pagination with the following parameters:

- `page`: Page number (default: 1, minimum: 1)
- `limit`: Records per page (default: 20, maximum: 100)
- `sort`: Sort field (default varies by endpoint)
- `order`: Sort order - `asc` or `desc` (default: `desc`)

Pagination metadata is included in the response:

```json
{
  "meta": {
    "page": 1,
    "limit": 20,
    "total": 1247,
    "totalPages": 63
  }
}
```

---

## Data Types

### Customer Object

| Field | Type | Description |
|-------|------|-------------|
| `id` | string | Unique customer identifier |
| `lineAccountId` | string | LINE account scope |
| `lineUserId` | string \| null | LINE user ID (if connected) |
| `displayName` | string \| null | Display name from LINE |
| `realName` | string \| null | Real name |
| `phone` | string \| null | Phone number |
| `email` | string \| null | Email address |
| `totalOrders` | number | Total number of orders |
| `totalSpent` | number | Total amount spent (THB) |
| `availablePoints` | number | Available loyalty points |
| `tier` | string \| null | Membership tier (bronze/silver/gold) |
| `membershipLevel` | string \| null | Membership level |
| `lastOrderAt` | string \| null | Last order timestamp (ISO 8601) |
| `lastInteractionAt` | string \| null | Last interaction timestamp (ISO 8601) |
| `isBlocked` | boolean | Whether customer is blocked |
| `createdAt` | string | Creation timestamp (ISO 8601) |
| `updatedAt` | string | Last update timestamp (ISO 8601) |

### CustomerProfile Object

Extends Customer with additional fields:

| Field | Type | Description |
|-------|------|-------------|
| `address` | string \| null | Street address |
| `province` | string \| null | Province |
| `district` | string \| null | District |
| `postalCode` | string \| null | Postal code |
| `birthday` | string \| null | Birthday (ISO 8601 date) |
| `gender` | string \| null | Gender (male/female/other) |
| `notes` | string \| null | Internal notes |
| `tags` | string \| null | Customer tags (comma-separated) |
| `customerScore` | number | Customer score (0-100) |
| `medicalConditions` | string \| null | Medical conditions |
| `drugAllergies` | string \| null | Drug allergies |
| `currentMedications` | string \| null | Current medications |
| `emergencyContact` | string \| null | Emergency contact |
| `bloodType` | string \| null | Blood type |

### Order Object

| Field | Type | Description |
|-------|------|-------------|
| `id` | string | Unique order identifier |
| `odooOrderId` | string | Odoo order reference |
| `status` | string | Order status (draft/sale/done/cancel) |
| `totalAmount` | number | Total order amount (THB) |
| `currency` | string | Currency code (THB) |
| `orderDate` | string \| null | Order date (ISO 8601) |
| `deliveryDate` | string \| null | Delivery date (ISO 8601) |
| `createdAt` | string | Creation timestamp (ISO 8601) |

---

## Integration Examples

### React/TypeScript

```typescript
import axios from 'axios';

const API_BASE_URL = 'https://api.example.com/api/v1';

// Search customers
async function searchCustomers(search: string, page: number = 1) {
  const response = await axios.get(`${API_BASE_URL}/customers`, {
    params: { search, page, limit: 20 },
    headers: {
      Authorization: `Bearer ${getAccessToken()}`,
    },
  });
  return response.data;
}

// Get customer profile
async function getCustomerProfile(customerId: string) {
  const response = await axios.get(`${API_BASE_URL}/customers/${customerId}`, {
    headers: {
      Authorization: `Bearer ${getAccessToken()}`,
    },
  });
  return response.data;
}

// Update LINE connection
async function updateLineConnection(customerId: string, lineUserId: string | null) {
  const response = await axios.put(
    `${API_BASE_URL}/customers/${customerId}/line`,
    { lineUserId },
    {
      headers: {
        Authorization: `Bearer ${getAccessToken()}`,
        'Content-Type': 'application/json',
      },
    }
  );
  return response.data;
}
```

### PHP

```php
<?php
$apiBaseUrl = 'https://api.example.com/api/v1';
$accessToken = getAccessToken();

// Search customers
function searchCustomers($search, $page = 1) {
    global $apiBaseUrl, $accessToken;
    
    $url = $apiBaseUrl . '/customers?' . http_build_query([
        'search' => $search,
        'page' => $page,
        'limit' => 20,
    ]);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Get customer profile
function getCustomerProfile($customerId) {
    global $apiBaseUrl, $accessToken;
    
    $url = $apiBaseUrl . '/customers/' . $customerId;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}
?>
```

---

## Testing

### Unit Tests

Customer API endpoints are covered by comprehensive unit tests:

```bash
npm run test backend/src/test/routes/customers.test.ts
npm run test backend/src/test/services/CustomerService.test.ts
```

### Integration Tests

Full integration tests are available in the system test suite:

```bash
npm run test:system
```

---

## Support

For issues or questions about the Customer Management API:

1. Check the [main documentation](../README.md)
2. Review the [Odoo Dashboard Modernization spec](../.kiro/specs/odoo-dashboard-modernization/)
3. Contact the development team

---

**Last Updated**: 2024-01-20  
**API Version**: 1.0.0  
**Spec Reference**: Task 10.2 - Customer Management API Endpoints
