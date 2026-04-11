<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
use think\facade\Route;

// 根路径 - 返回前端页面
Route::get('/', function () {
    return file_get_contents(public_path() . 'index.html');
});

// CORS 预检请求
Route::options('api/:any', function() {
    return response('', 204);
})->pattern(['any' => '.*']);

// ==================== 认证路由 ====================
// 登录不需要认证
Route::post('api/auth/login', 'Auth/login');

// 以下认证路由需要登录
Route::group('api/auth', function () {
    Route::post('logout', 'Auth/logout');
    Route::get('info', 'Auth/info');
    Route::post('changePassword', 'Auth/changePassword');
})->middleware(\app\middleware\Auth::class);

// ==================== 酒店管理路由 ====================
Route::group('api/hotels', function () {
    Route::get('/', 'Hotel/index');
    Route::get('/all', 'Hotel/all');
    Route::get('/:id', 'Hotel/read');
    Route::post('/', 'Hotel/create');
    Route::put('/:id', 'Hotel/update');
    Route::delete('/:id', 'Hotel/delete');
})->middleware(\app\middleware\Auth::class);

// ==================== 用户管理路由 ====================
Route::group('api/users', function () {
    Route::get('/', 'User/index');
    Route::get('/roles', 'User/roles');
    Route::get('/:id', 'User/read');
    Route::post('/', 'User/create');
    Route::put('/:id', 'User/update');
    Route::delete('/:id', 'User/delete');
})->middleware(\app\middleware\Auth::class);

// ==================== 角色管理路由 ====================
Route::group('api/roles', function () {
    Route::get('/permissions', 'RoleController/permissions');
    Route::get('/', 'RoleController/index');
    Route::get('/:id', 'RoleController/read');
    Route::post('/', 'RoleController/create');
    Route::put('/:id', 'RoleController/update');
    Route::delete('/:id', 'RoleController/delete');
})->middleware(\app\middleware\Auth::class);

// ==================== 日报表路由 ====================
Route::group('api/daily-reports', function () {
    Route::get('/config', 'DailyReport/config');
    Route::get('/export', 'DailyReport/export');
    Route::post('/parse-import', 'DailyReport/parseImport');
    Route::get('/view-mapping', 'DailyReport/getViewMapping');
    Route::post('/view-mapping', 'DailyReport/saveViewMapping');
    Route::get('/', 'DailyReport/index');
    Route::get('/:id/detail', 'DailyReport/detail');
    Route::get('/:id', 'DailyReport/read');
    Route::post('/', 'DailyReport/create');
    Route::put('/:id', 'DailyReport/update');
    Route::delete('/:id', 'DailyReport/delete');
})->middleware(\app\middleware\Auth::class);

// ==================== 月任务路由 ====================
Route::group('api/monthly-tasks', function () {
    Route::get('/config', 'MonthlyTask/config');
    Route::get('/', 'MonthlyTask/index');
    Route::get('/:id', 'MonthlyTask/read');
    Route::post('/', 'MonthlyTask/create');
    Route::put('/:id', 'MonthlyTask/update');
    Route::delete('/:id', 'MonthlyTask/delete');
})->middleware(\app\middleware\Auth::class);

// ==================== 报表配置路由 ====================
Route::group('api/report-configs', function () {
    Route::get('/', 'ReportConfig/index');
    Route::get('/all', 'ReportConfig/all');
    Route::get('/:id', 'ReportConfig/read');
    Route::post('/', 'ReportConfig/create');
    Route::put('/:id', 'ReportConfig/update');
    Route::delete('/:id', 'ReportConfig/delete');
})->middleware(\app\middleware\Auth::class);

// ==================== 门店字段映射模板路由 ====================
Route::group('api/hotel-field-templates', function () {
    Route::get('/', 'HotelFieldTemplate/index');
    Route::get('/:id', 'HotelFieldTemplate/read');
    Route::get('/:id/items', 'HotelFieldTemplate/items');
    Route::post('/', 'HotelFieldTemplate/create');
    Route::put('/:id', 'HotelFieldTemplate/update');
    Route::delete('/:id', 'HotelFieldTemplate/delete');
})->middleware(\app\middleware\Auth::class);

// ==================== 系统配置路由 ====================
Route::group('api/system-config', function () {
    Route::get('/', 'SystemConfigController/index');
    Route::put('/', 'SystemConfigController/update');
    Route::get('/groups', 'SystemConfigController/groups');
    Route::get('/export', 'SystemConfigController/export');
    Route::post('/import', 'SystemConfigController/import');
    Route::post('/reset', 'SystemConfigController/reset');
})->middleware(\app\middleware\Auth::class);

// ==================== 线上数据获取路由 ====================
Route::group('api/online-data', function () {
    Route::post('/fetch-ctrip', 'OnlineData/fetchCtrip');
    Route::post('/fetch-meituan', 'OnlineData/fetchMeituan');
    Route::post('/fetch-ctrip-traffic', 'OnlineData/fetchCtripTraffic');
    Route::post('/fetch-meituan-traffic', 'OnlineData/fetchMeituanTraffic');
    Route::post('/fetch-meituan-comments', 'OnlineData/fetchMeituanComments');
    Route::post('/fetch-custom', 'OnlineData/fetchCustom');
    Route::post('/save-cookies', 'OnlineData/saveCookies');
    Route::get('/cookies-list', 'OnlineData/getCookiesList');
    Route::post('/delete-cookies', 'OnlineData/deleteCookies');
    Route::get('/bookmarklet', 'OnlineData/bookmarklet');
    // 美团配置
    Route::post('/save-meituan-config', 'OnlineData/saveMeituanConfig');
    Route::get('/get-meituan-config', 'OnlineData/getMeituanConfig');
    Route::post('/save-meituan-config-item', 'OnlineData/saveMeituanConfigItem');
    Route::get('/get-meituan-config-list', 'OnlineData/getMeituanConfigList');
    Route::delete('/delete-meituan-config', 'OnlineData/deleteMeituanConfig');
    Route::get('/generate-meituan-bookmarklet', 'OnlineData/generateMeituanBookmarklet');
    // 美团点评配置（优化版）
    Route::post('/save-meituan-comment-config', 'OnlineData/saveMeituanCommentConfig');
    Route::get('/get-meituan-comment-config-list', 'OnlineData/getMeituanCommentConfigList');
    // 携程点评配置
    Route::post('/fetch-ctrip-comments', 'OnlineData/fetchCtripComments');
    Route::post('/save-ctrip-comment-config', 'OnlineData/saveCtripCommentConfig');
    Route::get('/get-ctrip-comment-config-list', 'OnlineData/getCtripCommentConfigList');
    // 携程配置
    Route::post('/save-ctrip-config', 'OnlineData/saveCtripConfig');
    Route::get('/get-ctrip-config-list', 'OnlineData/getCtripConfigList');
    Route::delete('/delete-ctrip-config', 'OnlineData/deleteCtripConfig');
    Route::get('/generate-ctrip-bookmarklet', 'OnlineData/generateCtripBookmarklet');
    Route::any('/auto-capture-ctrip-cookie', 'OnlineData/autoCaptureCtripCookie');
    Route::post('/save-ctrip-config-by-bookmark', 'OnlineData/saveCtripConfigByBookmark');
    // 线上数据管理
    Route::post('/save-daily-data', 'OnlineData/saveDailyData');
    Route::get('/daily-data-list', 'OnlineData/dailyDataList');
    Route::get('/daily-data-summary', 'OnlineData/dailyDataSummary');
    Route::get('/hotel-list', 'OnlineData/hotelList');
    Route::post('/auto-fetch', 'OnlineData/autoFetch');
    Route::get('/auto-fetch-status', 'OnlineData/autoFetchStatus');
    Route::post('/toggle-auto-fetch', 'OnlineData/toggleAutoFetch');
    Route::post('/set-fetch-schedule', 'OnlineData/setFetchSchedule');
    Route::post('/batch-delete', 'OnlineData/batchDelete');
    // 数据分析
    Route::get('/data-analysis', 'OnlineData/dataAnalysis');
    // AI智能分析
    Route::post('/ai-analysis', 'OnlineData/aiAnalysis');
})->middleware(\app\middleware\Auth::class);

// ==================== AI 筹建管理路由 ====================
Route::group('api/ai', function () {
    Route::post('/strategy', 'Ai/strategy');
    Route::post('/simulation', 'Ai/simulation');
    Route::post('/feasibility', 'Ai/feasibility');
})->middleware(\app\middleware\Auth::class);

// ==================== 竞对价格监控 API ====================
Route::post('api/competitor/task', 'CompetitorApi/task');
Route::post('api/competitor/report', 'CompetitorApi/report');

// ==================== 竞对价格监控 管理 ====================
Route::group('api/admin/competitor-hotels', function () {
    Route::get('/', 'admin.CompetitorHotelController/index');
    Route::get('/stores', 'admin.CompetitorHotelController/stores');
    Route::post('/', 'admin.CompetitorHotelController/create');
    Route::put('/:id', 'admin.CompetitorHotelController/update');
    Route::delete('/:id', 'admin.CompetitorHotelController/delete');
})->middleware(\app\middleware\Auth::class);

Route::group('api/admin/competitor-price-logs', function () {
    Route::get('/', 'admin.CompetitorPriceLogController/index');
})->middleware(\app\middleware\Auth::class);

Route::group('api/admin/competitor-devices', function () {
    Route::get('/', 'admin.CompetitorDeviceController/index');
})->middleware(\app\middleware\Auth::class);

// ==================== 企业微信机器人 ====================
Route::group('admin/competitor-wechat-robot', function () {
    Route::get('/', 'admin.CompetitorWechatRobotController/index');
    Route::get('/add', 'admin.CompetitorWechatRobotController/add');
    Route::post('/save', 'admin.CompetitorWechatRobotController/save');
    Route::get('/edit/:id', 'admin.CompetitorWechatRobotController/edit');
    Route::post('/update/:id', 'admin.CompetitorWechatRobotController/update');
    Route::post('/delete/:id', 'admin.CompetitorWechatRobotController/delete');
    Route::post('/test/:id', 'admin.CompetitorWechatRobotController/testSend');
    Route::post('/test-store/:storeId', 'admin.CompetitorWechatRobotController/testSendStore');
})->middleware(\app\middleware\Auth::class);

// ==================== 门店罗盘 ====================
Route::group('compass', function () {
    Route::get('index', 'admin.Compass/index');
    Route::post('save-layout', 'admin.Compass/saveLayout');
})->middleware(\app\middleware\Auth::class);

// ==================== 门店罗盘 API ====================
Route::group('api/compass', function () {
    Route::get('/', 'admin.Compass/apiIndex');
    Route::post('/layout', 'admin.Compass/apiSaveLayout');
})->middleware(\app\middleware\Auth::class);

// ==================== 企业微信机器人 API（SPA） ====================
Route::group('api/admin/competitor-wechat-robot', function () {
    Route::get('/', 'admin.CompetitorWechatRobotController/apiIndex');
    Route::post('/save', 'admin.CompetitorWechatRobotController/apiSave');
    Route::post('/update/:id', 'admin.CompetitorWechatRobotController/apiUpdate');
    Route::post('/delete/:id', 'admin.CompetitorWechatRobotController/apiDelete');
    Route::post('/test-store/:storeId', 'admin.CompetitorWechatRobotController/apiTestStore');
})->middleware(\app\middleware\Auth::class);

// 接收书签脚本的Cookies（不需要认证中间件，通过token参数验证）
Route::post('api/online-data/receive-cookies', 'OnlineData/receiveCookies');
Route::options('api/online-data/receive-cookies', 'OnlineData/receiveCookies');

// 定时任务触发接口（不需要认证，通过X-Cron-Token验证）
Route::get('api/online-data/cron-trigger', 'OnlineData/cronTrigger');

// 清除opcache缓存（开发调试用）
Route::get('api/online-data/clear-cache', 'OnlineData/clearCache');

// ==================== 操作日志路由 ====================
Route::group('api/operation-logs', function () {
    Route::get('/', 'OperationLogController/index');
    Route::get('/stats', 'OperationLogController/stats');
    Route::get('/:id', 'OperationLogController/detail');
})->middleware(\app\middleware\Auth::class);

// 健康检查
Route::get('api/health', function () {
    return json(['status' => 'ok', 'time' => date('Y-m-d H:i:s')]);
});

// 测试携程API获取（无需认证，用于调试）
Route::post('api/test-ctrip-fetch', function () {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $nodeId = $data['node_id'] ?? '24588';
        $cookies = $data['cookies'] ?? '';
        $startDate = $data['start_date'] ?? date('Y-m-d', strtotime('-1 day'));
        $endDate = $data['end_date'] ?? date('Y-m-d', strtotime('-1 day'));
        
        if (empty($cookies)) {
            return json(['code' => 400, 'message' => '请提供Cookies', 'data' => null]);
        }
        
        $url = 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport';
        
        $postData = [
            'nodeId' => $nodeId,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ];
        
        $headers = [
            'Accept: */*',
            'Content-Type: application/json',
            'Cookie: ' . $cookies,
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Origin: https://ebooking.ctrip.com',
            'Referer: https://ebooking.ctrip.com/',
        ];
        
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => json_encode($postData, JSON_UNESCAPED_UNICODE),
                'timeout' => 30,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            return json(['code' => 500, 'message' => '请求携程API失败', 'data' => null]);
        }
        
        // 检查是否是gzip压缩
        if (substr($response, 0, 2) === "\x1f\x8b") {
            $response = gzdecode($response);
        }
        
        $responseData = json_decode($response, true);
        
        return json([
            'code' => 200,
            'message' => '请求成功',
            'data' => [
                'data' => $responseData,
                'raw_length' => strlen($response),
            ]
        ]);
    } catch (\Exception $e) {
        return json(['code' => 500, 'message' => '异常: ' . $e->getMessage(), 'data' => null]);
    }
});

// 无认证数据库测试
Route::any('api/db-test', function () {
    try {
        // 测试数据库连接
        $count = \think\facade\Db::name('system_configs')->count();
        return json(['code' => 200, 'message' => '数据库正常', 'data' => ['count' => $count]]);
    } catch (\Exception $e) {
        return json(['code' => 500, 'message' => '数据库错误: ' . $e->getMessage()]);
    }
});

// 测试保存携程配置（无需认证）
Route::any('api/test-ctrip-save', function () {
    try {
        $name = 'test_' . date('YmdHis');
        $cookies = 'test_cookies_' . time();
        
        $id = 'ctrip_' . date('YmdHis') . '_' . substr(md5($name . time()), 0, 8);
        
        $key = 'ctrip_config_list';
        $existing = \think\facade\Db::name('system_configs')->where('config_key', $key)->find();
        $list = [];
        if ($existing) {
            $list = json_decode($existing['config_value'], true) ?: [];
        }
        $list[$id] = [
            'id' => $id,
            'name' => $name,
            'cookies' => $cookies,
            'update_time' => date('Y-m-d H:i:s'),
        ];
        
        $jsonValue = json_encode($list, JSON_UNESCAPED_UNICODE);
        
        if ($existing) {
            \think\facade\Db::name('system_configs')->where('config_key', $key)->update([
                'config_value' => $jsonValue,
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        } else {
            \think\facade\Db::name('system_configs')->insert([
                'config_key' => $key,
                'config_value' => $jsonValue,
                'description' => '携程配置列表',
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        }
        
        return json(['code' => 200, 'message' => '保存成功', 'data' => ['id' => $id, 'name' => $name]]);
    } catch (\Exception $e) {
        return json(['code' => 500, 'message' => '保存失败: ' . $e->getMessage()]);
    }
});

// 直接保存携程配置（带参数）
Route::post('api/test-ctrip-save-direct', function () {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $name = $data['name'] ?? '';
        $cookies = $data['cookies'] ?? '';
        
        if (empty($name) || empty($cookies)) {
            return json(['code' => 400, 'message' => '参数不完整']);
        }
        
        $id = 'ctrip_' . date('YmdHis') . '_' . substr(md5($name . time()), 0, 8);
        
        $key = 'ctrip_config_list';
        $existing = \think\facade\Db::name('system_configs')->where('config_key', $key)->find();
        $list = [];
        if ($existing) {
            $list = json_decode($existing['config_value'], true) ?: [];
        }
        $list[$id] = [
            'id' => $id,
            'name' => $name,
            'cookies' => $cookies,
            'url' => $data['url'] ?? '',
            'node_id' => $data['node_id'] ?? '',
            'update_time' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ];
        
        $jsonValue = json_encode($list, JSON_UNESCAPED_UNICODE);
        
        if ($existing) {
            \think\facade\Db::name('system_configs')->where('config_key', $key)->update([
                'config_value' => $jsonValue,
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        } else {
            \think\facade\Db::name('system_configs')->insert([
                'config_key' => $key,
                'config_value' => $jsonValue,
                'description' => '携程配置列表',
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        }
        
        return json(['code' => 200, 'message' => '配置保存成功', 'data' => $list[$id]]);
    } catch (\Exception $e) {
        return json(['code' => 500, 'message' => '保存失败: ' . $e->getMessage()]);
    }
});

// 获取携程配置列表（直接接口）
Route::any('api/test-ctrip-config-list', function () {
    try {
        $key = 'ctrip_config_list';
        $raw = \think\facade\Db::name('system_configs')->where('config_key', $key)->value('config_value');
        $list = $raw ? json_decode($raw, true) : [];
        if (!is_array($list)) {
            $list = [];
        }
        // 转为索引数组并按时间倒序
        $list = array_values($list);
        usort($list, function($a, $b) {
            return strcmp($b['update_time'] ?? '', $a['update_time'] ?? '');
        });
        return json(['code' => 200, 'message' => 'success', 'data' => $list]);
    } catch (\Exception $e) {
        return json(['code' => 500, 'message' => '获取失败: ' . $e->getMessage(), 'data' => []]);
    }
});

// 删除携程配置（直接接口）
Route::any('api/test-ctrip-config-delete', function () {
    try {
        $id = request()->param('id', '');
        if (empty($id)) {
            return json(['code' => 400, 'message' => '缺少ID参数']);
        }
        
        $key = 'ctrip_config_list';
        $existing = \think\facade\Db::name('system_configs')->where('config_key', $key)->find();
        if (!$existing) {
            return json(['code' => 404, 'message' => '配置不存在']);
        }
        
        $list = json_decode($existing['config_value'], true) ?: [];
        if (!isset($list[$id])) {
            return json(['code' => 404, 'message' => '配置项不存在']);
        }
        
        unset($list[$id]);
        
        \think\facade\Db::name('system_configs')->where('config_key', $key)->update([
            'config_value' => json_encode($list, JSON_UNESCAPED_UNICODE),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        
        return json(['code' => 200, 'message' => '删除成功']);
    } catch (\Exception $e) {
        return json(['code' => 500, 'message' => '删除失败: ' . $e->getMessage()]);
    }
});

// ==================== AI Agent 路由 ====================
Route::group('api/agent', function () {
    // 概览
    Route::get('/overview', 'Agent/overview');
    
    // 配置管理
    Route::get('/config', 'Agent/getConfig');
    Route::post('/config', 'Agent/saveConfig');
    
    // ========== 智能员工Agent ==========
    // 知识库
    Route::get('/knowledge', 'Agent/knowledgeList');
    Route::post('/knowledge', 'Agent/saveKnowledge');
    Route::delete('/knowledge/:id', 'Agent/deleteKnowledge');
    Route::get('/knowledge-categories', 'Agent/knowledgeCategories');
    
    // 工单管理
    Route::get('/work-orders', 'Agent/workOrders');
    Route::post('/work-orders', 'Agent/createWorkOrder');
    Route::post('/work-orders/:id/assign', 'Agent/assignWorkOrder');
    Route::post('/work-orders/:id/resolve', 'Agent/resolveWorkOrder');
    Route::get('/work-order-stats', 'Agent/workOrderStats');
    
    // 对话记录
    Route::get('/conversations', 'Agent/conversations');
    Route::get('/conversation-stats', 'Agent/conversationStats');
    
    // 智能员工仪表板
    Route::get('/staff-dashboard', 'Agent/staffDashboard');
    
    // ========== 收益管理Agent ==========
    // 定价建议
    Route::get('/price-suggestions', 'Agent/priceSuggestions');
    Route::post('/price-suggestions/:id/approve', 'Agent/approvePrice');
    Route::get('/revenue-analysis', 'Agent/revenueAnalysis');
    
    // 需求预测
    Route::get('/demand-forecasts', 'Agent/demandForecasts');
    Route::post('/demand-forecasts', 'Agent/createForecast');
    
    // 竞对分析
    Route::get('/competitor-analysis', 'Agent/competitorAnalysis');
    Route::post('/competitor-analysis', 'Agent/recordCompetitorPrice');
    
    // 收益管理仪表板
    Route::get('/revenue-dashboard', 'Agent/revenueDashboard');
    
    // ========== 资产运维Agent ==========
    // 设备管理
    Route::get('/devices', 'Agent/deviceList');
    Route::post('/devices', 'Agent/saveDevice');
    Route::get('/device-stats', 'Agent/deviceStats');
    
    // 能耗管理
    Route::get('/energy-data', 'Agent/energyData');
    Route::get('/energy-benchmarks', 'Agent/energyBenchmarks');
    Route::post('/energy-benchmarks', 'Agent/saveEnergyBenchmark');
    Route::post('/energy-benchmarks/auto-calculate', 'Agent/autoCalculateBenchmark');
    
    // 节能建议
    Route::get('/energy-suggestions', 'Agent/energySuggestions');
    Route::post('/energy-suggestions/generate', 'Agent/generateEnergySuggestions');
    Route::post('/energy-suggestions/:id/update', 'Agent/updateEnergySuggestion');
    
    // 维护计划
    Route::get('/maintenance-plans', 'Agent/maintenancePlans');
    Route::post('/maintenance-plans', 'Agent/createMaintenancePlan');
    Route::post('/maintenance-plans/:id/execute', 'Agent/executeMaintenancePlan');
    Route::get('/maintenance-reminders', 'Agent/maintenanceReminders');
    Route::post('/maintenance-plans/auto-generate', 'Agent/autoGenerateMaintenancePlans');
    
    // 资产运维仪表板
    Route::get('/asset-dashboard', 'Agent/assetDashboard');
    
    // 日志和任务
    Route::get('/logs', 'Agent/logs');
    Route::get('/tasks', 'Agent/tasks');
    Route::post('/tasks', 'Agent/createTask');
})->middleware(\app\middleware\Auth::class);

// 测试路由
Route::post('api/test-save', function () {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $name = $data['name'] ?? '';
        $cookies = $data['cookies'] ?? '';
        
        if (empty($name) || empty($cookies)) {
            return json(['code' => 400, 'message' => '参数不完整']);
        }
        
        // 直接保存到数据库
        $id = 'test_' . date('YmdHis');
        \think\facade\Db::name('system_configs')->insert([
            'config_key' => 'test_config_' . $id,
            'config_value' => json_encode(['name' => $name, 'cookies' => substr($cookies, 0, 100)], JSON_UNESCAPED_UNICODE),
            'description' => '测试配置',
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        
        return json(['code' => 200, 'message' => '测试保存成功', 'data' => ['id' => $id]]);
    } catch (\Exception $e) {
        return json(['code' => 500, 'message' => '测试失败: ' . $e->getMessage()]);
    }
})->middleware(\app\middleware\Auth::class);
