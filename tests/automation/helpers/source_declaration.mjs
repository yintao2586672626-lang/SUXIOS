import assert from 'node:assert/strict';
import { parse } from 'acorn';

const trees = new Map();

// Read the executed declaration after extraction; no replacement implementation is supplied.
export function sourceDeclaration(source, name) {
  if (!trees.has(source)) trees.set(source, parse(source, { ecmaVersion: 'latest', sourceType: 'script' }));
  const matches = [];
  const visit = node => {
    if (!node || typeof node !== 'object') return;
    if (node.type === 'VariableDeclaration' && node.declarations.some(item => item.id?.name === name)) matches.push(node);
    for (const child of Object.values(node)) {
      if (Array.isArray(child)) child.forEach(visit);
      else if (child && typeof child === 'object') visit(child);
    }
  };
  visit(trees.get(source));
  assert.equal(matches.length, 1, `one actual declaration is required for ${name}`);
  return source.slice(matches[0].start, matches[0].end);
}
