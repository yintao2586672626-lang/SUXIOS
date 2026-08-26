window.SUXI_COMPETITION_DOWNLOAD_STATIC = (() => {
    const buildCtripDownloadRows = ({
        tab = 'sales',
        rows = [],
        rankOffset = 0,
        ctripTrafficChannelColumns = [],
        ctripSalesOrderColumns = [],
        helpers = {},
    } = {}) => {
        const {
            hasDisplayValue = value => value !== null && value !== undefined && value !== '',
            formatOptionalNumber = value => hasDisplayValue(value) ? String(value) : '-',
            ctripTrafficChannelText = () => '-',
            ctripTrafficChannelSecondaryText = () => '',
            ctripEarlyMorningTrafficText = () => '-',
            ctripRankEligibilityText = () => '未取得',
        } = helpers;
        const rankText = rank => rank ? `第${rank}名` : '-';
        const hotelNameColumnWidth = 340;
        const oneDecimalText = value => hasDisplayValue(value)
            ? Number(value).toLocaleString('zh-CN', { minimumFractionDigits: 1, maximumFractionDigits: 1 })
            : '-';
        const totalOrderIncludingCancelledText = (row = {}) => {
            const value = row.totalOrderIncludingCancelledEstimate;
            return value === null || value === undefined || value === ''
                ? '缺来源'
                : formatOptionalNumber(value);
        };
        const channelOrderText = (row = {}, column = {}) => {
            const primaryText = ctripTrafficChannelText(row, column);
            const secondaryText = ctripTrafficChannelSecondaryText(row, column);
            return secondaryText ? `${primaryText} · ${secondaryText}` : primaryText;
        };
        const safeRows = Array.isArray(rows) ? rows : [];
        const safeRankOffset = Number(rankOffset || 0);
        if (tab === 'traffic') {
            return {
                title: '流量与转化',
                columns: [
                    { label: '排名', width: 58, value: (_, index) => String(safeRankOffset + index + 1), align: 'center' },
                    { label: '酒店名称', width: hotelNameColumnWidth, value: row => row.hotelName || '-', align: 'left' },
                    { label: '携程APP访客量', width: 150, value: row => ctripEarlyMorningTrafficText(row, 'totalDetailNum'), align: 'right' },
                    { label: '携程转化率', width: 130, value: row => ctripEarlyMorningTrafficText(row, 'convertionRate', true), align: 'right' },
                    { label: ctripTrafficChannelColumns[0].label, width: 130, value: row => ctripTrafficChannelText(row, ctripTrafficChannelColumns[0]), align: 'right' },
                    { label: ctripTrafficChannelColumns[1].label, width: 130, value: row => ctripTrafficChannelText(row, ctripTrafficChannelColumns[1]), align: 'right' },
                    { label: '预订转化率', width: 130, value: row => row.bookingRateText || '待更新', align: 'right' },
                    { label: '携程点评分', width: 105, value: row => formatOptionalNumber(row.commentScore), align: 'right' },
                    { label: '去哪儿点评分', width: 115, value: row => formatOptionalNumber(row.qunarCommentScore), align: 'right' },
                ],
                rows: safeRows,
            };
        }
        if (tab === 'rank') {
            return {
                title: '榜单与排名',
                columns: [
                    { label: '排名', width: 58, value: (_, index) => String(safeRankOffset + index + 1), align: 'center' },
                    { label: '酒店名称', width: hotelNameColumnWidth, value: row => row.hotelName || '-', align: 'left' },
                    { label: '平均房价指数(ARI)', width: 145, value: row => row.ariText || '-', align: 'right' },
                    { label: '综合竞争力指数(SCI)', width: 145, value: row => row.sciText || '-', align: 'right' },
                    { label: '销售额排名', width: 130, value: row => rankText(row.amountRank), align: 'center' },
                    { label: '预订订单排名', width: 140, value: row => rankText(row.bookOrderNumRank), align: 'center' },
                    { label: '间夜量排名', width: 130, value: row => rankText(row.quantityRank), align: 'center' },
                    { label: '点评排名', width: 130, value: row => rankText(row.commentScoreRank), align: 'center' },
                    { label: '去哪转化排名', width: 140, value: row => rankText(row.qunarDetailCRRank), align: 'center' },
                    { label: '上榜状态', width: 120, value: row => ctripRankEligibilityText(row.commentScore), align: 'center' },
                ],
                rows: safeRows,
            };
        }
        return {
            title: '销售与订单',
            columns: [
                { label: '排名', width: 58, value: (_, index) => String(safeRankOffset + index + 1), align: 'center' },
                { label: '酒店名称', width: hotelNameColumnWidth, value: row => row.hotelName || '-', align: 'left' },
                { label: '酒店ID', width: 95, value: row => row.hotelId || '未返回', align: 'center' },
                { label: '离店销售额', width: 125, value: row => oneDecimalText(row.amount), align: 'right' },
                { label: '离店间夜', width: 125, value: row => formatOptionalNumber(row.quantity), align: 'right' },
                { label: '平均卖价', width: 115, value: row => oneDecimalText(row.adr), align: 'right' },
                { label: '总平台订单', width: 125, value: row => formatOptionalNumber(row.bookOrderNum), align: 'right' },
                { label: '含取消总单', width: 165, value: row => totalOrderIncludingCancelledText(row), align: 'right' },
                { label: ctripSalesOrderColumns[0].tableLabel, width: 145, value: row => channelOrderText(row, ctripSalesOrderColumns[0]), align: 'right' },
                { label: ctripSalesOrderColumns[1].tableLabel, width: 120, value: row => channelOrderText(row, ctripSalesOrderColumns[1]), align: 'right' },
                { label: ctripSalesOrderColumns[2].tableLabel, width: 180, value: row => channelOrderText(row, ctripSalesOrderColumns[2]), align: 'center' },
            ],
            rows: safeRows,
        };
    };

    return Object.freeze({ buildCtripDownloadRows });
})();
