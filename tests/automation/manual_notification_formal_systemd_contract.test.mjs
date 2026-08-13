import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8');

test('formal WeCom timer executes only the formal dispatch mode', () => {
  const service = read('deploy/systemd/suxios-manual-notification-formal-dispatch.service');
  const timer = read('deploy/systemd/suxios-manual-notification-formal-dispatch.timer');

  assert.match(service, /ConditionPathExists=@SUXIOS_RELEASE_ROOT@\/think/);
  assert.match(service, /WorkingDirectory=@SUXIOS_RELEASE_ROOT@/);
  assert.doesNotMatch(service, /\/var\/www\/suxios\/current/);
  assert.match(
    service,
    /manual-notification:schedule --dispatch --mode=formal --limit=100/
  );
  assert.doesNotMatch(service, /--mode=test|--hotel-id=80|--robot-id=1/);
  assert.match(timer, /OnCalendar=\*-\*-\* \*:\*:00/);
  assert.match(timer, /Persistent=true/);
});

test('formal installer is disabled by default and requires explicit enablement', () => {
  const installer = read('deploy/systemd/install_manual_notification_formal_dispatch.sh');

  assert.match(installer, /--enable-formal-dispatch/);
  assert.match(installer, /SERVICE_RENDERED="\$\(mktemp\)"/);
  assert.match(installer, /str_replace\(\$placeholder, \$releaseRoot, \$template\)/);
  assert.match(installer, /ConditionPathExists=\$RELEASE_ROOT\/think/);
  assert.match(installer, /WorkingDirectory=\$RELEASE_ROOT/);
  assert.match(installer, /"\$SERVICE_RENDERED"\s*\\\s*\n\s*"\$SYSTEMD_DIR\/\$SERVICE_NAME"/);
  assert.match(installer, /verify_manual_notification_formal_dispatch\.php --require-enabled/);
  assert.match(installer, /systemctl enable --now "\$TIMER_NAME"/);
  assert.match(installer, /systemctl disable --now "\$TIMER_NAME"/);
  assert.match(installer, /--preview --mode=formal --limit=100/);
  assert.match(installer, /20260728_t_track_manual_notification_schedule_scopes\.sql/);
  assert.match(installer, /20260728_w_extend_manual_notification_schedule_rules\.sql/);
  assert.match(installer, /20260728_x_extend_manual_notification_three_source_delivery\.sql/);
  assert.match(installer, /20260812_zzzzzz_add_manual_notification_business_rules\.sql/);
  assert.match(installer, /ManualNotificationScheduleRuleService\.php/);
  assert.match(installer, /ManualNotificationConditionRuleService\.php/);
  assert.match(installer, /OperatingDailyReportPayloadService\.php/);
});

test('formal installer pins the runtime unit to the exact preflighted release', () => {
  const installer = read('deploy/systemd/install_manual_notification_formal_dispatch.sh');
  const serviceTemplate = read('deploy/systemd/suxios-manual-notification-formal-dispatch.service');
  const selectedRelease = '/var/www/suxios/releases/suxios-20260728-a';
  const renderedService = serviceTemplate.replaceAll('@SUXIOS_RELEASE_ROOT@', selectedRelease);

  assert.match(installer, /RELEASE_ROOT="\$\(readlink -f "\$RELEASE_ROOT"\)"/);
  assert.match(
    installer,
    /"\$RELEASE_ROOT\/deploy\/systemd\/\$SERVICE_NAME"\s*\\\s*\n\s*"\$RELEASE_ROOT"\s*\\\s*\n\s*"\$SERVICE_RENDERED"/
  );
  assert.match(renderedService, new RegExp(`ConditionPathExists=${selectedRelease}/think`));
  assert.match(renderedService, new RegExp(`WorkingDirectory=${selectedRelease}`));
  assert.doesNotMatch(renderedService, /@SUXIOS_RELEASE_ROOT@|\/var\/www\/suxios\/current/);
});

test('formal preflight validates scope without reading webhooks or sending messages', () => {
  const verifier = read('deploy/systemd/verify_manual_notification_formal_dispatch.php');

  assert.match(verifier, /resolvePlanRobot\(/);
  assert.match(verifier, /'formal'/);
  assert.match(verifier, /enabled_formal_schedule_missing/);
  assert.match(verifier, /manual_notification_schedule_run_scopes/);
  assert.match(verifier, /business_date_rule/);
  assert.match(verifier, /hourly_end_time/);
  assert.match(verifier, /source_scope/);
  assert.match(verifier, /content_sections/);
  assert.match(verifier, /interval_minutes/);
  assert.match(verifier, /condition_rule_fingerprint/);
  assert.match(verifier, /manual_notification_rule_states/);
  assert.match(verifier, /pending_trigger_bucket/);
  assert.match(verifier, /pending_dispatch_id/);
  assert.match(verifier, /pending_claimed_at/);
  assert.match(verifier, /last_test_status/);
  assert.match(verifier, /last_tested_at/);
  assert.match(verifier, /update_time/);
  assert.match(
    verifier,
    /manual_notification_schedule_test_evidence_invalid/
  );
  assert.match(verifier, /isOperatingDailyTriggerAllowed/);
  assert.match(verifier, /isStrictThreeSourceIntervalPlan/);
  assert.match(verifier, /operating_daily_loop_schedule_forbidden/);
  assert.match(verifier, /'webhook_read' => false/);
  assert.match(verifier, /'message_sent' => false/);
  assert.doesNotMatch(verifier, /deliverToPlanRobot|deliverToHotel|webhook`\s*FROM/);
});

test('cloud release refreshes an installed formal timer and preserves its lifecycle state', () => {
  const releaseInstaller = read('deploy/cloud/install_release.sh');
  const captureInstalled = releaseInstaller.indexOf(
    'systemctl cat "$FORMAL_DISPATCH_TIMER"'
  );
  const captureEnabled = releaseInstaller.indexOf(
    'systemctl is-enabled --quiet "$FORMAL_DISPATCH_TIMER"'
  );
  const captureActive = releaseInstaller.indexOf(
    'systemctl is-active --quiet "$FORMAL_DISPATCH_TIMER"'
  );
  const switchRelease = releaseInstaller.indexOf(
    'mv -Tf "$ROLLBACK_LINK" "$CURRENT_LINK"'
  );
  const healthGate = releaseInstaller.indexOf('if ! verify_health; then');
  const refreshGate = releaseInstaller.indexOf(
    'if ! refresh_formal_dispatch_for_release "$RELEASE_DIR"; then'
  );

  assert.match(
    releaseInstaller,
    /FORMAL_DISPATCH_TIMER="suxios-manual-notification-formal-dispatch\.timer"/
  );
  assert.ok(captureInstalled >= 0 && captureInstalled < switchRelease);
  assert.ok(captureEnabled >= 0 && captureEnabled < switchRelease);
  assert.ok(captureActive >= 0 && captureActive < switchRelease);
  assert.ok(healthGate >= 0 && healthGate < refreshGate);
  assert.match(
    releaseInstaller,
    /FORMAL_DISPATCH_PREVIOUS_STATE installed=%s enabled=%s active=%s/
  );
  assert.match(
    releaseInstaller,
    /if \[\[ \$FORMAL_DISPATCH_WAS_INSTALLED -ne 1 \]\]; then\s+return 0/
  );
  assert.match(
    releaseInstaller,
    /if ! bash "\$installer"\s+\\\s*\n\s*--release-root "\$release_root"\s+\\\s*\n\s*--install\s+\\\s*\n\s*--enable-formal-dispatch; then\s+return 1/
  );
  assert.match(
    releaseInstaller,
    /refresh_formal_dispatch_for_release[\s\S]*systemctl is-enabled --quiet "\$FORMAL_DISPATCH_TIMER"[\s\S]*systemctl is-active --quiet "\$FORMAL_DISPATCH_TIMER"/
  );
  assert.match(
    releaseInstaller,
    /if \[\[ \$FORMAL_DISPATCH_WAS_ENABLED -eq 1 \]\]; then[\s\S]*--enable-formal-dispatch[\s\S]*else[\s\S]*--install; then/
  );
  assert.match(
    releaseInstaller,
    /if \[\[ \$FORMAL_DISPATCH_WAS_ACTIVE -eq 1 \]\]; then[\s\S]*systemctl start "\$FORMAL_DISPATCH_TIMER"[\s\S]*systemctl stop "\$FORMAL_DISPATCH_TIMER"/
  );
});

test('formal timer refresh failure restores the previous release unit through rollback', () => {
  const releaseInstaller = read('deploy/cloud/install_release.sh');
  const restoreFunction = releaseInstaller.match(
    /restore_previous_formal_dispatch\(\) \{[\s\S]*?\n\}/
  )?.[0] || '';
  const refreshFunction = releaseInstaller.match(
    /refresh_formal_dispatch_for_release\(\) \{[\s\S]*?\n\}/
  )?.[0] || '';
  const rollbackFunction = releaseInstaller.match(
    /rollback_and_verify\(\) \{[\s\S]*?\n\}/
  )?.[0] || '';
  const refreshFailure = releaseInstaller.match(
    /if ! refresh_formal_dispatch_for_release "\$RELEASE_DIR"; then[\s\S]*?\nfi/
  )?.[0] || '';

  assert.match(restoreFunction, /FORMAL_DISPATCH_WAS_INSTALLED/);
  assert.match(restoreFunction, /FORMAL_DISPATCH_REFRESH_ATTEMPTED/);
  assert.match(
    restoreFunction,
    /refresh_formal_dispatch_for_release "\$PREVIOUS_RELEASE"/
  );
  assert.match(
    refreshFunction,
    /\$release_root\/deploy\/systemd\/install_manual_notification_formal_dispatch\.sh/
  );
  assert.match(refreshFunction, /--release-root "\$release_root"/);
  assert.match(refreshFunction, /--enable-formal-dispatch/);
  assert.match(refreshFunction, /if ! bash "\$installer"/);
  assert.match(rollbackFunction, /ln -sfn "\$PREVIOUS_RELEASE" "\$CURRENT_LINK"/);
  assert.match(rollbackFunction, /restore_previous_formal_dispatch/);
  assert.match(refreshFailure, /rollback_and_verify/);
  assert.match(refreshFailure, /previous release and formal unit restored/);
  assert.match(refreshFailure, /exit 82/);
});
