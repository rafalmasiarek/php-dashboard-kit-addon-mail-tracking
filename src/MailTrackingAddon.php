<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitMailTracking;

use Psr\Container\ContainerInterface;
use rafalmasiarek\DashboardKit\Log\AuditLog;
use rafalmasiarek\DashboardKit\Mail\MailerInterface;
use rafalmasiarek\DashboardKit\Model\Model;
use rafalmasiarek\DashboardKit\Schema\ModuleSchemaBuilder;
use rafalmasiarek\DashboardKit\Schema\SchemaInspector;
use rafalmasiarek\DashboardKit\Schema\SchemaStateManager;
use rafalmasiarek\RealIpResolver;
use Slim\App;

/**
 * Open-tracking pixel addon for dashboard-kit outgoing email.
 *
 * Wraps the app's MailerInterface binding with TrackingMailerDecorator, so
 * every email sent through it — regardless of which controller calls
 * send() — gets a pixel appended. Entirely inert (no schema sync, no
 * route, no decoration) unless config['mail_tracking']['base_url'] is set,
 * and a no-op if no MailerInterface is bound at all (mailer not configured).
 *
 * Usage (app bootstrap, after Dashboard::create()):
 *   MailTrackingAddon::register($app, $container, $config);
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
        self::decorateMailer($container, $baseUrl);
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
                    'token'     => ['type' => 'char(32)', 'null' => false],
                    'to_email'  => ['type' => 'varchar(255)', 'null' => false],
                    'mail_type' => ['type' => 'varchar(255)', 'null' => false],
                    'opened_at' => ['type' => 'datetime', 'default' => null],
                    'open_ip'   => ['type' => 'varchar(45)', 'default' => null],
                ],
                'primary' => 'id',
                'indexes' => [
                    'uniq_token' => ['columns' => ['token'], 'unique' => true],
                ],
            ],
        ]]]);
    }

    /**
     * Wraps the existing MailerInterface binding with TrackingMailerDecorator.
     * No-op if nothing is bound (mailer not configured) — resolves the
     * current instance once, so the decorator wraps a fixed delegate rather
     * than recursively re-resolving the (now overwritten) binding.
     *
     * @param ContainerInterface $container
     * @param string             $baseUrl
     */
    private static function decorateMailer(ContainerInterface $container, string $baseUrl): void
    {
        if (!$container->has(MailerInterface::class)) {
            return;
        }

        $inner = $container->get(MailerInterface::class);

        $container->set(
            MailerInterface::class,
            static fn(): TrackingMailerDecorator => new TrackingMailerDecorator($inner, $baseUrl),
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
        $app->get('/mail/{token}.png', static function ($req, $res, array $args) use ($container) {
            $token = (string) $args['token'];
            $row   = Model::on('mail_tracking')->where('token', $token)->first();

            if ($row !== null && $row['opened_at'] === null) {
                $ip = $container->get(RealIpResolver::class)->getIp() ?: 'unknown';

                Model::on('mail_tracking')->where('token', $token)->update([
                    'opened_at' => Model::getClock()->now()->format('Y-m-d H:i:s'),
                    'open_ip'   => $ip,
                ]);

                $container->get(AuditLog::class)->mailOpen((string) $row['to_email'], (string) $row['mail_type']);
            }

            $res->getBody()->write(base64_decode(self::PIXEL_PNG_BASE64));

            return $res
                ->withHeader('Content-Type', 'image/png')
                ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
                ->withHeader('Pragma', 'no-cache');
        });
    }
}
