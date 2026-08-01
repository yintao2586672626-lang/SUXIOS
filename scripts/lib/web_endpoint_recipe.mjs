export const WEB_ENDPOINT_RECIPE_SCHEMA_VERSION = 1;

const IDENTIFIER_PATTERN = /^[a-z][a-z0-9_-]{0,63}$/;
const BINDING_NAME_PATTERN = /^[a-z][a-z0-9_]{0,63}$/;
const PLACEHOLDER_PATTERN = /^\{([a-z][a-z0-9_]{0,63})\}$/;
const OPAQUE_ID_PATTERN = /^[A-Za-z0-9_-]{1,120}$/;
const FORBIDDEN_RECIPE_TEXT =
  /cookie|authorization|bearer|(?:^|[_-])token(?:$|[_-])|password|passwd|secret|signature|api[_-]?key/i;

export function defineWebEndpointRecipe(input = {}) {
  if (!input || typeof input !== 'object' || Array.isArray(input)) {
    throw new TypeError('web_endpoint_recipe_invalid');
  }

  const platform = identifier(input.platform, 'web_endpoint_recipe_platform_invalid');
  const sourceKind = identifier(
    input.sourceKind || input.source_kind,
    'web_endpoint_recipe_source_kind_invalid',
  );
  const businessModule = identifier(
    input.businessModule || input.business_module,
    'web_endpoint_recipe_business_module_invalid',
  );
  const id = identifier(input.id, 'web_endpoint_recipe_id_invalid');
  const intent = identifier(input.intent, 'web_endpoint_recipe_intent_invalid');
  const method = String(input.method || 'POST').trim().toUpperCase();
  if (!['GET', 'POST'].includes(method)) {
    throw new TypeError('web_endpoint_recipe_method_invalid');
  }

  const origin = normalizedHttpsOrigin(input.origin);
  const path = normalizedPath(input.path);
  const bindingDefinitions = normalizeBindingDefinitions(
    input.bindings || input.binding_definitions || {},
  );
  const bodyTemplate = normalizeTemplate(
    input.bodyTemplate ?? input.body_template ?? {},
    bindingDefinitions,
  );
  const referencedBindings = new Set();
  collectTemplateBindings(bodyTemplate, referencedBindings);
  for (const name of referencedBindings) {
    if (!Object.hasOwn(bindingDefinitions, name)) {
      throw new TypeError('web_endpoint_recipe_binding_definition_missing');
    }
  }
  for (const [name, definition] of Object.entries(bindingDefinitions)) {
    if (definition.required && !referencedBindings.has(name)) {
      throw new TypeError('web_endpoint_recipe_required_binding_unused');
    }
  }

  const recipe = {
    schema_version: WEB_ENDPOINT_RECIPE_SCHEMA_VERSION,
    id,
    platform,
    source_kind: sourceKind,
    business_module: businessModule,
    intent,
    origin,
    method,
    path,
    body_template: bodyTemplate,
    bindings: bindingDefinitions,
    optional: input.optional === true,
  };
  const serialized = JSON.stringify(recipe);
  if (FORBIDDEN_RECIPE_TEXT.test(serialized)) {
    throw new TypeError('web_endpoint_recipe_sensitive_material_forbidden');
  }
  return deepFreeze(recipe);
}

export function materializeWebEndpointRecipe(recipeInput, bindingsInput = {}) {
  const recipe = defineWebEndpointRecipe(recipeInput);
  if (!bindingsInput || typeof bindingsInput !== 'object' || Array.isArray(bindingsInput)) {
    throw new TypeError('web_endpoint_recipe_bindings_invalid');
  }
  const bindings = {};
  for (const name of Object.keys(bindingsInput)) {
    if (!Object.hasOwn(recipe.bindings, name)) {
      throw new TypeError('web_endpoint_recipe_binding_unknown');
    }
  }
  for (const [name, definition] of Object.entries(recipe.bindings)) {
    const hasValue = Object.hasOwn(bindingsInput, name);
    if (!hasValue && definition.required) {
      throw new TypeError('web_endpoint_recipe_binding_missing');
    }
    if (!hasValue) continue;
    bindings[name] = normalizeBindingValue(bindingsInput[name], definition);
  }

  return {
    recipe_id: recipe.id,
    platform: recipe.platform,
    source_kind: recipe.source_kind,
    business_module: recipe.business_module,
    intent: recipe.intent,
    origin: recipe.origin,
    method: recipe.method,
    path: recipe.path,
    body: materializeTemplate(recipe.body_template, bindings),
    optional: recipe.optional,
  };
}

export function materializeWebEndpointPlan(
  recipes,
  bindings,
  { maxRequests = 64 } = {},
) {
  if (!Array.isArray(recipes) || recipes.length === 0) {
    throw new TypeError('web_endpoint_recipe_plan_empty');
  }
  const limit = Number(maxRequests);
  if (!Number.isInteger(limit) || limit < 1 || limit > 200 || recipes.length > limit) {
    throw new TypeError('web_endpoint_recipe_plan_limit_exceeded');
  }
  if (!bindings || typeof bindings !== 'object' || Array.isArray(bindings)) {
    throw new TypeError('web_endpoint_recipe_bindings_invalid');
  }
  const normalizedRecipes = recipes.map((recipe) => defineWebEndpointRecipe(recipe));
  const allowedBindingNames = new Set(
    normalizedRecipes.flatMap((recipe) => Object.keys(recipe.bindings)),
  );
  for (const name of Object.keys(bindings)) {
    if (!allowedBindingNames.has(name)) {
      throw new TypeError('web_endpoint_recipe_binding_unknown');
    }
  }
  const materialized = normalizedRecipes.map((recipe) => {
    const scopedBindings = Object.fromEntries(
      Object.keys(recipe.bindings)
        .filter((name) => Object.hasOwn(bindings, name))
        .map((name) => [name, bindings[name]]),
    );
    return materializeWebEndpointRecipe(recipe, scopedBindings);
  });
  const ids = materialized.map((request) => request.recipe_id);
  if (new Set(ids).size !== ids.length) {
    throw new TypeError('web_endpoint_recipe_id_duplicate');
  }
  return materialized;
}

function normalizeBindingDefinitions(input) {
  if (!input || typeof input !== 'object' || Array.isArray(input)) {
    throw new TypeError('web_endpoint_recipe_binding_definitions_invalid');
  }
  const output = {};
  for (const [rawName, rawDefinition] of Object.entries(input)) {
    const name = String(rawName || '').trim().toLowerCase();
    if (!BINDING_NAME_PATTERN.test(name)
      || FORBIDDEN_RECIPE_TEXT.test(name)
      || !rawDefinition
      || typeof rawDefinition !== 'object'
      || Array.isArray(rawDefinition)
    ) {
      throw new TypeError('web_endpoint_recipe_binding_definition_invalid');
    }
    const format = String(rawDefinition.format || '').trim().toLowerCase();
    if (!['opaque_id', 'date', 'integer', 'enum', 'text'].includes(format)) {
      throw new TypeError('web_endpoint_recipe_binding_format_invalid');
    }
    const definition = {
      format,
      required: rawDefinition.required !== false,
    };
    if (format === 'integer') {
      const minimum = Number(rawDefinition.minimum ?? Number.MIN_SAFE_INTEGER);
      const maximum = Number(rawDefinition.maximum ?? Number.MAX_SAFE_INTEGER);
      if (!Number.isSafeInteger(minimum)
        || !Number.isSafeInteger(maximum)
        || minimum > maximum
      ) {
        throw new TypeError('web_endpoint_recipe_binding_range_invalid');
      }
      definition.minimum = minimum;
      definition.maximum = maximum;
    }
    if (format === 'enum') {
      const values = Array.isArray(rawDefinition.values)
        ? [...new Set(rawDefinition.values.map((value) => String(value)))]
        : [];
      if (values.length === 0
        || values.length > 50
        || values.some((value) => value === '' || value.length > 80)
      ) {
        throw new TypeError('web_endpoint_recipe_binding_enum_invalid');
      }
      definition.values = values;
    }
    output[name] = definition;
  }
  return output;
}

function normalizeTemplate(value, bindingDefinitions, depth = 0) {
  if (depth > 8) {
    throw new TypeError('web_endpoint_recipe_template_too_deep');
  }
  if (Array.isArray(value)) {
    if (value.length > 100) {
      throw new TypeError('web_endpoint_recipe_template_too_large');
    }
    return value.map((item) => normalizeTemplate(item, bindingDefinitions, depth + 1));
  }
  if (value && typeof value === 'object') {
    const entries = Object.entries(value);
    if (entries.length > 100) {
      throw new TypeError('web_endpoint_recipe_template_too_large');
    }
    const output = {};
    for (const [key, item] of entries) {
      if (!/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/.test(key)
        || FORBIDDEN_RECIPE_TEXT.test(key)
      ) {
        throw new TypeError('web_endpoint_recipe_template_key_invalid');
      }
      output[key] = normalizeTemplate(item, bindingDefinitions, depth + 1);
    }
    return output;
  }
  if (typeof value === 'string') {
    const match = value.match(PLACEHOLDER_PATTERN);
    if (match) {
      if (!Object.hasOwn(bindingDefinitions, match[1])) {
        throw new TypeError('web_endpoint_recipe_binding_definition_missing');
      }
      return value;
    }
    if (value.length > 300 || FORBIDDEN_RECIPE_TEXT.test(value)) {
      throw new TypeError('web_endpoint_recipe_template_value_invalid');
    }
    return value;
  }
  if (value === null || typeof value === 'boolean') return value;
  if (typeof value === 'number' && Number.isFinite(value)) return value;
  throw new TypeError('web_endpoint_recipe_template_value_invalid');
}

function collectTemplateBindings(value, output) {
  if (Array.isArray(value)) {
    value.forEach((item) => collectTemplateBindings(item, output));
    return;
  }
  if (value && typeof value === 'object') {
    Object.values(value).forEach((item) => collectTemplateBindings(item, output));
    return;
  }
  if (typeof value === 'string') {
    const match = value.match(PLACEHOLDER_PATTERN);
    if (match) output.add(match[1]);
  }
}

function materializeTemplate(value, bindings) {
  if (Array.isArray(value)) {
    return value.map((item) => materializeTemplate(item, bindings));
  }
  if (value && typeof value === 'object') {
    return Object.fromEntries(
      Object.entries(value).map(([key, item]) => [
        key,
        materializeTemplate(item, bindings),
      ]),
    );
  }
  if (typeof value === 'string') {
    const match = value.match(PLACEHOLDER_PATTERN);
    if (match) {
      if (!Object.hasOwn(bindings, match[1])) {
        throw new TypeError('web_endpoint_recipe_binding_missing');
      }
      return bindings[match[1]];
    }
  }
  return value;
}

function normalizeBindingValue(value, definition) {
  if (definition.format === 'opaque_id') {
    const text = String(value || '').trim();
    if (!OPAQUE_ID_PATTERN.test(text)) {
      throw new TypeError('web_endpoint_recipe_binding_value_invalid');
    }
    return text;
  }
  if (definition.format === 'date') {
    const text = String(value || '').trim();
    if (!isIsoDate(text)) {
      throw new TypeError('web_endpoint_recipe_binding_value_invalid');
    }
    return text;
  }
  if (definition.format === 'integer') {
    const number = Number(value);
    if (!Number.isSafeInteger(number)
      || number < definition.minimum
      || number > definition.maximum
    ) {
      throw new TypeError('web_endpoint_recipe_binding_value_invalid');
    }
    return number;
  }
  if (definition.format === 'enum') {
    const text = String(value);
    if (!definition.values.includes(text)) {
      throw new TypeError('web_endpoint_recipe_binding_value_invalid');
    }
    return text;
  }
  const text = String(value || '').trim();
  if (text === '' || text.length > 300 || FORBIDDEN_RECIPE_TEXT.test(text)) {
    throw new TypeError('web_endpoint_recipe_binding_value_invalid');
  }
  return text;
}

function normalizedHttpsOrigin(value) {
  let parsed;
  try {
    parsed = new URL(String(value || ''));
  } catch {
    throw new TypeError('web_endpoint_recipe_origin_invalid');
  }
  if (parsed.protocol !== 'https:'
    || parsed.username !== ''
    || parsed.password !== ''
    || parsed.pathname !== '/'
    || parsed.search !== ''
    || parsed.hash !== ''
  ) {
    throw new TypeError('web_endpoint_recipe_origin_invalid');
  }
  return parsed.origin;
}

function normalizedPath(value) {
  const path = String(value || '').trim();
  if (!path.startsWith('/')
    || path.startsWith('//')
    || path.includes('?')
    || path.includes('#')
    || path.length > 300
    || FORBIDDEN_RECIPE_TEXT.test(path)
  ) {
    throw new TypeError('web_endpoint_recipe_path_invalid');
  }
  return path;
}

function identifier(value, errorCode) {
  const text = String(value || '').trim().toLowerCase();
  if (!IDENTIFIER_PATTERN.test(text) || FORBIDDEN_RECIPE_TEXT.test(text)) {
    throw new TypeError(errorCode);
  }
  return text;
}

function isIsoDate(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const parsed = new Date(`${value}T00:00:00.000Z`);
  return Number.isFinite(parsed.getTime()) && parsed.toISOString().slice(0, 10) === value;
}

function deepFreeze(value) {
  if (!value || typeof value !== 'object' || Object.isFrozen(value)) return value;
  Object.values(value).forEach(deepFreeze);
  return Object.freeze(value);
}
