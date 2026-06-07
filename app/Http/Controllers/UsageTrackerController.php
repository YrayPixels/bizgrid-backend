<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UsageTrackerController extends Controller
{
    private function recordUserActivityEvent(?string $userKey, string $eventType, ?string $eventName = null, ?array $metadata = null): void
    {
        if ($userKey === null || $userKey === '') {
            return;
        }

        if (! Schema::hasTable('user_activity_events')) {
            return;
        }

        $key = mb_substr($userKey, 0, 191);

        DB::table('user_activity_events')->insert([
            'user_key' => $key,
            'event_type' => mb_substr($eventType, 0, 64),
            'event_name' => $eventName !== null ? mb_substr($eventName, 0, 191) : null,
            'metadata' => $metadata !== null ? json_encode($metadata) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function get_tracking_data()
    {
        //group the calls by name and sum the calls
        $button_clicks_by_date = DB::table('button_clicks')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('sum(clicks) as total_clicks'))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->get();

        $button_clicks_by_button_name = DB::table('button_clicks')
            ->select(DB::raw('button_name'), DB::raw('sum(clicks) as total_clicks'))
            ->groupBy('button_name')
            ->get();

        $tool_calls_by_date = DB::table('tool_calls')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('sum(calls) as total_calls'))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->get();

        $tool_calls_by_tool_name = DB::table('tool_calls')
            ->select(DB::raw('tool_name'), DB::raw('sum(calls) as total_calls'))
            ->groupBy('tool_name')
            ->get();


        $app_open_count = DB::table('app_open_count')->get();

        $app_open_count_by_date = DB::table('app_open_count')
            ->select(DB::raw('DATE(date) as date'), DB::raw('sum(open_count) as total_open_count'))
            ->groupBy(DB::raw('DATE(date)'))
            ->get();

        $page_open_count = DB::table('page_open_count')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('sum(open_count) as total_open_count'))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->get();

        $page_open_count_by_page_name = DB::table('page_open_count')
            ->select(DB::raw('page_name'), DB::raw('sum(open_count) as total_open_count'))
            ->groupBy('page_name')
            ->get();


        $token_usage_by_date = DB::table('token_usage')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('sum(usage_count) as total_usage'))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->get();

        $token_usage_by_token_name = DB::table('token_usage')
            ->select(DB::raw('token_name'), DB::raw('sum(usage_count) as total_usage'))
            ->groupBy('token_name')
            ->get();

        $data = [
            'button_clicks_by_date' => $button_clicks_by_date,
            'button_clicks_by_button_name' => $button_clicks_by_button_name,
            'tool_calls_by_date' => $tool_calls_by_date,
            'tool_calls_by_tool_name' => $tool_calls_by_tool_name,
            'app_open_count_by_date' => $app_open_count_by_date,
            'page_open_count_by_date' => $page_open_count,
            'page_open_count_by_page_name' => $page_open_count_by_page_name,
            'token_usage_by_date' => $token_usage_by_date,
            'token_usage_by_token_name' => $token_usage_by_token_name,
        ];

        return response()->json($data);
    }

    /**
     * Optional generic activity ingest (wallet, device id, etc.).
     */
    public function user_activity(Request $request)
    {
        $validated = $request->validate([
            'user_key' => 'required|string|max:191',
            'event_type' => 'required|string|max:64',
            'event_name' => 'nullable|string|max:191',
            'metadata' => 'nullable|array',
        ]);

        if (! Schema::hasTable('user_activity_events')) {
            return response()->json(['message' => 'Activity storage not available'], 503);
        }

        $this->recordUserActivityEvent(
            $validated['user_key'],
            $validated['event_type'],
            $validated['event_name'] ?? null,
            $validated['metadata'] ?? null
        );

        return response()->json(['message' => 'Activity recorded']);
    }

    /**
     * MAU / DAU, time series, page journey (admin, Sanctum).
     */
    public function get_engagement_analytics(Request $request)
    {
        if (! Schema::hasTable('user_activity_events')) {
            return response()->json([
                'available' => false,
                'message' => 'Run migrations to enable user_activity_events.',
                'dau_series' => [],
                'summary' => null,
                'journey_edges' => [],
                'journey_popular_pages' => [],
            ]);
        }

        $days = min(90, max(7, (int) $request->query('days', 30)));
        $since = Carbon::now()->subDays($days)->startOfDay();

        $eventDauRows = DB::table('user_activity_events')
            ->selectRaw('DATE(created_at) as date')
            ->selectRaw('COUNT(DISTINCT user_key) as active_users')
            ->where('created_at', '>=', $since)
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $eventTotals = DB::table('user_activity_events')
            ->where('created_at', '>=', $since)
            ->selectRaw('COUNT(DISTINCT user_key) as mau_window')
            ->selectRaw('COUNT(*) as event_count')
            ->first();

        $prevSince = Carbon::now()->subDays($days * 2)->startOfDay();
        $prevUntil = $since->copy();

        $mauPrevWindow = DB::table('user_activity_events')
            ->where('created_at', '>=', $prevSince)
            ->where('created_at', '<', $prevUntil)
            ->selectRaw('COUNT(DISTINCT user_key) as c')
            ->value('c');

        $dauToday = (int) DB::table('user_activity_events')
            ->whereDate('created_at', Carbon::today())
            ->selectRaw('COUNT(DISTINCT user_key) as c')
            ->value('c');

        $wau = (int) DB::table('user_activity_events')
            ->where('created_at', '>=', Carbon::now()->subDays(7)->startOfDay())
            ->selectRaw('COUNT(DISTINCT user_key) as c')
            ->value('c');

        $mauRolling = (int) ($eventTotals->mau_window ?? 0);

        $walletDauRows = collect();
        if (Schema::hasTable('addressbook')) {
            $walletDauRows = DB::table('addressbook')
                ->selectRaw('DATE(updated_at) as date')
                ->selectRaw('COUNT(*) as active_users')
                ->where('updated_at', '>=', $since)
                ->groupByRaw('DATE(updated_at)')
                ->orderBy('date')
                ->get()
                ->keyBy('date');
        }

        $useWalletFallback = ($eventTotals->event_count ?? 0) < 1;

        $seriesMap = $useWalletFallback ? $walletDauRows : $eventDauRows;
        $dauSeries = [];
        for ($i = 0; $i <= $days; $i++) {
            $d = $since->copy()->addDays($i)->format('Y-m-d');
            $dauSeries[] = [
                'date' => $d,
                'active_users' => (int) ($seriesMap[$d]->active_users ?? 0),
            ];
        }

        $dauSeriesSource = $useWalletFallback ? 'wallet_sync' : 'client_events';

        $walletMau = 0;
        $walletWau = 0;
        $walletMauPrev = 0;
        $walletDauToday = 0;
        if (Schema::hasTable('addressbook')) {
            $walletMau = DB::table('addressbook')
                ->where('updated_at', '>=', $since)
                ->count();
            $walletWau = DB::table('addressbook')
                ->where('updated_at', '>=', Carbon::now()->subDays(7)->startOfDay())
                ->count();
            $walletMauPrev = DB::table('addressbook')
                ->where('updated_at', '>=', $prevSince)
                ->where('updated_at', '<', $prevUntil)
                ->count();
            $walletDauToday = DB::table('addressbook')
                ->whereDate('updated_at', Carbon::today())
                ->count();
        }

        $journeyPopular = DB::table('user_activity_events')
            ->select('event_name', DB::raw('COUNT(*) as views'))
            ->where('created_at', '>=', $since)
            ->where('event_type', 'page_view')
            ->whereNotNull('event_name')
            ->groupBy('event_name')
            ->orderByDesc('views')
            ->limit(12)
            ->get();

        $journeyEdges = $this->buildPageJourneyEdges($since);

        return response()->json([
            'available' => true,
            'dau_series' => $dauSeries,
            'dau_series_source' => $dauSeriesSource,
            'dau_series_note' => $useWalletFallback
                ? 'Based on addressbook updated_at (API / sync activity). Pass user_key from the app for client-level DAU.'
                : 'Based on distinct user_key in user_activity_events.',
            'summary' => [
                'dau_today' => $useWalletFallback ? (int) $walletDauToday : $dauToday,
                'wau' => $useWalletFallback ? (int) $walletWau : $wau,
                'mau_rolling' => $useWalletFallback ? (int) $walletMau : (int) $mauRolling,
                'mau_prev_window' => $useWalletFallback ? (int) $walletMauPrev : (int) $mauPrevWindow,
                'stickiness' => $useWalletFallback
                    ? ($walletMau > 0 ? round(($walletDauToday / $walletMau) * 100, 1) : null)
                    : ($mauRolling > 0 ? round(($dauToday / $mauRolling) * 100, 1) : null),
            ],
            'journey_edges' => $journeyEdges,
            'journey_popular_pages' => $journeyPopular,
        ]);
    }

    private function buildPageJourneyEdges(Carbon $since): array
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            return [];
        }

        try {
            $bindings = [$since->toDateTimeString()];

            $sql = '
                SELECT from_page, to_page, COUNT(*) AS value
                FROM (
                    SELECT
                        LAG(event_name) OVER (PARTITION BY user_key ORDER BY created_at) AS from_page,
                        event_name AS to_page
                    FROM user_activity_events
                    WHERE event_type = \'page_view\'
                      AND event_name IS NOT NULL
                      AND created_at >= ?
                ) t
                WHERE from_page IS NOT NULL AND from_page <> to_page
                GROUP BY from_page, to_page
                ORDER BY value DESC
                LIMIT 24
            ';

            $rows = DB::select($sql, $bindings);

            return array_map(static function ($row) {
                return [
                    'source' => $row->from_page,
                    'target' => $row->to_page,
                    'value' => (int) $row->value,
                ];
            }, $rows);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    public function button_clicks(Request $request)
    {
        $button_name = $request->button_name;
        $clicks = $request->clicks;

        //check if button_name is already in the database and it has been recorded for that day
        $today = date('Y-m-d');

        $button = DB::table('button_clicks')->where('button_name', $button_name)->whereDate('created_at', $today)->first();

        if ($button) {

            $data = [
                'button_name' => $button_name,
                'clicks' => $button->clicks + 1,
                'updated_at' => now(),
            ];

            $update = DB::table('button_clicks')->where('button_name', $button_name)->whereDate('created_at', $today)->update($data);

            if ($update) {
                return response()->json(['message' => 'Button click tracked successfully']);
            } else {
                return response()->json(['message' => 'Button click not tracked']);
            }
        } else {

            $data = [
                'button_name' => $button_name,
                'clicks' => $clicks,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $save = DB::table('button_clicks')->insert($data);

            if ($save) {
                return response()->json(['message' => 'Button click tracked successfully']);
            } else {
                return response()->json(['message' => 'Button click not tracked']);
            }
        }
    }
    public function tool_calls(Request $request)
    {
        $tool_name = $request->tool_name;
        $calls = $request->calls;

        //check if tool_name is already in the database and it has been recorded for that day
        $today = date('Y-m-d');

        $tool = DB::table('tool_calls')->where('tool_name', $tool_name)->whereDate('created_at', $today)->first();

        if ($tool) {
            $data = [
                'tool_name' => $tool_name,
                'calls' => $tool->calls + 1,
                'updated_at' => now(),
            ];

            $update = DB::table('tool_calls')->where('tool_name', $tool_name)->whereDate('created_at', $today)->update($data);

            if ($update) {
                return response()->json(['message' => 'Tool call tracked successfully']);
            } else {
                return response()->json(['message' => 'Tool call not tracked']);
            }
        } else {
            $data = [
                'tool_name' => $tool_name,
                'calls' => $calls,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $save = DB::table('tool_calls')->insert($data);

            if ($save) {
                return response()->json(['message' => 'Tool call tracked successfully']);
            } else {
                return response()->json(['message' => 'Tool call not tracked']);
            }
        }
    }

    public function app_open_count(Request $request)
    {
        $today = date('Y-m-d');
        $app_open_count = DB::table('app_open_count')->whereDate('date', $today)->first();

        if ($app_open_count) {
            $data = [
                'open_count' => $app_open_count->open_count + 1,
                'updated_at' => now(),
            ];

            $update = DB::table('app_open_count')->whereDate('date', $today)->update($data);

            if ($update) {
                $this->recordUserActivityEvent($request->input('user_key'), 'app_open');

                return response()->json(['message' => 'App open count tracked successfully']);
            } else {
                return response()->json(['message' => 'App open count not tracked']);
            }
        } else {
            $data = [
                'date' => $today,
                'open_count' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $save = DB::table('app_open_count')->insert($data);

            if ($save) {
                $this->recordUserActivityEvent($request->input('user_key'), 'app_open');

                return response()->json(['message' => 'App open count tracked successfully']);
            } else {
                return response()->json(['message' => 'App open count not tracked']);
            }
        }
    }

    public function page_open_count(Request $request)
    {
        $page_name = $request->page_name;

        //check if page_name is already in the database and it has been recorded for that day
        $today = date('Y-m-d');

        $page = DB::table('page_open_count')->where('page_name', $page_name)->whereDate('created_at', $today)->first();

        if ($page) {

            $data = [
                'page_name' => $page_name,
                'open_count' => $page->open_count + 1,
                'updated_at' => now(),
            ];

            $update = DB::table('page_open_count')->where('page_name', $page_name)->whereDate('created_at', $today)->update($data);

            if ($update) {
                $this->recordUserActivityEvent($request->input('user_key'), 'page_view', $page_name);

                return response()->json(['message' => 'Page open count tracked successfully']);
            } else {
                return response()->json(['message' => 'Page open count not tracked']);
            }
        } else {
            $data = [
                'page_name' => $page_name,
                'open_count' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $save = DB::table('page_open_count')->insert($data);

            if ($save) {
                $this->recordUserActivityEvent($request->input('user_key'), 'page_view', $page_name);

                return response()->json(['message' => 'Page open count tracked successfully']);
            } else {
                return response()->json(['message' => 'Page open count not tracked']);
            }
        }
    }
}
