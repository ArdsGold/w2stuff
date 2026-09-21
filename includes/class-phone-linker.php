<?php
if (!defined('ABSPATH')) exit;

/**
 * Safely converts supported US phone-number text into tel: links.
 *
 * Important: only text nodes are processed. HTML tags/attributes are left
 * untouched, and existing anchors are never processed again. This prevents
 * generated href attributes from being turned into nested/corrupted links.
 */
class WFEBPG_Phone_Linker {
    public static function html($html) {
        if (!is_string($html) || $html === '') {
            return $html;
        }

        // Split HTML into tags and text. We only modify text portions.
        $parts = preg_split('/(<[^>]*>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }

        $in_anchor = false;
        $in_raw_text = false;

        foreach ($parts as &$part) {
            if ($part === '') {
                continue;
            }

            // HTML tag: never alter attributes or tag contents.
            if ($part[0] === '<') {
                if (preg_match('/^<\s*a\b/i', $part)) {
                    $in_anchor = true;
                } elseif (preg_match('/^<\s*\/\s*a\s*>/i', $part)) {
                    $in_anchor = false;
                }

                if (preg_match('/^<\s*(script|style)\b/i', $part)) {
                    $in_raw_text = true;
                } elseif (preg_match('/^<\s*\/\s*(script|style)\s*>/i', $part)) {
                    $in_raw_text = false;
                }

                continue;
            }

            // Do not process text inside an existing link, script, or style.
            if ($in_anchor || $in_raw_text) {
                continue;
            }

            $part = self::link_text($part);
        }
        unset($part);

        return implode('', $parts);
    }

    private static function link_text($text) {
        // Supported formats:
        // +1 (XXX) XXX-XXXX
        // +1-XXX-XXX-XXXX
        // (XXX) XXX-XXXX
        // XXX-XXX-XXXX
        // XXXXXXXXXX
        // +1XXXXXXXXXX
        // +1 XXX XXX XXXX
        // Use ONE callback pass so the <a> markup we create can never be
        // matched again by another phone-number pattern.
        $pattern = '/(?:\+1\s*\(\d{3}\)\s*\d{3}[-\s]\d{4}|\+1[-\s]\d{3}[-\s]\d{3}[-\s]\d{4}|\(\d{3}\)\s*\d{3}[-\s]\d{4}|\b\d{3}[-\s]\d{3}[-\s]\d{4}\b|\+1\s*\d{3}\s*\d{3}\s*\d{4}|\+1\d{10}\b|\b\d{10}\b)/';

        $text = preg_replace_callback($pattern, function ($m) {
            $display = $m[0];
            $digits = preg_replace('/\D+/', '', $display);

            // Every supported number is a US number. Normalize to E.164.
            if (strlen($digits) === 11 && $digits[0] === '1') {
                $phone = '+' . $digits;
            } elseif (strlen($digits) === 10) {
                $phone = '+1' . $digits;
            } else {
                return $display;
            }

            return '<a href="tel:' . esc_attr($phone) . '">' . $display . '</a>';
        }, $text);

        return $text;
    }
}
