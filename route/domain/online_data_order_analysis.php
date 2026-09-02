<?php
declare(strict_types=1);

use think\facade\Route;

Route::get('/ctrip/order-analysis', 'ota.SyncController/ctripOrderAnalysis');
Route::get('/dual-ota/order-analysis', 'OnlineData/dualOtaOrderAnalysis');
