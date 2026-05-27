<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class AiEvaluationCase extends Model
{
    protected $name = 'ai_evaluation_cases';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    protected $json = ['input_json', 'expected_json', 'metric_json'];
    protected $jsonAssoc = true;

    protected $type = [
        'id' => 'integer',
        'created_by' => 'integer',
    ];
}
