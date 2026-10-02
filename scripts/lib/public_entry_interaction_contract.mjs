export function inspectPlatformSyncLogRefreshContract(source) {
  const schedulerSource = source.slice(
    source.indexOf('const schedulePlatformSyncLogPanelRefresh ='),
    source.indexOf('const schedulePlatformAutoFetchPanelLoad =')
  );
  return {
    file: 'public/index.html + public/app-main.js + resources/frontend/app-template.html',
    label: 'platform source log button uses the non-blocking sync-log scheduler',
    ok: source.includes('@click="schedulePlatformSyncLogPanelRefresh({ force: true })"')
      || (schedulerSource.includes('const refreshPlatformSyncHistory = () => schedulePlatformSyncLogPanelRefresh({ force: true });')
        && source.includes('@click="refreshPlatformSyncHistory"')),
    detail: 'refresh button calls the forced shared scheduler directly or through its exact refreshPlatformSyncHistory alias',
  };
}

export function inspectHomeTrendSamplesContract(source) {
  const start = source.indexOf('const renderHomeTrendChart =');
  const chartSource = start >= 0 ? source.slice(start, start + 5000) : '';
  const samplesGuard = chartSource.indexOf('if (!homeTrendHasSamples.value) {');
  const chartLibrary = chartSource.indexOf('const ChartLib = window.Chart;');
  return {
    file: 'public/app-main.js',
    label: 'home trend chart does not load Chart.js until usable trend samples exist',
    ok: samplesGuard >= 0 && chartLibrary > samplesGuard
      && /if \(!homeTrendHasSamples\.value\) \{\s+destroyHomeTrendChart\(\);\s+return;\s+\}/.test(chartSource),
    detail: 'the usable-samples guard destroys the chart and returns before the Chart.js library access',
  };
}
