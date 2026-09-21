<?php
if (!defined('ABSPATH')) exit;
add_action('admin_menu',function(){add_menu_page('WF Bulk Pages','WF Bulk Pages','manage_options','wfebpg','wfebpg_admin','dashicons-layout',58);});
add_action('admin_post_wfebpg_generate','wfebpg_handle_generate');
add_action('admin_post_wfebpg_process_queue_now','wfebpg_process_queue_now');
add_action('admin_post_wfebpg_clear_logs','wfebpg_clear_logs');
add_action('admin_post_wfebpg_reset_generated','wfebpg_reset_generated');
add_action('admin_post_wfebpg_rollback','wfebpg_rollback');
function wfebpg_admin(){if(!current_user_can('manage_options'))return;$pages=get_pages(['post_status'=>['publish','draft','private']]);$logs=WFEBPG_Logger::get();$q=get_option('wfebpg_queue',[]);$created_pages=get_option('wfebpg_created_pages',[]);?>
<div class="wrap"><h1>Wolf Forge Elementor Bulk Page Generator</h1>
<?php if(isset($_GET['wfebpg_reset'])): ?><div class="notice notice-success is-dismissible"><p>Reset complete. <?php echo absint($_GET['deleted']??0); ?> generated page(s) deleted and the logs/To-Do list cleared<?php if(!empty($_GET['skipped'])): ?>. <?php echo absint($_GET['skipped']); ?> older tracked page(s) were left untouched because they were created before safe reset tracking was added<?php endif; ?>.</p></div><?php endif; ?>
<p>Upload DOCX content and an Elementor JSON template. Jobs are queued and processed in the background to reduce timeouts.</p>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>" enctype="multipart/form-data">
<input type="hidden" name="action" value="wfebpg_generate"><?php wp_nonce_field('wfebpg_generate'); ?>
<table class="form-table"><tr><th>Page Type</th><td><label><input type="radio" name="mode" value="unique" checked> Unique Pages</label> &nbsp; <label><input type="radio" name="mode" value="generic"> Generic Pages</label></td></tr>
<tr><th>DOCX Files</th><td><input type="file" name="docx[]" accept=".docx" multiple required><p class="description">Yellow-font headings are treated as repeatable markers in Unique mode.</p></td></tr>
<tr><th>Elementor JSON Template</th><td><input type="file" name="template" accept=".json" required><p class="description">Accepts Elementor exported templates and raw Elementor element arrays.</p></td></tr>
<tr><th>Repeatable Section</th><td><label>Widgets per section <input type="number" name="widgets_per_section" value="4" min="1" max="100" style="width:80px"></label><p class="description">Unique mode: maximum number of data-customID|repeatableItem widgets in each section. When reached, the entire containing Elementor section is cloned and the next yellow DOCX headings continue in the new section. Existing marked widgets are used as visual prototypes in round-robin order.</p></td></tr>
<tr><th>Parent Page</th><td><select name="parent"><option value="0">— No Parent —</option><?php foreach($pages as $p):?><option value="<?php echo esc_attr($p->ID);?>"><?php echo esc_html($p->post_title);?></option><?php endforeach;?></select></td></tr>
<tr><th>Existing Slugs</th><td><label><input type="checkbox" name="overwrite" value="1"> Overwrite matching existing pages</label><p class="description">Otherwise a short suffix is added to avoid overwriting.</p></td></tr>
</table><p><button class="button button-primary button-hero">Queue Pages</button></p></form>
<hr><h2>Queue</h2><p><strong><?php echo count($q);?></strong> job(s) waiting.</p>
<?php $next_cron=wp_next_scheduled('wfebpg_process_queue'); if($next_cron): ?>
<p class="description">Next WP-Cron run: <?php echo esc_html(wp_date(get_option('date_format').' '.get_option('time_format'),$next_cron)); ?></p>
<?php else: ?>
<p class="description">No WP-Cron worker is currently scheduled.</p>
<?php endif; ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>" style="margin:10px 0 20px"><input type="hidden" name="action" value="wfebpg_process_queue_now"><?php wp_nonce_field('wfebpg_process_queue_now');?><button class="button button-primary" <?php disabled(empty($q)); ?>>Process Queue Now</button> <span class="description">Processes one queued job immediately.</span></form>
<h2>Newly Created Pages — To-Do</h2><p>Use these links to open a generated page in WordPress or Elementor for final review and editing.</p><table class="widefat striped" style="margin-top:10px"><thead><tr><th>Created</th><th>Page</th><th>Actions</th></tr></thead><tbody><?php if(empty($created_pages)):?><tr><td colspan="3">No generated pages yet.</td></tr><?php else: foreach(array_slice($created_pages,0,100) as $cp): $cp_id=absint($cp['id']??0); if(!$cp_id)continue; ?><tr><td><?php echo esc_html($cp['time']??'');?></td><td><?php echo esc_html($cp['title']??get_the_title($cp_id));?></td><td><a class="button button-small" href="<?php echo esc_url(get_edit_post_link($cp_id));?>">Edit Page</a> <a class="button button-small" href="<?php echo esc_url(admin_url('post.php?post='.$cp_id.'&action=elementor'));?>">Edit with Elementor</a> <a href="<?php echo esc_url(get_permalink($cp_id));?>" target="_blank" rel="noopener">View</a></td></tr><?php endforeach; endif;?></tbody></table>
<h2>Reset</h2><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>" style="margin:10px 0 20px"><input type="hidden" name="action" value="wfebpg_reset_generated"><?php wp_nonce_field('wfebpg_reset_generated');?><button class="button button-secondary" style="border-color:#b32d2e;color:#b32d2e" onclick="return confirm('This will permanently delete pages created by Wolf Forge Bulk Page Generator and clear the generated-page list and logs. Overwritten existing pages will not be deleted. Continue?');">Reset Logs &amp; Generated Pages</button> <span class="description">Permanently deletes pages created by this plugin, clears their To-Do list, and clears the logs. Existing pages that were overwritten are not deleted.</span></form>
<h2>Logs</h2><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="wfebpg_clear_logs"><?php wp_nonce_field('wfebpg_clear_logs');?><button class="button">Clear Logs Only</button></form><table class="widefat striped" style="margin-top:10px"><thead><tr><th>Time</th><th>Level</th><th>Message</th></tr></thead><tbody><?php if(empty($logs)):?><tr><td colspan="3">No logs.</td></tr><?php else: foreach(array_slice($logs,0,100) as $l):?><tr><td><?php echo esc_html($l['time']);?></td><td><?php echo esc_html($l['level']);?></td><td><?php echo esc_html($l['message']);?></td></tr><?php endforeach; endif;?></tbody></table>
</div><?php }
function wfebpg_handle_generate(){if(!current_user_can('manage_options')||!check_admin_referer('wfebpg_generate'))wp_die('Unauthorized.');
    if(empty($_FILES['docx']['name'][0])||empty($_FILES['template']['tmp_name']))wp_die('DOCX and JSON template are required.');
    $upload=wp_upload_dir();$base=trailingslashit($upload['basedir']).'wfebpg/'.wp_generate_uuid4();wp_mkdir_p($base);
    $template=$base.'/template.json';if(!move_uploaded_file($_FILES['template']['tmp_name'],$template))wp_die('Unable to save template.');
    $mode=sanitize_key($_POST['mode']??'generic');$parent=absint($_POST['parent']??0);$overwrite=!empty($_POST['overwrite']);$widgets_per_section=max(1,min(100,absint($_POST['widgets_per_section']??4)));$count=0;
    foreach($_FILES['docx']['name'] as $i=>$name){if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='docx')continue;$dest=$base.'/'.sanitize_file_name(basename($name));if(!move_uploaded_file($_FILES['docx']['tmp_name'][$i],$dest))continue;WFEBPG_Generator::enqueue(['docx'=>$dest,'template'=>$template,'mode'=>$mode,'parent'=>$parent,'overwrite'=>$overwrite,'widgets_per_section'=>$widgets_per_section]);$count++;}
    WFEBPG_Logger::log('Queued '.$count.' DOCX page job(s).','success');wp_safe_redirect(admin_url('admin.php?page=wfebpg'));exit;
}
function wfebpg_process_queue_now(){
    if(!current_user_can('manage_options')||!check_admin_referer('wfebpg_process_queue_now'))wp_die('Unauthorized.');
    $q=get_option('wfebpg_queue',[]);
    if(empty($q)){
        WFEBPG_Logger::log('Process Queue Now clicked, but the queue is empty.','info');
    }else{
        WFEBPG_Logger::log('Manual queue processing started.','info');
        WFEBPG_Generator::process_queue();
    }
    wp_safe_redirect(admin_url('admin.php?page=wfebpg'));exit;
}
function wfebpg_clear_logs(){if(!current_user_can('manage_options')||!check_admin_referer('wfebpg_clear_logs'))wp_die('Unauthorized.');delete_option('wfebpg_logs');wp_safe_redirect(admin_url('admin.php?page=wfebpg'));exit;}
function wfebpg_reset_generated(){
    if(!current_user_can('manage_options')||!check_admin_referer('wfebpg_reset_generated'))wp_die('Unauthorized.');
    $created=get_option('wfebpg_created_pages',[]);
    $deleted=0;
    $skipped=0;
    foreach($created as $cp){
        $id=absint($cp['id']??0);
        if(!$id || get_post_type($id)!=='page') continue;
        $safe_generated=!empty($cp['plugin_created']) || get_post_meta($id,'_wfebpg_generated',true)==='1';
        if(!$safe_generated){ $skipped++; continue; }
        if(wp_delete_post($id,true)) $deleted++;
    }
    delete_option('wfebpg_created_pages');
    delete_option('wfebpg_logs');
    wp_safe_redirect(add_query_arg(['page'=>'wfebpg','wfebpg_reset'=>1,'deleted'=>$deleted,'skipped'=>$skipped],admin_url('admin.php')));
    exit;
}
function wfebpg_rollback(){wp_safe_redirect(admin_url('admin.php?page=wfebpg'));exit;}
