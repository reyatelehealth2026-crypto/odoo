# Odoo Dashboard Backend API

Modern Node.js backend API for the Odoo Dashboard modernization project, built with TypeScript, Fastify, and Prisma.

## Features

- **TypeScript**: Full type safety across the entire codebase
- **Fastify**: High-performance web framework with built-in validation
- **Prisma**: Type-safe database ORM with MySQL support
- **JWT Authentication**: Secure token-based authentication with refresh tokens
- **Redis Caching**: Distributed caching for improved performance
- **Rate Limiting**: Built-in protection against abuse
- **API Documentation**: Auto-generated Swagger/OpenAPI documentation
- **Clean Architecture**: Separation of concerns with services, controllers, and middleware

## Quick Start

### Prerequisites

- Node.js 18+
- MySQL 8.0+
- Redis (optional, for caching)

### Installation

```bash
# Install dependencies
npm install

# Copy environment variables
cp .env.example .env

# Edit .env with your configuration
# DATABASE_URL, JWT_SECRET, etc.

# Generate Prisma client
npm run prisma:generate

# Run database migrations
npm run prisma:migrate

# Start development server
npm run dev
```

### Environment Variables

Copy `.env.example` to `.env` and configure:

```env
DATABASE_URL="mysql://username:password@localhost:3306/database_name"
JWT_SECRET="your-super-secret-jwt-key-here"
JWT_REFRESH_SECRET="your-super-secret-refresh-key-here"
REDIS_URL="redis://localhost:6379"
PORT=3001
```

## Development

### Available Scripts

```bash
npm run dev          # Start development server with hot reload
npm run build        # Build for production
npm start            # Start production server
npm test             # Run tests
npm run test:coverage # Run tests with coverage
npm run lint         # Lint code
npm run lint:fix     # Fix linting issues
```

### Database Operations

```bash
npm run prisma:generate  # Generate Prisma client
npm run prisma:migrate   # Run database migrations
npm run prisma:studio    # Open Prisma Studio (database GUI)
npm run prisma:seed      # Seed database with test data
```

## API Documentation

When running in development mode, API documentation is available at:
- Swagger UI: http://localhost:3001/docs

### Available API Endpoints

- **[Customer Management API](/docs/API_CUSTOMER_MANAGEMENT.md)** - Search, profile, order history, and LINE connection management
  - `GET /api/v1/customers` - Search customers
  - `GET /api/v1/customers/:id` - Get customer profile
  - `GET /api/v1/customers/:id/orders` - Get order history
  - `PUT /api/v1/customers/:id/line` - Update LINE connection
  - `GET /api/v1/customers/statistics` - Get customer statistics

## Project Structure

```
src/
├── config/          # Configuration files
├── controllers/     # Request handlers (future)
├── middleware/      # Custom middleware
├── routes/          # API route definitions
├── services/        # Business logic services
├── types/           # TypeScript type definitions
├── utils/           # Utility functions
└── test/            # Test setup and utilities
```

## Architecture

The backend follows clean architecture principles:

- **Routes**: Handle HTTP requests and responses
- **Middleware**: Authentication, validation, error handling
- **Services**: Business logic and data processing
- **Prisma**: Database access layer
- **Utils**: Shared utilities and helpers

## Authentication

The API uses JWT-based authentication with refresh tokens:

1. Login with username/password to get access + refresh tokens
2. Include access token in `Authorization: Bearer <token>` header
3. Refresh access token using refresh token when expired
4. Logout to invalidate tokens

## Error Handling

All API responses follow a consistent format:

```json
{
  "success": true,
  "data": { ... },
  "meta": { ... }
}
```

Error responses:

```json
{
  "success": false,
  "error": {
    "code": "ERROR_CODE",
    "message": "Human readable message",
    "details": { ... },
    "timestamp": "2024-01-01T00:00:00.000Z"
  }
}
```

## Testing

The project uses Vitest for testing:

```bash
npm test                 # Run all tests
npm run test:coverage    # Run with coverage report
```

## Deployment

### Production Build

```bash
npm run build
npm start
```

### Docker (Future)

```bash
docker build -t odoo-dashboard-backend .
docker run -p 3001:3001 odoo-dashboard-backend
```

## Contributing

1. Follow TypeScript strict mode guidelines
2. Use Prisma for all database operations
3. Add tests for new features
4. Follow the existing code structure and patterns
5. Update API documentation for new endpoints