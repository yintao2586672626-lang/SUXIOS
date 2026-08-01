import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync('public/system-static.js', 'utf8');
const sandbox = { window: {}, console, setTimeout, clearTimeout };
vm.runInNewContext(source, sandbox, { filename: 'public/system-static.js' });

const {
  buildHotelPlatformBindingRows,
  platformAccountVerificationState,
} = sandbox.window.SUXI_SYSTEM_STATIC;

const helpers = {
  hasPlatformHotelMismatch: () => false,
  isPlatformSourceLoginExpired: () => false,
  platformCaptureStatusCode: () => 'none',
  platformAccountReason: () => ({ text: '', className: '' }),
  formatHotelBindingDate: (value) => value || '-',
  platformLastSuccessText: () => '-',
  platformAccountStatusText: (code) => code,
  platformAccountStatusClass: () => '',
  platformCaptureStatusText: () => '未采集',
  platformCaptureStatusClass: () => '',
};

const localDateKey = () => {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: 'Asia/Shanghai',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(new Date());
  const values = Object.fromEntries(parts.map((part) => [part.type, part.value]));
  return `${values.year}-${values.month}-${values.day}`;
};

const verifiedProfile = ({ id = 31, hotelId = 61, platform = 'ctrip' } = {}) => {
  const today = localDateKey();
  const profileKey = `${platform}-profile-${hotelId}`;
  return {
    id,
    system_hotel_id: hotelId,
    platform,
    ingestion_method: 'browser_profile',
    enabled: 1,
    status: 'ready',
    current_session_verified: true,
    config: {
      profile_id: profileKey,
      stable_profile_id: profileKey,
      profile_binding_key: profileKey,
      ...(platform === 'meituan' ? { store_id: `mt-store-${hotelId}` } : { hotel_id: `ctrip-hotel-${hotelId}` }),
      current_session_probe_performed: true,
      current_session_verified: true,
      current_session_status: 'verified',
      current_session_probe_at: `${today} 09:30:00`,
      current_session_probe_data_source_id: id,
      current_session_probe_date: today,
      current_session_probe_timezone: 'Asia/Shanghai',
      current_session_probe_platform: platform,
      current_session_probe_system_hotel_id: hotelId,
      current_session_probe_scope: 'same_data_source_profile_session',
      current_session_probe_producer: 'platform_profile_login_task',
    },
  };
};

assert.equal(typeof platformAccountVerificationState, 'function');

const cookieOnlyRows = buildHotelPlatformBindingRows({
  hotel: { id: 61, name: '喀纳斯牧野' },
  ctripConfig: { cookies: 'redacted-cookie' },
  helpers,
});
const cookieOnlyCtrip = cookieOnlyRows.find((row) => row.platform === 'ctrip');
assert.equal(cookieOnlyCtrip.level, 'partial', 'Cookie-only configuration must remain partial');
assert.equal(cookieOnlyCtrip.statusCode, 'waiting_login', 'Cookie-only configuration must wait for authorized login');
assert.equal(cookieOnlyCtrip.sessionVerified, false);
assert.equal(cookieOnlyCtrip.storeIdentitySaved, false);
assert.equal(cookieOnlyCtrip.hasManualAssist, true);
assert.match(cookieOnlyCtrip.verificationReasonText, /临时 Cookie\/API.*尚未完成授权登录/);

const historicalSourceRows = buildHotelPlatformBindingRows({
  hotel: { id: 61, name: '喀纳斯牧野' },
  ctripSource: {
    id: 30,
    system_hotel_id: 61,
    platform: 'ctrip',
    ingestion_method: 'historical_backfill',
    status: 'success',
    config: {},
  },
  helpers,
});
const historicalCtrip = historicalSourceRows.find((row) => row.platform === 'ctrip');
assert.equal(historicalCtrip.level, 'partial', 'Historical success must not become collection-ready');
assert.equal(historicalCtrip.statusCode, 'waiting_login');
assert.equal(historicalCtrip.sessionVerified, false);
assert.match(historicalCtrip.verificationReasonText, /历史数据不能证明当前登录有效/);

const profileWithoutProof = verifiedProfile();
delete profileWithoutProof.config.current_session_probe_performed;
delete profileWithoutProof.config.current_session_verified;
const unverifiedRows = buildHotelPlatformBindingRows({
  hotel: { id: 61, name: '喀纳斯牧野' },
  ctripProfile: profileWithoutProof,
  helpers,
});
const unverifiedCtrip = unverifiedRows.find((row) => row.platform === 'ctrip');
assert.equal(unverifiedCtrip.level, 'ready');
assert.equal(unverifiedCtrip.statusCode, 'profile_reusable');
assert.equal(unverifiedCtrip.storeIdentitySaved, true);
assert.equal(unverifiedCtrip.sessionVerified, false);
assert.match(unverifiedCtrip.verificationReasonText, /不阻塞采集.*平台实际采集结果/);

const forgedConfigProfile = verifiedProfile();
forgedConfigProfile.current_session_verified = false;
const forgedConfigState = platformAccountVerificationState({
  hotel: { id: 61 },
  platform: 'ctrip',
  profileSource: forgedConfigProfile,
});
assert.equal(
  forgedConfigState.sessionVerified,
  false,
  'Sanitized config flags must not override the server-authoritative current-session verdict',
);

const verifiedRows = buildHotelPlatformBindingRows({
  hotel: { id: 61, name: '喀纳斯牧野' },
  ctripProfile: verifiedProfile(),
  helpers,
});
const verifiedCtrip = verifiedRows.find((row) => row.platform === 'ctrip');
assert.equal(verifiedCtrip.level, 'ready');
assert.equal(verifiedCtrip.statusCode, 'logged_in');
assert.equal(verifiedCtrip.storeIdentitySaved, true);
assert.equal(verifiedCtrip.sessionVerified, true);
assert.equal(verifiedCtrip.verificationReasonText, '');

const meituanWithoutPartner = platformAccountVerificationState({
  hotel: { id: 61 },
  platform: 'meituan',
  profileSource: verifiedProfile({ id: 41, hotelId: 61, platform: 'meituan' }),
});
assert.equal(meituanWithoutPartner.storeIdentitySaved, true, 'Meituan POI/store identity does not require Partner ID');
assert.equal(meituanWithoutPartner.sessionVerified, true);
