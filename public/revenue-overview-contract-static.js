(function () {
    'use strict';

    const REVENUE_OVERVIEW_AS_OF_DATE_CONTRACT_VERSION = 'revenue_overview_as_of_date.v1';
    const revenueAiIsoDate = (value) => {
        const text = String(value || '').trim();
        if (!/^\d{4}-\d{2}-\d{2}$/.test(text)) return '';
        const [year, month, day] = text.split('-').map(Number);
        const date = new Date(Date.UTC(year, month - 1, day));
        return date.toISOString().slice(0, 10) === text ? text : '';
    };
    const resolveRevenueOverviewAsOfDate = (overview = null) => {
        const payload = overview && typeof overview === 'object' ? overview : {};
        const asOfDate = revenueAiIsoDate(payload.as_of_date);
        const contractVersion = String(payload.as_of_date_contract_version || '').trim();
        if (contractVersion !== REVENUE_OVERVIEW_AS_OF_DATE_CONTRACT_VERSION) {
            return {
                ok: false,
                status: 'blocked',
                asOfDate: '',
                contractVersion,
                message: 'Revenue AI 总览缺少固定的数据基准日合同，已阻止展示与快照保存',
            };
        }
        if (!asOfDate) {
            return {
                ok: false,
                status: 'blocked',
                asOfDate: '',
                contractVersion,
                message: 'Revenue AI 总览的数据基准日无效，已阻止展示与快照保存',
            };
        }
        return {
            ok: true,
            status: 'ready',
            asOfDate,
            contractVersion,
            message: `数据基准日 ${asOfDate}（Asia/Shanghai）`,
        };
    };

    const resolveRevenueAiBusinessDate = ({ overview = null, selectedDate = '' } = {}) => {
        const overviewDate = String(overview?.business_date || '').trim();
        if (revenueAiIsoDate(overviewDate)) return overviewDate;
        const explicitDate = String(selectedDate || '').trim();
        return revenueAiIsoDate(explicitDate);
    };


    window.SUXI_REVENUE_OVERVIEW_CONTRACT_STATIC = Object.freeze({
        REVENUE_OVERVIEW_AS_OF_DATE_CONTRACT_VERSION,
        revenueAiIsoDate,
        resolveRevenueOverviewAsOfDate,
        resolveRevenueAiBusinessDate,
    });
})();
