# Implementation Plan: Odoo Webhook Dashboard Improvement

## Overview

This implementation plan refactors the Odoo webhook dashboard from a monolithic 3000+ line API file into a maintainable, performant service-oriented architecture. The plan follows an incremental approach, building and testing each component before integration.

## Tasks

- [x] 1. Code Consolidation and Cleanup
  - Remove duplicate file `api/odoo-dashboard-api.php` (identical to `api/odoo-webhooks-dashboard.php`)
  - Create backup of current implementation before refactoring
  - Document all existing API endpoints and their behaviors for backward compatibility testing
  - _Requirements: 1.2, 1.3, 1.4_

- [x] 2. Database Schema Optimization
  - [x] 2.1 Add virtual generated columns for JSON extraction
    - Add `customer_id` and `order_id` as generated columns on `odoo_webhooks_log` table
    - Create indexes on new generated columns for fast filtering
    - _Requirements: 3.4_
  
  - [x] 2.2 Create customer projection table
    - Create `odoo_customer_projection` table with aggregated customer statistics
    - Add indexes for customer_name, last_order_date, and customer_tier
    - _Requirements: 10.1, 10.3_
  
  - [x] 2.3 Create supporting tables
    - Create `odoo_order_notes` table for manual order annotations
    - Create `odoo_order_status_overrides` table for status override tracking
    - Create `odoo_activity_log` table for audit trail
    - _Requirements: 11.5_

- [x] 3. Implement Core Service Classes
  - [x] 3.1 Create Database wrapper class
    - Implement `classes/Database.php` with PDO prepared statements
    - Add query logging and error handling
    - _Requirements: 6.2_
  
  - [x] 3.2 Create CacheService class
    - Implement `classes/CacheService.php` with Redis support and file fallback
    - Implement cache-aside pattern with `remember()` method
    - Add cache key generation and pattern-based invalidation
    - _Requirements: 4.1, 4.2_
  
  - [ ]* 3.3 Write property test for CacheService
    - **Property 5: Cache Consistency**
    - **Validates: Requirements 4.2, 4.5**
  
  - [x] 3.4 Create RequestValidator class
    - Implement `classes/RequestValidator.php` with validation rules
    - Add type validation, range validation, and pattern matching
    - _Requirements: 6.1_
  
  - [ ]* 3.5 Write property test for RequestValidator
    - **Property 8: Input Validation**
    - **Validates: Requirements 6.1**



- [x] 4. Implement WebhookService
  - [x] 4.1 Create WebhookService class
    - Implement `classes/OdooWebhookService.php` with webhook processing logic
    - Add methods for processWebhook, listWebhooks, getWebhookById
    - Implement retry logic with exponential backoff
    - _Requirements: 7.1, 7.2, 7.4_
  
  - [x] 4.2 Implement Dead Letter Queue management
    - Add sendToDeadLetterQueue method
    - Add retryFailedWebhook method with original payload preservation
    - _Requirements: 7.3, 13.1, 13.4_
  
  - [x] 4.3 Implement customer projection updates
    - Add updateCustomerProjection method to update aggregated stats
    - Ensure atomic updates during webhook processing
    - _Requirements: 7.5, 10.2_
  
  - [x] 4.4 Add Pusher notification broadcasting
    - Integrate Pusher for real-time webhook notifications
    - Implement notification batching for rapid webhook arrivals
    - _Requirements: 14.1, 14.5_
  
  - [ ] 4.5 Write property tests for WebhookService

    - **Property 10: Webhook Acknowledgment Speed**
    - **Property 11: Failed Webhook to DLQ**
    - **Property 12: Customer Projection Update**
    - **Property 13: Webhook Processing Logging**
    - **Property 21: Real-time Notification Broadcast**
    - **Property 22: Notification Batching**
    - **Validates: Requirements 7.1, 7.3, 7.5, 7.6, 14.1, 14.5, 20.5**
  
  - [ ]* 4.6 Write unit tests for retry logic
    - Test exponential backoff timing (1s, 2s, 4s)
    - Test transient vs non-transient error handling
    - _Requirements: 5.5, 7.4_

- [x] 5. Implement CustomerService
  - [x] 5.1 Create CustomerService class
    - Implement `classes/OdooCustomerService.php` with customer data methods
    - Add getCustomerById with cache integration
    - Add searchCustomers and listCustomers with pagination
    - _Requirements: 10.4, 10.6_
  
  - [x] 5.2 Implement customer projection rebuild
    - Add rebuildCustomerProjection for single customer
    - Add rebuildAllProjections for batch processing
    - Implement fallback to webhook log when projection missing
    - _Requirements: 10.4, 10.5_
  
  - [ ]* 5.3 Write property tests for CustomerService
    - **Property 17: Customer Projection Fallback**
    - **Validates: Requirements 10.4**
  
  - [ ]* 5.4 Write unit tests for customer lookup
    - Test cache hit scenario
    - Test cache miss with database fallback
    - Test projection fallback to webhook log
    - _Requirements: 10.4_

- [x] 6. Implement OrderService
  - [x] 6.1 Create OrderService class
    - Implement `classes/OdooOrderService.php` with order management methods
    - Add getOrderById, listOrders with filtering
    - Add getOrdersGroupedByStatus and getTodayOrders
    - _Requirements: 11.1, 11.4_
  
  - [x] 6.2 Implement order timeline aggregation
    - Add getOrderTimeline to aggregate events from multiple webhook types
    - Include order creation, payment, fulfillment, delivery events
    - Add manual notes and status overrides to timeline
    - Sort events chronologically
    - _Requirements: 11.1, 11.2, 11.3, 11.5_
  
  - [x] 6.3 Implement order notes and status overrides
    - Add addOrderNote method with user tracking
    - Add overrideOrderStatus method with audit trail
    - _Requirements: 11.5_
  
  - [ ]* 6.4 Write property tests for OrderService
    - **Property 18: Order Timeline Completeness**
    - **Validates: Requirements 11.1, 11.3**
  
  - [ ]* 6.5 Write unit tests for order timeline
    - Test timeline with multiple event types
    - Test chronological sorting
    - Test inclusion of notes and overrides
    - _Requirements: 11.2, 11.5_

- [x] 7. Implement AnalyticsService
  - [x] 7.1 Create AnalyticsService class
    - Implement `classes/OdooAnalyticsService.php` with analytics methods
    - Add getDashboardStats with 5-minute cache
    - Add getDailySummary with historical data
    - _Requirements: 12.1, 12.4_
  
  - [x] 7.2 Implement trend calculation
    - Add compareToPreviousPeriod method
    - Calculate percentage change and direction (up/down)
    - _Requirements: 12.5_
  
  - [x] 7.3 Implement reporting features
    - Add getSalespersonStats with grouping
    - Add getRevenueByPeriod with date range filtering
    - Add getTopCustomers with limit
    - _Requirements: 12.3_
  
  - [ ]* 7.4 Write property tests for AnalyticsService
    - **Property 19: Trend Calculation**
    - **Validates: Requirements 12.5**
  
  - [ ]* 7.5 Write unit tests for analytics
    - Test daily summary generation
    - Test trend calculation with equal periods
    - Test grouping by salesperson
    - _Requirements: 12.1, 12.3, 12.5_

- [x] 8. Checkpoint - Service Layer Complete
  - Verify all service classes are implemented and tested
  - Run unit tests and property tests for all services
  - Ensure all tests pass before proceeding to API controller
  - Ask the user if questions arise



- [x] 9. Refactor API Controller
  - [x] 9.1 Create DashboardAPIController class
    - Implement `api/odoo-webhooks-dashboard.php` as thin controller
    - Initialize all service dependencies
    - Implement handleRequest() method with action routing
    - Keep controller under 500 lines by delegating to services
    - _Requirements: 2.1, 2.2, 2.5_
  
  - [x] 9.2 Implement authentication and authorization
    - Add authenticate() method for session and API token validation
    - Implement rate limiting with 100 requests per minute
    - _Requirements: 6.4, 6.5_
  
  - [x] 9.3 Implement standardized response formatting
    - Add jsonResponse() method for success responses
    - Add errorResponse() method with standardized error format
    - Include request ID and timestamp in all responses
    - _Requirements: 5.1, 5.2, 5.3_
  
  - [x] 9.4 Implement output sanitization
    - Add OutputSanitizer utility class
    - Escape all string outputs to prevent XSS
    - _Requirements: 6.3_
  
  - [ ]* 9.5 Write property tests for API controller
    - **Property 1: API Backward Compatibility**
    - **Property 2: Service Routing Correctness**
    - **Property 6: Error Response Format**
    - **Property 9: Output Sanitization**
    - **Validates: Requirements 1.3, 2.2, 5.1, 5.2, 5.3, 6.3, 19.1**
  
  - [ ]* 9.6 Write integration tests for API endpoints
    - Test all 30+ API actions for backward compatibility
    - Test authentication and rate limiting
    - Test error responses for various failure scenarios
    - _Requirements: 1.3, 6.4, 6.5_

- [x] 10. Optimize Database Queries
  - [x] 10.1 Eliminate N+1 queries
    - Replace loops with JOIN queries in all services
    - Use eager loading for related data
    - _Requirements: 3.1, 3.2_
  
  - [x] 10.2 Consolidate COUNT queries
    - Combine multiple COUNT queries into single GROUP BY queries
    - Update AnalyticsService to use consolidated queries
    - _Requirements: 3.3_
  
  - [x] 10.3 Add query result caching
    - Integrate CacheService into all data access methods
    - Implement cache warming for dashboard initialization
    - Add cache invalidation on data updates
    - _Requirements: 4.2, 4.5, 4.6_
  
  - [ ]* 10.4 Write performance benchmark tests
    - **Property 3: Query Count Bounded**
    - **Property 4: Response Time Performance**
    - **Validates: Requirements 3.1, 3.5, 3.6, 20.1, 20.2, 20.4**
  
  - [ ]* 10.5 Write unit tests for query optimization
    - Test that list operations execute 3 or fewer queries
    - Test that JOIN queries replace N+1 patterns
    - _Requirements: 3.1, 3.5_

- [ ] 11. Implement API Response Optimization
  - [x] 11.1 Add field selection support
    - Implement fields parameter parsing
    - Filter response data to include only requested fields
    - _Requirements: 9.1_
  
  - [x] 11.2 Implement pagination metadata
    - Add pagination info to all list responses
    - Include current page, total pages, total count, has_next/has_previous
    - _Requirements: 9.6_
  
  - [x] 11.3 Add ISO 8601 timestamp formatting
    - Create DateFormatter utility
    - Convert all timestamps to ISO 8601 format
    - _Requirements: 9.4_
  
  - [x] 11.4 Implement response compression
    - Add gzip compression when client supports it
    - _Requirements: 9.3_
  
  - [ ]* 11.5 Write property tests for API responses
    - **Property 14: Field Selection**
    - **Property 15: ISO 8601 Timestamps**
    - **Property 16: Pagination Metadata**
    - **Validates: Requirements 9.1, 9.4, 9.6**

- [ ] 12. Checkpoint - Backend Complete
  - Run full test suite (unit tests, property tests, integration tests)
  - Verify all API endpoints maintain backward compatibility
  - Test performance benchmarks meet targets (sub-200ms for 95% of requests)
  - Ensure all tests pass before proceeding to frontend
  - Ask the user if questions arise



- [x] 13. Refactor Frontend Architecture
  - [x] 13.1 Create modular JavaScript structure
    - Create `assets/js/odoo-dashboard/` directory structure
    - Separate concerns into utils, components, state, and api-client modules
    - _Requirements: 8.1, 8.3_
  
  - [x] 13.2 Implement API client module
    - Create `api-client.js` with OdooAPIClient class
    - Add client-side caching with 60-second TTL
    - Implement all API methods (getStats, listWebhooks, getCustomer, etc.)
    - _Requirements: 8.1_
  
  - [x] 13.3 Implement state management
    - Create `dashboard-state.js` with DashboardState class
    - Implement subscribe/notify pattern for reactive updates
    - _Requirements: 8.1_
  
  - [x] 13.4 Implement utility modules
    - Create `utils/sanitizer.js` for XSS prevention
    - Create `utils/date-formatter.js` for date formatting
    - Create `utils/currency-formatter.js` for currency display
    - _Requirements: 6.3_
  
  - [x] 13.5 Create dashboard components
    - Create `components/stats-widget.js` for dashboard statistics
    - Create `components/webhook-list.js` for webhook listing
    - Create `components/customer-search.js` for customer lookup
    - Create `components/order-timeline.js` for order timeline display
    - Create `components/analytics-chart.js` for analytics visualization
    - _Requirements: 8.1_

- [x] 14. Implement Real-time Updates
  - [x] 14.1 Create Pusher integration module
    - Implement `DashboardRealtimeUpdates` class
    - Subscribe to 'odoo-webhooks' channel
    - Handle webhook-received, webhook-processed, and stats-updated events
    - _Requirements: 14.1, 14.2_
  
  - [x] 14.2 Implement UI update handlers
    - Add handleNewWebhook to prepend new webhooks to list
    - Add handleWebhookProcessed to update webhook status
    - Add handleStatsUpdate to refresh dashboard statistics
    - _Requirements: 14.4_
  
  - [x] 14.3 Add reconnection logic
    - Implement automatic reconnection on connection loss
    - Sync missed updates after reconnection
    - _Requirements: 14.6_

- [x] 15. Implement Frontend Build Process
  - [x] 15.1 Create PHP-based build script
    - Create `build-dashboard.php` to concatenate JS files
    - Implement simple minification (remove comments and whitespace)
    - Generate `assets/js/odoo-dashboard.min.js`
    - _Requirements: 8.2_
  
  - [x] 15.2 Update HTML to use built bundle
    - Modify `odoo-dashboard.php` to load minified bundle
    - Separate inline styles to `assets/css/odoo-dashboard.css`
    - _Requirements: 8.1_
  
  - [ ]* 15.3 Test frontend build process
    - Verify build script runs without errors
    - Test that minified bundle loads correctly
    - Verify all components function after minification
    - _Requirements: 8.2_

- [x] 16. Implement Monitoring and Logging
  - [x] 16.1 Create structured logging system
    - Implement Logger class with log levels (DEBUG, INFO, WARNING, ERROR, CRITICAL)
    - Add context logging with request ID, user ID, and stack traces
    - _Requirements: 5.2, 5.4_
  
  - [x] 16.2 Add health check endpoint
    - Implement `/reya/health` endpoint for monitoring
    - Return system status, version, and module info
    - _Requirements: 18.1_
  
  - [x] 16.3 Implement activity logging
    - Log all API requests with duration and status code
    - Store activity in `odoo_activity_log` table
    - _Requirements: 18.3_
  
  - [ ]* 16.4 Write property tests for logging
    - **Property 7: Retry with Exponential Backoff**
    - **Property 20: DLQ Retry with Original Payload**
    - **Validates: Requirements 5.5, 7.4, 13.4**

- [-] 17. Implement Configuration Management
  - [-] 17.1 Create configuration loader
    - Load configuration from environment variables
    - Support separate configs for dev, staging, production
    - Validate configuration on startup
    - _Requirements: 17.1, 17.3, 17.5_
  
  - [ ] 17.2 Add configuration validation
    - Fail fast with clear error messages for missing config
    - Validate required fields and data types
    - _Requirements: 17.2_
  
  - [ ]* 17.3 Write unit tests for configuration
    - Test missing configuration detection
    - Test configuration validation
    - _Requirements: 17.2, 17.5_

- [ ] 18. Create Cron Jobs for Maintenance
  - [ ] 18.1 Create customer projection rebuild script
    - Create `cron/rebuild-customer-projections.php`
    - Schedule to run daily for data consistency
    - _Requirements: 10.5_
  
  - [ ] 18.2 Create DLQ cleanup script
    - Create `cron/cleanup-dlq.php`
    - Purge DLQ items older than 30 days
    - Alert when DLQ exceeds 1000 items
    - _Requirements: 13.5, 13.6_
  
  - [ ] 18.3 Create daily summary notification script
    - Create `cron/send-daily-summary.php`
    - Generate and send daily summary via LINE/Telegram
    - _Requirements: 12.1_

- [ ] 19. Final Integration and Testing
  - [ ] 19.1 Integration testing
    - Test complete webhook processing flow from receipt to DLQ
    - Test real-time notifications with Pusher
    - Test frontend updates on webhook arrival
    - _Requirements: 7.1, 7.3, 14.1_
  
  - [ ] 19.2 Performance testing
    - Test 100 concurrent requests without degradation
    - Verify 95% of requests complete in under 200ms
    - Test webhook processing completes in under 500ms
    - _Requirements: 20.2, 20.3, 20.5_
  
  - [ ] 19.3 Backward compatibility testing
    - Test all existing API consumers still work
    - Verify response formats match original implementation
    - Test deprecated endpoints still function
    - _Requirements: 1.3, 19.1_
  
  - [ ]* 19.4 Run complete property test suite
    - Execute all 22 property tests with 100 iterations each
    - Verify all properties pass
    - Document any failures for investigation
    - _Requirements: 15.5_
  
  - [ ]* 19.5 Run complete unit test suite
    - Execute all unit tests for services, validators, and utilities
    - Verify 80%+ code coverage for business logic
    - _Requirements: 15.3_

- [ ] 20. Documentation and Deployment
  - [ ] 20.1 Create API documentation
    - Generate OpenAPI 3.0 specification
    - Document all endpoints with request/response examples
    - Document error codes and authentication
    - _Requirements: 16.1, 16.2, 16.3, 16.4_
  
  - [ ] 20.2 Create deployment guide
    - Document deployment steps for production
    - Include database migration scripts
    - Document cron job setup
    - _Requirements: 17.3_
  
  - [ ] 20.3 Create migration guide
    - Document changes from old to new implementation
    - Provide rollback procedures
    - Document deprecated features
    - _Requirements: 19.4, 19.6_
  
  - [ ] 20.4 Update code documentation
    - Add PHPDoc comments to all classes and methods
    - Add inline comments for complex logic
    - Update README with new architecture
    - _Requirements: 16.2_

- [ ] 21. Final Checkpoint - Deployment Ready
  - All tests passing (unit, property, integration, performance)
  - Documentation complete (API docs, deployment guide, migration guide)
  - Backward compatibility verified with existing consumers
  - Performance benchmarks met (sub-200ms for 95% of requests)
  - Code review completed and approved
  - Ready for production deployment

## Notes

- Tasks marked with `*` are optional test tasks that can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation at major milestones
- Property tests validate universal correctness properties with 100 iterations
- Unit tests validate specific examples and edge cases
- Integration tests verify end-to-end workflows
- Performance tests ensure response time targets are met
