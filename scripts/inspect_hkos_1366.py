"""Read the user-provided release archive without installing or executing it."""
import argparse
import hashlib
import json
import re
import zipfile
from html.parser import HTMLParser
from pathlib import Path, PurePosixPath

EXPECTED = 'D73EBA08A59021ED1CB0479273C1AE1635C70306471B545F9538F4959BDA0DA2'
BASE = Path(__file__).resolve().parents[1] / 'docs/knowledge/hkos-workbench-v1.3.66'


class PlainText(HTMLParser):
    def __init__(self):
        super().__init__()
        self.skipping = 0
        self.parts = []

    def handle_starttag(self, tag, attrs):
        if tag in ('script', 'style'):
            self.skipping += 1
        if tag in ('h1', 'h2', 'h3', 'h4', 'p', 'li', 'tr', 'br', 'pre'):
            self.parts.append('\n')

    def handle_endtag(self, tag):
        if tag in ('script', 'style'):
            self.skipping -= 1

    def handle_data(self, data):
        if not self.skipping:
            self.parts.append(data)

    def text(self):
        return '\n'.join(line for line in (re.sub(r'\s+', ' ', s).strip()
                         for s in ''.join(self.parts).splitlines()) if line) + '\n'


def sha(data):
    return hashlib.sha256(data).hexdigest().upper()


def retain(name, data):
    # Refuse to overwrite a different retained artifact on a later run.
    target = BASE / name
    target.parent.mkdir(parents=True, exist_ok=True)
    if target.exists() and target.read_bytes() != data:
        raise ValueError('retained_artifact_conflict:' + name)
    target.write_bytes(data)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('archive', type=Path)
    args = parser.parse_args()
    assert sha(args.archive.read_bytes()) == EXPECTED, 'archive_identity_mismatch'
    sources = []
    with zipfile.ZipFile(args.archive) as archive:
        items = archive.infolist()
        assert len({i.filename.casefold() for i in items}) == len(items), 'duplicate_paths'
        for item in items:
            path = PurePosixPath(item.filename)
            assert not path.is_absolute() and '..' not in path.parts
            assert '\\' not in item.filename and ':' not in item.filename
            assert (item.external_attr >> 16) & 0o170000 != 0o120000
        assert sum(i.file_size for i in items) < 20_000_000
        root = 'yusheng-hotel-knowledge-os/'
        manifest = json.loads(archive.read(root + 'manifest.json'))
        assert manifest['version'] == '1.3.66'
        selected = {
            'manifest.json': 'package-manifest.json', 'LICENSE': 'LICENSE.txt',
            'DEFUDDLE-LICENSE.txt': 'DEFUDDLE-LICENSE.txt', 'README.md': 'package-readme.txt',
            'commercial/docs/COMMERCIAL_QUICK_START.md': 'commercial-quick-start.txt',
            'commercial/docs/COMMERCIAL_CONTENT_SOURCE_POLICY.md': 'content-source-policy.txt',
            'commercial/docs/CUSTOMER_GUIDE.html': 'customer-guide.txt',
            'commercial/docs/CUSTOMER_MANUAL.html': 'customer-manual.txt',
        }
        for original, destination in selected.items():
            raw = archive.read(root + original)
            text = raw.decode('utf-8-sig')
            if original.endswith('.html'):
                html = PlainText()
                html.feed(text)
                text = html.text()
            # Do not retain apparent real credential values; never print matches.
            assert not re.search(r'(?:sk-[A-Za-z0-9]{20,}|-----BEGIN (?:RSA |EC )?PRIVATE KEY-----)', text), 'sensitive_content'
            data = text.encode('utf-8')
            name = 'sources/' + destination
            retain(name, data)
            sources.append({'file': name, 'archive_entry': root + original,
                            'archive_entry_sha256': sha(raw), 'sha256': sha(data),
                            'transformation': 'html_text_without_scripts_styles' if original.endswith('.html') else 'utf8_text'})
        bundled = archive.read(root + 'main.js').decode('utf-8')
        for module in ('usage-dashboard-stats', 'usage-dashboard-telemetry', 'core/controlled-source-policy'):
            marker = '// src/' + module + '.cjs'
            start = bundled.index(marker + '\n')
            end = bundled.find('\n// ', start + len(marker))
            assert end > start
            data = (bundled[start:end].rstrip() + '\n').encode('utf-8')
            name = 'sources/' + module.split('/')[-1] + '.cjs.txt'
            retain(name, data)
            sources.append({'file': name, 'archive_entry': root + 'main.js',
                            'archive_entry_sha256': sha(archive.read(root + 'main.js')),
                            'sha256': sha(data), 'transformation': 'exact_bundled_module_fragment',
                            'module_marker': marker})
        prior = BASE.parent / 'hkos-workbench/sources'
        comparisons = []
        for name in ('commercial-quick-start.txt', 'content-source-policy.txt', 'controlled-source-policy.cjs.txt'):
            comparisons.append({'file': name, 'same_ignoring_outer_whitespace':
                (prior / name).read_text(encoding='utf-8-sig').strip()
                == (BASE / 'sources' / name).read_text(encoding='utf-8-sig').strip()})
        report = {'package_id': manifest['id'], 'package_version': manifest['version'],
                  'guide_version': '1.3.60', 'manual_version': '1.3.60',
                  'archive_filename': args.archive.name, 'archive_sha256': EXPECTED,
                  'source_provenance': 'user_provided_archive', 'reviewed_on': '2026-09-27',
                  'archive_entries': len(items), 'file_count': sum(not i.is_dir() for i in items),
                  'uncompressed_bytes': sum(i.file_size for i in items),
                  'unsafe_paths': [], 'symlinks': [], 'plugin_installed': False,
                  'plugin_executed': False, 'retained_sources': sources,
                  'comparison_to_retained_1_3_56': comparisons,
                  'inventory': [{'path': i.filename, 'bytes': i.file_size,
                                  'sha256': sha(archive.read(i))} for i in items if not i.is_dir()]}
        retain('source-manifest.json', (json.dumps(report, ensure_ascii=False, indent=2) + '\n').encode('utf-8'))
        print(json.dumps({k: v for k, v in report.items() if k not in ('inventory', 'retained_sources')}, ensure_ascii=True))


if __name__ == '__main__':
    main()
