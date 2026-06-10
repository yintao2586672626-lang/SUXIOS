window.SUXI_SIMULATION_STATIC = (() => {
    const defaultSimulationInput = {
        roomCount: 86,
        decorationInvestment: 1600000,
        decorationHardCost: 1200000,
        decorationSoftCost: 300000,
        fireSafetyCost: 60000,
        signageDesignCost: 40000,
        furnitureInvestment: 520000,
        roomFurnitureCost: 320000,
        applianceEquipmentCost: 140000,
        linenSuppliesCost: 40000,
        techSystemCost: 20000,
        openingCost: 180000,
        licensePermitCost: 30000,
        openingMarketingCost: 70000,
        recruitmentTrainingCost: 50000,
        openingMaterialCost: 30000,
        otherInvestment: 120000,
        contingencyCost: 80000,
        rentDepositCost: 0,
        otherProjectCost: 40000,
        adr: 268,
        occupancyRate: 76,
        weekdayDays: 22,
        weekdayAdr: 248,
        weekdayOccupancyRate: 74,
        weekendDays: 6,
        weekendAdr: 298,
        weekendOccupancyRate: 82,
        holidayDays: 2,
        holidayAdr: 338,
        holidayOccupancyRate: 88,
        otherIncome: 18000,
        breakfastIncome: 8000,
        meetingIncome: 3500,
        retailIncome: 2200,
        parkingLaundryIncome: 1800,
        otherMiscIncome: 2500,
        monthlyRent: 180000,
        baseRentCost: 165000,
        propertyManagementCost: 15000,
        laborCost: 72000,
        frontDeskLaborCost: 22000,
        housekeepingLaborCost: 28000,
        managementLaborCost: 14000,
        socialSecurityCost: 8000,
        utilityCost: 26000,
        electricityCost: 15000,
        waterGasCost: 5000,
        networkEnergyCost: 6000,
        otaCommissionRate: 12,
        ctripRevenueShare: 50,
        ctripCommissionRate: 12,
        meituanRevenueShare: 30,
        meituanCommissionRate: 10,
        otherOtaRevenueShare: 20,
        otherOtaCommissionRate: 15,
        consumableCost: 18000,
        roomConsumableCost: 9000,
        cleaningSuppliesCost: 4000,
        linenReplacementCost: 5000,
        maintenanceCost: 12000,
        routineRepairCost: 5000,
        equipmentMaintenanceCost: 4000,
        roomRenovationReserve: 3000,
        otherFixedCost: 30000,
        marketingSystemCost: 9000,
        insuranceTaxCost: 6000,
        adminMiscCost: 15000,
    };
    const benchmarkModelDetailFields = [
        { key: 'competitor_count', label: '竞品数量（家）' },
        { key: 'avg_competitor_price', label: '竞品均价（元）' },
        { key: 'avg_competitor_score', label: '竞品均分', step: 0.1 },
        { key: 'avg_review_count', label: '平均点评量' },
        { key: 'ota_heat_index', label: 'OTA热度指数' },
        { key: 'traffic_radius_km', label: '采样半径（km）', step: 0.1 },
    ];
    const collaborationStatusOptions = ['未开始', '进行中', '已完成', '风险'];
    const expansionRecordPageTypes = {
        'market-evaluation': 'market',
        'market-eval': 'market',
        'benchmark-model': 'benchmark',
        'collaboration-efficiency': 'collaboration',
        'sync-efficiency': 'collaboration',
    };
    const transferPricingFields = [
        { key: 'hotel_name', label: '酒店名称', type: 'text', full: true },
        { key: 'location', label: '城市/商圈', type: 'text', full: true },
        { key: 'room_count', label: '房间数', type: 'number' },
        { key: 'monthly_revenue', label: '月营业额（万元）', type: 'number' },
        { key: 'monthly_rent', label: '月租金（万元）', type: 'number' },
        { key: 'labor_cost', label: '人工成本（万元）', type: 'number' },
        { key: 'utility_cost', label: '水电能耗（万元）', type: 'number' },
        { key: 'ota_commission', label: 'OTA佣金（万元）', type: 'number' },
        { key: 'other_fixed_cost', label: '其他固定成本（万元）', type: 'number' },
        { key: 'decoration_investment', label: '装修投入（万元）', type: 'number' },
        { key: 'remaining_lease_months', label: '剩余租期（月）', type: 'number' },
        { key: 'expected_transfer_price', label: '业主预期转让价（万元）', type: 'number' },
        { key: 'occupancy_rate', label: '入住率（%）', type: 'number' },
        { key: 'adr', label: 'ADR（元）', type: 'number' },
        { key: 'rating', label: '评分', type: 'number' },
        { key: 'order_count', label: '订单量', type: 'number' },
    ];
    const transferTimingCompareFields = [
        { key: 'current_revenue', label: '近30天营业额（万元）' },
        { key: 'previous_revenue', label: '年度30天营业额（万元）' },
        { key: 'current_orders', label: '近30天订单量' },
        { key: 'previous_orders', label: '年度30天订单量' },
        { key: 'current_adr', label: '近30天ADR（元）' },
        { key: 'previous_adr', label: '年度ADR（元）' },
        { key: 'current_occupancy_rate', label: '近30天入住率（%）' },
        { key: 'previous_occupancy_rate', label: '年度入住率（%）' },
    ];
    const transferTimingNumberFields = [
        { key: 'rating', label: '评分' },
        { key: 'holiday_days', label: '距离节假日天数' },
    ];
    const transferTimingDataFields = [
        { key: 'exposure', label: '曝光', hint: 'OTA曝光口径，缺失时填0', min: 0 },
        { key: 'visitors', label: '访客', hint: 'OTA访客/浏览口径', min: 0 },
        { key: 'conversion_rate', label: '转化率（%）', hint: '平台展示百分比，不按小数填', min: 0, step: 0.1 },
        { key: 'order_count', label: '订单量', hint: '近30天有效订单', min: 0 },
        { key: 'room_nights', label: '间夜', hint: '近30天已售间夜', min: 0 },
    ];
    const simulationCostFields = [
        { key: 'monthlyRent', label: '月租金' },
        { key: 'laborCost', label: '人工成本' },
        { key: 'utilityCost', label: '水电成本' },
        { key: 'otaCommissionRate', label: 'OTA佣金率(%)' },
        { key: 'consumableCost', label: '耗品成本' },
        { key: 'maintenanceCost', label: '维修成本' },
        { key: 'otherFixedCost', label: '其他固定成本' },
    ];
    const simulationCostFieldGroups = [
        { title: '月租金', totalKey: 'monthlyRent', fields: [{ key: 'baseRentCost', label: '基础租金' }, { key: 'propertyManagementCost', label: '物业/公区费' }] },
        { title: '人工成本', totalKey: 'laborCost', fields: [{ key: 'frontDeskLaborCost', label: '前厅人工' }, { key: 'housekeepingLaborCost', label: '客房人工' }, { key: 'managementLaborCost', label: '店长/管理岗' }, { key: 'socialSecurityCost', label: '社保及福利' }] },
        { title: '水电成本', totalKey: 'utilityCost', fields: [{ key: 'electricityCost', label: '电费' }, { key: 'waterGasCost', label: '水费/燃气' }, { key: 'networkEnergyCost', label: '网络及能耗杂费' }] },
        { title: '耗品成本', totalKey: 'consumableCost', fields: [{ key: 'roomConsumableCost', label: '客房一次性用品' }, { key: 'cleaningSuppliesCost', label: '清洁用品' }, { key: 'linenReplacementCost', label: '布草洗涤/补充' }] },
        { title: '维修成本', totalKey: 'maintenanceCost', fields: [{ key: 'routineRepairCost', label: '日常维修' }, { key: 'equipmentMaintenanceCost', label: '设备维保' }, { key: 'roomRenovationReserve', label: '客房翻新预提' }] },
        { title: '其他固定成本', totalKey: 'otherFixedCost', fields: [{ key: 'marketingSystemCost', label: '营销/系统服务费' }, { key: 'insuranceTaxCost', label: '保险及税费' }, { key: 'adminMiscCost', label: '办公及杂项' }] },
    ];
    const simulationOtaCommissionChannelDefinitions = [
        { key: 'ctrip', label: '携程', shareKey: 'ctripRevenueShare', rateKey: 'ctripCommissionRate' },
        { key: 'meituan', label: '美团', shareKey: 'meituanRevenueShare', rateKey: 'meituanCommissionRate' },
        { key: 'otherOta', label: '其他OTA', shareKey: 'otherOtaRevenueShare', rateKey: 'otherOtaCommissionRate' },
    ];
    const simulationInvestmentFieldGroups = [
        { title: '装修工程', totalKey: 'decorationInvestment', fields: [{ key: 'decorationHardCost', label: '硬装工程' }, { key: 'decorationSoftCost', label: '软装改造' }, { key: 'fireSafetyCost', label: '消防/合规' }, { key: 'signageDesignCost', label: '设计与招牌' }] },
        { title: '家具设备', totalKey: 'furnitureInvestment', fields: [{ key: 'roomFurnitureCost', label: '客房家具' }, { key: 'applianceEquipmentCost', label: '电器设备' }, { key: 'linenSuppliesCost', label: '布草及首批耗材' }, { key: 'techSystemCost', label: 'PMS/网络/门锁' }] },
        { title: '开办筹备', totalKey: 'openingCost', fields: [{ key: 'licensePermitCost', label: '证照办理' }, { key: 'openingMarketingCost', label: '开业营销' }, { key: 'recruitmentTrainingCost', label: '招聘培训' }, { key: 'openingMaterialCost', label: '开业物料' }] },
        { title: '其他及预备', totalKey: 'otherInvestment', fields: [{ key: 'contingencyCost', label: '预备费' }, { key: 'rentDepositCost', label: '押金/保证金' }, { key: 'otherProjectCost', label: '其他项目' }] },
    ];
    const simulationRoomRevenueDefinitions = [
        { key: 'weekday', label: '平日', daysKey: 'weekdayDays', adrKey: 'weekdayAdr', occupancyKey: 'weekdayOccupancyRate' },
        { key: 'weekend', label: '周末', daysKey: 'weekendDays', adrKey: 'weekendAdr', occupancyKey: 'weekendOccupancyRate' },
        { key: 'holiday', label: '节假日', daysKey: 'holidayDays', adrKey: 'holidayAdr', occupancyKey: 'holidayOccupancyRate' },
    ];
    const simulationOtherIncomeFields = [
        { key: 'breakfastIncome', label: '早餐/餐饮收入' },
        { key: 'meetingIncome', label: '会议/场租收入' },
        { key: 'retailIncome', label: '商品售卖收入' },
        { key: 'parkingLaundryIncome', label: '停车/洗衣收入' },
        { key: 'otherMiscIncome', label: '其他杂项收入' },
    ];

    const toNumberValue = (value, fallback = 0) => {
        const num = Number(value);
        return Number.isFinite(num) ? num : fallback;
    };

    function simulationGroupTotal(input, group) {
        const hasDetail = group.fields.some(field => Object.prototype.hasOwnProperty.call(input, field.key));
        if (!hasDetail) return toNumberValue(input[group.totalKey]);
        return group.fields.reduce((sum, field) => sum + toNumberValue(input[field.key]), 0);
    }

    function enrichSimulationTotals(input) {
        return { ...input };
    }

    function simulationRevenueSummaryFromInput(input, result = {}) {
        return {
            totalDays: null,
            availableRoomNights: result?.availableRoomNights ?? 0,
            occupiedRoomNights: result?.occupiedRoomNights ?? null,
            roomRevenue: result?.roomRevenue ?? 0,
            otherIncome: input?.otherIncome ?? 0,
            monthlyRevenue: result?.monthlyRevenue ?? 0,
            adr: input?.adr ?? 0,
            occupancyRate: input?.occupancyRate ?? 0,
        };
    }

    function simulationCostSummaryFromInput(input, result = {}) {
        return {
            fixedMonthlyCost: null,
            otaCommissionRate: input?.otaCommissionRate ?? 0,
            otaCommission: result?.otaCommission ?? 0,
            monthlyCost: result?.monthlyCost ?? 0,
        };
    }

    function generateRiskHints() {
        return [];
    }

    function normalizeTextList(items) {
        return Array.isArray(items)
            ? items.map(item => String(item || '').trim()).filter(Boolean)
            : [];
    }

    function normalizeSimulationModelAnalysis(raw) {
        if (!raw || typeof raw !== 'object') return null;
        const recommendations = Array.isArray(raw.recommendations)
            ? raw.recommendations.map(item => {
                const title = String(item?.title || '').trim();
                const detail = String(item?.detail || item?.content || '').trim();
                if (!title && !detail) return null;
                return {
                    priority: String(item?.priority || 'P1').trim() || 'P1',
                    title: title || '经营建议',
                    detail,
                };
            }).filter(Boolean)
            : [];
        const rawWatchPoints = raw.watch_points || raw.watchPoints || [];
        const watchPoints = Array.isArray(rawWatchPoints)
            ? rawWatchPoints.map(item => {
                const metric = String(item?.metric || '').trim();
                const threshold = String(item?.threshold || '').trim();
                const action = String(item?.action || '').trim();
                if (!metric && !threshold && !action) return null;
                return {
                    metric: metric || '关键指标',
                    threshold,
                    action,
                };
            }).filter(Boolean)
            : [];
        const assumptions = normalizeTextList(raw.assumptions);
        const analysis = {
            source: String(raw.source || '').trim(),
            model_key: String(raw.model_key || raw.modelKey || '').trim(),
            generated_at: String(raw.generated_at || raw.generatedAt || '').trim(),
            summary: String(raw.summary || '').trim(),
            decision: String(raw.decision || '').trim(),
            recommendations,
            watch_points: watchPoints,
            assumptions,
            error: String(raw.error || '').trim(),
        };
        return (analysis.summary || analysis.decision || recommendations.length || watchPoints.length || assumptions.length) ? analysis : null;
    }

    function normalizeSimulationInput(raw) {
        if (!raw) return {};
        const normalized = {
            roomCount: raw.roomCount ?? raw.room_count,
            decorationInvestment: raw.decorationInvestment ?? raw.decoration_investment,
            decorationHardCost: raw.decorationHardCost ?? raw.decoration_hard_cost,
            decorationSoftCost: raw.decorationSoftCost ?? raw.decoration_soft_cost,
            fireSafetyCost: raw.fireSafetyCost ?? raw.fire_safety_cost,
            signageDesignCost: raw.signageDesignCost ?? raw.signage_design_cost,
            furnitureInvestment: raw.furnitureInvestment ?? raw.equipment_investment,
            roomFurnitureCost: raw.roomFurnitureCost ?? raw.room_furniture_cost,
            applianceEquipmentCost: raw.applianceEquipmentCost ?? raw.appliance_equipment_cost,
            linenSuppliesCost: raw.linenSuppliesCost ?? raw.linen_supplies_cost,
            techSystemCost: raw.techSystemCost ?? raw.tech_system_cost,
            openingCost: raw.openingCost ?? raw.pre_opening_cost,
            licensePermitCost: raw.licensePermitCost ?? raw.license_permit_cost,
            openingMarketingCost: raw.openingMarketingCost ?? raw.opening_marketing_cost,
            recruitmentTrainingCost: raw.recruitmentTrainingCost ?? raw.recruitment_training_cost,
            openingMaterialCost: raw.openingMaterialCost ?? raw.opening_material_cost,
            otherInvestment: raw.otherInvestment ?? raw.other_investment,
            contingencyCost: raw.contingencyCost ?? raw.contingency_cost,
            rentDepositCost: raw.rentDepositCost ?? raw.rent_deposit_cost,
            otherProjectCost: raw.otherProjectCost ?? raw.other_project_cost,
            adr: raw.adr,
            occupancyRate: raw.occupancyRate ?? raw.occupancy_rate,
            weekdayDays: raw.weekdayDays ?? raw.weekday_days,
            weekdayAdr: raw.weekdayAdr ?? raw.weekday_adr,
            weekdayOccupancyRate: raw.weekdayOccupancyRate ?? raw.weekday_occupancy_rate,
            weekendDays: raw.weekendDays ?? raw.weekend_days,
            weekendAdr: raw.weekendAdr ?? raw.weekend_adr,
            weekendOccupancyRate: raw.weekendOccupancyRate ?? raw.weekend_occupancy_rate,
            holidayDays: raw.holidayDays ?? raw.holiday_days,
            holidayAdr: raw.holidayAdr ?? raw.holiday_adr,
            holidayOccupancyRate: raw.holidayOccupancyRate ?? raw.holiday_occupancy_rate,
            otherIncome: raw.otherIncome ?? raw.other_income,
            breakfastIncome: raw.breakfastIncome ?? raw.breakfast_income,
            meetingIncome: raw.meetingIncome ?? raw.meeting_income,
            retailIncome: raw.retailIncome ?? raw.retail_income,
            parkingLaundryIncome: raw.parkingLaundryIncome ?? raw.parking_laundry_income,
            otherMiscIncome: raw.otherMiscIncome ?? raw.other_misc_income,
            monthlyRent: raw.monthlyRent ?? raw.monthly_rent,
            baseRentCost: raw.baseRentCost ?? raw.base_rent_cost,
            propertyManagementCost: raw.propertyManagementCost ?? raw.property_management_cost,
            laborCost: raw.laborCost ?? raw.labor_cost,
            frontDeskLaborCost: raw.frontDeskLaborCost ?? raw.front_desk_labor_cost,
            housekeepingLaborCost: raw.housekeepingLaborCost ?? raw.housekeeping_labor_cost,
            managementLaborCost: raw.managementLaborCost ?? raw.management_labor_cost,
            socialSecurityCost: raw.socialSecurityCost ?? raw.social_security_cost,
            utilityCost: raw.utilityCost ?? raw.utility_cost,
            electricityCost: raw.electricityCost ?? raw.electricity_cost,
            waterGasCost: raw.waterGasCost ?? raw.water_gas_cost,
            networkEnergyCost: raw.networkEnergyCost ?? raw.network_energy_cost,
            otaCommissionRate: raw.otaCommissionRate ?? raw.ota_commission_rate,
            ctripRevenueShare: raw.ctripRevenueShare ?? raw.ctrip_revenue_share,
            ctripCommissionRate: raw.ctripCommissionRate ?? raw.ctrip_commission_rate,
            meituanRevenueShare: raw.meituanRevenueShare ?? raw.meituan_revenue_share,
            meituanCommissionRate: raw.meituanCommissionRate ?? raw.meituan_commission_rate,
            otherOtaRevenueShare: raw.otherOtaRevenueShare ?? raw.other_ota_revenue_share,
            otherOtaCommissionRate: raw.otherOtaCommissionRate ?? raw.other_ota_commission_rate,
            consumableCost: raw.consumableCost ?? raw.consumable_cost,
            roomConsumableCost: raw.roomConsumableCost ?? raw.room_consumable_cost,
            cleaningSuppliesCost: raw.cleaningSuppliesCost ?? raw.cleaning_supplies_cost,
            linenReplacementCost: raw.linenReplacementCost ?? raw.linen_replacement_cost,
            maintenanceCost: raw.maintenanceCost ?? raw.maintenance_cost,
            routineRepairCost: raw.routineRepairCost ?? raw.routine_repair_cost,
            equipmentMaintenanceCost: raw.equipmentMaintenanceCost ?? raw.equipment_maintenance_cost,
            roomRenovationReserve: raw.roomRenovationReserve ?? raw.room_renovation_reserve,
            otherFixedCost: raw.otherFixedCost ?? raw.other_fixed_cost,
            marketingSystemCost: raw.marketingSystemCost ?? raw.marketing_system_cost,
            insuranceTaxCost: raw.insuranceTaxCost ?? raw.insurance_tax_cost,
            adminMiscCost: raw.adminMiscCost ?? raw.admin_misc_cost,
            recommendedModel: raw.recommendedModel,
            targetCustomer: raw.targetCustomer,
        };
        const output = Object.fromEntries(Object.entries(normalized).filter(([, value]) => value !== undefined));
        const compatibility = [
            ['decorationInvestment', ['decorationHardCost', 'decorationSoftCost', 'fireSafetyCost', 'signageDesignCost']],
            ['furnitureInvestment', ['roomFurnitureCost', 'applianceEquipmentCost', 'linenSuppliesCost', 'techSystemCost']],
            ['openingCost', ['licensePermitCost', 'openingMarketingCost', 'recruitmentTrainingCost', 'openingMaterialCost']],
            ['otherInvestment', ['contingencyCost', 'rentDepositCost', 'otherProjectCost']],
        ];
        compatibility.forEach(([totalKey, detailKeys]) => {
            const hasDetail = detailKeys.some(key => Object.prototype.hasOwnProperty.call(output, key));
            if (!hasDetail && Object.prototype.hasOwnProperty.call(output, totalKey)) {
                detailKeys.forEach((key, index) => {
                    output[key] = index === 0 ? output[totalKey] : 0;
                });
            }
        });
        const hasRoomRevenueDetail = simulationRoomRevenueDefinitions.some(segment =>
            Object.prototype.hasOwnProperty.call(output, segment.daysKey)
            || Object.prototype.hasOwnProperty.call(output, segment.adrKey)
            || Object.prototype.hasOwnProperty.call(output, segment.occupancyKey)
        );
        if (!hasRoomRevenueDetail && (Object.prototype.hasOwnProperty.call(output, 'adr') || Object.prototype.hasOwnProperty.call(output, 'occupancyRate'))) {
            output.weekdayDays = 30;
            output.weekdayAdr = output.adr ?? defaultSimulationInput.adr;
            output.weekdayOccupancyRate = output.occupancyRate ?? defaultSimulationInput.occupancyRate;
            output.weekendDays = 0;
            output.weekendAdr = output.weekdayAdr;
            output.weekendOccupancyRate = output.weekdayOccupancyRate;
            output.holidayDays = 0;
            output.holidayAdr = output.weekdayAdr;
            output.holidayOccupancyRate = output.weekdayOccupancyRate;
        }
        const hasOtherIncomeDetail = simulationOtherIncomeFields.some(field => Object.prototype.hasOwnProperty.call(output, field.key));
        if (!hasOtherIncomeDetail && Object.prototype.hasOwnProperty.call(output, 'otherIncome')) {
            simulationOtherIncomeFields.forEach(field => {
                output[field.key] = field.key === 'otherMiscIncome' ? output.otherIncome : 0;
            });
        }
        simulationCostFieldGroups.forEach(group => {
            const hasDetail = group.fields.some(field => Object.prototype.hasOwnProperty.call(output, field.key));
            if (!hasDetail && Object.prototype.hasOwnProperty.call(output, group.totalKey)) {
                group.fields.forEach((field, index) => {
                    output[field.key] = index === 0 ? output[group.totalKey] : 0;
                });
            }
        });
        const hasOtaDetail = simulationOtaCommissionChannelDefinitions.some(channel =>
            Object.prototype.hasOwnProperty.call(output, channel.shareKey)
            || Object.prototype.hasOwnProperty.call(output, channel.rateKey)
        );
        if (!hasOtaDetail && Object.prototype.hasOwnProperty.call(output, 'otaCommissionRate')) {
            simulationOtaCommissionChannelDefinitions.forEach(channel => {
                output[channel.shareKey] = channel.key === 'otherOta' ? 100 : 0;
                output[channel.rateKey] = output.otaCommissionRate;
            });
        }
        return enrichSimulationTotals(output);
    }

    function validateSimulationInput(input) {
        const investmentFields = [
            'decorationInvestment', 'furnitureInvestment', 'openingCost', 'otherInvestment',
            ...simulationInvestmentFieldGroups.flatMap(group => group.fields.map(field => field.key)),
        ];
        const incomeFields = ['otherIncome', ...simulationOtherIncomeFields.map(field => field.key)];
        const roomAdrFields = simulationRoomRevenueDefinitions.map(segment => segment.adrKey);
        const roomDayFields = simulationRoomRevenueDefinitions.map(segment => segment.daysKey);
        const roomOccupancyFields = simulationRoomRevenueDefinitions.map(segment => segment.occupancyKey);
        const costFields = [
            'monthlyRent', 'laborCost', 'utilityCost', 'consumableCost', 'maintenanceCost', 'otherFixedCost',
            ...simulationCostFieldGroups.flatMap(group => group.fields.map(field => field.key)),
        ];
        const otaShareFields = simulationOtaCommissionChannelDefinitions.map(channel => channel.shareKey);
        const otaRateFields = simulationOtaCommissionChannelDefinitions.map(channel => channel.rateKey);
        if (toNumberValue(input.roomCount) <= 0) return '房间数必须大于0';
        if (toNumberValue(input.adr) <= 0) return 'ADR必须大于0';
        if (toNumberValue(input.occupancyRate) < 0 || toNumberValue(input.occupancyRate) > 100) return '入住率必须在0到100之间';
        if (roomAdrFields.some(key => toNumberValue(input[key]) < 0)) return '所有客房ADR不能为负数';
        if (roomDayFields.some(key => toNumberValue(input[key]) < 0)) return '所有客房收入天数不能为负数';
        if (roomDayFields.reduce((sum, key) => sum + toNumberValue(input[key]), 0) <= 0) return '客房收入天数必须大于0';
        if (roomDayFields.reduce((sum, key) => sum + toNumberValue(input[key]), 0) > 31) return '客房收入天数不能超过31天';
        if (roomOccupancyFields.some(key => toNumberValue(input[key]) < 0 || toNumberValue(input[key]) > 100)) return '所有客房入住率必须在0到100之间';
        if (otaShareFields.some(key => toNumberValue(input[key]) < 0 || toNumberValue(input[key]) > 100)) return '渠道收入占比必须在0到100之间';
        if (otaShareFields.reduce((sum, key) => sum + toNumberValue(input[key]), 0) > 100) return '渠道收入占比合计不能超过100%';
        if (otaRateFields.some(key => toNumberValue(input[key]) < 0 || toNumberValue(input[key]) > 100)) return '渠道佣金率必须在0到100之间';
        if (toNumberValue(input.otaCommissionRate) < 0 || toNumberValue(input.otaCommissionRate) > 100) return 'OTA佣金率必须在0到100之间';
        if (investmentFields.some(key => toNumberValue(input[key]) < 0)) return '所有投资字段不能为负数';
        if (incomeFields.some(key => toNumberValue(input[key]) < 0)) return '所有收入字段不能为负数';
        if (costFields.some(key => toNumberValue(input[key]) < 0)) return '所有成本字段不能为负数';
        return '';
    }

    return {
        defaultSimulationInput,
        benchmarkModelDetailFields,
        collaborationStatusOptions,
        expansionRecordPageTypes,
        transferPricingFields,
        transferTimingCompareFields,
        transferTimingNumberFields,
        transferTimingDataFields,
        simulationCostFields,
        simulationCostFieldGroups,
        simulationOtaCommissionChannelDefinitions,
        simulationInvestmentFieldGroups,
        simulationRoomRevenueDefinitions,
        simulationOtherIncomeFields,
        simulationGroupTotal,
        simulationRevenueSummaryFromInput,
        simulationCostSummaryFromInput,
        generateRiskHints,
        normalizeSimulationModelAnalysis,
        normalizeSimulationInput,
        validateSimulationInput,
    };
})();
