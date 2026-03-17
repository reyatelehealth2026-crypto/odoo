import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';

export default function Home() {
  return (
    <div className="min-h-screen bg-secondary-50 p-6">
      <div className="mx-auto max-w-7xl">
        <div className="mb-8">
          <h1 className="text-3xl font-bold text-secondary-900">
            Odoo Dashboard Modernization
          </h1>
          <p className="mt-2 text-secondary-600">
            Next.js 14 frontend project initialized successfully
          </p>
        </div>

        <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center space-x-2">
                <span className="h-2 w-2 rounded-full bg-success-500"></span>
                <span>TypeScript</span>
              </CardTitle>
              <CardDescription>
                Strict TypeScript configuration with enhanced type safety
              </CardDescription>
            </CardHeader>
            <CardContent>
              <p className="text-sm text-secondary-600">
                Full type safety with strict mode, path mapping, and
                comprehensive error checking
              </p>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center space-x-2">
                <span className="h-2 w-2 rounded-full bg-success-500"></span>
                <span>Tailwind CSS</span>
              </CardTitle>
              <CardDescription>
                Design system with Thai-friendly color palette
              </CardDescription>
            </CardHeader>
            <CardContent>
              <p className="text-sm text-secondary-600">
                Custom color scheme, animations, and utility classes for the
                dashboard
              </p>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center space-x-2">
                <span className="h-2 w-2 rounded-full bg-success-500"></span>
                <span>Component Library</span>
              </CardTitle>
              <CardDescription>
                Reusable UI components with consistent styling
              </CardDescription>
            </CardHeader>
            <CardContent>
              <p className="text-sm text-secondary-600">
                Button, Card, Input, and Layout components ready for dashboard
                development
              </p>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center space-x-2">
                <span className="h-2 w-2 rounded-full bg-success-500"></span>
                <span>Development Tools</span>
              </CardTitle>
              <CardDescription>
                ESLint, Prettier, and development workflow
              </CardDescription>
            </CardHeader>
            <CardContent>
              <p className="text-sm text-secondary-600">
                Code formatting, linting, and type checking configured for
                optimal DX
              </p>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center space-x-2">
                <span className="h-2 w-2 rounded-full bg-success-500"></span>
                <span>API Integration</span>
              </CardTitle>
              <CardDescription>
                HTTP client with authentication and error handling
              </CardDescription>
            </CardHeader>
            <CardContent>
              <p className="text-sm text-secondary-600">
                Ready for integration with PHP backend APIs and WebSocket
                connections
              </p>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center space-x-2">
                <span className="h-2 w-2 rounded-full bg-success-500"></span>
                <span>Thai Localization</span>
              </CardTitle>
              <CardDescription>
                Thai language support and formatting utilities
              </CardDescription>
            </CardHeader>
            <CardContent>
              <p className="text-sm text-secondary-600">
                Currency formatting, date localization, and Thai phone
                validation
              </p>
            </CardContent>
          </Card>
        </div>

        <div className="mt-8 flex space-x-4">
          <Button variant="primary">เริ่มพัฒนา Dashboard</Button>
          <Button variant="secondary">ดูเอกสาร API</Button>
        </div>

        <div className="mt-8 rounded-lg bg-primary-50 p-6">
          <h2 className="mb-2 text-lg font-semibold text-primary-900">
            Next Steps
          </h2>
          <ul className="space-y-2 text-sm text-primary-800">
            <li>
              • Install dependencies:{' '}
              <code className="rounded bg-primary-100 px-2 py-1">
                npm install
              </code>
            </li>
            <li>
              • Start development server:{' '}
              <code className="rounded bg-primary-100 px-2 py-1">
                npm run dev
              </code>
            </li>
            <li>
              • Run type checking:{' '}
              <code className="rounded bg-primary-100 px-2 py-1">
                npm run type-check
              </code>
            </li>
            <li>
              • Format code:{' '}
              <code className="rounded bg-primary-100 px-2 py-1">
                npm run format
              </code>
            </li>
          </ul>
        </div>
      </div>
    </div>
  );
}
