<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\OperationLog;
use app\service\CtripProfileFieldMetaService;
use think\Response;
use think\facade\Db;

trait CtripProfileConfigConcern
{
    public function getCtripProfileFields(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        try {
            $configuredFields = $this->readCtripProfileCaptureFields(true);
            $fields = array_values($this->activeCtripProfileCaptureFields($configuredFields));
            $autoFetchCandidates = $this->discoverCtripProfileAutoFetchFieldCandidates();
            $candidateScope = $this->scopeCtripProfileAutoFetchFieldCandidates($autoFetchCandidates);
            $includeSamples = !in_array(strtolower(trim((string)$this->request->get('include_samples', '1'))), ['0', 'false', 'no'], true);
            $sampleSummary = $includeSamples
                ? $this->hydrateCtripProfileFieldLatestSamples($fields)
                : [
                    'sampled_field_count' => 0,
                    'latest_sample_time' => null,
                    'latest_sample_batch_key' => null,
                    'sample_pending' => true,
                ];
            $summary = array_merge(
                $this->summarizeCtripProfileCaptureFields($fields),
                $sampleSummary,
                $this->summarizeCtripProfileCaptureModules($configuredFields),
                $this->summarizeCtripProfileAutoFetchFieldCandidates(
                    $configuredFields,
                    $candidateScope['key_candidates'],
                    $candidateScope['skipped_candidates']
                )
            );

            return $this->success(array_merge([
                'list' => $fields,
                'summary' => $summary,
            ], $this->buildCtripProfileModulePayload($configuredFields)));
        } catch (\Throwable $e) {
            \think\facade\Log::error('获取携程 Profile 字段目录失败: ' . $e->getMessage(), ['exception' => $e]);
            return $this->error('获取携程 Profile 字段目录失败', 500);
        }
    }

    public function getCtripProfileModules(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        try {
            $fields = $this->readCtripProfileCaptureFields(true);
            return $this->success($this->buildCtripProfileModulePayload($fields));
        } catch (\Throwable $e) {
            \think\facade\Log::error('获取携程 Profile 模块配置失败: ' . $e->getMessage(), ['exception' => $e]);
            return $this->error('获取携程 Profile 模块配置失败', 500);
        }
    }

    public function saveCtripProfileModule(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $requestData = $this->requestData();
            $modules = $this->readCtripProfileCaptureModules(true);
            $id = trim((string)($requestData['id'] ?? ''));
            $label = trim((string)($requestData['label'] ?? $requestData['name'] ?? ''));
            if ($id === '') {
                $id = $this->resolveCtripProfileModuleIdForSave($requestData, $label);
            }
            if ($id === '') {
                $id = $this->buildCtripProfileModuleId($label);
            }
            if ($id === '' || $label === '') {
                return $this->error('模块编码和模块名称不能为空');
            }
            $original = $modules[$id] ?? [];
            if ($original && $this->isCtripProfileCaptureModuleDeleted($original)) {
                $original = [];
            }

            $module = $this->normalizeCtripProfileCaptureModule(array_merge($original, $requestData, [
                'id' => $id,
                'label' => $label,
                'created_at' => $original['created_at'] ?? date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
                'deleted_at' => '',
                'deleted_by' => null,
            ]), $original);
            $modules[$id] = $module;
            $this->writeCtripProfileCaptureModules($modules);

            if (empty($module['enabled'])) {
                $this->pauseCtripProfileFieldsForModule($id);
            }

            OperationLog::record('online_data', 'save_ctrip_profile_module', '保存携程 Profile 模块: ' . $label, $this->currentUser->id);

            $fields = $this->readCtripProfileCaptureFields(true);
            return $this->success(array_merge([
                'module' => $this->enrichCtripProfileModule($module, $fields),
            ], $this->buildCtripProfileModulePayload($fields)), '模块配置已保存');
        } catch (\Throwable $e) {
            \think\facade\Log::error('保存携程 Profile 模块配置失败: ' . $e->getMessage(), ['exception' => $e]);
            return $this->error('保存携程 Profile 模块配置失败: ' . $e->getMessage(), 500);
        }
    }

    public function deleteCtripProfileModule(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_delete_online_data');

        $requestData = $this->requestData();
        $id = trim((string)($requestData['id'] ?? $this->request->param('id', '')));
        if ($id === '') {
            return $this->error('模块编码不能为空');
        }

        try {
            $modules = $this->readCtripProfileCaptureModules(true);
            if (!isset($modules[$id]) || $this->isCtripProfileCaptureModuleDeleted($modules[$id])) {
                return $this->error('模块配置不存在', 404);
            }

            $moduleName = (string)($modules[$id]['label'] ?? $id);
            $modules[$id] = $this->normalizeCtripProfileCaptureModule(array_merge($modules[$id], [
                'enabled' => 0,
                'deleted_at' => date('Y-m-d H:i:s'),
                'deleted_by' => $this->currentUser->id ?? null,
                'update_time' => date('Y-m-d H:i:s'),
            ]), $modules[$id]);
            $this->writeCtripProfileCaptureModules($modules);
            $pausedCount = $this->pauseCtripProfileFieldsForModule($id);

            OperationLog::record('online_data', 'delete_ctrip_profile_module', '删除携程 Profile 模块: ' . $moduleName, $this->currentUser->id);

            $fields = $this->readCtripProfileCaptureFields(true);
            return $this->success(array_merge([
                'id' => $id,
                'paused_field_count' => $pausedCount,
            ], $this->buildCtripProfileModulePayload($fields)), '模块配置已删除，相关字段已停用');
        } catch (\Throwable $e) {
            \think\facade\Log::error('删除携程 Profile 模块配置失败: ' . $e->getMessage(), ['exception' => $e]);
            return $this->error('删除携程 Profile 模块配置失败', 500);
        }
    }

    public function syncCtripProfileFields(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $fields = $this->readCtripProfileCaptureFields(true);
            $candidates = $this->discoverCtripProfileAutoFetchFieldCandidates();
            $candidateScope = $this->scopeCtripProfileAutoFetchFieldCandidates($candidates);
            $syncResult = $this->mergeCtripProfileAutoFetchFieldCandidates($fields, $candidates);
            if ((int)$syncResult['added_count'] > 0) {
                $this->writeCtripProfileCaptureFields($fields);
            }

            $fieldList = array_values($this->activeCtripProfileCaptureFields($fields));
            $sampleSummary = $this->hydrateCtripProfileFieldLatestSamples($fieldList);
            $summary = array_merge(
                $this->summarizeCtripProfileCaptureFields($fieldList),
                $sampleSummary,
                $this->summarizeCtripProfileCaptureModules($fields),
                $this->summarizeCtripProfileAutoFetchFieldCandidates(
                    $fields,
                    $candidateScope['key_candidates'],
                    $candidateScope['skipped_candidates']
                )
            );

            OperationLog::record(
                'online_data',
                'sync_ctrip_profile_fields',
                '同步携程 Profile 自动获取字段: 新增 ' . (int)$syncResult['added_count'] . ' 个',
                $this->currentUser->id
            );

            return $this->success(array_merge([
                'list' => $fieldList,
                'summary' => $summary,
                'sync_result' => $syncResult,
            ], $this->buildCtripProfileModulePayload($fields)), '自动获取字段同步完成');
        } catch (\Throwable $e) {
            \think\facade\Log::error('同步携程 Profile 自动获取字段失败: ' . $e->getMessage(), ['exception' => $e]);
            return $this->error('同步携程 Profile 自动获取字段失败: ' . $e->getMessage(), 500);
        }
    }

    public function saveCtripProfileField(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $requestData = $this->requestData();
            $fields = $this->readCtripProfileCaptureFields(true);
            $id = trim((string)($requestData['id'] ?? ''));
            $original = $id !== '' && isset($fields[$id]) ? $fields[$id] : [];
            if ($id !== '' && (!isset($fields[$id]) || $this->isCtripProfileCaptureFieldDeleted($fields[$id]))) {
                return $this->error('字段配置不存在', 404);
            }

            $requestData = $this->prepareCtripProfileFieldSaveData($requestData, $original, $id === '');
            if ($id === '' && !$this->hasRequiredCtripProfileFieldEvidence($requestData)) {
                return $this->error('新增字段配置请填写网页URL、接口URL、JSON或JSON路径、我要取的值、代表的含义');
            }
            $fieldKey = trim((string)($requestData['field_key'] ?? $requestData['fieldKey'] ?? ($original['field_key'] ?? '')));
            $fieldName = trim((string)($requestData['field_name'] ?? $requestData['fieldName'] ?? ($original['field_name'] ?? '')));
            if ($fieldKey === '' || $fieldName === '') {
                return $this->error('字段编码和字段名称不能为空');
            }

            if ($id === '') {
                $safeIdPart = preg_replace('/[^a-z0-9_\-]+/i', '_', strtolower($fieldKey)) ?: bin2hex(random_bytes(4));
                $id = 'profile_field_' . trim($safeIdPart, '_');
                if (isset($fields[$id])) {
                    $id .= '_' . date('His') . '_' . bin2hex(random_bytes(2));
                }
            }

            $field = $this->normalizeCtripProfileCaptureField(array_merge($original, $requestData, [
                'id' => $id,
                'field_key' => $fieldKey,
                'field_name' => $fieldName,
                'user_id' => $this->currentUser->id ?? null,
                'created_at' => $original['created_at'] ?? date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ]), $original);

            $modules = $this->readCtripProfileCaptureModules(true);
            $section = (string)($field['section'] ?? '');
            if ($section === '' || !isset($modules[$section]) || $this->isCtripProfileCaptureModuleDeleted($modules[$section])) {
                return $this->error('字段所属模块不存在，请先在模块管理中新增模块');
            }

            $fields[$id] = $field;
            $this->writeCtripProfileCaptureFields($fields);

            OperationLog::record('online_data', 'save_ctrip_profile_field', '保存携程 Profile 字段: ' . $fieldName, $this->currentUser->id);

            return $this->success($field, '字段配置已保存');
        } catch (\Throwable $e) {
            \think\facade\Log::error('保存携程 Profile 字段失败: ' . $e->getMessage(), ['exception' => $e]);
            return $this->error('保存携程 Profile 字段失败: ' . $e->getMessage(), 500);
        }
    }

    public function deleteCtripProfileField(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_delete_online_data');

        $requestData = $this->requestData();
        $id = trim((string)($requestData['id'] ?? $this->request->param('id', '')));
        if ($id === '') {
            return $this->error('字段ID不能为空');
        }

        try {
            $fields = $this->readCtripProfileCaptureFields(true);
            if (!isset($fields[$id]) || $this->isCtripProfileCaptureFieldDeleted($fields[$id])) {
                return $this->error('字段配置不存在', 404);
            }

            $fieldName = (string)($fields[$id]['field_name'] ?? $id);
            $fields[$id] = $this->normalizeCtripProfileCaptureField(array_merge($fields[$id], [
                'enabled' => 0,
                'status' => 'paused',
                'deleted_at' => date('Y-m-d H:i:s'),
                'deleted_by' => $this->currentUser->id ?? null,
                'update_time' => date('Y-m-d H:i:s'),
            ]), $fields[$id]);
            $this->writeCtripProfileCaptureFields($fields);

            OperationLog::record('online_data', 'delete_ctrip_profile_field', '删除携程 Profile 字段: ' . $fieldName, $this->currentUser->id);

            return $this->success(['id' => $id], '字段配置已删除');
        } catch (\Throwable $e) {
            \think\facade\Log::error('删除携程 Profile 字段失败: ' . $e->getMessage(), ['exception' => $e]);
            return $this->error('删除携程 Profile 字段失败', 500);
        }
    }

    public function verifyCtripProfileFieldSample(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $requestData = $this->requestData();
            $id = trim((string)($requestData['id'] ?? ''));
            $rawStatus = strtolower(trim((string)($requestData['sample_verification_status'] ?? $requestData['status'] ?? '')));
            if ($id === '') {
                return $this->error('字段ID不能为空');
            }
            if (!in_array($rawStatus, ['matched', 'match', 'mismatched', 'mismatch'], true)) {
                return $this->error('人工核验状态只能是数值相符或数据不符');
            }

            $fields = $this->readCtripProfileCaptureFields(true);
            if (!isset($fields[$id]) || $this->isCtripProfileCaptureFieldDeleted($fields[$id])) {
                return $this->error('字段配置不存在', 404);
            }

            $status = $this->normalizeCtripProfileFieldSampleVerificationStatus($rawStatus);
            $field = $this->normalizeCtripProfileCaptureField(array_merge($fields[$id], [
                'status' => $this->statusForCtripProfileFieldSampleVerification($status, (string)($fields[$id]['status'] ?? 'pending')),
                'sample_verification_status' => $status,
                'sample_verified_at' => date('Y-m-d H:i:s'),
                'sample_verified_by' => $this->currentUser->id ?? null,
                'update_time' => date('Y-m-d H:i:s'),
            ]), $fields[$id]);

            $fields[$id] = $field;
            $this->writeCtripProfileCaptureFields($fields);

            $fieldName = (string)($field['field_name'] ?? $id);
            $statusText = $status === 'matched' ? '数值相符' : '数据不符';
            OperationLog::record('online_data', 'verify_ctrip_profile_field_sample', '人工核验携程 Profile 字段样例: ' . $fieldName . ' / ' . $statusText, $this->currentUser->id);

            return $this->success($field, '人工核验状态已保存');
        } catch (\Throwable $e) {
            \think\facade\Log::error('保存携程 Profile 字段人工核验状态失败: ' . $e->getMessage(), ['exception' => $e]);
            return $this->error('保存人工核验状态失败: ' . $e->getMessage(), 500);
        }
    }

    public function recheckCtripProfileMismatchedFields(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $fields = $this->readCtripProfileCaptureFields(true);
            $targetsById = [];
            foreach ($fields as $id => $field) {
                if ($this->isCtripProfileCaptureFieldDeleted($field)) {
                    continue;
                }
                $sampleStatus = $this->normalizeCtripProfileFieldSampleVerificationStatus($field['sample_verification_status'] ?? 'unverified');
                $status = (string)($field['status'] ?? 'pending');
                if ($sampleStatus === 'mismatched' || $status === 'needs_parser') {
                    $targetsById[$id] = $field;
                }
            }

            $targetList = array_values($targetsById);
            $this->hydrateCtripProfileFieldLatestSamples($targetList);

            $refreshed = [];
            $unresolved = [];
            $secondConfirmation = [];
            foreach ($targetList as $field) {
                $id = (string)($field['id'] ?? '');
                if ($id === '' || !isset($fields[$id])) {
                    continue;
                }

                $latestValue = trim((string)($field['latest_value'] ?? ''));
                if ($latestValue !== '') {
                    $fields[$id] = $this->normalizeCtripProfileCaptureField(array_merge($fields[$id], [
                        'status' => 'pending',
                        'sample_verification_status' => 'unverified',
                        'sample_verified_at' => '',
                        'sample_verified_by' => null,
                        'update_time' => date('Y-m-d H:i:s'),
                    ]), $fields[$id]);
                    $latestSample = is_array($field['latest_sample'] ?? null) ? $field['latest_sample'] : [];
                    $refreshed[] = [
                        'id' => $id,
                        'field_key' => (string)($field['field_key'] ?? ''),
                        'field_name' => (string)($field['field_name'] ?? ''),
                        'latest_value' => $latestValue,
                        'source_key' => (string)($latestSample['source_key'] ?? ''),
                        'source_path' => (string)($latestSample['source_path'] ?? ''),
                        'captured_at' => (string)($latestSample['captured_at'] ?? ''),
                    ];
                    $secondConfirmation[] = [
                        'id' => $id,
                        'field_key' => (string)($field['field_key'] ?? ''),
                        'field_name' => (string)($field['field_name'] ?? ''),
                        'latest_value' => $latestValue,
                    ];
                    continue;
                }

                $fields[$id] = $this->normalizeCtripProfileCaptureField(array_merge($fields[$id], [
                    'status' => 'needs_parser',
                    'update_time' => date('Y-m-d H:i:s'),
                ]), $fields[$id]);
                $unresolved[] = [
                    'id' => $id,
                    'field_key' => (string)($field['field_key'] ?? ''),
                    'field_name' => (string)($field['field_name'] ?? ''),
                ];
            }

            if ($targetsById) {
                $this->writeCtripProfileCaptureFields($fields);
            }

            $fieldList = array_values($this->activeCtripProfileCaptureFields($fields));
            $sampleSummary = $this->hydrateCtripProfileFieldLatestSamples($fieldList);
            $candidates = $this->discoverCtripProfileAutoFetchFieldCandidates();
            $candidateScope = $this->scopeCtripProfileAutoFetchFieldCandidates($candidates);
            $summary = array_merge(
                $this->summarizeCtripProfileCaptureFields($fieldList),
                $sampleSummary,
                $this->summarizeCtripProfileCaptureModules($fields),
                $this->summarizeCtripProfileAutoFetchFieldCandidates(
                    $fields,
                    $candidateScope['key_candidates'],
                    $candidateScope['skipped_candidates']
                )
            );

            OperationLog::record(
                'online_data',
                'recheck_ctrip_profile_mismatched_fields',
                '重跑携程 Profile 不符字段取值: 待处理 ' . count($targetsById) . ' 个，重新解析 ' . count($refreshed) . ' 个',
                $this->currentUser->id
            );

            return $this->success(array_merge([
                'list' => $fieldList,
                'summary' => $summary,
                'recheck_result' => [
                    'checked_count' => count($targetsById),
                    'refreshed_count' => count($refreshed),
                    'unresolved_count' => count($unresolved),
                    'second_confirmation_count' => count($secondConfirmation),
                    'refreshed_fields' => $refreshed,
                    'unresolved_fields' => $unresolved,
                    'second_confirmation_fields' => $secondConfirmation,
                ],
            ], $this->buildCtripProfileModulePayload($fields)), '不符字段取值已重跑，已恢复为待二次确认');
        } catch (\Throwable $e) {
            \think\facade\Log::error('重跑携程 Profile 不符字段取值失败: ' . $e->getMessage(), ['exception' => $e]);
            return $this->error('重跑不符字段取值失败: ' . $e->getMessage(), 500);
        }
    }


    private function defaultCtripProfileCaptureModules(): array
    {
        $defaults = [
            ['business_overview', '经营报告-概要-日报', 'https://ebooking.ctrip.com/datacenter/inland/businessreport/outline?microJump=true', '经营收益数据'],
            ['business_weekly_overview', '经营报告-概要-周报', 'https://ebooking.ctrip.com/datacenter/inland/businessreport/weekReport?microJump=true', '经营收益数据'],
            ['sales_report', '经营报告-销售数据', 'https://ebooking.ctrip.com/datacenter/inland/businessreport/beneficialdata?microJump=true', '经营收益数据'],
            ['traffic_report', '经营报告-流量数据', 'https://ebooking.ctrip.com/datacenter/inland/businessreport/flowdata?microJump=true', '流量转化数据'],
            ['comment_review', '点评数据', 'https://ebooking.ctrip.com/comment/commentList?microJump=true', '服务质量数据'],
            ['competitor_overview', '竞争圈动态-竞争圈概览', 'https://ebooking.ctrip.com/ebkgrowth/datacenter/competition/competitionprofile?microJump=true', '竞争力数据'],
            ['loss_analysis', '竞争圈动态-流失分析', 'https://ebooking.ctrip.com/ebkgrowth/datacenter/competition/lossanalysis?microJump=true', '竞争力数据'],
            ['competitor_rank', '竞争圈动态-竞争圈榜单', 'https://ebooking.ctrip.com/ebkgrowth/datacenter/competition/competitionlist?microJump=true', '竞争力数据'],
            ['quality_psi', 'PSI服务质量', 'https://ebooking.ctrip.com/toolcenter/psi/index?microJump=true', '服务质量数据'],
            ['market_calendar', '市场分析-市场热度', 'https://ebooking.ctrip.com/ebkgrowth/datacenter/marketanalysis/marketheat?microJump=true', '竞争力数据'],
            ['user_profile', '用户行为/点评分析', 'https://ebooking.ctrip.com/ebkgrowth/datacenter/userbehavior/user?microJump=true', '流量转化数据'],
            ['im_board', '用户行为-IM看板', 'https://ebooking.ctrip.com/datacenter/inland/userbehavior/user?goto=im', '服务质量数据'],
            ['ads_pyramid', '金字塔广告', 'https://ebooking.ctrip.com/toolcenter/cpc/pyramid?microJump=true', '流量转化数据'],
        ];

        $modules = [];
        foreach ($defaults as $index => [$id, $label, $pageUrl, $primaryCategory]) {
            $modules[$id] = $this->normalizeCtripProfileCaptureModule([
                'id' => $id,
                'label' => $label,
                'page_url' => $pageUrl,
                'primary_category' => $primaryCategory,
                'enabled' => true,
                'system' => true,
                'sort_order' => ($index + 1) * 10,
                'description' => '携程 Profile 自动获取字段模块',
                'created_at' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        }

        return $modules;
    }

    private function buildCtripProfileModuleId(string $value): string
    {
        $id = strtolower(trim($value));
        $id = preg_replace('/[^a-z0-9_\-]+/', '_', $id) ?: '';
        $id = trim($id, '_-');
        if ($id !== '') {
            return $id;
        }

        return 'module_' . substr(hash('crc32b', $value !== '' ? $value : microtime(true)), 0, 8);
    }

    private function resolveCtripProfileModuleIdForSave(array $requestData, string $label): string
    {
        $pageUrl = trim((string)($requestData['page_url'] ?? $requestData['pageUrl'] ?? $requestData['url'] ?? ''));
        $defaults = $this->defaultCtripProfileCaptureModules();
        if ($pageUrl !== '') {
            $section = $this->classifyCtripProfileCaptureSectionByPageUrl($pageUrl, '');
            if ($section !== '' && isset($defaults[$section])) {
                return $section;
            }

            $normalizedPageUrl = $this->normalizeCtripProfileModulePageUrl($pageUrl);
            if ($normalizedPageUrl !== '') {
                foreach ($defaults as $id => $module) {
                    if ($this->normalizeCtripProfileModulePageUrl((string)($module['page_url'] ?? '')) === $normalizedPageUrl) {
                        return (string)$id;
                    }
                }
            }
        }

        $normalizedLabel = $this->normalizeCtripProfileModuleLabel($label);
        if ($normalizedLabel === '') {
            return '';
        }
        $legacyLabels = $this->legacyCtripProfileCaptureModuleLabels();
        foreach ($defaults as $id => $module) {
            $labels = array_merge([(string)($module['label'] ?? '')], $legacyLabels[$id] ?? []);
            foreach ($labels as $candidate) {
                if ($this->normalizeCtripProfileModuleLabel((string)$candidate) === $normalizedLabel) {
                    return (string)$id;
                }
            }
        }

        return '';
    }

    private function normalizeCtripProfileModulePageUrl(string $pageUrl): string
    {
        $pageUrl = strtolower(trim($pageUrl));
        if ($pageUrl === '') {
            return '';
        }
        $parts = parse_url($pageUrl);
        if (!is_array($parts)) {
            return rtrim($pageUrl, '/');
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = strtolower(rtrim((string)($parts['path'] ?? ''), '/'));
        return trim($host . $path);
    }

    private function normalizeCtripProfileModuleLabel(string $label): string
    {
        return preg_replace('/\s+/u', '', trim($label)) ?: '';
    }

    private function normalizeCtripProfileCaptureModule(array $item, array $original = []): array
    {
        $id = strtolower(trim((string)($item['id'] ?? $original['id'] ?? '')));
        $id = preg_replace('/[^a-z0-9_\-]+/', '_', $id) ?: '';
        $deletedAt = trim((string)($item['deleted_at'] ?? $item['deletedAt'] ?? $original['deleted_at'] ?? ''));
        $enabled = array_key_exists('enabled', $item) ? $this->meituanBool($item['enabled']) : (bool)($original['enabled'] ?? true);
        if ($deletedAt !== '') {
            $enabled = false;
        }

        return [
            'id' => $id,
            'label' => trim((string)($item['label'] ?? $item['name'] ?? $original['label'] ?? '')),
            'enabled' => $enabled,
            'system' => array_key_exists('system', $item) ? $this->meituanBool($item['system']) : (bool)($original['system'] ?? false),
            'sort_order' => (int)($item['sort_order'] ?? $item['sortOrder'] ?? $original['sort_order'] ?? 0),
            'page_url' => trim((string)($item['page_url'] ?? $item['pageUrl'] ?? $item['url'] ?? $original['page_url'] ?? '')),
            'primary_category' => trim((string)($item['primary_category'] ?? $item['primaryCategory'] ?? $item['category'] ?? $original['primary_category'] ?? '')),
            'description' => trim((string)($item['description'] ?? $item['notes'] ?? $original['description'] ?? '')),
            'created_at' => (string)($item['created_at'] ?? $original['created_at'] ?? date('Y-m-d H:i:s')),
            'update_time' => (string)($item['update_time'] ?? $item['updated_at'] ?? $original['update_time'] ?? date('Y-m-d H:i:s')),
            'deleted_at' => $deletedAt,
            'deleted_by' => $item['deleted_by'] ?? $item['deletedBy'] ?? $original['deleted_by'] ?? null,
        ];
    }

    private function isCtripProfileCaptureModuleDeleted(array $module): bool
    {
        return trim((string)($module['deleted_at'] ?? '')) !== '';
    }

    private function legacyCtripProfileCaptureModuleLabels(): array
    {
        return [
            'competitor_overview' => ['竞争圈动态-概览', '竞争圈-概览', '竞争圈概览'],
            'im_board' => ['IM看板', '用户行为IM看板'],
        ];
    }

    private function mergeDefaultCtripProfileCaptureModules(array $modules): array
    {
        $changed = false;
        $legacyLabels = $this->legacyCtripProfileCaptureModuleLabels();

        foreach ($this->defaultCtripProfileCaptureModules() as $id => $module) {
            if (!isset($modules[$id])) {
                $modules[$id] = $module;
                $changed = true;
                continue;
            }

            $currentLabel = trim((string)($modules[$id]['label'] ?? ''));
            $defaultLabel = trim((string)($module['label'] ?? ''));
            if ($defaultLabel !== '' && $currentLabel !== $defaultLabel && in_array($currentLabel, $legacyLabels[$id] ?? [], true)) {
                $modules[$id]['label'] = $defaultLabel;
                $modules[$id]['update_time'] = date('Y-m-d H:i:s');
                $changed = true;
            }

            if (trim((string)($modules[$id]['page_url'] ?? '')) === '' && trim((string)($module['page_url'] ?? '')) !== '') {
                $modules[$id]['page_url'] = $module['page_url'];
                $changed = true;
            }
            if (trim((string)($modules[$id]['primary_category'] ?? '')) === '' && trim((string)($module['primary_category'] ?? '')) !== '') {
                $modules[$id]['primary_category'] = $module['primary_category'];
                $changed = true;
            }
        }

        [$modules, $restoredDuplicate] = $this->restoreDeletedDefaultCtripProfileModulesFromDuplicates($modules);
        $changed = $changed || $restoredDuplicate;

        return [$modules, $changed];
    }

    private function restoreDeletedDefaultCtripProfileModulesFromDuplicates(array $modules): array
    {
        $changed = false;
        $now = date('Y-m-d H:i:s');
        foreach ($this->defaultCtripProfileCaptureModules() as $id => $defaultModule) {
            if (!isset($modules[$id]) || !$this->isCtripProfileCaptureModuleDeleted($modules[$id])) {
                continue;
            }
            $duplicateId = $this->findDuplicateCtripProfileModuleId($modules, (string)$id, $defaultModule);
            if ($duplicateId === '') {
                continue;
            }

            $duplicate = $modules[$duplicateId];
            $modules[$id] = $this->normalizeCtripProfileCaptureModule(array_merge($modules[$id], [
                'id' => $id,
                'label' => (string)($defaultModule['label'] ?? ($duplicate['label'] ?? $id)),
                'enabled' => !empty($duplicate['enabled']),
                'system' => true,
                'sort_order' => (int)($defaultModule['sort_order'] ?? ($duplicate['sort_order'] ?? 0)),
                'page_url' => trim((string)($duplicate['page_url'] ?? '')) !== '' ? (string)$duplicate['page_url'] : (string)($defaultModule['page_url'] ?? ''),
                'primary_category' => trim((string)($duplicate['primary_category'] ?? '')) !== '' ? (string)$duplicate['primary_category'] : (string)($defaultModule['primary_category'] ?? ''),
                'description' => trim((string)($duplicate['description'] ?? '')) !== '' ? (string)$duplicate['description'] : (string)($defaultModule['description'] ?? ''),
                'deleted_at' => '',
                'deleted_by' => null,
                'update_time' => $now,
            ]), $modules[$id]);

            $modules[$duplicateId] = $this->normalizeCtripProfileCaptureModule(array_merge($duplicate, [
                'enabled' => 0,
                'deleted_at' => $now,
                'deleted_by' => null,
                'description' => trim((string)($duplicate['description'] ?? '')) !== ''
                    ? (string)$duplicate['description']
                    : '已合并到系统模块 ' . $id,
                'update_time' => $now,
            ]), $duplicate);
            $changed = true;
        }

        return [$modules, $changed];
    }

    private function findDuplicateCtripProfileModuleId(array $modules, string $defaultId, array $defaultModule): string
    {
        $defaultPageUrl = $this->normalizeCtripProfileModulePageUrl((string)($defaultModule['page_url'] ?? ''));
        $labelCandidates = array_merge(
            [(string)($defaultModule['label'] ?? '')],
            $this->legacyCtripProfileCaptureModuleLabels()[$defaultId] ?? []
        );
        $normalizedLabels = array_fill_keys(array_filter(array_map(
            fn(string $label): string => $this->normalizeCtripProfileModuleLabel($label),
            $labelCandidates
        )), true);

        foreach ($modules as $id => $module) {
            $id = (string)$id;
            if ($id === $defaultId || !is_array($module) || $this->isCtripProfileCaptureModuleDeleted($module)) {
                continue;
            }
            $pageUrl = $this->normalizeCtripProfileModulePageUrl((string)($module['page_url'] ?? ''));
            if ($defaultPageUrl !== '' && $pageUrl !== '' && $pageUrl === $defaultPageUrl) {
                return $id;
            }
            $label = $this->normalizeCtripProfileModuleLabel((string)($module['label'] ?? ''));
            if ($label !== '' && isset($normalizedLabels[$label])) {
                return $id;
            }
        }

        return '';
    }

    private function readCtripProfileCaptureModules(bool $includeDeleted = false): array
    {
        try {
            $row = \think\facade\Db::name('system_configs')->where('config_key', self::CTRIP_PROFILE_MODULES_CONFIG_KEY)->find();
        } catch (\Throwable $e) {
            $modules = $this->defaultCtripProfileCaptureModules();
            return $includeDeleted ? $modules : $this->activeCtripProfileCaptureModules($modules);
        }
        if (!$row) {
            $modules = $this->defaultCtripProfileCaptureModules();
            $this->writeCtripProfileCaptureModules($modules, true);
            return $includeDeleted ? $modules : $this->activeCtripProfileCaptureModules($modules);
        }

        $rawConfigValue = (string)($row['config_value'] ?? '');
        $payload = json_decode($rawConfigValue, true);
        if (!is_array($payload)) {
            \think\facade\Log::warning('携程 Profile 模块配置无法解析，已恢复默认模块', [
                'config_key' => self::CTRIP_PROFILE_MODULES_CONFIG_KEY,
                'json_error' => json_last_error_msg(),
                'stored_length' => strlen($rawConfigValue),
            ]);
            $modules = $this->defaultCtripProfileCaptureModules();
            $this->writeCtripProfileCaptureModules($modules);
            return $includeDeleted ? $modules : $this->activeCtripProfileCaptureModules($modules);
        }

        $rawModules = is_array($payload['modules'] ?? null) ? $payload['modules'] : $payload;
        $modules = [];
        foreach ($rawModules as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            if (empty($item['id']) && is_string($key)) {
                $item['id'] = $key;
            }
            $module = $this->normalizeCtripProfileCaptureModule($item);
            if ($module['id'] !== '') {
                $modules[$module['id']] = $module;
            }
        }

        [$modules, $changed] = $this->mergeDefaultCtripProfileCaptureModules($modules);
        if (empty($modules)) {
            $modules = $this->defaultCtripProfileCaptureModules();
            $changed = true;
        }

        uasort($modules, function (array $a, array $b): int {
            $sort = ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0));
            if ($sort !== 0) {
                return $sort;
            }
            return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
        });

        if ($changed) {
            $this->writeCtripProfileCaptureModules($modules);
        }

        return $includeDeleted ? $modules : $this->activeCtripProfileCaptureModules($modules);
    }

    private function writeCtripProfileCaptureModules(array $modules, bool $createOnly = false): void
    {
        $normalized = [];
        foreach ($modules as $item) {
            if (!is_array($item)) {
                continue;
            }
            $module = $this->normalizeCtripProfileCaptureModule($item);
            if ($module['id'] !== '' && $module['label'] !== '') {
                $normalized[$module['id']] = $module;
            }
        }

        $payload = [
            'version' => self::CTRIP_PROFILE_MODULES_CONFIG_VERSION,
            'modules' => $normalized,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $jsonValue = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $existing = \think\facade\Db::name('system_configs')->where('config_key', self::CTRIP_PROFILE_MODULES_CONFIG_KEY)->find();
        if ($existing) {
            if ($createOnly) {
                return;
            }
            \think\facade\Db::name('system_configs')->where('config_key', self::CTRIP_PROFILE_MODULES_CONFIG_KEY)->update([
                'config_value' => $jsonValue,
                'update_time' => date('Y-m-d H:i:s'),
            ]);
            return;
        }

        \think\facade\Db::name('system_configs')->insert([
            'config_key' => self::CTRIP_PROFILE_MODULES_CONFIG_KEY,
            'config_value' => $jsonValue,
            'description' => '携程 Profile 自动获取字段模块配置',
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
    }

    private function activeCtripProfileCaptureModules(array $modules): array
    {
        return array_filter($modules, fn(array $module): bool => !$this->isCtripProfileCaptureModuleDeleted($module) && !empty($module['enabled']));
    }

    private function activeCtripProfileCaptureModuleMap(): array
    {
        return $this->readCtripProfileCaptureModules(false);
    }

    private function enrichCtripProfileModule(array $module, array $fields = []): array
    {
        $id = (string)($module['id'] ?? '');
        $fieldCount = 0;
        $enabledFieldCount = 0;
        foreach ($fields as $field) {
            if (!is_array($field) || $this->isCtripProfileCaptureFieldDeleted($field) || (string)($field['section'] ?? '') !== $id) {
                continue;
            }
            $fieldCount++;
            if (!empty($field['enabled'])) {
                $enabledFieldCount++;
            }
        }

        return array_merge($module, [
            'field_count' => $fieldCount,
            'enabled_field_count' => $enabledFieldCount,
        ]);
    }

    private function buildCtripProfileModulePayload(array $fields = []): array
    {
        $modules = array_map(
            fn(array $module): array => $this->enrichCtripProfileModule($module, $fields),
            $this->readCtripProfileCaptureModules(true)
        );
        $visibleModules = array_values(array_filter($modules, fn(array $module): bool => !$this->isCtripProfileCaptureModuleDeleted($module)));
        $activeModules = array_values(array_filter($visibleModules, fn(array $module): bool => !empty($module['enabled'])));

        return [
            'modules' => $activeModules,
            'all_modules' => $visibleModules,
        ];
    }

    private function summarizeCtripProfileCaptureModules(array $fields = []): array
    {
        $payload = $this->buildCtripProfileModulePayload($fields);
        return [
            'module_count' => count($payload['all_modules'] ?? []),
            'enabled_module_count' => count($payload['modules'] ?? []),
            'disabled_module_count' => max(0, count($payload['all_modules'] ?? []) - count($payload['modules'] ?? [])),
        ];
    }

    private function pauseCtripProfileFieldsForModule(string $section): int
    {
        $section = strtolower(trim($section));
        if ($section === '') {
            return 0;
        }

        $fields = $this->readCtripProfileCaptureFields(true);
        $paused = 0;
        foreach ($fields as $id => $field) {
            if (!is_array($field) || $this->isCtripProfileCaptureFieldDeleted($field) || (string)($field['section'] ?? '') !== $section) {
                continue;
            }
            if (!empty($field['enabled']) || (string)($field['status'] ?? '') !== 'paused') {
                $fields[$id] = $this->normalizeCtripProfileCaptureField(array_merge($field, [
                    'enabled' => 0,
                    'status' => 'paused',
                    'update_time' => date('Y-m-d H:i:s'),
                ]), $field);
                $paused++;
            }
        }
        if ($paused > 0) {
            $this->writeCtripProfileCaptureFields($fields);
        }

        return $paused;
    }

    private function ctripProfileKeyFieldMap(): array
    {
        return array_fill_keys(CtripProfileFieldMetaService::keyFieldKeys(), true);
    }

    private function isCtripProfileKeyField(string $fieldKey): bool
    {
        $fieldKey = strtolower(trim($fieldKey));
        return $fieldKey !== '' && isset($this->ctripProfileKeyFieldMap()[$fieldKey]);
    }

    private function filterCtripProfileKeyFields(array $fields): array
    {
        $filtered = [];
        foreach ($fields as $id => $field) {
            if (!is_array($field)) {
                continue;
            }
            if ($this->isCtripProfileKeyField((string)($field['field_key'] ?? ''))) {
                $filtered[$id] = $field;
            }
        }

        return $filtered;
    }

    private function isCtripProfileCaptureFieldDeleted(array $field): bool
    {
        return trim((string)($field['deleted_at'] ?? '')) !== '';
    }

    private function activeCtripProfileCaptureFields(array $fields): array
    {
        $moduleMap = $this->activeCtripProfileCaptureModuleMap();
        return array_filter($fields, function (array $field) use ($moduleMap): bool {
            if ($this->isCtripProfileCaptureFieldDeleted($field)) {
                return false;
            }
            $section = (string)($field['section'] ?? '');
            return $section !== '' && isset($moduleMap[$section]);
        });
    }

    private function scopeCtripProfileAutoFetchFieldCandidates(array $candidates): array
    {
        $keyCandidates = [];
        $skippedCandidates = [];
        $moduleMap = $this->activeCtripProfileCaptureModuleMap();
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $section = strtolower(trim((string)($candidate['section'] ?? '')));
            if ($section !== '' && !isset($moduleMap[$section])) {
                $candidate['skip_reason'] = 'module_disabled';
                $skippedCandidates[] = $candidate;
                continue;
            }
            if ($this->isCtripProfileKeyField((string)($candidate['field_key'] ?? ''))) {
                $keyCandidates[] = $candidate;
            } else {
                $candidate['skip_reason'] = 'not_key_field';
                $skippedCandidates[] = $candidate;
            }
        }

        return [
            'key_candidates' => $keyCandidates,
            'skipped_candidates' => $skippedCandidates,
        ];
    }

    private function readCtripProfileCaptureFields(bool $includeDeleted = false): array
    {
        $row = \think\facade\Db::name('system_configs')->where('config_key', self::CTRIP_PROFILE_FIELDS_CONFIG_KEY)->find();
        if (!$row) {
            $fields = $this->defaultCtripProfileCaptureFields();
            $this->writeCtripProfileCaptureFields($fields, true);
            return $includeDeleted ? $fields : $this->activeCtripProfileCaptureFields($fields);
        }

        $rawConfigValue = (string)($row['config_value'] ?? '');
        $payload = json_decode($rawConfigValue, true);
        if (!is_array($payload)) {
            \think\facade\Log::warning('携程 Profile 字段目录配置无法解析，已恢复默认字段目录', [
                'config_key' => self::CTRIP_PROFILE_FIELDS_CONFIG_KEY,
                'json_error' => json_last_error_msg(),
                'stored_length' => strlen($rawConfigValue),
            ]);
            $fields = $this->defaultCtripProfileCaptureFields();
            $this->writeCtripProfileCaptureFields($fields);
            return $includeDeleted ? $fields : $this->activeCtripProfileCaptureFields($fields);
        }

        $payloadVersion = (int)($payload['version'] ?? 0);
        $rawFields = is_array($payload['fields'] ?? null) ? $payload['fields'] : $payload;
        $fields = [];
        foreach ($rawFields as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            if (empty($item['id']) && is_string($key)) {
                $item['id'] = $key;
            }
            $normalized = $this->normalizeCtripProfileCaptureField($item);
            if ($normalized['id'] !== '') {
                $fields[$normalized['id']] = $normalized;
            }
        }

        $defaultFields = $this->defaultCtripProfileCaptureFields();
        $refreshDefaultFieldKeys = $payloadVersion < (self::CTRIP_PROFILE_FIELDS_CONFIG_VERSION - 1)
            ? CtripProfileFieldMetaService::keyFieldKeys()
            : CtripProfileFieldMetaService::metaRefreshKeys();
        $hasNewDefaults = false;
        foreach ($defaultFields as $id => $field) {
            if (!isset($fields[$id])) {
                $fields[$id] = $field;
                $hasNewDefaults = true;
                continue;
            }

            if ($payloadVersion < self::CTRIP_PROFILE_FIELDS_CONFIG_VERSION
                && in_array((string)($field['field_key'] ?? ''), $refreshDefaultFieldKeys, true)
            ) {
                $existing = $fields[$id];
                $fields[$id] = array_merge($existing, $field, [
                    'id' => $existing['id'] ?? $field['id'],
                    'created_at' => $existing['created_at'] ?? $field['created_at'],
                    'status' => $existing['status'] ?? $field['status'],
                    'enabled' => $existing['enabled'] ?? $field['enabled'],
                    'deleted_at' => $existing['deleted_at'] ?? '',
                    'deleted_by' => $existing['deleted_by'] ?? null,
                    'sample_verification_status' => $existing['sample_verification_status'] ?? 'unverified',
                    'sample_verified_at' => $existing['sample_verified_at'] ?? '',
                    'sample_verified_by' => $existing['sample_verified_by'] ?? null,
                    'verified_sample_value' => $existing['verified_sample_value'] ?? '',
                    'verified_sample_unit' => $existing['verified_sample_unit'] ?? '',
                    'verified_sample_source_key' => $existing['verified_sample_source_key'] ?? '',
                    'verified_sample_source_path' => $existing['verified_sample_source_path'] ?? '',
                    'verified_sample_endpoint_id' => $existing['verified_sample_endpoint_id'] ?? '',
                    'verified_sample_data_date' => $existing['verified_sample_data_date'] ?? '',
                    'verified_sample_hotel_name' => $existing['verified_sample_hotel_name'] ?? '',
                    'verified_sample_captured_at' => $existing['verified_sample_captured_at'] ?? '',
                    'update_time' => date('Y-m-d H:i:s'),
                    'user_id' => $existing['user_id'] ?? null,
                ]);
                $hasNewDefaults = true;
            }
        }
        if ($hasNewDefaults) {
            $this->writeCtripProfileCaptureFields($fields);
        }

        if (empty($fields)) {
            \think\facade\Log::warning('携程 Profile 字段目录为空，已恢复默认字段目录', [
                'config_key' => self::CTRIP_PROFILE_FIELDS_CONFIG_KEY,
                'payload_version' => $payloadVersion,
            ]);
            $fields = $this->defaultCtripProfileCaptureFields();
            $this->writeCtripProfileCaptureFields($fields);
            return $includeDeleted ? $fields : $this->activeCtripProfileCaptureFields($fields);
        }

        $keyFields = $this->filterCtripProfileKeyFields($fields);
        if (count($keyFields) < count($fields)) {
            \think\facade\Log::warning('携程 Profile 字段目录已按关键字段白名单收敛', [
                'config_key' => self::CTRIP_PROFILE_FIELDS_CONFIG_KEY,
                'before_count' => count($fields),
                'after_count' => count($keyFields),
            ]);
            $fields = $keyFields;
            $this->writeCtripProfileCaptureFields($fields);
        }

        uasort($fields, function (array $a, array $b): int {
            $sort = ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0));
            if ($sort !== 0) {
                return $sort;
            }
            return strcmp((string)($a['field_key'] ?? ''), (string)($b['field_key'] ?? ''));
        });

        return $includeDeleted ? $fields : $this->activeCtripProfileCaptureFields($fields);
    }

    private function writeCtripProfileCaptureFields(array $fields, bool $createOnly = false): void
    {
        $normalized = [];
        foreach ($fields as $item) {
            if (!is_array($item)) {
                continue;
            }
            $field = $this->normalizeCtripProfileCaptureField($item);
            if ($field['id'] !== '' && $this->isCtripProfileKeyField((string)($field['field_key'] ?? ''))) {
                $normalized[$field['id']] = $field;
            }
        }

        $payload = [
            'version' => self::CTRIP_PROFILE_FIELDS_CONFIG_VERSION,
            'fields' => $normalized,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $jsonValue = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $existing = \think\facade\Db::name('system_configs')->where('config_key', self::CTRIP_PROFILE_FIELDS_CONFIG_KEY)->find();
        if ($existing) {
            if ($createOnly) {
                return;
            }
            \think\facade\Db::name('system_configs')->where('config_key', self::CTRIP_PROFILE_FIELDS_CONFIG_KEY)->update([
                'config_value' => $jsonValue,
                'update_time' => date('Y-m-d H:i:s'),
            ]);
            return;
        }

        \think\facade\Db::name('system_configs')->insert([
            'config_key' => self::CTRIP_PROFILE_FIELDS_CONFIG_KEY,
            'config_value' => $jsonValue,
            'description' => '携程 Profile 自动获取字段目录',
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
    }

    private function normalizeCtripProfileFieldSampleVerificationStatus($value): string
    {
        $status = strtolower(trim((string)$value));
        if (in_array($status, ['matched', 'match', 'ok', 'correct'], true)) {
            return 'matched';
        }
        if (in_array($status, ['mismatched', 'mismatch', 'wrong', 'incorrect'], true)) {
            return 'mismatched';
        }
        return 'unverified';
    }

    private function statusForCtripProfileFieldSampleVerification(string $sampleStatus, string $currentStatus): string
    {
        if ($sampleStatus === 'matched') {
            return 'confirmed';
        }
        if ($sampleStatus === 'mismatched') {
            return 'needs_parser';
        }

        return in_array($currentStatus, ['confirmed', 'needs_section', 'needs_parser', 'not_captured', 'pending', 'paused'], true)
            ? $currentStatus
            : 'pending';
    }

    private function buildCtripProfileFieldKeyFromText(string $value): string
    {
        $text = strtolower(trim($value));
        $key = trim((string)preg_replace('/[^a-z0-9_\-]+/', '_', $text), '_-');
        if ($key !== '') {
            return $key;
        }

        return 'custom_' . substr(hash('crc32b', $text !== '' ? $text : microtime(true)), 0, 8);
    }

    private function firstCtripProfileFieldText(array $item, array $keys, array $original = []): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $item)) {
                $value = trim((string)$item[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $original)) {
                $value = trim((string)$original[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function hasRequiredCtripProfileFieldEvidence(array $requestData): bool
    {
        return $this->firstCtripProfileFieldText($requestData, ['page_url', 'pageUrl']) !== ''
            && $this->firstCtripProfileFieldText($requestData, ['request_url', 'requestUrl', 'source_url', 'sourceUrl']) !== ''
            && $this->firstCtripProfileFieldText($requestData, ['json_path', 'jsonPath', 'json', 'jsonEvidence']) !== ''
            && $this->firstCtripProfileFieldText($requestData, ['target_value', 'targetValue', 'target_field', 'targetField', 'requested_field', 'requestedField', 'source_keys', 'sourceKeys']) !== ''
            && $this->firstCtripProfileFieldText($requestData, ['value_meaning', 'valueMeaning', 'business_meaning', 'businessMeaning', 'field_name', 'fieldName']) !== '';
    }

    private function prepareCtripProfileFieldSaveData(array $requestData, array $original = [], bool $isNew = false): array
    {
        $pageUrl = $this->firstCtripProfileFieldText($requestData, ['page_url', 'pageUrl'], $original);
        $requestUrl = $this->firstCtripProfileFieldText($requestData, ['request_url', 'requestUrl', 'source_url', 'sourceUrl'], $original);
        $jsonPath = $this->firstCtripProfileFieldText($requestData, ['json_path', 'jsonPath', 'json', 'jsonEvidence'], $original);
        $targetValue = $this->firstCtripProfileFieldText($requestData, ['target_value', 'targetValue', 'target_field', 'targetField', 'requested_field', 'requestedField', 'source_keys', 'sourceKeys'], $original);
        $valueMeaning = $this->firstCtripProfileFieldText($requestData, ['value_meaning', 'valueMeaning', 'business_meaning', 'businessMeaning', 'field_name', 'fieldName'], $original);

        if (($requestData['field_key'] ?? $requestData['fieldKey'] ?? '') === '') {
            $requestData['field_key'] = $this->buildCtripProfileFieldKeyFromText($targetValue !== '' ? $targetValue : ($valueMeaning !== '' ? $valueMeaning : $pageUrl));
        }
        if (($requestData['field_name'] ?? $requestData['fieldName'] ?? '') === '' && $valueMeaning !== '') {
            $requestData['field_name'] = $valueMeaning;
        }
        if (($requestData['source_keys'] ?? $requestData['sourceKeys'] ?? '') === '' && $targetValue !== '') {
            $requestData['source_keys'] = $targetValue;
        }
        if (($requestData['target_value'] ?? $requestData['targetValue'] ?? '') === '' && $targetValue !== '') {
            $requestData['target_value'] = $targetValue;
        }
        if (($requestData['target_field'] ?? $requestData['targetField'] ?? '') === '' && $targetValue !== '') {
            $requestData['target_field'] = $targetValue;
        }
        if (($requestData['value_meaning'] ?? $requestData['valueMeaning'] ?? '') === '' && $valueMeaning !== '') {
            $requestData['value_meaning'] = $valueMeaning;
        }
        if (($requestData['page_url'] ?? $requestData['pageUrl'] ?? '') === '' && $pageUrl !== '') {
            $requestData['page_url'] = $pageUrl;
        }
        if (($requestData['request_url'] ?? $requestData['requestUrl'] ?? '') === '' && $requestUrl !== '') {
            $requestData['request_url'] = $requestUrl;
        }
        if (($requestData['json_path'] ?? $requestData['jsonPath'] ?? '') === '' && $jsonPath !== '') {
            $requestData['json_path'] = $jsonPath;
        }
        if ($isNew && (($requestData['status'] ?? '') === '' || ($requestData['status'] ?? '') === 'pending')) {
            $requestData['status'] = 'needs_parser';
        }

        return $requestData;
    }

    private function normalizeCtripProfileCaptureField(array $item, array $original = []): array
    {
        $id = trim((string)($item['id'] ?? $original['id'] ?? ''));
        $fieldKey = strtolower(trim((string)($item['field_key'] ?? $item['fieldKey'] ?? $original['field_key'] ?? '')));
        $fieldKey = preg_replace('/[^a-z0-9_\-]+/', '_', $fieldKey) ?: '';
        $pageUrl = trim((string)($item['page_url'] ?? $item['pageUrl'] ?? $original['page_url'] ?? ''));
        $section = strtolower(trim((string)($item['section'] ?? $original['section'] ?? 'business_overview')));
        $section = preg_replace('/[^a-z0-9_\-]+/', '_', $section) ?: 'business_overview';
        $section = $this->classifyCtripProfileCaptureSectionByPageUrl($pageUrl, $section);
        $status = strtolower(trim((string)($item['status'] ?? $original['status'] ?? 'pending')));
        if (!in_array($status, ['confirmed', 'needs_section', 'needs_parser', 'not_captured', 'pending', 'paused'], true)) {
            $status = 'pending';
        }
        $sourceKeys = $item['source_keys'] ?? $item['sourceKeys'] ?? $original['source_keys'] ?? '';
        if (is_array($sourceKeys)) {
            $sourceKeys = implode(', ', array_values(array_filter(array_map('strval', $sourceKeys))));
        }
        $sampleVerificationStatus = $this->normalizeCtripProfileFieldSampleVerificationStatus(
            $item['sample_verification_status']
                ?? $item['sampleVerificationStatus']
                ?? $original['sample_verification_status']
                ?? 'unverified'
        );
        $sampleVerifiedAt = trim((string)($item['sample_verified_at'] ?? $item['sampleVerifiedAt'] ?? $original['sample_verified_at'] ?? ''));
        $sampleVerifiedBy = $item['sample_verified_by'] ?? $item['sampleVerifiedBy'] ?? $original['sample_verified_by'] ?? null;
        if ($sampleVerificationStatus === 'unverified') {
            $sampleVerifiedAt = '';
            $sampleVerifiedBy = null;
        }
        $verifiedSampleValue = trim((string)($item['verified_sample_value'] ?? $item['verifiedSampleValue'] ?? $original['verified_sample_value'] ?? ''));
        $verifiedSampleUnit = trim((string)($item['verified_sample_unit'] ?? $item['verifiedSampleUnit'] ?? $original['verified_sample_unit'] ?? ''));
        $verifiedSampleSourceKey = trim((string)($item['verified_sample_source_key'] ?? $item['verifiedSampleSourceKey'] ?? $original['verified_sample_source_key'] ?? ''));
        $verifiedSampleSourcePath = trim((string)($item['verified_sample_source_path'] ?? $item['verifiedSampleSourcePath'] ?? $original['verified_sample_source_path'] ?? ''));
        $verifiedSampleEndpointId = trim((string)($item['verified_sample_endpoint_id'] ?? $item['verifiedSampleEndpointId'] ?? $original['verified_sample_endpoint_id'] ?? ''));
        $verifiedSampleDataDate = trim((string)($item['verified_sample_data_date'] ?? $item['verifiedSampleDataDate'] ?? $original['verified_sample_data_date'] ?? ''));
        $verifiedSampleHotelName = trim((string)($item['verified_sample_hotel_name'] ?? $item['verifiedSampleHotelName'] ?? $original['verified_sample_hotel_name'] ?? ''));
        $verifiedSampleCapturedAt = trim((string)($item['verified_sample_captured_at'] ?? $item['verifiedSampleCapturedAt'] ?? $original['verified_sample_captured_at'] ?? ''));
        if ($sampleVerificationStatus !== 'matched') {
            $verifiedSampleValue = '';
            $verifiedSampleUnit = '';
            $verifiedSampleSourceKey = '';
            $verifiedSampleSourcePath = '';
            $verifiedSampleEndpointId = '';
            $verifiedSampleDataDate = '';
            $verifiedSampleHotelName = '';
            $verifiedSampleCapturedAt = '';
        }
        $deletedAt = trim((string)($item['deleted_at'] ?? $item['deletedAt'] ?? $original['deleted_at'] ?? ''));
        $deletedBy = $item['deleted_by'] ?? $item['deletedBy'] ?? $original['deleted_by'] ?? null;
        $enabled = array_key_exists('enabled', $item) ? $this->meituanBool($item['enabled']) : (bool)($original['enabled'] ?? true);
        if ($deletedAt !== '') {
            $enabled = false;
            $status = 'paused';
        }

        $normalized = [
            'id' => $id,
            'field_key' => $fieldKey,
            'field_name' => trim((string)($item['field_name'] ?? $item['fieldName'] ?? $original['field_name'] ?? '')),
            'section' => $section,
            'data_type' => strtolower(trim((string)($item['data_type'] ?? $item['dataType'] ?? $original['data_type'] ?? 'business'))),
            'page_location' => trim((string)($item['page_location'] ?? $item['pageLocation'] ?? $original['page_location'] ?? '')),
            'target_field' => trim((string)($item['target_field'] ?? $item['targetField'] ?? $original['target_field'] ?? '')),
            'target_value' => trim((string)($item['target_value'] ?? $item['targetValue'] ?? $original['target_value'] ?? '')),
            'value_meaning' => trim((string)($item['value_meaning'] ?? $item['valueMeaning'] ?? $original['value_meaning'] ?? '')),
            'source_interface' => trim((string)($item['source_interface'] ?? $item['sourceInterface'] ?? $original['source_interface'] ?? '')),
            'page_url' => $pageUrl,
            'request_url' => trim((string)($item['request_url'] ?? $item['requestUrl'] ?? $item['source_url'] ?? $item['sourceUrl'] ?? $original['request_url'] ?? $original['source_url'] ?? '')),
            'json_path' => trim((string)($item['json_path'] ?? $item['jsonPath'] ?? $item['json'] ?? $item['jsonEvidence'] ?? $original['json_path'] ?? '')),
            'ownership_rule' => trim((string)($item['ownership_rule'] ?? $item['ownershipRule'] ?? $original['ownership_rule'] ?? '')),
            'storage_field' => trim((string)($item['storage_field'] ?? $item['storageField'] ?? $original['storage_field'] ?? '')),
            'source_keys' => trim((string)$sourceKeys),
            'value_type' => trim((string)($item['value_type'] ?? $item['valueType'] ?? $original['value_type'] ?? '')),
            'unit' => trim((string)($item['unit'] ?? $original['unit'] ?? '')),
            'transform_rule' => trim((string)($item['transform_rule'] ?? $item['transformRule'] ?? $original['transform_rule'] ?? '')),
            'status' => $status,
            'enabled' => $enabled,
            'sample_verification_status' => $sampleVerificationStatus,
            'sample_verified_at' => $sampleVerifiedAt,
            'sample_verified_by' => $sampleVerifiedBy,
            'verified_sample_value' => $verifiedSampleValue,
            'verified_sample_unit' => $verifiedSampleUnit,
            'verified_sample_source_key' => $verifiedSampleSourceKey,
            'verified_sample_source_path' => $verifiedSampleSourcePath,
            'verified_sample_endpoint_id' => $verifiedSampleEndpointId,
            'verified_sample_data_date' => $verifiedSampleDataDate,
            'verified_sample_hotel_name' => $verifiedSampleHotelName,
            'verified_sample_captured_at' => $verifiedSampleCapturedAt,
            'notes' => trim((string)($item['notes'] ?? $original['notes'] ?? '')),
            'sort_order' => (int)($item['sort_order'] ?? $item['sortOrder'] ?? $original['sort_order'] ?? 0),
            'created_at' => (string)($item['created_at'] ?? $original['created_at'] ?? date('Y-m-d H:i:s')),
            'update_time' => (string)($item['update_time'] ?? $item['updated_at'] ?? $original['update_time'] ?? date('Y-m-d H:i:s')),
            'user_id' => $item['user_id'] ?? $original['user_id'] ?? null,
            'deleted_at' => $deletedAt,
            'deleted_by' => $deletedBy,
        ];
        $this->assertCtripProfileFieldRuntimeMetadataSafe($normalized);
        return $normalized;
    }

    private function assertCtripProfileFieldRuntimeMetadataSafe(array $field): void
    {
        foreach (['field_name', 'source_interface', 'source_keys'] as $key) {
            $value = trim((string)($field[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            if (
                preg_match('/["\']?(?:cookie|set-cookie|authorization|proxy-authorization|x-api-key|api-key|auth_data|token|access_token|refresh_token|spidertoken|spiderkey|mtgsig|usertoken|usersign|password)["\']?\s*[:=]/i', $value) === 1
                || preg_match('/\bbearer\s+[A-Za-z0-9._~+\/=:-]{8,}/i', $value) === 1
            ) {
                throw new \InvalidArgumentException('携程 Profile 字段元数据不得包含 Cookie、token 或 Authorization 等凭据内容');
            }
        }
    }

    private function classifyCtripProfileCaptureSectionByPageUrl(string $pageUrl, string $currentSection): string
    {
        $normalizedUrl = strtolower(trim($pageUrl));
        if ($normalizedUrl === '') {
            return $currentSection !== '' ? $currentSection : 'business_overview';
        }
        if (
            (str_contains($normalizedUrl, '/datacenter/userbehavior/user') || str_contains($normalizedUrl, '/datacenter/inland/userbehavior/user'))
            && str_contains($normalizedUrl, 'goto=im')
        ) {
            return 'im_board';
        }

        $rules = [
            ['path' => '/datacenter/inland/businessreport/outline', 'section' => 'business_overview'],
            ['path' => '/datacenter/inland/businessreport/weekreport', 'section' => 'business_weekly_overview'],
            ['path' => '/datacenter/inland/businessreport/beneficialdata', 'section' => 'sales_report'],
            ['path' => '/datacenter/inland/businessreport/flowdata', 'section' => 'traffic_report'],
            ['path' => '/comment/commentlist', 'section' => 'comment_review'],
            ['path' => '/datacenter/inland/userbehavior/im', 'section' => 'im_board'],
            ['path' => '/datacenter/userbehavior/user', 'section' => 'user_profile'],
            ['path' => '/datacenter/inland/userbehavior/user', 'section' => 'user_profile'],
            ['path' => '/ebkgrowth/datacenter/competition/competitionprofile', 'section' => 'competitor_overview'],
            ['path' => '/datacenter/competition/competitionprofile', 'section' => 'competitor_overview'],
            ['path' => '/ebkgrowth/datacenter/competition/lossanalysis', 'section' => 'loss_analysis'],
            ['path' => '/datacenter/competition/lossanalysis', 'section' => 'loss_analysis'],
            ['path' => '/ebkgrowth/datacenter/competition/competitionlist', 'section' => 'competitor_rank'],
            ['path' => '/datacenter/competition/competitionlist', 'section' => 'competitor_rank'],
            ['path' => '/ebkgrowth/datacenter/marketanalysis/marketheat', 'section' => 'market_calendar'],
            ['path' => '/datacenter/marketanalysis/marketheat', 'section' => 'market_calendar'],
            ['path' => '/toolcenter/cpc/', 'section' => 'ads_pyramid'],
            ['path' => '/advertise/cpc/', 'section' => 'ads_pyramid'],
            ['path' => '/pyramidad/', 'section' => 'ads_pyramid'],
            ['path' => '/toolcenter/psi/index', 'section' => 'quality_psi'],
            ['path' => '/psi/index', 'section' => 'quality_psi'],
        ];

        foreach ($rules as $rule) {
            if (str_contains($normalizedUrl, $rule['path'])) {
                return $rule['section'];
            }
        }

        return $currentSection !== '' ? $currentSection : 'business_overview';
    }

    private function summarizeCtripProfileCaptureFields(array $fields): array
    {
        $statusCounts = [];
        $sampleVerificationCounts = [
            'unverified' => 0,
            'matched' => 0,
            'mismatched' => 0,
        ];
        $sections = [];
        $enabled = 0;
        $confirmed = 0;
        foreach ($fields as $field) {
            $status = (string)($field['status'] ?? 'pending');
            $section = (string)($field['section'] ?? 'business_overview');
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            if ($status === 'confirmed') {
                $confirmed++;
            }
            $sampleVerificationStatus = $this->normalizeCtripProfileFieldSampleVerificationStatus($field['sample_verification_status'] ?? 'unverified');
            $sampleVerificationCounts[$sampleVerificationStatus] = ($sampleVerificationCounts[$sampleVerificationStatus] ?? 0) + 1;
            $sections[$section] = ($sections[$section] ?? 0) + 1;
            if (!empty($field['enabled'])) {
                $enabled++;
            }
        }

        ksort($statusCounts);
        ksort($sections);

        return [
            'total' => count($fields),
            'enabled' => $enabled,
            'confirmed_field_count' => $confirmed,
            'doubtful_field_count' => max(0, count($fields) - $confirmed),
            'status_counts' => $statusCounts,
            'sample_verification_counts' => $sampleVerificationCounts,
            'sections' => $sections,
        ];
    }

    private function discoverCtripProfileAutoFetchFieldCandidates(int $limit = 5): array
    {
        try {
            $rows = Db::name('platform_data_raw_records')
                ->field('id,raw_payload,received_at')
                ->where('platform', 'ctrip')
                ->where('ingestion_method', 'browser_profile')
                ->order('received_at', 'desc')
                ->order('id', 'desc')
                ->limit(max(1, min(10, $limit)))
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            \think\facade\Log::warning('读取携程 Profile 自动获取原始载荷失败: ' . $e->getMessage());
            return [];
        }

        $candidates = [];
        foreach ($rows as $row) {
            $payload = json_decode((string)($row['raw_payload'] ?? ''), true);
            if (!is_array($payload)) {
                continue;
            }
            foreach ($this->extractCtripProfileFieldCandidatesFromPayload($payload, (string)($row['received_at'] ?? '')) as $candidate) {
                $this->addCtripProfileAutoFetchFieldCandidate($candidates, $candidate);
            }
        }
        foreach ($this->discoverCtripProfileAutoFetchFieldCandidatesFromMetricFacts() as $candidate) {
            $this->addCtripProfileAutoFetchFieldCandidate($candidates, $candidate);
        }

        ksort($candidates);
        return array_values($candidates);
    }

    private function discoverCtripProfileAutoFetchFieldCandidatesFromMetricFacts(int $limit = 500): array
    {
        try {
            $rows = Db::name('ota_ctrip_metric_facts')
                ->field('capture_section,metric_key,MAX(metric_label) AS metric_label,MAX(category) AS category,MAX(data_type) AS data_type,MAX(unit) AS unit,MAX(value_type) AS value_type,MAX(endpoint_id) AS endpoint_id,MAX(source_key) AS source_key,MAX(source_path) AS source_path,MAX(captured_at) AS captured_at')
                ->where('source', 'ctrip')
                ->where('capture_section', '<>', '')
                ->where('metric_key', '<>', '')
                ->group('capture_section,metric_key')
                ->limit(max(1, min(1000, $limit)))
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            \think\facade\Log::warning('读取携程 Profile 已入库指标事实失败: ' . $e->getMessage());
            return [];
        }

        $candidates = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $fieldKey = strtolower(trim((string)($row['metric_key'] ?? '')));
            $fieldKey = preg_replace('/[^a-z0-9_\-]+/', '_', $fieldKey) ?: '';
            $section = strtolower(trim((string)($row['capture_section'] ?? '')));
            $section = preg_replace('/[^a-z0-9_\-]+/', '_', $section) ?: '';
            if ($fieldKey === '' || $section === '') {
                continue;
            }
            $sourceKeys = array_values(array_unique(array_filter([
                trim((string)($row['source_key'] ?? '')),
                trim((string)($row['source_path'] ?? '')),
                $fieldKey,
            ], static fn(string $value): bool => $value !== '')));

            $candidates[] = [
                'field_key' => $fieldKey,
                'field_name' => trim((string)($row['metric_label'] ?? '')) ?: $fieldKey,
                'section' => $section,
                'data_type' => strtolower(trim((string)($row['data_type'] ?? $row['category'] ?? 'business'))) ?: 'business',
                'source_interface' => trim((string)($row['endpoint_id'] ?? '')),
                'source_keys' => implode(', ', $sourceKeys),
                'value_type' => trim((string)($row['value_type'] ?? '')) ?: 'number',
                'unit' => trim((string)($row['unit'] ?? '')),
                'status' => 'pending',
                'enabled' => false,
                'transform_rule' => '已入库指标事实发现字段，需确认口径后启用',
                'notes' => '来自 ota_ctrip_metric_facts 已入库事实；默认停用，确认来源路径后可启用。',
                'captured_at' => (string)($row['captured_at'] ?? ''),
            ];
        }

        return $candidates;
    }

    private function addCtripProfileAutoFetchFieldCandidate(array &$candidates, array $candidate): void
    {
        $key = $this->ctripProfileAutoFetchCandidateKey($candidate);
        if ($key === '') {
            return;
        }
        if (!isset($candidates[$key])) {
            $candidates[$key] = $candidate;
            return;
        }
        $candidates[$key] = $this->mergeCtripProfileAutoFetchFieldCandidate($candidates[$key], $candidate);
    }

    private function ctripProfileAutoFetchCandidateKey(array $candidate): string
    {
        $fieldKey = strtolower(trim((string)($candidate['field_key'] ?? '')));
        $fieldKey = preg_replace('/[^a-z0-9_\-]+/', '_', $fieldKey) ?: '';
        if ($fieldKey === '') {
            return '';
        }
        $section = strtolower(trim((string)($candidate['section'] ?? '')));
        $section = preg_replace('/[^a-z0-9_\-]+/', '_', $section) ?: '';
        return ($section !== '' ? $section : 'unknown') . ':' . $fieldKey;
    }

    private function extractCtripProfileFieldCandidatesFromPayload(array $payload, string $capturedAt = ''): array
    {
        $candidates = [];
        foreach (is_array($payload['standard_rows'] ?? null) ? $payload['standard_rows'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($this->buildCtripProfileAutoFetchFieldCandidatesFromStandardRow($row, $capturedAt) as $candidate) {
                $this->addCtripProfileAutoFetchFieldCandidate($candidates, $candidate);
            }
        }

        return array_values($candidates);
    }

    private function buildCtripProfileAutoFetchFieldCandidatesFromStandardRow(array $row, string $capturedAt = ''): array
    {
        $rawData = $row['raw_data'] ?? [];
        if (is_string($rawData) && trim($rawData) !== '') {
            $decoded = json_decode($rawData, true);
            $rawData = is_array($decoded) ? $decoded : [];
        }
        $rawData = is_array($rawData) ? $rawData : [];
        $facts = is_array($rawData['facts'] ?? null) ? $rawData['facts'] : [];
        if ($facts === []) {
            $facts = [[
                'metric_key' => $row['metric_key'] ?? $this->metricKeyFromCtripProfileDimension((string)($row['dimension'] ?? '')),
                'metric_label' => $row['metric_label'] ?? '',
                'source_key' => $row['source_key'] ?? '',
                'source_path' => $row['source_path'] ?? '',
                'value' => $row['value'] ?? $row['data_value'] ?? $row['amount'] ?? $row['quantity'] ?? $row['book_order_num'] ?? null,
            ]];
        }

        $section = strtolower(trim((string)($row['capture_section'] ?? $rawData['section'] ?? '')));
        if ($section === '') {
            $section = $this->sectionFromCtripProfileDimension((string)($row['dimension'] ?? '')) ?: 'business_overview';
        }
        $endpointId = trim((string)($row['endpoint_id'] ?? $rawData['endpoint_id'] ?? ''));
        $dataType = strtolower(trim((string)($row['data_type'] ?? 'business'))) ?: 'business';
        $candidates = [];
        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $metricKey = strtolower(trim((string)($fact['metric_key'] ?? '')));
            $metricKey = preg_replace('/[^a-z0-9_\-]+/', '_', $metricKey) ?: '';
            if ($metricKey === '') {
                continue;
            }
            $sourceKey = trim((string)($fact['source_key'] ?? ''));
            $sourcePath = trim((string)($fact['source_path'] ?? ''));
            $sourceKeys = array_values(array_unique(array_filter([$sourceKey, $sourcePath, $metricKey], static fn($value): bool => trim((string)$value) !== '')));
            $value = $fact['value'] ?? null;
            $valueType = is_numeric(is_array($value) ? null : $value) ? 'number' : 'text';
            if (in_array((string)($rawData['metric_status'] ?? ''), ['non_numeric_fact'], true)) {
                $valueType = 'text';
            }

            $candidates[] = [
                'field_key' => $metricKey,
                'field_name' => trim((string)($fact['metric_label'] ?? '')) ?: $metricKey,
                'section' => $section,
                'data_type' => $dataType,
                'source_interface' => $endpointId,
                'source_keys' => implode(', ', $sourceKeys),
                'value_type' => $valueType,
                'unit' => '',
                'status' => 'pending',
                'enabled' => false,
                'transform_rule' => '自动获取发现字段，需确认口径后启用',
                'notes' => '来自最近携程 Profile 自动获取；默认停用，确认后可启用。',
                'captured_at' => $capturedAt,
            ];
        }

        return $candidates;
    }

    private function mergeCtripProfileAutoFetchFieldCandidate(array $existing, array $candidate): array
    {
        foreach (['source_interface', 'source_keys'] as $key) {
            $values = array_values(array_unique(array_filter(array_merge(
                preg_split('/\s*,\s*/', (string)($existing[$key] ?? '')) ?: [],
                preg_split('/\s*,\s*/', (string)($candidate[$key] ?? '')) ?: []
            ), static fn($value): bool => trim((string)$value) !== '')));
            $existing[$key] = implode(', ', $values);
        }
        foreach (['field_name', 'section', 'data_type', 'value_type', 'unit', 'captured_at'] as $key) {
            if (trim((string)($existing[$key] ?? '')) === '' && trim((string)($candidate[$key] ?? '')) !== '') {
                $existing[$key] = $candidate[$key];
            }
        }
        return $existing;
    }

    private function mergeCtripProfileAutoFetchFieldCandidates(array &$fields, array $candidates): array
    {
        $candidateScope = $this->scopeCtripProfileAutoFetchFieldCandidates($candidates);
        $candidates = $candidateScope['key_candidates'];
        $existingScopeKeys = [];
        $maxSortOrder = 0;
        foreach ($fields as $field) {
            $fieldKey = (string)($field['field_key'] ?? '');
            if ($fieldKey !== '') {
                $existingScopeKeys[$this->ctripProfileFieldScopeKey($fieldKey, (string)($field['section'] ?? ''))] = true;
            }
            $maxSortOrder = max($maxSortOrder, (int)($field['sort_order'] ?? 0));
        }

        $added = [];
        $matched = 0;
        foreach ($candidates as $candidate) {
            $fieldKey = (string)($candidate['field_key'] ?? '');
            if ($fieldKey === '') {
                continue;
            }
            $section = (string)($candidate['section'] ?? '');
            $scopeKey = $this->ctripProfileFieldScopeKey($fieldKey, $section);
            if (isset($existingScopeKeys[$scopeKey])) {
                $matched++;
                continue;
            }
            $maxSortOrder += 10;
            $idPart = trim(preg_replace('/[^a-z0-9_\-]+/', '_', $fieldKey) ?: $fieldKey, '_');
            $sectionPart = trim(preg_replace('/[^a-z0-9_\-]+/', '_', $section) ?: '', '_');
            $idBase = 'profile_field_' . $idPart;
            if (isset($fields[$idBase]) && $sectionPart !== '') {
                $idBase = 'profile_field_' . $sectionPart . '_' . $idPart;
            }
            $id = $idBase;
            $suffix = 1;
            while (isset($fields[$id])) {
                $suffix++;
                $id = $idBase . '_' . $suffix;
            }
            $field = $this->normalizeCtripProfileCaptureField(array_merge($candidate, [
                'id' => $id,
                'sort_order' => $maxSortOrder,
                'created_at' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
                'user_id' => $this->currentUser->id ?? null,
            ]));
            $fields[$id] = $field;
            $existingScopeKeys[$scopeKey] = true;
            $added[] = [
                'id' => $id,
                'field_key' => $fieldKey,
                'field_name' => (string)($field['field_name'] ?? ''),
                'section' => (string)($field['section'] ?? ''),
                'source_interface' => (string)($field['source_interface'] ?? ''),
            ];
        }

        return [
            'discovered_count' => count($candidates),
            'skipped_count' => count($candidateScope['skipped_candidates']),
            'matched_count' => $matched,
            'added_count' => count($added),
            'added_fields' => $added,
        ];
    }

    private function summarizeCtripProfileAutoFetchFieldCandidates(array $fields, array $candidates, array $skippedCandidates = []): array
    {
        $existingScopeKeys = [];
        foreach ($fields as $field) {
            $fieldKey = (string)($field['field_key'] ?? '');
            if ($fieldKey !== '') {
                $existingScopeKeys[$this->ctripProfileFieldScopeKey($fieldKey, (string)($field['section'] ?? ''))] = true;
            }
        }

        $missing = [];
        $configured = 0;
        $latestTime = '';
        foreach ($candidates as $candidate) {
            $fieldKey = (string)($candidate['field_key'] ?? '');
            if ($fieldKey === '') {
                continue;
            }
            if (isset($existingScopeKeys[$this->ctripProfileFieldScopeKey($fieldKey, (string)($candidate['section'] ?? ''))])) {
                $configured++;
            } else {
                $missing[] = [
                    'field_key' => $fieldKey,
                    'field_name' => (string)($candidate['field_name'] ?? ''),
                    'section' => (string)($candidate['section'] ?? ''),
                    'source_interface' => (string)($candidate['source_interface'] ?? ''),
                ];
            }
            $capturedAt = (string)($candidate['captured_at'] ?? '');
            if ($capturedAt !== '' && $capturedAt > $latestTime) {
                $latestTime = $capturedAt;
            }
        }

        return [
            'auto_fetch_field_count' => count($candidates),
            'auto_fetch_configured_field_count' => $configured,
            'auto_fetch_missing_field_count' => count($missing),
            'auto_fetch_missing_fields' => $missing,
            'auto_fetch_skipped_field_count' => count($skippedCandidates),
            'auto_fetch_skipped_fields' => array_slice(array_map(static fn(array $candidate): array => [
                'field_key' => (string)($candidate['field_key'] ?? ''),
                'field_name' => (string)($candidate['field_name'] ?? ''),
                'section' => (string)($candidate['section'] ?? ''),
                'source_interface' => (string)($candidate['source_interface'] ?? ''),
            ], $skippedCandidates), 0, 50),
            'auto_fetch_latest_time' => $latestTime !== '' ? $latestTime : null,
        ];
    }

    private function ctripProfileFieldScopeKey(string $fieldKey, string $section): string
    {
        $fieldKey = strtolower(trim($fieldKey));
        $fieldKey = preg_replace('/[^a-z0-9_\-]+/', '_', $fieldKey) ?: '';
        if ($fieldKey === '') {
            return '';
        }
        $section = strtolower(trim($section));
        $section = preg_replace('/[^a-z0-9_\-]+/', '_', $section) ?: 'unknown';
        return $section . ':' . $fieldKey;
    }

    private function metricKeyFromCtripProfileDimension(string $dimension): string
    {
        $parts = explode(':', $dimension);
        return count($parts) >= 4 && $parts[0] === 'catalog' ? (string)$parts[3] : '';
    }

    private function sectionFromCtripProfileDimension(string $dimension): string
    {
        $parts = explode(':', $dimension);
        return count($parts) >= 2 && $parts[0] === 'catalog' ? (string)$parts[1] : '';
    }

    private function ctripProfileSampleBucketKeyForRow(string $fieldKey, string $section, array $fieldScopesByMetric): ?string
    {
        $fieldKey = strtolower(trim($fieldKey));
        $fieldKey = preg_replace('/[^a-z0-9_\-]+/', '_', $fieldKey) ?: '';
        if ($fieldKey === '' || empty($fieldScopesByMetric[$fieldKey])) {
            return null;
        }

        $section = strtolower(trim($section));
        $section = preg_replace('/[^a-z0-9_\-]+/', '_', $section) ?: '';
        if ($section !== '') {
            $scopeKey = $this->ctripProfileFieldScopeKey($fieldKey, $section);
            if (isset($fieldScopesByMetric[$fieldKey][$scopeKey])) {
                return $scopeKey;
            }
        }

        if (count($fieldScopesByMetric[$fieldKey]) === 1) {
            return array_key_first($fieldScopesByMetric[$fieldKey]);
        }

        return null;
    }

    private function ctripProfileSampleSectionFromOnlineDailyRow(array $row, array $raw): string
    {
        $section = strtolower(trim((string)($row['capture_section'] ?? $raw['capture_section'] ?? $raw['section'] ?? '')));
        if ($section === '') {
            $section = $this->sectionFromCtripProfileDimension((string)($row['dimension'] ?? ''));
        }

        $section = strtolower(trim($section));
        return preg_replace('/[^a-z0-9_\-]+/', '_', $section) ?: '';
    }

    private function hydrateCtripProfileFieldLatestSamples(array &$fields): array
    {
        $sampleLimit = 8;
        $fieldKeys = [];
        $fieldScopesByMetric = [];
        foreach ($fields as $field) {
            $fieldKey = (string)($field['field_key'] ?? '');
            if ($fieldKey !== '') {
                $fieldKeys[$fieldKey] = true;
                $normalizedFieldKey = strtolower(trim($fieldKey));
                $normalizedFieldKey = preg_replace('/[^a-z0-9_\-]+/', '_', $normalizedFieldKey) ?: '';
                $scopeKey = $this->ctripProfileFieldScopeKey($fieldKey, (string)($field['section'] ?? ''));
                if ($normalizedFieldKey !== '' && $scopeKey !== '') {
                    $fieldScopesByMetric[$normalizedFieldKey][$scopeKey] = true;
                }
            }
        }

        foreach ($fields as &$field) {
            $field['latest_value'] = '';
            $field['latest_values'] = [];
            $field['latest_sample'] = null;
            $field['latest_sample_note'] = '未采到样例';
        }
        unset($field);

        if (!$fieldKeys) {
            return [
                'sampled_field_count' => 0,
                'latest_sample_time' => null,
                'latest_sample_batch_key' => null,
            ];
        }

        try {
            $rows = \think\facade\Db::name('ota_ctrip_metric_facts')
                ->whereIn('metric_key', array_keys($fieldKeys))
                ->order('captured_at', 'desc')
                ->order('id', 'desc')
                ->limit(1500)
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            \think\facade\Log::warning('读取携程 Profile 字段样例失败: ' . $e->getMessage());
            return [
                'sampled_field_count' => 0,
                'latest_sample_time' => null,
                'latest_sample_batch_key' => null,
                'sample_error' => '读取最近样例失败',
            ];
        }

        $samplesByKey = [];
        $latestSampleTime = null;
        $latestSampleBatchKey = null;
        $this->hydrateCtripProfileFieldSamplesFromOnlineDailyData($samplesByKey, $fields, $latestSampleTime, $latestSampleBatchKey, $sampleLimit);

        foreach ($rows as $row) {
            $metricKey = (string)($row['metric_key'] ?? '');
            if ($metricKey === '' || !isset($fieldKeys[$metricKey])) {
                continue;
            }
            if ($this->ctripProfilePrefersOnlineDailySamples($metricKey)) {
                continue;
            }
            $bucketKey = $this->ctripProfileSampleBucketKeyForRow(
                $metricKey,
                (string)($row['capture_section'] ?? ''),
                $fieldScopesByMetric
            );
            if ($bucketKey === null) {
                continue;
            }

            $value = $this->formatCtripProfileFieldSampleValue(
                $row['value_decimal'] ?? null,
                $row['value_text'] ?? null,
                $row['value_json'] ?? null
            );
            if ($value === '') {
                continue;
            }

            $samplesByKey[$bucketKey] ??= [
                'items' => [],
                'seen' => [],
            ];
            if (isset($samplesByKey[$bucketKey]['seen'][$value])) {
                continue;
            }

            $samplesByKey[$bucketKey]['seen'][$value] = true;
            $capturedAt = (string)($row['captured_at'] ?? '');
            $runId = trim((string)($row['run_id'] ?? ''));
            $batchKey = $runId !== ''
                ? 'run:' . $runId
                : ($capturedAt !== '' ? 'captured_at:' . $capturedAt : '');
            if (count($samplesByKey[$bucketKey]['items']) < $sampleLimit) {
                $samplesByKey[$bucketKey]['items'][] = [
                    'value' => $value,
                    'unit' => (string)($row['unit'] ?? ''),
                    'data_date' => (string)($row['data_date'] ?? ''),
                    'hotel_name' => (string)($row['hotel_name'] ?? ''),
                    'capture_section' => (string)($row['capture_section'] ?? ''),
                    'endpoint_id' => (string)($row['endpoint_id'] ?? ''),
                    'source_key' => (string)($row['source_key'] ?? ''),
                    'source_path' => (string)($row['source_path'] ?? ''),
                    'capture_status' => (string)($row['capture_status'] ?? ''),
                    'captured_at' => $capturedAt,
                    'sample_batch_key' => $batchKey,
                    'run_id' => $runId,
                ];
            }

            if ($capturedAt !== '' && ($latestSampleTime === null || $capturedAt > $latestSampleTime)) {
                $latestSampleTime = $capturedAt;
                $latestSampleBatchKey = $batchKey;
            }
        }

        $sampledFieldCount = 0;
        foreach ($fields as &$field) {
            $fieldKey = (string)($field['field_key'] ?? '');
            $bucketKey = $this->ctripProfileFieldScopeKey($fieldKey, (string)($field['section'] ?? ''));
            $items = $samplesByKey[$bucketKey]['items'] ?? [];
            if (!$items) {
                continue;
            }

            $sampledFieldCount++;
            $field['latest_values'] = $items;
            $field['latest_sample'] = $items[0];
            $field['latest_value'] = implode(' / ', array_map(static fn(array $item): string => (string)$item['value'], $items));
            $field['latest_sample_note'] = '';
        }
        unset($field);

        return [
            'sampled_field_count' => $sampledFieldCount,
            'latest_sample_time' => $latestSampleTime,
            'latest_sample_batch_key' => $latestSampleBatchKey,
        ];
    }

    private function hydrateCtripProfileFieldSamplesFromOnlineDailyData(array &$samplesByKey, array $fields, ?string &$latestSampleTime, ?string &$latestSampleBatchKey, int $sampleLimit = 8): void
    {
        $fieldSpecs = [];
        $fieldScopeCountByMetric = [];
        foreach ($fields as $field) {
            $fieldKey = (string)($field['field_key'] ?? '');
            if ($fieldKey === '') {
                continue;
            }

            $scopeKey = $this->ctripProfileFieldScopeKey($fieldKey, (string)($field['section'] ?? ''));
            if ($scopeKey === '') {
                continue;
            }
            $normalizedFieldKey = strtolower(trim($fieldKey));
            $normalizedFieldKey = preg_replace('/[^a-z0-9_\-]+/', '_', $normalizedFieldKey) ?: $fieldKey;
            $fieldScopeCountByMetric[$normalizedFieldKey] = ($fieldScopeCountByMetric[$normalizedFieldKey] ?? 0) + 1;
            $sourceKeys = preg_split('/\s*,\s*/', (string)($field['source_keys'] ?? '')) ?: [];
            $fieldSpecs[$scopeKey] = [
                'field_key' => $fieldKey,
                'section' => strtolower(trim((string)($field['section'] ?? ''))),
                'field' => $field,
                'source' => $this->ctripProfileFieldSampleSource($field),
                'keys' => array_values(array_unique(array_filter(array_merge(
                    [$fieldKey],
                    $sourceKeys,
                    $this->onlineDailyDataSampleAliases($fieldKey)
                ), static fn($key): bool => trim((string)$key) !== ''))),
            ];
        }

        if (!$fieldSpecs) {
            return;
        }

        $sampleSources = array_values(array_unique(array_map(
            static fn(array $spec): string => (string)($spec['source'] ?? 'ctrip'),
            $fieldSpecs
        )));
        $sampleRowLimit = max(1000, min(5000, count($fieldSpecs) * max(1, $sampleLimit) * 3));

        try {
            $query = \think\facade\Db::name('online_daily_data')
                ->field('id,hotel_id,hotel_name,source,platform,compare_type,data_date,amount,quantity,book_order_num,comment_score,qunar_comment_score,raw_data,create_time,update_time,data_value,data_type,dimension,validation_status,list_exposure,detail_exposure,flow_rate,order_filling_num,order_submit_num,sync_task_id,source_trace_id')
                ->order('update_time', 'desc')
                ->order('create_time', 'desc')
                ->order('id', 'desc')
                ->limit($sampleRowLimit);
            if (count($sampleSources) === 1) {
                $query->where('source', $sampleSources[0]);
            } else {
                $query->whereIn('source', $sampleSources);
            }
            $rows = $query->select()->toArray();
        } catch (\Throwable $e) {
            \think\facade\Log::warning('读取 online_daily_data 字段样例失败: ' . $e->getMessage());
            return;
        }

        foreach ($rows as $row) {
            $rowPlatform = $this->normalizeCtripProfileTrafficPlatform((string)($row['platform'] ?? ''));
            $rowSource = $this->sourceForCtripProfileTrafficPlatform((string)($row['source'] ?? ''), $rowPlatform);
            $raw = json_decode((string)($row['raw_data'] ?? ''), true);
            $raw = is_array($raw) ? $raw : [];
            $rawMap = $this->flattenCtripProfileRawValues($raw);
            $rowIsRankFact = $this->isCtripOnlineDailyRankFactRow($row, $raw);
            $rowSection = $this->ctripProfileSampleSectionFromOnlineDailyRow($row, $raw);
            foreach ($fieldSpecs as $bucketKey => $spec) {
                if (count($samplesByKey[$bucketKey]['items'] ?? []) >= $sampleLimit) {
                    continue;
                }
                if ($rowSource !== (string)($spec['source'] ?? 'ctrip')) {
                    continue;
                }
                $fieldKey = (string)($spec['field_key'] ?? '');
                $fieldSection = strtolower(trim((string)($spec['section'] ?? '')));
                if ($this->shouldSkipCtripProfileOnlineDailySampleSection($fieldKey, $fieldSection, $rowSection, $fieldScopeCountByMetric)) {
                    continue;
                }
                if ($rowIsRankFact && !$this->isCtripProfileRankFieldKey((string)$fieldKey)) {
                    continue;
                }

                $fieldSample = $this->resolveCtripProfileOnlineDailyFieldSample($fieldKey, $row, $raw, $rawMap, $spec['keys']);
                if ($fieldSample === null) {
                    continue;
                }
                [$value, $matchedKey, $sourcePath] = $fieldSample;
                $value = $this->formatCtripProfileGenericSampleValue($value);
                if ($value === '') {
                    continue;
                }

                $samplesByKey[$bucketKey] ??= [
                    'items' => [],
                    'seen' => [],
                ];
                if (isset($samplesByKey[$bucketKey]['seen'][$value])) {
                    continue;
                }

                $samplesByKey[$bucketKey]['seen'][$value] = true;
                $capturedAt = (string)($row['update_time'] ?? $row['create_time'] ?? '');
                $syncTaskId = (int)($row['sync_task_id'] ?? 0);
                $batchKey = $syncTaskId > 0
                    ? 'sync_task:' . $syncTaskId
                    : ($capturedAt !== '' ? 'captured_at:' . $capturedAt : '');
                $samplesByKey[$bucketKey]['items'][] = [
                    'value' => $value,
                    'unit' => (string)($spec['field']['unit'] ?? ''),
                    'data_date' => (string)($row['data_date'] ?? ''),
                    'hotel_name' => (string)($row['hotel_name'] ?? ''),
                    'capture_section' => $rowSection !== '' ? $rowSection : (string)($row['data_type'] ?? 'online_daily_data'),
                    'endpoint_id' => 'online_daily_data',
                    'source_key' => $matchedKey,
                    'source_path' => $sourcePath,
                    'capture_status' => (string)($row['validation_status'] ?? ''),
                    'captured_at' => $capturedAt,
                    'created_at' => (string)($row['create_time'] ?? ''),
                    'sample_batch_key' => $batchKey,
                    'sync_task_id' => $syncTaskId,
                    'source_trace_id' => (string)($row['source_trace_id'] ?? ''),
                ];

                if ($capturedAt !== '' && ($latestSampleTime === null || $capturedAt > $latestSampleTime)) {
                    $latestSampleTime = $capturedAt;
                    $latestSampleBatchKey = $batchKey;
                }
            }
        }
    }

    private function shouldSkipCtripProfileOnlineDailySampleSection(string $fieldKey, string $fieldSection, string $rowSection, array $fieldScopeCountByMetric): bool
    {
        $normalizedFieldKey = strtolower(trim($fieldKey));
        $normalizedFieldKey = preg_replace('/[^a-z0-9_\-]+/', '_', $normalizedFieldKey) ?: $fieldKey;
        $fieldHasMultipleScopes = ($fieldScopeCountByMetric[$normalizedFieldKey] ?? 0) > 1;
        if (!$fieldHasMultipleScopes) {
            return false;
        }

        $fieldSection = strtolower(trim($fieldSection));
        $fieldSection = preg_replace('/[^a-z0-9_\-]+/', '_', $fieldSection) ?: '';
        $rowSection = strtolower(trim($rowSection));
        $rowSection = preg_replace('/[^a-z0-9_\-]+/', '_', $rowSection) ?: '';
        if ($rowSection === '') {
            return true;
        }

        return $fieldSection !== '' && $fieldSection !== 'unknown' && $rowSection !== $fieldSection;
    }

    private function isCtripOnlineDailyRankFactRow(array $row, array $raw): bool
    {
        $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
        if ($dataType === 'ranking') {
            return true;
        }

        $metricStatus = strtolower(trim((string)($raw['metric_status'] ?? '')));
        return $metricStatus === 'rank_fact';
    }

    private function isCtripProfileRankFieldKey(string $fieldKey): bool
    {
        $fieldKey = strtolower(trim($fieldKey));
        return $fieldKey === 'competition_rank'
            || $fieldKey === 'seq_rank'
            || str_ends_with($fieldKey, '_rank');
    }

    private function ctripProfileFieldSampleSource(array $field): string
    {
        $text = strtolower(implode(' ', [
            (string)($field['section'] ?? ''),
            (string)($field['source_keys'] ?? ''),
            (string)($field['transform_rule'] ?? ''),
            (string)($field['notes'] ?? ''),
        ]));
        if (str_contains($text, 'traffic_report') && str_contains($text, 'platform=qunar')) {
            return 'qunar';
        }

        return 'ctrip';
    }

    private function onlineDailyDataSampleAliases(string $fieldKey): array
    {
        $aliases = [
            'hotel_id' => ['hotel_id'],
            'hotel_name' => ['hotel_name'],
            'date' => ['data_date'],
            'last_visitor_total' => ['visitor_count'],
            'order_amount' => ['amount'],
            'room_nights' => ['quantity'],
            'order_count' => ['book_order_num'],
            'comment_score_summary' => ['comment_score', 'qunar_comment_score'],
            'comment_store_name' => ['hotel_name'],
            'comment_date' => ['data_date'],
            'comment_score' => ['comment_score'],
            'comment_count' => ['data_value'],
            'close_rate' => ['conversion_rate'],
            'page_views' => ['list_exposure'],
            'list_exposure' => ['list_exposure'],
            'competitor_list_exposure' => ['competitor_list_exposure'],
            'detail_visitor' => ['detail_exposure'],
            'competitor_detail_visitor' => ['competitor_detail_visitor'],
            'flow_rate' => ['flow_rate'],
            'flow_conversion_rate' => ['flow_rate'],
            'competitor_flow_rate' => ['competitor_flow_rate'],
            'order_page_visitor' => ['order_filling_num'],
            'competitor_order_page_visitor' => ['competitor_order_page_visitor'],
            'order_fill_rate' => ['order_fill_rate'],
            'competitor_order_fill_rate' => ['competitor_order_fill_rate'],
            'order_submit_user' => ['order_submit_num'],
            'competitor_order_submit_user' => ['competitor_order_submit_user'],
            'deal_rate' => ['deal_rate'],
            'competitor_deal_rate' => ['competitor_deal_rate'],
            'qunar_list_exposure' => ['list_exposure'],
            'qunar_competitor_list_exposure' => ['list_exposure'],
            'qunar_detail_visitor' => ['detail_exposure'],
            'qunar_competitor_detail_visitor' => ['detail_exposure'],
            'qunar_flow_rate' => ['flow_rate'],
            'qunar_competitor_flow_rate' => ['flow_rate'],
            'qunar_order_page_visitor' => ['order_filling_num'],
            'qunar_competitor_order_page_visitor' => ['order_filling_num'],
            'qunar_order_fill_rate' => ['order_fill_rate'],
            'qunar_competitor_order_fill_rate' => ['order_fill_rate'],
            'qunar_order_submit_user' => ['order_submit_num'],
            'qunar_competitor_order_submit_user' => ['order_submit_num'],
            'qunar_deal_rate' => ['deal_rate'],
            'qunar_competitor_deal_rate' => ['deal_rate'],
        ];

        return $aliases[$fieldKey] ?? [];
    }

    private function ctripProfilePrefersOnlineDailySamples(string $fieldKey): bool
    {
        return in_array($fieldKey, [
            'page_views',
            'list_exposure',
            'competitor_list_exposure',
            'detail_visitor',
            'competitor_detail_visitor',
            'flow_rate',
            'flow_conversion_rate',
            'competitor_flow_rate',
            'order_page_visitor',
            'competitor_order_page_visitor',
            'order_fill_rate',
            'competitor_order_fill_rate',
            'order_submit_user',
            'competitor_order_submit_user',
            'deal_rate',
            'competitor_deal_rate',
            'qunar_list_exposure',
            'qunar_competitor_list_exposure',
            'qunar_detail_visitor',
            'qunar_competitor_detail_visitor',
            'qunar_flow_rate',
            'qunar_competitor_flow_rate',
            'qunar_order_page_visitor',
            'qunar_competitor_order_page_visitor',
            'qunar_order_fill_rate',
            'qunar_competitor_order_fill_rate',
            'qunar_order_submit_user',
            'qunar_competitor_order_submit_user',
            'qunar_deal_rate',
            'qunar_competitor_deal_rate',
        ], true);
    }

    private function resolveCtripProfileTrafficDerivedSample(string $fieldKey, array $row, array $raw): ?array
    {
        $metricMap = [
            'page_views' => ['scope' => 'self', 'metric' => 'listExposure'],
            'list_exposure' => ['scope' => 'self', 'metric' => 'listExposure'],
            'detail_visitor' => ['scope' => 'self', 'metric' => 'detailExposure'],
            'flow_rate' => ['scope' => 'self', 'metric' => 'flowRate'],
            'flow_conversion_rate' => ['scope' => 'self', 'metric' => 'flowRate'],
            'order_page_visitor' => ['scope' => 'self', 'metric' => 'orderFillingNum'],
            'order_fill_rate' => ['scope' => 'self', 'metric' => 'orderFillRate'],
            'order_submit_user' => ['scope' => 'self', 'metric' => 'orderSubmitNum'],
            'deal_rate' => ['scope' => 'self', 'metric' => 'dealRate'],
            'competitor_list_exposure' => ['scope' => 'competitor', 'metric' => 'listExposure'],
            'competitor_detail_visitor' => ['scope' => 'competitor', 'metric' => 'detailExposure'],
            'competitor_flow_rate' => ['scope' => 'competitor', 'metric' => 'flowRate'],
            'competitor_order_page_visitor' => ['scope' => 'competitor', 'metric' => 'orderFillingNum'],
            'competitor_order_fill_rate' => ['scope' => 'competitor', 'metric' => 'orderFillRate'],
            'competitor_order_submit_user' => ['scope' => 'competitor', 'metric' => 'orderSubmitNum'],
            'competitor_deal_rate' => ['scope' => 'competitor', 'metric' => 'dealRate'],
            'qunar_list_exposure' => ['scope' => 'self', 'metric' => 'listExposure'],
            'qunar_competitor_list_exposure' => ['scope' => 'competitor', 'metric' => 'listExposure'],
            'qunar_detail_visitor' => ['scope' => 'self', 'metric' => 'detailExposure'],
            'qunar_competitor_detail_visitor' => ['scope' => 'competitor', 'metric' => 'detailExposure'],
            'qunar_flow_rate' => ['scope' => 'self', 'metric' => 'flowRate'],
            'qunar_competitor_flow_rate' => ['scope' => 'competitor', 'metric' => 'flowRate'],
            'qunar_order_page_visitor' => ['scope' => 'self', 'metric' => 'orderFillingNum'],
            'qunar_competitor_order_page_visitor' => ['scope' => 'competitor', 'metric' => 'orderFillingNum'],
            'qunar_order_fill_rate' => ['scope' => 'self', 'metric' => 'orderFillRate'],
            'qunar_competitor_order_fill_rate' => ['scope' => 'competitor', 'metric' => 'orderFillRate'],
            'qunar_order_submit_user' => ['scope' => 'self', 'metric' => 'orderSubmitNum'],
            'qunar_competitor_order_submit_user' => ['scope' => 'competitor', 'metric' => 'orderSubmitNum'],
            'qunar_deal_rate' => ['scope' => 'self', 'metric' => 'dealRate'],
            'qunar_competitor_deal_rate' => ['scope' => 'competitor', 'metric' => 'dealRate'],
        ];
        if (!isset($metricMap[$fieldKey])) {
            return null;
        }

        $trafficRows = [];
        $this->collectCtripProfileTrafficRows($raw, $trafficRows, 'raw_data');
        $target = $this->selectCtripProfileTrafficRow($trafficRows, $metricMap[$fieldKey]['scope']);
        $sourcePath = $target['path'] ?? ('online_daily_data#' . (string)($row['id'] ?? ''));
        $trafficRow = $target['row'] ?? [];

        if (
            !$trafficRow
            && $this->ctripProfileTrafficRowMatchesScope($row, $metricMap[$fieldKey]['scope'])
            && $this->ctripProfileOnlineDailyRowLooksLikeFlowTransform($row, $raw)
        ) {
            $trafficRow = [
                'listExposure' => $row['list_exposure'] ?? null,
                'detailExposure' => $row['detail_exposure'] ?? null,
                'flowRate' => $row['flow_rate'] ?? null,
                'orderFillingNum' => $row['order_filling_num'] ?? null,
                'orderSubmitNum' => $row['order_submit_num'] ?? null,
            ];
        }
        if (!$trafficRow) {
            return null;
        }

        $metric = $metricMap[$fieldKey]['metric'];
        $value = match ($metric) {
            'listExposure', 'detailExposure', 'orderFillingNum', 'orderSubmitNum' => $this->ctripProfileTrafficNumber($trafficRow, $metric),
            'flowRate' => $this->ctripProfileTrafficRate($trafficRow, 'detailExposure', 'listExposure', 'flowRate'),
            'orderFillRate' => $this->ctripProfileTrafficRate($trafficRow, 'orderFillingNum', 'detailExposure'),
            'dealRate' => $this->ctripProfileTrafficRate($trafficRow, 'orderSubmitNum', 'orderFillingNum'),
            default => null,
        };
        if ($value === null) {
            return null;
        }

        $sourceKey = match ($metric) {
            'flowRate' => 'detailExposure / listExposure',
            'orderFillRate' => 'orderFillingNum / detailExposure',
            'dealRate' => 'orderSubmitNum / orderFillingNum',
            default => $metric,
        };

        return [$value, $sourceKey, $sourcePath];
    }

    private function ctripProfileOnlineDailyRowLooksLikeFlowTransform(array $row, array $raw): bool
    {
        $parts = [
            (string)($row['dimension'] ?? ''),
            (string)($row['endpoint_id'] ?? ''),
            (string)($row['source_url'] ?? ''),
            (string)($raw['dimension'] ?? ''),
            (string)($raw['endpoint_id'] ?? ''),
            (string)($raw['_source_url'] ?? ''),
            (string)($raw['source_url'] ?? ''),
        ];
        if (is_array($raw['row'] ?? null)) {
            $parts[] = (string)($raw['row']['dimension'] ?? '');
            $parts[] = (string)($raw['row']['endpoint_id'] ?? '');
            $parts[] = (string)($raw['row']['_source_url'] ?? '');
            $parts[] = (string)($raw['row']['source_url'] ?? '');
        }

        $text = strtolower(implode(' ', array_filter($parts, static fn(string $value): bool => $value !== '')));
        return str_contains($text, 'flow_transform')
            || str_contains($text, 'queryflowtransformnewv1')
            || str_contains($text, 'queryflowtransfornewv1')
            || str_contains($text, 'queryflowtransfernewv1');
    }

    private function ctripProfileTrafficRowMatchesScope(array $row, string $scope): bool
    {
        $compareType = strtolower(trim((string)($row['compare_type'] ?? '')));
        $hotelId = trim((string)($row['hotel_id'] ?? ''));
        if ($scope === 'competitor') {
            return $compareType === 'competitor' || $compareType === 'competitor_avg' || $hotelId === '-1';
        }

        if ($compareType === 'competitor' || $compareType === 'competitor_avg' || $hotelId === '-1') {
            return false;
        }

        return $compareType === '' || $compareType === 'self' || $hotelId !== '';
    }

    private function collectCtripProfileTrafficRows($value, array &$rows, string $path, int $depth = 0): void
    {
        if ($depth > 8 || count($rows) > 200 || !is_array($value)) {
            return;
        }

        if ($this->looksLikeCtripProfileTrafficRow($value)) {
            $rows[] = [
                'row' => $value,
                'path' => $path,
            ];
            return;
        }

        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $nextPath = $path . '.' . (is_int($key) ? '[' . $key . ']' : (string)$key);
                $this->collectCtripProfileTrafficRows($child, $rows, $nextPath, $depth + 1);
            }
        }
    }

    private function looksLikeCtripProfileTrafficRow(array $value): bool
    {
        foreach (['listExposure', 'detailExposure', 'flowRate', 'orderFillingNum', 'orderSubmitNum'] as $key) {
            if (array_key_exists($key, $value)) {
                return true;
            }
        }

        return false;
    }

    private function selectCtripProfileTrafficRow(array $rows, string $scope): ?array
    {
        foreach ($rows as $item) {
            $hotelId = (string)($item['row']['hotelId'] ?? '');
            if ($scope === 'competitor' && $hotelId === '-1') {
                return $item;
            }
            if ($scope === 'self' && $hotelId !== '-1') {
                return $item;
            }
        }
        if ($scope === 'self') {
            foreach ($rows as $item) {
                if (!array_key_exists('hotelId', $item['row'])) {
                    return $item;
                }
            }
        }

        return null;
    }

    private function ctripProfileTrafficNumber(array $row, string $key): ?float
    {
        if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
            return null;
        }
        $value = str_replace(['%', ','], '', (string)$row[$key]);
        if (!is_numeric($value)) {
            return null;
        }

        return round((float)$value, 2);
    }

    private function ctripProfileTrafficRate(array $row, string $numeratorKey, string $denominatorKey, ?string $fallbackKey = null): ?string
    {
        $numerator = $this->ctripProfileTrafficNumber($row, $numeratorKey);
        $denominator = $this->ctripProfileTrafficNumber($row, $denominatorKey);
        if ($numerator !== null && $denominator !== null && $denominator > 0) {
            return sprintf('%.2F', ($numerator / $denominator) * 100);
        }

        if ($fallbackKey !== null) {
            $fallback = $this->ctripProfileTrafficNumber($row, $fallbackKey);
            if ($fallback !== null) {
                return sprintf('%.2F', $fallback);
            }
        }

        return null;
    }

    private function resolveCtripProfileOnlineDailyFieldSample(string $fieldKey, array $row, array $raw, array $rawMap, array $keys): ?array
    {
        $derivedSample = $this->resolveCtripProfileTrafficDerivedSample($fieldKey, $row, $raw);
        if ($derivedSample !== null) {
            return $derivedSample;
        }
        if ($this->ctripProfilePrefersOnlineDailySamples($fieldKey)) {
            return null;
        }

        $factSample = $this->resolveCtripProfileOnlineDailyFactSample($fieldKey, $raw, $keys);
        if ($factSample !== null) {
            return $factSample;
        }

        [$value, $matchedKey] = $this->resolveCtripProfileOnlineDailySampleValue($row, $rawMap, $keys);
        return [$value, $matchedKey, 'online_daily_data#' . (string)($row['id'] ?? '')];
    }

    private function resolveCtripProfileOnlineDailyFactSample(string $fieldKey, array $raw, array $keys): ?array
    {
        $facts = $this->extractCtripProfileOnlineDailyFacts($raw);
        if (!$facts) {
            return null;
        }

        $lookup = [];
        foreach (array_merge([$fieldKey], $keys) as $key) {
            $normalized = $this->normalizeCtripProfileSampleLookupKey($key);
            if ($normalized !== '') {
                $lookup[$normalized] = true;
            }
        }

        foreach ($facts as $fact) {
            if (!is_array($fact) || !$this->hasCtripProfileNonEmptySampleValue($fact['value'] ?? null)) {
                continue;
            }
            foreach (['source_key', 'source_path'] as $sourceField) {
                $candidate = trim((string)($fact[$sourceField] ?? ''));
                if ($candidate !== '' && isset($lookup[$this->normalizeCtripProfileSampleLookupKey($candidate)])) {
                    return [
                        $fact['value'],
                        $candidate,
                        trim((string)($fact['source_path'] ?? '')) ?: $candidate,
                    ];
                }
            }
        }

        $fieldLookupKey = $this->normalizeCtripProfileSampleLookupKey($fieldKey);
        foreach ($facts as $fact) {
            if (!is_array($fact) || !$this->hasCtripProfileNonEmptySampleValue($fact['value'] ?? null)) {
                continue;
            }
            $metricKey = $this->normalizeCtripProfileSampleLookupKey($fact['metric_key'] ?? '');
            if ($metricKey === $fieldLookupKey) {
                $sourceKey = trim((string)($fact['source_key'] ?? ''));
                return [
                    $fact['value'],
                    $sourceKey !== '' ? $sourceKey : (string)($fact['metric_key'] ?? $fieldKey),
                    trim((string)($fact['source_path'] ?? '')),
                ];
            }
        }

        return null;
    }

    private function extractCtripProfileOnlineDailyFacts(array $raw): array
    {
        $candidates = [
            $raw['facts'] ?? null,
            $raw['raw_data']['facts'] ?? null,
            $raw['row']['raw_data']['facts'] ?? null,
        ];
        $facts = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            foreach ($candidate as $fact) {
                if (is_array($fact)) {
                    $facts[] = $fact;
                }
            }
        }

        return $facts;
    }

    private function normalizeCtripProfileSampleLookupKey($value): string
    {
        return strtolower(trim((string)$value));
    }

    private function hasCtripProfileNonEmptySampleValue($value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            $text = trim($value);
            return $text !== '' && !in_array(strtolower($text), ['null', 'undefined', 'nan'], true);
        }

        return true;
    }

    private function resolveCtripProfileOnlineDailySampleValue(array $row, array $rawMap, array $keys): array
    {
        foreach ($keys as $key) {
            $key = (string)$key;
            if ($key !== '' && array_key_exists($key, $row)) {
                return [$row[$key], $key];
            }
        }

        foreach ($keys as $key) {
            $key = (string)$key;
            if ($key !== '' && array_key_exists($key, $rawMap)) {
                return [$rawMap[$key], $key];
            }
        }

        return [null, ''];
    }

    private function flattenCtripProfileRawValues(array $raw): array
    {
        $values = [];
        $this->collectCtripProfileRawValues($raw, $values, 0);
        return $values;
    }

    private function collectCtripProfileRawValues($value, array &$values, int $depth): void
    {
        if ($depth > 8 || count($values) > 2000 || !is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            if (is_string($key) && !array_key_exists($key, $values) && !$this->isAssociativeArray($child)) {
                $values[$key] = $child;
            }
            if (is_array($child)) {
                $this->collectCtripProfileRawValues($child, $values, $depth + 1);
            }
        }
    }

    private function isAssociativeArray($value): bool
    {
        return is_array($value) && array_keys($value) !== range(0, count($value) - 1);
    }

    private function formatCtripProfileFieldSampleValue($decimal, $text, $json): string
    {
        if ($decimal !== null && $decimal !== '') {
            $value = rtrim(rtrim(sprintf('%.4F', (float)$decimal), '0'), '.');
            return $value === '-0' ? '0' : $value;
        }

        if ($text !== null && trim((string)$text) !== '') {
            return $this->formatCtripProfileGenericSampleValue($text);
        }

        if ($json !== null && trim((string)$json) !== '') {
            $decoded = json_decode((string)$json, true);
            if ($decoded === null) {
                return '';
            }
            $value = json_last_error() === JSON_ERROR_NONE
                ? json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string)$json;
            return $this->formatCtripProfileGenericSampleValue($value);
        }

        return '';
    }

    private function formatCtripProfileGenericSampleValue($value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif (is_float($value)) {
            $value = rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
        } else {
            $value = (string)$value;
        }

        $value = trim($value);
        if ($value === '' || in_array(strtolower($value), ['null', 'undefined', 'nan'], true)) {
            return '';
        }

        return $this->compactCtripProfileFieldSampleText($value);
    }

    private function compactCtripProfileFieldSampleText(string $value): string
    {
        $value = trim((string)preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value, 'UTF-8') > 160 ? mb_substr($value, 0, 160, 'UTF-8') . '...' : $value;
        }

        return strlen($value) > 240 ? substr($value, 0, 240) . '...' : $value;
    }

    private function defaultCtripProfileFieldMeta(string $fieldKey): array
    {
        $flowMeta = CtripProfileFieldMetaService::flowTransform($fieldKey);
        if ($flowMeta !== []) {
            $flowSourceKeys = [
                'list_exposure' => 'listExposure',
                'competitor_list_exposure' => 'listExposure',
                'detail_visitor' => 'detailExposure',
                'competitor_detail_visitor' => 'detailExposure',
                'flow_rate' => 'flowRate',
                'competitor_flow_rate' => 'flowRate',
                'order_page_visitor' => 'orderFillingNum',
                'competitor_order_page_visitor' => 'orderFillingNum',
                'order_fill_rate' => 'orderFillingNum / detailExposure',
                'competitor_order_fill_rate' => 'orderFillingNum / detailExposure',
                'order_submit_user' => 'orderSubmitNum',
                'competitor_order_submit_user' => 'orderSubmitNum',
                'deal_rate' => 'orderSubmitNum / orderFillingNum',
                'competitor_deal_rate' => 'orderSubmitNum / orderFillingNum',
            ];
            return array_merge([
                'source_interface' => 'queryFlowTransforNewV1',
                'source_keys' => $flowSourceKeys[$fieldKey] ?? '',
                'status' => 'confirmed',
                'enabled' => true,
                'notes' => '携程流量漏斗固定接口；按 hotelId/masterHotelId 区分本店与竞争圈平均。',
            ], $flowMeta);
        }

        $weeklyMeta = CtripProfileFieldMetaService::weekly($fieldKey);
        if ($weeklyMeta !== []) {
            return $weeklyMeta;
        }

        $competitionProfileMeta = CtripProfileFieldMetaService::competitionProfile($fieldKey);
        if ($competitionProfileMeta !== []) {
            return $competitionProfileMeta;
        }

        return CtripProfileFieldMetaService::base($fieldKey);
    }

    private function defaultCtripProfileCaptureFields(): array
    {
        $defaults = CtripProfileFieldMetaService::defaultCaptureFieldRows();

        $fields = [];
        foreach ($defaults as $index => $row) {
            [$key, $name, $section, $dataType, $sourceInterface, $sourceKeys, $valueType, $unit, $status, $rule, $enabled, $notes] = array_pad($row, 12, null);
            $id = 'profile_field_' . $key;
            $fieldConfig = array_merge([
                'id' => $id,
                'field_key' => $key,
                'field_name' => $name,
                'section' => $section,
                'data_type' => $dataType,
                'source_interface' => $sourceInterface,
                'source_keys' => $sourceKeys,
                'value_type' => $valueType,
                'unit' => $unit,
                'status' => $status,
                'enabled' => $enabled ?? true,
                'transform_rule' => $rule,
                'notes' => $notes ?? '携程 OTA 渠道口径',
                'sort_order' => ($index + 1) * 10,
            ], $this->defaultCtripProfileFieldMeta((string)$key));
            $fields[$id] = $this->normalizeCtripProfileCaptureField($fieldConfig);
        }

        return $this->filterCtripProfileKeyFields($fields);
    }

    /**
     * 保存携程配置
     */

}
