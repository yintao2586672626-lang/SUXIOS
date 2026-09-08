(function () {
  'use strict';

  function parseNumber(raw, label, max, decimals) {
    const text = String(raw == null ? '' : raw).trim();
    if (!text) throw new Error('请填写' + label + '，未知值不会按0计算。');
    const pattern = new RegExp('^\\d+(?:\\.\\d{1,' + decimals + '})?$');
    const value = Number(text);
    if (!pattern.test(text) || !Number.isFinite(value) || value < 0 || (max !== null && value > max)) {
      const range = max === null ? '大于或等于0' : '在0—' + max + '之间';
      throw new Error(label + '需' + range + '，最多' + decimals + '位小数。');
    }
    return value;
  }

  function calculatePaidTraffic(baseResult, raw) {
    if (!baseResult || !Number.isFinite(baseResult.price) || baseResult.price <= 0
      || !Number.isSafeInteger(baseResult.nights) || baseResult.nights <= 0
      || !Number.isFinite(baseResult.oldRate) || !Number.isFinite(baseResult.newRate)
      || !Number.isSafeInteger(baseResult.oldUnits) || baseResult.oldUnits <= 0
      || !Number.isSafeInteger(baseResult.newUnits) || baseResult.newUnits <= 0
      || typeof baseResult.costEnabled !== 'boolean') {
      throw new Error('请先完成有效的佣金测算，再进行付费流量情景测算。');
    }
    if (!raw || !['commission_gap', 'manual'].includes(raw.budgetMode)) {
      throw new Error('请选择佣金差额预算或自填广告预算。');
    }

    // Manual scenario only. Historical ROI means attributed revenue / ad spend
    // (ROAS, a multiple), not profit ROI or a guarantee of future performance.
    const historicalRoi = parseNumber(raw.historicalRoi, '历史付费流量ROI（ROAS倍数）', 1000, 4);
    const incrementalityPercent = parseNumber(raw.incrementalityPercent, '归因营收中的真实增量比例', 100, 2);
    const incrementality = incrementalityPercent / 100;

    // Equal-budget comparison at the original room-night volume. Both margins
    // deduct the same known cost, so their integer-unit difference is exactly
    // price * nights * abs(newRate - oldRate) / 100, without rounding to cents.
    const commissionGap = Number(BigInt(baseResult.nights)
      * BigInt(Math.abs(baseResult.oldUnits - baseResult.newUnits))) / 100000;
    const budgetSource = raw.budgetMode;
    const budget = budgetSource === 'commission_gap'
      ? commissionGap : parseNumber(raw.budget, '广告预算', null, 2);

    // Advertising is the alternative strategy at the LOWER commission rate;
    // this budget is not added on top of the higher-commission strategy.
    const paidCommissionRate = Math.min(baseResult.oldRate, baseResult.newRate);
    const paidUnits = baseResult.oldRate <= baseResult.newRate ? baseResult.oldUnits : baseResult.newUnits;
    const unitAmount = paidUnits / 100000;
    const attributedRevenue = budget * historicalRoi;
    const attributedNights = attributedRevenue / baseResult.price;
    const incrementalRevenue = attributedRevenue * incrementality;
    const incrementalNights = attributedNights * incrementality;
    const amountAfterAds = incrementalNights * unitAmount - budget;
    const breakEvenRoi = incrementality > 0 ? baseResult.price / (incrementality * unitAmount) : null;
    const costIncluded = baseResult.costEnabled;
    const metricScope = costIncluded
      ? 'net_contribution_after_ads' : 'commission_revenue_after_ads_excludes_fulfillment';

    if (![commissionGap, budget, attributedRevenue, attributedNights, incrementalRevenue,
      incrementalNights, unitAmount, amountAfterAds].every(Number.isFinite)
      || (breakEvenRoi !== null && !Number.isFinite(breakEvenRoi))) {
      throw new Error('测算结果超出可计算范围，请缩小广告预算或核对输入。');
    }

    // Without click price / conversion evidence, do not infer a click count.
    return { budget, budgetSource, commissionGap, paidCommissionRate, historicalRoi,
      incrementalityPercent, attributedRevenue, attributedNights, incrementalRevenue,
      incrementalNights, unitAmount, amountAfterAds, breakEvenRoi, costIncluded, metricScope };
  }

  window.SUXI_COMMISSION_PAID_TRAFFIC_CORE = Object.freeze({ calculatePaidTraffic });
})();
