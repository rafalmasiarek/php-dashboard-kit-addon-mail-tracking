<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitMailTracking;

use rafalmasiarek\DashboardKit\Mail\MailerInterface;
use rafalmasiarek\DashboardKit\Mail\MailMessage;
use rafalmasiarek\DashboardKit\Model\Model;

/**
 * Decorates a MailerInterface to append an open-tracking pixel to every
 * outgoing message, independent of which controller sent it.
 *
 * Priority 999 on appendBodyHtml() guarantees the pixel renders after any
 * other 'extra_body_html' fragment another decorator might append.
 *
 * @package rafalmasiarek\DashboardKitMailTracking
 */
final class TrackingMailerDecorator implements MailerInterface
{
    private const TABLE = 'mail_tracking';

    /**
     * @param MailerInterface $inner   The real mailer to delegate delivery to.
     * @param string          $baseUrl Canonical public base URL (e.g. 'https://masiarek.pl/api'),
     *                                 used to build the tracking URL — Mailer::send() may run
     *                                 from a CLI/cron context with no HTTP request to derive it from.
     */
    public function __construct(
        private readonly MailerInterface $inner,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function send(MailMessage $message): void
    {
        $token = bin2hex(random_bytes(16));

        Model::on(self::TABLE)->insert([
            'token'     => $token,
            'to_email'  => $message->getToEmail(),
            'mail_type' => $message->getSubject(),
        ]);

        $pixelUrl = rtrim($this->baseUrl, '/') . '/mail/' . $token . '.png';
        $message->appendBodyHtml(
            sprintf(
                '<img src="%s" width="1" height="1" alt="" style="border:0;display:block;width:1px;height:1px">',
                htmlspecialchars($pixelUrl, ENT_QUOTES),
            ),
            999,
        );

        $this->inner->send($message);
    }
}
