<?php
declare(strict_types=1);

namespace app\service;

/**
 * Dependency-free identity shared by revenue facts and operation provenance.
 */
final class RevenueCockpitActionContract
{
    public const VERSION = 'revenue_cockpit_operation_action.v1';
    public const SOURCE_MODULE = 'revenue_cockpit_action';

    private function __construct()
    {
    }
}
