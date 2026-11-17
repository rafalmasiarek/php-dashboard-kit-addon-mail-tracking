# dashboard-kit-addon-mail-tracking








Open-tracking pixel addon for [dashboard-kit](https://github.com/rafalmasiarek/php-dashboard-kit)
outgoing email — listens on the `mail_sending` hook to append a pixel to every message,
independent of which controller sends it.







## License

![License: BUSL-1.1](https://img.shields.io/badge/license-BUSL--1.1-red)

Business Source License 1.1.

Source available; commercial use restricted until 2099-01-01.


For alternative licensing, contact: https://masiarek.pl/contact/?af_subject=Commercial+license+%E2%80%94+dashboard-kit-addon-mail-tracking&af_message=Hello%2C+I+am+interested+in+a+commercial+license+for+dashboard-kit-addon-mail-tracking.



See [LICENSE](LICENSE) for full details.


## Authors

- Rafał Masiarek | [website](https://masiarek.pl) | [github](https://github.com/rafalmasiarek)




## `MailTrackingAddon`

*final class* — `rafalmasiarek\DashboardKitMailTracking\MailTrackingAddon` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-mail-tracking/blob/main/src/MailTrackingAddon.php#L41)

Open-tracking pixel addon for dashboard-kit outgoing email. Registers a HookRegistry::addListener('mail_sending', ...) listener, so every email sent through Mailer::send() — regardless of which controller calls it — gets a pixel appended before delivery. A failure in this listener (e.g. a DB insert error) is caught and logged by the hook dispatcher itself and never blocks the actual send; it previously replaced the whole MailerInterface binding with a decorator, which could break delivery entirely if the decorator itself failed. Entirely inert (no schema sync, no route, no listener) unless config['mail_tracking']['base_url'] is set. Usage (app bootstrap, after Dashboard::create()): MailTrackingAddon::register($app, $container, $config); Hooks emitted (via dashboard-kit's HookRegistry, no-op if unregistered): mail_opened (string $toEmail, string $mailType, string $token, string $ip, ?string $correlationId) Register with: $dashboard->on('mail_opened', function (...) { ... });

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`register()`](#mailtrackingaddon-register) | public static | `void` | — |
| [`syncSchema()`](#mailtrackingaddon-syncschema) | private static | `void` | Creates/updates the mail_tracking table via ModuleSchemaBuilder, the same mechanism and config['schema_defaults'] convention core modules use. |
| [`registerHook()`](#mailtrackingaddon-registerhook) | private static | `void` | Registers the pixel-appending listener on Mailer's 'mail_sending' hook. A thrown exception here is caught and logged by HookRegistry::emit() itself, so a failure (e.g. this INSERT) never blocks the actual delivery that follows. |
| [`registerRoute()`](#mailtrackingaddon-registerroute) | private static | `void` | Registers the pixel endpoint. The response is identical (a 1x1 PNG) whether or not the token matched or was already opened — the HTTP response itself never discloses tracking state to the client. |

<details>
<summary><code>register()</code></summary>

#### `MailTrackingAddon register()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-mail-tracking/blob/main/src/MailTrackingAddon.php#L55)

```php
public static function register(App $app, ContainerInterface $container, array $config): void
```

| Param | Type | Description |
|---|---|---|
| `$app` | `App` | Slim application instance. |
| `$container` | `ContainerInterface` | DI container. |
| `$config` | `array<string, mixed>` | Application config. |

</details>

<details>
<summary><code>syncSchema()</code></summary>

#### `MailTrackingAddon syncSchema()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-mail-tracking/blob/main/src/MailTrackingAddon.php#L74)

```php
private static function syncSchema(ContainerInterface $container, array $config): void
```

Creates/updates the mail_tracking table via ModuleSchemaBuilder, the same mechanism and config['schema_defaults'] convention core modules use.

| Param | Type | Description |
|---|---|---|
| `$container` | `ContainerInterface` | — |
| `$config` | `array<string, mixed>` | — |

</details>

<details>
<summary><code>registerHook()</code></summary>

#### `MailTrackingAddon registerHook()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-mail-tracking/blob/main/src/MailTrackingAddon.php#L115)

```php
private static function registerHook(ContainerInterface $container, string $baseUrl): void
```

Registers the pixel-appending listener on Mailer's 'mail_sending' hook. A thrown exception here is caught and logged by HookRegistry::emit() itself, so a failure (e.g. this INSERT) never blocks the actual delivery that follows.

| Param | Type | Description |
|---|---|---|
| `$container` | `ContainerInterface` | — |
| `$baseUrl` | `string` | — |

</details>

<details>
<summary><code>registerRoute()</code></summary>

#### `MailTrackingAddon registerRoute()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-mail-tracking/blob/main/src/MailTrackingAddon.php#L152)

```php
private static function registerRoute(App $app, ContainerInterface $container): void
```

Registers the pixel endpoint. The response is identical (a 1x1 PNG) whether or not the token matched or was already opened — the HTTP response itself never discloses tracking state to the client.

| Param | Type | Description |
|---|---|---|
| `$app` | `App` | — |
| `$container` | `ContainerInterface` | — |

</details>




<!-- references -->

[repo_link]: https://github.com/rafalmasiarek/php-dashboard-kit-addon-mail-tracking
