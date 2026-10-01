"""Read the two user attachments as data; retain bounded, reproducible sources."""
import argparse
import hashlib
import json
import re
import zipfile
from html.parser import HTMLParser
from pathlib import Path

EXPECTED = {
    'archive': '02EBA16E643BDB37A88450D4354574C69846516F532A006AE45C371D429FE34D',
    'guide': '000D43A10E38A3FADED7A79FBD4C54054B0FAC1698583D0388C95A3A3E343BB1',
}


class TextOnly(HTMLParser):
    def __init__(self):
        super().__init__()
        self.skip = 0
        self.parts = []

    def handle_starttag(self, tag, attrs):
        if tag in ('script', 'style'):
            self.skip += 1
        if tag in ('h1', 'h2', 'h3', 'p', 'li', 'tr', 'pre'):
            self.parts.append('\n')

    def handle_endtag(self, tag):
        if tag in ('script', 'style'):
            self.skip -= 1
        if tag in ('h1', 'h2', 'h3', 'p', 'li', 'tr', 'pre'):
            self.parts.append('\n')

    def handle_data(self, text):
        if not self.skip:
            self.parts.append(text)


def digest(data):
    return hashlib.sha256(data).hexdigest().upper()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--archive', type=Path, required=True)
    parser.add_argument('--guide', type=Path, required=True)
    args = parser.parse_args()
    for kind in EXPECTED:
        if digest(getattr(args, kind).read_bytes()) != EXPECTED[kind]:
            raise ValueError('source_hash_mismatch:' + kind)
    target = Path(__file__).resolve().parents[1] / 'docs/knowledge/hkos-workbench/sources'
    target.mkdir(parents=True, exist_ok=True)
    selected = {
        'README.md': 'package-readme.txt',
        'manifest.json': 'package-manifest.json',
        'LICENSE': 'LICENSE.txt',
        'commercial/docs/COMMERCIAL_QUICK_START.md': 'commercial-quick-start.txt',
        'commercial/docs/COMMERCIAL_CONTENT_SOURCE_POLICY.md': 'content-source-policy.txt',
    }
    records = []

    def save(name, data, origin):
        path = target / name
        if path.exists() and path.read_bytes() != data:
            raise ValueError('refuse_to_overwrite_changed_source:' + name)
        path.write_bytes(data)
        records.append({'file': 'sources/' + name, 'sha256': digest(data), 'origin': origin})

    with zipfile.ZipFile(args.archive) as archive:
        for member, name in selected.items():
            save(name, archive.read('yusheng-hotel-knowledge-os/' + member), 'archive:' + member)
        bundle = archive.read('yusheng-hotel-knowledge-os/main.js').decode('utf-8')
        marker = '// src/core/controlled-source-policy.cjs\n'
        start = bundle.index(marker)
        end = bundle.index('// src/', start + len(marker))
        save('controlled-source-policy.cjs.txt', bundle[start:end].encode('utf-8'),
             'archive:main.js#src/core/controlled-source-policy.cjs')
    plain = TextOnly()
    plain.feed(args.guide.read_text(encoding='utf-8'))
    text = re.sub(r'\n\s*\n+', '\n\n', ''.join(plain.parts)).strip() + '\n'
    save('customer-guide.txt', text.encode('utf-8'), 'guide:HTML text, scripts/styles excluded')
    manifest = {
        'package_id': 'yusheng-hotel-knowledge-os', 'package_version': '1.3.56',
        'guide_version': '1.3.57', 'mapping_status': 'verified_attachment_identity',
        'source_instructions_executed': False,
        'archive_sha256': EXPECTED['archive'], 'guide_sha256': EXPECTED['guide'],
        'archive_filename': args.archive.name, 'guide_filename': args.guide.name,
        'retained_sources': records,
    }
    (target.parent / 'source-manifest.json').write_text(
        json.dumps(manifest, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    print(json.dumps({'status': 'verified', 'retained_sources': len(records)}, ensure_ascii=False))


if __name__ == '__main__':
    main()
