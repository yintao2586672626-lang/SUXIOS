window.SUXI_SIMULATION_STATIC = (() => {
    const defaultSimulationInput = {
        hotel_id: '',
        input_source_status: 'example_prefill_unverified',
        operatingScenario: null,
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
        { title: '预填示例：月租金', totalKey: 'monthlyRent', fields: [{ key: 'baseRentCost', label: '基础租金' }, { key: 'propertyManagementCost', label: '物业/公区费' }] },
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
        { title: '预填示例：装修工程', totalKey: 'decorationInvestment', fields: [{ key: 'decorationHardCost', label: '硬装工程' }, { key: 'decorationSoftCost', label: '软装改造' }, { key: 'fireSafetyCost', label: '消防/合规' }, { key: 'signageDesignCost', label: '设计与招牌' }] },
        { title: '家具设备', totalKey: 'furnitureInvestment', fields: [{ key: 'roomFurnitureCost', label: '客房家具' }, { key: 'applianceEquipmentCost', label: '电器设备' }, { key: 'linenSuppliesCost', label: '布草及首批耗材' }, { key: 'techSystemCost', label: 'PMS/网络/门锁' }] },
        { title: '开办筹备', totalKey: 'openingCost', fields: [{ key: 'licensePermitCost', label: '证照办理' }, { key: 'openingMarketingCost', label: '开业营销' }, { key: 'recruitmentTrainingCost', label: '招聘培训' }, { key: 'openingMaterialCost', label: '开业物料' }] },
        { title: '其他及预备', totalKey: 'otherInvestment', fields: [{ key: 'contingencyCost', label: '预备费' }, { key: 'rentDepositCost', label: '押金/保证金' }, { key: 'otherProjectCost', label: '其他项目' }] },
    ];
    const simulationRoomRevenueDefinitions = [
        { key: 'weekday', label: '预填示例：平日', daysKey: 'weekdayDays', adrKey: 'weekdayAdr', occupancyKey: 'weekdayOccupancyRate' },
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
            availableRoomNights: result?.availableRoomNights ?? null,
            occupiedRoomNights: result?.occupiedRoomNights ?? null,
            roomRevenue: result?.roomRevenue ?? null,
            otherIncome: input?.otherIncome ?? null,
            monthlyRevenue: result?.monthlyRevenue ?? null,
            adr: input?.adr ?? null,
            occupancyRate: input?.occupancyRate ?? null,
        };
    }

    function simulationCostSummaryFromInput(input, result = {}) {
        return {
            fixedMonthlyCost: null,
            otaCommissionRate: input?.otaCommissionRate ?? null,
            otaCommission: result?.otaCommission ?? null,
            monthlyCost: result?.monthlyCost ?? null,
        };
    }

    function buildSimulationInvestmentGroups(input = {}) {
        return simulationInvestmentFieldGroups.map(group => ({
            ...group,
            total: simulationGroupTotal(input, group),
        }));
    }

    function simulationInvestmentTotalFromGroups(groups = []) {
        return groups.reduce((sum, group) => sum + toNumberValue(group.total), 0);
    }

    function simulationInvestmentPerRoom(input = {}, totalInvestment = 0) {
        const roomCount = toNumberValue(input.roomCount);
        return roomCount > 0 ? toNumberValue(totalInvestment) / roomCount : 0;
    }

    function clampValue(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function buildSimulationRoomRevenueSegments(input = {}) {
        return simulationRoomRevenueDefinitions.map(segment => ({
            ...segment,
            days: Math.max(0, toNumberValue(input[segment.daysKey])),
            adr: Math.max(0, toNumberValue(input[segment.adrKey])),
            occupancy: clampValue(toNumberValue(input[segment.occupancyKey]), 0, 100),
            revenue: null,
        }));
    }

    function buildSimulationCostGroups(input = {}) {
        return simulationCostFieldGroups.map(group => ({
            ...group,
            total: simulationGroupTotal(input, group),
        }));
    }

    function buildSimulationOtaCommissionChannels(input = {}) {
        return simulationOtaCommissionChannelDefinitions.map(channel => ({
            ...channel,
            share: Math.max(0, toNumberValue(input[channel.shareKey])),
            rate: Math.max(0, toNumberValue(input[channel.rateKey])),
            weightedRate: null,
        }));
    }

    function isSimulationModelAnalysisVisible(analysis) {
        return !!(analysis && (
            analysis.summary
            || analysis.decision
            || (Array.isArray(analysis.recommendations) && analysis.recommendations.length)
            || (Array.isArray(analysis.watch_points) && analysis.watch_points.length)
            || (Array.isArray(analysis.assumptions) && analysis.assumptions.length)
        ));
    }

    function simulationModelSourceLabel(analysis) {
        const source = analysis?.source;
        if (source === 'deterministic_formula') return '确定性公式解释（假设测算）';
        if (source === 'llm') return 'AI\u6a21\u578b';
        if (source === 'fallback') return '\u672c\u5730\u6a21\u62df\u515c\u5e95\uff08\u975eAI\uff0c\u4e0d\u8fdb\u5165\u771f\u5b9e\u51b3\u7b56\uff09';
        return '\u6765\u6e90\u672a\u6838\u9a8c\uff08\u4e0d\u8fdb\u5165\u771f\u5b9e\u51b3\u7b56\uff09';
    }

    function generateRiskHints() {
        return [{
            title: '\u6a21\u62df\u6570\u636e\u8fb9\u754c',
            riskLevel: '\u9700\u590d\u6838',
            content: '\u672c\u9875\u53c2\u6570\u5c5e\u4e8e\u4eba\u5de5\u8f93\u5165\u4e0e\u672c\u5730\u6a21\u62df\uff0c\u672a\u66ff\u6362\u7684\u9884\u586b\u503c\u662f\u793a\u4f8b\u6570\u636e\uff1b\u6d4b\u7b97\u7ed3\u679c\u4e0d\u81ea\u52a8\u8fdb\u5165\u771f\u5b9e\u6295\u8d44\u51b3\u7b56\uff0c\u9700\u7528\u5df2\u6838\u9a8c\u9152\u5e97\u6570\u636e\u548c\u6765\u6e90\u8bc1\u636e\u590d\u6838\u3002',
            className: 'bg-amber-50 border-amber-200 text-amber-800',
        }];
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
            hotel_id: raw.hotel_id ?? raw.system_hotel_id,
            input_source_status: raw.input_source_status,
            operatingScenario: raw.operatingScenario ? JSON.parse(JSON.stringify(raw.operatingScenario)) : null,
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
        const scenarioError = validateOperatingScenario(input);
        if (scenarioError) return scenarioError;
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
        if (!Number.isInteger(Number(input.hotel_id)) || Number(input.hotel_id) <= 0) return '请选择当前账号有权限的酒店后再运行量化模拟';
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

    const simulationStateStorage = {
        save(input, result, scenarios, modelAnalysis = null) {
            localStorage.setItem('suxios_simulation_input', JSON.stringify(input));
            localStorage.setItem('suxios_simulation_result', JSON.stringify(result));
            localStorage.setItem('suxios_simulation_scenarios', JSON.stringify(scenarios));
            if (modelAnalysis) {
                localStorage.setItem('suxios_simulation_model_analysis', JSON.stringify(modelAnalysis));
            } else {
                localStorage.removeItem('suxios_simulation_model_analysis');
            }
            localStorage.setItem('suxios_report_simulation_seed', JSON.stringify({
                roomCount: input.roomCount,
                monthlyRent: input.monthlyRent,
                decorationInvestment: input.decorationInvestment,
                monthlyRevenue: result?.monthlyRevenue,
                monthlyCost: result?.monthlyCost,
                monthlyNetCashflow: result?.monthlyNetCashflow,
                totalInvestment: result?.totalInvestment,
                paybackMonths: result?.paybackMonths,
                breakEvenOccupancy: result?.breakEvenOccupancy,
                rentRatio: result?.rentRatio,
                riskLevel: result?.riskLevel
            }));
        },
        saveInputOnly(input) {
            localStorage.setItem('suxios_simulation_input', JSON.stringify(input));
            localStorage.removeItem('suxios_simulation_result');
            localStorage.removeItem('suxios_simulation_scenarios');
            localStorage.removeItem('suxios_simulation_model_analysis');
            localStorage.removeItem('suxios_report_simulation_seed');
        },
        load(defaultInput, normalizeInput, normalizeModelAnalysis) {
            let input = { ...defaultInput };
            try {
                const savedInput = JSON.parse(localStorage.getItem('suxios_simulation_input') || 'null');
                if (savedInput) input = { ...input, ...normalizeInput(savedInput) };
                const seed = JSON.parse(localStorage.getItem('suxios_simulation_seed') || 'null');
                if (seed) {
                    input = {
                        ...input,
                        ...normalizeInput(seed),
                        roomCount: seed.roomCount ?? seed.room_count ?? input.roomCount,
                        monthlyRent: seed.monthlyRent ?? seed.monthly_rent ?? input.monthlyRent,
                        decorationInvestment: seed.decorationInvestment ?? seed.decoration_budget ?? input.decorationInvestment
                    };
                }
                const savedResult = JSON.parse(localStorage.getItem('suxios_simulation_result') || 'null');
                const savedScenarios = JSON.parse(localStorage.getItem('suxios_simulation_scenarios') || 'null');
                const savedModelAnalysis = JSON.parse(localStorage.getItem('suxios_simulation_model_analysis') || 'null');
                const result = savedResult && Object.prototype.hasOwnProperty.call(savedResult, 'monthlyRevenue') ? savedResult : null;
                const scenarios = Array.isArray(savedScenarios) && savedScenarios[0] && Object.prototype.hasOwnProperty.call(savedScenarios[0], 'monthlyRevenue') ? savedScenarios : null;
                const modelAnalysis = normalizeModelAnalysis(savedModelAnalysis || result?.modelAnalysis || result?.model_analysis);
                return { input, result, scenarios, modelAnalysis };
            } catch (err) {
                return { input, result: null, scenarios: null, modelAnalysis: null };
            }
        },
    };

    function readinessBadgeClass(stage, readyStages, warningStages, dangerStages = []) {
        if (readyStages.includes(stage)) return 'bg-emerald-50 text-emerald-700 border-emerald-200';
        if (warningStages.includes(stage)) return 'bg-amber-50 text-amber-700 border-amber-200';
        if (dangerStages.includes(stage)) return 'bg-rose-50 text-rose-700 border-rose-200';
        return 'bg-gray-50 text-gray-600 border-gray-200';
    }

    function readinessMissingText(readiness, emptyText) {
        const missing = Array.isArray(readiness?.missing_evidence) ? readiness.missing_evidence : [];
        if (!missing.length) return emptyText;
        return `缺口：${missing.slice(0, 3).map(item => item.label || item.code).join('、')}`;
    }

    function simulationReadinessBadgeClass(stage) {
        return readinessBadgeClass(
            stage,
            ['execution_ready', 'review_ready'],
            ['approved_pending_execution', 'manual_input_only', 'partial_model'],
            ['data_recheck_required']
        );
    }

    function simulationReadinessMissingText(readiness) {
        return readinessMissingText(readiness, '暂无显式缺口；执行前仍需保留审批、任务和效果证据。');
    }




    function executionIntentIdFromRecord(record) {
        const result = record?.result || {};
        const direct = Number(record?.execution_intent_id || result.operation_execution_intent_id || result.execution_intent_id || 0);
        if (direct > 0) return direct;
        const tracking = result.execution_tracking;
        const rows = Array.isArray(tracking) ? tracking : (tracking && typeof tracking === 'object' ? [tracking] : []);
        for (let i = rows.length - 1; i >= 0; i -= 1) {
            const id = Number(rows[i]?.execution_intent_id || rows[i]?.id || 0);
            if (id > 0) return id;
        }
        return 0;
    }

    function simulationRecordSummary(record, { getHotelNameById = () => '', formatCurrency = value => value ?? '--' } = {}) {
        const hotelId = record?.truth_context?.hotel_id || record?.input?.hotel_id;
        const hotel = getHotelNameById(hotelId) || '未绑定酒店';
        const scenario = record?.summary?.operatingScenario;
        if (scenario) return `${hotel} · ${scenario.case_type === 'existing_hotel' ? '现有酒店' : '拟投资'} · ${scenario.start_month} 至 ${scenario.end_month} · 股东回本 ${operatingPaybackText(scenario.equity_payback)} · 假设测算`;
        const payback = record?.payback_months === null ? '不可回本' : `${record?.payback_months}个月`;
        return `${hotel} · 月净现金流 ${formatCurrency(record?.monthly_net_cashflow)} · 回本 ${payback} · ${record?.created_at || '-'}`;
    }

    function simulationTaskDisabled(record, { loadingId = 0 } = {}) {
        return Number(loadingId) === Number(record?.id || 0)
            || executionIntentIdFromRecord(record) > 0
            || !['execution_ready', 'review_ready'].includes(String(record?.execution_readiness?.stage || ''));
    }

    function simulationTaskLabel(record, { loadingId = 0 } = {}) {
        const intentId = executionIntentIdFromRecord(record);
        if (intentId > 0) return `待审批 #${intentId}`;
        return Number(loadingId) === Number(record?.id || 0) ? '保存中…' : '转待审批任务';
    }

    async function runSimulationExecutionIntentFlow({
        record, loadingId = 0, setLoadingId = () => {}, request, showToast,
        readinessMissingText, openWorkflowFormDialog, formatDate, loadRecords,
    } = {}) {
        const recordId = Number(record?.id || 0);
        const hotelId = Number(record?.truth_context?.hotel_id || record?.input?.hotel_id || 0);
        if (recordId <= 0 || hotelId <= 0 || loadingId) return null;
        if (executionIntentIdFromRecord(record) > 0) {
            showToast('该量化模拟已经关联待审批执行意图');
            return null;
        }
        if (!['execution_ready', 'review_ready'].includes(String(record?.execution_readiness?.stage || ''))) {
            showToast(readinessMissingText(record?.execution_readiness), 'warning');
            return null;
        }
        const today = formatDate(new Date());
        const dates = await openWorkflowFormDialog({
            title: '生成量化模拟待审批任务',
            description: '这里只保存待人工审批意图；不会自动执行、写OTA/PMS或把模拟值当作经营事实。',
            submitText: '保存待审批任务',
            fields: [
                { name: 'date_start', label: '开始日期', type: 'date', required: true, value: today },
                { name: 'date_end', label: '结束日期', type: 'date', required: true, value: today },
            ],
        });
        if (dates === null) return null;
        setLoadingId(recordId);
        try {
            const res = await request(`/simulation/records/${recordId}/execution-intent`, {
                method: 'POST',
                body: JSON.stringify({ hotel_id: hotelId, date_start: String(dates.date_start || ''), date_end: String(dates.date_end || '') }),
            });
            const intent = res.data?.execution_intent;
            if (res.code !== 200 || Number(intent?.source_record_id || 0) !== recordId
                || Number(intent?.hotel_id || 0) !== hotelId || String(intent?.status || '') !== 'pending_approval') {
                throw new Error(res.message || '量化模拟待审批任务未完成精确回读');
            }
            await loadRecords();
            showToast('量化模拟已转为待人工审批任务');
            return intent;
        } catch (error) {
            showToast(error.message || '量化模拟转任务失败', 'error');
            return null;
        } finally {
            setLoadingId(0);
        }
    }

    function simulationHotelSelectionIsPermitted(input = {}, hotels = []) {
        const hotelId = Number(input?.hotel_id || 0);
        return hotelId > 0 && (Array.isArray(hotels) ? hotels : []).some(
            hotel => Number(hotel?.id || 0) === hotelId
        );
    }

    function hydrateSimulationState({
        enabled = false, loadState, setInput, setResult, setScenarios,
        setRiskHints, setModelAnalysis, refresh,
    } = {}) {
        if (!enabled || typeof loadState !== 'function') return false;
        const loaded = loadState();
        setInput?.(loaded.input);
        if (loaded.result && loaded.scenarios) {
            setResult?.(loaded.result);
            setScenarios?.(loaded.scenarios);
            setRiskHints?.(generateRiskHints(loaded.result, loaded.scenarios));
            setModelAnalysis?.(loaded.modelAnalysis);
        } else {
            refresh?.(true);
        }
        return true;
    }

    async function runSimulationCalculationUiFlow({
        input = {}, hotels = [], projectName = '', ensureReady = async () => {},
        setInput = () => {}, saveInput = () => {}, setLoading = () => {},
        request, applyRecord, loadRecords, showToast = () => {}, isCurrent = () => true, clientRequestId,
    } = {}) {
        if (!simulationHotelSelectionIsPermitted(input, hotels)) {
            const clearedInput = { ...input, hotel_id: '' };
            setInput(clearedInput);
            saveInput(clearedInput);
            showToast('请选择当前账号可访问的酒店后再运行量化模拟', 'warning');
            return null;
        }
        setLoading(true);
        try {
            await ensureReady();
            if (!isCurrent()) return null;
            const result = await runSimulationCalculationFlow({ input, projectName, request, applyRecord, loadRecords, isCurrent, clientRequestId });
            if (isCurrent()) showToast('模拟已完成并精确回读保存记录');
            return result;
        } catch (error) {
            if (isCurrent()) showToast(error?.message || '量化模拟失败，请修复后端错误后重试', 'error');
            return null;
        } finally {
            if (isCurrent()) setLoading(false);
        }
    }

    async function runSimulationCalculationFlow({ input = {}, projectName = '', request, applyRecord, loadRecords, isCurrent = () => true, clientRequestId } = {}) {
        const payloadInput = JSON.parse(JSON.stringify(input));
        const hotelId = Number(payloadInput.hotel_id || 0);
        payloadInput.hotel_id = hotelId;
        payloadInput.system_hotel_id = hotelId;
        payloadInput.input_source_status = String(payloadInput.input_source_status || 'manual_unverified');
        const message = validateSimulationInput(payloadInput);
        if (message) throw new Error(message);
        const res = await request('/simulation/calculate', {
            method: 'POST',
            body: JSON.stringify({ hotel_id: hotelId, project_name: projectName || '量化模拟项目', input: payloadInput, client_request_id: clientRequestId }),
        });
        if (res.code !== 200) throw new Error(res.message || '量化模拟保存失败');
        if (!isCurrent()) return null;
        if (payloadInput.operatingScenario) {
            const expected = normalizedOperatingScenario(payloadInput.operatingScenario);
            const readback = res.data?.input?.operatingScenario;
            const sameScenario = Object.entries(expected).every(([key, value]) => readback?.[key] === value);
            if (Number(res.data?.truth_context?.hotel_id) !== hotelId || !res.data?.truth_context?.persistence?.readback_verified
                || !sameScenario) {
                throw new Error('保存回读的酒店或经营情景与本次输入不一致');
            }
        }
        applyRecord(res.data, true);
        await loadRecords();
        return res.data;
    }

    function createOperatingScenario(caseType = 'existing_hotel') {
        const now = new Date();
        return {
            case_type: caseType, case_name: caseType === 'existing_hotel' ? '现有酒店经营方案' : '拟投资方案',
            start_month: `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`,
            evidence_basis: 'assumptions', source_note: '示例假设，所有金额待人工核对', currency: 'CNY', monetary_unit: 'yuan',
            schema_version: 'quant-operating.v1', horizon_months: 60, target_payback_months: 36,
            ramp_months: 0, ramp_start_occupancy: 0, loan_amount: 0, annual_interest_rate: 0,
            loan_term_months: 0, opening_cash: 0, minimum_monthly_cashflow: 0,
        };
    }

    const operatingScenarioFields = [
        ['horizon_months', '测算期限（月）', 1, 360, 1], ['target_payback_months', '目标股东回本（月）', 1, 360, 1],
        ['ramp_months', '爬坡期（月；0表示直接稳定）', 0, 359, 1], ['ramp_start_occupancy', '爬坡起始入住率（%）', 0, 100, 0.1],
        ['loan_amount', '期初贷款（元）', 0, 1e11, 1], ['annual_interest_rate', '贷款年利率（%）', 0, 100, 0.1],
        ['loan_term_months', '贷款期限（月；无贷款填0）', 0, 360, 1], ['opening_cash', '自有可用现金（元）', 0, 1e11, 1],
        ['minimum_monthly_cashflow', '稳定期最低月现金目标（元）', 0, 1e11, 1],
    ].map(([key, label, min, max, step]) => ({ key, label, min, max, step }));

    function normalizedOperatingScenario(s) {
        const out = {};
        ['case_type', 'case_name', 'start_month', 'evidence_basis', 'source_note', 'currency', 'monetary_unit'].forEach(key => { out[key] = s[key]; });
        out.schema_version = 'quant-operating.v1';
        operatingScenarioFields.forEach(({ key }) => { out[key] = Number(s[key]); });
        return out;
    }

    function validateOperatingScenario(input) {
        const s = input?.operatingScenario;
        if (!s) return '';
        if (!['existing_hotel', 'proposed_investment'].includes(s.case_type) || !String(s.case_name || '').trim()) return '请选择案例类型并填写方案名称';
        if (!/^(19\d{2}|20\d{2}|21\d{2}|2200)-(0[1-9]|1[0-2])$/.test(s.start_month)) return '请填写有效起始月份';
        if (s.currency !== 'CNY' || s.monetary_unit !== 'yuan') return '所有金额须以人民币元输入';
        if (!['assumptions', 'ota_only', 'manual_pms_cost_unverified'].includes(s.evidence_basis) || !String(s.source_note || '').trim()) return '请填写输入来源及假设说明';
        for (const f of operatingScenarioFields) {
            const value = s[f.key];
            if (value === '' || value === null || value === undefined || typeof value === 'boolean' || !Number.isFinite(Number(value)) || Number(value) < f.min || Number(value) > f.max
                || (['horizon_months', 'target_payback_months', 'ramp_months', 'loan_term_months'].includes(f.key) && !Number.isInteger(Number(value)))) return `${f.label}缺失或超出范围`;
        }
        if (Number(s.target_payback_months) > Number(s.horizon_months) || Number(s.ramp_months) >= Number(s.horizon_months)) return '回本目标及爬坡期必须在测算期内';
        const totalDays = simulationRoomRevenueDefinitions.reduce((sum, r) => sum + Number(input[r.daysKey]), 0);
        const stableOccupancy = totalDays > 0 ? simulationRoomRevenueDefinitions.reduce((sum, r) => sum + Number(input[r.daysKey]) * Number(input[r.occupancyKey]), 0) / totalDays : Number(input.occupancyRate);
        if (Number(s.ramp_start_occupancy) > stableOccupancy) return '爬坡起始入住率不得高于稳定入住率';
        if (Number(s.loan_amount) > 0 ? Number(s.loan_term_months) < 1 : Number(s.loan_term_months) !== 0 || Number(s.annual_interest_rate) !== 0) return '有贷款须填期限；无贷款时期限及利率均须为0';
        const investment = simulationInvestmentTotalFromGroups(buildSimulationInvestmentGroups(input));
        if (Number(s.loan_amount) > investment) return '贷款不得超过总投资';
        const [year, month] = s.start_month.split('-').map(Number);
        const days = new Date(Date.UTC(year, month, 0)).getUTCDate();
        if (!Number.isInteger(Number(input.roomCount)) || ['weekdayDays', 'weekendDays', 'holidayDays'].some(k => !Number.isInteger(Number(input[k])))
            || ['weekdayDays', 'weekendDays', 'holidayDays'].reduce((sum, k) => sum + Number(input[k]), 0) !== days) return `房间数及天数须为整数，起始月天数合计须为${days}`;
        return '';
    }

    function operatingPaybackText(payback) {
        if (!payback) return '未测算';
        const labels = { never_within_horizon: '测算期内始终不回本', not_recovered_within_horizon: '测算期内未回本', no_initial_outlay: '无初始出资，回本不适用', no_initial_outlay_with_debt: '无初始出资，期末仍有债务' };
        return labels[payback.status] || (Number.isFinite(payback.months) ? `${payback.months.toFixed(2)}个月（假设）` : '未测算');
    }

    function compareOperatingRecords(records) {
        if (!Array.isArray(records) || records.length < 2) throw new Error('请选择至少两份已保存的经营情景');
        const first = records[0];
        const scope = r => JSON.stringify([r.truth_context?.tenant_id, r.truth_context?.hotel_id, r.input?.operatingScenario?.case_type,
            r.input?.operatingScenario?.start_month, r.input?.operatingScenario?.horizon_months, r.input?.operatingScenario?.currency, r.input?.operatingScenario?.monetary_unit]);
        if (records.some(r => !r.result?.operatingScenario || !r.truth_context?.persistence?.readback_verified || scope(r) !== scope(first))) throw new Error('比较须使用同租户、酒店、案例类型、起始月、期限和单位的精确回读记录');
        return records.map(r => ({ id: r.id, name: r.input.operatingScenario.case_name, input: r.input, ...r.result.operatingScenario }));
    }

    async function runSimulationArchiveFlow({ record, confirmAction, request, showToast, clearCurrent, loadRecords } = {}) {
        if (record?.access_policy?.mutation_allowed === false) {
            showToast('该历史模拟未绑定酒店，只读保留；请复用输入并保存为当前酒店的新记录后再归档。', 'warning');
            return false;
        }
        if (!record?.id || !confirmAction('确认归档该量化模拟记录？归档后将从历史列表隐藏。')) return false;
        try {
            const res = await request(`/simulation/records/${record.id}`, { method: 'DELETE' });
            if (res.code !== 200) throw new Error(res.message || '量化模拟记录归档失败');
            clearCurrent(record.id);
            await loadRecords();
            showToast('量化模拟记录已归档');
            return true;
        } catch (error) {
            showToast(error.message || '量化模拟记录归档失败', 'error');
            return false;
        }
    }

    const trimMetricZeros = (value) => String(value).replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');


    function benchmarkMetricValue(value, suffix = '', decimals = 0) {
        const number = Number(value);
        if (!Number.isFinite(number)) return '--';
        return `${trimMetricZeros(number.toFixed(decimals))}${suffix}`;
    }

    function buildBenchmarkModelDetailCards(metrics = {}) {
        return [
            { label: '竞品数量', value: benchmarkMetricValue(metrics.competitor_count, '家') },
            { label: '竞品均价', value: benchmarkMetricValue(metrics.avg_competitor_price, '元') },
            { label: '竞品均分', value: benchmarkMetricValue(metrics.avg_competitor_score, '分', 1) },
            { label: '平均点评量', value: benchmarkMetricValue(metrics.avg_review_count) },
            { label: 'OTA热度指数', value: benchmarkMetricValue(metrics.ota_heat_index, '%') },
            { label: '采样半径', value: benchmarkMetricValue(metrics.traffic_radius_km, 'km', 1) }
        ];
    }











    function buildSimulationMetricCards(baseSimulation = null, formatCurrency = value => value ?? '--') {
        if (!baseSimulation) {
            return [
                { label: '月总收入', value: '--' },
                { label: '月净现金流', value: '--' },
                { label: 'RevPAR', value: '--' },
                { label: '回本周期', value: '--' }
            ];
        }
        return [
            { label: '月总收入', value: formatCurrency(baseSimulation.monthlyRevenue) },
            { label: '月净现金流', value: formatCurrency(baseSimulation.monthlyNetCashflow) },
            { label: 'RevPAR', value: formatCurrency(baseSimulation.revPAR) },
            {
                label: '回本周期',
                value: baseSimulation.paybackMonths === null
                    ? '不可回本'
                    : (baseSimulation.paybackMonths === undefined || baseSimulation.paybackMonths === '' || !Number.isFinite(Number(baseSimulation.paybackMonths))
                        ? '--'
                        : `${Math.round(Number(baseSimulation.paybackMonths))}个月`)
            }
        ];
    }


    return {
        defaultSimulationInput,
        createOperatingScenario, operatingScenarioFields, validateOperatingScenario, normalizedOperatingScenario, operatingPaybackText, compareOperatingRecords,
        simulationCostFields,
        simulationCostFieldGroups,
        simulationOtaCommissionChannelDefinitions,
        simulationInvestmentFieldGroups,
        simulationRoomRevenueDefinitions,
        simulationOtherIncomeFields,
        simulationGroupTotal,
        simulationRevenueSummaryFromInput,
        simulationCostSummaryFromInput,
        buildSimulationInvestmentGroups,
        simulationInvestmentTotalFromGroups,
        simulationInvestmentPerRoom,
        buildSimulationRoomRevenueSegments,
        buildSimulationCostGroups,
        buildSimulationOtaCommissionChannels,
        isSimulationModelAnalysisVisible,
        simulationModelSourceLabel,
        generateRiskHints,
        normalizeSimulationModelAnalysis,
        normalizeSimulationInput,
        validateSimulationInput,
        simulationStateStorage,
        simulationReadinessBadgeClass,
        simulationReadinessMissingText,
        executionIntentIdFromRecord,
        simulationRecordSummary,
        simulationTaskDisabled,
        simulationTaskLabel,
        runSimulationExecutionIntentFlow,
        simulationHotelSelectionIsPermitted,
        hydrateSimulationState,
        runSimulationCalculationUiFlow,
        runSimulationCalculationFlow,
        runSimulationArchiveFlow,
        buildBenchmarkModelDetailCards,
        buildSimulationMetricCards,
        };
})();
