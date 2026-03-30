# Vigil Client

Laravel package that captures exceptions and forwards logs to a [Vigil](https://github.com/byronfichardt/vigil) server for centralized error tracking and log viewing.

## Requirements

- PHP 8.1+
- Laravel 10, 11, 12, or 13
- A running [Vigil server](https://github.com/byronfichardt/vigil)

## Installation

```bash
composer require vigil/client
```

The package auto-discovers via Laravel's package discovery. No service provider registration needed.

## Configuration

Add to your `.env`:

```env
VIGIL_URL=https://your-vigil-server.com
VIGIL_KEY=your-project-api-key
```

That's it. Exceptions are automatically captured and logs at `warning` level and above are forwarded to Vigil.

### Optional Configuration

Publish the config file to customize behavior:

```bash
php artisan vendor:publish --tag=vigil-config
```

This creates `config/vigil.php` with the following options:

```php
return [
    // Vigil server URL and project API key
    'url' => env('VIGIL_URL'),
    'key' => env('VIGIL_KEY'),

    // Enable/disable reporting
    'enabled' => env('VIGIL_ENABLED', true),

    // Environment label sent with each exception/log
    'environment' => env('APP_ENV', 'production'),

    // Log errors to error_log() when sending fails
    'debug' => env('VIGIL_DEBUG', false),

    // HTTP timeouts (seconds)
    'timeout' => env('VIGIL_TIMEOUT', 2),
    'connect_timeout' => env('VIGIL_CONNECT_TIMEOUT', 1),

    // Include source code context in stack traces
    'code_snippets' => true,

    // Request body fields to redact before sending
    'redact_fields' => [
        'password', 'password_confirmation', 'secret', 'token',
        'credit_card', 'card_number', 'cvv', 'ssn',
        'api_key', 'authorization',
    ],

    // Exception classes to ignore
    'ignore' => [
        Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        Illuminate\Auth\AuthenticationException::class,
        Illuminate\Session\TokenMismatchException::class,
    ],

    // Log forwarding configuration
    'logs' => [
        'enabled' => env('VIGIL_LOGS_ENABLED', true),
        'levels' => ['warning', 'error', 'critical', 'alert', 'emergency'],
        'channels' => ['*'],       // ['*'] = all channels, or ['stack', 'single'] to whitelist
        'buffer_limit' => 100,     // flush early if buffer exceeds this before request ends
    ],
];
```

### Log Level Configuration

By default, only `warning` and above are forwarded. To include all levels:

```php
'logs' => [
    'levels' => ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'],
],
```

To disable log forwarding entirely:

```env
VIGIL_LOGS_ENABLED=false
```

### Channel Filtering

To only forward logs from specific channels:

```php
'logs' => [
    'channels' => ['stack', 'single'],
],
```

## What Gets Captured

### Exceptions

| Data | Description |
|------|-------------|
| Exception class | Fully qualified class name |
| Message | The exception message |
| Stack trace | All frames with optional source code snippets |
| Request URL | Full URL including query string |
| Request method | GET, POST, etc. |
| Request headers | Filtered (Authorization, Cookie, etc. are excluded) |
| Request body | Filtered (passwords, tokens, etc. are redacted) |
| User info | Authenticated user's ID and email |
| Environment | APP_ENV value |
| Hostname | Server hostname |
| PHP version | Runtime PHP version |
| Laravel version | Framework version |

### Logs

| Data | Description |
|------|-------------|
| Level | debug, info, notice, warning, error, critical, alert, emergency |
| Channel | Laravel log channel name (e.g., `stack`, `single`) |
| Message | The log message |
| Context | Log context array (with sensitive fields redacted, `exception` key stripped) |
| Extra | Monolog processor-added data |
| Request URL | Full URL (HTTP requests only) |
| Request method | GET, POST, etc. (HTTP requests only) |
| Environment | APP_ENV value |
| Hostname | Server hostname |

## How Log Shipping Works

Logs are **batched, not sent individually**. This is important for performance:

1. Each `Log::warning()`, `Log::error()`, etc. appends to an in-memory buffer
2. At the end of the request, a terminable middleware flushes the buffer in a single HTTP call
3. The flush happens **after the response has been sent** to the user, so there is zero impact on response time

For queue workers and CLI commands:
- Logs are flushed after each job completes (via `JobProcessed` / `JobFailed` events)
- A shutdown function flushes any remaining logs when the process exits
- The buffer limit (default 100) triggers a mid-process flush for long-running commands

If the buffer fills up before the request ends, it flushes immediately to keep memory bounded.

## Security

Vigil takes care to avoid leaking sensitive data:

- **Headers filtered:** `Authorization`, `Cookie`, `Set-Cookie`, `X-CSRF-Token`, `X-XSRF-Token`, and `Proxy-Authorization` are never sent.
- **Request body redacted:** Fields like `password`, `credit_card`, `cvv`, `ssn`, `token`, and `api_key` are replaced with `[REDACTED]`. Customize via the `redact_fields` config.
- **Log context redacted:** The same `redact_fields` apply to log context data. The `exception` key is also stripped from log context (exceptions are tracked separately).
- **Body size limit:** Request bodies larger than 16KB are truncated to prevent oversized payloads.
- **CLI safe:** In console context (Artisan commands, queue workers), request data is not collected.
- **Silent failures:** The client never throws exceptions. If the Vigil server is unreachable, your app continues normally.

## Performance

- **Zero overhead when no exceptions occur.** The exception reporter only runs inside Laravel's exception handler.
- **~2-5ms per exception.** A synchronous HTTP POST with a 2-second timeout and 1-second connect timeout. This only happens on error paths, not normal requests.
- **Log shipping is non-blocking.** Logs buffer in memory (~1-2KB per entry) and flush after the response is sent. The user never waits for log delivery.
- **One HTTP call per request.** Even if your app logs 50 lines, they're sent as a single batched request.
- **If the Vigil server is down:** Exceptions time out after 2s; log flushes fail silently. Your app is never affected.
- **Code snippets:** Reading source files for stack trace context adds minor I/O. Disable with `'code_snippets' => false` in config if needed.

## Debugging

If exceptions or logs aren't showing up in your Vigil dashboard:

1. **Check your config:**
   ```bash
   php artisan tinker
   > config('vigil.url')    // Should return your Vigil server URL
   > config('vigil.key')    // Should return your API key
   > config('vigil.enabled') // Should return true
   ```

2. **Enable debug mode** to see errors in your log:
   ```env
   VIGIL_DEBUG=true
   ```

3. **Check the ignore list** - your exception class might be filtered out.

4. **Check log levels** - by default only `warning` and above are forwarded. If you're testing with `Log::info()`, it won't appear unless you add `info` to the levels config.

5. **Test connectivity** from your server:
   ```bash
   # Test exception endpoint
   curl -X POST https://your-vigil-server.com/api/exceptions \
     -H "X-Vigil-Key: your-key" \
     -H "Content-Type: application/json" \
     -d '{"exception_class":"Test","message":"test","file":"test.php","line":1,"stack_trace":[]}'

   # Test log endpoint
   curl -X POST https://your-vigil-server.com/api/logs \
     -H "X-Vigil-Key: your-key" \
     -H "Content-Type: application/json" \
     -d '{"logs":[{"level":"warning","channel":"test","message":"test log","logged_at":"2025-01-01T00:00:00+00:00"}]}'
   ```

## Limitations

- **Laravel only.** This package uses Laravel's exception handler, service container, and helpers. It does not work with other PHP frameworks.
- **Logs lost on hard crash.** If the PHP process is killed (SIGKILL, OOM) before the buffer flushes, buffered logs are lost. The exception itself is still reported immediately via the exception handler.
- **Synchronous exception sending.** Each exception is sent individually as a blocking HTTP request. Under extreme error rates, this could add latency. Log shipping is non-blocking.

## License

MIT
