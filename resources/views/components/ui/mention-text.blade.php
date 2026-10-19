@props(['text'])
{!! app(\App\Services\MentionService::class)->render($text) !!}
