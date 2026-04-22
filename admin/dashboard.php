<?php

declare(strict_types=1);

require_once __DIR__ . '/analytics_functions.php';

$currentAdmin = analytics_require_admin_session();
$dashboardState = analytics_load_dashboard_state();
$analytics = $dashboardState['analytics'] ?? analytics_build_empty_payload();
$analyticsError = $dashboardState['error'] ?? null;
$analyticsWarnings = $analytics['meta']['warnings'] ?? [];
$statusBanner = analytics_build_status_banner($analyticsError, $analyticsWarnings);
$dashboardDataJson = json_encode(
    $analytics,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

if ($dashboardDataJson === false) {
    $dashboardDataJson = json_encode(
        analytics_build_empty_payload(),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
}

if ($dashboardDataJson === false) {
    $dashboardDataJson = '{}';
}

$generatedAtLabel = $analytics['generated_at_label'] ?? 'Just now';
$kpiCards = analytics_build_kpi_cards($analytics);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Analytics Dashboard | IndianRail Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="analytics_dashboard.css">
</head>
<body class="analytics-dashboard-page">
<div class="analytics-dashboard" data-booking-trend-api="../api/get_booking_trend.php">
  <div class="analytics-page">
    <header class="analytics-topbar">
      <div class="analytics-shell analytics-topbar__inner">
        <div class="analytics-topbar__copy">
          <span class="analytics-topbar__eyebrow">Admin analytics</span>
          <h1>Analytics Dashboard</h1>
          <p>Production-grade snapshot of bookings, ticket status mix, route demand, and cancellation movement.</p>
        </div>
        <div class="analytics-topbar__actions">
          <span class="analytics-badge">Updated <?php echo htmlspecialchars($generatedAtLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
          <span class="analytics-badge analytics-badge--muted">Admin: <?php echo htmlspecialchars((string) ($currentAdmin['name'] ?? 'Admin'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
          <a class="analytics-button analytics-button--secondary" href="index.html">Back to Admin</a>
        </div>
      </div>
    </header>

    <main class="analytics-shell analytics-grid">
      <?php if ($statusBanner !== null): ?>
        <div class="analytics-status-banner is-visible is-<?php echo htmlspecialchars($statusBanner['tone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" role="status" aria-live="polite" aria-atomic="true">
          <?php echo htmlspecialchars($statusBanner['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
        </div>
      <?php endif; ?>

      <section class="analytics-kpi-grid" aria-label="Top analytics summary">
        <?php foreach ($kpiCards as $card): ?>
          <article class="analytics-kpi-card tone-<?php echo htmlspecialchars($card['tone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
            <div class="analytics-kpi-card__header">
              <span class="analytics-kpi-card__label"><?php echo htmlspecialchars($card['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
              <span class="analytics-kpi-card__icon"><?php echo $card['icon']; ?></span>
            </div>
            <strong class="analytics-kpi-card__value"><?php echo htmlspecialchars($card['value_label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong>
            <p class="analytics-kpi-card__meta"><?php echo htmlspecialchars($card['meta'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p>
          </article>
        <?php endforeach; ?>
      </section>

      <section class="analytics-chart-grid" aria-label="Primary analytics">
        <article class="analytics-chart-card">
          <div class="analytics-chart-card__header">
            <div class="analytics-chart-card__copy">
              <span class="analytics-chart-card__eyebrow">Status mix</span>
              <h2 class="analytics-chart-card__title">Confirmed vs Waiting vs Cancelled</h2>
              <p class="analytics-chart-card__subtitle">Distribution of booking status across the current dataset.</p>
            </div>
          </div>
          <div class="analytics-chart-frame analytics-chart-frame--chart">
            <canvas id="analyticsPieChart" aria-label="Booking status distribution pie chart" role="img"></canvas>
            <div class="analytics-chart-empty" id="analyticsPieEmpty" hidden>No Data Available</div>
          </div>
        </article>

        <article class="analytics-chart-card">
          <div class="analytics-chart-card__header">
            <div class="analytics-chart-card__copy">
              <span class="analytics-chart-card__eyebrow">Trendline</span>
              <h2 class="analytics-chart-card__title">Booking Trend</h2>
              <p class="analytics-chart-card__subtitle" id="analyticsTrendSubtitle">Select a date range</p>
            </div>
            <div class="analytics-trend-controls" aria-label="Booking trend filters">
              <div class="analytics-trend-chips" role="radiogroup" aria-label="Booking trend date range">
                <button type="button" class="analytics-trend-chip is-active" data-trend-range="7" aria-pressed="true">7D</button>
                <button type="button" class="analytics-trend-chip" data-trend-range="30" aria-pressed="false">30D</button>
                <button type="button" class="analytics-trend-chip" data-trend-range="90" aria-pressed="false">90D</button>
                <button type="button" class="analytics-trend-chip analytics-trend-chip--custom" data-trend-range="custom" aria-pressed="false">Custom Range</button>
              </div>
              <div class="analytics-trend-custom" id="analyticsTrendCustomPanel" aria-hidden="true">
                <label class="analytics-date-field">
                  <span>From</span>
                  <input type="date" id="analyticsTrendStartDate" autocomplete="off">
                </label>
                <label class="analytics-date-field">
                  <span>To</span>
                  <input type="date" id="analyticsTrendEndDate" autocomplete="off">
                </label>
              </div>
              <p class="analytics-trend-message" id="analyticsTrendMessage" role="status" aria-live="polite" aria-atomic="true" hidden></p>
            </div>
          </div>
          <div class="analytics-chart-frame analytics-chart-frame--chart analytics-chart-frame--trend">
            <div class="analytics-trend-loader" id="analyticsTrendLoader" hidden aria-hidden="true">
              <span class="analytics-trend-loader__spinner" aria-hidden="true"></span>
              <span>Loading booking trend...</span>
            </div>
            <canvas id="analyticsTrendChart" aria-label="Booking trend line chart" role="img"></canvas>
            <div class="analytics-chart-empty" id="analyticsTrendEmpty" hidden>No Data Available</div>
          </div>
          <div class="analytics-trend-insight" id="analyticsTrendInsight" role="status" aria-live="polite" aria-atomic="true">
            <span class="analytics-trend-insight__eyebrow">Insight</span>
            <strong class="analytics-trend-insight__headline" id="analyticsTrendInsightHeadline">Select a range to see performance insights.</strong>
            <span class="analytics-trend-insight__detail" id="analyticsTrendInsightDetail"></span>
          </div>
        </article>
      </section>

      <section class="analytics-chart-card analytics-chart-card--wide" aria-label="Route intelligence">
        <div class="analytics-chart-card__header">
          <div class="analytics-chart-card__copy">
            <span class="analytics-chart-card__eyebrow">Route intelligence</span>
            <h2 class="analytics-chart-card__title">Top 5 Routes by Bookings</h2>
            <p class="analytics-chart-card__subtitle">Source to destination demand ranked in descending booking volume.</p>
          </div>
        </div>
        <div class="analytics-chart-frame analytics-chart-frame--wide">
          <canvas id="analyticsRoutesChart" aria-label="Top routes horizontal bar chart" role="img"></canvas>
          <div class="analytics-chart-empty" id="analyticsRoutesEmpty" hidden>No Data Available</div>
        </div>
      </section>
    </main>
  </div>
  </div>

  <script id="analyticsDashboardData" type="application/json"><?php echo $dashboardDataJson; ?></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js" defer></script>
  <script src="analytics_dashboard.js" defer></script>
</body>
</html>
