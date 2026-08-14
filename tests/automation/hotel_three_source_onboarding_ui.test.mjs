import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const appMain = read('public/app-main.js');
const dialog = read('resources/frontend/templates/fragments/40-dialog-hotel.html');
const hotelController = read('app/controller/Hotel.php');

const sliceBetween = (source, start, end) => {
  const startIndex = source.indexOf(start);
  const endIndex = source.indexOf(end, startIndex + start.length);
  assert.notEqual(startIndex, -1, `missing start marker: ${start}`);
  assert.notEqual(endIndex, -1, `missing end marker: ${end}`);
  return source.slice(startIndex, endIndex);
};
const panel = sliceBetween(appMain, 'const hotelThreeSourceOnboardingPanel = markRaw', 'const hotelSaving = ref');

test('new hotel stays in a four-step, truthful three-source onboarding flow', () => {
  for (const testId of [
    'hotel-onboarding-steps',
    'hotel-onboarding-hotel-step',
    'hotel-onboarding-authorization-step',
    'hotel-onboarding-verification-step',
    'hotel-onboarding-complete-step',
    'hotel-onboarding-open-wechat',
  ]) {
    assert.match(panel, new RegExp(`'data-testid': '${testId}'`));
  }
  assert.match(dialog, /:is="hotelThreeSourceOnboardingPanel"/);
  assert.match(panel, /创建、授权或保存身份不会自动采集，也不会向企业微信发送消息。/);
  assert.match(panel, /这一步没有启动采集，也没有发送任何企业微信消息。/);
  assert.match(panel, /进入企业微信配置/);
});

test('onboarding accepts public store identity only and never asks for credentials', () => {
  assert.match(panel, /'data-testid': 'hotel-pms-public-identity'/);
  assert.match(panel, /hotelForm\.value\.pms_provider_hotel_id/);
  assert.match(panel, /hotelForm\.value\.pms_provider_hotel_name/);
  assert.doesNotMatch(panel, /type:\s*'password'/i);
  assert.doesNotMatch(panel, /(?:password|cookie|verification_code|sms_code)\s*:/i);
  assert.match(panel, /账号、密码和验证码只在平台自己的云端浏览器页面输入/);
});

test('PMS metadata endpoint rejects every unlisted field instead of accepting credentials', () => {
  const pmsWriter = sliceBetween(hotelController, 'public function updatePmsBinding', 'public function create');
  assert.match(pmsWriter, /'provider_hotel_id'/);
  assert.match(pmsWriter, /hotel_pms_binding_input_unknown_fields/);
  assert.match(pmsWriter, /'credentials_accepted' => false/);
});

test('create response is pinned to savedHotelId and exact readback before advancing', () => {
  const saver = sliceBetween(appMain, 'const saveHotel = async', 'const toggleHotelStatus = async');
  const creator = sliceBetween(hotelController, 'public function create', 'private function assignGeneratedHotelCode');
  assert.match(saver, /savedHotelId = savedHotel\.id \|\| hotelForm\.value\.id/);
  assert.match(saver, /await loadHotels\(\{ force: true, includeInactive: true \}\)/);
  assert.match(saver, /String\(item\?\.id \|\| ''\)\.trim\(\) === exactSavedHotelId/);
  assert.match(saver, /normalizeHotelIdentityName\(readbackHotel\.name\) !== normalizeHotelIdentityName\(payload\.name\)/);
  assert.match(saver, /if \(isEdit && !hotelOnboardingActive\.value\)/);
  assert.match(saver, /hotelOnboardingStep\.value = 'authorization'/);
  assert.match(saver, /loadHotelThreeSourceOnboarding\(\{ hotelId: exactSavedHotelId, silent: true \}\)/);
  assert.match(creator, /hotel_create_input_unknown_fields/);
  assert.match(creator, /'credentials_accepted' => false/);
});

test('cloud logins are serialized and only complete with opaque profile and session ids', () => {
  const opener = sliceBetween(appMain, 'const openHotelOnboardingCloudLogin = async', 'const completeHotelOnboardingCloudLogin = async');
  const completer = sliceBetween(appMain, 'const completeHotelOnboardingCloudLogin = async', 'const saveHotelOnboardingBinding = async');
  assert.match(opener, /hotelOnboardingBusyPlatform\.value\) return false/);
  assert.match(opener, /request\('\/cloud-browser-profiles\/open-login'/);
  assert.match(opener, /data\.browser_started !== true/);
  assert.match(opener, /hotelOnboardingViewerUrl\(data\.viewer_url\)/);
  assert.match(appMain, /url\.origin !== window\.location\.origin/);
  assert.match(appMain, /!url\.pathname\.startsWith\('\/cloud-browser-viewer\/'\)/);
  assert.match(opener, /viewerWindow\.location\.replace\(viewerUrl\)/);
  assert.match(completer, /request\('\/cloud-browser-profiles\/complete-login'/);
  assert.match(completer, /profile_id: session\.profile_id/);
  assert.match(completer, /session_id: session\.session_id/);
  assert.match(completer, /delete nextSessions\[platform\]/);
  assert.match(completer, /hotelOnboardingBusyPlatform\.value = ''/);
  assert.match(appMain, /row\.profileReady === true/);
  assert.match(panel, /rows\.every\(row => row\.profileReady === true\)/);
});

test('platform identities save and read back through the exact hotel-scoped contract', () => {
  assert.match(appMain, /`\/hotels\/\$\{encodeURIComponent\(exactHotelId\)\}\/three-source-onboarding`/);
  assert.match(appMain, /`\/hotels\/\$\{encodeURIComponent\(exactHotelId\)\}\/platform-bindings\/\$\{encodeURIComponent\(platform\)\}`/);
  assert.match(appMain, /`\/hotels\/\$\{encodeURIComponent\(exactHotelId\)\}\/pms-binding`/);
  assert.match(appMain, /provider_hotel_id: platformHotelId/);
  assert.match(appMain, /provider_hotel_name: platformHotelName/);
  assert.match(appMain, /platform_hotel_id: platformHotelId/);
  assert.match(appMain, /platform_hotel_name: platformHotelName/);
  assert.match(appMain, /!platformHotelId \|\| !platformHotelName/);
  assert.match(appMain, /binding\.readback_verified === true/);
  assert.match(appMain, /门店回读串店/);
  assert.match(appMain, /statusClass: statusMeta\[1\]/);
});

test('hourly collection remains an explicit dual-OTA plus PMS action with strict response readback', () => {
  const enabler = sliceBetween(appMain, 'const enableHotelOnboardingHourlyCollection = async', 'const openHotelOnboardingWechatConfig =');
  const planWriter = sliceBetween(hotelController, 'public function updateCollectionPlan', 'public function updatePmsBinding');
  assert.match(enabler, /hotelOnboardingCollectionPlanEligible\.value/);
  assert.match(enabler, /当前统一三源队列要求携程\+美团\+PMS/);
  assert.match(enabler, /request\(`\/hotels\/\$\{encodeURIComponent\(exactHotelId\)\}\/collection-plan`/);
  assert.match(enabler, /business_date_policy: 'same_day_realtime'/);
  assert.match(enabler, /timezone: 'Asia\/Shanghai'/);
  assert.match(enabler, /schedule_time: '00:30'/);
  assert.match(enabler, /retry_interval_minutes: 30/);
  assert.match(enabler, /max_attempts: 1/);
  assert.match(enabler, /activate: true/);
  assert.match(enabler, /ctrip: \{ data_source_id:/);
  assert.match(enabler, /meituan: \{ data_source_id:/);
  assert.match(enabler, /dingdandao_pms/);
  assert.match(enabler, /data\.readback_verified !== true/);
  assert.match(enabler, /data\.execution_authorized !== true/);
  assert.match(enabler, /data\.plan_status/);
  assert.match(panel, /'data-testid': 'hotel-onboarding-enable-collection'/);
  assert.match(panel, /不会发送企业微信消息/);
  assert.doesNotMatch(panel, /enableHotelOnboardingHourlyCollection\(\)/);
  assert.match(planWriter, /hotel_collection_plan_input_unknown_fields/);
  assert.match(planWriter, /'credentials_accepted' => false/);
});

test('collection plan can be enabled before onboarding is allowed to finish', () => {
  const verification = sliceBetween(appMain, 'const renderVerificationStep = () =>', 'const renderCompleteStep = () =>');
  assert.match(verification, /'data-testid': 'hotel-onboarding-enable-collection'/);
  assert.match(verification, /onClick: enableHotelOnboardingHourlyCollection/);
  assert.match(verification, /disabled: hotelOnboardingLoading\.value \|\| !hotelOnboardingReady\.value/);
  assert.match(appMain, /await loadHotelThreeSourceOnboarding\(\{ hotelId: exactHotelId, silent: true \}\)/);
});

test('page completion never overrides a backend blocker when visible source rows look ready', () => {
  const readyProjection = sliceBetween(
    appMain,
    'const hotelOnboardingReady = computed(() => {',
    'const hotelOnboardingCollectionPlanEligible = computed(() => {'
  );
  assert.match(readyProjection, /if \(!sourceRowsReady\) return false/);
  assert.match(readyProjection, /snapshot\.ready === true/);
  assert.doesNotMatch(readyProjection, /return sourceRowsReady\s*;/);
});
