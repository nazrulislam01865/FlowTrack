<?php

namespace App\Services;

use App\Models\FlowJob;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MentionService
{
    private const DISPLAY_TOKEN_PATTERN = '/@\[([^\]\r\n]+)\]\((\d+)\)|(?<![\pL\pN._-])@([\pL\pN][\pL\pN._-]*\.(\d+))\b|(?<![\pL\pN._-])@([\pL\pN][\pL\pN._-]{0,80})/u';

    private ?Collection $renderUsers = null;
    private ?Collection $renderAliases = null;

    public function optionsForCreate(User $actor): Collection
    {
        return $this->activeUserOptions($actor);
    }

    public function optionsForJob(FlowJob $job, User $actor): Collection
    {
        return $this->activeUserOptions($actor);
    }

    public function optionsForTask(Task $task, User $actor): Collection
    {
        return $this->activeUserOptions($actor);
    }

    public function handle(User $user): string
    {
        return $this->baseHandle($user).'.'.$user->id;
    }

    public function userIdsFromText(?string $text): array
    {
        if (blank($text)) return [];

        $text = app(RichTextService::class)->plainText((string) $text);
        $ids = collect();

        // Canonical autocomplete token: @john.smith.42
        preg_match_all('/(?<![\pL\pN._-])@[\pL\pN][\pL\pN._-]*\.(\d+)\b/u', $text, $canonicalMatches);
        $ids = $ids->merge($canonicalMatches[1] ?? []);

        // Also accept an explicit portable token if content was pasted from another editor.
        preg_match_all('/@\[[^\]\r\n]+\]\((\d+)\)/u', $text, $portableMatches);
        $ids = $ids->merge($portableMatches[1] ?? []);

        // Accept a unique plain handle such as @john.smith. This keeps manual
        // typing useful while avoiding ambiguous notifications.
        preg_match_all('/(?<![\pL\pN._-])@([\pL\pN][\pL\pN._-]{0,80})/u', $text, $plainMatches);
        $plainTokens = collect($plainMatches[1] ?? [])
            ->map(fn ($token) => Str::lower(trim((string) $token, '._-')))
            ->filter()
            ->unique()
            ->values();

        $users = User::query()
            ->where('is_active', true)
            ->get(['id', 'name', 'email']);

        if ($plainTokens->isNotEmpty()) {
            $aliases = [];

            foreach ($users as $user) {
                foreach ($this->aliasesFor($user) as $alias) {
                    $aliases[$alias] ??= [];
                    $aliases[$alias][] = (int) $user->id;
                }
            }

            foreach ($plainTokens as $token) {
                $matchingIds = array_values(array_unique($aliases[$token] ?? []));
                if (count($matchingIds) === 1) $ids->push($matchingIds[0]);
            }
        }

        $candidateIds = $ids
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($candidateIds->isEmpty()) return [];

        $activeIds = $users->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $candidateIds
            ->filter(fn ($id) => in_array((int) $id, $activeIds, true))
            ->values()
            ->all();
    }

    public function render(?string $text): string
    {
        $text = (string) $text;
        $richText = app(RichTextService::class);

        if ($richText->isRich($text)) {
            $safeHtml = $richText->safeHtml($text) ?? '';
            return $this->renderTokens($safeHtml);
        }

        return nl2br($this->renderTokens(e($text)));
    }

    /**
     * Return notification/list-safe plain text with stored mention handles replaced
     * by the user's current display name. The stored content is left untouched so
     * mention parsing and deep links keep using the durable user-id token.
     */
    public function displayText(?string $text): string
    {
        $plain = app(RichTextService::class)->plainText((string) $text);
        if ($plain === '') return '';

        $users = $this->renderUsers();
        $aliases = $this->renderAliases();

        return preg_replace_callback(
            self::DISPLAY_TOKEN_PATTERN,
            function (array $match) use ($users, $aliases): string {
                $id = (int) (($match[2] ?? 0) ?: ($match[4] ?? 0));
                $user = $id > 0 ? $users->get($id) : null;

                if (!$user) {
                    $plainAlias = Str::lower(trim((string) ($match[5] ?? ''), '._-'));
                    $userId = $plainAlias !== '' ? $aliases->get($plainAlias) : null;
                    $user = $userId ? $users->get((int) $userId) : null;
                }

                return $user ? '@'.$user->name : $match[0];
            },
            $plain,
        ) ?? $plain;
    }

    private function renderTokens(string $safeContent): string
    {
        $users = $this->renderUsers();

        return preg_replace_callback(
            self::DISPLAY_TOKEN_PATTERN,
            function (array $match) use ($users): string {
                $id = (int) (($match[2] ?? 0) ?: ($match[4] ?? 0));
                $user = $id > 0 ? $users->get($id) : null;

                if (!$user) {
                    $plainAlias = Str::lower(trim((string) ($match[5] ?? ''), '._-'));
                    $userId = $plainAlias !== '' ? $this->renderAliases()->get($plainAlias) : null;
                    $user = $userId ? $users->get((int) $userId) : null;
                }

                if (!$user) return $match[0];

                return '<span class="ft-user-mention" title="'.e($user->name).'">@'.e($user->name).'</span>';
            },
            $safeContent,
        ) ?? $safeContent;
    }

    private function activeUserOptions(User $actor): Collection
    {
        return $this->formatUsers(
            User::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
        );
    }

    private function formatUsers(Collection $users): Collection
    {
        return $users->map(fn (User $user) => [
            'id' => (int) $user->id,
            'name' => $user->name,
            'handle' => $this->handle($user),
        ])->values();
    }

    private function renderUsers(): Collection
    {
        // Historical content should continue to show the human name even if a
        // previously-mentioned account is later deactivated. Creation/search
        // options still remain active-user-only in activeUserOptions().
        return $this->renderUsers ??= User::query()
            ->get(['id', 'name', 'email'])
            ->keyBy('id');
    }

    private function renderAliases(): Collection
    {
        if ($this->renderAliases) return $this->renderAliases;

        $aliases = [];
        foreach ($this->renderUsers() as $user) {
            foreach ($this->aliasesFor($user) as $alias) {
                $aliases[$alias] ??= [];
                $aliases[$alias][] = (int) $user->id;
            }
        }

        return $this->renderAliases = collect($aliases)
            ->filter(fn (array $ids) => count(array_unique($ids)) === 1)
            ->map(fn (array $ids) => (int) array_values(array_unique($ids))[0]);
    }

    private function baseHandle(User $user): string
    {
        $base = Str::of($user->name)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '.')
            ->trim('.')
            ->limit(45, '')
            ->toString();

        return $base !== '' ? $base : 'user';
    }

    private function aliasesFor(User $user): array
    {
        $base = Str::lower($this->baseHandle($user));
        $full = Str::lower($this->handle($user));
        $emailLocal = Str::lower(Str::before((string) $user->email, '@'));

        return collect([$base, $full, $emailLocal])
            ->map(fn ($alias) => trim((string) $alias, '._-'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
