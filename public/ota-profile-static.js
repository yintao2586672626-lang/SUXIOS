window.SUXI_OTA = (() => {
    const resolvePlatformProfileLoginDataPeriod = (targetDate = '', businessDate = '') => {
        const normalizedTargetDate = String(targetDate || '').trim();
        const normalizedBusinessDate = String(businessDate || '').trim();
        if (!normalizedTargetDate || !normalizedBusinessDate) return '';
        return normalizedTargetDate >= normalizedBusinessDate ? 'realtime_snapshot' : 'historical_daily';
    };

    const resolvePlatformProfileLoginCaptureSections = ({
        binding = {}, item = {}, fallbackSections = '', defaultSections = [],
    } = {}) => {
        const configured = binding?.capture_sections || item?.capture_sections || '';
        const source = Array.isArray(configured || fallbackSections)
            ? (configured || fallbackSections)
            : String(configured || fallbackSections || '').split(/[,\s]+/);
        const sections = source.map(value => String(value || '').trim()).filter(Boolean);
        return sections.length ? sections : [...(Array.isArray(defaultSections) ? defaultSections : [])];
    };

    const buildPlatformProfileLoginPayload = ({
        platform = '', systemHotelId = '', hotelName = '', item = null, options = {},
        businessDate = '', platformData = {}, normalizeMeituanSections = sections => sections,
    } = {}) => {
        if (!systemHotelId) return null;
        const safeItem = item && typeof item === 'object' ? item : {};
        const dataSourceId = Number(safeItem.data_source_id || safeItem.dataSourceId || 0);
        const dataDate = String(safeItem.data_date || safeItem.dataDate || safeItem.target_date || safeItem.targetDate || businessDate).trim();
        const rawSections = resolvePlatformProfileLoginCaptureSections({
            binding: safeItem.binding || {},
            item: safeItem,
            fallbackSections: platformData.sections,
            defaultSections: platform === 'ctrip' ? ['default'] : [],
        });
        const requestedSyncSections = options?.captureSections;
        const rawSyncSections = requestedSyncSections === undefined || requestedSyncSections === null || requestedSyncSections === ''
            ? []
            : resolvePlatformProfileLoginCaptureSections({
                item: { capture_sections: requestedSyncSections },
            });
        const syncContract = {
            system_hotel_id: systemHotelId,
            data_source_id: dataSourceId || undefined,
            bind_data_source: true,
            sync_after_login: !!(safeItem.sync_after_login || safeItem.syncAfterLogin || dataSourceId > 0) || undefined,
            data_date: dataDate || undefined,
            data_period: resolvePlatformProfileLoginDataPeriod(dataDate, businessDate) || undefined,
            sync_capture_sections: rawSyncSections.length ? rawSyncSections : undefined,
        };
        if (platform === 'ctrip') {
            return {
                ...syncContract,
                profile_id: platformData.profileId,
                hotel_id: platformData.hotelId,
                hotel_name: hotelName,
                sections: rawSections,
            };
        }
        const sections = typeof normalizeMeituanSections === 'function'
            ? normalizeMeituanSections(rawSections)
            : rawSections;
        if (syncContract.sync_capture_sections) {
            syncContract.sync_capture_sections = typeof normalizeMeituanSections === 'function'
                ? normalizeMeituanSections(syncContract.sync_capture_sections)
                : syncContract.sync_capture_sections;
        }
        const storeId = String(platformData.storeId || '').trim();
        return {
            ...syncContract,
            store_id: storeId,
            poi_id: platformData.poiId || storeId,
            poi_name: platformData.poiName || hotelName,
            partner_id: platformData.partnerId || '',
            ads_url: sections.includes('ads') ? (platformData.adsUrl || '') : '',
            sections,
        };
    };

    const preferredBrowserProfileDataSource = (sources = []) => {
        const score = (source = {}) => {
            const config = source?.config && typeof source.config === 'object' ? source.config : {};
            const status = String(source?.status || '').trim().toLowerCase();
            const statusRank = ({ success: 0, ready: 1, partial_success: 2, failed: 3, waiting_config: 4 })[status] ?? 5;
            return (source?.current_session_verified === true ? 0 : 1) * 1e15
                + (source?.profile_reusable === true ? 0 : 1) * 1e14
                + ((config.source_projection_ids || []).some(id => Number(id) > 0) ? 1 : 0) * 1e13
                + statusRank * 1e10
                + Number(source?.id || 0);
        };
        return [...(Array.isArray(sources) ? sources : [])]
            .sort((left, right) => score(left) - score(right))[0] || null;
    };

    return {
        buildPlatformProfileLoginPayload,
        preferredBrowserProfileDataSource,
    };
})();
