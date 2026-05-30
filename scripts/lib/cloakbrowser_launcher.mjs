import { launchPersistentContext } from 'cloakbrowser';

export async function launchOtaPersistentContext(userDataDir, parsedArgs, defaults = {}) {
  return launchPersistentContext(buildOtaPersistentContextOptions(userDataDir, parsedArgs, defaults));
}

export function buildOtaPersistentContextOptions(userDataDir, parsedArgs, defaults = {}) {
  const args = buildChromiumArgs(parsedArgs);
  const launchOptions = {};
  const chromePath = stringValue(parsedArgs.chromePath);
  if (chromePath) {
    launchOptions.executablePath = chromePath;
  }

  return {
    userDataDir,
    headless: parsedArgs.headless === 'true',
    viewport: defaults.viewport || { width: 1440, height: 960 },
    locale: stringValue(parsedArgs.locale) || defaults.locale || 'zh-CN',
    ...(stringValue(parsedArgs.timezone) ? { timezone: stringValue(parsedArgs.timezone) } : {}),
    ...(stringValue(parsedArgs.proxy) ? { proxy: stringValue(parsedArgs.proxy) } : {}),
    ...(parsedArgs.geoip === 'true' ? { geoip: true } : {}),
    ...(parsedArgs.humanize === 'true' ? { humanize: true } : {}),
    ...(args.length ? { args } : {}),
    ...(Object.keys(launchOptions).length ? { launchOptions } : {}),
  };
}

function buildChromiumArgs(parsedArgs) {
  const args = [];
  const fingerprint = stringValue(parsedArgs.fingerprint || parsedArgs.fingerprintSeed);
  if (fingerprint) {
    args.push(`--fingerprint=${fingerprint}`);
  }

  const remoteDebuggingPort = stringValue(parsedArgs.remoteDebuggingPort || parsedArgs.cdpPort);
  if (remoteDebuggingPort) {
    const port = Number(remoteDebuggingPort);
    if (!Number.isInteger(port) || port < 1 || port > 65535) {
      throw new Error(`Invalid remote debugging port: ${remoteDebuggingPort}`);
    }
    args.push(`--remote-debugging-port=${port}`);
    args.push('--remote-debugging-address=127.0.0.1');
  }

  return args;
}

function stringValue(value) {
  if (value === null || value === undefined) {
    return '';
  }
  return String(value).trim();
}
