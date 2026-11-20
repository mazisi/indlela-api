<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChangeEntryResource;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\RankKeyword;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\Site;
use App\Services\ChangeHistory\UndoChange;
use App\Support\ChangeHistory;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Settings › Change history (ported from workforce, plus Undo): every
 * audited change in the workspace, newest first. A user sees the changes on
 * the pages they may open, and for client records only their clients'.
 * Entries are never edited or deleted; Undo writes a new entry.
 */
class ChangeHistoryController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        [$query, $filters] = $this->filtered($request);

        return ChangeEntryResource::collection(
            $query->with('undoneBy')->latest('timestamp')->latest('id')->paginate($filters['per_page'] ?? 15)
        );
    }

    /** What the filters offer: the pages this user sees, and who made changes on them. */
    public function options(Request $request): JsonResponse
    {
        Gate::authorize('view_settings_change_history');
        $pages = ChangeHistory::pagesFor($request->user());

        $users = $this->visible($request, $pages)
            ->selectRaw('user_id, MAX(user_name) AS name')
            ->groupBy('user_id')
            ->get()
            ->map(fn (AuditLog $row) => ['id' => $row->user_id ?? 'system', 'name' => $row->user_id ? (trim((string) $row->name) ?: 'Unknown user') : 'System'])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return response()->json([
            'pages' => collect(ChangeHistory::pages())->only($pages)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values(),
            'users' => $users,
        ]);
    }

    /** Undo one entry: the entry back, marked undone (409 with error_type when it can't be). */
    public function undo(Request $request, int $entry, UndoChange $undo): ChangeEntryResource
    {
        Gate::authorize('view_settings_change_history');
        $log = $this->visible($request, ChangeHistory::pagesFor($request->user()))->findOrFail($entry);

        return ChangeEntryResource::make($undo->undo($log, $request->user()));
    }

    /** @return array{Builder, array<string, mixed>} */
    private function filtered(Request $request): array
    {
        Gate::authorize('view_settings_change_history');
        $pages = ChangeHistory::pagesFor($request->user());
        $filters = $request->validate([
            'page_key' => ['nullable', Rule::in($pages)],
            'user' => ['nullable', 'string', 'regex:/^(system|[0-9]+)$/'],
            'action' => ['nullable', Rule::in(['create', 'update', 'delete', 'restore'])],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ], [
            'page_key.in' => 'Choose one of the pages listed.',
            'to.after_or_equal' => 'The end date can\'t be before the start date.',
        ]);

        $query = $this->visible($request, isset($filters['page_key']) ? [$filters['page_key']] : $pages)
            ->when($filters['user'] ?? null, fn (Builder $q, string $user) => $user === 'system' ? $q->whereNull('user_id') : $q->where('user_id', (int) $user))
            ->when($filters['action'] ?? null, fn (Builder $q, string $action) => $q->where('action_type', strtoupper($action)))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('timestamp', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('timestamp', '<', Carbon::parse($to)->addDay()->toDateString()))
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
                $q->where(fn (Builder $w) => $w
                    ->whereRaw('LOWER(user_name) LIKE ?', [$like])
                    ->orWhereRaw("LOWER(COALESCE(new_values->>'_label', old_values->>'_label', new_values->>'name', old_values->>'name', '')) LIKE ?", [$like])
                    ->orWhereRaw('LOWER(CAST(field_changes AS TEXT)) LIKE ?', [$like]));
            });

        return [$query, $filters];
    }

    /**
     * This workspace's entries on these pages. A user limited to some clients
     * sees client records (clients, sites, keywords, reports, schedules) of
     * those clients only, deleted ones included.
     */
    private function visible(Request $request, array $pages): Builder
    {
        $query = AuditLog::forWorkspace($this->current->id())->whereIn('table_name', ChangeHistory::tablesOn($pages));
        $clientIds = $request->user()->accessibleClientIds();
        if ($clientIds === null) {
            return $query;
        }

        $sites = Site::withTrashed()->whereIn('client_id', $clientIds)->select('id');
        $ids = fn (Builder $records) => $records->selectRaw('CAST(id AS VARCHAR)');
        $scoped = [
            'clients' => Client::withTrashed()->whereKey($clientIds),
            'sites' => Site::withTrashed()->whereIn('client_id', $clientIds),
            'rank_keywords' => RankKeyword::withTrashed()->whereIn('site_id', $sites),
            'reports' => Report::withTrashed()->whereIn('client_id', $clientIds),
            'report_schedules' => ReportSchedule::query()->whereIn('site_id', $sites),
        ];

        return $query->where(function (Builder $w) use ($scoped, $ids) {
            $w->whereNotIn('table_name', array_keys($scoped));
            foreach ($scoped as $table => $records) {
                $w->orWhere(fn (Builder $t) => $t->where('table_name', $table)->whereIn('record_id', $ids($records)));
            }
        });
    }
}
