<?php
declare(strict_types=1);

namespace app\domain\Ota;

final class OtaDomain
{
    public const CTRIP = 'ctrip';
    public const MEITUAN = 'meituan';
    public const CREDENTIAL = 'credential';
    public const PROFILE = 'profile';
    public const SYNC = 'sync';

    private function __construct()
    {
    }
}
