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
    collector,
    pipelineRunService,
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
    readFile(new URL(
      '../../scripts/run_dingdandao_cloud_collection.php',
      import.meta.url,
    ), 'utf8'),
    readFile(new URL(
      '../../app/service/ManualNotificationPipelineRunService.php',
      import.meta.url,
    ), 'utf8'),
  ]);

  const staleReconcileOffset = pipeline.indexOf(
    'markStaleSendingAsOutcomeUnknown',
  );
  const previewOffset = pipeline.indexOf("'--preview'");
  const collectionOffset = pipeline.indexOf(
    "'/scripts/run_dingdandao_cloud_collection.php'",
  );
  const dispatchOffset = pipeline.indexOf("'--dispatch'");
  const pipelineRunStartOffset = pipeline.lastIndexOf('$runs->start');
  assert.ok(previewOffset > 0);
  assert.ok(staleReconcileOffset > 0);
  assert.ok(staleReconcileOffset < previewOffset);
  assert.ok(pipelineRunStartOffset > previewOffset);
  assert.ok(collectionOffset > previewOffset);
  assert.ok(dispatchOffset > collectionOffset);
  assert.match(pipeline, /report_send_eligible/);
  assert.match(pipeline, /saved_synced_and_report_ready/);
  assert.match(pipeline, /ManualNotificationPipelineRunService/);
  assert.match(pipeline, /ManualNotificationDispatchLedgerService/);
  assert.match(pipeline, /pipeline_stale_sending_outcome_unknown/);
  assert.match(pipeline, /stale_sending_outcome_unknown_count/);
  assert.match(pipelineRunService, /stale_sending_outcome_unknown_count/);
  assert.equal(
    [...pipeline.matchAll(/\$observedAtArgument,/g)].length,
    2,
    'preview and dispatch must receive the same observed-at argument',
  );
  assert.match(pipeline, /pipelineDispatchClosureReason/);
  assert.match(pipeline, /pipeline_dispatch_zero_send_unverified/);
  assert.match(
    pipeline,
    /\$status === 'skipped'[\s\S]*\$result\['existing_status'\] \?\? ''\) === 'sent'/,
  );
  assert.doesNotMatch(pipeline, /dispatch_idempotent_noop/);
  assert.match(pipeline, /message_sent' => \$sentCount > 0/);
  assert.doesNotMatch(pipeline, /file_get_contents\([^)]*webhook/i);

  assert.match(service, /^EnvironmentFile=\/etc\/suxios\/dingdandao-notification-pipeline\.env$/m);
  assert.match(service, /^LoadCredential=control-token:\/etc\/suxios-cloud-browser\/control-token$/m);
  assert.match(service, /run_dingdandao_notification_pipeline\.php/);
  assert.match(service, /verify_manual_notification_test_dispatch\.php/);
  assert.match(service, /RuntimeDirectory=suxios-dingdandao-pipeline suxios-dingdandao-collection/);
  assert.doesNotMatch(service, /ExecStart=.*manual-notification:schedule --dispatch/);
  assert.doesNotMatch(service, /^\[Install\]$/m);
  assert.doesNotMatch(service, /^WantedBy=multi-user\.target$/m);
  assert.match(
    collector,
    /\/run\/credentials\/suxios-dingdandao-notification-pipeline\.service\/control-token/,
  );

  assert.match(timer, /^OnCalendar=\*-\*-\* \*:\*:00$/m);
  assert.match(timer, /^Persistent=false$/m);
  assert.match(timer, /^WantedBy=timers\.target$/m);

  assert.match(installer, /^INSTALL=0$/m);
  assert.match(installer, /^ENABLE_TEST_DISPATCH=0$/m);
  assert.match(installer, /--enable-test-dispatch requires --install/);
  assert.match(installer, /legacy standalone dispatch timer is active/);
  assert.match(installer, /STANDALONE_COLLECTION_TIMER_NAME/);
  assert.match(installer, /STANDALONE_COLLECTION_SERVICE_NAME/);
  assert.match(installer, /LEGACY_SERVICE_NAME/);
  assert.match(installer, /assert_conflicting_units_disabled_and_inactive/);
  assert.equal(
    [...installer.matchAll(/^\s*assert_conflicting_units_disabled_and_inactive$/gm)].length,
    2,
    'conflicting units are checked before readiness work and immediately before enable',
  );
  assert.match(installer, /must be disabled/);
  assert.match(installer, /must be inactive/);
  assert.match(installer, /disable_pipeline_service_autostart/);
  assert.equal(
    [...installer.matchAll(/^\s*assert_pipeline_service_timer_only$/gm)].length,
    2,
    'direct service boot enablement is rejected before and after unit installation',
  );
  assert.match(installer, /service_trigger=timer_only/);
  assert.match(installer, /systemd-analyze verify/);
  assert.match(installer, /INSTALLED_DISABLED/);
  assert.match(installer, /--require-enabled-schedule/);
  assert.match(installer, /--require-enable-readiness/);
  assert.doesNotMatch(installer, /cat "\$CONTROL_TOKEN_FILE"/);

  assert.match(verifier, /validateDingdandaoCollectionProfile/);
  assert.match(verifier, /ManualNotificationTestTargetService/);
  assert.match(verifier, /notification_scope/);
  assert.match(verifier, /eligible_saved_schedule_count/);
  assert.match(verifier, /bindingBootstrapScope/);
  assert.match(verifier, /pipeline_enable_readiness_requires_enabled_schedule/);
  assert.match(verifier, /dingdandao_hotel_bindings/);
  assert.match(verifier, /OperatingTargetNotificationPayloadService/);
  assert.match(verifier, /prior_sent_attempt_verified/);
  assert.match(verifier, /manual_notification_dispatch_attempts attempt/);
  assert.match(verifier, /pipelineVerifyGatewayProfileLeaseContract/);
  assert.match(verifier, /dingdandao_profile_lease\.v1/);
  assert.match(verifier, /pipeline_gateway_profile_lease_contract_unavailable/);
  assert.match(verifier, /'gateway_profile_lease_ready' => \$gatewayProfileLeaseReady/);
  assert.match(verifier, /runtime_collection_fail_closed/);
  assert.match(verifier, /'live_session_verified' => false/);
  assert.match(verifier, /'webhook_read' => false/);
  assert.match(verifier, /'secret_material_read' => false/);
  assert.match(verifier, /'message_sent' => false/);
  assert.doesNotMatch(verifier, /value\(['"]webhook['"]\)/);
  assert.doesNotMatch(verifier, /WechatRobotWebhookSecret|revealWebhook/);
});
