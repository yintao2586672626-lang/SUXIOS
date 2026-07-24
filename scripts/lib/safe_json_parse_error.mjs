export function safeJsonParseErrorCode(error) {
  const message = String(error?.message || '');
  const position = message.match(/\bposition\s+(\d+)\b/i);
  if (position) {
    return `parse_error_position_${position[1]}`;
  }
  const location = message.match(/\bline\s+(\d+)\s+column\s+(\d+)\b/i);
  if (location) {
    return `parse_error_line_${location[1]}_column_${location[2]}`;
  }
  return 'parse_error';
}

export function parseJsonTextSafely(text, label = 'json') {
  try {
    return JSON.parse(String(text ?? ''));
  } catch (error) {
    const safeLabel = String(label || 'json')
      .toLowerCase()
      .replace(/[^a-z0-9_.-]+/g, '_')
      .replace(/^_+|_+$/g, '')
      .slice(0, 80) || 'json';
    throw new SyntaxError(`${safeLabel}:${safeJsonParseErrorCode(error)}`);
  }
}
