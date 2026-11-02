<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitMailTracking;

use Psr\Container\ContainerInterface;
use rafalmasiarek\DashboardKit\Hook\HookRegistry;
use rafalmasiarek\DashboardKit\Log\AuditLog;
use rafalmasiarek\DashboardKit\Mail\MailMessage;
use rafalmasiarek\DashboardKit\Model\Model;
use rafalmasiarek\DashboardKit\Schema\ModuleSchemaBuilder;
use rafalmasiarek\DashboardKit\Schema\SchemaInspector;
use rafalmasiarek\DashboardKit\Schema\SchemaStateManager;
use rafalmasiarek\RealIpResolver;
use Slim\App;

/**
 * Open-tracking pixel addon for dashboard-kit outgoing email.
 *
 * Registers a HookRegistry::addListener('mail_sending', ...) listener, so
 * every email sent through Mailer::send() — regardless of which controller
 * calls it — gets a pixel appended before delivery. A failure in this
 * listener (e.g. a DB insert error) is caught and logged by the hook
 * dispatcher itself and never blocks the actual send; it previously
 * replaced the whole MailerInterface binding with a decorator, which could.
 * Entirely inert (no schema sync, no route, no listener) unless
 * config['mail_tracking']['base_url'] is set.
 *
 * Usage (app bootstrap, after Dashboard::create()):
 *   MailTrackingAddon::register($app, $container, $config);
 *
 * Hooks emitted (via dashboard-kit's HookRegistry, no-op if unregistered):
 *   mail_opened (string $toEmail, string $mailType, string $token, string $ip, ?string $correlationId)
 *
 * Register with: $dashboard->on('mail_opened', function (...) { ... });
 *
 * @package rafalmasiarek\DashboardKitMailTracking
 */
final class MailTrackingAddon
{
    /**
     * 1x1 transparent PNG, served for every pixel request regardless of
     * whether the token matched — never reveal open/no-open via a different
     * response.
     */
    private const PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    /**
     * @param App                 $app       Slim application instance.
     * @param ContainerInterface  $container DI container.
     * @param array<string, mixed> $config   Application config.
     */
    public static function register(App $app, ContainerInterface $container, array $config = []): void
    {
        $baseUrl = (string) ($config['mail_tracking']['base_url'] ?? '');
        if ($baseUrl === '') {
            return;
        }

        self::syncSchema($container, $config);
        self::registerHook($container, $baseUrl);
        self::registerRoute($app, $container);
    }

    /**
     * Creates/updates the mail_tracking table via ModuleSchemaBuilder, the
     * same mechanism and config['schema_defaults'] convention core modules use.
     *
     * @param ContainerInterface   $container
     * @param array<string, mixed> $config
     */
    private static function syncSchema(ContainerInterface $container, array $config): void
    {
        $builder = new ModuleSchemaBuilder(
            (bool) ($config['schema_defaults']['timestamps']   ?? false),
            (bool) ($config['schema_defaults']['soft_deletes'] ?? false),
        );

        $manager = new SchemaStateManager(
            $container->get('pdo.raw'),
            $builder,
            new SchemaInspector(),
        );

        $manager->sync(['mail_tracking' => ['schema' => [
            'mail_tracking' => [
                'columns' => [
                    'id'        => ['type' => 'int', 'unsigned' => true, 'auto_increment' => true, 'null' => false],
                    'token'          => ['type' => 'char(32)', 'null' => false],
                    'to_email'       => ['type' => 'varchar(255)', 'null' => false],
                    'mail_type'      => ['type' => 'varchar(255)', 'null' => false],
                    'correlation_id' => ['type' => 'varchar(64)', 'default' => null],
                    'opened_at'      => ['type' => 'datetime', 'default' => null],
                    'open_ip'        => ['type' => 'varchar(45)', 'default' => null],
                ],
                'primary' => 'id',
                'indexes' => [
                    'uniq_token' => ['columns' => ['token'], 'unique' => true],
                ],
            ],
        ]]]);
    }

    /**
     * Registers the pixel-appending listener on Mailer's 'mail_sending' hook.
     * A thrown exception here is caught and logged by
     * HookRegistry::emit() itself, so a failure (e.g. this INSERT) never
     * blocks the actual delivery that follows.
     *
     * @param ContainerInterface $container
     * @param string             $baseUrl
     */
    private static function registerHook(ContainerInterface $container, string $baseUrl): void
    {
        $container->get(HookRegistry::class)->addListener(
            'mail_sending',
            static function (MailMessage $message) use ($baseUrl): void {
                $token = bin2hex(random_bytes(16));

                Model::on('mail_tracking')->insert([
                    'token'          => $token,
                    'to_email'       => $message->getToEmail(),
                    'mail_type'      => $message->getSubject(),
                    'correlation_id' => $message->getCorrelationId(),
                ]);

                $pixelUrl = rtrim($baseUrl, '/') . '/mail/' . $token . '.png';
                if ($message->getCorrelationId() !== null) {
                    $pixelUrl .= '?id=' . rawurlencode($message->getCorrelationId());
                }
                $message->appendBodyHtml(
                    sprintf(
                        '<img src="%s" width="1" height="1" alt="" style="border:0;display:block;width:1px;height:1px">',
                        htmlspecialchars($pixelUrl, ENT_QUOTES),
                    ),
                    999,
                );
            },
        );
    }

    /**
     * Registers the pixel endpoint. The response is identical (a 1x1 PNG)
     * whether or not the token matched or was already opened — the HTTP
     * response itself never discloses tracking state to the client.
     *
     * @param App                $app
     * @param ContainerInterface $container
     */
    private static function registerRoute(App $app, ContainerInterface $container): void
    {
        $app->get('/mail/{token}.png', function ($req, $res, array $args) use ($container) {
            $token = (string) $args['token'];
            $row   = Model::on('mail_tracking')->where('token', $token)->first();

            if ($row !== null && $row['opened_at'] === null) {
                $ip = $container->get(RealIpResolver::class)->getIp() ?: 'unknown';

                Model::on('mail_tracking')->where('token', $token)->update([
                    'opened_at' => Model::getClock()->now()->format('Y-m-d H:i:s'),
                    'open_ip'   => $ip,
                ]);

                $correlationId = $row['correlation_id'] !== null ? (string) $row['correlation_id'] : null;

                $container->get(AuditLog::class)->mailOpen((string) $row['to_email'], (string) $row['mail_type'], $correlationId);
                $container->get(HookRegistry::class)->emit(
                    'mail_opened',
                    (string) $row['to_email'],
                    (string) $row['mail_type'],
                    $token,
                    $ip,
                    $correlationId,
                );
            }

            $res->getBody()->write(base64_decode(self::PIXEL_PNG_BASE64));

            return $res
                ->withHeader('Content-Type', 'image/png')
                ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
                ->withHeader('Pragma', 'no-cache');
        });
    }
}
