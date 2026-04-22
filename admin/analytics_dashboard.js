(function () {
  'use strict';

  const dashboardRoot = document.querySelector('.analytics-dashboard');
  const dataNode = document.getElementById('analyticsDashboardData');
  const pieCanvas = document.getElementById('analyticsPieChart');
  const pieEmpty = document.getElementById('analyticsPieEmpty');
  const trendCanvas = document.getElementById('analyticsTrendChart');
  const trendEmpty = document.getElementById('analyticsTrendEmpty');
  const trendLoader = document.getElementById('analyticsTrendLoader');
  const trendMessage = document.getElementById('analyticsTrendMessage');
  const trendCustomPanel = document.getElementById('analyticsTrendCustomPanel');
  const trendInsight = document.getElementById('analyticsTrendInsight');
  const trendInsightHeadline = document.getElementById('analyticsTrendInsightHeadline');
  const trendInsightDetail = document.getElementById('analyticsTrendInsightDetail');
  const trendStartInput = document.getElementById('analyticsTrendStartDate');
  const trendEndInput = document.getElementById('analyticsTrendEndDate');
  const routesCanvas = document.getElementById('analyticsRoutesChart');
  const routesEmpty = document.getElementById('analyticsRoutesEmpty');
  const trendSubtitle = document.getElementById('analyticsTrendSubtitle');
  const trendChipButtons = Array.from(document.querySelectorAll('.analytics-trend-chip'));

  if (
    !dashboardRoot
    || !dataNode
    || !pieCanvas
    || !pieEmpty
    || !trendCanvas
    || !trendEmpty
    || !trendLoader
    || !trendMessage
    || !trendCustomPanel
    || trendChipButtons.length === 0
    || !trendInsight
    || !trendInsightHeadline
    || !trendInsightDetail
    || !trendStartInput
    || !trendEndInput
    || !routesCanvas
    || !routesEmpty
  ) {
    return;
  }

  const trendApiUrl = dashboardRoot.dataset.bookingTrendApi || '../api/get_booking_trend.php';
  const presetTrendLabels = {
    7: 'Last 7 days',
    30: 'Last 30 days',
    90: 'Last 90 days'
  };
  const theme = {
    primary: '#334155',
    success: '#1f7a45',
    warning: '#b26a00',
    danger: '#b83232',
    grid: 'rgba(23, 50, 61, 0.08)',
    text: '#16313c',
    muted: '#5d7680'
  };

  const payload = safeParseJSON(dataNode.textContent || '{}');
  const charts = payload.charts || {};
  const routeData = charts.top_routes_bar || {};
  const pieData = charts.status_distribution || {};
  const trendData = charts.booking_trend_line || {};
  const defaultWindow = String(trendData.default_window || '7');

  let pieChart = null;
  let trendChart = null;
  let routesChart = null;
  let activeTrendRange = String(defaultWindow || '7');
  let trendRequestController = null;
  let trendRequestToken = 0;
  let trendDebounceTimer = null;
  let trendCustomRange = {
    start: '',
    end: ''
  };

  function safeParseJSON(value) {
    try {
      return JSON.parse(value);
    } catch (error) {
      return {};
    }
  }

  function hasPositiveValues(values) {
    return Array.isArray(values) && values.some((value) => Number(value) > 0);
  }

  function getTrendTotal(values) {
    return Array.isArray(values)
      ? values.reduce((sum, value) => sum + (Number(value) || 0), 0)
      : 0;
  }

  function formatCount(value) {
    return new Intl.NumberFormat(undefined).format(Number(value) || 0);
  }

  function destroyChart(chart) {
    if (chart) {
      chart.destroy();
    }
  }

  function getTodayIso() {
    return new Date().toISOString().slice(0, 10);
  }

  function parseIsoDate(value) {
    const dateValue = String(value || '').trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(dateValue)) {
      return null;
    }

    const parsed = new Date(`${dateValue}T00:00:00`);
    if (Number.isNaN(parsed.getTime())) {
      return null;
    }

    return dateValue;
  }

  function diffInclusiveDays(startDate, endDate) {
    const start = new Date(`${startDate}T00:00:00`);
    const end = new Date(`${endDate}T00:00:00`);
    const msPerDay = 24 * 60 * 60 * 1000;

    return Math.floor((end.getTime() - start.getTime()) / msPerDay) + 1;
  }

  function buildTrendUrl(params) {
    const url = new URL(trendApiUrl, window.location.origin);
    const searchParams = url.searchParams;

    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null && String(value).trim() !== '') {
        searchParams.set(key, String(value));
      }
    });

    return url.toString();
  }

  function getTrendRangeLabel(rangeKey) {
    const key = String(rangeKey || '');
    if (key in presetTrendLabels) {
      return presetTrendLabels[key];
    }

    return 'Custom range';
  }

  function formatTrendPercent(value) {
    const numericValue = Number(value) || 0;
    const formatter = new Intl.NumberFormat(undefined, {
      minimumFractionDigits: 0,
      maximumFractionDigits: 1
    });

    return `${formatter.format(Math.abs(numericValue))}%`;
  }

  function formatTrendDateLabel(value) {
    const parsed = parseIsoDate(value);
    if (!parsed) {
      return '';
    }

    const date = new Date(`${parsed}T00:00:00`);
    if (Number.isNaN(date.getTime())) {
      return '';
    }

    return new Intl.DateTimeFormat(undefined, {
      day: 'numeric',
      month: 'short'
    }).format(date);
  }

  function setTrendMessage(message = '', tone = 'info', visible = false) {
    if (!trendMessage) {
      return;
    }

    trendMessage.textContent = message;
    trendMessage.classList.remove('is-info', 'is-error');
    trendMessage.hidden = !visible || !message;

    if (visible && message) {
      if (tone === 'error') {
        trendMessage.classList.add('is-error');
      } else {
        trendMessage.classList.add('is-info');
      }
    }
  }

  function setTrendLoading(isLoading) {
    if (trendCanvas) {
      trendCanvas.style.opacity = isLoading ? '0.72' : '';
    }

    if (trendLoader) {
      trendLoader.hidden = !isLoading;
    }
  }

  function normalizeTrendSeriesPayload(payload) {
    const series = payload?.series || {};
    const labels = Array.isArray(series.labels) ? series.labels.slice() : [];
    const values = Array.isArray(series.values)
      ? series.values.map((value) => Number(value) || 0)
      : labels.map(() => 0);
    const points = Array.isArray(payload?.points)
      ? payload.points.map((point) => ({
        date: String(point?.date || ''),
        total: Number(point?.total) || 0
      }))
      : labels.map((label, index) => ({
        date: String(label || ''),
        total: Number(values[index]) || 0
      }));
    const summary = payload?.summary || {};
    const total = values.reduce((sum, value) => sum + Number(value || 0), 0);

    return {
      range: payload?.range || {},
      points,
      labels,
      values,
      hasData: total > 0,
      message: String(summary.message || ''),
      comparison: payload?.comparison || {},
      insight: payload?.insight || {}
    };
  }

  function buildTrendStateFromWindow(windowKey) {
    const selectedWindow = getTrendWindow(windowKey);
    const labels = Array.isArray(selectedWindow.labels) ? selectedWindow.labels.slice() : [];
    const values = Array.isArray(selectedWindow.bookings)
      ? selectedWindow.bookings.map((value) => Number(value) || 0)
      : labels.map(() => 0);

    return {
      range: {
        mode: 'preset',
        value: String(windowKey),
        label: getTrendRangeLabel(windowKey)
      },
      labels,
      values,
      hasData: values.some((value) => value > 0),
      message: ''
    };
  }

  function buildTrendChartOptions() {
    return {
      responsive: true,
      maintainAspectRatio: false,
      animation: {
        duration: 500,
        easing: 'easeOutQuart'
      },
      interaction: {
        mode: 'index',
        intersect: false
      },
      layout: {
        padding: {
          top: 8,
          right: 10,
          bottom: 8,
          left: 8
        }
      },
      scales: {
        x: {
          grid: {
            color: theme.grid
          },
          ticks: {
            color: theme.muted,
            maxRotation: 0,
            autoSkip: true
          }
        },
        y: {
          beginAtZero: true,
          suggestedMin: 0,
          grid: {
            color: theme.grid
          },
          ticks: {
            color: theme.muted,
            precision: 0
          }
        }
      },
      plugins: {
        legend: {
          display: false
        },
        tooltip: {
          callbacks: {
            label(context) {
              return `Bookings: ${formatCount(context.parsed.y)}`;
            }
          }
        }
      }
    };
  }

  function ensureTrendChart(trendState) {
    if (trendChart) {
      return;
    }

    trendChart = new Chart(trendCanvas, {
      type: 'line',
      data: {
        labels: trendState.labels,
        datasets: [
          {
            label: 'Bookings',
            data: trendState.values,
            borderColor: theme.primary,
            backgroundColor: 'rgba(51, 65, 85, 0.08)',
            pointBackgroundColor: theme.primary,
            pointBorderColor: '#ffffff',
            pointRadius: 3,
            pointHoverRadius: 5,
            tension: 0.42,
            borderWidth: 2,
            fill: true,
            spanGaps: true
          }
        ]
      },
      options: buildTrendChartOptions()
    });
  }

  function setTrendEmptyState(visible, message) {
    if (!trendEmpty) {
      return;
    }

    trendEmpty.textContent = message || 'No Data Available';
    trendEmpty.hidden = !visible;
  }

  function setTrendInsight(insightState) {
    if (!trendInsight || !trendInsightHeadline || !trendInsightDetail) {
      return;
    }

    const headline = String(insightState?.headline || 'Select a range to see performance insights.');
    const detail = String(insightState?.detail || '');
    const tone = String(insightState?.tone || 'neutral');

    trendInsight.classList.remove('is-positive', 'is-negative', 'is-neutral');
    trendInsight.classList.add(
      tone === 'positive' ? 'is-positive' : tone === 'negative' ? 'is-negative' : 'is-neutral'
    );
    trendInsightHeadline.textContent = headline;
    trendInsightDetail.textContent = detail;
  }

  function resolveTrendInsight(trendState) {
    const explicitInsight = trendState?.insight || {};
    if (String(explicitInsight.headline || '').trim() !== '' || String(explicitInsight.detail || '').trim() !== '') {
      return {
        headline: String(explicitInsight.headline || 'Select a range to see performance insights.'),
        detail: String(explicitInsight.detail || ''),
        tone: String(explicitInsight.tone || 'neutral')
      };
    }

    const labels = Array.isArray(trendState?.labels) ? trendState.labels : [];
    const values = Array.isArray(trendState?.values) ? trendState.values.map((value) => Number(value) || 0) : [];
    const points = Array.isArray(trendState?.points) && trendState.points.length > 0
      ? trendState.points.map((point) => ({
        date: String(point?.date || ''),
        total: Number(point?.total) || 0
      }))
      : labels.map((label, index) => ({
        date: String(label || ''),
        total: Number(values[index]) || 0
      }));
    const comparison = trendState?.comparison || {};
    const currentTotal = Number.isFinite(Number(comparison.current_total))
      ? Number(comparison.current_total)
      : getTrendTotal(values);
    const previousTotal = Number.isFinite(Number(comparison.previous_total))
      ? Number(comparison.previous_total)
      : 0;
    const peakPoint = points.reduce((best, point) => (point.total > best.total ? point : best), {
      date: '',
      total: 0
    });

    if (currentTotal <= 0) {
      return {
        headline: 'No bookings found for selected range',
        detail: 'The selected window contains no confirmed bookings.',
        tone: 'neutral'
      };
    }

    if (currentTotal < 5 || peakPoint.total <= 1) {
      return {
        headline: 'No significant booking activity in selected range',
        detail: peakPoint.total > 0
          ? `Peak bookings occurred on ${formatTrendDateLabel(peakPoint.date)} with ${formatCount(peakPoint.total)} bookings.`
          : 'The selected window stayed flat with no booking spikes.',
        tone: 'neutral'
      };
    }

    if (previousTotal > 0) {
      const changePercent = Number(comparison.change_percent);
      const resolvedPercent = Number.isFinite(changePercent)
        ? changePercent
        : ((currentTotal - previousTotal) / previousTotal) * 100;

      if (Math.abs(resolvedPercent) < 5) {
        return {
          headline: 'Booking activity stayed steady compared to the previous period.',
          detail: peakPoint.total > 0
            ? `Peak bookings occurred on ${formatTrendDateLabel(peakPoint.date)} with ${formatCount(peakPoint.total)} bookings.`
            : 'The selected range maintained a balanced booking rhythm.',
          tone: 'neutral'
        };
      }

      return {
        headline: resolvedPercent > 0
          ? `Bookings increased by ${formatTrendPercent(resolvedPercent)} compared to the previous period.`
          : `Bookings decreased by ${formatTrendPercent(resolvedPercent)} compared to the previous period.`,
        detail: peakPoint.total > 0
          ? `Peak bookings occurred on ${formatTrendDateLabel(peakPoint.date)} with ${formatCount(peakPoint.total)} bookings.`
          : 'The selected range maintained a balanced booking rhythm.',
        tone: resolvedPercent > 0 ? 'positive' : 'negative'
      };
    }

    return {
      headline: 'Fresh booking activity was recorded in this range.',
      detail: peakPoint.total > 0
        ? `Peak bookings occurred on ${formatTrendDateLabel(peakPoint.date)} with ${formatCount(peakPoint.total)} bookings.`
        : 'The selected range maintained a balanced booking rhythm.',
      tone: 'positive'
    };
  }

  function updateTrendChart(trendState) {
    ensureTrendChart(trendState);

    trendChart.data.labels = trendState.labels;
    trendChart.data.datasets[0].data = trendState.values;
    trendChart.update();

    if (trendSubtitle) {
      trendSubtitle.textContent = trendState.range?.label || getTrendRangeLabel(activeTrendRange);
    }

    setTrendEmptyState(false, '');
    setTrendMessage('', 'info', false);
    setTrendInsight(resolveTrendInsight(trendState));
  }

  function setActiveTrendRange(rangeKey) {
    activeTrendRange = String(rangeKey || '7');
    trendChipButtons.forEach((button) => {
      const isActive = String(button.dataset.trendRange || '') === activeTrendRange;
      button.classList.toggle('is-active', isActive);
      button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });

    const isCustom = activeTrendRange === 'custom';
    trendCustomPanel.classList.toggle('is-visible', isCustom);
    trendCustomPanel.setAttribute('aria-hidden', isCustom ? 'false' : 'true');

    if (trendSubtitle && !isCustom) {
      trendSubtitle.textContent = getTrendRangeLabel(activeTrendRange);
    }
  }

  function setTrendCustomRange(startDate, endDate) {
    trendCustomRange = {
      start: startDate || '',
      end: endDate || ''
    };

    trendStartInput.value = trendCustomRange.start;
    trendEndInput.value = trendCustomRange.end;
    trendStartInput.min = '';
    trendStartInput.max = trendCustomRange.end || getTodayIso();
    trendEndInput.min = trendCustomRange.start || '';
    trendEndInput.max = getTodayIso();
  }

  function validateTrendCustomRange(startDate, endDate) {
    const parsedStart = parseIsoDate(startDate);
    const parsedEnd = parseIsoDate(endDate);

    if (!parsedStart || !parsedEnd) {
      return 'Select both start and end dates.';
    }

    if (parsedStart > parsedEnd) {
      return 'Start date must be on or before end date.';
    }

    const rangeDays = diffInclusiveDays(parsedStart, parsedEnd);
    if (rangeDays > 90) {
      return 'Custom range cannot exceed 90 days.';
    }

    return '';
  }

  function normalizeTrendRequestParams(params) {
    const normalized = {};
    const range = String(params?.range ?? '').trim();
    const startDate = parseIsoDate(params?.start_date);
    const endDate = parseIsoDate(params?.end_date);

    if (startDate && endDate) {
      normalized.start_date = startDate;
      normalized.end_date = endDate;
      return normalized;
    }

    if (['7', '30', '90'].includes(range)) {
      normalized.range = range;
    }

    return normalized;
  }

  function debounceTrendFetch(params, delay = 250) {
    clearTimeout(trendDebounceTimer);
    trendDebounceTimer = window.setTimeout(() => {
      void loadTrendRange(params);
    }, delay);
  }

  async function fetchTrendRange(params) {
    if (trendRequestController) {
      trendRequestController.abort();
    }

    const controller = new AbortController();
    const requestToken = ++trendRequestToken;
    trendRequestController = controller;
    setTrendLoading(true);
    setTrendMessage('Loading booking trend...', 'info', true);

    try {
      const response = await fetch(buildTrendUrl(params), {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json'
        },
        signal: controller.signal
      });

      const responseText = await response.text();
      let payload = null;

      try {
        payload = responseText ? JSON.parse(responseText) : null;
      } catch (error) {
        throw new Error('Admin API returned invalid JSON.');
      }

      if (!response.ok || !payload?.success) {
        throw new Error(payload?.message || 'Unable to load booking trend data.');
      }

      return normalizeTrendSeriesPayload(payload.data?.trend || {});
    } finally {
      if (trendRequestToken === requestToken) {
        setTrendLoading(false);
        if (trendRequestController === controller) {
          trendRequestController = null;
        }
      }
    }
  }

  async function loadTrendRange(params) {
    const normalizedParams = normalizeTrendRequestParams(params);

    if (
      normalizedParams.start_date
      && normalizedParams.end_date
      && validateTrendCustomRange(normalizedParams.start_date, normalizedParams.end_date) !== ''
    ) {
      return;
    }

    try {
      const trendState = await fetchTrendRange(normalizedParams);
      updateTrendChart(trendState);
    } catch (error) {
      if (error?.name === 'AbortError') {
        return;
      }

      setTrendMessage(error?.message || 'Analytics temporarily unavailable', 'error', true);
      setTrendInsight({
        headline: 'Analytics temporarily unavailable',
        detail: 'The trend module will retry on the next selection.',
        tone: 'neutral'
      });
    }
  }

  function handleTrendRangeChange(rangeKey) {
    setActiveTrendRange(rangeKey);

    if (rangeKey === 'custom') {
      const startValue = trendStartInput.value || trendCustomRange.start;
      const endValue = trendEndInput.value || trendCustomRange.end;
      setTrendCustomRange(startValue, endValue);

      if (startValue === '' || endValue === '') {
        setTrendMessage('', 'info', false);
        return;
      }

      const validationMessage = validateTrendCustomRange(startValue, endValue);
      if (validationMessage !== '') {
        setTrendMessage(validationMessage, 'error', true);
        setTrendEmptyState(false, '');
        return;
      }

      setTrendMessage('', 'info', false);
      debounceTrendFetch({
        start_date: startValue,
        end_date: endValue
      });
      return;
    }

    setTrendMessage('', 'info', false);
    debounceTrendFetch({
      range: rangeKey
    }, 150);
  }

  function handleTrendCustomInputChange() {
    if (String(activeTrendRange || '7') !== 'custom') {
      return;
    }

    const startValue = trendStartInput.value;
    const endValue = trendEndInput.value;
    setTrendCustomRange(startValue, endValue);

    if (startValue === '' || endValue === '') {
      setTrendMessage('', 'info', false);
      return;
    }

    const validationMessage = validateTrendCustomRange(startValue, endValue);
    if (validationMessage !== '') {
      setTrendMessage(validationMessage, 'error', true);
      setTrendEmptyState(false, '');
      return;
    }

    setTrendMessage('', 'info', false);
    debounceTrendFetch({
      start_date: startValue,
      end_date: endValue
    });
  }

  function bindTrendControls() {
    trendChipButtons.forEach((button) => {
      button.addEventListener('click', () => {
        handleTrendRangeChange(String(button.dataset.trendRange || '7'));
      });
    });
    trendStartInput.max = getTodayIso();
    trendEndInput.max = getTodayIso();
    trendStartInput.addEventListener('change', handleTrendCustomInputChange);
    trendEndInput.addEventListener('change', handleTrendCustomInputChange);
  }

  function setEmptyState(emptyEl, canvasEl, visible, message = 'No Data Available') {
    emptyEl.textContent = message;
    emptyEl.hidden = !visible;
    canvasEl.hidden = visible;
  }

  function getTrendWindow(windowKey) {
    const windows = trendData.windows || {};
    return windows[String(windowKey)] || { labels: [], bookings: [], cancellations: [] };
  }

  function renderPieChart() {
    const labels = Array.isArray(pieData.labels) ? pieData.labels : [];
    const values = Array.isArray(pieData.values) ? pieData.values.map((value) => Number(value) || 0) : [];
    const colors = Array.isArray(pieData.colors) && pieData.colors.length > 0
      ? pieData.colors
      : [theme.success, theme.warning, theme.danger];

    destroyChart(pieChart);

    if (typeof Chart === 'undefined' || !hasPositiveValues(values)) {
      setEmptyState(pieEmpty, pieCanvas, true);
      pieChart = null;
      return;
    }

    setEmptyState(pieEmpty, pieCanvas, false);
    pieChart = new Chart(pieCanvas, {
      type: 'pie',
      data: {
        labels: labels.length > 0 ? labels : ['Confirmed', 'Waiting', 'Cancelled'],
        datasets: [{
          data: values,
          backgroundColor: colors,
          borderColor: '#ffffff',
          borderWidth: 2,
          hoverOffset: 6
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        layout: {
          padding: 8
        },
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              usePointStyle: true,
              pointStyle: 'circle',
              boxWidth: 10,
              boxHeight: 10,
              padding: 18,
              color: theme.text,
              font: {
                family: 'Poppins'
              }
            }
          },
          tooltip: {
            callbacks: {
              label(context) {
                return `${context.label}: ${formatCount(context.parsed)}`;
              }
            }
          }
        }
      }
    });
  }

  function renderRoutesChart() {
    const labels = Array.isArray(routeData.labels) ? routeData.labels : [];
    const values = Array.isArray(routeData.values) ? routeData.values.map((value) => Number(value) || 0) : [];
    const barColor = routeData.color || theme.primary;
    const hoverColor = routeData.hover_color || '#111827';

    destroyChart(routesChart);

    if (typeof Chart === 'undefined' || !hasPositiveValues(values)) {
      setEmptyState(routesEmpty, routesCanvas, true);
      routesChart = null;
      return;
    }

    setEmptyState(routesEmpty, routesCanvas, false);
    routesChart = new Chart(routesCanvas, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: 'Bookings',
          data: values,
          backgroundColor: barColor,
          hoverBackgroundColor: hoverColor,
          borderRadius: 10,
          borderSkipped: false,
          barThickness: 18
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: 'y',
        layout: {
          padding: {
            top: 8,
            right: 10,
            bottom: 8,
            left: 8
          }
        },
        scales: {
          x: {
            beginAtZero: true,
            grid: {
              color: theme.grid
            },
            ticks: {
              color: theme.muted,
              precision: 0
            }
          },
          y: {
            grid: {
              display: false
            },
            ticks: {
              color: theme.text,
              autoSkip: false,
              font: {
                family: 'Poppins',
                weight: '600'
              }
            }
          }
        },
        plugins: {
          legend: {
            display: false
          },
          tooltip: {
            callbacks: {
              label(context) {
                return `Bookings: ${formatCount(context.parsed.x)}`;
              }
            }
          }
        }
      }
    });
  }

  function init() {
    bindTrendControls();
    setTrendCustomRange('', '');
    setActiveTrendRange(defaultWindow);
    updateTrendChart(buildTrendStateFromWindow(defaultWindow));
    void loadTrendRange({
      range: defaultWindow
    });
    renderPieChart();
    renderRoutesChart();
  }

  init();
})();
