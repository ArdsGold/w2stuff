<?php
if (!defined('ABSPATH')) exit;

class WFEBPG_Generator {
    const H1_ID = 'h1NonRepeat';
    const SECTION_TITLE_ID = 'sectionTitleNonRepeat';
    const H_ID  = 'hNonRepeat';
    const P_ID  = 'pNonRepeat';
    const REPEAT_ID = 'repeatableItem';

    public static function clean_filename($name) {
        $name = pathinfo($name, PATHINFO_FILENAME);
        $name = preg_replace('/[\s._+\-]+$/u', '', $name);
        $name = preg_replace('/[._+\-]+/u', ' ', $name);
        $name = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $name);
        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    public static function slug($title) { return sanitize_title($title); }

    public static function enqueue($args) {
        $q = get_option('wfebpg_queue', []);
        $q[] = $args;
        update_option('wfebpg_queue', $q, false);

        // Schedule a near-immediate single event as well as the recurring
        // worker. This helps the queue start promptly instead of waiting for
        // the next minute tick. WordPress still requires WP-Cron to be
        // triggered by traffic or a real cron request.
        if (!wp_next_scheduled('wfebpg_process_queue')) {
            wp_schedule_single_event(time() + 5, 'wfebpg_process_queue');
        }
    }

    public static function process_queue() {
        $q = get_option('wfebpg_queue', []);
        if (!$q) return;

        $job = array_shift($q);
        update_option('wfebpg_queue', $q, false);

        try {
            self::generate($job);
        } catch (Throwable $e) {
            WFEBPG_Logger::log($e->getMessage(), 'error');
        }
    }

    /**
     * Populate all non-repeat widgets using the custom IDs in the template.
     * Mapping is based on occurrence order in the DOCX:
     * h1NonRepeat -> first non-yellow heading
     * hNonRepeat  -> subsequent non-yellow headings
     * pNonRepeat  -> paragraph/content blocks in document order
     */
    /**
     * Populate non-repeat content according to the semantic order of the DOCX.
     *
     * The template uses pNonRepeat for several different Elementor widgets:
     * - Text Editor: body text belonging to the most recently mapped heading.
     * - Icon Box: one heading + its body paragraph.
     * - Toggle: multiple heading/body pairs (FAQ entries).
     *
     * h1NonRepeat/hNonRepeat consume heading blocks in order. pNonRepeat
     * widgets then consume the appropriate block(s), preventing paragraphs
     * from being shifted or duplicated merely because the DOCX contains many
     * headings between paragraph blocks.
     */
    private static function populate_nonrepeat(&$elements, $doc) {
        $blocks = !empty($doc['nonrepeat_blocks']) && is_array($doc['nonrepeat_blocks'])
            ? $doc['nonrepeat_blocks']
            : self::legacy_nonrepeat_blocks($doc);

        $cursor = 0;
        $last_block = null;

        self::walk_mutate($elements, function (&$el) use (&$blocks, &$cursor, &$last_block) {
            $custom_id = WFEBPG_Template::custom_id($el);
            if ($custom_id === self::REPEAT_ID) return;

            $settings = isset($el['settings']) && is_array($el['settings']) ? $el['settings'] : [];
            $widget_type = isset($el['widgetType']) ? (string) $el['widgetType'] : '';

            if ($custom_id === self::H1_ID) {
                // H1 markers map specifically to a level-1 DOCX heading.
                $block = self::next_block_by_heading_level($blocks, $cursor, 1);
                if ($block !== null) {
                    self::set_widget_title($settings, $block['heading']);
                    $last_block = $block;
                }
            } elseif ($custom_id === self::SECTION_TITLE_ID) {
                // Major section-title markers map specifically to level-2 DOCX
                // headings, keeping them separate from normal H3 headings.
                $block = self::next_block_by_heading_level($blocks, $cursor, 2);
                if ($block !== null) {
                    self::set_widget_title($settings, $block['heading']);
                    $last_block = $block;
                }
            } elseif ($custom_id === self::H_ID) {
                // Normal heading markers map to level-3 DOCX headings in the
                // local-SEO document structure used by Wolf Forge.
                $block = self::next_block_by_heading_level($blocks, $cursor, 3);
                if ($block !== null) {
                    self::set_widget_title($settings, $block['heading']);
                    $last_block = $block;
                }
            } elseif ($custom_id === self::P_ID) {
                if ($widget_type === 'icon-box') {
                    $block = self::next_block($blocks, $cursor);
                    if ($block !== null) {
                        self::set_icon_box_content($settings, $block);
                        $last_block = $block;
                    }
                } elseif ($widget_type === 'toggle') {
                    self::set_toggle_content($settings, $blocks, $cursor);
                } else {
                    // Text Editor content belongs to the last mapped heading.
                    // If no suitable block is active, find the next block with
                    // body content rather than consuming an unrelated heading.
                    $block = $last_block;
                    if ($block === null || empty($block['content'])) {
                        $block = self::next_block_with_content($blocks, $cursor);
                        if ($block !== null) $last_block = $block;
                    }
                    if ($block !== null && !empty($block['content'])) {
                        self::set_widget_text($settings, implode("\n\n", $block['content']));
                    }
                }
            }

            $el['settings'] = $settings;
        });
    }

    private static function legacy_nonrepeat_blocks($doc) {
        $blocks = [];
        $current = null;
        foreach (($doc['items'] ?? []) as $item) {
            if (!empty($item['repeatable'])) continue;
            if (!empty($item['heading'])) {
                if ($current !== null) $blocks[] = $current;
                $current = ['heading' => $item['text'], 'content' => []];
            } elseif ($current !== null) {
                $current['content'][] = $item['text'];
            }
        }
        if ($current !== null) $blocks[] = $current;
        return $blocks;
    }

    private static function next_block(&$blocks, &$cursor) {
        if ($cursor >= count($blocks)) return null;
        $block = $blocks[$cursor];
        $cursor++;
        return $block;
    }

    private static function next_block_by_heading_level(&$blocks, &$cursor, $level) {
        $count = count($blocks);
        for ($i = $cursor; $i < $count; $i++) {
            if ((int) ($blocks[$i]['heading_level'] ?? 0) !== (int) $level) continue;
            $cursor = $i + 1;
            return $blocks[$i];
        }
        return null;
    }

    private static function next_block_with_content(&$blocks, &$cursor) {
        while ($cursor < count($blocks)) {
            $block = $blocks[$cursor++];
            if (!empty($block['content'])) return $block;
        }
        return null;
    }

    private static function set_icon_box_content(&$settings, $block) {
        $settings['title_text'] = $block['heading'];
        $settings['description_text'] = implode("\n\n", $block['content']);
    }

    private static function set_toggle_content(&$settings, &$blocks, &$cursor) {
        if (!isset($settings['tabs']) || !is_array($settings['tabs'])) return;

        foreach ($settings['tabs'] as $index => &$tab) {
            $block = self::next_block($blocks, $cursor);
            if ($block === null) break;

            if (is_array($tab)) {
                $tab['tab_title'] = $block['heading'];
                $tab['tab_content'] = wpautop(implode("\n\n", $block['content']));
            }
        }
        unset($tab);
    }

    private static function set_widget_title(&$settings, $value) {
        if (array_key_exists('title', $settings)) {
            $settings['title'] = $value;
        } elseif (array_key_exists('title_text', $settings)) {
            $settings['title_text'] = $value;
        }
    }

    private static function set_widget_text(&$settings, $value) {
        $html = wpautop($value);
        if (array_key_exists('editor', $settings)) {
            $settings['editor'] = $html;
        } elseif (array_key_exists('description_text', $settings)) {
            $settings['description_text'] = wp_strip_all_tags($value);
        } elseif (array_key_exists('text', $settings)) {
            $settings['text'] = $value;
        } elseif (array_key_exists('content', $settings)) {
            $settings['content'] = $html;
        } elseif (array_key_exists('html', $settings)) {
            $settings['html'] = $html;
        }
    }

    /**
     * Yellow DOCX headings are the actual repeatable content boundaries.
     * Each yellow heading starts one item; all following non-heading paragraphs
     * belong to that item until the next yellow heading.
     *
     * repeatableItem is treated as the actual marked Elementor widget. The
     * widget itself is cloned/populated; its parent column/container is never
     * cloned merely to create another item.
     */
    private static function populate_repeatables(&$elements, $repeatables, $widgets_per_section = 0) {
        if (!$repeatables) return;

        // When a section contains repeatableItem widgets, use that whole
        // section as the visual prototype and clone the section when the
        // configured widget limit is reached. The marker remains on the
        // actual widget (normally an Icon Box in the user's template).
        if ($widgets_per_section > 0 && self::has_repeatable_section($elements)) {
            self::expand_repeatable_sections($elements, $repeatables, $widgets_per_section);
            return;
        }

        // Backward-compatible fallback: if no repeatable section is found,
        // clone the marked widgets themselves.
        $refs = [];
        self::collect_repeatables($elements, $refs);
        $existing = count($refs);
        $wanted = count($repeatables);

        if ($existing === 0 && $wanted > 0) {
            throw new Exception('Unique template contains no data-customID|repeatableItem widget.');
        }

        if ($wanted < $existing) {
            self::remove_repeatables_from_end($elements, $existing - $wanted);
        } elseif ($wanted > $existing) {
            self::clone_repeatable_widgets($elements, $wanted - $existing);
        }

        $refs = [];
        self::collect_repeatables($elements, $refs);
        foreach ($refs as $i => &$widget) {
            if (isset($repeatables[$i])) self::populate_repeatable_widget($widget, $repeatables[$i]);
        }
        unset($widget);
    }

    private static function has_repeatable_section(&$elements) {
        foreach ($elements as &$el) {
            if (($el['elType'] ?? '') === 'section' && self::section_repeatable_locations($el)) {
                unset($el);
                return true;
            }
            if (isset($el['elements']) && is_array($el['elements']) && self::has_repeatable_section($el['elements'])) {
                unset($el);
                return true;
            }
        }
        unset($el);
        return false;
    }

    private static function section_repeatable_locations($section) {
        $locations = [];
        if (!is_array($section) || !isset($section['elements']) || !is_array($section['elements'])) return $locations;
        self::find_repeatable_locations($section['elements'], $locations);
        return $locations;
    }

    /**
     * Replace every section containing repeatableItem widgets with one or more
     * cloned sections. Each generated section receives at most
     * $widgets_per_section marked widgets. The source widgets are cloned in
     * round-robin order so an existing four-card design can preserve its four
     * visual prototypes. The widget's parent Column is preserved by appending
     * the cloned widget back into that same relative parent inside the cloned
     * section.
     */
    private static function expand_repeatable_sections(&$elements, $repeatables, $widgets_per_section) {
        $cursor = 0;
        self::expand_repeatable_sections_recursive($elements, $repeatables, $widgets_per_section, $cursor);

        if ($cursor < count($repeatables)) {
            // A template can contain more than one repeatable section. Continue
            // filling later sections if present; otherwise fail loudly instead
            // of silently dropping DOCX content.
            throw new Exception('The template does not contain enough repeatable section capacity for all yellow DOCX headings.');
        }
    }

    private static function expand_repeatable_sections_recursive(&$elements, $repeatables, $limit, &$cursor) {
        for ($i = 0; $i < count($elements); $i++) {
            if (($elements[$i]['elType'] ?? '') === 'section') {
                $locations = self::section_repeatable_locations($elements[$i]);
                if ($locations) {
                    $remaining = count($repeatables) - $cursor;
                    if ($remaining <= 0) {
                        self::remove_all_repeatables($elements[$i]['elements']);
                        continue;
                    }

                    $prototype = $elements[$i];
                    $prototype_widgets = [];
                    foreach ($locations as $location) {
                        $prototype_widgets[] = [
                            'node' => $location['node'],
                            'parent_path' => $location['parent_path'],
                        ];
                    }

                    // The number of sections is driven by the complete DOCX
                    // repeatable count, not by the number of prototype widgets.
                    $section_count = (int) ceil($remaining / $limit);
                    $replacement = [];

                    for ($section_index = 0; $section_index < $section_count; $section_index++) {
                        $section = self::deep_clone_element($prototype);
                        self::remove_all_repeatables($section['elements']);

                        $chunk_count = min($limit, count($repeatables) - $cursor);
                        for ($j = 0; $j < $chunk_count; $j++) {
                            $source = $prototype_widgets[($section_index * $limit + $j) % count($prototype_widgets)];
                            $copy = self::deep_clone_element($source['node']);
                            self::populate_repeatable_widget($copy, $repeatables[$cursor]);
                            self::append_to_path($section['elements'], $source['parent_path'], $copy);
                            $cursor++;
                        }

                        // If this is a partial/final section, remove the unused
                        // prototype Columns too. Their background images and
                        // styling must not survive as empty cards.
                        if ($chunk_count < $limit) {
                            self::remove_unused_repeatable_columns($section, $prototype_widgets, $chunk_count);
                        }

                        $replacement[] = $section;
                    }

                    array_splice($elements, $i, 1, $replacement);
                    $i += count($replacement) - 1;
                    continue;
                }
            }

            if (isset($elements[$i]['elements']) && is_array($elements[$i]['elements'])) {
                self::expand_repeatable_sections_recursive($elements[$i]['elements'], $repeatables, $limit, $cursor);
            }
        }
    }

    /**
     * Return the path to the nearest Elementor column that contains a
     * repeatable widget. The path points to the column node itself (not its
     * elements array), so the whole visual card/column can be removed when a
     * final section has unused repeatable slots.
     */
    private static function nearest_column_path($root, $parent_path) {
        $node = $root;
        $column_path = null;
        foreach ($parent_path as $position => $index) {
            if (!isset($node[$index]) || !is_array($node[$index])) break;
            $node = $node[$index];
            if (($node['elType'] ?? '') === 'column') {
                $column_path = array_slice($parent_path, 0, $position + 1);
            }
        }
        return $column_path;
    }

    /** Remove a node at an element-tree path. */
    private static function remove_at_path(&$root, $path) {
        if (!$path) return false;
        $parent_path = $path;
        $index = array_pop($parent_path);
        $parent =& $root;
        foreach ($parent_path as $step) {
            if (!isset($parent[$step]['elements']) || !is_array($parent[$step]['elements'])) {
                unset($parent);
                return false;
            }
            $parent =& $parent[$step]['elements'];
        }
        if (!isset($parent[$index])) {
            unset($parent);
            return false;
        }
        array_splice($parent, $index, 1);
        unset($parent);
        return true;
    }

    /**
     * Remove the visual prototype columns that were not used in the current
     * repeatable section. This is important when a column's background image
     * is its visual card: removing only the Icon Box leaves a blank image-only
     * card behind.
     */
    private static function remove_unused_repeatable_columns(&$section, $prototype_widgets, $used_count) {
        $used_paths = [];
        $total = count($prototype_widgets);
        if ($total === 0) return;

        for ($j = 0; $j < $used_count; $j++) {
            $source = $prototype_widgets[$j % $total];
            $column_path = self::nearest_column_path($section['elements'], $source['parent_path']);
            if ($column_path !== null) {
                $used_paths[serialize($column_path)] = true;
            }
        }

        $unused = [];
        foreach ($prototype_widgets as $source) {
            $column_path = self::nearest_column_path($section['elements'], $source['parent_path']);
            if ($column_path === null) continue;
            $key = serialize($column_path);
            if (!isset($used_paths[$key])) {
                $unused[$key] = $column_path;
            }
        }

        // Remove deepest/highest-index paths first so earlier removals do not
        // shift the indexes of paths that are still going to be removed.
        usort($unused, function ($a, $b) {
            if (count($a) !== count($b)) return count($b) <=> count($a);
            for ($i = 0; $i < count($a); $i++) {
                if ($a[$i] !== $b[$i]) return $b[$i] <=> $a[$i];
            }
            return 0;
        });

        foreach ($unused as $path) {
            self::remove_at_path($section['elements'], $path);
        }
    }

    private static function remove_all_repeatables(&$elements) {
        if (!is_array($elements)) return;
        for ($i = count($elements) - 1; $i >= 0; $i--) {
            if (WFEBPG_Template::custom_id($elements[$i]) === self::REPEAT_ID) {
                array_splice($elements, $i, 1);
                continue;
            }
            if (isset($elements[$i]['elements']) && is_array($elements[$i]['elements'])) {
                self::remove_all_repeatables($elements[$i]['elements']);
            }
        }
    }

    private static function populate_repeatable_widget(&$widget, $item) {
        $settings = isset($widget['settings']) && is_array($widget['settings']) ? $widget['settings'] : [];
        $title = $item['heading'];
        $content = implode("\n\n", $item['content']);

        if (array_key_exists('title_text', $settings)) {
            $settings['title_text'] = $title;
        } elseif (array_key_exists('title', $settings)) {
            $settings['title'] = $title;
        }

        if (array_key_exists('description_text', $settings)) {
            $settings['description_text'] = $content;
        } elseif (array_key_exists('editor', $settings)) {
            $settings['editor'] = wpautop($content);
        } elseif (array_key_exists('text', $settings)) {
            $settings['text'] = $content;
        } elseif (array_key_exists('content', $settings)) {
            $settings['content'] = wpautop($content);
        } elseif (array_key_exists('html', $settings)) {
            $settings['html'] = wpautop($content);
        }

        $widget['settings'] = $settings;
    }

    private static function collect_repeatables(&$elements, &$refs) {
        foreach ($elements as &$el) {
            if (WFEBPG_Template::custom_id($el) === self::REPEAT_ID) {
                $refs[] =& $el;
            }
            if (isset($el['elements']) && is_array($el['elements'])) {
                self::collect_repeatables($el['elements'], $refs);
            }
        }
        unset($el);
    }

    private static function remove_repeatables_from_end(&$elements, $remove_count, &$removed = 0) {
        for ($i = count($elements) - 1; $i >= 0; $i--) {
            if (WFEBPG_Template::custom_id($elements[$i]) === self::REPEAT_ID) {
                array_splice($elements, $i, 1);
                $removed++;
                if ($removed >= $remove_count) return true;
                continue;
            }
            if (isset($elements[$i]['elements']) && is_array($elements[$i]['elements'])) {
                if (self::remove_repeatables_from_end($elements[$i]['elements'], $remove_count, $removed)) return true;
            }
        }
        return false;
    }

    /**
     * Clone only the marked repeatable widget. New widgets are inserted into
     * the same parent arrays as the existing repeatable widgets, distributed
     * round-robin so a four-column icon-box grid keeps using its existing columns.
     */
    private static function clone_repeatable_widgets(&$elements, $needed) {
        if ($needed <= 0) return;

        $locations = [];
        self::find_repeatable_locations($elements, $locations);
        if (!$locations) return;

        $templates = [];
        foreach ($locations as $location) {
            $templates[] = [
                'node' => $location['node'],
                'parent_path' => $location['parent_path'],
                'index' => $location['index'],
            ];
        }

        for ($i = 0; $i < $needed; $i++) {
            $source = $templates[$i % count($templates)]['node'];
            $copy = self::deep_clone_element($source);
            $parent = $templates[$i % count($templates)]['parent_path'];
            self::append_to_path($elements, $parent, $copy);
        }
    }

    private static function find_repeatable_locations(&$elements, &$locations, $parent_path = []) {
        foreach ($elements as $index => &$el) {
            if (WFEBPG_Template::custom_id($el) === self::REPEAT_ID) {
                $locations[] = [
                    'node' => $el,
                    'parent_path' => $parent_path,
                    'index' => $index,
                ];
            }
            if (isset($el['elements']) && is_array($el['elements'])) {
                self::find_repeatable_locations($el['elements'], $locations, array_merge($parent_path, [$index]));
            }
        }
        unset($el);
    }

    private static function append_to_path(&$root, $path, $copy) {
        $ref =& $root;
        foreach ($path as $index) {
            if (!isset($ref[$index]['elements']) || !is_array($ref[$index]['elements'])) return;
            $ref =& $ref[$index]['elements'];
        }
        $ref[] = $copy;
        unset($ref);
    }

    /**
     * Deep-clone an Elementor element tree. Every native Elementor element ID
     * is replaced with a fresh ID; custom data-customID markers are preserved.
     */
    private static function deep_clone_element($element) {
        if (!is_array($element)) return $element;
        if (isset($element['id'])) $element['id'] = self::new_element_id();
        if (isset($element['elements']) && is_array($element['elements'])) {
            foreach ($element['elements'] as &$child) $child = self::deep_clone_element($child);
            unset($child);
        }
        return $element;
    }

    private static function new_element_id() {
        return substr(str_replace('-', '', wp_generate_uuid4()), 0, 8);
    }

    private static function walk_mutate(&$elements, $callback) {
        foreach ($elements as &$el) {
            $callback($el);
            if (isset($el['elements']) && is_array($el['elements'])) {
                self::walk_mutate($el['elements'], $callback);
            }
        }
        unset($el);
    }

    private static function apply_phone_links(&$elements) {
        foreach ($elements as &$el) {
            if (isset($el['settings']) && is_array($el['settings'])) {
                foreach ($el['settings'] as $key => $value) {
                    if (is_string($value)) $el['settings'][$key] = WFEBPG_Phone_Linker::html($value);
                }
            }
            if (isset($el['elements']) && is_array($el['elements'])) {
                self::apply_phone_links($el['elements']);
            }
        }
        unset($el);
    }

    /**
     * Force Elementor to rebuild the frontend CSS for the generated document.
     * Programmatic _elementor_data writes do not always invalidate Elementor's
     * generated CSS files, which can make a new page appear extremely narrow
     * until the page is opened/saved in Elementor once.
     */
    private static function regenerate_elementor_css($post_id) {
        if (!class_exists('\Elementor\Core\Files\CSS\Post')) return;

        try {
            $css_file = new \Elementor\Core\Files\CSS\Post($post_id);
            if (method_exists($css_file, 'update')) {
                $css_file->update();
            }
        } catch (Throwable $e) {
            WFEBPG_Logger::log('Elementor CSS regeneration warning: ' . $e->getMessage(), 'warning');
        }

        if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->files_manager)) {
            try {
                if (method_exists(\Elementor\Plugin::$instance->files_manager, 'clear_cache')) {
                    \Elementor\Plugin::$instance->files_manager->clear_cache();
                }
            } catch (Throwable $e) {
                WFEBPG_Logger::log('Elementor cache clear warning: ' . $e->getMessage(), 'warning');
            }
        }
    }

    private static function count_markers(&$elements) {
        $counts = [
            self::H1_ID => 0,
            self::SECTION_TITLE_ID => 0,
            self::H_ID => 0,
            self::P_ID => 0,
            self::REPEAT_ID => 0,
        ];

        self::walk_mutate($elements, function (&$el) use (&$counts) {
            $id = WFEBPG_Template::custom_id($el);
            if (isset($counts[$id])) $counts[$id]++;
        });

        return $counts;
    }

    public static function generate($job) {
        $doc = WFEBPG_DOCX_Reader::read($job['docx']);
        $json = file_get_contents($job['template']);
        if ($json === false) throw new Exception('Unable to read Elementor JSON template.');

        $elements = WFEBPG_Template::decode($json);
        $template_counts = self::count_markers($elements);

        WFEBPG_Logger::log(
            'Mapping DOCX: ' . count($doc['items']) . ' paragraphs, ' .
            count($doc['repeatables']) . ' yellow repeatable headings. Template markers: ' .
            'h1=' . $template_counts[self::H1_ID] . ', ' .
            'sectionTitle=' . $template_counts[self::SECTION_TITLE_ID] . ', ' .
            'h=' . $template_counts[self::H_ID] . ', ' .
            'p=' . $template_counts[self::P_ID] . ', ' .
            'repeatable=' . $template_counts[self::REPEAT_ID] . '.'
        );

        self::populate_nonrepeat($elements, $doc);

        if (($job['mode'] ?? 'generic') === 'unique') {
            if (!$template_counts[self::REPEAT_ID] && !empty($doc['repeatables'])) {
                throw new Exception('DOCX contains ' . count($doc['repeatables']) . ' yellow repeatable headings, but the Elementor template contains no data-customID|repeatableItem widgets.');
            }

            self::populate_repeatables(
                $elements,
                $doc['repeatables'],
                absint($job['widgets_per_section'] ?? 0)
            );
        }

        self::apply_phone_links($elements);

        $title = self::clean_filename(basename($job['docx']));
        if ($title === '') throw new Exception('Could not derive a page title from filename.');
        $slug = self::slug($title);

        $existing = get_page_by_path($slug, OBJECT, 'page');
        if ($existing && empty($job['overwrite'])) {
            $slug .= '-' . wp_generate_password(4, false, false);
        }

        $post = [
            'post_title' => $title,
            'post_name' => $slug,
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_parent' => absint($job['parent'] ?? 0),
            'post_content' => '',
        ];

        $was_overwrite = false;
        if ($existing && !empty($job['overwrite'])) {
            $post['ID'] = $existing->ID;
            $id = wp_update_post(wp_slash($post), true);
            $was_overwrite = true;
        } else {
            $id = wp_insert_post(wp_slash($post), true);
        }

        if (is_wp_error($id)) throw new Exception('Page creation failed: ' . $id->get_error_message());

        $elementor_json = WFEBPG_Template::encode($elements);

        update_post_meta($id, '_elementor_edit_mode', 'builder');
        update_post_meta($id, '_elementor_version', defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '');
        update_post_meta($id, '_elementor_data', wp_slash($elementor_json));

        if (class_exists('\Elementor\Plugin')) {
            try {
                $document = \Elementor\Plugin::$instance->documents->get($id);
                if ($document) {
                    $document->save(['elements' => $elements]);

                    // Elementor may normalize/re-save document data during
                    // document->save(). Re-write the generated JSON afterward
                    // so DOCX-mapped content remains authoritative.
                    update_post_meta($id, '_elementor_data', wp_slash($elementor_json));
                }
            } catch (Throwable $e) {
                WFEBPG_Logger::log('Elementor document save fallback used: ' . $e->getMessage(), 'warning');
                update_post_meta($id, '_elementor_data', wp_slash($elementor_json));
            }
        }

        // Rebuild Elementor's generated CSS after the final JSON is in place.
        // This prevents the page from relying on a stale/missing CSS file until
        // someone manually opens the page in Elementor.
        self::regenerate_elementor_css($id);

        // Mark only pages that were actually created by this plugin. Pages that
        // were deliberately overwritten are tracked for review but are never
        // automatically deleted by the Reset button.
        update_post_meta($id, '_wfebpg_generated', $was_overwrite ? '0' : '1');

        clean_post_cache($id);
        wp_cache_delete($id, 'post_meta');

        $created_pages = get_option('wfebpg_created_pages', []);
        array_unshift($created_pages, [
            'id' => (int) $id,
            'title' => $title,
            'time' => current_time('mysql'),
            'plugin_created' => $was_overwrite ? 0 : 1,
        ]);
        update_option('wfebpg_created_pages', array_slice($created_pages, 0, 300), false);

        WFEBPG_Logger::log('Generated page #' . $id . ' — ' . $title, 'success');
        return $id;
    }
}
