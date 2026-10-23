<?php

namespace App\Http\Middleware;

use App\Models\Meta;
use App\Models\MetaAction;
use App\Models\MetaParam;
use App\Models\WfmStatus;
use App\Models\WfmTransition;
use App\Support\MetaVersions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * On the routes that change a meta or its parts: the meta is read before the request, and
 * when the request went through and changed it, how it was is kept as a version (see
 * MetaVersions::keepBefore). So a change saved over someone else's can be undone.
 */
class SavesMetaVersion
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $metaId = $this->metaId($request);
        $before = $metaId ? MetaVersions::snapshot($metaId) : null;

        $response = $next($request);

        if ($before !== null && $response->isSuccessful()) {
            try {
                MetaVersions::keepBefore($before, $request->user());
            } catch (Throwable $e) {
                // The change itself is saved; a version that could not be kept must not undo it.
                report($e);
            }
        }

        return $response;
    }

    /** The meta the route changes: itself, or the one its param, action, status or transition belongs to. */
    private function metaId(Request $request): ?int
    {
        $route = $request->route();

        return match (true) {
            ($meta = $route?->parameter('meta')) instanceof Meta => $meta->id,
            ($param = $route?->parameter('param')) instanceof MetaParam => $param->main_meta_id,
            ($action = $route?->parameter('action')) instanceof MetaAction => $action->grid_meta_id,
            ($status = $route?->parameter('status')) instanceof WfmStatus => $status->workflow_meta_id,
            ($transition = $route?->parameter('transition')) instanceof WfmTransition => $transition->workflow_meta_id,
            default => null,
        };
    }
}
