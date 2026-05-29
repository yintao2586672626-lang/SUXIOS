<?php
declare(strict_types=1);

namespace app\trace;

use think\App;
use think\Response;

class NullTrace
{
    public function output(App $app, Response $response, array $log = [])
    {
        return false;
    }
}
