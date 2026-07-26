import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

test('Dingdandao timer runs one fail-closed test-group pipeline and stays disabled by default', async () => {
  const [
    pipeline,
    service,
    timer,
    installer,
    verifier,
  ] = await Promise.all([
    readFile(new URL(
      '../../scripts/run_dingdandao_notification_pipeline.php',
      import.meta.url,
    ), 'utf8'),
    readFile(new URL(
      '../../deploy/systemd/suxios-dingdandao-notification-pipeline.service',
      import.meta.url,
    ), 'utf8'),
    readFile(new URL(
      '../../deploy/systemd/suxios-dingdandao-notification-pipeline.timer',
      import.meta.url,
    ), 'utf8'),
    readFile(new URL(
      '../../deploy/systemd/install_dingdandao_notification_pipeline.sh',
      import.meta.url,
    ), 'utf8'),
    readFile(new URL(
      '../../deploy/systemd/verify_dingdandao_notification_pipeline.php',
      import.meta.url,
    ), 'utf8'),
  ]);

  const previewOffset = pipeline.indexOf("'--preview'");
  const collectionOffset = pipeline.indexOf(
    "'/scripts/run_dingdandao_cloud_collection.php'",
  );
  const dispatchOffset = pipeline.indexOf("'--dispatch'");
  const pipelineRunStartOffset = pipeline.indexOf('$runs->start');
  assert.ok(previewOffset > 0);
  assert.ok(pipelineRunStartOffset > previewOffset);
  assert.ok(collectionOffset > previewOffset);
  assert.ok(dispatchOffset > collectionOffset);
  assert.match(pipeline, /report_send_eligible/);
  assert.match(pipeline, /saved_synced_and_report_ready/);
  assert.match(pipeline, /ManualNotificationPipelineRunService/);
  assert.match(pipeline, /message_sent' => \$sentCount > 0/);
  assert.doesNotMatch(pipeline, /file_get_contents\([^)]*webhook/i);

  assert.match(service, /^EnvironmentFile=\/etc\/suxios\/dingdandao-notification-pipeline\.env$/m);
  assert.match(service, /^LoadCredential=control-token:\/etc\/suxios-cloud-browser\/control-token$/m);
  assert.match(service, /run_dingdandao_notification_pipeline\.php/);
  assert.match(service, /verify_manual_notification_test_dispatch\.php/);
  assert.match(service, /RuntimeDirectory=suxios-dingdandao-pipeline suxios-dingdandao-collection/);
  assert.doesNotMatch(service, /ExecStart=.*manual-notification:schedule --dispatch/);

  assert.match(timer, /^OnCalendar=\*-\*-\* \*:\*:00$/m);
  assert.match(timer, /^Persistent=false$/m);
  assert.match(timer, /^WantedBy=timers\.target$/m);

  assert.match(installer, /^INSTALL=0$/m);
  assert.match(installer, /^ENABLE_TEST_DISPATCH=0$/m);
  assert.match(installer, /--enable-test-dispatch requires --install/);
  assert.match(installer, /legacy standalone dispatch timer is active/);
  assert.match(installer, /systemd-analyze verify/);
  assert.match(installer, /INSTALLED_DISABLED/);
  assert.match(installer, /--require-enabled-schedule/);
  assert.doesNotMatch(installer, /cat "\$CONTROL_TOKEN_FILE"/);

  assert.match(verifier, /validateDingdandaoCollectionProfile/);
  assert.match(verifier, /ManualNotificationTestTargetService/);
  assert.match(verifier, /notification_scope/);
  assert.match(verifier, /eligible_saved_schedule_count/);
  assert.match(verifier, /'webhook_read' => false/);
  assert.match(verifier, /'message_sent' => false/);
});
