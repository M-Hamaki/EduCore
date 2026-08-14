<?php

declare(strict_types=1);

namespace EduCore\Modules\LearningContent;

use ArPHP\I18N\Arabic as ArabicText;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;
use RuntimeException;

final class LessonExportService
{
    private const MAX_FRAGMENT_BYTES = 8_000_000;

    private const ALLOWED_SECTION_KEYS = [
        'lesson_plan',
        'question_bank',
        'visual_materials',
        'class_activities',
        'mind_maps',
        'lesson_summary',
        'educational_stories',
        'custom_content',
        'exam',
    ];

    public function prepareUniqueSections(string $fragment): string
    {
        if ($fragment === '' || strlen($fragment) > self::MAX_FRAGMENT_BYTES) {
            throw new InvalidArgumentException('Export content is empty or too large.');
        }
        if (!mb_check_encoding($fragment, 'UTF-8')) {
            throw new InvalidArgumentException('Export content must use UTF-8 encoding.');
        }
        if (!class_exists(DOMDocument::class)) {
            throw new RuntimeException('The DOM extension is required for lesson exports.');
        }

        // libxml's HTML parser otherwise assumes an ISO-8859-1-compatible
        // encoding for fragments and turns valid Arabic UTF-8 into mojibake.
        $encodedFragment = mb_encode_numericentity(
            $fragment,
            [0x80, 0x10FFFF, 0, 0xFFFFFF],
            'UTF-8'
        );
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>'
            . '<div id="lesson-export-root">' . $encodedFragment . '</div></body></html>',
            LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new InvalidArgumentException('Export content could not be parsed.');
        }

        $xpath = new DOMXPath($document);
        $root = $document->getElementById('lesson-export-root');
        if (!$root instanceof DOMElement) {
            throw new InvalidArgumentException('Export root is missing.');
        }

        $this->removeUnsafeNodes($xpath, $root);
        $this->sanitizeAttributes($xpath, $root);

        $sections = $xpath->query('./section[@data-export-key]', $root);
        if ($sections === false || $sections->length === 0) {
            throw new InvalidArgumentException('No export sections were selected.');
        }

        $seenKeys = [];
        $seenFingerprints = [];
        $unique = [];

        foreach ($sections as $section) {
            if (!$section instanceof DOMElement) {
                continue;
            }
            $key = trim($section->getAttribute('data-export-key'));
            if (!in_array($key, self::ALLOWED_SECTION_KEYS, true) || isset($seenKeys[$key])) {
                continue;
            }

            $text = preg_replace('/\s+/u', ' ', trim((string) $section->textContent));
            $html = $document->saveHTML($section);
            if ($html === false || ($text === '' && stripos($html, '<img') === false)) {
                continue;
            }

            $fingerprint = hash('sha256', $text !== '' ? $text : $html);
            if (isset($seenFingerprints[$fingerprint])) {
                continue;
            }

            $seenKeys[$key] = true;
            $seenFingerprints[$fingerprint] = true;
            $unique[] = $html;
        }

        if ($unique === []) {
            throw new InvalidArgumentException('The selected sections have no exportable content.');
        }

        return implode("\n", $unique);
    }

    public function buildDocument(string $title, string $uniqueSections): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8">'
            . '<title>' . $safeTitle . '</title>'
            . '<style>'
            . '@page{margin:22mm 16mm}*{box-sizing:border-box}'
            . 'body{font-family:"DejaVu Sans",Arial,sans-serif;direction:rtl;color:#1e293b;line-height:1.75;font-size:12px}'
            . '.lesson-export-cover{padding:22px;margin-bottom:24px;border:1px solid #a7f3d0;background:#f0fdf4;text-align:center}'
            . '.lesson-export-cover h1{margin:0;color:#166534;font-size:24px}'
            . '.lesson-export-section{page-break-before:always}.lesson-export-section:first-of-type{page-break-before:auto}'
            . '.lesson-export-section>h1{margin:0 0 18px;padding:0 0 10px;border-bottom:3px solid #2563eb;color:#1e3a8a;font-size:20px}'
            . 'table{width:100%;border-collapse:collapse;margin:14px 0;page-break-inside:auto}'
            . 'tr{page-break-inside:avoid}th,td{padding:9px;border:1px solid #cbd5e1;text-align:right;vertical-align:top}'
            . 'th{background:#e0f2fe;color:#0c4a6e}img{max-width:100%;height:auto}'
            . '.plan-item,.question-item,.question-card,.visual-item,.activity-card{padding:12px;margin:10px 0;border:1px solid #e2e8f0;background:#f8fafc;page-break-inside:avoid}'
            . '.sub-tab-content{display:block!important}.sub-tabs-container,.section-actions,.section-header-actions,.no-print{display:none!important}'
            . '</style></head><body>'
            . '<header class="lesson-export-cover"><h1>' . $safeTitle . '</h1></header>'
            . $uniqueSections
            . '</body></html>';
    }

    /**
     * @return array{payload:string, extension:string, content_type:string, download_name:string}
     */
    public function createExportArtifact(
        string $format,
        string $title,
        int $lessonId,
        string $fragment
    ): array {
        if (!in_array($format, ['html', 'word', 'pdf'], true)) {
            throw new InvalidArgumentException('Unsupported lesson export format.');
        }

        $uniqueSections = $this->prepareUniqueSections($fragment);
        $document = $this->buildDocument($title, $uniqueSections);

        if ($format === 'pdf') {
            $payload = $this->renderPdf($document);
            $extension = 'pdf';
            $contentType = 'application/pdf';
        } elseif ($format === 'word') {
            $payload = $document;
            $extension = 'doc';
            $contentType = 'application/msword; charset=utf-8';
        } else {
            $payload = $document;
            $extension = 'html';
            $contentType = 'text/html; charset=utf-8';
        }

        return [
            'payload' => $payload,
            'extension' => $extension,
            'content_type' => $contentType,
            'download_name' => self::safeFilenameBase($title, $lessonId) . '.' . $extension,
        ];
    }

    public function renderPdf(string $documentHtml): string
    {
        if (!class_exists(\Dompdf\Dompdf::class) || !class_exists(\Dompdf\Options::class)) {
            throw new RuntimeException('PDF export is unavailable.');
        }
        if (!class_exists(ArabicText::class)) {
            throw new RuntimeException('Arabic PDF text shaping is unavailable.');
        }

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', [dirname(__DIR__, 3) . '/assets']);

        $pdf = new \Dompdf\Dompdf($options);
        $pdf->loadHtml($this->shapeArabicForPdf($documentHtml), 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        return $pdf->output();
    }

    private function shapeArabicForPdf(string $documentHtml): string
    {
        if (!mb_check_encoding($documentHtml, 'UTF-8')) {
            throw new InvalidArgumentException('PDF document must use UTF-8 encoding.');
        }

        $encodedDocument = mb_encode_numericentity(
            $documentHtml,
            [0x80, 0x10FFFF, 0, 0xFFFFFF],
            'UTF-8'
        );
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($encodedDocument, LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new InvalidArgumentException('PDF document could not be parsed.');
        }

        $xpath = new DOMXPath($document);
        $textNodes = $xpath->query(
            '//body//text()[not(ancestor::script) and not(ancestor::style)]'
        );
        if ($textNodes === false) {
            throw new RuntimeException('PDF text nodes could not be inspected.');
        }

        $arabic = new ArabicText();
        foreach ($textNodes as $textNode) {
            $text = (string) $textNode->nodeValue;
            if (preg_match('/^(\s*)(.*?)(\s*)$/us', $text, $parts) !== 1) {
                continue;
            }

            $content = (string) ($parts[2] ?? '');
            if ($content === '' || preg_match('/\p{Arabic}/u', $content) !== 1) {
                continue;
            }

            $maxChars = max(50, mb_strlen($content, 'UTF-8') + 1);
            $textNode->nodeValue = (string) ($parts[1] ?? '')
                . $arabic->utf8Glyphs($content, $maxChars, false, true)
                . (string) ($parts[3] ?? '');
        }

        $shapedDocument = $document->saveHTML();
        if ($shapedDocument === false || $shapedDocument === '') {
            throw new RuntimeException('PDF document could not be serialized.');
        }

        return $shapedDocument;
    }

    public static function safeFilenameBase(string $title, int $lessonId): string
    {
        $clean = preg_replace('~[\\\\/:*?"<>|\x00-\x1F\x7F]+~u', ' ', $title);
        $clean = trim((string) preg_replace('~\s+~u', ' ', (string) $clean));
        if ($clean === '') {
            $clean = 'lesson_' . $lessonId;
        }

        return mb_substr($clean, 0, 80, 'UTF-8');
    }

    private function removeUnsafeNodes(DOMXPath $xpath, DOMElement $root): void
    {
        $nodes = $xpath->query(
            './/script|.//iframe|.//object|.//embed|.//form|.//button|.//input|.//select|.//textarea|.//link|.//meta|.//base',
            $root
        );
        if ($nodes === false) {
            return;
        }

        $remove = [];
        foreach ($nodes as $node) {
            $remove[] = $node;
        }
        foreach ($remove as $node) {
            if ($node instanceof DOMNode && $node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    private function sanitizeAttributes(DOMXPath $xpath, DOMElement $root): void
    {
        $nodes = $xpath->query('.//*', $root);
        if ($nodes === false) {
            return;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement || !$node->hasAttributes()) {
                continue;
            }

            $attributes = [];
            foreach ($node->attributes as $attribute) {
                $attributes[] = [$attribute->name, $attribute->value];
            }
            foreach ($attributes as [$name, $value]) {
                $lowerName = strtolower($name);
                if (str_starts_with($lowerName, 'on') || in_array($lowerName, ['srcdoc', 'contenteditable'], true)) {
                    $node->removeAttribute($name);
                    continue;
                }
                if ($lowerName === 'href'
                    && preg_match('/^\s*(?:javascript|vbscript|file):/i', $value)) {
                    $node->removeAttribute($name);
                    continue;
                }
                if ($lowerName === 'src'
                    && !preg_match('~^\s*(?:https?://|data:image/(?:png|jpe?g|gif|webp);base64,)~i', $value)) {
                    $node->removeAttribute($name);
                    continue;
                }
                if ($lowerName === 'style'
                    && preg_match('/(?:expression\s*\(|url\s*\()/i', $value)) {
                    $node->removeAttribute($name);
                }
            }
        }
    }
}
