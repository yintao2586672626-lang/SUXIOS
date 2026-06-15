<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\OperationLog;
use think\Response;

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

}
