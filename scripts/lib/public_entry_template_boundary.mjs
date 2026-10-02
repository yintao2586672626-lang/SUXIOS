export const lineNumberForOffset = (content, offset) => content.slice(0, offset).split(/\r?\n/).length;

export const openTagStackBefore = (content, endOffset) => {
  const voidTags = new Set([
    'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
    'link', 'meta', 'param', 'source', 'track', 'wbr',
  ]);
  const stack = [];
  const tagPattern = /<!--[\s\S]*?-->|<![^>]*>|<\/?([a-zA-Z][\w:-]*)([^>]*)>/g;
  let match;
  while ((match = tagPattern.exec(content)) && match.index < endOffset) {
    const raw = match[0];
    if (raw.startsWith('<!--') || raw.startsWith('<!')) continue;
    const tag = match[1].toLowerCase();
    if (raw.startsWith('</')) {
      let matchingIndex = -1;
      for (let i = stack.length - 1; i >= 0; i -= 1) {
        if (stack[i].tag === tag) {
          matchingIndex = i;
          break;
        }
      }
      if (matchingIndex >= 0) stack.splice(matchingIndex);
      continue;
    }
    if (!voidTags.has(tag) && !/\/\s*>$/.test(raw)) stack.push({ tag, raw });
  }
  return stack;
};

export const hasOpenVueRoot = stack => stack.some(entry => entry.tag === 'div' && /\bid\s*=\s*["']app["']/.test(entry.raw));
