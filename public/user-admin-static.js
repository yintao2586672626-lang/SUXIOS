window.SUXI_USER_ADMIN_STATIC = (() => {
    const rolePermissionList = (role = {}) => {
        const raw = role?.permissions;
        if (Array.isArray(raw)) return raw.map(item => String(item));
        if (typeof raw === 'string' && raw.trim()) {
            try {
                const parsed = JSON.parse(raw);
                return Array.isArray(parsed) ? parsed.map(item => String(item)) : [];
            } catch (error) {
                return [];
            }
        }
        return [];
    };

    const roleHasAnyPermission = (role = {}, keys = []) => {
        const permissions = rolePermissionList(role);
        if (permissions.includes('all')) return true;
        return keys.some(key => permissions.includes(key));
    };

    const normalExternalDeniedPermissionGroups = [
        { label: '门店新增/编辑', keys: ['hotel.create', 'hotel.update', 'can_manage_own_hotels'] },
        { label: 'OTA采集', keys: ['ota.collect', 'ota.collect_batch', 'can_fetch_online_data'] },
        { label: 'OTA删除', keys: ['ota.delete', 'can_delete_online_data'] },
        { label: '数据导出', keys: ['ota.export', 'report.export', 'can_export_data'] },
        { label: 'AI执行', keys: ['ai.execute', 'can_use_ai_decision'] },
        { label: '用户/角色管理', keys: ['user.role_change', 'can_manage_users'] },
    ];

    const roleUnsafeExternalCapabilityLabels = (role = {}) => {
        const permissions = rolePermissionList(role);
        if (!permissions.length) return [];
        return normalExternalDeniedPermissionGroups
            .filter(group => permissions.includes('all') || group.keys.some(key => permissions.includes(key)))
            .map(group => group.label);
    };

    const issueStatusForRoleProfile = (profile = {}) => {
        if (profile.key === 'normal_user' && profile.issueBlockers?.length) {
            return {
                issueBlocked: true,
                issueStatusText: '先修角色权限',
                issueStatusDetail: profile.issueBlockers[0],
            };
        }
        if (profile.key === 'normal_user') {
            return {
                issueBlocked: false,
                issueStatusText: '可发普通账号',
                issueStatusDetail: '普通外发：只读授权门店 OTA 数据；创建时仍必须分配门店。',
            };
        }
        if (profile.key === 'beta_user') {
            return {
                issueBlocked: false,
                issueStatusText: '可发内测账号',
                issueStatusDetail: profile.canManageHotels ? '内测发放：适合可信内测方；可按角色能力绑定/管理自己门店。' : '内测发放：适合已分配门店的内测账号；创建时需确认门店范围。',
            };
        }
        return {
            issueBlocked: false,
            issueStatusText: '需人工复核',
            issueStatusDetail: profile.boundary || '自定义角色发放前需人工复核权限和门店范围。',
        };
    };

    const roleIssueProfile = (role = {}) => {
        const roleId = Number(role?.id || 0);
        const roleName = String(role?.name || '').trim();
        const roleCode = roleName || (roleId ? String(roleId) : '-');
        const level = Number(role?.level || 0);
        const canCollectOta = roleHasAnyPermission(role, ['ota.collect', 'can_fetch_online_data']);
        const canManageHotels = roleHasAnyPermission(role, ['hotel.create', 'can_manage_own_hotels']);
        const unsafeExternalCapabilityLabels = roleUnsafeExternalCapabilityLabels(role);
        const normalExternalIssueBlockers = unsafeExternalCapabilityLabels.length
            ? [`普通用户角色含高风险权限：${unsafeExternalCapabilityLabels.join('、')}。先在角色管理移除后再对外发放。`]
            : [];
        if (roleId === 1 || roleName === 'admin' || level === 1) {
            return {
                key: 'admin',
                title: '管理员',
                handoffType: '内部管理',
                roleCode,
                badge: '内部管理',
                badgeClass: 'border-red-100 bg-red-50 text-red-700',
                audience: '仅发给内部系统管理员',
                scope: '全部门店和全部系统配置',
                ota: '可查看、采集、删除 OTA 数据',
                allowed: '用户、角色、系统配置、全部门店和高风险管理操作',
                denied: '不用于内测或外部普通用户发放',
                boundary: '拥有用户、角色、系统配置和高风险操作权限，不用于内测或外部普通账号。',
                dataBoundary: '系统全局管理范围；不得作为内测或普通外发账号使用。',
                sendChecklist: '仅内部管理员使用；不要复制给内测或普通外部用户。',
                canCollectOta: true,
                canManageHotels: true,
                requiresHotelAssignment: false,
                roleId,
                userCount: 0,
                issueBlockers: [],
            };
        }
        if (roleId === 2 || roleName === 'beta_user' || level === 2) {
            return {
                key: 'beta_user',
                title: '内测用户',
                handoffType: '内测发放',
                roleCode,
                badge: canCollectOta ? '可采集' : '仅查看',
                badgeClass: canCollectOta ? 'border-orange-100 bg-orange-50 text-orange-700' : 'border-slate-200 bg-slate-50 text-slate-600',
                audience: '发给参与内测的酒店经营者或可信运营方',
                scope: canManageHotels ? '可新增/绑定自己门店，也可查看管理员分配门店' : '仅查看管理员分配门店',
                ota: canCollectOta ? '可按授权门店发起 OTA 查看和采集' : '只能查看授权门店 OTA 数据',
                allowed: canCollectOta ? '授权门店 OTA 查看/采集、内测功能体验' : '授权门店 OTA 查看、内测功能体验',
                denied: '不开放用户/角色管理、系统配置和跨门店数据',
                boundary: '不开放用户管理、角色权限和系统配置；OTA 结论仍限定在授权门店和 OTA 渠道范围。',
                dataBoundary: '仅授权门店的 OTA 渠道数据和内测功能，不代表全酒店经营数据。',
                sendChecklist: canManageHotels ? '确认内测范围、门店责任人和账号状态；初始密码走单独安全渠道。' : '先分配门店，确认账号状态正常；初始密码走单独安全渠道。',
                canCollectOta,
                canManageHotels,
                requiresHotelAssignment: !canManageHotels,
                roleId,
                userCount: 0,
                issueBlockers: [],
            };
        }
        if (roleId === 3 || roleName === 'normal_user' || level >= 3) {
            return {
                key: 'normal_user',
                title: '普通用户',
                handoffType: '普通外发',
                roleCode,
                badge: normalExternalIssueBlockers.length ? '需修权限' : '安全查看',
                badgeClass: normalExternalIssueBlockers.length ? 'border-amber-100 bg-amber-50 text-amber-700' : 'border-emerald-100 bg-emerald-50 text-emerald-700',
                audience: '发给外部普通试用者、门店员工或只读体验账号',
                scope: '必须分配门店；未分配则不应看到业务数据',
                ota: canCollectOta ? '当前角色含 OTA 采集权限，发外部账号前需复核' : '默认只查看授权门店 OTA 数据，不发起采集',
                allowed: '授权门店 OTA 数据查看、基础报表查看',
                denied: '不开放 OTA 采集、删除/导出、AI 执行、用户/角色管理和系统配置',
                boundary: normalExternalIssueBlockers.length ? normalExternalIssueBlockers[0] : '不允许新增门店、管理用户、修改系统配置；适合对外发放。',
                dataBoundary: '仅授权门店的 OTA 渠道只读数据；不代表全酒店经营数据，也不开放采集执行。',
                sendChecklist: normalExternalIssueBlockers.length ? '先修角色高风险权限，再分配门店和启用账号。' : '必须分配门店，确认账号状态正常；初始密码走单独安全渠道。',
                canCollectOta,
                canManageHotels,
                requiresHotelAssignment: true,
                roleId,
                userCount: 0,
                issueBlockers: normalExternalIssueBlockers,
            };
        }
        return {
            key: `role_${roleId || roleName || 'custom'}`,
            title: role?.display_name || roleName || '自定义角色',
            handoffType: '自定义发放',
            roleCode,
            badge: canCollectOta ? '含采集' : '自定义',
            badgeClass: canCollectOta ? 'border-amber-100 bg-amber-50 text-amber-700' : 'border-slate-200 bg-slate-50 text-slate-600',
            audience: '自定义角色，发放前按权限清单复核',
            scope: canManageHotels ? '可能包含门店管理能力' : '按管理员分配门店生效',
            ota: canCollectOta ? '包含 OTA 采集权限' : '未检测到 OTA 采集权限',
            allowed: '按角色权限清单生效',
            denied: '未在本页归类为标准内测或普通外发角色',
            boundary: '先确认角色权限、门店范围和账号状态，再发给外部用户。',
            dataBoundary: '按角色权限和门店授权生效；发放前需人工确认是否可外发。',
            sendChecklist: '人工复核权限清单、门店范围和账号状态后再发送。',
            canCollectOta,
            canManageHotels,
            requiresHotelAssignment: !canManageHotels,
            roleId,
            userCount: 0,
            issueBlockers: [],
        };
    };

    const rolePermissionTags = (profile = {}) => {
        if (profile.key === 'admin') {
            return [
                { text: '仅内部', class: 'border-red-100 bg-red-50 text-red-700' },
                { text: '可管用户/角色', class: 'border-red-100 bg-red-50 text-red-700' },
                { text: '不对外发放', class: 'border-slate-200 bg-white text-slate-600' },
            ];
        }
        if (profile.key === 'beta_user') {
            return [
                { text: '内测发放', class: 'border-orange-100 bg-orange-50 text-orange-700' },
                { text: profile.canCollectOta ? '可OTA采集' : 'OTA只读', class: profile.canCollectOta ? 'border-orange-100 bg-orange-50 text-orange-700' : 'border-slate-200 bg-white text-slate-600' },
                { text: profile.canManageHotels ? '可新增门店' : '需分配门店', class: profile.canManageHotels ? 'border-blue-100 bg-blue-50 text-blue-700' : 'border-amber-100 bg-amber-50 text-amber-700' },
            ];
        }
        if (profile.key === 'normal_user') {
            const tags = [
                { text: '普通外发', class: 'border-emerald-100 bg-emerald-50 text-emerald-700' },
                { text: profile.canCollectOta ? '含采集需复核' : 'OTA只读', class: profile.canCollectOta ? 'border-amber-100 bg-amber-50 text-amber-700' : 'border-emerald-100 bg-emerald-50 text-emerald-700' },
                { text: '必须分配门店', class: 'border-blue-100 bg-blue-50 text-blue-700' },
            ];
            if (profile.issueBlockers?.length) {
                tags.push({ text: '高风险待修', class: 'border-red-100 bg-red-50 text-red-700' });
            }
            return tags;
        }
        return [
            { text: '自定义角色', class: 'border-slate-200 bg-slate-50 text-slate-600' },
            { text: profile.canCollectOta ? '含OTA采集' : '未含采集', class: profile.canCollectOta ? 'border-amber-100 bg-amber-50 text-amber-700' : 'border-slate-200 bg-white text-slate-600' },
        ];
    };

    const withRolePermissionTags = (profile = {}) => ({
        ...profile,
        permissionTags: rolePermissionTags(profile),
        ...issueStatusForRoleProfile(profile),
    });

    const roleIssueActionText = (profile = {}) => {
        if (profile.issueBlocked) return '先修角色权限';
        if (profile.key === 'beta_user') return '发内测账号';
        if (profile.key === 'normal_user') return '发普通账号';
        return '发放此角色';
    };

    const userRoleBoundaryTextFromProfile = (profile = {}) => {
        if (profile.key === 'admin') return '内部管理：不发给内测或外部用户';
        if (profile.key === 'beta_user') {
            return profile.canCollectOta
                ? '内测发放：授权门店 OTA 查看/采集；不含用户/角色管理'
                : '内测发放：授权门店 OTA 只读；不含用户/角色管理';
        }
        if (profile.key === 'normal_user') {
            return profile.canCollectOta
                ? '普通：含采集权限，发放前需复核'
                : '普通外发：授权门店 OTA 只读；不可采集/导出/AI执行';
        }
        return profile.canCollectOta
            ? '自定义：含 OTA 采集，发放前复核'
            : '自定义：按门店授权查看';
    };

    const buildUserIssueChecklistRows = (profile = {}, assignedHotelIds = [], status = 1) => {
        if (!profile || typeof profile !== 'object') return [];
        const normalizedHotelIds = Array.isArray(assignedHotelIds) ? assignedHotelIds : [];
        const rows = [];
        if (profile.key === 'admin') {
            rows.push({
                key: 'role',
                label: '角色用途',
                level: 'warning',
                text: '管理员仅用于内部系统管理，不建议发给内测或外部普通用户。',
            });
        } else if (profile.key === 'normal_user' && profile.canCollectOta) {
            rows.push({
                key: 'role',
                label: '角色用途',
                level: 'error',
                text: '普通用户角色当前包含 OTA 采集权限，先在角色管理移除采集权限后再对外发放。',
            });
        } else {
            rows.push({
                key: 'role',
                label: '角色用途',
                level: 'success',
                text: profile.audience,
            });
        }
        rows.push({
            key: 'hotel_scope',
            label: '门店范围',
            level: profile.requiresHotelAssignment && normalizedHotelIds.length === 0 ? 'error' : 'success',
            text: profile.requiresHotelAssignment && normalizedHotelIds.length === 0
                ? '该角色必须先分配门店，否则外部用户登录后没有可追责的数据范围。'
                : (normalizedHotelIds.length ? `已分配 ${normalizedHotelIds.length} 个门店` : profile.scope),
        });
        rows.push({
            key: 'ota_scope',
            label: 'OTA边界',
            level: profile.canCollectOta && profile.key !== 'beta_user' && profile.key !== 'admin' ? 'warning' : 'success',
            text: profile.ota,
        });
        rows.push({
            key: 'status',
            label: '账号状态',
            level: String(status) === '1' ? 'success' : 'warning',
            text: String(status) === '1' ? '保存后账号可登录' : '保存后仍为待审核/暂停，用户暂不能登录',
        });
        return rows;
    };

    const validateUserIssueProfile = (profile = {}, assignedHotelIds = []) => {
        const normalizedHotelIds = Array.isArray(assignedHotelIds) ? assignedHotelIds : [];
        if (!profile || typeof profile !== 'object') return '';
        if (profile.key === 'normal_user' && profile.canCollectOta) {
            return '普通用户角色当前包含 OTA 采集权限，请先在角色管理移除采集权限后再发放。';
        }
        if (profile.issueBlockers?.length) {
            return profile.issueBlockers[0];
        }
        if (profile.requiresHotelAssignment && normalizedHotelIds.length === 0) {
            return '该角色必须先分配门店，避免生成无业务范围的外部账号。';
        }
        return '';
    };

    const userIssueLoginStatusText = (status) => (
        String(status) === '1' ? '正常，可登录' : '待审核/暂停，暂不能登录'
    );

    const userIssueStatusFromProfile = (profile = {}, blocker = '') => {
        if (['beta_user', 'normal_user'].includes(profile?.key)) {
            if (blocker) {
                return {
                    label: '暂不可发',
                    detail: blocker,
                    class: 'border-amber-100 bg-amber-50 text-amber-700',
                    icon: 'fas fa-exclamation-circle',
                };
            }
            return {
                label: '可发送说明',
                detail: `${profile.handoffType}；${profile.dataBoundary}`,
                class: 'border-emerald-100 bg-emerald-50 text-emerald-700',
                icon: 'fas fa-check-circle',
            };
        }
        if (profile?.key === 'admin') {
            return {
                label: '内部账号',
                detail: '管理员不发给内测或外部普通用户',
                class: 'border-red-100 bg-red-50 text-red-700',
                icon: 'fas fa-user-shield',
            };
        }
        return {
            label: '人工复核',
            detail: profile?.boundary || '自定义角色发放前需人工复核权限和门店范围',
            class: 'border-slate-200 bg-slate-50 text-slate-600',
            icon: 'fas fa-clipboard-check',
        };
    };

    return {
        rolePermissionList,
        roleHasAnyPermission,
        normalExternalDeniedPermissionGroups,
        roleUnsafeExternalCapabilityLabels,
        issueStatusForRoleProfile,
        roleIssueProfile,
        rolePermissionTags,
        withRolePermissionTags,
        roleIssueActionText,
        userRoleBoundaryTextFromProfile,
        buildUserIssueChecklistRows,
        validateUserIssueProfile,
        userIssueLoginStatusText,
        userIssueStatusFromProfile,
    };
})();
