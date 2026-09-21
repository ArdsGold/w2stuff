<?php
if (!defined('ABSPATH')) exit;

class WFEBPG_Template {
    public static function decode($json) {
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid Elementor JSON: ' . json_last_error_msg());
        }

        if (isset($data['content']) && is_array($data['content'])) return $data['content'];
        if (isset($data['elements']) && is_array($data['elements'])) return $data['elements'];
        if (is_array($data) && isset($data[0])) return $data;

        throw new Exception('Unsupported Elementor JSON structure. Expected exported template, content, elements, or raw element array.');
    }

    public static function encode($elements) {
        return wp_json_encode($elements, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Elementor stores the custom HTML attribute in settings as:
     * data-customID|h1NonRepeat
     *
     * We also accept common normalized forms so the plugin remains flexible.
     */
    public static function custom_id($el) {
        $settings = isset($el['settings']) && is_array($el['settings']) ? $el['settings'] : [];
        $candidates = [];

        foreach (['customID', 'custom_id', 'data-customID', 'data_customID'] as $key) {
            if (isset($el[$key])) $candidates[] = $el[$key];
            if (isset($settings[$key])) $candidates[] = $settings[$key];
        }

        if (isset($settings['_attributes']) && is_string($settings['_attributes'])) {
            $candidates[] = $settings['_attributes'];
        }

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') continue;
            if (strpos($candidate, '|') !== false) {
                $parts = explode('|', $candidate, 2);
                return trim($parts[1]);
            }
            if (stripos($candidate, 'data-customID=') === 0) {
                return trim(trim(substr($candidate, 14)), " \t\"'");
            }
            return $candidate;
        }

        return '';
    }
}
