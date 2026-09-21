<?php
/**
 * Plugin Name: Wolf Forge Elementor Bulk Page Generator
 * Description: Bulk-generate Elementor pages from DOCX files and Elementor JSON templates. Supports generic/unique mapping, yellow-heading repeatable sections, parent pages, phone links, queue processing, previews, logs, and rollback.
 * Version: 1.8.0
 * Author: Wolf Forge
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

define('WFEBPG_VERSION','1.8.0');
define('WFEBPG_DIR',plugin_dir_path(__FILE__));
define('WFEBPG_URL',plugin_dir_url(__FILE__));

require_once WFEBPG_DIR.'includes/class-docx-reader.php';
require_once WFEBPG_DIR.'includes/class-template.php';
require_once WFEBPG_DIR.'includes/class-generator.php';
require_once WFEBPG_DIR.'includes/class-phone-linker.php';
require_once WFEBPG_DIR.'includes/class-logger.php';
require_once WFEBPG_DIR.'admin/admin-page.php';

register_activation_hook(__FILE__, function(){
    if (!wp_next_scheduled('wfebpg_process_queue')) wp_schedule_event(time()+60,'minute','wfebpg_process_queue');
});
register_deactivation_hook(__FILE__, function(){
    wp_clear_scheduled_hook('wfebpg_process_queue');
});
add_action('wfebpg_process_queue',['WFEBPG_Generator','process_queue']);
add_filter('cron_schedules',function($s){$s['minute']=['interval'=>60,'display'=>'Every Minute'];return $s;});
