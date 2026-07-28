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
  assert.match(verifier, /'webhook_read' => false/);
  assert.match(verifier, /'message_sent' => false/);
  assert.doesNotMatch(verifier, /deliverToPlanRobot|deliverToHotel|webhook`\s*FROM/);
});
