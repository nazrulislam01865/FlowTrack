<?php

namespace App\Services\Email;

use App\Contracts\Email\EmailTransport;
use App\DTOs\Email\EmailMessage;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Message;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;

/**
 * Adapter between FlowTrack's provider-neutral email module and Laravel Mail.
 *
 * SMTP, SES, Postmark, Resend, failover, etc. are selected through config;
 * application modules never need provider-specific code.
 */
final class LaravelEmailTransport implements EmailTransport
{
    public function __construct(
        private readonly MailFactory $mail,
        private readonly FilesystemFactory $filesystems,
    ) {}

    public function send(EmailMessage $message, ?string $idempotencyKey = null): void
    {
        $mailerName = trim((string) config('flowtrack_email.mailer'));
        $mailer = $this->mail->mailer($mailerName !== '' ? $mailerName : null);

        $configure = function (Message $mail) use ($message): void {
            foreach ($message->to as $address) {
                $mail->to($address);
            }
            foreach ($message->cc as $address) {
                $mail->cc($address);
            }
            foreach ($message->bcc as $address) {
                $mail->bcc($address);
            }
            foreach ($message->replyTo as $address) {
                $mail->replyTo($address);
            }

            $mail->subject($message->subject);

            foreach ($message->attachments as $attachment) {
                $this->attach($mail, $attachment);
            }
        };

        if ($message->view !== null) {
            $mailer->send($message->view, $message->viewData, $configure);
            return;
        }

        if ($message->html !== null) {
            $mailer->send(['html' => new HtmlString($message->html)], [], $configure);
            return;
        }

        $mailer->raw((string) $message->text, $configure);
    }

    /** @param array{source:string,path:string,disk:?string,name:?string,mime:?string} $attachment */
    private function attach(Message $message, array $attachment): void
    {
        $maxBytes = max(1, (int) config('flowtrack_email.attachments.max_bytes', 15 * 1024 * 1024));

        if ($attachment['source'] === 'storage') {
            $storage = $this->filesystems->disk((string) $attachment['disk']);
            if (! $storage->exists($attachment['path'])) {
                throw new InvalidArgumentException('Email storage attachment does not exist: '.$attachment['path']);
            }
            if ($storage->size($attachment['path']) > $maxBytes) {
                throw new InvalidArgumentException('Email attachment exceeds configured size limit: '.$attachment['path']);
            }

            $mailAttachment = Attachment::fromStorageDisk($attachment['disk'], $attachment['path']);
        } else {
            if (! is_file($attachment['path']) || ! is_readable($attachment['path'])) {
                throw new InvalidArgumentException('Email attachment is missing or unreadable: '.$attachment['path']);
            }
            if ((int) filesize($attachment['path']) > $maxBytes) {
                throw new InvalidArgumentException('Email attachment exceeds configured size limit: '.$attachment['path']);
            }

            $mailAttachment = Attachment::fromPath($attachment['path']);
        }

        if ($attachment['name'] !== null) {
            $mailAttachment->as($attachment['name']);
        }
        if ($attachment['mime'] !== null) {
            $mailAttachment->withMime($attachment['mime']);
        }

        $mailAttachment->attachTo($message);
    }
}
