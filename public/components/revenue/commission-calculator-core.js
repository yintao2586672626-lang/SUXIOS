(function () {
  'use strict';

  // Manual scenario calculations only; inputs are not verified OTA facts.
  // Money uses cents; rates use tenths of a percentage point. Integer units
  // preserve commission fractions, and BigInt makes the final ceiling exact.
  function parseNumber(raw, label, min, max, decimals) {
    const text = String(raw == null ? '' : raw).trim();
    if (!text) throw new Error('请填写' + label + '，空白不会按0计算。');
    const pattern = decimals === 0 ? /^\d+$/ : new RegExp('^\\d+(?:\\.\\d{1,' + decimals + '})?$');
    const value = Number(text);
    if (!pattern.test(text) || !Number.isFinite(value) || value < min || value > max) {
      throw new Error(label + '需在' + min + '—' + max + '之间，' + (decimals ? '最多' + decimals + '位小数。' : '请填写整数。'));
    }
    return value;
  }

  function calculateCommission(raw) {
    const price = parseNumber(raw.price, '平均房价', 0.01, 1000000, 2);
    const nights = parseNumber(raw.nights, '调整前已入住间夜', 1, 1000000, 0);
    const oldRate = parseNumber(raw.oldRate, '调整前佣金', 10, 15, 1);
    const newRate = parseNumber(raw.newRate, '调整后佣金', 10, 15, 1);
    const costEnabled = raw.costEnabled === true;
    const cost = costEnabled ? parseNumber(raw.cost, '单间变动成本', 0, 1000000, 2) : null;
    const priceCents = Math.round(price * 100);
    const oldTenths = Math.round(oldRate * 10);
    const newTenths = Math.round(newRate * 10);
    const oldRevenueUnits = priceCents * (1000 - oldTenths);
    const newRevenueUnits = priceCents * (1000 - newTenths);
    const oldUnits = costEnabled ? oldRevenueUnits - Math.round(cost * 100) * 1000 : oldRevenueUnits;
    const newUnits = costEnabled ? newRevenueUnits - Math.round(cost * 100) * 1000 : newRevenueUnits;
    if (oldUnits <= 0) throw new Error('调整前每间夜净贡献已经不为正，常规增长/流失门槛不适用。请先检查房价与成本。');
    if (newUnits <= 0) throw new Error('调整后每间夜净贡献不为正，靠增加订单无法维持原来的正净贡献。请检查佣金、房价与成本。');
    const oldTotalUnits = BigInt(nights) * BigInt(oldUnits);
    const minimumBig = (oldTotalUnits + BigInt(newUnits) - 1n) / BigInt(newUnits);
    if (minimumBig > BigInt(Number.MAX_SAFE_INTEGER)) throw new Error('最低间夜量超出可精确显示范围，请缩小测算规模。');
    const minimumNights = Number(minimumBig);
    return { price, nights, oldRate, newRate, costEnabled, cost, oldUnits, newUnits,
      oldMargin: oldUnits / 100000, newMargin: newUnits / 100000,
      percent: (oldUnits - newUnits) / newUnits * 100, minimumNights,
      direction: Math.sign(oldUnits - newUnits), oldTotalUnits,
      minimumTotalUnits: minimumBig * BigInt(newUnits) };
  }

  function calculateForecast(result, rawNights) {
    if (String(rawNights == null ? '' : rawNights).trim() === '') return null;
    const nights = parseNumber(rawNights, '预计调整后间夜', 0, 1000000, 0);
    const totalUnits = BigInt(nights) * BigInt(result.newUnits);
    return { nights, totalUnits, differenceUnits: totalUnits - result.oldTotalUnits };
  }

  window.SUXI_COMMISSION_CALCULATOR_CORE = Object.freeze({ calculateCommission, calculateForecast });
})();
