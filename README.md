# Email Notification Extension for Glueful

## Overview

The EmailNotification extension provides a modern email delivery system for the Glueful Framework's notification system. Built on **Symfony Mailer**, it features robust multi-provider support, failover, and an extensible transport architecture.

> Built on Symfony Mailer with modern provider-bridge support. Implements the framework's notification channel contract (`RichNotificationChannel`) with structured `NotificationResult`s.

> Deprecation notice
>
> - `manifest.json` is deprecated and removed in favor of Composer discovery (`extra.glueful.provider`).
> - The top-level `EmailNotification` class has been removed; use the ServiceProvider-driven registration. Code that previously referenced `Glueful\\Extensions\\EmailNotification` should instead use the framework's notification system and/or resolve `EmailNotificationProvider` via DI.

## Features

- ✅ **Modern Symfony Mailer Integration** - Enterprise-grade email infrastructure
- ✅ **Multi-Provider Support** - Brevo, SendGrid, Mailgun, Amazon SES, Postmark, and custom providers
- ✅ **Provider Bridges** - Native API integrations for optimal performance and reliability
- ✅ **Failover** - Multiple transport support with automatic failover
- ✅ **Extensible Architecture** - Support for any Symfony Mailer provider bridge
- ✅ **Advanced Template System** - Responsive templates with auto-escaped variable substitution and conditional logic
- ✅ **Recipient Domain Policy** - Allow-list / block-list enforcement on every recipient (primary, cc, bcc) before send
- ✅ **Attachment Path Confinement** - Attachment/embed paths confined to allowed directories
- ✅ **Developer Experience** - Clear error messages, debugging tools, and type safety

## Requirements

- PHP 8.3 or higher with strict typing
- Glueful Framework 1.51.0 or higher
- OpenSSL PHP extension
- Symfony Mailer (included)
- Composer for provider bridge dependencies

## Installation

### Install via Composer (Recommended)

```bash
composer require glueful/email-notification
```

### Enabling the extension

Installing the package does **not** auto-load it — its provider must be in
`config/extensions.php`'s `enabled` allow-list.

**Development (recommended):** the CLI edits `config/extensions.php` and recompiles the
cache for you (it validates the change first, so it won't leave the config broken):

```bash
php glueful extensions:enable email-notification

# To disable (removes the provider from `enabled`):
php glueful extensions:disable email-notification
```

**By hand / in production:** add the provider as a plain string FQCN (no `::class`) to
the `enabled` list, then build the manifest in your deploy step:

```php
// config/extensions.php
return [
    'enabled' => [
        'Glueful\\Extensions\\EmailNotification\\EmailNotificationServiceProvider',
        // other providers...
    ],
];
```

```bash
php glueful extensions:cache   # required in production; boot fails fast without it
```

Verify discovery and state:

```bash
php glueful extensions:list
php glueful extensions:info email-notification
```

Note: `enable`/`disable` are dev-only conveniences and are disabled in production — there,
manage the `enabled` list in config and run `extensions:cache`.

### Provider Bridge Installation

Install required Symfony provider bridges based on your email providers:

```bash
# For Brevo (Sendinblue)
composer require symfony/brevo-mailer

# For SendGrid
composer require symfony/sendgrid-mailer

# For Mailgun
composer require symfony/mailgun-mailer

# For Amazon SES
composer require symfony/amazon-mailer

# For Postmark
composer require symfony/postmark-mailer
```

## Configuration

### Environment Variables

Configure your email providers in your `.env` file:

```env
# Email Provider Selection
MAIL_MAILER=brevo

# Brevo Configuration (Sendinblue)
BREVO_TRANSPORT=brevo+smtp         # or brevo+api
BREVO_API_KEY=your-brevo-api-key
MAIL_USERNAME=your-smtp-username
MAIL_PASSWORD=your-smtp-password

# Generic SMTP Configuration
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls               # tls, ssl, or none
MAIL_USERNAME=your-email@example.com
MAIL_PASSWORD=your-app-password

# Email Addresses
MAIL_FROM=noreply@example.com
MAIL_FROM_NAME=Your Application

# Extension behavior
MAIL_DEBUG=false              # debug-log each outgoing email (recipient/subject/type only)
MAIL_LOG_RESULTS=false        # info/error-log each send outcome (recipient/subject/type only)

# Recipient domain policy (optional; see Security Features)
# MAIL_ALLOWED_DOMAINS=yourcompany.com,partner.com
# MAIL_BLOCKED_DOMAINS=example.com,spam.test
```

### Services Configuration

Configure multiple email providers in `config/services.php`:

```php
return [
    'mail' => [
        'default' => env('MAIL_MAILER', 'smtp'),
        
        'mailers' => [
            // Generic SMTP
            'smtp' => [
                'transport' => 'smtp',
                'host' => env('MAIL_HOST'),
                'port' => env('MAIL_PORT', 587),
                'encryption' => env('MAIL_ENCRYPTION', 'tls'),
                'username' => env('MAIL_USERNAME'),
                'password' => env('MAIL_PASSWORD'),
            ],
            
            // Brevo (Sendinblue) - API or SMTP
            'brevo' => [
                'transport' => env('BREVO_TRANSPORT', 'brevo+api'),
                'key' => env('BREVO_API_KEY'),
                'username' => env('MAIL_USERNAME'),
                'password' => env('MAIL_PASSWORD'),
                'dsn' => env('BREVO_DSN'), // Override for custom DSN
            ],
            
            // SendGrid
            'sendgrid' => [
                'transport' => 'sendgrid+api',
                'key' => env('SENDGRID_API_KEY'),
            ],
            
            // Mailgun
            'mailgun' => [
                'transport' => 'mailgun+api',
                'domain' => env('MAILGUN_DOMAIN'),
                'key' => env('MAILGUN_SECRET'),
                'region' => env('MAILGUN_REGION', 'us'),
            ],
            
            // Amazon SES
            'ses' => [
                'transport' => 'ses+api',
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
                'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            ],
            
            // Postmark
            'postmark' => [
                'transport' => 'postmark+api',
                'token' => env('POSTMARK_TOKEN'),
            ],
        ],
        
        'from' => [
            'address' => env('MAIL_FROM', 'noreply@example.com'),
            'name' => env('MAIL_FROM_NAME', 'Glueful Application'),
        ],
        
        // Failover configuration
        'failover' => [
            'mailers' => explode(',', env('MAIL_FAILOVER_MAILERS', '')),
        ],
    ],
];
```

### Custom Provider Support

The extension supports any Symfony Mailer provider bridge through three methods:

#### 1. Custom DSN (Most Flexible)
```php
'custom_provider' => [
    'transport' => 'custom',
    'dsn' => 'mandrill+api://your-api-key@default',
],
```

#### 2. Auto-Configuration (Standard Patterns)
```php
'office365' => [
    'transport' => 'office365+smtp',
    'username' => 'user@company.com',
    'password' => 'password',
    // Auto-builds: office365+smtp://user:password@default
],
```

#### 3. Explicit Support (Built-in)
Already supported providers work without additional configuration.

## Usage

### Basic Email Sending

The extension integrates seamlessly with Glueful's notification system:

```php
use Glueful\Notifications\NotificationService;

$notificationService = container()->get(NotificationService::class);

// Simple email notification
$notificationService->send(
    'account_activity',
    $user,
    'Account Login Alert',
    [
        'message' => 'Your account was accessed from a new device.',
        'location' => 'San Francisco, CA',
        'device' => 'iPhone 13',
        'timestamp' => '2024-06-21 14:30:00',
    ],
    ['channels' => ['email']]
);
```

### Template-Based Emails

Use professional templates for rich email experiences:

```php
// Email verification with OTP using verification template
$result = $notificationService->send(
    'email_verification',
    $notifiable,
    'Verify your email address',
    [
        'otp' => '123456',
        'expiry_minutes' => 15,
        'template_name' => 'verification'
    ],
    ['channels' => ['email']]
);

// Password reset with OTP using password-reset template
$result = $notificationService->send(
    'password_reset',
    $notifiable,
    'Password Reset Code',
    [
        'name' => $user->getFirstName(),
        'otp' => '654321',
        'expiry_minutes' => 15,
        'template_name' => 'password-reset'
    ],
    ['channels' => ['email']]
);

// Welcome email using welcome template
$result = $notificationService->send(
    'user_welcome',
    $user,
    'Welcome to Our Platform',
    [
        'user_name' => $user->getName(),
        'welcome_message' => 'Thank you for joining us!',
        'get_started_url' => 'https://example.com/onboarding',
        'template_name' => 'welcome'
    ],
    ['channels' => ['email']]
);

// Alert notification using alert template
$result = $notificationService->send(
    'security_alert',
    $user,
    'Security Alert',
    [
        'alert_type' => 'login_from_new_device',
        'message' => 'Your account was accessed from a new device.',
        'location' => 'San Francisco, CA',
        'device' => 'iPhone 13',
        'timestamp' => '2024-06-21 14:30:00',
        'template_name' => 'alert'
    ],
    ['channels' => ['email']]
);
```

**Key Benefits of this Approach:**

- ✅ **Clean and Simple** - Minimal code required for template-based emails
- ✅ **Automatic Global Variables** - Variables like `app_name`, `current_year`, `logo_url` are automatically available
- ✅ **Template Mappings** - Use friendly template names that map to actual template files
- ✅ **No Duplication** - Each piece of data specified only once
- ✅ **Type Safety** - All template variables are validated and type-checked

**Global Variables Available in All Templates:**
```php
// These are automatically available without specifying them:
$globalVariables = [
    'app_name' => 'Glueful Application',
    'app_url' => 'https://example.com',
    'support_email' => 'support@example.com',
    'logo_url' => 'https://brand.glueful.com/logo.png',
    'current_year' => '2024',
    'company_name' => 'Your Company'
];
```

### Advanced Email Features

Leverage Symfony Mailer's advanced capabilities:

```php
// Email with attachments, custom headers, and advanced features
$notificationService->send(
    'invoice_generated',
    $customer,
    'Your Invoice #' . $invoice->number,
    [
        'invoice_number' => $invoice->number,
        'amount' => $invoice->total,
        'due_date' => $invoice->due_date,
        'embedImages' => [
            'logo' => '/path/to/logo.png',
        ],
    ],
    [
        'channels' => ['email'],
        'email_options' => [
            'attachments' => [
                [
                    'path' => $invoice->pdf_path,
                    'name' => 'Invoice-' . $invoice->number . '.pdf',
                    'contentType' => 'application/pdf'
                ]
            ],
            'cc' => ['accounting@example.com'],
            'bcc' => ['archive@example.com'],
            'priority' => 'high',
            'headers' => [
                'X-Invoice-ID' => $invoice->id,
                'X-Customer-ID' => $customer->id,
            ],
            'returnPath' => 'bounces@example.com',
        ]
    ]
);
```

## Asynchronous Delivery

This extension does not manage its own queue. Whether emails are sent synchronously or
queued is decided by the framework's notification dispatcher — push notifications onto the
framework queue at the dispatch layer and run workers (`php glueful queue:work`) per the
framework's queue documentation. The channel itself sends a single message when invoked.

### Retry tuning

Delivery-failure retry behavior is configured under `emailnotification.retry` (env
`MAIL_RETRY_ENABLED`, `MAIL_RETRY_MAX_ATTEMPTS`, `MAIL_RETRY_DELAY`, `MAIL_RETRY_BACKOFF`,
`MAIL_RETRY_JITTER`). On boot the extension surfaces this under the framework's channel-agnostic
`notifications.retry` key (Framework 1.51.0+), so the core retry service picks it up. A
transport failure returns a retryable `transport_exception` result; configuration errors return
the non-retryable `transport_misconfigured` result and are not retried.

## Transport Features

### Multi-Transport Support

Configure failover so a send falls through to the next mailer when one is unavailable:

```php
// Failover configuration
'failover' => [
    'mailers' => ['brevo', 'sendgrid', 'smtp'],
],
```

### Transport Health Monitoring

```php
use Glueful\Extensions\EmailNotification\TransportFactory;

// Check available providers
$providers = TransportFactory::getAvailableProviders();
// Returns status of all Symfony provider bridges

// Verify transport configuration
$emailChannel->isAvailable(); // Returns true if transport is configured
```

## Template System

The extension provides a flexible template system with built-in responsive templates and support for custom templates.

### Built-in Templates

The extension includes 6 professionally designed, responsive email templates:

#### 1. Default Template (`default.html`)
- **Use Case**: General notifications, alerts, and multi-purpose emails
- **Features**: OTP support, action buttons, customizable styling
- **Variables**: `{{subject}}`, `{{message}}`, `{{action_url}}`, `{{action_text}}`, `{{otp_code}}`

#### 2. Welcome Template (`welcome.html`)
- **Use Case**: User onboarding and welcome emails
- **Features**: Friendly greeting, getting started guidance
- **Variables**: `{{user_name}}`, `{{app_name}}`, `{{welcome_message}}`, `{{get_started_url}}`

#### 3. Alert Template (`alert.html`) 
- **Use Case**: Security alerts, important notifications, warnings
- **Features**: Attention-grabbing design, urgency indicators
- **Variables**: `{{alert_type}}`, `{{message}}`, `{{timestamp}}`, `{{action_required}}`

#### 4. Password Reset Template (`password-reset.html`)
- **Use Case**: Password reset functionality
- **Features**: Secure reset process, expiry warnings
- **Variables**: `{{user_name}}`, `{{reset_url}}`, `{{expiry_time}}`, `{{security_tip}}`

#### 5. Verification Template (`verification.html`)
- **Use Case**: Account verification, email confirmation
- **Features**: Verification codes, confirmation links
- **Variables**: `{{user_name}}`, `{{verification_code}}`, `{{verification_url}}`, `{{expiry_time}}`

#### 6. Two-Factor PIN Template (`two-factor-pin.html`)
- **Use Case**: Two-factor authentication one-time codes
- **Features**: Prominent PIN display, expiry warning
- **Variables**: `{{user_name}}`, `{{otp}}`, `{{expiry_minutes}}`

### Custom Templates

You can add your own email templates and customize the template system through configuration.

#### Template Configuration

Configure custom templates in `config/services.php`:

```php
'mail' => [
    'templates' => [
        // Primary template directory (optional override). By default, the
        // extension's own templates are used. To override explicitly:
        // 'path' => base_path('vendor/glueful/email-notification/src/Templates/html'),
        
        // Additional custom template directories (checked in order)
        'custom_paths' => [
            // Framework's mail templates
            dirname(__DIR__) . '/resources/mail',
            // Your custom templates directory
            dirname(__DIR__) . '/templates/email',
        ],
        
        // Layout and partials
        'default_layout' => env('MAIL_DEFAULT_LAYOUT', 'layout'),
        'partials_directory' => 'partials',
        
        // Template file extension
        'extension' => '.html',
        
        // Custom template mappings (aliases)
        'mappings' => [
            // Map friendly names to actual template files
            'user_welcome' => 'onboarding/welcome',
            'password_reset' => 'auth/reset-password',
            'invoice' => 'billing/invoice-generated',
        ],
        
        // Global variables available to all templates
        'global_variables' => [
            'app_name' => env('APP_NAME', 'Glueful Application'),
            'app_url' => env('BASE_URL', 'https://example.com'),
            'support_email' => env('MAIL_SUPPORT_EMAIL', 'support@example.com'),
            'logo_url' => env('MAIL_LOGO_URL', 'https://brand.glueful.com/logo.png'),
            'current_year' => date('Y'),
            'company_name' => env('COMPANY_NAME', 'Your Company'),
        ],
    ],
],
```

#### Creating Custom Templates

1. **Create Template Directory Structure**:
   ```
   resources/mail/
   ├── custom-welcome.html
   ├── invoice.html
   ├── newsletter.html
   └── partials/
       ├── layout.html
       ├── header.html
       └── footer.html
   ```

> **Override behavior**: if you set `services.mail.templates.custom_paths`, the extension will load templates from those paths first. Only templates or partials you provide are overridden; everything else falls back to the built‑in templates.

2. **Custom Template Example** (`resources/mail/invoice.html`):
   ```html
   <!DOCTYPE html>
   <html>
   <head>
       <meta charset="UTF-8">
       <title>{{subject}}</title>
       <style>
           .invoice-header { background: #f8f9fa; padding: 20px; }
           .invoice-details { margin: 20px 0; }
           .total { font-weight: bold; font-size: 18px; }
       </style>
   </head>
   <body>
       <div class="invoice-header">
           <h1>{{app_name}}</h1>
           <h2>Invoice #{{invoice_number}}</h2>
       </div>
       
       <div class="invoice-details">
           <p>Dear {{customer_name}},</p>
           <p>Your invoice is ready for review.</p>
           
           <table>
               <tr><td>Invoice Number:</td><td>{{invoice_number}}</td></tr>
               <tr><td>Amount:</td><td class="total">${{amount}}</td></tr>
               <tr><td>Due Date:</td><td>{{due_date}}</td></tr>
           </table>
           
           <p><a href="{{invoice_url}}">View Invoice Online</a></p>
       </div>
       
       {{> footer}}
   </body>
   </html>
   ```

3. **Using Custom Templates**:
   ```php
   // Use by filename
   $notificationService->sendWithTemplate(
       'invoice_generated',
       $customer,
       'invoice', // Uses resources/mail/invoice.html
       [
           'invoice_number' => 'INV-2024-001',
           'customer_name' => $customer->name,
           'amount' => '299.99',
           'due_date' => '2024-07-15',
           'invoice_url' => 'https://app.com/invoices/123',
       ]
   );
   
   // Use with mapping alias
   $notificationService->sendWithTemplate(
       'user_registration',
       $user,
       'user_welcome', // Maps to onboarding/welcome.html via template mappings
       [
           'user_name' => $user->name,
           'activation_url' => $activationUrl,
       ]
   );
   ```

#### Template Features

**Variable Substitution**:
- Simple variables: `{{variable_name}}`
- Default values: `{{variable_name|default_value}}`
- Nested variables: `{{user.profile.name}}`

> **Auto-escaping (security):** every `{{variable}}` (and its default literal) is HTML-escaped
> with `htmlspecialchars(ENT_QUOTES | ENT_HTML5)`, so notification data (display names, messages)
> cannot inject markup into outgoing mail. For slots that intentionally receive pre-rendered HTML,
> use the **raw** triple-mustache `{{{variable}}}` — the shipped layout's `{{{content}}}` is the
> only such slot; only use it for values you fully control. The `action_url`/`reset_url` values are
> blanked unless their scheme is `http`/`https` (relative URLs pass; `javascript:`/`data:` and
> malformed URLs are rejected).

**Conditional Blocks**:
```html
{{#if show_discount}}
<div class="discount">
    <p>Special offer: {{discount_percent}}% off!</p>
</div>
{{/if}}
```

**Partials (Template Includes)**:
```html
{{> header}}
<div class="content">
    <!-- Your content -->
</div>
{{> footer}}
```

**Global Variables**:
All templates automatically have access to configured global variables like `{{app_name}}`, `{{logo_url}}`, `{{current_year}}`, etc.

#### Template Inheritance

Create a base layout in `partials/layout.html`:

```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{subject}} - {{app_name}}</title>
    <style>
        /* Your base styles */
    </style>
</head>
<body>
    <header>
        <img src="{{logo_url}}" alt="{{app_name}}">
    </header>
    
    <main>
        {{{content}}} <!-- Template content goes here -->
    </main>
    
    <footer>
        <p>&copy; {{current_year}} {{company_name}}. All rights reserved.</p>
    </footer>
</body>
</html>
```

Templates without `<!DOCTYPE html>` automatically use this layout.

## Security Features

### Recipient Domain Policy

`EmailChannel` enforces an optional recipient-domain policy **before** sending. A recipient
whose domain is disallowed yields a non-retryable `NotificationResult` failure
(`blocked_domain`) and no mail is sent.

```env
# Denylist: a matching recipient domain is rejected (comma-separated)
MAIL_BLOCKED_DOMAINS=example.com,spam.test

# Allowlist: when set, ONLY matching recipient domains are permitted (comma-separated)
MAIL_ALLOWED_DOMAINS=yourcompany.com,partner.com
```

With neither set, all recipient domains are allowed. Both also accept an array in
`config/emailnotification.php` under `security.allowed_domains` / `security.blocked_domains`.

The policy is enforced on **every** recipient — the primary address plus all cc/bcc entries —
so an allowlist cannot be bypassed via a cc/bcc field; any disallowed (or non-string) entry
fails the whole send closed. Matching is **asymmetric by design**: the blocklist also matches
subdomains (blocking `evil.com` blocks `sub.evil.com`), while the allowlist is **exact-match**
only (allowlisting `company.com` does **not** permit `sub.company.com`).

### Attachment Path Confinement

Attachment and embedded-image paths come from notification data, so they are confined to
allowed base directories before reaching Symfony: a path is accepted only when `realpath()`
resolves it inside an allowed base (sibling-dir-safe — `/app/storage-evil` cannot pass for
`/app/storage`). Configure via `security.attachment_allowed_paths` (array) in
`config/emailnotification.php`; when null/empty it defaults to the application storage directory.
A rejected path is a loud, non-retryable `invalid_attachment` failure with an ERROR log naming
the path — never a silent skip.

### Transport security

- **Symfony Mailer**: transport built on Symfony's mailer security.
- **Provider isolation**: isolated transport creation per send.
- **Fail-loud misconfiguration**: a missing SMTP host, missing provider-bridge credentials, or
  any transport-factory failure is a non-retryable `transport_misconfigured` failure (ERROR log,
  config keys only) — the channel never silently falls back to a null sink that would report
  success while discarding mail. An explicitly configured null sink (`transport: 'null'` or a
  `null://` DSN) remains supported for development.
- **Error sanitization**: send failures return a structured `NotificationResult` (error code +
  message) and never throw SMTP credentials into the dispatcher. Logs never carry payload values
  (OTP pins, reset tokens/URLs, PII) — only payload keys plus the `subject`/`type`/`template_name`
  identifiers; diagnostics (`getExtensionInfo()`) return a credential-free config summary.

## Monitoring and Debugging

### Health & Metrics

```php
use Glueful\Extensions\EmailNotification\EmailNotificationProvider;

$provider = app($context, EmailNotificationProvider::class);

// Check provider configuration status
$isConfigured = $provider->isEmailProviderConfigured();
```

Delivery metrics (per-channel delivery times, retry counts and distributions) are owned by the
framework's notification system — use `NotificationService::getMetricsService()`
(`Glueful\Notifications\Services\NotificationMetricsService`), which is fed by the structured
`NotificationResult` this channel returns for every send.

### Debug Mode

Enable detailed logging for troubleshooting:

```env
MAIL_DEBUG=true          # debug-log each outgoing email (recipient/subject/type only)
MAIL_LOG_RESULTS=true    # info/error-log each send outcome
APP_DEBUG=true
```

## Migration from PHPMailer

### Breaking Changes in v1.0.0

1. **Transport Configuration**: New multi-mailer structure required
2. **Queue System**: File-based queue replaced with framework queue
3. **Provider Specification**: Explicit transport types required (e.g., `brevo+api`)
4. **Dependencies**: Symfony Mailer replaces PHPMailer

### Migration Steps

1. **Update Dependencies**:
   ```bash
   composer require symfony/mailer symfony/brevo-mailer
   ```

2. **Update Configuration**:
   ```php
   // OLD (PHPMailer)
   'mail' => [
       'host' => 'smtp.brevo.com',
       'port' => 587,
       'username' => 'user@domain.com',
       'password' => 'password',
   ]
   
   // NEW (Symfony Mailer)
   'mail' => [
       'default' => 'brevo',
       'mailers' => [
           'brevo' => [
               'transport' => 'brevo+smtp',
               'username' => env('MAIL_USERNAME'),
               'password' => env('MAIL_PASSWORD'),
           ],
       ],
   ]
   ```

3. **Update Environment Variables**:
   ```env
   MAIL_MAILER=brevo
   BREVO_TRANSPORT=brevo+smtp  # or brevo+api
   MAIL_ENCRYPTION=tls         # not MAIL_SECURE
   ```

4. **Verify Configuration**:
   ```bash
   # Confirm the extension is discovered/enabled and view its metadata
   php glueful extensions:info email-notification
   ```

## Troubleshooting

### Common Issues

1. **Transport Creation Errors**
   - Verify provider bridge is installed: `composer require symfony/brevo-mailer`
   - Check configuration structure in `services.php`
   - Review error logs for specific transport issues

2. **Emails Not Sending Asynchronously**
   - Async delivery is governed by the framework's notification dispatcher/queue, not this
     extension — see the framework queue documentation
   - Start queue workers: `php glueful queue:work`

3. **Provider Bridge Issues**
   - Verify API credentials are correct
   - Check transport specification (e.g., `brevo+api` vs `brevo+smtp`)
   - Review provider-specific documentation

4. **Configuration Path Issues**
   - Ensure the provider is discovered (composer-installed) and, if needed, enabled in `config/extensions.php`
   - Verify `services.mail` configuration exists
   - Check `from` address is configured

### Health Checks

This extension registers no HTTP routes. Diagnose configuration from PHP/CLI instead:

```php
$provider = app()->get(\Glueful\Extensions\EmailNotification\EmailNotificationProvider::class);
$provider->isEmailProviderConfigured();   // bool — credentials/transport/from validated
$provider->getExtensionInfo();            // credential-free config summary + feature flags
```

```bash
php glueful extensions:info email-notification
```

## Provider-Specific Setup

### Brevo (Sendinblue)

```env
MAIL_MAILER=brevo
BREVO_TRANSPORT=brevo+api           # API mode (recommended)
# BREVO_TRANSPORT=brevo+smtp        # SMTP mode
BREVO_API_KEY=your-brevo-api-key
MAIL_USERNAME=your-smtp-login
MAIL_PASSWORD=your-smtp-key
```

### SendGrid

```env
MAIL_MAILER=sendgrid
SENDGRID_API_KEY=your-sendgrid-key
```

### Amazon SES

```env
MAIL_MAILER=ses
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
AWS_DEFAULT_REGION=us-east-1
```

### Mailgun

```env
MAIL_MAILER=mailgun
MAILGUN_DOMAIN=your-domain.mailgun.org
MAILGUN_SECRET=your-mailgun-key
MAILGUN_REGION=us                  # or eu
```

## License

This extension is licensed under the MIT License.

## Support

For issues, feature requests, or questions about the EmailNotification extension:
- Create an issue in the repository
- Consult the [Symfony Mailer documentation](https://symfony.com/doc/current/mailer.html)
- Check the extension health monitoring for diagnostics

---

**📚 Documentation**: [Glueful Framework Documentation](https://docs.glueful.com)  
**🔧 Provider Bridges**: [Symfony Mailer Bridges](https://symfony.com/doc/current/mailer.html#using-a-3rd-party-transport)
