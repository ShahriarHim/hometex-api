<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    /**
     * GET /activity-logs
     *
     * Filterable by causer (staff user), event (action), date range.
     * Returns the Spatie activity_log table — the audit trail.
     * Separate from user_activity_logs (security/session log).
     *
     * Query params:
     *   user_id   — filter by causer user id
     *   action    — event string e.g. "order.created"
     *   from      — Y-m-d
     *   to        — Y-m-d (inclusive)
     *   per_page  — default 30, max 100
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'user_id'  => 'nullable|integer|exists:users,id',
            'action'   => 'nullable|string|max:100',
            'from'     => 'nullable|date',
            'to'       => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $authUser  = $request->user('sanctum');
        $isAdmin   = $authUser?->hasRole('admin');

        $query = Activity::query()
            ->with('causer:id,first_name,last_name,email')
            ->orderByDesc('created_at');

        if ($isAdmin && $request->filled('user_id')) {
            $query->where('causer_type', User::class)
                  ->where('causer_id', $request->user_id);
        } elseif (!$isAdmin) {
            // Non-admins can only see their own activity
            $query->where('causer_type', User::class)
                  ->where('causer_id', $authUser->id);
        }

        if ($request->filled('action')) {
            $query->where('event', $request->action);
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $logs = $query->paginate($request->input('per_page', 30));

        return response()->json([
            'status' => 'success',
            'data'   => $logs->map(fn (Activity $log) => [
                'id'          => $log->id,
                'causer_id'   => $log->causer_id,
                'causer_name' => $log->causer
                    ? trim("{$log->causer->first_name} {$log->causer->last_name}")
                    : null,
                'causer_email' => $log->causer?->email,
                'action'       => $log->event,
                'description'  => $log->description,
                'properties'   => $log->properties,
                'log_name'     => $log->log_name,
                'created_at'   => $log->created_at?->toISOString(),
            ]),
            'meta' => [
                'total'        => $logs->total(),
                'per_page'     => $logs->perPage(),
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'from'         => $logs->firstItem(),
            ],
        ]);
    }

    /**
     * GET /staff/{user}/activity
     * Per-user audit trail. Supports ?action=, ?from=, ?to=, ?per_page=, ?page=
     */
    public function userActivity(Request $request, User $user): JsonResponse
    {
        $authUser = $request->user('sanctum');

        // Non-admins can only view their own activity
        if (!$authUser->hasRole('admin') && $authUser->id !== $user->id) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden.'], 403);
        }

        $query = Activity::where('causer_type', User::class)
            ->where('causer_id', $user->id)
            ->orderByDesc('created_at');

        if ($request->filled('action')) {
            $query->where('event', $request->input('action'));
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }

        $perPage = min(100, max(1, (int) $request->input('per_page', 30)));
        $logs    = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data'   => $logs->map(fn (Activity $log) => [
                'id'          => $log->id,
                'action'      => $log->event,
                'description' => $log->description,
                'properties'  => $log->properties,
                'created_at'  => $log->created_at?->toISOString(),
            ]),
            'meta' => [
                'total'        => $logs->total(),
                'per_page'     => $logs->perPage(),
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'from'         => $logs->firstItem(),
            ],
        ]);
    }

    /**
     * GET /activity-logs/actions
     * Distinct event strings for the filter dropdown in IMS.
     */
    public function actions(): JsonResponse
    {
        $actions = Activity::query()
            ->whereNotNull('event')
            ->select('event')
            ->distinct()
            ->orderBy('event')
            ->pluck('event');

        return response()->json(['status' => 'success', 'data' => $actions]);
    }

    /**
     * GET /activity-logs/staff
     * Staff list for the activity log overview — admin only.
     * Supports ?search= (name/email) and ?role= filter.
     */
    public function staffList(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', ''));
        $role   = trim((string) $request->input('role', ''));

        $query = User::query()
            ->with('roles:name')
            ->whereHas('roles', fn ($q) => $q->whereNotIn('name', ['customer', 'corporate']))
            ->select('id', 'first_name', 'last_name', 'email', 'status')
            ->selectRaw("(SELECT MAX(created_at) FROM activity_log WHERE causer_type = ? AND causer_id = users.id) as last_active_at", [User::class]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', '%' . $search . '%')
                  ->orWhere('last_name',  'like', '%' . $search . '%')
                  ->orWhere('email',      'like', '%' . $search . '%');
            });
        }

        if ($role !== '') {
            $query->whereHas('roles', fn ($q) => $q->where('name', $role));
        }

        $query->orderByRaw(
            "(SELECT MAX(created_at) FROM activity_log WHERE causer_type = ? AND causer_id = users.id) IS NULL ASC,
             (SELECT MAX(created_at) FROM activity_log WHERE causer_type = ? AND causer_id = users.id) DESC",
            [User::class, User::class]
        );

        $staff = $query->get()->map(fn (User $u) => [
            'id'            => $u->id,
            'name'          => trim("{$u->first_name} {$u->last_name}"),
            'email'         => $u->email,
            'status'        => $u->status,
            'roles'         => $u->roles->pluck('name')->values(),
            'last_active_at'=> $u->last_active_at,
        ]);

        return response()->json(['status' => 'success', 'data' => $staff]);
    }
}
