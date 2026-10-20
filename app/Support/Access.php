<?php

namespace App\Support;

use App\Models\Meta;
use App\Models\MetaPermission;
use App\Models\User;
use App\Models\WfmStatusPermission;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may do what. Admins may do everything. A meta with no permission rows is open to
 * everyone; once it has rows, a user needs a role with the ability. Workflow statuses
 * work the same way, separately for moving records in (can_enter) and seeing them (can_see).
 */
class Access
{
    /** Stops with 403 unless the user is an admin. */
    public static function requireAdmin(?User $user): void
    {
        abort_unless($user?->isAdmin(), 403, 'Only administrators can do this.');
    }

    /** Whether the user may view, create, edit or delete through this meta. */
    public static function can(?User $user, Meta|int $meta, string $ability): bool
    {
        if ($user?->isAdmin()) {
            return true;
        }
        $metaId = $meta instanceof Meta ? $meta->id : $meta;
        $rows = MetaPermission::where('meta_id', $metaId);
        if (! (clone $rows)->exists()) {
            return true;
        }

        return $user !== null && $rows->whereIn('role_id', $user->roleIds())->where("can_{$ability}", true)->exists();
    }

    /** Stops with 403 unless the user may do this with the meta. */
    public static function require(?User $user, Meta|int $meta, string $ability): void
    {
        $verb = ['view' => 'open', 'create' => 'add records to', 'edit' => 'change records in', 'delete' => 'delete records from'][$ability] ?? $ability;
        abort_unless(self::can($user, $meta, $ability), 403, "You don't have permission to {$verb} this.");
    }

    /** Limits a metas query to the ones the user may view. */
    public static function viewable(Builder $query, ?User $user): Builder
    {
        if ($user?->isAdmin()) {
            return $query;
        }
        $roles = $user?->roleIds() ?? [];

        return $query->where(fn (Builder $q) => $q
            ->whereNotExists(fn ($p) => $p->from('meta_permissions')->whereColumn('meta_permissions.meta_id', 'metas.id'))
            ->orWhereExists(fn ($p) => $p->from('meta_permissions')->whereColumn('meta_permissions.meta_id', 'metas.id')->whereIn('role_id', $roles)->where('can_view', true)));
    }

    /**
     * For every meta that has permission rows, what this user may do with it. Metas not
     * listed are open. Admins get an empty list: everything is open to them.
     *
     * @return array<int, array{view: bool, create: bool, edit: bool, delete: bool}>
     */
    public static function metaRules(?User $user): array
    {
        if ($user?->isAdmin()) {
            return [];
        }
        $roles = $user?->roleIds() ?? [];
        $rules = [];
        foreach (MetaPermission::all()->groupBy('meta_id') as $metaId => $rows) {
            $mine = $rows->whereIn('role_id', $roles);
            $rules[$metaId] = array_combine(MetaPermission::ABILITIES, array_map(fn ($a) => $mine->contains("can_{$a}", true), MetaPermission::ABILITIES));
        }

        return $rules;
    }

    /** Whether the user may move a record into this status. */
    public static function canEnter(?User $user, int $statusId): bool
    {
        return self::statusAllows($user, $statusId, 'can_enter');
    }

    /**
     * Statuses whose records this user may not see: ones with can_see rows, none of them
     * for the user's roles.
     *
     * @return int[]
     */
    public static function hiddenStatuses(?User $user): array
    {
        if ($user?->isAdmin()) {
            return [];
        }
        $roles = $user?->roleIds() ?? [];

        return WfmStatusPermission::where('can_see', true)->get()->groupBy('status_id')
            ->reject(fn ($rows) => $rows->whereIn('role_id', $roles)->isNotEmpty())
            ->keys()->map(fn ($id) => (int) $id)->all();
    }

    private static function statusAllows(?User $user, int $statusId, string $column): bool
    {
        if ($user?->isAdmin()) {
            return true;
        }
        $rows = WfmStatusPermission::where('status_id', $statusId)->where($column, true);
        if (! (clone $rows)->exists()) {
            return true;
        }

        return $user !== null && $rows->whereIn('role_id', $user->roleIds())->exists();
    }
}
