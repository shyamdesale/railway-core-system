<?php

declare(strict_types=1);

require_once __DIR__ . '/admin/common.php';
require_once __DIR__ . '/../admin/analytics_functions.php';

function bookingTrendFormatRangeLabel(DateTimeImmutable $start, DateTimeImmutable $end, bool $isCustom): string
{
    if (!$isCustom) {
        return 'Last ' . ((int) $start->diff($end)->days + 1) . ' days';
    }

    return $start->format('d M Y') . ' to ' . $end->format('d M Y');
}

function bookingTrendBuildPreviousWindow(DateTimeImmutable $currentStartDay, int $days): array
{
    $comparisonStartDay = $currentStartDay->sub(new DateInterval('P' . max(1, $days) . 'D'));
    $comparisonEndDay = $currentStartDay->sub(new DateInterval('P1D'));

    return [
        'comparison_start_day' => $comparisonStartDay->setTime(0, 0, 0),
        'comparison_end_day' => $comparisonEndDay->setTime(0, 0, 0),
    ];
}

function bookingTrendResolveDateRange(array $query): array
{
    $rangeValue = sanitizeString($query['range'] ?? '7');
    $startDateRaw = sanitizeString($query['start_date'] ?? '');
    $endDateRaw = sanitizeString($query['end_date'] ?? '');

    if ($startDateRaw !== '' || $endDateRaw !== '') {
        ensure($startDateRaw !== '' && $endDateRaw !== '', 'Both start_date and end_date are required.');

        $startDate = normalizeAdminDate($startDateRaw, false);
        $endDate = normalizeAdminDate($endDateRaw, false);
        $start = new DateTimeImmutable($startDate . ' 00:00:00');
        $end = new DateTimeImmutable($endDate . ' 23:59:59');
        $days = ((int) $start->diff($end)->days + 1);
        $previousWindow = bookingTrendBuildPreviousWindow($start->setTime(0, 0, 0), $days);

        ensure($start <= $end, 'start_date must be on or before end_date.');
        ensure($days <= 90, 'Custom range cannot exceed 90 days.');

        return [
            'start_day' => $start->setTime(0, 0, 0),
            'end_day' => $end->setTime(0, 0, 0),
            'start_datetime' => $start,
            'end_datetime' => $end,
            'query_start_datetime' => $previousWindow['comparison_start_day']->setTime(0, 0, 0),
            'query_end_datetime' => $end,
            'range' => [
                'mode' => 'custom',
                'value' => null,
                'label' => bookingTrendFormatRangeLabel($start, $end, true),
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $end->format('Y-m-d'),
                'days' => $days,
            ],
            'comparison' => [
                'start_day' => $previousWindow['comparison_start_day'],
                'end_day' => $previousWindow['comparison_end_day'],
                'days' => $days,
            ],
        ];
    }

    $range = filter_var($rangeValue, FILTER_VALIDATE_INT);
    ensure($range !== false && in_array((int) $range, [7, 30, 90], true), 'Invalid range. Allowed values are 7, 30, or 90.');

    $range = (int) $range;
    $endDay = new DateTimeImmutable('today');
    $startDay = $endDay->sub(new DateInterval('P' . max(0, $range - 1) . 'D'));
    $previousWindow = bookingTrendBuildPreviousWindow($startDay, $range);

    return [
        'start_day' => $startDay,
        'end_day' => $endDay,
        'start_datetime' => $startDay->setTime(0, 0, 0),
        'end_datetime' => $endDay->setTime(23, 59, 59),
        'query_start_datetime' => $previousWindow['comparison_start_day']->setTime(0, 0, 0),
        'query_end_datetime' => $endDay->setTime(23, 59, 59),
        'range' => [
            'mode' => 'preset',
            'value' => $range,
            'label' => bookingTrendFormatRangeLabel($startDay, $endDay, false),
            'start_date' => $startDay->format('Y-m-d'),
            'end_date' => $endDay->format('Y-m-d'),
            'days' => $range,
        ],
        'comparison' => [
            'start_day' => $previousWindow['comparison_start_day'],
            'end_day' => $previousWindow['comparison_end_day'],
            'days' => $range,
        ],
    ];
}

function bookingTrendFetchDailyRows(PDO $pdo, DateTimeImmutable $startDatetime, DateTimeImmutable $endDatetime): array
{
    $stmt = $pdo->prepare(
        'SELECT
            DATE(created_at) AS trend_date,
            COUNT(*) AS total
         FROM bookings
         WHERE created_at BETWEEN :start_datetime AND :end_datetime
         GROUP BY DATE(created_at)
         ORDER BY trend_date ASC'
    );
    $stmt->execute([
        'start_datetime' => $startDatetime->format('Y-m-d H:i:s'),
        'end_datetime' => $endDatetime->format('Y-m-d H:i:s'),
    ]);

    return $stmt->fetchAll() ?: [];
}

function bookingTrendFormatInsightDate(string $value): string
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if ($date === false) {
        return $value;
    }

    return $date->format('d M');
}

function bookingTrendBuildComparison(int $currentTotal, int $previousTotal): array
{
    $delta = $currentTotal - $previousTotal;
    $changePercent = $previousTotal > 0
        ? round(($delta / $previousTotal) * 100, 1)
        : null;

    return [
        'current_total' => $currentTotal,
        'previous_total' => $previousTotal,
        'delta' => $delta,
        'change_percent' => $changePercent,
        'direction' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat'),
    ];
}

function bookingTrendBuildInsight(array $points, array $comparison, array $peakPoint): array
{
    $currentTotal = (int) ($comparison['current_total'] ?? 0);
    $previousTotal = (int) ($comparison['previous_total'] ?? 0);
    $peakTotal = (int) ($peakPoint['total'] ?? 0);
    $peakDate = (string) ($peakPoint['date'] ?? '');

    if ($currentTotal <= 0) {
        return [
            'headline' => 'No bookings found for selected range',
            'detail' => 'The selected window contains no confirmed bookings.',
            'tone' => 'neutral',
        ];
    }

    if ($currentTotal < 5 || $peakTotal <= 1) {
        return [
            'headline' => 'No significant booking activity in selected range',
            'detail' => $peakTotal > 0
                ? 'Peak bookings occurred on ' . bookingTrendFormatInsightDate($peakDate) . ' with ' . $peakTotal . ' bookings.'
                : 'The selected window stayed flat with no booking spikes.',
            'tone' => 'neutral',
        ];
    }

    if ($previousTotal > 0) {
        $changePercent = $comparison['change_percent'];
        $resolvedPercent = is_numeric($changePercent)
            ? (float) $changePercent
            : round((($currentTotal - $previousTotal) / $previousTotal) * 100, 1);

        if (abs($resolvedPercent) < 5) {
            return [
                'headline' => 'Booking activity stayed steady compared to the previous period.',
                'detail' => $peakTotal > 0
                    ? 'Peak bookings occurred on ' . bookingTrendFormatInsightDate($peakDate) . ' with ' . $peakTotal . ' bookings.'
                    : 'The selected range maintained a balanced booking rhythm.',
                'tone' => 'neutral',
            ];
        }

        return [
            'headline' => $resolvedPercent > 0
                ? 'Bookings increased by ' . abs($resolvedPercent) . '% compared to the previous period.'
                : 'Bookings decreased by ' . abs($resolvedPercent) . '% compared to the previous period.',
            'detail' => $peakTotal > 0
                ? 'Peak bookings occurred on ' . bookingTrendFormatInsightDate($peakDate) . ' with ' . $peakTotal . ' bookings.'
                : 'The selected range maintained a balanced booking rhythm.',
            'tone' => $resolvedPercent > 0 ? 'positive' : 'negative',
        ];
    }

    return [
        'headline' => 'Fresh booking activity was recorded in this range.',
        'detail' => $peakTotal > 0
            ? 'Peak bookings occurred on ' . bookingTrendFormatInsightDate($peakDate) . ' with ' . $peakTotal . ' bookings.'
            : 'The selected range maintained a balanced booking rhythm.',
        'tone' => 'positive',
    ];
}

function bookingTrendBuildSeries(DateTimeImmutable $comparisonStartDay, DateTimeImmutable $currentStartDay, DateTimeImmutable $currentEndDay, array $rows): array
{
    $dailyMap = [];
    foreach ($rows as $row) {
        $date = (string) ($row['trend_date'] ?? '');
        if ($date !== '') {
            $dailyMap[$date] = (int) ($row['total'] ?? 0);
        }
    }

    $points = [];
    $labels = [];
    $values = [];
    $comparisonValues = [];
    $cursor = $comparisonStartDay;
    $interval = new DateInterval('P1D');
    $totalBookings = 0;
    $previousTotal = 0;
    $peakPoint = [
        'date' => '',
        'total' => 0,
    ];

    while ($cursor <= $currentEndDay) {
        $dateKey = $cursor->format('Y-m-d');
        $count = (int) ($dailyMap[$dateKey] ?? 0);

        if ($cursor < $currentStartDay) {
            $comparisonValues[] = $count;
            $previousTotal += $count;
        } else {
            $points[] = [
                'date' => $dateKey,
                'total' => $count,
            ];
            $labels[] = $cursor->format('d M');
            $values[] = $count;
            $totalBookings += $count;

            if ($count > $peakPoint['total']) {
                $peakPoint = [
                    'date' => $dateKey,
                    'total' => $count,
                ];
            }
        }

        $cursor = $cursor->add($interval);
    }

    $comparison = bookingTrendBuildComparison($totalBookings, $previousTotal);

    return [
        'points' => $points,
        'series' => [
            'labels' => $labels,
            'values' => $values,
            'comparison_values' => $comparisonValues,
        ],
        'comparison' => $comparison,
        'insight' => bookingTrendBuildInsight($points, $comparison, $peakPoint),
        'summary' => [
            'total_bookings' => $totalBookings,
            'has_data' => $totalBookings > 0,
            'message' => $totalBookings > 0 ? '' : 'No bookings found for selected range',
        ],
    ];
}

try {
    requireMethod('GET');
    requireAdmin();

    $pdo = getPDO();
    $range = bookingTrendResolveDateRange($_GET);
    $rows = bookingTrendFetchDailyRows(
        $pdo,
        $range['query_start_datetime'] ?? $range['start_datetime'],
        $range['query_end_datetime'] ?? $range['end_datetime']
    );
    $trend = bookingTrendBuildSeries(
        $range['comparison']['start_day'],
        $range['start_day'],
        $range['end_day'],
        $rows
    );

    jsonResponse(true, 'Booking trend data fetched successfully.', [
        'trend' => [
            'range' => $range['range'],
            'comparison_range' => [
                'start_date' => $range['comparison']['start_day']->format('Y-m-d'),
                'end_date' => $range['comparison']['end_day']->format('Y-m-d'),
                'days' => $range['comparison']['days'],
            ],
            'points' => $trend['points'],
            'series' => $trend['series'],
            'comparison' => $trend['comparison'],
            'insight' => $trend['insight'],
            'summary' => $trend['summary'],
        ],
    ]);
} catch (Throwable $exception) {
    $statusCode = $exception instanceof ApiException ? $exception->getStatusCode() : 503;
    $errorMessage = $exception instanceof ApiException
        ? $exception->getMessage()
        : analytics_normalize_exception_message($exception);

    $responseData = [];
    if (analytics_is_debug_mode()) {
        $responseData['error'] = $errorMessage;
    }

    jsonResponse(
        false,
        $exception instanceof ApiException ? $errorMessage : analytics_public_error_message($errorMessage),
        $responseData,
        $statusCode
    );
}
