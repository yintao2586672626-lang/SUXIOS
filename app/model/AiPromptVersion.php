<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class AiPromptVersion extends Model
{
    protected $name = 'ai_prompt_versions';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    protected $type = [
        'id' => 'integer',
        'created_by' => 'integer',
    ];
}
