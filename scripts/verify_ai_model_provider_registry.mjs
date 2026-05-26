import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const html = readFileSync(join(root, 'public/index.html'), 'utf8');
const aiConfig = readFileSync(join(root, 'app/controller/AiConfig.php'), 'utf8');

const failures = [];
const providers = [
  'openai',
  'anthropic',
  'gemini',
  'meta_llama',
  'xai',
  'mistral',
  'cohere',
  'perplexity',
  'amazon_nova',
  'microsoft_phi',
  'ibm_granite',
  'nvidia',
];

for (const provider of providers) {
  if (!aiConfig.includes(`'${provider}'`)) {
    failures.push(`backend provider definition missing: ${provider}`);
  }
  if (!html.includes(`value="${provider}"`)) {
    failures.push(`frontend quick setup option missing: ${provider}`);
  }
}

const requiredHtmlSnippets = [
  'aiQuickSetupProviderModels',
  'availableAiModelOptions',
  'base_url: aiQuickSetupForm.value.base_url',
  'option v-for="model in availableAiModelOptions"',
  'option v-for="model in transferAiModelOptions"',
  'return modelKey ||',
];

for (const snippet of requiredHtmlSnippets) {
  if (!html.includes(snippet)) {
    failures.push(`frontend model registry contract missing: ${snippet}`);
  }
}

if (failures.length > 0) {
  console.error(`AI model provider registry verification failed:\n- ${failures.join('\n- ')}`);
  process.exit(1);
}

console.log('AI model provider registry verification passed.');
