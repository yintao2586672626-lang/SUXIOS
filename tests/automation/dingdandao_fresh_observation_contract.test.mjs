import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const captureService = readFileSync(
  'app/service/DingdandaoOperatingTargetCaptureService.php',
  'utf8',
);
const cloudRunner = readFileSync(
  'scripts/run_dingdandao_cloud_collection.php',
  'utf8',
);
const queueService = readFileSync(
  'app/service/CloudThreeSourceCollectionQueueService.php',
  'utf8',
);

test('verified capture reuse remains the default and is actor scoped', () => {
  assert.match(captureService, /bool \$freshObservation = false/);
  assert.match(captureService, /if \(\$verifiedOnly && !\$freshObservation\)/);
  const reuseLookup = captureService.slice(
    captureService.indexOf('if ($verifiedOnly && !$freshObservation)'),
    captureService.indexOf('$captureId = (int)Db::name'),
  );
  assert.match(reuseLookup, /where\('tenant_id', \$tenantId\)/);
  assert.match(reuseLookup, /where\('hotel_id', \$hotelId\)/);
  assert.match(reuseLookup, /where\('captured_by', \$userId\)/);
  assert.match(reuseLookup, /where\('business_date', \$businessDate\)/);
});

test('latest PMS readback is strictly scoped to the execution actor', () => {
  const latestForActor = captureService.slice(
    captureService.indexOf('public function latestForActor('),
    captureService.indexOf('public function history('),
  );
  assert.match(latestForActor, /where\('tenant_id', \$tenantId\)/);
  assert.match(latestForActor, /where\('hotel_id', \$hotelId\)/);
  assert.match(latestForActor, /where\('captured_by', \$actorId\)/);
  assert.match(latestForActor, /where\('business_date', \$businessDate\)/);
  assert.match(latestForActor, /order\('captured_at', 'desc'\)/);
  assert.match(latestForActor, /order\('id', 'desc'\)/);
  assert.match(captureService, /'captured_by' => \(int\)\(\$row\['captured_by'\]/);
});

test('cloud PMS runner proves the saved row belongs to this fresh round', () => {
  assert.match(cloudRunner, /'fresh-observation'/);
  assert.match(cloudRunner, /\$freshObservation = array_key_exists\('fresh-observation', \$options\)/);
  assert.match(cloudRunner, /->save\([\s\S]*\$expectedProviderHotelId,[\s\n]*\$freshObservation[\s\n]*\)/);
  assert.match(cloudRunner, /->latestForActor\([\s\S]*\$ownerUserId,[\s\n]*\$targetDate/);
  assert.match(cloudRunner, /\$actorReadback\['captured_by'\][\s\S]*\$ownerUserId/);
  assert.match(cloudRunner, /\$actorReadback\['captured_at'\][\s\S]*\$roundCapturedAt/);
  assert.match(cloudRunner, /'fresh_observation' => \$freshObservation/);
});

test('serial queue requests fresh Dingdandao evidence explicitly', () => {
  const pmsCommand = queueService.slice(
    queueService.indexOf('$pmsCommand = ['),
    queueService.indexOf('$commands = ['),
  );
  assert.match(pmsCommand, /PROVIDER_DINGDANDAO/);
  assert.match(pmsCommand, /\$pmsCommand\[\] = '--fresh-observation'/);
});
