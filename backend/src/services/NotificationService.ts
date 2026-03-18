/**
 * Notification Service for Error Alerts and System Monitoring
 * Implements alert notifications for BR-2.2, NFR-2.2
 */

import { ErrorSeverity } from '../types/errors.js';

interface AlertNotification {
  type: 'error_threshold' | 'system_health' | 'performance_degradation';
  severity: ErrorSeverity;
  message: string;
  details?: Record<string, any>;
}

interface NotificationChannel {
  name: string;
  enabled: boolean;
  config: Record<string, any>;
}

export class NotificationService {
  private channels: Map<string, NotificationChannel> = new Map();

  constructor() {
    this.initializeChannels();
  }

  /**
   * Initialize notification channels
   */
  private initializeChannels(): void {
    // Console logging (always enabled for development)
    this.channels.set('console', {
      name: 'Console',
      enabled: true,
      config: {}
    });

    // Email notifications (configure SMTP settings)
    this.channels.set('email', {
      name: 'Email',
      enabled: process.env.SMTP_HOST ? true : false,
      config: {
        host: process.env.SMTP_HOST,
        port: process.env.SMTP_PORT || 587,
        secure: process.env.SMTP_SECURE === 'true',
        auth: {
          user: process.env.SMTP_USER,
          pass: process.env.SMTP_PASS
        },
        recipients: (process.env.ALERT_EMAIL_RECIPIENTS || '').split(',').filter(Boolean)
      }
    });

    // Slack notifications (configure webhook URL)
    this.channels.set('slack', {
      name: 'Slack',
      enabled: process.env.SLACK_WEBHOOK_URL ? true : false,
      config: {
        webhookUrl: process.env.SLACK_WEBHOOK_URL,
        channel: process.env.SLACK_CHANNEL || '#alerts'
      }
    });

    // LINE notifications (for Thai users)
    this.channels.set('line', {
      name: 'LINE',
      enabled: process.env.LINE_NOTIFY_TOKEN ? true : false,
      config: {
        token: process.env.LINE_NOTIFY_TOKEN
      }
    });
  }

  /**
   * Send alert notification to all enabled channels
   */
  async sendAlert(notification: AlertNotification): Promise<void> {
    const promises: Promise<void>[] = [];

    for (const [channelName, channel] of this.channels.entries()) {
      if (channel.enabled && this.shouldSendToChannel(notification.severity, channelName)) {
        promises.push(this.sendToChannel(channelName, notification));
      }
    }

    try {
      await Promise.allSettled(promises);
    } catch (error) {
      console.error('Failed to send notifications:', error);
    }
  }

  /**
   * Determine if notification should be sent to specific channel based on severity
   */
  private shouldSendToChannel(severity: ErrorSeverity, channelName: string): boolean {
    switch (channelName) {
      case 'console':
        return true; // Always log to console
      
      case 'email':
        return severity === ErrorSeverity.HIGH || severity === ErrorSeverity.CRITICAL;
      
      case 'slack':
        return severity === ErrorSeverity.MEDIUM || severity === ErrorSeverity.HIGH || severity === ErrorSeverity.CRITICAL;
      
      case 'line':
        return severity === ErrorSeverity.CRITICAL;
      
      default:
        return false;
    }
  }

  /**
   * Send notification to specific channel
   */
  private async sendToChannel(channelName: string, notification: AlertNotification): Promise<void> {
    const channel = this.channels.get(channelName);
    if (!channel) return;

    try {
      switch (channelName) {
        case 'console':
          await this.sendToConsole(notification);
          break;
        
        case 'email':
          await this.sendToEmail(notification, channel.config);
          break;
        
        case 'slack':
          await this.sendToSlack(notification, channel.config);
          break;
        
        case 'line':
          await this.sendToLine(notification, channel.config);
          break;
      }
    } catch (error) {
      console.error(`Failed to send notification to ${channelName}:`, error);
    }
  }

  /**
   * Send notification to console
   */
  private async sendToConsole(notification: AlertNotification): Promise<void> {
    const emoji = this.getSeverityEmoji(notification.severity);
    const timestamp = new Date().toISOString();
    
    console.log(`\n${emoji} [${notification.severity.toUpperCase()}] ${notification.type.toUpperCase()}`);
    console.log(`Time: ${timestamp}`);
    console.log(`Message: ${notification.message}`);
    
    if (notification.details) {
      console.log('Details:', JSON.stringify(notification.details, null, 2));
    }
    console.log('---');
  }

  /**
   * Send notification via email
   */
  private async sendToEmail(notification: AlertNotification, config: any): Promise<void> {
    if (!config.recipients || config.recipients.length === 0) {
      return;
    }

    // In a real implementation, you would use nodemailer or similar
    // For now, we'll just log the email that would be sent
    const emailContent = {
      to: config.recipients,
      subject: `🚨 ${notification.severity.toUpperCase()} Alert: ${notification.type}`,
      html: `
        <h2>System Alert</h2>
        <p><strong>Severity:</strong> ${notification.severity.toUpperCase()}</p>
        <p><strong>Type:</strong> ${notification.type}</p>
        <p><strong>Message:</strong> ${notification.message}</p>
        <p><strong>Time:</strong> ${new Date().toISOString()}</p>
        ${notification.details ? `<p><strong>Details:</strong><br><pre>${JSON.stringify(notification.details, null, 2)}</pre></p>` : ''}
      `
    };

    console.log('Email notification (would be sent):', emailContent);
  }

  /**
   * Send notification to Slack
   */
  private async sendToSlack(notification: AlertNotification, config: any): Promise<void> {
    if (!config.webhookUrl) return;

    const emoji = this.getSeverityEmoji(notification.severity);
    const color = this.getSeverityColor(notification.severity);

    const payload = {
      channel: config.channel,
      username: 'Dashboard Monitor',
      icon_emoji: ':warning:',
      attachments: [{
        color,
        title: `${emoji} ${notification.severity.toUpperCase()} Alert`,
        fields: [
          {
            title: 'Type',
            value: notification.type,
            short: true
          },
          {
            title: 'Time',
            value: new Date().toISOString(),
            short: true
          },
          {
            title: 'Message',
            value: notification.message,
            short: false
          }
        ],
        footer: 'Dashboard Monitoring System'
      }]
    };

    if (notification.details) {
      payload.attachments[0].fields.push({
        title: 'Details',
        value: `\`\`\`${JSON.stringify(notification.details, null, 2)}\`\`\``,
        short: false
      } as any);
    }

    // In a real implementation, you would make HTTP request to Slack webhook
    console.log('Slack notification (would be sent):', payload);
  }

  /**
   * Send notification to LINE
   */
  private async sendToLine(notification: AlertNotification, config: any): Promise<void> {
    if (!config.token) return;

    const emoji = this.getSeverityEmoji(notification.severity);
    const message = `${emoji} ${notification.severity.toUpperCase()} Alert\n\n` +
                   `Type: ${notification.type}\n` +
                   `Message: ${notification.message}\n` +
                   `Time: ${new Date().toLocaleString('th-TH', { timeZone: 'Asia/Bangkok' })}`;

    // In a real implementation, you would use LINE Notify API
    console.log('LINE notification (would be sent):', { message, token: config.token });
  }

  /**
   * Get emoji for severity level
   */
  private getSeverityEmoji(severity: ErrorSeverity): string {
    switch (severity) {
      case ErrorSeverity.CRITICAL:
        return '🔥';
      case ErrorSeverity.HIGH:
        return '🚨';
      case ErrorSeverity.MEDIUM:
        return '⚠️';
      case ErrorSeverity.LOW:
        return 'ℹ️';
      default:
        return '📢';
    }
  }

  /**
   * Get color for severity level (for Slack)
   */
  private getSeverityColor(severity: ErrorSeverity): string {
    switch (severity) {
      case ErrorSeverity.CRITICAL:
        return '#FF0000'; // Red
      case ErrorSeverity.HIGH:
        return '#FF8C00'; // Orange
      case ErrorSeverity.MEDIUM:
        return '#FFD700'; // Yellow
      case ErrorSeverity.LOW:
        return '#00CED1'; // Blue
      default:
        return '#808080'; // Gray
    }
  }

  /**
   * Test notification channels
   */
  async testNotifications(): Promise<Record<string, boolean>> {
    const results: Record<string, boolean> = {};

    for (const [channelName, channel] of this.channels.entries()) {
      if (channel.enabled) {
        try {
          await this.sendToChannel(channelName, {
            type: 'system_health',
            severity: ErrorSeverity.LOW,
            message: 'Test notification - system is healthy',
            details: {
              test: true,
              timestamp: new Date().toISOString()
            }
          });
          results[channelName] = true;
        } catch (error) {
          results[channelName] = false;
          console.error(`Test failed for ${channelName}:`, error);
        }
      } else {
        results[channelName] = false;
      }
    }

    return results;
  }

  /**
   * Get notification channel status
   */
  getChannelStatus(): Record<string, { enabled: boolean; configured: boolean }> {
    const status: Record<string, { enabled: boolean; configured: boolean }> = {};

    for (const [channelName, channel] of this.channels.entries()) {
      status[channelName] = {
        enabled: channel.enabled,
        configured: this.isChannelConfigured(channelName, channel.config)
      };
    }

    return status;
  }

  /**
   * Check if channel is properly configured
   */
  private isChannelConfigured(channelName: string, config: any): boolean {
    switch (channelName) {
      case 'console':
        return true;
      
      case 'email':
        return !!(config.host && config.auth?.user && config.recipients?.length > 0);
      
      case 'slack':
        return !!config.webhookUrl;
      
      case 'line':
        return !!config.token;
      
      default:
        return false;
    }
  }
}