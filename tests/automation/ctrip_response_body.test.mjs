import assert from 'node:assert/strict';
import test from 'node:test';

import { parseCtripResponseBody } from '../../scripts/lib/ctrip_response_body.mjs';

test('Ctrip response parser accepts JSON objects and arrays', () => {
  const objectResult = parseCtripResponseBody('{"data":{"listExposure":120}}', 'application/json');
  const arrayResult = parseCtripResponseBody('[{"date":"2026-07-27"}]', 'text/plain');

  assert.equal(objectResult.ok, true);
  assert.equal(objectResult.evidence.format, 'json');
  assert.equal(objectResult.body.data.listExposure, 120);
  assert.equal(arrayResult.ok, true);
  assert.equal(arrayResult.body[0].date, '2026-07-27');
});

test('Ctrip response parser unwraps JSON strings, XSSI prefixes and JSONP', () => {
  const jsonString = parseCtripResponseBody(
    JSON.stringify('{"data":[{"orderSubmitNum":3}]}'),
    'text/plain',
  );
  const xssi = parseCtripResponseBody(
    ')]}\',\n{"data":{"detailExposure":40}}',
    'application/json',
  );
  const jsonp = parseCtripResponseBody(
    '/**/ctripCallback({"data":{"orderFillingNum":2}});',
    'application/javascript',
  );

  assert.equal(jsonString.ok, true);
  assert.equal(jsonString.evidence.format, 'json_string');
  assert.equal(jsonString.body.data[0].orderSubmitNum, 3);
  assert.equal(xssi.ok, true);
  assert.equal(xssi.evidence.format, 'xssi_json');
  assert.equal(xssi.body.data.detailExposure, 40);
  assert.equal(jsonp.ok, true);
  assert.equal(jsonp.evidence.format, 'jsonp');
  assert.equal(jsonp.body.data.orderFillingNum, 2);
});

test('Ctrip response parser rejects HTML and unknown text without retaining raw content', () => {
  const html = parseCtripResponseBody(
    '<!doctype html><html><body>login required secret-value</body></html>',
    'text/html',
  );
  const unknown = parseCtripResponseBody('encrypted-or-unsupported secret-value', 'text/plain');

  assert.equal(html.ok, false);
  assert.equal(html.body, null);
  assert.equal(html.evidence.reason, 'html_document');
  assert.equal(unknown.ok, false);
  assert.equal(unknown.body, null);
  assert.equal(unknown.evidence.reason, 'unrecognized_text');
  assert.equal(unknown.evidence.sensitive_values_exposed, false);
  assert.match(unknown.evidence.body_sha256, /^[a-f0-9]{16}$/);
  assert.doesNotMatch(JSON.stringify(unknown), /secret-value/);
});
