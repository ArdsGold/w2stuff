<?php
if (!defined('ABSPATH')) exit;

class WFEBPG_DOCX_Reader {
    public static function read($file) {
        if (!class_exists('ZipArchive')) {
            throw new Exception('PHP ZipArchive extension is required.');
        }

        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            throw new Exception('Unable to open DOCX.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (!$xml) {
            throw new Exception('DOCX document.xml not found.');
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (!$dom->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new Exception('Unable to parse DOCX document.xml.');
        }

        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $items = [];

        foreach ($xp->query('//w:body/w:p') as $p) {
            // Use all descendant runs, not only direct child runs. Word may
            // place text inside hyperlinks or other run containers.
            $text = self::paragraph_text($xp, $p);
            $text = trim(preg_replace('/[ \t\r\n]+/u', ' ', $text));
            if ($text === '') continue;

            $style_name = '';
            $style = $xp->query('./w:pPr/w:pStyle', $p)->item(0);
            if ($style) {
                $style_name = (string) $style->getAttribute('w:val');
            }

            $is_heading = preg_match('/heading/i', $style_name) === 1;
            $heading_level = 0;
            if ($is_heading && preg_match('/heading\s*([1-9][0-9]*)/i', $style_name, $hm)) {
                $heading_level = (int) $hm[1];
            }
            $yellow = false;

            // Check every run in the paragraph. This catches yellow formatting
            // whether the heading text is split across multiple runs or nested
            // inside a hyperlink.
            foreach ($xp->query('.//w:r', $p) as $run) {
                if (self::run_is_yellow($xp, $run)) {
                    $yellow = true;
                    break;
                }
            }

            $items[] = [
                'text' => $text,
                'yellow' => $yellow,
                'heading' => $is_heading,
                'heading_level' => $heading_level,
                'repeatable' => $yellow && $is_heading,
            ];
        }

        // Build ordered non-repeat blocks separately from repeatable items.
        // Each non-yellow heading owns the following non-heading paragraphs
        // until the next non-yellow heading. This preserves the document's
        // semantic structure so the Elementor template can map heading + body
        // pairs correctly (for example Icon Boxes, process steps, and FAQs).
        $nonrepeat_blocks = [];
        $current_block = null;
        $inside_repeatable = false;

        foreach ($items as $item) {
            if ($item['repeatable']) {
                if ($current_block !== null) {
                    $nonrepeat_blocks[] = $current_block;
                    $current_block = null;
                }
                $inside_repeatable = true;
                continue;
            }

            if ($inside_repeatable) {
                // Ignore body paragraphs belonging to the yellow repeatable
                // heading until the next non-yellow heading starts.
                if (!$item['heading']) continue;
                $inside_repeatable = false;
            }

            if ($item['heading']) {
                if ($current_block !== null) $nonrepeat_blocks[] = $current_block;
                $current_block = [
                    'heading' => $item['text'],
                    'heading_level' => (int) ($item['heading_level'] ?? 0),
                    'content' => [],
                ];
            } elseif ($current_block !== null) {
                $current_block['content'][] = $item['text'];
            }
        }
        if ($current_block !== null) $nonrepeat_blocks[] = $current_block;

        // A yellow heading starts one repeatable item. Its following body
        // paragraphs belong to that item until the next yellow heading or a
        // new non-yellow heading.
        $sections = [];
        $repeatables = [];
        $current_repeatable = null;

        foreach ($items as $item) {
            if ($item['repeatable']) {
                if ($current_repeatable !== null) {
                    $repeatables[] = $current_repeatable;
                }

                $current_repeatable = [
                    'heading' => $item['text'],
                    'content' => [],
                    'repeatable' => true,
                ];
                continue;
            }

            if ($current_repeatable !== null) {
                if ($item['heading']) {
                    $repeatables[] = $current_repeatable;
                    $current_repeatable = null;
                    $sections[] = $item;
                } else {
                    $current_repeatable['content'][] = $item['text'];
                }
            } else {
                $sections[] = $item;
            }
        }

        if ($current_repeatable !== null) {
            $repeatables[] = $current_repeatable;
        }

        return [
            'items' => $items,
            'sections' => $sections,
            'nonrepeat_blocks' => $nonrepeat_blocks,
            'repeatables' => $repeatables,
            'repeatable_count' => count($repeatables),
        ];
    }

    private static function paragraph_text($xp, $paragraph) {
        $text = '';

        foreach ($xp->query('.//w:r', $paragraph) as $run) {
            foreach ($xp->query('.//w:t', $run) as $t) {
                $text .= $t->nodeValue;
            }

            if ($xp->query('.//w:tab', $run)->length) {
                $text .= "\t";
            }

            if ($xp->query('.//w:br|.//w:cr', $run)->length) {
                $text .= "\n";
            }
        }

        return $text;
    }

    private static function run_is_yellow($xp, $run) {
        foreach ($xp->query('./w:rPr/w:color', $run) as $color) {
            $value = strtolower(trim((string) $color->getAttribute('w:val')));
            if (self::is_yellow_value($value)) return true;
        }

        foreach ($xp->query('./w:rPr/w:highlight', $run) as $highlight) {
            $value = strtolower(trim((string) $highlight->getAttribute('w:val')));
            if (in_array($value, ['yellow', 'ffff00'], true)) return true;
        }

        return false;
    }

    private static function is_yellow_value($value) {
        $value = ltrim($value, '#');

        if (in_array($value, ['yellow', 'ff0', 'fff200', 'ffff00'], true)) {
            return true;
        }

        if (strlen($value) === 6 && ctype_xdigit($value)) {
            $r = hexdec(substr($value, 0, 2));
            $g = hexdec(substr($value, 2, 2));
            $b = hexdec(substr($value, 4, 2));
            return $r >= 220 && $g >= 180 && $b <= 80;
        }

        return false;
    }
}
