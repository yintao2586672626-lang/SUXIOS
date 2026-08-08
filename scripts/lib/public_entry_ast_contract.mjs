import { parse } from 'acorn';

function unwrap(node) {
  return node?.type === 'ChainExpression' ? node.expression : node;
}

function staticBoolean(node) {
  const current = unwrap(node);
  if (current?.type === 'Literal' && typeof current.value === 'boolean') return current.value;
  return null;
}

function walk(root, visitor, parent = null) {
  const node = unwrap(root);
  if (!node || typeof node !== 'object' || typeof node.type !== 'string') return;
  visitor(node, parent);
  if (node.type === 'IfStatement') {
    walk(node.test, visitor, node);
    const condition = staticBoolean(node.test);
    if (condition !== false) walk(node.consequent, visitor, node);
    if (condition !== true) walk(node.alternate, visitor, node);
    return;
  }
  if (node.type === 'ConditionalExpression') {
    walk(node.test, visitor, node);
    const condition = staticBoolean(node.test);
    if (condition !== false) walk(node.consequent, visitor, node);
    if (condition !== true) walk(node.alternate, visitor, node);
    return;
  }
  for (const [key, value] of Object.entries(node)) {
    if (['type', 'start', 'end', 'loc', 'range'].includes(key) || value == null) continue;
    if (Array.isArray(value)) {
      for (const child of value) walk(child, visitor, node);
    } else if (typeof value === 'object') {
      walk(value, visitor, node);
    }
  }
}

function memberPath(root) {
  const node = unwrap(root);
  if (!node) return '';
  if (node.type === 'Identifier') return node.name;
  if (node.type === 'ThisExpression') return 'this';
  if (node.type !== 'MemberExpression') return '';
  const objectPath = memberPath(node.object);
  const propertyName = node.computed
    ? (node.property?.type === 'Literal' ? String(node.property.value) : '')
    : (node.property?.name || '');
  return objectPath && propertyName ? `${objectPath}.${propertyName}` : '';
}

function literalValue(root) {
  const node = unwrap(root);
  return node?.type === 'Literal' ? node.value : undefined;
}

function isFunction(node) {
  return ['ArrowFunctionExpression', 'FunctionExpression', 'FunctionDeclaration'].includes(node?.type);
}

function findNamedFunction(ast, name) {
  let match = null;
  walk(ast, (node) => {
    if (node.type === 'FunctionDeclaration' && node.id?.name === name) {
      match = node;
    } else if (node.type === 'VariableDeclarator' && node.id?.name === name && isFunction(node.init)) {
      match = node.init;
    } else if (node.type === 'AssignmentExpression'
      && memberPath(node.left) === name
      && isFunction(node.right)) {
      match = node.right;
    }
  });
  return match;
}

function findObjectMethod(ast, objectName, methodName) {
  let match = null;
  walk(ast, (node) => {
    if (match
      || node.type !== 'VariableDeclarator'
      || node.id?.name !== objectName
      || node.init?.type !== 'ObjectExpression') return;
    const property = node.init.properties.find((candidate) => {
      const key = candidate.computed ? literalValue(candidate.key) : candidate.key?.name;
      return key === methodName && isFunction(candidate.value);
    });
    match = property?.value || null;
  });
  return match;
}

function findAssignedFunction(root, targetPath) {
  let match = null;
  walk(root, (node) => {
    if (!match
      && node.type === 'AssignmentExpression'
      && memberPath(node.left) === targetPath
      && isFunction(node.right)) {
      match = node.right;
    }
  });
  return match;
}

function collectCalls(root, targetPath, firstArgument = undefined) {
  const matches = [];
  walk(root, (node) => {
    if (node.type !== 'CallExpression' || memberPath(node.callee) !== targetPath) return;
    if (firstArgument !== undefined && literalValue(node.arguments?.[0]) !== firstArgument) return;
    matches.push(node);
  });
  return matches;
}

function collectAssignments(root, targetPath, expectedIdentifier = undefined) {
  const matches = [];
  walk(root, (node) => {
    if (node.type !== 'AssignmentExpression' || memberPath(node.left) !== targetPath) return;
    if (expectedIdentifier !== undefined
      && (node.right?.type !== 'Identifier' || node.right.name !== expectedIdentifier)) return;
    matches.push(node);
  });
  return matches;
}

function hasCallOrCallbackReference(root, identifierName) {
  if (collectCalls(root, identifierName).length > 0) return true;
  let found = false;
  walk(root, (node, parent) => {
    if (found || node.type !== 'Identifier' || node.name !== identifierName) return;
    if (parent?.type === 'CallExpression' && parent.arguments.includes(node)) found = true;
  });
  return found;
}

function hasCustomEventDispatch(root, eventName) {
  let found = false;
  walk(root, (node) => {
    if (found || node.type !== 'CallExpression' || memberPath(node.callee) !== 'window.dispatchEvent') return;
    const event = unwrap(node.arguments?.[0]);
    if (event?.type === 'NewExpression'
      && memberPath(event.callee) === 'CustomEvent'
      && literalValue(event.arguments?.[0]) === eventName) found = true;
  });
  return found;
}

function hasWeakMapDeclaration(ast, name) {
  let found = false;
  walk(ast, (node) => {
    if (found || node.type !== 'VariableDeclarator' || node.id?.name !== name) return;
    const init = unwrap(node.init);
    found = init?.type === 'NewExpression' && memberPath(init.callee) === 'WeakMap';
  });
  return found;
}

function hasFatalRecoveryGuard(root) {
  let found = false;
  walk(root, (node) => {
    if (found || node.type !== 'IfStatement') return;
    let referencesFatal = false;
    walk(node.test, (candidate) => {
      if (candidate.type === 'Identifier' && candidate.name === 'isFatalStartupError') referencesFatal = true;
    });
    if (!referencesFatal) return;
    walk(node.consequent, (candidate) => {
      if (candidate.type === 'ReturnStatement' && literalValue(candidate.argument) === false) found = true;
    });
  });
  return found;
}

function hasFatalClassificationPattern(root) {
  let found = false;
  walk(root, (node) => {
    if (node.type !== 'Literal' || !node.regex?.pattern) return;
    const pattern = node.regex.pattern;
    found ||= ['setup function', 'app errorHandler', 'app warnHandler', 'app unmount cleanup function']
      .every((term) => pattern.includes(term));
  });
  return found;
}

function parseSource(source, label, failures) {
  try {
    return parse(String(source || ''), {
      ecmaVersion: 'latest',
      sourceType: 'script',
      allowHashBang: true,
    });
  } catch {
    failures.push(`${label} must remain valid JavaScript before its runtime contract can be inspected.`);
    return null;
  }
}

export function inspectPublicEntryRuntimeContracts({ appBootstrapSource, appMainSource }) {
  const failures = [];
  const bootstrapAst = parseSource(appBootstrapSource, 'public/app-bootstrap.js', failures);
  const appMainAst = parseSource(appMainSource, 'public/app-main.js', failures);
  if (!bootstrapAst || !appMainAst) return { failures };

  const deferredLoader = findNamedFunction(bootstrapAst, 'loadDeferredAuthenticatedAssets');
  if (!deferredLoader || !hasCustomEventDispatch(deferredLoader.body, 'suxi:full-render-ready')) {
    failures.push('public/app-bootstrap.js must dispatch suxi:full-render-ready from the executable deferred authenticated asset loader.');
  }
  if (!hasWeakMapDeclaration(appMainAst, 'suxiRenderCaches')) {
    failures.push('public/app-main.js must keep render caches isolated by render function in a WeakMap.');
  }
  const renderMethod = findObjectMethod(appMainAst, 'suxiRootComponent', 'render');
  if (!renderMethod
    || collectCalls(renderMethod.body, 'suxiRenderCaches.get').length === 0
    || collectCalls(renderMethod.body, 'suxiRenderCaches.set').length === 0
    || collectCalls(renderMethod.body, 'activeRender.apply').length === 0) {
    failures.push('public/app-main.js root render must read and populate the active render cache before invoking the active render function.');
  }

  const promote = findNamedFunction(appMainAst, 'promoteSuxiFullRender');
  const promoteSteps = promote ? [
    collectAssignments(promote.body, 'window.SUXI_INITIAL_PAGE_OVERRIDE', 'targetPage')[0],
    collectCalls(promote.body, 'suxiApp.unmount')[0],
    collectAssignments(promote.body, 'suxiActiveRender.value', 'fullRender')[0],
    collectCalls(promote.body, 'mountSuxiApp')[0],
  ] : [];
  if (promoteSteps.length !== 4
    || promoteSteps.some((step) => !step)
    || !promoteSteps.every((step, index) => index === 0 || promoteSteps[index - 1].start < step.start)) {
    failures.push('public/app-main.js must preserve the target page, unmount, switch render, and remount in order.');
  }

  const requestFullRender = findNamedFunction(appMainAst, 'requestSuxiFullRenderForPage');
  if (!requestFullRender
    || collectAssignments(requestFullRender.body, 'pendingFullRenderPage').length === 0
    || collectCalls(requestFullRender.body, 'window.SUXI_LOAD_DEFERRED_AUTHENTICATED_ASSETS').length === 0
    || !hasCallOrCallbackReference(requestFullRender.body, 'promoteSuxiFullRender')) {
    failures.push('public/app-main.js must request deferred assets and schedule full-render promotion for non-startup pages.');
  }
  const readyListeners = collectCalls(appMainAst, 'window.addEventListener', 'suxi:full-render-ready');
  if (!readyListeners.some((listener) => listener.arguments?.[1]?.type === 'Identifier'
    && listener.arguments[1].name === 'handleSuxiFullRenderReady')) {
    failures.push('public/app-main.js must connect suxi:full-render-ready to the executable full-render handler.');
  }

  const recover = findNamedFunction(appMainAst, 'recoverSuxiRuntimeError');
  if (!recover
    || !hasFatalClassificationPattern(recover.body)
    || !hasFatalRecoveryGuard(recover.body)
    || collectAssignments(recover.body, 'currentPage.value').every((node) => literalValue(node.right) !== 'compass')
    || collectCalls(recover.body, 'showToast').length === 0) {
    failures.push('public/app-main.js runtime recovery must keep startup failures fatal and return recoverable page failures to the safe compass page.');
  }

  const configure = findNamedFunction(appMainAst, 'configureSuxiApp');
  const errorHandler = configure ? findAssignedFunction(configure.body, 'app.config.errorHandler') : null;
  const recoveryCall = errorHandler ? collectCalls(errorHandler.body, 'recoverSuxiRuntimeError')[0] : null;
  const fatalScheduleCall = errorHandler ? collectCalls(errorHandler.body, 'scheduleSuxiStartupError')[0] : null;
  const directFatalRender = errorHandler ? collectCalls(errorHandler.body, 'renderSuxiStartupError') : [];
  const fatalScheduler = findNamedFunction(appMainAst, 'scheduleSuxiStartupError');
  const deferredFatalCall = fatalScheduler ? collectCalls(fatalScheduler.body, 'window.setTimeout')[0] : null;
  const fatalCallback = deferredFatalCall && isFunction(deferredFatalCall.arguments?.[0])
    ? deferredFatalCall.arguments[0]
    : null;
  const unmountCall = fatalCallback ? collectCalls(fatalCallback.body, 'appToUnmount.unmount')[0] : null;
  const fatalRenderCall = fatalCallback ? collectCalls(fatalCallback.body, 'renderSuxiStartupError')[0] : null;
  let appCapture = null;
  let appClear = null;
  let unmountTry = null;
  if (fatalCallback) {
    walk(fatalCallback.body, (node) => {
      if (!appCapture
        && node.type === 'VariableDeclarator'
        && node.id?.name === 'appToUnmount'
        && node.init?.type === 'Identifier'
        && node.init.name === 'suxiApp') appCapture = node;
      if (!appClear
        && node.type === 'AssignmentExpression'
        && memberPath(node.left) === 'suxiApp'
        && literalValue(node.right) === null) appClear = node;
      if (!unmountTry
        && node.type === 'TryStatement'
        && collectCalls(node.block, 'appToUnmount.unmount').length > 0) unmountTry = node;
    });
  }
  let catchReturns = false;
  if (unmountTry?.handler?.body) {
    walk(unmountTry.handler.body, (node) => {
      if (node.type === 'ReturnStatement') catchReturns = true;
    });
  }
  if (!errorHandler || !recoveryCall || !fatalScheduleCall
    || recoveryCall.start >= fatalScheduleCall.start || directFatalRender.length > 0
    || !deferredFatalCall || !fatalCallback || !appCapture || !appClear || !unmountTry
    || !unmountCall || !fatalRenderCall || catchReturns
    || appCapture.start >= appClear.start || appClear.start >= unmountCall.start
    || unmountTry.end >= fatalRenderCall.start) {
    failures.push('public/app-main.js Vue error handler must recover in scope, then defer fatal rendering until after a safe unmount attempt.');
  }

  const mount = findNamedFunction(appMainAst, 'mountSuxiApp');
  const configureCall = mount ? collectCalls(mount.body, 'configureSuxiApp')[0] : null;
  const mountCall = mount ? collectCalls(mount.body, 'suxiApp.mount')[0] : null;
  if (!mount || !configureCall || !mountCall || configureCall.start >= mountCall.start) {
    failures.push('public/app-main.js must configure the Vue error boundary before mounting the application.');
  }

  return { failures };
}
