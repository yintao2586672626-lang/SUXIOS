<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use ZipArchive;

/**
 * Deterministically renders a verified PresentationSpec into an offline HTML
 * deck and a macro-free, editable OOXML PowerPoint. It performs no business
 * calculation and never sends or publishes an artifact externally.
 */
final class AiDailyReportPresentationRendererService
{
    public const ARTIFACT_SCHEMA_VERSION = 'suxios.ai_daily_report.presentation_artifact.v1';
    public const RENDERER_VERSION = '2026-08-24.5';

    private const ZIP_MTIME = 315532800; // 1980-01-01T00:00:00Z, the ZIP epoch.
    private const MAX_ARTIFACT_BYTES = 4_194_304;
    private const EMU_PER_INCH = 914400;
    private const SLIDE_WIDTH = 12192000;
    private const SLIDE_HEIGHT = 6858000;

    /**
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     */
    public function render(array $spec): array
    {
        $specFingerprint = $this->verifySpecFingerprint($spec);
        $slides = $this->arrayRows($spec['slides'] ?? []);
        $evidence = $this->arrayRows($spec['evidence_ledger'] ?? []);
        if ($slides === []) {
            throw new RuntimeException('presentation spec has no renderable slides');
        }

        $evidenceById = [];
        foreach ($evidence as $item) {
            $id = trim((string)($item['id'] ?? ''));
            if ($id !== '') {
                $evidenceById[$id] = $item;
            }
        }

        $baseName = $this->baseName($spec, $specFingerprint);
        $htmlName = $baseName . '.html';
        $pptxName = $baseName . '.pptx';
        $specName = 'presentation-spec.json';
        $manifestName = 'manifest.json';

        $html = $this->renderHtml($spec, $slides, $evidenceById, $specFingerprint);
        $pptx = $this->renderPptx($spec, $slides, $evidenceById, $specFingerprint);
        $specJson = $this->canonicalJson($spec);

        $manifest = [
            'schema_version' => self::ARTIFACT_SCHEMA_VERSION,
            'renderer_version' => self::RENDERER_VERSION,
            'render_status' => 'rendered',
            'source' => [
                'spec_fingerprint' => $specFingerprint,
                'spec_schema_version' => (string)($spec['schema_version'] ?? ''),
                'adapter_version' => (string)($spec['adapter_version'] ?? ''),
                'audience' => (string)($spec['deck']['audience'] ?? ''),
                'business_date' => $spec['source_report']['business_date'] ?? null,
                'data_status' => (string)($spec['deck']['data_status'] ?? 'unverified'),
            ],
            'contract' => [
                'single_spec_consumed' => true,
                'recalculation_during_render' => false,
                'cross_format_semantic_parity' => 'same_spec_fingerprint',
                'html_external_requests_allowed' => false,
                'pptx_macro_enabled' => false,
                'pptx_editable_text_and_shapes' => true,
                'external_write_authorized' => false,
                'human_review_status' => 'pending',
            ],
            'components' => [
                'html' => $this->componentDescriptor($htmlName, 'text/html; charset=utf-8', $html),
                'pptx' => $this->componentDescriptor(
                    $pptxName,
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    $pptx
                ),
                'presentation_spec' => $this->componentDescriptor(
                    $specName,
                    'application/json; charset=utf-8',
                    $specJson
                ),
            ],
            'qa' => [
                'spec_fingerprint_verified' => true,
                'html_offline_contract' => 'pass',
                'pptx_package_contract' => 'pass',
                'visual_inspection_status' => 'not_recorded_in_artifact',
            ],
        ];
        $manifestJson = $this->canonicalJson($manifest);
        $bundleName = $baseName . '-bundle.zip';
        $bundle = $this->zip([
            $htmlName => $html,
            $pptxName => $pptx,
            $specName => $specJson,
            $manifestName => $manifestJson,
        ]);

        if (strlen($bundle) > self::MAX_ARTIFACT_BYTES) {
            throw new RuntimeException('presentation artifact bundle exceeds the safe size limit');
        }

        return [
            'schema_version' => self::ARTIFACT_SCHEMA_VERSION,
            'renderer_version' => self::RENDERER_VERSION,
            'spec_fingerprint' => $specFingerprint,
            'filename' => $bundleName,
            'mime_type' => 'application/zip',
            'content_sha256' => hash('sha256', $bundle),
            'content_bytes' => strlen($bundle),
            'manifest' => $manifest,
            'manifest_json' => $manifestJson,
            'bundle' => $bundle,
        ];
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array{status:string,errors:array<int,string>}
     */
    public function verifyBundle(string $bundle, array $manifest): array
    {
        $errors = [];
        if ($bundle === '') {
            return ['status' => 'fail', 'errors' => ['bundle_empty']];
        }

        $files = $this->unzip($bundle);
        $components = is_array($manifest['components'] ?? null) ? $manifest['components'] : [];
        foreach (['html', 'pptx', 'presentation_spec'] as $componentName) {
            $component = is_array($components[$componentName] ?? null) ? $components[$componentName] : [];
            $filename = trim((string)($component['filename'] ?? ''));
            if ($filename === '' || !array_key_exists($filename, $files)) {
                $errors[] = 'bundle_component_missing:' . $componentName;
                continue;
            }
            $content = (string)$files[$filename];
            if (!hash_equals((string)($component['sha256'] ?? ''), hash('sha256', $content))) {
                $errors[] = 'bundle_component_hash_mismatch:' . $componentName;
            }
            if ((int)($component['bytes'] ?? -1) !== strlen($content)) {
                $errors[] = 'bundle_component_size_mismatch:' . $componentName;
            }
        }

        $manifestJson = $files['manifest.json'] ?? null;
        if (!is_string($manifestJson)) {
            $errors[] = 'bundle_manifest_missing';
        } else {
            $decoded = json_decode($manifestJson, true);
            if (!is_array($decoded)
                || !hash_equals(hash('sha256', $this->canonicalJson($manifest)), hash('sha256', $this->canonicalJson($decoded)))
            ) {
                $errors[] = 'bundle_manifest_mismatch';
            }
        }

        $pptxName = trim((string)($components['pptx']['filename'] ?? ''));
        if ($pptxName !== '' && isset($files[$pptxName])) {
            $pptxFiles = $this->unzip((string)$files[$pptxName]);
            foreach (['[Content_Types].xml', 'ppt/presentation.xml', 'ppt/slides/slide1.xml'] as $required) {
                if (!isset($pptxFiles[$required])) {
                    $errors[] = 'pptx_part_missing:' . $required;
                }
            }
            foreach (array_keys($pptxFiles) as $name) {
                if (str_ends_with(strtolower($name), 'vbaproject.bin')) {
                    $errors[] = 'pptx_macro_part_forbidden';
                }
            }
        }

        return [
            'status' => $errors === [] ? 'pass' : 'fail',
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /** @param array<string,mixed> $spec */
    private function verifySpecFingerprint(array $spec): string
    {
        $validation = (new AiDailyReportPresentationSpecService())->validate($spec);
        if (($validation['status'] ?? '') !== 'pass') {
            throw new RuntimeException('presentation spec validation failed before render');
        }
        $embedded = strtolower(trim((string)($spec['spec_fingerprint'] ?? '')));
        $withoutFingerprint = $spec;
        unset($withoutFingerprint['spec_fingerprint']);
        $calculated = hash('sha256', $this->canonicalJson($withoutFingerprint));
        if (!preg_match('/^[a-f0-9]{64}$/', $embedded) || !hash_equals($embedded, $calculated)) {
            throw new RuntimeException('presentation spec fingerprint verification failed before render');
        }
        return $embedded;
    }

    /**
     * @param array<string,mixed> $spec
     * @param array<int,array<string,mixed>> $slides
     * @param array<string,array<string,mixed>> $evidenceById
     */
    private function renderHtml(array $spec, array $slides, array $evidenceById, string $fingerprint): string
    {
        $renderedSlides = [];
        $total = count($slides);
        foreach ($slides as $index => $slide) {
            $role = strtoupper(trim((string)($slide['role'] ?? 'CONTENT')));
            $title = $this->text((string)($slide['title'] ?? '宿析OS经营演示'), 90);
            $message = $this->text((string)($slide['message'] ?? ''), 180);
            $rows = $this->slideEvidence($slide, $evidenceById);
            $densityClass = count($rows) >= 5 ? ' density-compact' : '';
            $number = $index + 1;
            $sourceLines = $this->slideSourceLines($slide, $rows, $fingerprint);
            $evidenceHtml = '';
            foreach ($rows as $row) {
                $class = (string)($row['class'] ?? 'UNKNOWN');
                $evidenceHtml .= '<li class="evidence-row evidence-' . $this->html($class) . '">'
                    . '<div class="evidence-meta"><span>' . $this->html($this->classLabel($class)) . '</span>'
                    . '<strong>' . $this->html($this->text((string)($row['label'] ?? ''), 42)) . '</strong></div>'
                    . '<p>' . $this->html($this->visibleStatement((string)($row['statement'] ?? ''))) . '</p>'
                    . '</li>';
            }
            if ($evidenceHtml === '') {
                $evidenceHtml = '<li class="empty-row">当前没有达到该页展示门槛的证据。</li>';
            }

            $sourcesHtml = implode('', array_map(
                fn(string $line): string => '<li>' . $this->html($line) . '</li>',
                $sourceLines
            ));
            $titleContent = $role === 'TITLE'
                ? '<div class="title-copy"><div class="eyebrow">SUXIOS · EVIDENCE-GOVERNED REPORT</div>'
                    . '<h1>' . $this->html($title) . '</h1><p class="title-message">' . $this->html($message) . '</p>'
                    . '<div class="title-status">数据状态：' . $this->html((string)($spec['deck']['data_status'] ?? 'unverified'))
                    . ' · 人工复核：待完成</div></div>'
                : '<header><div class="eyebrow">' . $this->html($this->roleLabel($role)) . '</div>'
                    . '<h2>' . $this->html($title) . '</h2><p class="message">' . $this->html($message) . '</p></header>'
                    . '<ol class="evidence-list">' . $evidenceHtml . '</ol>';

            $renderedSlides[] = '<section class="slide role-' . $this->html(strtolower($role)) . $densityClass . '" id="slide-' . $number . '" tabindex="0">'
                . '<div class="slide-inner">' . $titleContent
                . '<details class="sources"><summary>来源与边界</summary><ul>' . $sourcesHtml . '</ul></details>'
                . '<footer><span>SUXIOS</span><span>' . sprintf('%02d/%02d', $number, $total) . '</span></footer>'
                . '</div></section>';
        }

        $title = $this->html((string)($spec['deck']['title'] ?? '宿析OS AI经营日报证据演示'));
        return '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; script-src \'unsafe-inline\'; img-src data:">'
            . '<meta name="suxios-spec-fingerprint" content="' . $this->html($fingerprint) . '">'
            . '<title>' . $title . '</title><style>' . $this->htmlCss() . '</style></head><body>'
            . '<main class="deck">' . implode('', $renderedSlides) . '</main>'
            . '<nav aria-label="幻灯片导航"><button type="button" id="prev" aria-label="上一页">←</button>'
            . '<span id="position">01/' . sprintf('%02d', $total) . '</span>'
            . '<button type="button" id="next" aria-label="下一页">→</button></nav>'
            . '<script>' . $this->htmlScript() . '</script></body></html>';
    }

    private function htmlCss(): string
    {
        return <<<'CSS'
:root{color-scheme:light;--ink:#10231d;--muted:#64748b;--deep:#06110d;--green:#315d50;--gold:#a88a52;--paper:#f7f6f1;--line:#dfe4df;--danger:#9a4d48}*{box-sizing:border-box}html,body{margin:0;background:#111b17;color:var(--ink);font-family:"Microsoft YaHei","PingFang SC","Segoe UI",system-ui,sans-serif}.deck{display:flex;min-height:100vh;overflow-x:auto;scroll-snap-type:x mandatory;scroll-behavior:smooth}.slide{position:relative;flex:0 0 100vw;min-height:100vh;scroll-snap-align:start;background:var(--paper);padding:3.5vh 4vw}.slide-inner{position:relative;width:min(92vw,160vh);aspect-ratio:16/9;max-height:92vh;margin:0 auto;background:#fff;overflow:hidden;padding:5.5% 6% 4.2%;box-shadow:0 28px 80px rgba(0,0,0,.28)}.role-title .slide-inner{background:linear-gradient(135deg,#06110d 0%,#10231d 72%,#143a31 100%);color:#f8fafc}.title-copy{position:absolute;left:8%;right:8%;top:22%;border-top:3px solid var(--gold);padding-top:4%}.eyebrow{font-size:clamp(12px,1.05vw,17px);font-weight:700;letter-spacing:.16em;color:var(--gold);text-transform:uppercase}h1{max-width:88%;margin:1.4rem 0 1rem;font-size:clamp(42px,4.2vw,72px);line-height:1.12;letter-spacing:-.025em}h2{max-width:92%;margin:.65rem 0 .8rem;font-size:clamp(32px,3vw,52px);line-height:1.15;letter-spacing:-.02em}.title-message,.message{max-width:84%;font-size:clamp(18px,1.65vw,28px);line-height:1.55}.title-message{color:#d9e3de}.message{color:#40584e;margin:0}.title-status{margin-top:2rem;font-size:clamp(12px,1vw,16px);color:#b8c8c0}.evidence-list{list-style:none;margin:3.1% 0 0;padding:0;border-top:1px solid var(--line)}.evidence-row{display:grid;grid-template-columns:25% 1fr;gap:3%;align-items:start;padding:1.5% 0;border-bottom:1px solid var(--line)}.density-compact .evidence-row{padding:1.05% 0}.evidence-meta{display:flex;flex-direction:column;gap:.42rem}.evidence-meta span{font-size:clamp(10px,.82vw,13px);font-weight:700;letter-spacing:.08em;color:var(--green)}.evidence-meta strong{font-size:clamp(15px,1.25vw,20px);line-height:1.35}.evidence-row p{margin:0;font-size:clamp(14px,1.25vw,20px);line-height:1.55;color:#33463e}.evidence-DERIVED_METRIC .evidence-meta span{color:#3b6c8e}.evidence-PROFESSIONAL_JUDGMENT .evidence-meta span,.evidence-ACTION_RECOMMENDATION .evidence-meta span{color:var(--gold)}.evidence-HUMAN_DECISION .evidence-meta span{color:#6e5687}.evidence-UNKNOWN .evidence-meta span,.evidence-MOCK .evidence-meta span{color:var(--danger)}.empty-row{padding:4% 0;color:var(--muted);font-size:clamp(16px,1.4vw,22px)}.sources{position:absolute;left:6%;right:6%;bottom:7.2%;font-size:clamp(9px,.72vw,12px);color:var(--muted)}.sources summary{cursor:pointer;font-weight:700}.sources ul{max-height:8vh;overflow:auto;margin:.5rem 0 0;padding-left:1.2rem}.sources li{margin:.18rem 0}footer{position:absolute;left:6%;right:6%;bottom:3.1%;display:flex;justify-content:space-between;border-top:1px solid rgba(100,116,139,.28);padding-top:.7%;font-size:clamp(10px,.75vw,12px);font-weight:700;letter-spacing:.08em;color:var(--muted)}.role-title footer{color:#b8c8c0;border-color:rgba(220,197,145,.32)}nav{position:fixed;z-index:3;right:2rem;bottom:1.25rem;display:flex;align-items:center;gap:.65rem;padding:.5rem .65rem;border:1px solid rgba(220,197,145,.35);border-radius:999px;background:rgba(6,17,13,.9);color:#fff;box-shadow:0 8px 30px rgba(0,0,0,.25)}nav button{width:2.3rem;height:2.3rem;border:0;border-radius:50%;background:#f8fafc;color:#06110d;font-size:1.1rem;cursor:pointer}nav span{min-width:4.2rem;text-align:center;font-size:.78rem;letter-spacing:.08em}@media(max-width:720px){.slide{padding:0}.slide-inner{width:100vw;height:100vh;max-height:none;aspect-ratio:auto;padding:9vh 7vw 8vh}.evidence-row{grid-template-columns:1fr;gap:.4rem}.density-compact .evidence-row{padding:.7rem 0}.sources{bottom:7.5vh}footer{bottom:3.5vh}nav{right:1rem;bottom:.8rem}}@media print{html,body{background:#fff}.deck{display:block}.slide{width:13.333in;height:7.5in;min-height:0;padding:0;page-break-after:always}.slide-inner{width:13.333in;height:7.5in;max-height:none;box-shadow:none}nav{display:none}}
CSS;
    }

    private function htmlScript(): string
    {
        return <<<'JS'
(()=>{const slides=[...document.querySelectorAll('.slide')],position=document.getElementById('position');let active=0;const total=slides.length;const go=index=>{active=Math.max(0,Math.min(total-1,index));slides[active].scrollIntoView({behavior:'smooth',inline:'start'});position.textContent=String(active+1).padStart(2,'0')+'/'+String(total).padStart(2,'0')};document.getElementById('prev').addEventListener('click',()=>go(active-1));document.getElementById('next').addEventListener('click',()=>go(active+1));document.addEventListener('keydown',event=>{if(event.key==='ArrowLeft'||event.key==='PageUp')go(active-1);if(event.key==='ArrowRight'||event.key==='PageDown'||event.key===' ')go(active+1)});const observer=new IntersectionObserver(entries=>{for(const entry of entries){if(entry.isIntersecting&&entry.intersectionRatio>.55){active=slides.indexOf(entry.target);position.textContent=String(active+1).padStart(2,'0')+'/'+String(total).padStart(2,'0')}}},{threshold:[.55]});slides.forEach(slide=>observer.observe(slide));})();
JS;
    }

    /**
     * @param array<string,mixed> $spec
     * @param array<int,array<string,mixed>> $slides
     * @param array<string,array<string,mixed>> $evidenceById
     */
    private function renderPptx(array $spec, array $slides, array $evidenceById, string $fingerprint): string
    {
        $slideCount = count($slides);
        $files = [
            '[Content_Types].xml' => $this->contentTypesXml($slideCount),
            '_rels/.rels' => $this->rootRelationshipsXml(),
            'docProps/app.xml' => $this->appPropertiesXml($slideCount),
            'docProps/core.xml' => $this->corePropertiesXml($spec),
            'ppt/presentation.xml' => $this->presentationXml($slideCount),
            'ppt/_rels/presentation.xml.rels' => $this->presentationRelationshipsXml($slideCount),
            'ppt/presProps.xml' => $this->presPropsXml(),
            'ppt/viewProps.xml' => $this->viewPropsXml(),
            'ppt/tableStyles.xml' => $this->tableStylesXml(),
            'ppt/theme/theme1.xml' => $this->themeXml(),
            'ppt/slideMasters/slideMaster1.xml' => $this->slideMasterXml(),
            'ppt/slideMasters/_rels/slideMaster1.xml.rels' => $this->slideMasterRelationshipsXml(),
            'ppt/slideLayouts/slideLayout1.xml' => $this->slideLayoutXml(),
            'ppt/slideLayouts/_rels/slideLayout1.xml.rels' => $this->slideLayoutRelationshipsXml(),
            'ppt/notesMasters/notesMaster1.xml' => $this->notesMasterXml(),
            'ppt/notesMasters/_rels/notesMaster1.xml.rels' => $this->notesMasterRelationshipsXml(),
        ];

        foreach ($slides as $index => $slide) {
            $number = $index + 1;
            $rows = $this->slideEvidence($slide, $evidenceById);
            $files['ppt/slides/slide' . $number . '.xml'] = $this->slideXml(
                $spec,
                $slide,
                $rows,
                $number,
                $slideCount
            );
            $files['ppt/slides/_rels/slide' . $number . '.xml.rels'] = $this->slideRelationshipsXml($number);
            $files['ppt/notesSlides/notesSlide' . $number . '.xml'] = $this->notesSlideXml(
                $this->slideSourceLines($slide, $rows, $fingerprint)
            );
            $files['ppt/notesSlides/_rels/notesSlide' . $number . '.xml.rels'] = $this->notesSlideRelationshipsXml($number);
        }

        return $this->zip($files);
    }

    /**
     * @param array<string,mixed> $spec
     * @param array<string,mixed> $slide
     * @param array<int,array<string,mixed>> $rows
     */
    private function slideXml(array $spec, array $slide, array $rows, int $number, int $total): string
    {
        $role = strtoupper(trim((string)($slide['role'] ?? 'CONTENT')));
        $dark = $role === 'TITLE';
        $background = $dark ? '06110D' : 'F7F6F1';
        $titleColor = $dark ? 'F8FAFC' : '10231D';
        $secondaryColor = $dark ? 'D9E3DE' : '40584E';
        $shapes = [];
        $shapeId = 2;

        if ($dark) {
            $shapes[] = $this->textBox(
                $shapeId++,
                'Brand eyebrow',
                .95,
                1.2,
                8.5,
                .35,
                'SUXIOS · EVIDENCE-GOVERNED REPORT',
                14,
                'A88A52',
                true
            );
            $shapes[] = $this->lineShape($shapeId++, 'Gold rule', .95, 1.75, 1.5, 'A88A52', 2.5);
            $shapes[] = $this->textBox(
                $shapeId++,
                'Deck title',
                .95,
                2.05,
                11.25,
                1.72,
                $this->text((string)($slide['title'] ?? $spec['deck']['title'] ?? ''), 70),
                50,
                $titleColor,
                true,
                'l',
                'ctr'
            );
            $shapes[] = $this->textBox(
                $shapeId++,
                'Deck purpose',
                .95,
                3.8,
                11.35,
                .82,
                $this->text((string)($slide['message'] ?? ''), 150),
                22,
                $secondaryColor,
                false
            );
            $status = '数据状态：' . (string)($spec['deck']['data_status'] ?? 'unverified') . ' · 人工复核：待完成';
            $shapes[] = $this->textBox($shapeId++, 'Data status', .95, 5.15, 8.5, .4, $status, 16, 'B8C8C0');
        } else {
            $shapes[] = $this->textBox(
                $shapeId++,
                'Role eyebrow',
                .72,
                .46,
                4.5,
                .28,
                $this->roleLabel($role),
                13,
                'A88A52',
                true
            );
            $shapes[] = $this->textBox(
                $shapeId++,
                'Slide title',
                .72,
                .79,
                11.8,
                .64,
                $this->text((string)($slide['title'] ?? ''), 44),
                35,
                $titleColor,
                true
            );
            $shapes[] = $this->textBox(
                $shapeId++,
                'Takeaway',
                .72,
                1.48,
                11.55,
                .62,
                $this->text((string)($slide['message'] ?? ''), 150),
                22,
                $secondaryColor
            );
            $shapes[] = $this->lineShape($shapeId++, 'Section rule', .72, 2.13, 11.88, 'D7DDD8', 1.0);

            if ($rows === []) {
                $shapes[] = $this->textBox(
                    $shapeId++,
                    'Empty evidence state',
                    .72,
                    2.65,
                    10.6,
                    .7,
                    '当前没有达到该页展示门槛的证据。',
                    20,
                    '64748B'
                );
            } else {
                $rowHeight = .77;
                foreach (array_slice($rows, 0, 5) as $rowIndex => $row) {
                    $y = 2.35 + ($rowIndex * $rowHeight);
                    $class = (string)($row['class'] ?? 'UNKNOWN');
                    $accent = $this->classColor($class);
                    $shapes[] = $this->textBox(
                        $shapeId++,
                        'Evidence class ' . ($rowIndex + 1),
                        .72,
                        $y,
                        2.35,
                        .72,
                        $this->classLabel($class) . "\n" . $this->text((string)($row['label'] ?? ''), 32),
                        14,
                        $accent,
                        true
                    );
                    $shapes[] = $this->textBox(
                        $shapeId++,
                        'Evidence statement ' . ($rowIndex + 1),
                        3.15,
                        $y,
                        9.08,
                        .72,
                        $this->visibleStatement((string)($row['statement'] ?? '')),
                        16,
                        '33463E'
                    );
                    if ($rowIndex < count($rows) - 1) {
                        $shapes[] = $this->lineShape(
                            $shapeId++,
                            'Evidence divider ' . ($rowIndex + 1),
                            .72,
                            $y + .74,
                            11.5,
                            'E2E6E2',
                            .6
                        );
                    }
                }
            }
        }

        $footerColor = $dark ? 'B8C8C0' : '64748B';
        $shapes[] = $this->lineShape($shapeId++, 'Footer rule', .72, 7.03, 11.88, $dark ? '6F826F' : 'D7DDD8', .8);
        $shapes[] = $this->textBox($shapeId++, 'Footer brand', .72, 7.09, 2.0, .22, 'SUXIOS', 10, $footerColor, true);
        $shapes[] = $this->textBox(
            $shapeId++,
            'Footer page',
            11.6,
            7.09,
            .98,
            .22,
            sprintf('%02d/%02d', $number, $total),
            10,
            $footerColor,
            true,
            'r'
        );

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            . '<p:cSld><p:bg><p:bgPr><a:solidFill><a:srgbClr val="' . $background . '"/></a:solidFill><a:effectLst/></p:bgPr></p:bg>'
            . '<p:spTree>' . $this->groupRootXml() . implode('', $shapes) . '</p:spTree></p:cSld>'
            . '<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>';
    }

    private function textBox(
        int $id,
        string $name,
        float $x,
        float $y,
        float $w,
        float $h,
        string $text,
        int $fontSize,
        string $color,
        bool $bold = false,
        string $align = 'l',
        string $vertical = 't'
    ): string {
        $paragraphs = preg_split('/\R/u', $this->xmlText($text)) ?: [''];
        $paragraphXml = '';
        foreach ($paragraphs as $paragraph) {
            $paragraphXml .= '<a:p><a:pPr algn="' . $align . '"/>'
                . '<a:r><a:rPr lang="zh-CN" sz="' . ($fontSize * 100) . '"'
                . ($bold ? ' b="1"' : '') . '><a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill>'
                . '<a:latin typeface="Microsoft YaHei"/><a:ea typeface="Microsoft YaHei"/></a:rPr>'
                . '<a:t>' . $paragraph . '</a:t></a:r>'
                . '<a:endParaRPr lang="zh-CN" sz="' . ($fontSize * 100) . '"/></a:p>';
        }
        return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="' . $this->xml($name) . '"/>'
            . '<p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr><p:spPr><a:xfrm>'
            . '<a:off x="' . $this->emu($x) . '" y="' . $this->emu($y) . '"/>'
            . '<a:ext cx="' . $this->emu($w) . '" cy="' . $this->emu($h) . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/><a:ln><a:noFill/></a:ln></p:spPr>'
            . '<p:txBody><a:bodyPr wrap="square" anchor="' . $vertical . '" lIns="0" rIns="0" tIns="0" bIns="0"/>'
            . '<a:lstStyle/>' . $paragraphXml . '</p:txBody></p:sp>';
    }

    private function lineShape(
        int $id,
        string $name,
        float $x,
        float $y,
        float $width,
        string $color,
        float $points
    ): string {
        return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="' . $this->xml($name) . '"/>'
            . '<p:cNvSpPr/><p:nvPr/></p:nvSpPr><p:spPr><a:xfrm>'
            . '<a:off x="' . $this->emu($x) . '" y="' . $this->emu($y) . '"/>'
            . '<a:ext cx="' . $this->emu($width) . '" cy="0"/></a:xfrm>'
            . '<a:prstGeom prst="line"><a:avLst/></a:prstGeom><a:noFill/>'
            . '<a:ln w="' . (int)round($points * 12700) . '"><a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill>'
            . '<a:prstDash val="solid"/></a:ln></p:spPr></p:sp>';
    }

    private function groupRootXml(): string
    {
        return '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/>'
            . '<a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>';
    }

    private function contentTypesXml(int $slideCount): string
    {
        $overrides = '';
        for ($i = 1; $i <= $slideCount; $i++) {
            $overrides .= '<Override PartName="/ppt/slides/slide' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
            $overrides .= '<Override PartName="/ppt/notesSlides/notesSlide' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.notesSlide+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
            . '<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>'
            . '<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>'
            . '<Override PartName="/ppt/notesMasters/notesMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.notesMaster+xml"/>'
            . '<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
            . '<Override PartName="/ppt/presProps.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presProps+xml"/>'
            . '<Override PartName="/ppt/viewProps.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.viewProps+xml"/>'
            . '<Override PartName="/ppt/tableStyles.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.tableStyles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . $overrides . '</Types>';
    }

    private function rootRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function presentationXml(int $slideCount): string
    {
        $slides = '';
        for ($i = 1; $i <= $slideCount; $i++) {
            $slides .= '<p:sldId id="' . (255 + $i) . '" r:id="rId' . ($i + 1) . '"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            . '<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>'
            . '<p:notesMasterIdLst><p:notesMasterId r:id="rId' . ($slideCount + 2) . '"/></p:notesMasterIdLst>'
            . '<p:sldIdLst>' . $slides . '</p:sldIdLst>'
            . '<p:sldSz cx="' . self::SLIDE_WIDTH . '" cy="' . self::SLIDE_HEIGHT . '" type="screen16x9"/>'
            . '<p:notesSz cx="6858000" cy="9144000"/>'
            . '<p:defaultTextStyle><a:defPPr><a:defRPr lang="zh-CN"/></a:defPPr></p:defaultTextStyle>'
            . '</p:presentation>';
    }

    private function presentationRelationshipsXml(int $slideCount): string
    {
        $rels = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>';
        for ($i = 1; $i <= $slideCount; $i++) {
            $rels .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide' . $i . '.xml"/>';
        }
        $next = $slideCount + 2;
        $rels .= '<Relationship Id="rId' . $next++ . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesMaster" Target="notesMasters/notesMaster1.xml"/>';
        $rels .= '<Relationship Id="rId' . $next++ . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/presProps" Target="presProps.xml"/>';
        $rels .= '<Relationship Id="rId' . $next++ . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/viewProps" Target="viewProps.xml"/>';
        $rels .= '<Relationship Id="rId' . $next . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/tableStyles" Target="tableStyles.xml"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels . '</Relationships>';
    }

    private function slideRelationshipsXml(int $number): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesSlide" Target="../notesSlides/notesSlide' . $number . '.xml"/>'
            . '</Relationships>';
    }

    /** @param array<int,string> $sourceLines */
    private function notesSlideXml(array $sourceLines): string
    {
        $paragraphs = [];
        foreach ($sourceLines as $line) {
            $paragraphs[] = $this->xmlText($line);
        }
        $runs = '';
        foreach ($paragraphs as $line) {
            $runs .= '<a:p><a:r><a:rPr lang="zh-CN" sz="1200"><a:latin typeface="Microsoft YaHei"/><a:ea typeface="Microsoft YaHei"/></a:rPr><a:t>'
                . $line . '</a:t></a:r><a:endParaRPr lang="zh-CN" sz="1200"/></a:p>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:notes xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"><p:cSld><p:spTree>'
            . $this->groupRootXml()
            . '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Speaker notes"/><p:cNvSpPr txBox="1"/><p:nvPr><p:ph type="body" idx="1"/></p:nvPr></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="685800" y="914400"/><a:ext cx="5486400" cy="7315200"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/><a:ln><a:noFill/></a:ln></p:spPr>'
            . '<p:txBody><a:bodyPr/><a:lstStyle/>' . $runs . '</p:txBody></p:sp>'
            . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:notes>';
    }

    private function notesSlideRelationshipsXml(int $number): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesMaster" Target="../notesMasters/notesMaster1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="../slides/slide' . $number . '.xml"/>'
            . '</Relationships>';
    }

    private function slideMasterXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:sldMaster xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            . '<p:cSld name="SUXIOS Blank"><p:spTree>' . $this->groupRootXml() . '</p:spTree></p:cSld>'
            . '<p:clrMap accent1="315D50" accent2="A88A52" accent3="3B6C8E" accent4="6E5687" accent5="9A4D48" accent6="64748B" bg1="F7F6F1" bg2="E7ECE8" dk1="06110D" dk2="10231D" folHlink="6E5687" hlink="3B6C8E" lt1="FFFFFF" lt2="F7F6F1" tx1="10231D" tx2="40584E"/>'
            . '<p:sldLayoutIdLst><p:sldLayoutId id="1" r:id="rId1"/></p:sldLayoutIdLst>'
            . '<p:txStyles><p:titleStyle><a:lvl1pPr algn="l"><a:defRPr sz="3500" b="1"/></a:lvl1pPr></p:titleStyle>'
            . '<p:bodyStyle><a:lvl1pPr marL="0" indent="0"><a:defRPr sz="1600"/></a:lvl1pPr></p:bodyStyle>'
            . '<p:otherStyle><a:defPPr><a:defRPr lang="zh-CN"/></a:defPPr></p:otherStyle></p:txStyles>'
            . '</p:sldMaster>';
    }

    private function slideMasterRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>'
            . '</Relationships>';
    }

    private function slideLayoutXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:sldLayout xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" type="blank" preserve="1">'
            . '<p:cSld name="Blank"><p:spTree>' . $this->groupRootXml() . '</p:spTree></p:cSld>'
            . '<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>';
    }

    private function slideLayoutRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>'
            . '</Relationships>';
    }

    private function notesMasterXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:notesMaster xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            . '<p:cSld name="SUXIOS Notes"><p:spTree>' . $this->groupRootXml() . '</p:spTree></p:cSld>'
            . '<p:clrMap accent1="315D50" accent2="A88A52" accent3="3B6C8E" accent4="6E5687" accent5="9A4D48" accent6="64748B" bg1="FFFFFF" bg2="F7F6F1" dk1="06110D" dk2="10231D" folHlink="6E5687" hlink="3B6C8E" lt1="FFFFFF" lt2="F7F6F1" tx1="10231D" tx2="40584E"/>'
            . '<p:hf hdr="0" ftr="0" dt="0" sldNum="0"/><p:notesStyle><a:lvl1pPr marL="0" indent="0"><a:defRPr sz="1200"/></a:lvl1pPr></p:notesStyle>'
            . '</p:notesMaster>';
    }

    private function notesMasterRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>'
            . '</Relationships>';
    }

    private function themeXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="SUXIOS Evidence">'
            . '<a:themeElements><a:clrScheme name="SUXIOS"><a:dk1><a:srgbClr val="06110D"/></a:dk1><a:lt1><a:srgbClr val="FFFFFF"/></a:lt1>'
            . '<a:dk2><a:srgbClr val="10231D"/></a:dk2><a:lt2><a:srgbClr val="F7F6F1"/></a:lt2>'
            . '<a:accent1><a:srgbClr val="315D50"/></a:accent1><a:accent2><a:srgbClr val="A88A52"/></a:accent2>'
            . '<a:accent3><a:srgbClr val="3B6C8E"/></a:accent3><a:accent4><a:srgbClr val="6E5687"/></a:accent4>'
            . '<a:accent5><a:srgbClr val="9A4D48"/></a:accent5><a:accent6><a:srgbClr val="64748B"/></a:accent6>'
            . '<a:hlink><a:srgbClr val="3B6C8E"/></a:hlink><a:folHlink><a:srgbClr val="6E5687"/></a:folHlink></a:clrScheme>'
            . '<a:fontScheme name="SUXIOS"><a:majorFont><a:latin typeface="Microsoft YaHei"/><a:ea typeface="Microsoft YaHei"/><a:cs typeface="Arial"/></a:majorFont>'
            . '<a:minorFont><a:latin typeface="Microsoft YaHei"/><a:ea typeface="Microsoft YaHei"/><a:cs typeface="Arial"/></a:minorFont></a:fontScheme>'
            . '<a:fmtScheme name="SUXIOS"><a:fillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
            . '<a:gradFill rotWithShape="1"><a:gsLst><a:gs pos="0"><a:schemeClr val="phClr"><a:tint val="50000"/><a:satMod val="300000"/></a:schemeClr></a:gs><a:gs pos="100000"><a:schemeClr val="phClr"><a:shade val="100000"/><a:satMod val="200000"/></a:schemeClr></a:gs></a:gsLst><a:lin ang="16200000" scaled="1"/></a:gradFill>'
            . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:fillStyleLst>'
            . '<a:lnStyleLst><a:ln w="9525" cap="flat" cmpd="sng" algn="ctr"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:prstDash val="solid"/></a:ln>'
            . '<a:ln w="25400" cap="flat" cmpd="sng" algn="ctr"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:prstDash val="solid"/></a:ln>'
            . '<a:ln w="38100" cap="flat" cmpd="sng" algn="ctr"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:prstDash val="solid"/></a:ln></a:lnStyleLst>'
            . '<a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst>'
            . '<a:bgFillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"><a:tint val="95000"/><a:satMod val="170000"/></a:schemeClr></a:solidFill><a:gradFill rotWithShape="1"><a:gsLst><a:gs pos="0"><a:schemeClr val="phClr"><a:tint val="93000"/><a:satMod val="150000"/><a:shade val="98000"/><a:lumMod val="102000"/></a:schemeClr></a:gs><a:gs pos="50000"><a:schemeClr val="phClr"><a:tint val="98000"/><a:satMod val="130000"/><a:shade val="90000"/><a:lumMod val="103000"/></a:schemeClr></a:gs><a:gs pos="100000"><a:schemeClr val="phClr"><a:shade val="63000"/><a:satMod val="120000"/></a:schemeClr></a:gs></a:gsLst><a:lin ang="16200000" scaled="1"/></a:gradFill></a:bgFillStyleLst>'
            . '</a:fmtScheme></a:themeElements></a:theme>';
    }

    private function appPropertiesXml(int $slideCount): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>SUXIOS</Application><PresentationFormat>Widescreen</PresentationFormat><Slides>' . $slideCount . '</Slides><Notes>' . $slideCount . '</Notes><HiddenSlides>0</HiddenSlides><MMClips>0</MMClips><ScaleCrop>false</ScaleCrop><Company>SUXIOS</Company><AppVersion>1.0</AppVersion></Properties>';
    }

    /** @param array<string,mixed> $spec */
    private function corePropertiesXml(array $spec): string
    {
        $title = $this->xml((string)($spec['deck']['title'] ?? '宿析OS AI经营日报证据演示'));
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . $title . '</dc:title><dc:creator>SUXIOS</dc:creator><cp:lastModifiedBy>SUXIOS</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">2000-01-01T00:00:00Z</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">2000-01-01T00:00:00Z</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private function presPropsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:presentationPr xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>';
    }

    private function viewPropsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:viewPr xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" lastView="sldView"/>';
    }

    private function tableStylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><a:tblStyleLst xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" def="{5C22544A-7EE6-4342-B048-85BDC9FD1C3A}"/>';
    }

    /**
     * @param array<string,mixed> $slide
     * @param array<string,array<string,mixed>> $evidenceById
     * @return array<int,array<string,mixed>>
     */
    private function slideEvidence(array $slide, array $evidenceById): array
    {
        $rows = [];
        foreach ((array)($slide['evidence_ids'] ?? []) as $id) {
            $id = (string)$id;
            if (isset($evidenceById[$id])) {
                $rows[] = $evidenceById[$id];
            }
        }
        return $rows;
    }

    /**
     * @param array<string,mixed> $slide
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,string>
     */
    private function slideSourceLines(array $slide, array $rows, string $fingerprint): array
    {
        $lines = [
            '[Sources]',
            'PresentationSpec SHA-256: ' . $fingerprint,
            $this->text((string)($slide['source_note'] ?? '来源边界未提供。'), 300),
        ];
        foreach ($rows as $row) {
            $id = (string)($row['id'] ?? 'evidence');
            $gapCode = trim((string)($row['gap_code'] ?? ''));
            $lines[] = '- ' . $id . ' [' . (string)($row['class'] ?? 'UNKNOWN') . ']'
                . ($gapCode !== '' ? ' code=' . $this->text($gapCode, 120) : '') . ' '
                . $this->text((string)($row['statement'] ?? ''), 500);
            foreach (array_values(array_filter((array)($row['source_refs'] ?? []), 'is_string')) as $sourceRef) {
                $lines[] = '  - ref: ' . $this->text((string)$sourceRef, 240);
            }
        }
        return $lines;
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            'TITLE' => '经营日报证据演示',
            'EXECUTIVE_SUMMARY' => '已核验事实与派生指标',
            'DIAGNOSIS' => '辅助判断',
            'DECISION' => '待人工确认动作',
            'GAP' => '证据缺口',
            'METHOD' => '口径与方法',
            default => '经营证据',
        };
    }

    private function classLabel(string $class): string
    {
        return match ($class) {
            'VERIFIED_FACT' => '已核验事实',
            'DERIVED_METRIC' => '派生指标',
            'PROFESSIONAL_JUDGMENT' => '辅助判断',
            'ACTION_RECOMMENDATION' => '待确认动作',
            'HUMAN_DECISION' => '人工决定',
            'MOCK' => '模拟资料',
            default => '缺失 / 未核验',
        };
    }

    private function classColor(string $class): string
    {
        return match ($class) {
            'VERIFIED_FACT' => '315D50',
            'DERIVED_METRIC' => '3B6C8E',
            'PROFESSIONAL_JUDGMENT', 'ACTION_RECOMMENDATION' => 'A88A52',
            'HUMAN_DECISION' => '6E5687',
            default => '9A4D48',
        };
    }

    /** @param array<string,mixed> $spec */
    private function baseName(array $spec, string $fingerprint): string
    {
        $audience = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($spec['deck']['audience'] ?? 'owner')));
        $audience = $audience !== '' ? $audience : 'owner';
        $date = (string)($spec['source_report']['business_date'] ?? '');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : 'training-case';
        $rendererTag = preg_replace('/[^a-z0-9]+/', '-', strtolower(self::RENDERER_VERSION));
        return 'suxios-ai-daily-' . $audience . '-' . $date . '-' . substr($fingerprint, 0, 12)
            . '-r' . trim((string)$rendererTag, '-');
    }

    /** @return array{filename:string,mime_type:string,sha256:string,bytes:int,status:string} */
    private function componentDescriptor(string $filename, string $mimeType, string $content): array
    {
        return [
            'filename' => $filename,
            'mime_type' => $mimeType,
            'sha256' => hash('sha256', $content),
            'bytes' => strlen($content),
            'status' => 'rendered',
        ];
    }

    /** @param array<string,string> $files */
    private function zip(array $files): string
    {
        if (!class_exists(ZipArchive::class) || !method_exists(ZipArchive::class, 'setMtimeName')) {
            throw new RuntimeException('ZipArchive with deterministic timestamp support is required');
        }
        $path = tempnam(sys_get_temp_dir(), 'suxios-presentation-');
        if (!is_string($path) || $path === '') {
            throw new RuntimeException('unable to allocate presentation archive');
        }

        $zip = new ZipArchive();
        $opened = false;
        try {
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('unable to create presentation archive');
            }
            $opened = true;
            ksort($files, SORT_STRING);
            foreach ($files as $name => $content) {
                if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/') || str_starts_with($name, '\\')) {
                    throw new RuntimeException('presentation archive path is invalid');
                }
                if (!$zip->addFromString($name, $content)
                    || !$zip->setCompressionName($name, ZipArchive::CM_DEFLATE)
                    || !$zip->setMtimeName($name, self::ZIP_MTIME)
                ) {
                    throw new RuntimeException('unable to add presentation archive entry');
                }
            }
            if (!$zip->close()) {
                throw new RuntimeException('unable to finalize presentation archive');
            }
            $opened = false;
            $content = file_get_contents($path);
            if (!is_string($content) || $content === '') {
                throw new RuntimeException('presentation archive readback failed');
            }
            return $content;
        } finally {
            if ($opened) {
                $zip->close();
            }
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /** @return array<string,string> */
    private function unzip(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'suxios-presentation-read-');
        if (!is_string($path) || $path === '' || file_put_contents($path, $content, LOCK_EX) !== strlen($content)) {
            throw new RuntimeException('presentation archive verification setup failed');
        }
        $zip = new ZipArchive();
        $opened = false;
        try {
            if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
                throw new RuntimeException('presentation archive is invalid');
            }
            $opened = true;
            $files = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat)) {
                    throw new RuntimeException('presentation archive entry metadata is invalid');
                }
                $name = (string)($stat['name'] ?? '');
                $size = (int)($stat['size'] ?? -1);
                if ($name === '' || str_contains($name, '..') || $size < 0 || $size > self::MAX_ARTIFACT_BYTES) {
                    throw new RuntimeException('presentation archive entry is unsafe');
                }
                $entry = $zip->getFromIndex($index);
                if (!is_string($entry) || strlen($entry) !== $size) {
                    throw new RuntimeException('presentation archive entry readback failed');
                }
                $files[$name] = $entry;
            }
            return $files;
        } finally {
            if ($opened) {
                $zip->close();
            }
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function visibleStatement(string $value): string
    {
        $value = $this->text($value, 500);
        return mb_strlen($value) > 78 ? mb_substr($value, 0, 77) . '…' : $value;
    }

    private function text(string $value, int $maxLength): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        $value = preg_replace('/(?<=[A-Za-z0-9])\s+(?=\p{Han})/u', '', $value) ?? $value;
        $value = preg_replace('/(?<=\p{Han})\s+(?=[A-Za-z0-9])/u', '', $value) ?? $value;
        return mb_substr($value, 0, max(1, $maxLength));
    }

    private function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private function xml(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
    }

    private function xmlText(string $value): string
    {
        return $this->xml($value);
    }

    private function emu(float $inches): int
    {
        return (int)round($inches * self::EMU_PER_INCH);
    }

    /** @return array<int,array<string,mixed>> */
    private function arrayRows(mixed $value): array
    {
        return array_values(array_filter(is_array($value) ? $value : [], 'is_array'));
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
