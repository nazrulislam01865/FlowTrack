<?php

namespace App\Services\Email;

use App\Contracts\Email\EmailTransport;
use App\DTOs\Email\EmailMessage;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * e2a REST API adapter for FlowTrack's provider-neutral email service.
 *
 * The sender identity is the configured e2a agent/inbox. Application modules
 * continue to depend only on EmailService, so switching back to Laravel SMTP,
 * SES, Postmark, Resend, etc. remains an environment/configuration change.
 */
final class E2AEmailTransport implements EmailTransport
{
    private const MAX_ATTACHMENTS = 10;
    private const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;
    private const MAX_ATTACHMENTS_TOTAL_BYTES = 25 * 1024 * 1024;
    private const MAX_COMPOSED_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly ViewFactory $views,
        private readonly FilesystemFactory $filesystems,
    ) {}

    public function send(EmailMessage $message, ?string $idempotencyKey = null): void
    {
        $baseUrl = rtrim(trim((string) config('flowtrack_email.e2a.base_url', 'https://api.e2a.dev')), '/');
        $apiKey = trim((string) config('flowtrack_email.e2a.api_key'));
        $agentEmail = trim((string) config('flowtrack_email.e2a.agent_email'));
        $wait = trim((string) config('flowtrack_email.e2a.wait', 'sent'));
        $timeout = max(5, (int) config('flowtrack_email.e2a.timeout', 25));

        if ($baseUrl === '') {
            throw new RuntimeException('E2A base URL is not configured.');
        }
        if ($apiKey === '') {
            throw new RuntimeException('E2A API key is not configured.');
        }
        if ($agentEmail === '' || filter_var($agentEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('E2A agent email is missing or invalid.');
        }

        [$text, $html] = $this->renderBodies($message);
        $attachments = $this->attachments($message);

        $composedBytes = strlen($message->subject) + strlen($text) + strlen($html ?? '');
        foreach ($attachments as $attachment) {
            $composedBytes += (int) ($attachment['_decoded_bytes'] ?? 0);
        }
        if ($composedBytes > self::MAX_COMPOSED_BYTES) {
            throw new InvalidArgumentException('E2A composed message exceeds the 10 MiB limit.');
        }

        $payload = [
            'to' => $message->to,
            'subject' => $message->subject,
            'text' => $text,
        ];

        if ($html !== null && $html !== '') {
            $payload['html'] = $html;
        }
        if ($message->cc !== []) {
            $payload['cc'] = $message->cc;
        }
        if ($message->bcc !== []) {
            $payload['bcc'] = $message->bcc;
        }
        if ($message->replyTo !== []) {
            $payload['reply_to'] = count($message->replyTo) === 1
                ? $message->replyTo[0]
                : $message->replyTo;
        }
        if ($attachments !== []) {
            $payload['attachments'] = array_map(static function (array $attachment): array {
                unset($attachment['_decoded_bytes']);
                return $attachment;
            }, $attachments);
        }

        $url = $baseUrl.'/v1/agents/'.rawurlencode($agentEmail).'/messages';
        if ($wait !== '') {
            $url .= '?wait='.rawurlencode($wait);
        }

        $response = $this->http
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->withHeaders([
                'Idempotency-Key' => $idempotencyKey ?: (string) Str::uuid(),
            ])
            ->post($url, $payload);

        if (! $response->successful()) {
            $code = trim((string) $response->json('error.code'));
            $messageText = trim((string) $response->json('error.message'));
            $requestId = trim((string) $response->header('X-Request-Id'));

            $summary = 'E2A email request failed with HTTP '.$response->status();
            if ($code !== '') {
                $summary .= ' ['.$code.']';
            }
            if ($messageText !== '') {
                $summary .= ': '.Str::limit($messageText, 240, '...');
            }
            if ($requestId !== '') {
                $summary .= ' (request '.$requestId.')';
            }

            throw new RuntimeException($summary);
        }

        // e2a treats all of these as successful durable acceptance. In
        // particular, accepted/pending_review/scheduled must NOT be resent.
        $status = trim((string) $response->json('status'));
        if ($status !== '' && ! in_array($status, ['sent', 'accepted', 'pending_review', 'scheduled'], true)) {
            throw new RuntimeException('E2A returned an unexpected send status ['.$status.'].');
        }
    }

    /** @return array{0:string,1:?string} */
    private function renderBodies(EmailMessage $message): array
    {
        if ($message->view !== null) {
            $html = $this->views->make($message->view, $message->viewData)->render();
            return [$this->plainTextFromHtml($html), $html];
        }

        if ($message->html !== null) {
            $html = $message->html;
            $text = $message->text !== null && trim($message->text) !== ''
                ? $message->text
                : $this->plainTextFromHtml($html);

            return [$text, $html];
        }

        return [(string) $message->text, null];
    }

    private function plainTextFromHtml(string $html): string
    {
        $html = preg_replace('/<(style|script)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $html = preg_replace('/<br\s*\/?\s*>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<\/p\s*>/i', "\n\n", $html) ?? $html;
        $html = preg_replace('/<\/li\s*>/i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+\n/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /** @return list<array{filename:string,content_type:string,data:string,_decoded_bytes:int}> */
    private function attachments(EmailMessage $message): array
    {
        if (count($message->attachments) > self::MAX_ATTACHMENTS) {
            throw new InvalidArgumentException('E2A supports at most 10 attachments per email.');
        }

        $result = [];
        $totalBytes = 0;

        foreach ($message->attachments as $attachment) {
            if ($attachment['source'] === 'storage') {
                $disk = $this->filesystems->disk((string) $attachment['disk']);
                if (! $disk->exists($attachment['path'])) {
                    throw new InvalidArgumentException('Email storage attachment does not exist: '.$attachment['path']);
                }

                $size = (int) $disk->size($attachment['path']);
                $bytes = $disk->get($attachment['path']);
                $mime = $attachment['mime'] ?: ($disk->mimeType($attachment['path']) ?: 'application/octet-stream');
            } else {
                if (! is_file($attachment['path']) || ! is_readable($attachment['path'])) {
                    throw new InvalidArgumentException('Email attachment is missing or unreadable: '.$attachment['path']);
                }

                $size = (int) filesize($attachment['path']);
                $bytes = file_get_contents($attachment['path']);
                if ($bytes === false) {
                    throw new InvalidArgumentException('Email attachment could not be read: '.$attachment['path']);
                }
                $mime = $attachment['mime'] ?: (mime_content_type($attachment['path']) ?: 'application/octet-stream');
            }

            if ($size > self::MAX_ATTACHMENT_BYTES) {
                throw new InvalidArgumentException('E2A attachment exceeds the 10 MiB per-file limit: '.$attachment['path']);
            }

            $totalBytes += $size;
            if ($totalBytes > self::MAX_ATTACHMENTS_TOTAL_BYTES) {
                throw new InvalidArgumentException('E2A attachments exceed the 25 MiB combined limit.');
            }

            $result[] = [
                'filename' => $attachment['name'] ?: basename($attachment['path']),
                'content_type' => (string) $mime,
                'data' => base64_encode($bytes),
                '_decoded_bytes' => $size,
            ];
        }

        return $result;
    }
}
