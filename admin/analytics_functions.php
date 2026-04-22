<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../api/helpers.php';

const ANALYTICS_STATUS_COLORS = [
    'CONFIRMED' => '#1f7a45',
    'WAITING' => '#b26a00',
    'CANCELLED' => '#b83232',
];

const ANALYTICS_CHART_COLORS = [
    'primary' => '#0f6c8d',
    'primary_soft' => 'rgba(15, 108, 141, 0.12)',
    'primary_soft_strong' => 'rgba(15, 108, 141, 0.18)',
    'success' => '#1f7a45',
    'success_soft' => 'rgba(31, 122, 69, 0.12)',
    'warning' => '#b26a00',
    'warning_soft' => 'rgba(178, 106, 0, 0.12)',
    'danger' => '#b83232',
    'danger_soft' => 'rgba(184, 50, 50, 0.12)',
    'muted' => '#5d7680',
    'grid' => 'rgba(23, 50, 61, 0.08)',
    'surface' => '#ffffff',
];

const ANALYTICS_LINE_BOOKINGS_COLOR = '#0f6c8d';
const ANALYTICS_LINE_CANCELLATIONS_COLOR = '#b83232';
const ANALYTICS_BAR_COLOR = 'rgba(15, 108, 141, 0.82)';
const ANALYTICS_BAR_HOVER_COLOR = 'rgba(15, 108, 141, 0.96)';
const ANALYTICS_FALLBACK_MESSAGE = 'Analytics temporarily unavailable';

function analytics_is_debug_mode(): bool
{
    return defined('DEBUG_MODE') && DEBUG_MODE === true;
}

function analytics_public_error_message(?string $message = null): string
{
    if (analytics_is_debug_mode()) {
        $message = trim((string) $message);
        return $message !== '' ? $message : ANALYTICS_FALLBACK_MESSAGE;
    }

    return ANALYTICS_FALLBACK_MESSAGE;
}

function analytics_session_snapshot_user(): ?array
{
    startSessionIfNeeded();

    $sessionUser = $_SESSION['user'] ?? [];
    $userId = (int) ($_SESSION['user_id'] ?? ($sessionUser['id'] ?? 0));
    $role = strtoupper((string) ($_SESSION['role'] ?? ($sessionUser['role'] ?? 'USER')));

    if ($userId <= 0) {
        return null;
    }

    return [
        'id' => $userId,
        'name' => sanitizeString((string) ($sessionUser['name'] ?? '')),
        'email' => sanitizeString((string) ($sessionUser['email'] ?? '')),
        'phone' => isset($sessionUser['phone']) ? sanitizeString((string) $sessionUser['phone']) : null,
        'role' => $role,
        'status' => strtoupper((string) ($sessionUser['status'] ?? 'ACTIVE')),
    ];
}

function analytics_require_admin_session(): array
{
    startSessionIfNeeded();

    $sessionUser = analytics_session_snapshot_user();
    $authLookupFailed = false;

    if (function_exists('currentUser')) {
        try {
            $currentUser = currentUser();
        } catch (Throwable) {
            $authLookupFailed = true;
        }

        if (is_array($currentUser ?? null)) {
            if (($currentUser['role'] ?? 'USER') === 'ADMIN') {
                return $currentUser;
            }

            if (!headers_sent()) {
                header('Location: index.html?auth=admin_required');
            }

            exit;
        }
    }

    if ($authLookupFailed && $sessionUser !== null && ($sessionUser['role'] ?? 'USER') === 'ADMIN') {
        return $sessionUser;
    }

    if (!headers_sent()) {
        header('Location: index.html?auth=admin_required');
    }

    exit;
}

function analytics_normalize_exception_message(Throwable $exception): string
{
    $message = trim((string) $exception->getMessage());
    if ($message === '') {
        return ANALYTICS_FALLBACK_MESSAGE;
    }

    $message = preg_replace('/SQLSTATE\\[[^\\]]+\\]:\\s*/', '', $message) ?? $message;
    $message = preg_replace('/\\s+/', ' ', $message) ?? $message;

    if (function_exists('mb_substr')) {
        $message = mb_substr($message, 0, 180);
    } elseif (strlen($message) > 180) {
        $message = substr($message, 0, 177) . '...';
    }

    return $message !== '' ? $message : ANALYTICS_FALLBACK_MESSAGE;
}

function analytics_log_issue(string $scope, Throwable $exception): void
{
    if (function_exists('logSystemEvent')) {
        logSystemEvent('analytics.issue', [
            'scope' => $scope,
            'message' => analytics_normalize_exception_message($exception),
        ]);
    }
}

function analytics_safe_fetch(string $scope, callable $callback, mixed $fallback, array &$warnings): mixed
{
    try {
        return $callback();
    } catch (Throwable $exception) {
        $warnings[] = $scope . ' unavailable.';
        analytics_log_issue($scope, $exception);

        return $fallback;
    }
}

function analytics_format_count(int $value): string
{
    return number_format(max(0, $value), 0, '.', ',');
}

function analytics_format_percentage(float $value): string
{
    return rtrim(rtrim(number_format(max(0.0, $value), 1, '.', ''), '0'), '.') . '%';
}

function analytics_render_icon_svg(string $icon): string
{
    $icons = [
        'total' => '
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <rect x="4" y="4" width="7" height="7" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"></rect>
                <rect x="13" y="4" width="7" height="7" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"></rect>
                <rect x="4" y="13" width="7" height="7" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"></rect>
                <rect x="13" y="13" width="7" height="7" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"></rect>
            </svg>',
        'confirmed' => '
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <circle cx="12" cy="12" r="8.25" fill="none" stroke="currentColor" stroke-width="1.8"></circle>
                <path d="M8.4 12.3l2.2 2.2 4.8-5.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"></path>
            </svg>',
        'waiting' => '
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <circle cx="12" cy="12" r="8.25" fill="none" stroke="currentColor" stroke-width="1.8"></circle>
                <path d="M12 7.2v5.2l3.2 2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"></path>
            </svg>',
        'cancelled' => '
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <circle cx="12" cy="12" r="8.25" fill="none" stroke="currentColor" stroke-width="1.8"></circle>
                <path d="M9 9l6 6M15 9l-6 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"></path>
            </svg>',
    ];

    return $icons[$icon] ?? $icons['total'];
}

function analytics_format_datetime_label(?string $value): string
{
    $normalized = trim((string) $value);
    if ($normalized === '') {
        return '';
    }

    try {
        $dateTime = new DateTimeImmutable($normalized);
    } catch (Throwable) {
        return $normalized;
    }

    return $dateTime->format('d M, h:i A');
}

function analytics_default_status_counts(): array
{
    return [
        'total_bookings' => 0,
        'confirmed_bookings' => 0,
        'waiting_bookings' => 0,
        'cancelled_bookings' => 0,
    ];
}

function analytics_build_overview(array $counts): array
{
    $overview = analytics_default_status_counts();

    foreach ($overview as $key => $value) {
        $overview[$key] = max(0, (int) ($counts[$key] ?? $value));
    }

    $total = max(0, $overview['total_bookings']);
    $confirmed = max(0, $overview['confirmed_bookings']);
    $waiting = max(0, $overview['waiting_bookings']);
    $cancelled = max(0, $overview['cancelled_bookings']);
    $denominator = max(1, $total);

    $overview['confirmed_share'] = round(($confirmed / $denominator) * 100, 1);
    $overview['waiting_share'] = round(($waiting / $denominator) * 100, 1);
    $overview['cancelled_share'] = round(($cancelled / $denominator) * 100, 1);
    $overview['confirmed_waiting_ratio_label'] = $confirmed . ':' . $waiting;
    $overview['status_mix_label'] = sprintf('%d confirmed, %d waiting, %d cancelled', $confirmed, $waiting, $cancelled);

    return $overview;
}

function analytics_card_definition(
    string $key,
    string $label,
    int $value,
    string $meta,
    string $tone,
    string $icon
): array {
    return [
        'key' => $key,
        'label' => $label,
        'value' => max(0, $value),
        'value_label' => analytics_format_count($value),
        'meta' => $meta,
        'tone' => $tone,
        'icon' => analytics_render_icon_svg($icon),
    ];
}

function analytics_build_kpi_cards(array $analytics): array
{
    $overview = $analytics['overview'] ?? analytics_build_overview([]);

    return [
        analytics_card_definition(
            'total_bookings',
            'Total Bookings',
            (int) ($overview['total_bookings'] ?? 0),
            'All reservations',
            'primary',
            'total'
        ),
        analytics_card_definition(
            'confirmed_bookings',
            'Confirmed Tickets',
            (int) ($overview['confirmed_bookings'] ?? 0),
            'Seats issued',
            'success',
            'confirmed'
        ),
        analytics_card_definition(
            'waiting_bookings',
            'Waiting List Tickets',
            (int) ($overview['waiting_bookings'] ?? 0),
            'Pending confirmation',
            'warning',
            'waiting'
        ),
        analytics_card_definition(
            'cancelled_bookings',
            'Cancelled Tickets',
            (int) ($overview['cancelled_bookings'] ?? 0),
            'Voided reservations',
            'danger',
            'cancelled'
        ),
    ];
}

function analytics_build_preview_cards(array $analytics): array
{
    $overview = $analytics['overview'] ?? analytics_build_overview([]);
    $trendSummary = $analytics['trend_summary'] ?? [];
    $trendDirection = (string) ($trendSummary['direction'] ?? '');
    $trendValue = (string) ($trendSummary['label'] ?? '');

    return [
        [
            'label' => 'Total Bookings',
            'value' => (int) ($overview['total_bookings'] ?? 0),
            'meta' => $trendValue !== '' ? 'Last 7 days ' . $trendValue : 'All reservations',
            'trend' => $trendDirection,
            'tone' => 'primary',
            'icon' => analytics_render_icon_svg('total'),
        ],
        [
            'label' => 'Confirmed',
            'value' => (int) ($overview['confirmed_bookings'] ?? 0),
            'meta' => 'Confirmed seats',
            'tone' => 'success',
            'icon' => analytics_render_icon_svg('confirmed'),
        ],
        [
            'label' => 'Waiting',
            'value' => (int) ($overview['waiting_bookings'] ?? 0),
            'meta' => 'Waiting list',
            'tone' => 'warning',
            'icon' => analytics_render_icon_svg('waiting'),
        ],
        [
            'label' => 'Cancelled',
            'value' => (int) ($overview['cancelled_bookings'] ?? 0),
            'meta' => 'Voided reservations',
            'tone' => 'danger',
            'icon' => analytics_render_icon_svg('cancelled'),
        ],
    ];
}

function analytics_build_status_pie_chart_data(array $overview): array
{
    return [
        'labels' => ['Confirmed', 'Waiting'],
        'values' => [
            (int) ($overview['confirmed_bookings'] ?? 0),
            (int) ($overview['waiting_bookings'] ?? 0),
        ],
        'colors' => [
            ANALYTICS_STATUS_COLORS['CONFIRMED'],
            ANALYTICS_STATUS_COLORS['WAITING'],
        ],
    ];
}

function analytics_build_status_distribution_chart_data(array $overview): array
{
    return [
        'labels' => ['Confirmed', 'Waiting', 'Cancelled'],
        'values' => [
            (int) ($overview['confirmed_bookings'] ?? 0),
            (int) ($overview['waiting_bookings'] ?? 0),
            (int) ($overview['cancelled_bookings'] ?? 0),
        ],
        'colors' => [
            ANALYTICS_STATUS_COLORS['CONFIRMED'],
            ANALYTICS_STATUS_COLORS['WAITING'],
            ANALYTICS_STATUS_COLORS['CANCELLED'],
        ],
    ];
}

function analytics_build_top_routes_chart_data(array $topRoutes): array
{
    $labels = [];
    $values = [];

    foreach ($topRoutes as $route) {
        $labels[] = (string) ($route['route_label'] ?? '');
        $values[] = (int) ($route['booking_count'] ?? 0);
    }

    return [
        'labels' => $labels,
        'values' => $values,
        'color' => ANALYTICS_BAR_COLOR,
        'hover_color' => ANALYTICS_BAR_HOVER_COLOR,
    ];
}

function analytics_build_date_range_bounds(int $days): array
{
    $days = max(1, $days);
    $end = new DateTimeImmutable('today');
    $start = $end->sub(new DateInterval('P' . max(0, $days - 1) . 'D'));

    return [$start, $end];
}

function analytics_build_daily_window(array $dailyMap, int $days): array
{
    [$start, $end] = analytics_build_date_range_bounds($days);
    $cursor = $start;
    $interval = new DateInterval('P1D');
    $labels = [];
    $bookings = [];
    $cancellations = [];

    while ($cursor <= $end) {
        $dayKey = $cursor->format('Y-m-d');
        $labels[] = $cursor->format('d M');
        $bookings[] = (int) ($dailyMap[$dayKey]['bookings'] ?? 0);
        $cancellations[] = (int) ($dailyMap[$dayKey]['cancellations'] ?? 0);
        $cursor = $cursor->add($interval);
    }

    return [
        'labels' => $labels,
        'bookings' => $bookings,
        'cancellations' => $cancellations,
        'total_bookings' => array_sum($bookings),
        'total_cancellations' => array_sum($cancellations),
        'start_date' => $start->format('Y-m-d'),
        'end_date' => $end->format('Y-m-d'),
    ];
}

function analytics_build_booking_trend_windows(array $dailyMap, int $longWindowDays = 30): array
{
    $longWindowDays = max(1, $longWindowDays);
    $longWindow = analytics_build_daily_window($dailyMap, $longWindowDays);
    $shortWindowDays = min(7, $longWindowDays);
    $shortWindow = analytics_build_daily_window($dailyMap, $shortWindowDays);

    $windows = [
        (string) $shortWindowDays => $shortWindow,
    ];

    if ($shortWindowDays !== $longWindowDays) {
        $windows[(string) $longWindowDays] = $longWindow;
    }

    return [
        'default_window' => $shortWindowDays,
        'windows' => $windows,
        'series' => [
            'bookings' => [
                'label' => 'Bookings',
                'color' => ANALYTICS_LINE_BOOKINGS_COLOR,
            ],
            'cancellations' => [
                'label' => 'Cancellations',
                'color' => ANALYTICS_LINE_CANCELLATIONS_COLOR,
            ],
        ],
    ];
}

function analytics_build_trend_summary(array $trendWindows): array
{
    $windows = $trendWindows['windows'] ?? [];
    $sevenDay = $windows['7'] ?? null;
    $thirtyDay = $windows['30'] ?? null;

    if (!is_array($sevenDay) || !is_array($thirtyDay)) {
        return [
            'direction' => 'flat',
            'label' => '',
            'delta_percent' => 0.0,
        ];
    }

    $lastSeven = array_sum(array_map('intval', $sevenDay['bookings'] ?? []));
    $prevSeven = array_sum(array_map('intval', array_slice($thirtyDay['bookings'] ?? [], 0, max(0, count($thirtyDay['bookings'] ?? []) - 7))));
    $prevWindow = array_sum(array_map('intval', array_slice($thirtyDay['bookings'] ?? [], max(0, count($thirtyDay['bookings'] ?? []) - 14), 7)));

    $baseline = $prevWindow > 0 ? $prevWindow : $prevSeven;
    if ($baseline <= 0) {
        return [
            'direction' => $lastSeven > 0 ? 'up' : 'flat',
            'label' => $lastSeven > 0 ? 'New activity' : '',
            'delta_percent' => 0.0,
        ];
    }

    $deltaPercent = round((($lastSeven - $baseline) / $baseline) * 100, 1);
    $direction = $deltaPercent > 0 ? 'up' : ($deltaPercent < 0 ? 'down' : 'flat');
    $label = ($deltaPercent > 0 ? '+' : '') . rtrim(rtrim(number_format($deltaPercent, 1, '.', ''), '0'), '.') . '%';

    return [
        'direction' => $direction,
        'label' => $label,
        'delta_percent' => $deltaPercent,
    ];
}

function analytics_fetch_top_waiting_routes(PDO $pdo, int $limit = 3): array
{
    $limit = max(1, min(10, $limit));
    $stmt = $pdo->prepare(
        'SELECT
            t.source,
            t.destination,
            COUNT(*) AS waiting_count
         FROM bookings b
         INNER JOIN trains t ON t.id = b.train_id
         WHERE b.status = "WAITING"
         GROUP BY t.source, t.destination
         ORDER BY waiting_count DESC, t.source ASC, t.destination ASC
         LIMIT :limit'
    );
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll() ?: [];
    $results = [];

    foreach ($rows as $row) {
        $source = (string) ($row['source'] ?? '');
        $destination = (string) ($row['destination'] ?? '');
        $waitingCount = (int) ($row['waiting_count'] ?? 0);

        $results[] = [
            'source' => $source,
            'destination' => $destination,
            'route_label' => $source . ' → ' . $destination,
            'waiting_count' => $waitingCount,
            'waiting_count_label' => analytics_format_count($waitingCount),
        ];
    }

    return $results;
}

function analytics_fetch_underutilized_trains(PDO $pdo, int $limit = 3): array
{
    $limit = max(1, min(10, $limit));
    $stmt = $pdo->prepare(
        'SELECT
            t.id,
            t.train_number,
            t.train_name,
            t.source,
            t.destination,
            t.total_seats,
            COUNT(b.id) AS booking_count,
            ROUND((COUNT(b.id) / NULLIF(t.total_seats, 0)) * 100, 1) AS utilization_percent
         FROM trains t
         LEFT JOIN bookings b
            ON b.train_id = t.id
           AND b.status IN ("CONFIRMED", "WAITING")
         WHERE t.is_active = 1
         GROUP BY t.id, t.train_number, t.train_name, t.source, t.destination, t.total_seats
         HAVING t.total_seats > 0
         ORDER BY utilization_percent ASC, booking_count ASC, t.train_name ASC, t.train_number ASC
         LIMIT :limit'
    );
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll() ?: [];
    $results = [];

    foreach ($rows as $row) {
        $bookingCount = (int) ($row['booking_count'] ?? 0);
        $totalSeats = (int) ($row['total_seats'] ?? 0);
        $utilization = (float) ($row['utilization_percent'] ?? 0.0);

        $results[] = [
            'id' => (int) ($row['id'] ?? 0),
            'train_number' => (string) ($row['train_number'] ?? ''),
            'train_name' => (string) ($row['train_name'] ?? ''),
            'source' => (string) ($row['source'] ?? ''),
            'destination' => (string) ($row['destination'] ?? ''),
            'route_label' => trim((string) ($row['source'] ?? '') . ' → ' . (string) ($row['destination'] ?? '')),
            'booking_count' => $bookingCount,
            'booking_count_label' => analytics_format_count($bookingCount),
            'total_seats' => $totalSeats,
            'utilization_percent' => $utilization,
            'utilization_label' => analytics_format_percentage($utilization),
        ];
    }

    return $results;
}

function analytics_fetch_recent_cancellations(PDO $pdo, int $limit = 5): array
{
    $limit = max(1, min(10, $limit));
    $stmt = $pdo->prepare(
        'SELECT
            b.id,
            b.pnr,
            b.cancelled_at,
            b.created_at,
            b.travel_date,
            b.status,
            t.train_number,
            t.train_name,
            t.source,
            t.destination
         FROM bookings b
         INNER JOIN trains t ON t.id = b.train_id
         WHERE b.status = "CANCELLED"
         ORDER BY COALESCE(b.cancelled_at, b.updated_at, b.created_at) DESC, b.id DESC
         LIMIT :limit'
    );
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll() ?: [];
    $results = [];

    foreach ($rows as $row) {
        $results[] = [
            'id' => (int) ($row['id'] ?? 0),
            'pnr' => (string) ($row['pnr'] ?? ''),
            'train_number' => (string) ($row['train_number'] ?? ''),
            'train_name' => (string) ($row['train_name'] ?? ''),
            'route_label' => trim((string) ($row['source'] ?? '') . ' → ' . (string) ($row['destination'] ?? '')),
            'travel_date' => (string) ($row['travel_date'] ?? ''),
            'cancelled_at' => (string) ($row['cancelled_at'] ?? ''),
            'cancelled_at_label' => analytics_format_datetime_label((string) ($row['cancelled_at'] ?? $row['created_at'] ?? '')),
        ];
    }

    return $results;
}

function analytics_build_preview_payload(array $analytics, array $previewData = []): array
{
    $overview = $analytics['overview'] ?? analytics_build_overview([]);
    $trendWindows = $analytics['trend_windows'] ?? [];
    $trendSummary = $previewData['trend_summary'] ?? analytics_build_trend_summary([
        'windows' => $trendWindows,
    ]);
    $sevenDay = $trendWindows['7'] ?? ['labels' => [], 'bookings' => []];

    return [
        'kpis' => analytics_build_preview_cards([
            'overview' => $overview,
            'trend_summary' => $trendSummary,
        ]),
        'charts' => [
            'status_donut' => analytics_build_status_distribution_chart_data($overview),
            'booking_trend_7d' => [
                'labels' => $sevenDay['labels'] ?? [],
                'values' => $sevenDay['bookings'] ?? [],
                'color' => ANALYTICS_LINE_BOOKINGS_COLOR,
            ],
        ],
        'insights' => [
            'top_waiting_routes' => $previewData['top_waiting_routes'] ?? [],
            'underutilized_trains' => $previewData['underutilized_trains'] ?? [],
            'recent_cancellations' => $previewData['recent_cancellations'] ?? [],
        ],
    ];
}

function analytics_build_trend_map(array $rows): array
{
    $dailyMap = [];

    foreach ($rows as $row) {
        $day = (string) ($row['trend_date'] ?? '');
        if ($day === '') {
            continue;
        }

        if (!isset($dailyMap[$day])) {
            $dailyMap[$day] = [
                'bookings' => 0,
                'cancellations' => 0,
            ];
        }

        $dailyMap[$day]['bookings'] += (int) ($row['booking_count'] ?? 0);
        $dailyMap[$day]['cancellations'] += (int) ($row['cancellation_count'] ?? 0);
    }

    return $dailyMap;
}

function analytics_pick_trend_window_key(array $windows): ?string
{
    if (array_key_exists('30', $windows)) {
        return '30';
    }

    if (array_key_exists('7', $windows)) {
        return '7';
    }

    $firstKey = array_key_first($windows);

    return is_string($firstKey) ? $firstKey : null;
}

function analytics_build_dashboard_payload(
    array $counts,
    array $topRoutes,
    array $trendWindows,
    array $previewData = [],
    array $warnings = []
): array {
    $overview = analytics_build_overview($counts);
    $now = new DateTimeImmutable('now');
    $trend30Key = analytics_pick_trend_window_key($trendWindows['windows'] ?? []);
    $trend30 = is_string($trend30Key) && $trend30Key !== '' ? ($trendWindows['windows'][$trend30Key] ?? []) : [];
    $previewPayload = analytics_build_preview_payload([
        'overview' => $overview,
        'trend_windows' => $trendWindows['windows'] ?? [],
    ], $previewData);

    $dashboard = [
        'meta' => [
            'generated_at' => $now->format(DATE_ATOM),
            'generated_at_label' => $now->format('d M Y, h:i A'),
            'warnings' => array_values(array_unique(array_filter($warnings))),
            'degraded' => !empty($warnings),
        ],
        'generated_at' => $now->format(DATE_ATOM),
        'generated_at_label' => $now->format('d M Y, h:i A'),
        'overview' => $overview,
        'kpis' => analytics_build_kpi_cards(['overview' => $overview]),
        'preview_cards' => analytics_build_preview_cards(['overview' => $overview]),
        'preview' => $previewPayload,
        'charts' => [
            'status_pie' => analytics_build_status_pie_chart_data($overview),
            'status_distribution' => analytics_build_status_distribution_chart_data($overview),
            'top_routes_bar' => analytics_build_top_routes_chart_data($topRoutes),
            'booking_trend_line' => [
                'default_window' => (int) ($trendWindows['default_window'] ?? 7),
                'windows' => $trendWindows['windows'] ?? [],
                'series' => $trendWindows['series'] ?? [
                    'bookings' => [
                        'label' => 'Bookings',
                        'color' => ANALYTICS_LINE_BOOKINGS_COLOR,
                    ],
                    'cancellations' => [
                        'label' => 'Cancellations',
                        'color' => ANALYTICS_LINE_CANCELLATIONS_COLOR,
                    ],
                ],
            ],
            'cancellation_line' => [
                'labels' => $trend30['labels'] ?? [],
                'values' => $trend30['cancellations'] ?? [],
                'color' => ANALYTICS_LINE_CANCELLATIONS_COLOR,
            ],
        ],
        'top_routes' => $topRoutes,
        'trend_windows' => $trendWindows['windows'] ?? [],
    ];

    return $dashboard;
}

function analytics_build_empty_payload(): array
{
    return analytics_build_dashboard_payload(
        analytics_default_status_counts(),
        [],
        analytics_build_booking_trend_windows([], 30),
        [
            'top_waiting_routes' => [],
            'underutilized_trains' => [],
            'recent_cancellations' => [],
            'trend_summary' => [
                'direction' => 'flat',
                'label' => '',
                'delta_percent' => 0.0,
            ],
        ],
        []
    );
}

function getTotalBookings(PDO $pdo): int
{
    $counts = getBookingStatusCounts($pdo);

    return (int) ($counts['total_bookings'] ?? 0);
}

function getBookingStatusCounts(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        'SELECT
            COUNT(*) AS total_bookings,
            COALESCE(SUM(CASE WHEN status = "CONFIRMED" THEN 1 ELSE 0 END), 0) AS confirmed_bookings,
            COALESCE(SUM(CASE WHEN status = "WAITING" THEN 1 ELSE 0 END), 0) AS waiting_bookings,
            COALESCE(SUM(CASE WHEN status = "CANCELLED" THEN 1 ELSE 0 END), 0) AS cancelled_bookings
         FROM bookings'
    );
    $stmt->execute();
    $row = $stmt->fetch() ?: [];

    return [
        'total_bookings' => (int) ($row['total_bookings'] ?? 0),
        'confirmed_bookings' => (int) ($row['confirmed_bookings'] ?? 0),
        'waiting_bookings' => (int) ($row['waiting_bookings'] ?? 0),
        'cancelled_bookings' => (int) ($row['cancelled_bookings'] ?? 0),
    ];
}

function getTopRoutes(PDO $pdo, int $limit = 5): array
{
    $limit = max(1, min(10, $limit));
    $stmt = $pdo->prepare(
        'SELECT
            t.source,
            t.destination,
            COUNT(*) AS booking_count
         FROM bookings b
         INNER JOIN trains t ON t.id = b.train_id
         GROUP BY t.source, t.destination
         ORDER BY booking_count DESC, t.source ASC, t.destination ASC
         LIMIT :limit'
    );
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll() ?: [];
    $results = [];

    foreach ($rows as $index => $row) {
        $source = (string) ($row['source'] ?? '');
        $destination = (string) ($row['destination'] ?? '');
        $bookingCount = (int) ($row['booking_count'] ?? 0);

        $results[] = [
            'rank' => $index + 1,
            'source' => $source,
            'destination' => $destination,
            'route_label' => $source . ' → ' . $destination,
            'booking_count' => $bookingCount,
            'booking_count_label' => analytics_format_count($bookingCount),
        ];
    }

    return $results;
}

function analytics_fetch_booking_and_cancellation_trend(PDO $pdo, int $days = 30): array
{
    $days = max(1, $days);
    [$start, $end] = analytics_build_date_range_bounds($days);
    $endExclusive = $end->modify('+1 day');
    $stmt = $pdo->prepare(
        'SELECT
            trend_date,
            SUM(booking_count) AS booking_count,
            SUM(cancellation_count) AS cancellation_count
         FROM (
            SELECT
                DATE(created_at) AS trend_date,
                COUNT(*) AS booking_count,
                0 AS cancellation_count
            FROM bookings
            WHERE created_at >= :booking_start_datetime
              AND created_at < :booking_end_datetime
            GROUP BY DATE(created_at)
            UNION ALL
            SELECT
                DATE(cancelled_at) AS trend_date,
                0 AS booking_count,
                COUNT(*) AS cancellation_count
            FROM bookings
            WHERE status = "CANCELLED"
              AND cancelled_at IS NOT NULL
              AND cancelled_at >= :cancellation_start_datetime
              AND cancelled_at < :cancellation_end_datetime
            GROUP BY DATE(cancelled_at)
         ) AS trend_source
         GROUP BY trend_date
         ORDER BY trend_date ASC'
    );
    $stmt->execute([
        'booking_start_datetime' => $start->format('Y-m-d H:i:s'),
        'booking_end_datetime' => $endExclusive->format('Y-m-d H:i:s'),
        'cancellation_start_datetime' => $start->format('Y-m-d H:i:s'),
        'cancellation_end_datetime' => $endExclusive->format('Y-m-d H:i:s'),
    ]);
    $rows = $stmt->fetchAll() ?: [];
    $dailyMap = analytics_build_trend_map($rows);
    $longWindow = analytics_build_daily_window($dailyMap, $days);
    $shortWindowDays = min(7, $days);
    $shortWindow = analytics_build_daily_window($dailyMap, $shortWindowDays);
    $windows = [
        (string) $shortWindowDays => $shortWindow,
    ];

    if ($shortWindowDays !== $days) {
        $windows[(string) $days] = $longWindow;
    }

    return [
        'default_window' => $shortWindowDays,
        'windows' => $windows,
        'series' => [
            'bookings' => [
                'label' => 'Bookings',
                'color' => ANALYTICS_LINE_BOOKINGS_COLOR,
            ],
            'cancellations' => [
                'label' => 'Cancellations',
                'color' => ANALYTICS_LINE_CANCELLATIONS_COLOR,
            ],
        ],
    ];
}

function getCancellationTrends(PDO $pdo, int $days = 30): array
{
    $trend = analytics_fetch_booking_and_cancellation_trend($pdo, $days);
    $windowKey = (string) $days;
    if (!array_key_exists($windowKey, $trend['windows'])) {
        $windowKey = (string) ($trend['default_window'] ?? min(7, max(1, $days)));
    }

    $window = $trend['windows'][$windowKey] ?? ['labels' => [], 'cancellations' => []];

    return [
        'window' => (int) $windowKey,
        'labels' => $window['labels'] ?? [],
        'values' => $window['cancellations'] ?? [],
        'total' => array_sum(array_map('intval', $window['cancellations'] ?? [])),
    ];
}

function analytics_get_dashboard_data(PDO $pdo): array
{
    $warnings = [];

    $counts = analytics_safe_fetch(
        'booking counts',
        static fn (): array => getBookingStatusCounts($pdo),
        analytics_default_status_counts(),
        $warnings
    );

    $topRoutes = analytics_safe_fetch(
        'top routes',
        static fn (): array => getTopRoutes($pdo, 5),
        [],
        $warnings
    );

    $trendWindows = analytics_safe_fetch(
        'trend windows',
        static fn (): array => analytics_fetch_booking_and_cancellation_trend($pdo, 30),
        analytics_build_booking_trend_windows([], 30),
        $warnings
    );

    $previewData = [
        'top_waiting_routes' => analytics_safe_fetch(
            'top waiting routes',
            static fn (): array => analytics_fetch_top_waiting_routes($pdo, 3),
            [],
            $warnings
        ),
        'underutilized_trains' => analytics_safe_fetch(
            'underutilized trains',
            static fn (): array => analytics_fetch_underutilized_trains($pdo, 3),
            [],
            $warnings
        ),
        'recent_cancellations' => analytics_safe_fetch(
            'recent cancellations',
            static fn (): array => analytics_fetch_recent_cancellations($pdo, 5),
            [],
            $warnings
        ),
        'trend_summary' => analytics_safe_fetch(
            'trend summary',
            static fn (): array => analytics_build_trend_summary($trendWindows),
            [
                'direction' => 'flat',
                'label' => '',
                'delta_percent' => 0.0,
            ],
            $warnings
        ),
    ];

    return analytics_build_dashboard_payload($counts, $topRoutes, $trendWindows, $previewData, $warnings);
}

function analytics_build_status_banner(?string $errorMessage, array $warnings = []): ?array
{
    if ($errorMessage !== null && $errorMessage !== '') {
        return [
            'tone' => 'error',
            'message' => analytics_public_error_message($errorMessage),
        ];
    }

    if ($warnings !== []) {
        return [
            'tone' => 'info',
            'message' => analytics_is_debug_mode()
                ? implode(' ', $warnings)
                : ANALYTICS_FALLBACK_MESSAGE,
        ];
    }

    return null;
}

function analytics_load_dashboard_state(): array
{
    static $state = null;

    if (is_array($state)) {
        return $state;
    }

    $analytics = analytics_build_empty_payload();
    $errorMessage = null;

    try {
        $pdo = getPDO();
        $analytics = analytics_get_dashboard_data($pdo);
    } catch (Throwable $exception) {
        $errorMessage = analytics_normalize_exception_message($exception);
        analytics_log_issue('dashboard load', $exception);
    }

    $state = [
        'analytics' => $analytics,
        'error' => $errorMessage,
        'warnings' => $analytics['meta']['warnings'] ?? [],
    ];

    return $state;
}

function analytics_load_dashboard_payload(): array
{
    return analytics_load_dashboard_state()['analytics'];
}
