<?php

namespace App\DTOs\Email;

use InvalidArgumentException;

/**
 * Provider-neutral email payload that is safe to serialize onto a queue.
 *
 * Keep view data serializable (arrays/scalars/DTO data), rather than closures
 * or open resources, because queued delivery serializes this object.
 */
final readonly class EmailMessage
{
    /** @var list<string> */
    public array $to;

    /** @var list<string> */
    public array $cc;

    /** @var list<string> */
    public array $bcc;

    /** @var list<string> */
    public array $replyTo;

    /** @var list<array{source:string,path:string,disk:?string,name:?string,mime:?string}> */
    public array $attachments;

    public string $subject;

    /** @param string|list<string> $to */
    public function __construct(
        string|array $to,
        string $subject,
        public ?string $html = null,
        public ?string $text = null,
        public ?string $view = null,
        public array $viewData = [],
        string|array $cc = [],
        string|array $bcc = [],
        string|array $replyTo = [],
        array $attachments = [],
        public array $context = [],
    ) {
        $this->to = self::normalizeAddresses($to, true);
        $this->cc = self::normalizeAddresses($cc);
        $this->bcc = self::normalizeAddresses($bcc);
        $this->replyTo = self::normalizeAddresses($replyTo);
        $this->attachments = self::normalizeAttachments($attachments);
        $this->subject = trim($subject);

        if ($this->subject === '') {
            throw new InvalidArgumentException('Email subject cannot be empty.');
        }

        if ($this->view === null && $this->html === null && $this->text === null) {
            throw new InvalidArgumentException('Email requires a Blade view, HTML body, or text body.');
        }
    }

    /** @param string|list<string> $to */
    public static function html(string|array $to, string $subject, string $html, array $context = []): self
    {
        return new self(to: $to, subject: $subject, html: $html, context: $context);
    }

    /** @param string|list<string> $to */
    public static function text(string|array $to, string $subject, string $text, array $context = []): self
    {
        return new self(to: $to, subject: $subject, text: $text, context: $context);
    }

    /** @param string|list<string> $to */
    public static function view(string|array $to, string $subject, string $view, array $data = [], array $context = []): self
    {
        return new self(to: $to, subject: $subject, view: $view, viewData: $data, context: $context);
    }

    /** @return array{source:string,path:string,disk:?string,name:?string,mime:?string} */
    public static function attachment(string $path, ?string $name = null, ?string $mime = null): array
    {
        return [
            'source' => 'path',
            'path' => $path,
            'disk' => null,
            'name' => $name,
            'mime' => $mime,
        ];
    }

    /** @return array{source:string,path:string,disk:?string,name:?string,mime:?string} */
    public static function storageAttachment(string $disk, string $path, ?string $name = null, ?string $mime = null): array
    {
        return [
            'source' => 'storage',
            'path' => $path,
            'disk' => $disk,
            'name' => $name,
            'mime' => $mime,
        ];
    }

    /** @param string|list<string> $addresses @return list<string> */
    private static function normalizeAddresses(string|array $addresses, bool $required = false): array
    {
        $values = is_array($addresses) ? $addresses : [$addresses];
        $normalized = [];

        foreach ($values as $address) {
            $address = trim((string) $address);
            if ($address === '') {
                continue;
            }
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('Invalid email address: '.$address);
            }
            $normalized[strtolower($address)] = $address;
        }

        $normalized = array_values($normalized);
        if ($required && $normalized === []) {
            throw new InvalidArgumentException('Email requires at least one recipient.');
        }

        return $normalized;
    }

    /** @return list<array{source:string,path:string,disk:?string,name:?string,mime:?string}> */
    private static function normalizeAttachments(array $attachments): array
    {
        $normalized = [];
        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                throw new InvalidArgumentException('Email attachments must be arrays created with EmailMessage::attachment().');
            }

            $path = trim((string) ($attachment['path'] ?? ''));
            if ($path === '') {
                throw new InvalidArgumentException('Email attachment path cannot be empty.');
            }

            $source = trim((string) ($attachment['source'] ?? 'path'));
            if (! in_array($source, ['path', 'storage'], true)) {
                throw new InvalidArgumentException('Email attachment source must be path or storage.');
            }

            $diskValue = trim((string) ($attachment['disk'] ?? ''));
            $disk = $diskValue !== '' ? $diskValue : null;
            if ($source === 'storage' && $disk === null) {
                throw new InvalidArgumentException('Storage email attachments require a disk name.');
            }

            $nameValue = trim((string) ($attachment['name'] ?? ''));
            $mimeValue = trim((string) ($attachment['mime'] ?? ''));

            $normalized[] = [
                'source' => $source,
                'path' => $path,
                'disk' => $disk,
                'name' => $nameValue !== '' ? $nameValue : null,
                'mime' => $mimeValue !== '' ? $mimeValue : null,
            ];
        }

        return $normalized;
    }
}
