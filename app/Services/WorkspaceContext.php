<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Request-scoped workspace boundary introduced in Phase 9.
 *
 * FlowTrack remains compatible with its configured single-workspace deployment:
 * the configured workspace is authoritative when it exists. If it does not,
 * an authenticated user's active membership is preferred before the first
 * active workspace fallback. This lets future multi-workspace routing set a
 * request workspace explicitly without changing domain code again.
 */
final class WorkspaceContext
{
    private ?int $resolvedWorkspaceId = null;

    public function set(int $workspaceId): void
    {
        abort_unless($workspaceId > 0 && Workspace::query()->whereKey($workspaceId)->where('is_active', true)->exists(), 404);
        $this->resolvedWorkspaceId = $workspaceId;
    }

    public function id(?User $actor = null): int
    {
        if ($this->resolvedWorkspaceId !== null) return $this->resolvedWorkspaceId;

        $configured = (int) config('flowtrack.workspace_id', 1);
        $workspace = $configured > 0
            ? Workspace::query()->whereKey($configured)->where('is_active', true)->first()
            : null;

        if (! $workspace && $actor) {
            $workspace = Workspace::query()
                ->where('is_active', true)
                ->whereHas('memberships', fn (Builder $membership) => $membership
                    ->where('user_id', $actor->id)
                    ->where('status', 'active'))
                ->orderBy('id')
                ->first();
        }

        $workspace ??= Workspace::query()->where('is_active', true)->orderBy('id')->first();

        if (! $workspace) {
            $workspace = new Workspace();
            if ($configured > 0) $workspace->id = $configured;
            $workspace->name = 'FlowTrack';
            $workspace->slug = 'flowtrack';
            $workspace->timezone = (string) config('app.timezone', 'Asia/Dhaka');
            $workspace->default_currency = 'USD';
            $workspace->is_active = true;
            $workspace->save();
        }

        return $this->resolvedWorkspaceId = (int) $workspace->id;
    }

    public function scope(Builder|Relation $query, string $column = 'workspace_id', ?User $actor = null): Builder
    {
        $builder = $query instanceof Relation ? $query->getQuery() : $query;
        return $builder->where($column, $this->id($actor));
    }

    public function contains(?int $workspaceId, ?User $actor = null): bool
    {
        return $workspaceId !== null && $workspaceId > 0 && $workspaceId === $this->id($actor);
    }

    public function assertModel(Model $model, string $attribute = 'workspace_id', ?User $actor = null): void
    {
        abort_unless($this->contains((int) $model->getAttribute($attribute), $actor), 404);
    }
}
