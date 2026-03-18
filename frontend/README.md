# Odoo Dashboard Frontend

Modern Next.js 14 frontend for the Odoo Dashboard modernization project, part of the LINE Telepharmacy Platform.

## Features

- **Next.js 14** with App Router and TypeScript
- **Tailwind CSS** with custom design system
- **Component Library** with reusable UI components
- **Authentication** with JWT and role-based access control
- **WebSocket Integration** for real-time updates
- **Thai Localization** with proper formatting utilities
- **Development Tools** with ESLint, Prettier, and type checking

## Project Structure

```
src/
├── app/                    # Next.js App Router
│   ├── globals.css        # Global styles
│   ├── layout.tsx         # Root layout
│   └── page.tsx           # Home page
├── components/            # Reusable components
│   ├── ui/               # Basic UI components
│   ├── forms/            # Form components
│   ├── charts/           # Chart components
│   └── layout/           # Layout components
├── lib/                  # Utility libraries
│   ├── api/             # API client
│   ├── auth/            # Authentication
│   └── utils/           # Helper functions
├── hooks/               # Custom React hooks
├── types/               # TypeScript definitions
└── utils/               # Utility functions
```

## Getting Started

### Prerequisites

- Node.js 18+
- npm or yarn

### Installation

1. Install dependencies:

```bash
npm install
```

2. Copy environment variables:

```bash
cp .env.local.example .env.local
```

3. Update environment variables in `.env.local`

### Development

Start the development server:

```bash
npm run dev
```

Open [http://localhost:3000](http://localhost:3000) in your browser.

### Available Scripts

- `npm run dev` - Start development server
- `npm run build` - Build for production
- `npm run start` - Start production server
- `npm run lint` - Run ESLint
- `npm run lint:fix` - Fix ESLint issues
- `npm run type-check` - Run TypeScript type checking
- `npm run format` - Format code with Prettier
- `npm run format:check` - Check code formatting

## Configuration

### TypeScript

Strict TypeScript configuration with:

- Strict mode enabled
- Path mapping for clean imports
- Enhanced type checking options

### Tailwind CSS

Custom design system with:

- Thai-friendly color palette
- Custom animations and utilities
- Responsive design patterns

### ESLint & Prettier

Code quality tools configured with:

- TypeScript support
- Next.js best practices
- Prettier integration
- Tailwind CSS class sorting

## Integration with Backend

This frontend is designed to work with the existing PHP backend:

- **API Endpoints**: Connects to `/api/v1/*` endpoints
- **Authentication**: JWT tokens with role-based access
- **WebSocket**: Real-time updates via Socket.io
- **Multi-tenant**: LINE Account ID scoping

## Development Guidelines

### Component Development

- Use TypeScript for all components
- Follow the established component patterns
- Include proper prop types and documentation
- Use Tailwind CSS for styling

### State Management

- Use React Query for server state
- Use Zustand for client state
- Implement optimistic updates where appropriate

### API Integration

- Use the provided API client
- Handle errors gracefully
- Implement proper loading states
- Cache responses appropriately

### Styling

- Use Tailwind CSS utility classes
- Follow the design system colors
- Ensure responsive design
- Support Thai language content

## Deployment

### Build

```bash
npm run build
```

### Production

The built application can be deployed to any Node.js hosting platform or served statically.

## Contributing

1. Follow the established code style
2. Run type checking and linting before commits
3. Write tests for new components
4. Update documentation as needed

## License

Part of the LINE Telepharmacy Platform project.
