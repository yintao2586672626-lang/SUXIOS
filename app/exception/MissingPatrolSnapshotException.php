<?php
declare(strict_types=1);

namespace app\exception;

final class MissingPatrolSnapshotException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('尚无可用巡检快照，请先为当前门店生成巡检结果，再查看运营效果。');
    }
}
