# Vigil Client

Laravel package that captures exceptions and sends them to a [Vigil](https://github.com/byronfichardt/vigil) server for centralized error tracking.

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

That's it. Exceptions are automatically captured and reported.

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

    // Environment label sent with each exception
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
        'password',
        'password_confirmation',
        'secret',
        'token',
        'credit_card',
        'card_number',
        'cvv',
        'ssn',
        'api_key',
        'authorization',
    ],

    // Exception classes to ignore
    'ignore' => [
        Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        Illuminate\Auth\AuthenticationException::class,
        Illuminate\Session\TokenMismatchException::class,
    ],
];
```

## What Gets Captured

For each exception, Vigil collects:

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

## Security

Vigil takes care to avoid leaking sensitive data:

- **Headers filtered:** `Authorization`, `Cookie`, `Set-Cookie`, `X-CSRF-Token`, `X-XSRF-Token`, and `Proxy-Authorization` are never sent.
- **Request body redacted:** Fields like `password`, `credit_card`, `cvv`, `ssn`, `token`, and `api_key` are replaced with `[REDACTED]`. Customize via the `redact_fields` config.
- **Body size limit:** Request bodies larger than 16KB are truncated to prevent oversized payloads.
- **CLI safe:** In console context (Artisan commands, queue workers), request data is not collected.
- **Silent failures:** The client never throws exceptions. If the Vigil server is unreachable, your app continues normally.

## Performance

- **Zero overhead when no exceptions occur.** The reporter only runs inside Laravel's exception handler.
- **~2-5ms per exception.** A synchronous HTTP POST with a 2-second timeout and 1-second connect timeout. This only happens on error paths, not normal requests.
- **If the Vigil server is down:** The request times out and fails silently. Worst case, an exception adds 2 seconds to the response - but only when an error was already happening.
- **Code snippets:** Reading source files for stack trace context adds minor I/O. Disable with `'code_snippets' => false` in config if needed.

## Debugging

If exceptions aren't showing up in your Vigil dashboard:

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

4. **Test connectivity** from your server:
   ```bash
   curl -X POST https://your-vigil-server.com/api/exceptions \
     -H "X-Vigil-Key: your-key" \
     -H "Content-Type: application/json" \
     -d '{"exception_class":"Test","message":"test","file":"test.php","line":1,"stack_trace":[]}'
   ```

## Limitations

- **Laravel only.** This package uses Laravel's exception handler, service container, and helpers. It does not work with other PHP frameworks.
- **No batching.** Each exception is sent individually. Under extreme error rates, this could generate significant outbound traffic.
- **Synchronous sending.** The HTTP request blocks for up to 2 seconds (configurable). For queue workers processing thousands of jobs, consider increasing the timeout or disabling Vigil in queue contexts.

## License

MIT
