<?php
declare(strict_types=1);

namespace app\controller;

use app\model\HotelFieldTemplate as TemplateModel;
use app\model\HotelFieldTemplateItem;
use app\model\OperationLog;
use think\Response;

class HotelFieldTemplate extends Base
{
    private function checkPermission(): void
    {
        if (!$this->currentUser) {
            abort(401, '未登录');
        }
        // 非超级管理员必须有酒店关联
        $this->requireHotel();
    }
    
    private function checkSuperAdmin(): void
    {
        $this->checkPermission();
        if (!$this->currentUser->isSuperAdmin()) {
            abort(403, '需要超级管理员权限');
        }
    }
    
    public function index(): Response
    {
        $this->checkPermission();
        $hotelId = $this->request->param('hotel_id');
        $flat = $this->request->param('flat', 0);
        
        $query = TemplateModel::with(['items', 'hotel']);
        if ($hotelId) {
            $query->where('hotel_id', $hotelId);
        }
        
        $list = $query->order('id', 'desc')->select();
        
        // 扁平化输出（每个item一行）
        if ($flat) {
            $flatList = [];
            foreach ($list as $template) {
                $hotelName = $template->hotel ? $template->hotel->name : '';
                foreach ($template->items as $item) {
                    $rowRange = '';
                    if ($item->row_start !== null && $item->row_end !== null) {
                        $rowRange = $item->row_start . '-' . $item->row_end;
                    } elseif ($item->row_start !== null) {
                        $rowRange = $item->row_start . '+';
                    } elseif ($item->row_end !== null) {
                        $rowRange = '1-' . $item->row_end;
                    }
                    
                    $flatList[] = [
                        'id' => $item->id,
                        'template_id' => $template->id,
                        'hotel_id' => $template->hotel_id,
                        'hotel_name' => $hotelName,
                        'template_name' => $template->template_name,
                        'excel_item_name' => $item->excel_item_name,
                        'system_field' => $item->system_field,
                        'field_type' => $item->field_type,
                        'row_start' => $item->row_start,
                        'row_end' => $item->row_end,
                        'row_range' => $rowRange,
                        'value_column' => $item->value_column,
                        'category' => $item->category,
                        'merge_rule' => $item->merge_rule,
                        'is_active' => $item->is_active,
                    ];
                }
            }
            return $this->success($flatList);
        }
        
        // 嵌套输出
        $list->each(function($item) {
            $item->hotel_name = $item->hotel ? $item->hotel->name : '';
            return $item;
        });
        return $this->success($list);
    }
    
    public function read(int $id): Response
    {
        $this->checkPermission();
        $template = TemplateModel::with(['items', 'hotel'])->find($id);
        if (!$template) {
            return $this->error('模板不存在');
        }
        
        // 添加hotel_name和格式化行号
        $template->hotel_name = $template->hotel ? $template->hotel->name : '';
        $items = [];
        foreach ($template->items as $item) {
            $rowRange = '';
            if ($item->row_start !== null && $item->row_end !== null) {
                $rowRange = $item->row_start . '-' . $item->row_end;
            } elseif ($item->row_start !== null) {
                $rowRange = $item->row_start . '+';
            } elseif ($item->row_end !== null) {
                $rowRange = '1-' . $item->row_end;
            }
            $itemArr = $item->toArray();
            $itemArr['row_range'] = $rowRange;
            $items[] = $itemArr;
        }
        $template->items = $items;
        
        return $this->success($template);
    }
    
    public function create(): Response
    {
        $this->checkSuperAdmin();
        
        $data = $this->request->post();
        $hotelId = (int)$data['hotel_id'];
        $templateName = $data['template_name'] ?? '默认模板';
        
        if (TemplateModel::where('hotel_id', $hotelId)->where('template_name', $templateName)->find()) {
            return $this->error('该门店已存在同名模板');
        }
        
        $template = new TemplateModel();
        $template->hotel_id = $hotelId;
        $template->template_name = $templateName;
        $template->is_default = $data['is_default'] ?? 1;
        $template->save();
        
        // 保存明细
        if (!empty($data['items']) && is_array($data['items'])) {
            $this->saveItems($template->id, $data['items']);
        }
        
        OperationLog::record('field_template', 'create', "创建字段映射模板: {$templateName}", $this->currentUser->id);
        
        $template = TemplateModel::with(['items'])->find($template->id);
        return $this->success($template, '创建成功');
    }
    
    public function update(int $id): Response
    {
        $this->checkSuperAdmin();
        
        $template = TemplateModel::find($id);
        if (!$template) {
            return $this->error('模板不存在');
        }
        
        $data = $this->request->put();
        
        if (isset($data['template_name'])) {
            $template->template_name = $data['template_name'];
        }
        if (isset($data['is_default'])) {
            $template->is_default = $data['is_default'];
        }
        $template->save();
        
        // 更新明细
        if (isset($data['items']) && is_array($data['items'])) {
            HotelFieldTemplateItem::where('template_id', $id)->delete();
            $this->saveItems($id, $data['items']);
        }
        
        OperationLog::record('field_template', 'update', "更新字段映射模板: {$template->template_name}", $this->currentUser->id);
        
        $template = TemplateModel::with(['items'])->find($template->id);
        return $this->success($template, '更新成功');
    }
    
    public function delete(int $id): Response
    {
        $this->checkSuperAdmin();
        
        $template = TemplateModel::find($id);
        if (!$template) {
            return $this->error('模板不存在');
        }
        
        HotelFieldTemplateItem::where('template_id', $id)->delete();
        $name = $template->template_name;
        $template->delete();
        
        OperationLog::record('field_template', 'delete', "删除字段映射模板: {$name}", $this->currentUser->id);
        return $this->success(null, '删除成功');
    }
    
    public function items(int $id): Response
    {
        $this->checkPermission();
        $items = HotelFieldTemplateItem::getItemsByTemplate($id);
        return $this->success($items);
    }
    
    private function saveItems(int $templateId, array $items): void
    {
        $sort = 0;
        foreach ($items as $item) {
            if (empty($item['excel_item_name']) || empty($item['system_field'])) {
                continue;
            }
            $i = new HotelFieldTemplateItem();
            $i->template_id = $templateId;
            $i->excel_item_name = $item['excel_item_name'];
            $i->system_field = $item['system_field'];
            $i->field_type = $item['field_type'] ?? 'number';
            $i->row_start = $item['row_start'] ?? null;
            $i->row_end = $item['row_end'] ?? null;
            $i->value_column = $item['value_column'] ?? 'E';
            $i->category = $item['category'] ?? '';
            $i->merge_rule = $item['merge_rule'] ?? 'sum';
            $i->sort_order = $sort++;
            $i->is_active = $item['is_active'] ?? 1;
            $i->save();
        }
    }
}
