<?php
/**
 * AzlinkPRO Migration Wizard: non-destructive AmaLinks Pro table importer.
 * Works from existing WordPress content/meta even if the old plugin is inactive.
 * No payments, Amazon API calls, external telemetry or legacy-plugin dependencies.
 */
if (!defined('ABSPATH')) { exit; }

function azlinkpro_mig_cap() { return current_user_can('manage_options'); }
function azlinkpro_mig_key() { return 'azp_migration_latest_run'; }
function azlinkpro_mig_source_ids() {
    return get_posts(array('post_type'=>'amalinkspro-table','post_status'=>array('publish','draft','private','pending','future'),
        'fields'=>'ids','numberposts'=>-1, 'orderby'=>'ID','order'=>'ASC'));
}
function azlinkpro_mig_content_ids() {
    global $wpdb;
    // Shortcodes in published, draft and private content. Original slugs/URLs are never changed.
    $types=array_values(array_unique(array_merge(array('post','page','wp_block'),array_values(get_post_types(array('public'=>true),'names')))));
    $types=array_values(array_filter($types,function($type){return is_string($type)&&preg_match('/^[a-z0-9_-]{1,30}$/D',$type);}));
    $marks=implode(',',array_fill(0,count($types),'%s'));
    $params=array_merge($types,array('%[amalinkspro_table%'));
    // Shortcode search across post content: no WordPress API covers a LIKE on post_content.
    // $marks holds only %s placeholders, one per post type, so every value is still prepared.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
    return array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($marks) AND post_status IN ('publish','draft','pending','private','future') AND post_content LIKE %s ORDER BY ID ASC LIMIT 20001",
        $params)));
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
}
function azlinkpro_mig_matches($content) {
    if (!is_string($content) || stripos($content,'[amalinkspro_table')===false) { return array(); }
    preg_match_all('~\[(amalinkspro_table)(?=[\s\]/])([^\]]*)\]~i', $content, $all, PREG_SET_ORDER);
    return $all;
}
function azlinkpro_mig_attrs($match) {
    $raw=html_entity_decode((string)($match[2]??''),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $attr=shortcode_parse_atts($raw);
    return is_array($attr)?$attr:array();
}
/**
 * These two URL validators stay for backwards compatibility. They are used by
 * existing saved Tables AND existing saved Showcases (including older imports).
 * Removing Showcase migration must NOT break their affiliate URLs or photos.
 */
function azlinkpro_mig_amazon_link($url,$asin='') {
    $url=html_entity_decode(trim((string)$url),ENT_QUOTES|ENT_HTML5,'UTF-8');
    if (!$url || strlen($url)>2048 || !wp_http_validate_url($url)) { return ''; }
    $p=wp_parse_url($url);
    $host=strtolower((string)($p['host']??''));
    if (strtolower((string)($p['scheme']??''))!=='https' ||
        !preg_match('/(^|\.)amazon\.(com|ca|co\.uk|de|fr|it|es|com\.au|co\.jp)$/D',$host)) { return ''; }
    // Only retain the original affiliate URL for the same product.
    if ($asin && !preg_match('~/(?:dp|gp/product)/'.preg_quote($asin,'~').'(?=[/?#]|$)~i',$url)) { return ''; }
    return esc_url_raw($url);
}
function azlinkpro_mig_image($url) {
    $url=html_entity_decode(trim((string)$url),ENT_QUOTES|ENT_HTML5,'UTF-8');
    if (!$url || strlen($url)>2048 || !wp_http_validate_url($url)) { return ''; }
    $parts=wp_parse_url($url); $host=strtolower((string)($parts['host']??''));
    // WordPress media assets and AmaLinks' existing Amazon product photographs only.
    $local=wp_parse_url(home_url('/'),PHP_URL_HOST);
    if (strtolower((string)($parts['scheme']??''))!=='https' ||
        !in_array($host,array('m.media-amazon.com','images-na.ssl-images-amazon.com','images-eu.ssl-images-amazon.com',strtolower((string)$local)),true)) { return ''; }
    return esc_url_raw($url);
}
function azlinkpro_mig_table_data($id) {
    $source=get_post($id);
    if (!$source || $source->post_type!=='amalinkspro-table') { return new WP_Error('azp_mig_source','Legacy table not found.'); }
    $raw_count=(int)get_post_meta($id,'alp_table_rows',true);
    if ($raw_count>50) { return new WP_Error('azp_mig_large_table','Table exceeds 50 products and needs manual review.'); }
    $n=max(0,$raw_count);
    $rows=array();$links=array();$images=array();$seen=array();
    for($i=0;$i<$n;$i++) {
        $base='alp_table_rows_'.$i.'_';
        $asin=strtoupper(trim((string)get_post_meta($id,$base.'asin',true)));
        if (!preg_match('/^[A-Z0-9]{10}$/D',$asin) || isset($seen[$asin])) { continue; }
        $seen[$asin]=true; $name='';$details=array();$image='';$cta='Check Price';$featured=false;
        for($c=0;$c<18;$c++) {
            $type=strtolower((string)get_post_meta($id,$base.'cell_'.$c.'_type',true));
            $html=(string)get_post_meta($id,$base.'cell_'.$c.'_html',true);
            if (!$html && !$type) { continue; }
            // Never copy prices, offers, Prime status, review ratings or star claims.
            if (preg_match('/price|rating|review-stars|discount|prime|offer|cost/i',$type)) { continue; }
            if ($type==='image' && preg_match('~<img[^>]+src=["\']([^"\']+)~i',$html,$im)) { $image=azlinkpro_mig_image($im[1]); }
            if (in_array($type,array('title','name','product-title'),true)) { $name=sanitize_text_field(wp_strip_all_tags($html)); }
            if (in_array($type,array('features','feature','bullets','description','text','custom-text'),true)) {
                $d=trim(sanitize_text_field(wp_strip_all_tags(str_replace(array('</li>','<br>','<br/>','<br />'),' | ',$html))));
                if ($d!=='') { $details[]=$d; }
            }
            if (in_array($type,array('cta-btn','button','cta'),true)) {
                if (preg_match('~<a[^>]*>(.*?)</a>~si',$html,$label)) { $candidate=trim(wp_strip_all_tags($label[1])); if ($candidate) {$cta=substr(sanitize_text_field($candidate),0,100);} }
            }
            if (strpos($html,'checked="checked"')!==false && strpos($html,'alp-table-top-product')!==false) { $featured=true; }
        }
        $original=azlinkpro_mig_amazon_link((string)get_post_meta($id,$base.'url',true),$asin);
        if ($original) { $links[$asin]=$original; }
        if ($image) { $images[$asin]=$image; }
        $rows[]=array('asin'=>$asin,'name'=>substr($name,0,400),'details'=>substr(implode(' | ',$details),0,4000),
            'review'=>'','featured'=>$featured,'image_id'=>0,'image_size'=>'default','image_custom_px'=>180,
            'amazon_image_key'=>'','cta'=>$cta);
    }
    if (!$rows) { return new WP_Error('azp_mig_no_rows','No valid products in legacy table; original is unchanged.'); }
    if (count($rows)<$n) { return new WP_Error('azp_mig_partial','Some legacy rows have missing or repeated ASINs. Table left untouched for manual review.'); }
    return array('title'=>$source->post_title,'rows'=>azlinkpro_tbl_clean_rows($rows),'urls'=>$links,'images'=>$images);
}
function azlinkpro_mig_existing($key,$value,$type) {
    // Admin-only importer lookup of one migration record.
    $found=get_posts(array('post_type'=>$type,'post_status'=>array('publish','draft','private','pending','future'),
        'meta_key'=>$key,'meta_value'=>$value,'fields'=>'ids','numberposts'=>1)); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
    return $found?(int)$found[0]:0;
}
function azlinkpro_mig_create_table($source_id) {
    $old=azlinkpro_mig_existing('_azp_mig_table_source',(string)$source_id,'atp_table');
    if ($old) { return $old; }
    $data=azlinkpro_mig_table_data($source_id);
    if (is_wp_error($data)) { return $data; }
    $id=wp_insert_post(array('post_type'=>'atp_table','post_status'=>'publish','post_title'=>$data['title']),true);
    if (is_wp_error($id) || !$id) { return new WP_Error('azp_mig_insert','Could not create AzlinkPRO table.'); }
    update_post_meta($id,'_atp_rows',$data['rows']);
    update_post_meta($id,'_atp_ribbon','Top Pick');
    update_post_meta($id,'_atp_header_color','#162337');
    update_post_meta($id,'_atp_disclosure',azlinkpro_tbl_default_disclosure());
    update_post_meta($id,'_azp_mig_table_source',(string)$source_id);
    update_post_meta($id,'_azp_mig_affiliate_urls',$data['urls']);
    update_post_meta($id,'_azp_mig_image_urls',$data['images']);
    return (int)$id;
}
function azlinkpro_mig_tasks() {
    $tables=array();foreach(azlinkpro_mig_source_ids() as $id) { $tables[]=array('kind'=>'table','id'=>(int)$id); }
    $posts=array(); foreach(azlinkpro_mig_content_ids() as $id) { $posts[]=array('kind'=>'post','id'=>(int)$id); }
    return array_merge($tables,$posts);
}
/** Count actual table occurrences; do not count copies inside Gutenberg JSON comments. */
function azlinkpro_mig_table_reference_count($content) {
    $count=0;
    $remaining=preg_replace_callback(
        '~<!--\s*wp:common/insert-amalinkspro-shortcode\b[^\r\n]*-->\s*<div>([\s\S]*?)</div>\s*<!--\s*/wp:common/insert-amalinkspro-shortcode\s*-->~i',
        function($block) use (&$count) {
            $count+=count(azlinkpro_mig_matches(trim($block[1])));
            return '';
        },(string)$content);
    if (!is_string($remaining)) { return $count; }
    // Gutenberg JSON attributes and HTML comments cannot be counted as widgets.
    $remaining=preg_replace('~<!--[\s\S]*?-->~','',$remaining);
    return $count+count(azlinkpro_mig_matches($remaining));
}
function azlinkpro_mig_report() {
    $table_ids=azlinkpro_mig_source_ids();$post_ids=azlinkpro_mig_content_ids();
    $refs=0;$pages=0;
    foreach($post_ids as $id) {
        $p=get_post($id);if (!$p) {continue;}
        $n=azlinkpro_mig_table_reference_count($p->post_content);
        if ($n) {$pages++;$refs+=$n;}
    }
    return array('tables'=>count($table_ids),'pages'=>$pages,'table_refs'=>$refs,
        'too_many'=>count($post_ids)>20000);
}
/** Hide legacy Showcase-migration notices from the table-only workflow. */
function azlinkpro_mig_table_warnings($run) {
    return array_values(array_filter((array)($run['warnings']??array()),function($message){
        return is_string($message) && !preg_match('/showcase|paired AmaLinks/i',$message);
    }));
}
function azlinkpro_mig_admin() {
    if (!azlinkpro_mig_cap()) { return; }
    $stats=azlinkpro_mig_report();$run=get_option(azlinkpro_mig_key(),array());$run=is_array($run)?$run:array();
    $nonce=wp_create_nonce('azp_mig_action');
    ?>
    <div class="wrap"><h1>AzlinkPRO — AmaLinks Pro Table Migration <small style="font-size:.5em">BETA</small></h1>
    <p>Import legacy AmaLinks Pro comparison tables and replace their table shortcodes in posts and pages. <strong>AmaLinks Pro is not removed, and its tables are not edited.</strong> Back up WordPress before migration.</p>
    <div class="notice notice-info inline"><p>All operations run on this website only. Existing Amazon Associates URLs and original image links are preserved when safe. Legacy prices, ratings, stars, discount claims and Prime labels are not imported. AmaLinks showcases, product links and buttons are not migrated or edited. Previously imported AzlinkPRO Showcases remain usable.</p></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;max-width:1000px;margin:18px 0">
      <?php foreach(array('Legacy tables'=>$stats['tables'],'Content items with legacy table shortcodes'=>$stats['pages'],'Table shortcode uses'=>$stats['table_refs']) as $label=>$number): ?>
      <div style="background:#fff;border:1px solid #c3c4c7;padding:18px;border-radius:7px"><div style="font-size:27px;font-weight:700"><?php echo (int)$number; ?></div><div><?php echo esc_html($label); ?></div></div>
      <?php endforeach; ?>
    </div>
    <h2>Conversion preview</h2><p>AmaLinks comparison tables become native AzlinkPRO comparison tables. Only matching table shortcode references are replaced; page URLs and all Showcase content stay unchanged. Unsupported tables are left for manual review.</p>
    <div style="overflow-x:auto;max-width:1000px"><table class="widefat striped"><thead><tr><th>Legacy ID</th><th>Table</th><th>Products</th><th>Conversion readiness</th></tr></thead><tbody>
    <?php foreach(array_slice(azlinkpro_mig_source_ids(),0,100) as $legacy_id): $source=get_post($legacy_id);$preview=azlinkpro_mig_table_data($legacy_id); ?>
       <tr><td><?php echo (int)$legacy_id; ?></td><td><?php echo esc_html($source?$source->post_title:'Unknown'); ?></td>
       <td><?php echo is_wp_error($preview)?'0':count($preview['rows']); ?></td>
       <td><?php echo is_wp_error($preview)?esc_html($preview->get_error_message()):'Ready'; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?php if($stats['tables']>100): ?><p>Showing the first 100 tables; all eligible tables will be processed.</p><?php endif; ?>
    <?php if($stats['too_many']): ?><div class="notice notice-error"><p>More than 20,000 content items need scanning. Bulk migration is disabled; divide the site migration into smaller batches first.</p></div><?php endif; ?>
    <p><button class="button" onclick="location.reload()">Rescan website</button>
    <button id="azp-mig-go" class="button button-primary" <?php disabled(!empty($run['run_id']) || $stats['too_many']); ?>>Convert All (with backup)</button>
    <button id="azp-mig-rollback" class="button" <?php disabled(empty($run['run_id'])); ?>>Rollback last migration</button></p>
    <?php if(!empty($run['run_id'])): ?><p><strong>Last migration:</strong> <?php echo esc_html((string)($run['status']??'unknown')); ?> · <?php echo (int)($run['converted_tables']??0); ?> tables · <?php echo (int)($run['updated_posts']??0); ?> content items changed.</p><?php endif; ?>
    <?php $table_warnings=azlinkpro_mig_table_warnings($run); ?>
    <?php if($table_warnings): ?><details><summary><strong><?php echo count($table_warnings); ?> table items require review</strong></summary><ul><?php foreach($table_warnings as $warning): ?><li><?php echo esc_html($warning); ?></li><?php endforeach; ?></ul></details><?php endif; ?>
    <?php if(($run['status']??'')==='running'): ?><p><button id="azp-mig-resume" class="button button-primary">Resume interrupted conversion</button></p><?php endif; ?>
    <?php if(in_array(($run['status']??''),array('complete','rolled_back'),true)): ?><p><button id="azp-mig-close" class="button">Archive finished migration report</button> <span class="description">This closes the previous rollback session so you can scan and import newly added AmaLinks items. Do not archive until you are happy with the conversion.</span></p><?php endif; ?>
    <div id="azp-mig-message" role="status" aria-live="polite"></div><progress id="azp-mig-progress" max="100" value="0" style="width:min(700px,100%);display:none"></progress>
    <h2>Notes and limitations</h2><p>Some heavily customized AmaLinks table formats require manual review. An existing native table is reused by its recorded source ID. Showcases are not converted or modified. Previously imported Showcases are preserved even when rolling back an older mixed migration. Original post content is backed up; rollback protects later manual edits.</p>
    <script>
    (function(){const endpoint=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, nonce=<?php echo wp_json_encode($nonce); ?>;
    const box=document.getElementById('azp-mig-message'),progress=document.getElementById('azp-mig-progress');
    async function action(op){const body=new URLSearchParams({action:'azp_mig_ajax',nonce,op});
      const resp=await fetch(endpoint,{method:'POST',body,credentials:'same-origin'});const json=await resp.json();
      if(!json.success)throw new Error((json.data&&json.data.message)||'Migration request failed.');return json.data;}
    async function run(op){try{progress.style.display='block';let data=await action(op);
      while(data.more){progress.value=data.total?Math.round(100*data.done/data.total):0;box.textContent=data.message||'Processing…';data=await action((op==='start'||op==='step')?'step':'rollback_step');}
      progress.value=100;box.textContent=data.message||'Completed. Refresh to see the final report.';
      document.getElementById('azp-mig-go').disabled=true;setTimeout(()=>location.reload(),1200);
    }catch(err){box.textContent='Stopped: '+err.message+'  Your original AmaLinks content has not been deleted. Refresh and review progress.';}}
    document.getElementById('azp-mig-go').addEventListener('click',()=>{if(confirm('Convert all supported AmaLinks tables and replace ONLY their table shortcodes? Original content is backed up, and unsupported shortcodes stay unchanged.'))run('start');});
    document.getElementById('azp-mig-rollback').addEventListener('click',()=>{if(confirm('Restore original post/page content from the last migration? Manual edits made afterward are protected and will be skipped.'))run('rollback_start');});
    const resume=document.getElementById('azp-mig-resume');if(resume)resume.addEventListener('click',()=>run('step'));
    const close=document.getElementById('azp-mig-close');if(close)close.addEventListener('click',async()=>{if(!confirm('Archive this migration report and remove its rollback backups? This cannot be undone.'))return;try{await action('archive');location.reload();}catch(e){box.textContent=e.message;}});
    })();
    </script></div>
    <?php
}
function azlinkpro_mig_add_menu() { add_submenu_page('azlinkpro','Migration Tools','Migration Tools','manage_options','azp-migration','azlinkpro_mig_admin'); }
add_action('admin_menu','azlinkpro_mig_add_menu',32);

function azlinkpro_mig_ajax() {
    if (!azlinkpro_mig_cap() || !check_ajax_referer('azp_mig_action','nonce',false)) { wp_send_json_error(array('message'=>'Not authorized.'),403); }
    $op=isset($_POST['op'])?sanitize_key(wp_unslash($_POST['op'])):'';
    $run=get_option(azlinkpro_mig_key(),array());$run=is_array($run)?$run:array();
    if ($op==='archive') {
        if (!in_array(($run['status']??''),array('complete','rolled_back'),true)) { wp_send_json_error(array('message'=>'Only completed migrations can be archived.'),409); }
        foreach(array_keys((array)($run['backups']??array())) as $post_id) { delete_post_meta((int)$post_id,'_azp_mig_original_'.$run['run_id']); }
        delete_option(azlinkpro_mig_key());
        wp_send_json_success(array('more'=>false,'message'=>'Migration report archived.'));
    }
    if ($op==='start') {
        if (!empty($run['run_id'])) { wp_send_json_error(array('message'=>'A migration already exists. Complete, roll back, or archive the previous run before starting another.'),409); }
        $tasks=azlinkpro_mig_tasks();if(count($tasks)>25000){wp_send_json_error(array('message'=>'Too many records for a single migration.'),400);}
        $run=array('run_id'=>wp_generate_uuid4(),'status'=>'running','tasks'=>$tasks,'total'=>count($tasks),'done'=>0,
            'created'=>array(),'backups'=>array(),'updated_posts'=>0,'converted_tables'=>0,'warnings'=>array(), 'table_map'=>array());
        update_option(azlinkpro_mig_key(),$run,false);
        wp_send_json_success(array('more'=>!empty($tasks),'total'=>count($tasks),'done'=>0,'message'=>'Migration started.'));
    }
    if ($op==='step') {
        if (($run['status']??'')!=='running') { wp_send_json_error(array('message'=>'No running migration.'),409); }
        // Prevent two browser tabs from processing the same task at once.
        if (get_transient('azp_mig_step_lock')) { wp_send_json_error(array('message'=>'Another migration request is running. Try again.'),409); }
        set_transient('azp_mig_step_lock',1,20);
        try {
            for($x=0;$x<5 && !empty($run['tasks']);$x++) {
                $task=array_shift($run['tasks']);$result=azlinkpro_mig_perform_task($task,$run);
                if (is_wp_error($result) && count($run['warnings'])<100) { $run['warnings'][]=$task['kind'].' #'.$task['id'].': '.$result->get_error_message(); }
                $run['done']++;
                update_option(azlinkpro_mig_key(),$run,false);
            }
            if (!$run['tasks']) { $run['status']='complete'; update_option(azlinkpro_mig_key(),$run,false); }
        } finally { delete_transient('azp_mig_step_lock'); }
        wp_send_json_success(array('more'=>!empty($run['tasks']),'total'=>(int)$run['total'],'done'=>(int)$run['done'],
            'message'=>!empty($run['tasks'])?'Converting '.$run['done'].' of '.$run['total'].' items…':
            'Table migration complete: '.$run['converted_tables'].' tables, '.$run['updated_posts'].' posts/pages updated. '.count(azlinkpro_mig_table_warnings($run)).' table review notices.'));
    }
    if ($op==='rollback_start') {
        if (empty($run['run_id']) || !in_array(($run['status']??''),array('complete','running'),true)) { wp_send_json_error(array('message'=>'No completed migration to roll back.'),409); }
        $run['status']='rolling_back';$run['rollback_tasks']=array_keys($run['backups']);$run['rollback_skipped']=0;
        update_option(azlinkpro_mig_key(),$run,false);
        wp_send_json_success(array('more'=>true,'total'=>count($run['rollback_tasks']),'done'=>0,'message'=>'Restoring original content…'));
    }
    if ($op==='rollback_step') {
        if (($run['status']??'')!=='rolling_back') { wp_send_json_error(array('message'=>'Rollback is not active.'),409); }
        $start=count($run['backups']);
        for($x=0;$x<5 && !empty($run['rollback_tasks']);$x++) {
            $id=(int)array_shift($run['rollback_tasks']);$post=get_post($id);$backup=$run['backups'][$id]??array();
            if (!$post || !is_array($backup) || hash('sha256',$post->post_content)!==($backup['after_hash']??'')) { $run['rollback_skipped']++; continue; }
            $old=get_post_meta($id,'_azp_mig_original_'.$run['run_id'],true);
            if (!is_string($old)) { $run['rollback_skipped']++; continue; }
            $res=wp_update_post(wp_slash(array('ID'=>$id,'post_content'=>$old)),true);
            if (is_wp_error($res)) { $run['rollback_skipped']++; }
        }
        update_option(azlinkpro_mig_key(),$run,false);
        if (!$run['rollback_tasks']) {
            // Never delete a generated product if any post was edited after migration.
            if (!$run['rollback_skipped']) { foreach($run['created'] as $id) { $p=get_post($id);if($p && $p->post_type==='atp_table') { wp_trash_post($id); } } }
            $run['status']='rolled_back';update_option(azlinkpro_mig_key(),$run,false);
        }
        wp_send_json_success(array('more'=>!empty($run['rollback_tasks']),'total'=>$start,'done'=>$start-count($run['rollback_tasks']),
          'message'=>$run['rollback_tasks']?'Restoring posts/pages…':'Rollback complete. '.$run['rollback_skipped'].' post(s) skipped due to subsequent edits; imported products kept if anything was skipped.'));
    }
    wp_send_json_error(array('message'=>'Invalid operation.'),400);
}
add_action('wp_ajax_azp_mig_ajax','azlinkpro_mig_ajax');
/** Convert a legacy TABLE shortcode only. Every other AmaLinks type is untouched. */
function azlinkpro_mig_convert_table_shortcode($match,&$run,&$warnings) {
    if (strtolower((string)($match[1]??''))!=='amalinkspro_table') { return false; }
    $attrs=azlinkpro_mig_attrs($match);
    $src=isset($attrs['id'])?absint($attrs['id']):0;
    $dest=$src ? ($run['table_map'][(string)$src]??azlinkpro_mig_existing('_azp_mig_table_source',(string)$src,'atp_table')) : 0;
    if (!$src || !$dest) { $warnings[]='Legacy table ID '.$src.' not converted';return false; }
    return '[azlinkpro_table id="'.(int)$dest.'"]';
}
function azlinkpro_mig_perform_task($task,&$run) {
    $id=(int)$task['id'];
    if ($task['kind']==='table') {
        $before=azlinkpro_mig_existing('_azp_mig_table_source',(string)$id,'atp_table');
        $new=azlinkpro_mig_create_table($id);
        if (is_wp_error($new)) { return $new; }
        $run['table_map'][(string)$id]=$new;
        if (!$before) { $run['created'][]=$new;$run['converted_tables']++; }
        return true;
    }
    if ($task['kind']!=='post') { return new WP_Error('azp_mig_task','Unsupported migration task.'); }
    $p=get_post($id);if (!$p) { return new WP_Error('azp_mig_post','Post not found.'); }
    $old=$p->post_content;$warnings=array();
    // Convert complete TABLE Gutenberg blocks. Leave Showcase and all other
    // AmaLinks Gutenberg blocks byte-for-byte unchanged, including their JSON.
    $new=preg_replace_callback(
      '~<!--\s*wp:common/insert-amalinkspro-shortcode\b[^\r\n]*-->\s*<div>([\s\S]*?)</div>\s*<!--\s*/wp:common/insert-amalinkspro-shortcode\s*-->~i',
      function($block) use (&$run,&$warnings) {
        $inside=trim($block[1]);
        // Repair an already converted TABLE that still has a legacy wrapper.
        if (preg_match('~^\[azlinkpro_table\s+id=[\x22\x27]?[0-9]+[\x22\x27]?\]$~i',$inside)) {
            return "<!-- wp:shortcode -->\n".$inside."\n<!-- /wp:shortcode -->";
        }
        if (!preg_match('~^(\[(amalinkspro_table)(?=[\s\]/])([^\]]*)\])$~i',$inside,$found)) {return $block[0];}
        $converted=azlinkpro_mig_convert_table_shortcode(array($found[1],$found[2],$found[3]),$run,$warnings);
        return $converted===false ? $block[0] : "<!-- wp:shortcode -->\n".$converted."\n<!-- /wp:shortcode -->";
      },$old);
    if (!is_string($new)) { return new WP_Error('azp_mig_parse','Could not read Gutenberg content safely.'); }
    // Never convert shortcode-like text within comments (including Gutenberg
    // saved-block JSON). Preserve all non-table shortcodes exactly.
    $pieces=preg_split('~(<!--[\s\S]*?-->)~',$new,-1,PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($pieces)) { return new WP_Error('azp_mig_parse','Could not parse post content safely.'); }
    foreach($pieces as $index=>$segment) {
        if ($index%2) { continue; }
        $segment=preg_replace_callback('~\[(amalinkspro_table)(?=[\s\]/])([^\]]*)\]~i',
          function($m)use(&$run,&$warnings){
            $converted=azlinkpro_mig_convert_table_shortcode($m,$run,$warnings);
            return $converted===false?$m[0]:$converted;
          },$segment);
        if (!is_string($segment)) { return new WP_Error('azp_mig_parse','Could not convert table shortcode.'); }
        $pieces[$index]=$segment;
    }
    $new=implode('',$pieces);
    foreach($warnings as $msg) { if(count($run['warnings'])<100) { $run['warnings'][]='Post #'.$id.': '.$msg; } }
    if ($new===$old) {return true;}
    // Save original content before touching a post. WordPress revisions stay intact.
    // Both calls unslash their input; without wp_slash() every backslash in the
    // post (including the escaped characters in Gutenberg block JSON) would be lost.
    update_post_meta($id,'_azp_mig_original_'.$run['run_id'],wp_slash($old));
    $res=wp_update_post(wp_slash(array('ID'=>$id,'post_content'=>$new)),true);
    if (is_wp_error($res)) {return $res;}
    $run['backups'][(string)$id]=array('after_hash'=>hash('sha256',$new));
    $run['updated_posts']++;
    return true;
}
