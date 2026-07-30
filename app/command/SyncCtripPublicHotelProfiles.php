<?php
declare(strict_types=1);

namespace app\command;

use app\service\CtripPublicHotelProfileService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use think\facade\Db;

final class SyncCtripPublicHotelProfiles extends Command
{
    protected function configure()
    {
        $this->setName('online-data:sync-ctrip-public-profiles')
            ->addOption('hotel-id', null, Option::VALUE_REQUIRED, 'Only sync one system hotel ID')
            ->addOption('scope', null, Option::VALUE_REQUIRED, 'own, competitors or all; default all')
            ->addOption('hotel-limit', null, Option::VALUE_REQUIRED, 'Maximum enabled hotels per run, 1-50; default 10')
            ->addOption('profile-limit', null, Option::VALUE_REQUIRED, 'Maximum profiles per hotel, 1-30; default 10')
            ->addOption('force', null, Option::VALUE_NONE, 'Ignore the seven-day public profile cache')
            ->setDescription('Sync static Ctrip public hotel profiles for bound hotels and competition-circle hotels');
    }

    protected function execute(Input $input, Output $output)
    {
        $hotelIdOptionProvided = $input->hasParameterOption('--hotel-id');
        $hotelId = $this->positiveInteger($input->getOption('hotel-id'));
        if ($hotelIdOptionProvided && $hotelId <= 0) {
            $output->writeln('hotel-id must be a positive integer.');
            return 1;
        }
        $scope = strtolower(trim((string)$input->getOption('scope')));
        $scope = $scope !== '' ? $scope : 'all';
        if (!in_array($scope, ['own', 'competitors', 'all'], true)) {
            $output->writeln('scope must be own, competitors or all.');
            return 1;
        }
        $hotelLimit = $this->boundedInteger($input->getOption('hotel-limit'), 10, 1, 50);
        $profileLimit = $this->boundedInteger($input->getOption('profile-limit'), 10, 1, 30);
        $force = (bool)$input->getOption('force');

        $query = Db::name('hotels')->where('status', 1)->order('id', 'asc');
        if ($hotelId > 0) {
            $query->where('id', $hotelId);
        }
        $hotels = $query->limit($hotelLimit)->select()->toArray();
        if ($hotels === []) {
            $output->writeln('No enabled hotel matched the requested scope.');
            return 0;
        }

        $service = new CtripPublicHotelProfileService();
        $totals = [
            'hotels' => 0,
            'binding_missing' => 0,
            'requested' => 0,
            'fetched' => 0,
            'cached' => 0,
            'saved' => 0,
            'partial' => 0,
            'failed' => 0,
        ];
        foreach ($hotels as $hotel) {
            $systemHotelId = (int)($hotel['id'] ?? 0);
            if ($systemHotelId <= 0) {
                continue;
            }
            $totals['hotels']++;
            try {
                $result = $service->syncForHotel($systemHotelId, $scope, $profileLimit, $force);
            } catch (\Throwable $exception) {
                $totals['failed']++;
                $output->writeln("Hotel {$systemHotelId}: collection_failed (" . get_debug_type($exception) . ')');
                continue;
            }
            if (($result['status'] ?? '') === 'binding_missing') {
                $totals['binding_missing']++;
                $output->writeln("Hotel {$systemHotelId}: binding_missing");
                continue;
            }
            $totals['requested'] += (int)($result['requested_count'] ?? 0);
            $totals['fetched'] += (int)($result['fetched_count'] ?? 0);
            $totals['cached'] += (int)($result['cached_count'] ?? 0);
            $totals['saved'] += (int)($result['saved_count'] ?? 0);
            $totals['partial'] += (int)($result['partial_count'] ?? 0);
            $totals['failed'] += (int)($result['failed_count'] ?? 0);
            $output->writeln("Hotel {$systemHotelId}: status=" . (string)($result['status'] ?? 'unknown')
                . ', requested=' . (int)($result['requested_count'] ?? 0)
                . ', saved=' . (int)($result['saved_count'] ?? 0)
                . ', cached=' . (int)($result['cached_count'] ?? 0)
                . ', partial=' . (int)($result['partial_count'] ?? 0)
                . ', failed=' . (int)($result['failed_count'] ?? 0));
        }

        $output->writeln('Summary: ' . json_encode($totals, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $output->writeln('Boundary: public static profile only; no dynamic price, dated inventory, orders or traffic. Room count is not date-specific sellable inventory.');

        return $totals['failed'] > 0 && $totals['saved'] === 0 && $totals['cached'] === 0 ? 1 : 0;
    }

    private function positiveInteger(mixed $value): int
    {
        $value = trim((string)$value);
        return preg_match('/^[1-9][0-9]*$/D', $value) === 1 ? (int)$value : 0;
    }

    private function boundedInteger(mixed $value, int $default, int $minimum, int $maximum): int
    {
        $number = is_numeric($value) ? (int)$value : $default;
        return max($minimum, min($maximum, $number > 0 ? $number : $default));
    }
}
