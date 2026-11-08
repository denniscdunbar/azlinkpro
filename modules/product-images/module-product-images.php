<?php
/**
 * Amazon Product Images — single clickable, API-fed product photographs.
 * Only the ASIN, image identity and user-authored appearance are stored.
 * Amazon image bytes and URLs are never saved to post meta or Media Library.
 */
if (!defined('ABSPATH')) { exit; }

function azlinkpro_img_register_type() {
    register_post_type('azp_product_image', array(
        'labels'=>array('name'=>'Amazon Product Images','singular_name'=>'Amazon Product Image',
            'all_items'=>'AZ Images','add_new'=>'Add Image','add_new_item'=>'Add Clickable Amazon Image',
            'edit_item'=>'Edit Clickable Amazon Image','menu_name'=>'Amazon Product Images'),
        'public'=>false,'publicly_queryable'=>false,'show_ui'=>true,'show_in_menu'=>'azlinkpro',
        'show_in_rest'=>true,'rest_base'=>'azp_product_images','exclude_from_search'=>true,
        'show_in_nav_menus'=>false,'supports'=>array('title'),
        'capability_type'=>'post','map_meta_cap'=>true,'has_archive'=>false,
    ));
}
add_action('init','azlinkpro_img_register_type');

function azlinkpro_img_valid_key($key) {
    return is_string($key) && preg_match('/^[a-f0-9]{32}$/D',$key) ? $key : '';
}
/** New images start responsive and centred; images saved earlier keep their stored settings. */
function azlinkpro_img_size($size) {
    return in_array((string)$size,array('small','medium','large','full'),true) ? $size : 'full';
}
function azlinkpro_img_alignment($alignment) {
    return in_array((string)$alignment,array('none','left','center','right'),true) ? $alignment : 'center';
}
function azlinkpro_img_button($button) {
    return (string)$button==='small' ? 'small' : 'none';
}
function azlinkpro_img_shortcode($atts) {
    $a=shortcode_atts(array('id'=>0),$atts,'azlinkpro_amazon_image');
    $id=absint($a['id']);
    $post=$id ? get_post($id) : null;
    if (!$post || $post->post_type !== 'azp_product_image' ||
        ($post->post_status !== 'publish' && !current_user_can('edit_post',$id))) { return ''; }
    $asin=azlinkpro_sc_valid_asin((string)get_post_meta($id,'_azp_img_asin',true));
    if (!$asin) { return ''; }
    // The shared Creators API client caches product data for only 12 hours.
    // No stale Amazon image URLs are saved on the image post.
    $product=azlinkpro_sc_get_item_from_api($asin);
    if (is_wp_error($product) || !is_array($product)) { $product=array(); }
    $choices=isset($product['images']) && is_array($product['images']) ? $product['images'] : array();
    $key=azlinkpro_img_valid_key(get_post_meta($id,'_azp_img_image_key',true));
    $selected=null;
    if ($key) {
        foreach ($choices as $choice) {
            if (isset($choice['key']) && hash_equals($key,(string)$choice['key'])) { $selected=$choice; break; }
        }
    }
    if ($selected===null && $choices) { $selected=$choices[0]; }
    $url=is_array($selected) ? azlinkpro_sc_safe_amazon_image((string)($selected['url']??'')) : '';
    if (!$url && ($shared=azlinkpro_asin_photo_src($asin,'large'))) {
        $url=(string)$shared[0];
        $selected=array('width'=>(int)$shared[1],'height'=>(int)$shared[2]);
    }
    if (!$url) { return ''; }
    $destination=azlinkpro_sc_amazon_link($asin);
    if (!$destination || !wp_http_validate_url($destination)) { return ''; }
    $custom_alt=trim((string)get_post_meta($id,'_azp_img_alt',true));
    $fallback_alt=isset($product['title']) && is_string($product['title']) && $product['title']!==''
        ? $product['title'] : 'View product on Amazon';
    $alt=$custom_alt!==''?$custom_alt:$fallback_alt;
    $caption=trim((string)get_post_meta($id,'_azp_img_caption',true));
    $size=azlinkpro_img_size(get_post_meta($id,'_azp_img_size',true));
    $alignment=azlinkpro_img_alignment(get_post_meta($id,'_azp_img_align',true));
    $width=max(0,absint($selected['width']??0));
    $height=max(0,absint($selected['height']??0));
    $dimensions=($width&&$height) ? ' width="'.(int)$width.'" height="'.(int)$height.'"' : '';
    $button=azlinkpro_img_button(get_post_meta($id,'_azp_img_button',true));
    $button_label=azlinkpro_sc_button_label(get_post_meta($id,'_azp_img_button_label',true));
    $figure='<figure class="azp-amazon-product-image azp-image-size-'.esc_attr($size).
        ' azp-image-align-'.esc_attr($alignment).'">';
    $link='<a href="'.esc_url($destination).'" target="_blank" rel="nofollow sponsored noopener"'.
        ' aria-label="'.esc_attr('View '.$alt.' on Amazon').'">';
    $link.='<img src="'.esc_url($url).'" alt="'.esc_attr($alt).'" loading="lazy" decoding="async"'.$dimensions.'>';
    $link.='</a>';
    if ($button==='small') {
        // The stack keeps the button centred under the photo for every alignment.
        $link='<span class="azp-image-stack">'.$link.'<a class="azp-amazon-image-button" href="'.esc_url($destination).
            '" target="_blank" rel="nofollow sponsored noopener">'.esc_html($button_label).'</a></span>';
    }
    $figure.=$link;
    if ($caption!=='') { $figure.='<figcaption>'.esc_html($caption).'</figcaption>'; }
    $figure.='</figure>';
    return $figure;
}
add_shortcode('azlinkpro_amazon_image','azlinkpro_img_shortcode');

function azlinkpro_img_render_block($attributes) {
    return azlinkpro_img_shortcode(array('id'=>absint($attributes['id']??0)));
}
function azlinkpro_img_register_block() {
    wp_register_script('azp-amazon-image-block',plugins_url('assets/block.js',__FILE__),
        array('wp-blocks','wp-element','wp-components','wp-block-editor','wp-server-side-render','wp-api-fetch'),AZLINKPRO_VERSION,true);
    register_block_type('azlinkpro/amazon-image',array(
        'editor_script'=>'azp-amazon-image-block',
        'render_callback'=>'azlinkpro_img_render_block',
        'attributes'=>array('id'=>array('type'=>'number','default'=>0)),
        'supports'=>array('html'=>false),
    ));
}
add_action('init','azlinkpro_img_register_block');

function azlinkpro_img_public_styles() {
    // Image shortcodes may also appear in page builders, reusable blocks or widgets.
    wp_enqueue_style('azp-amazon-product-image',plugins_url('assets/frontend.css',__FILE__),array(),AZLINKPRO_VERSION);
}
add_action('wp_enqueue_scripts','azlinkpro_img_public_styles');

function azlinkpro_img_add_boxes() {
    add_meta_box('azp-img-picker','Amazon Product & Image','azlinkpro_img_picker_box','azp_product_image','normal','high');
    add_meta_box('azp-img-shortcode','Copy Shortcode','azlinkpro_img_shortcode_box','azp_product_image','side','high');
}
add_action('add_meta_boxes','azlinkpro_img_add_boxes');

function azlinkpro_img_shortcode_box($post) {
    if ($post->post_status==='auto-draft') { echo '<p>Save this product image first to obtain its shortcode.</p>';return; }
    echo '<p>Paste this into a Gutenberg Shortcode block, or use the AzlinkPRO Amazon Image block.</p>';
    azlinkpro_copy_shortcode_control('[azlinkpro_amazon_image id="'.absint($post->ID).'"]',get_the_title($post->ID),true);
}

function azlinkpro_img_picker_box($post) {
    wp_nonce_field('azp_img_save','azp_img_nonce');
    $asin=azlinkpro_sc_valid_asin((string)get_post_meta($post->ID,'_azp_img_asin',true));
    $key=azlinkpro_img_valid_key(get_post_meta($post->ID,'_azp_img_image_key',true));
    $alt=(string)get_post_meta($post->ID,'_azp_img_alt',true);
    $caption=(string)get_post_meta($post->ID,'_azp_img_caption',true);
    $size=azlinkpro_img_size(get_post_meta($post->ID,'_azp_img_size',true));
    $align=azlinkpro_img_alignment(get_post_meta($post->ID,'_azp_img_align',true));
    // New images start with the small button; saved images keep their setting.
    $button=$post->post_status==='auto-draft' ? 'small' : azlinkpro_img_button(get_post_meta($post->ID,'_azp_img_button',true));
    $button_label=azlinkpro_sc_button_label(get_post_meta($post->ID,'_azp_img_button_label',true));
    ?>
    <div class="azp-img-editor">
      <p><strong>Pick an image directly from the Amazon Creators API.</strong> It will link to the corresponding Amazon product with your website's configured affiliate tag. Amazon images are served from Amazon, never downloaded to your Media Library.</p>
      <label for="azp-img-search"><strong>Search Amazon products</strong></label>
      <div class="azp-img-row"><input type="search" id="azp-img-search" placeholder="Search Amazon by keyword or product name" maxlength="120" class="regular-text"><button type="button" id="azp-img-search-btn" class="button button-secondary">Search</button></div>
      <div id="azp-img-results" aria-live="polite"></div>
      <button type="button" id="azp-img-search-more" class="button" hidden>Load 10 more Amazon results</button>
      <label for="azp-img-asin"><strong>Or enter an ASIN</strong></label>
      <div class="azp-img-row"><input type="text" id="azp-img-asin" name="azp_img_asin" maxlength="10" pattern="[A-Za-z0-9]{10}" value="<?php echo esc_attr($asin); ?>" placeholder="10-character ASIN"><button type="button" id="azp-img-fetch" class="button button-primary">Fetch Images</button></div>
      <p class="description">Select a photo from the returned gallery. Not all images displayed on Amazon's store are necessarily available through its API.</p>
      <input type="hidden" id="azp-img-image-key" name="azp_img_image_key" value="<?php echo esc_attr($key); ?>">
      <div id="azp-img-notice" role="status" aria-live="polite"></div>
      <div id="azp-img-gallery" role="group" aria-label="Amazon image gallery"></div>
      <hr>
      <div class="azp-img-fields">
        <p><label for="azp-img-size"><strong>Image size</strong></label>
        <select name="azp_img_size" id="azp-img-size"><?php foreach(array('full'=>'Original size (responsive)','small'=>'Small (120px)','medium'=>'Medium (240px)','large'=>'Large (400px)') as $v=>$label): ?><option value="<?php echo esc_attr($v); ?>" <?php selected($size,$v); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></p>
        <p><label for="azp-img-align"><strong>Alignment</strong></label>
        <select name="azp_img_align" id="azp-img-align"><?php foreach(array('center'=>'Center','left'=>'Left (text wraps)','right'=>'Right (text wraps)','none'=>'None (theme default)') as $v=>$label): ?><option value="<?php echo esc_attr($v); ?>" <?php selected($align,$v); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></p>
        <p><label for="azp-img-button"><strong>Amazon button</strong></label>
        <select name="azp_img_button" id="azp-img-button"><?php foreach(array('small'=>'Small gold button below the image','none'=>'No button (image only)') as $v=>$label): ?><option value="<?php echo esc_attr($v); ?>" <?php selected($button,$v); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></p>
        <p><label for="azp-img-button-label"><strong>Gold button text</strong></label><input type="text" id="azp-img-button-label" name="azp_img_button_label" class="regular-text" maxlength="50" value="<?php echo esc_attr($button_label); ?>" placeholder="Check Price"><br><span class="description">Leave empty for Check Price.</span></p>
        <p><label for="azp-img-alt"><strong>Accessible alt text (optional)</strong></label><input type="text" id="azp-img-alt" name="azp_img_alt" class="large-text" maxlength="250" value="<?php echo esc_attr($alt); ?>" placeholder="Defaults to the current Amazon product name"></p>
        <p><label for="azp-img-caption"><strong>Caption (optional)</strong></label><input type="text" id="azp-img-caption" name="azp_img_caption" class="large-text" maxlength="280" value="<?php echo esc_attr($caption); ?>" placeholder="Optional editorial caption"></p>
      </div>
      <p><strong>Save or Publish</strong> to preserve the ASIN, photo choice and display settings. Image URLs are refreshed from Amazon after the short-lived API cache expires.</p>
    </div>
    <?php
}

/** Never blank a saved product if an incomplete editor submission arrives. */
function azlinkpro_img_save_post($id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }
    if (wp_is_post_revision($id) || !current_user_can('edit_post',$id)) { return; }
    if (!isset($_POST['azp_img_nonce']) || !is_string($_POST['azp_img_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['azp_img_nonce'])),'azp_img_save')) { return; }
    $needed=array('azp_img_asin','azp_img_image_key','azp_img_size','azp_img_align','azp_img_alt','azp_img_caption');
    foreach ($needed as $field) { if (!isset($_POST[$field]) || !is_string($_POST[$field])) {return;} }
    // Every field in $needed was checked above.
    // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated
    $asin=azlinkpro_sc_valid_asin(sanitize_text_field(wp_unslash($_POST['azp_img_asin'])));
    if (!$asin) {return;} // Prevent a failed fetch from erasing a previously chosen ASIN.
    $fields=array(
       '_azp_img_asin'=>$asin,
       '_azp_img_image_key'=>azlinkpro_img_valid_key(sanitize_text_field(wp_unslash($_POST['azp_img_image_key']))),
       '_azp_img_size'=>azlinkpro_img_size(sanitize_key(wp_unslash($_POST['azp_img_size']))),
       '_azp_img_align'=>azlinkpro_img_alignment(sanitize_key(wp_unslash($_POST['azp_img_align']))),
       '_azp_img_alt'=>azlinkpro_text_limit(sanitize_text_field(wp_unslash($_POST['azp_img_alt'])),250),
       '_azp_img_caption'=>azlinkpro_text_limit(sanitize_text_field(wp_unslash($_POST['azp_img_caption'])),280),
    );
    // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotValidated
    // Optional so an editor page loaded before this setting existed still saves.
    if (isset($_POST['azp_img_button']) && is_string($_POST['azp_img_button'])) {
        $fields['_azp_img_button']=azlinkpro_img_button(sanitize_key(wp_unslash($_POST['azp_img_button'])));
    }
    if (isset($_POST['azp_img_button_label']) && is_string($_POST['azp_img_button_label'])) {
        $fields['_azp_img_button_label']=azlinkpro_sc_button_label(sanitize_text_field(wp_unslash($_POST['azp_img_button_label'])));
    }
    foreach($fields as $key=>$value) { update_post_meta($id,$key,$value); }
}
add_action('save_post_azp_product_image','azlinkpro_img_save_post');

/** Admin AJAX uses the existing shared API client and its 12-hour image cache. */
function azlinkpro_img_ajax() {
    check_ajax_referer('azp_img_ajax','nonce');
    if (!current_user_can('edit_posts')) { wp_send_json_error(array('message'=>'Not authorized.'),403); }
    $op=isset($_POST['op']) && is_string($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';
    if ($op==='search') {
        $query=isset($_POST['query']) && is_string($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
        $page=isset($_POST['page']) && is_string($_POST['page']) ? max(1,min(10,absint($_POST['page']))) : 1;
        $items=azlinkpro_sc_search_products($query,$page);
        if (is_wp_error($items)) { wp_send_json_error(array('message'=>$items->get_error_message()),400); }
        // Amazon returns 10 results per page, up to 10 pages.
        wp_send_json_success(array('items'=>$items,'has_more'=>$page<10 && count($items)===10));
    }
    if ($op==='fetch') {
        $asin=isset($_POST['asin']) && is_string($_POST['asin']) ? azlinkpro_sc_valid_asin(sanitize_text_field(wp_unslash($_POST['asin']))) : '';
        if (!$asin) { wp_send_json_error(array('message'=>'Enter a valid 10-character ASIN.'),400); }
        $product=azlinkpro_sc_get_item_from_api($asin);
        if (is_wp_error($product)) { wp_send_json_error(array('message'=>$product->get_error_message()),400); }
        // Only the admin sees the short-lived API-provided image URLs.
        wp_send_json_success(array('asin'=>$asin,'title'=>$product['title'],'images'=>$product['images']));
    }
    wp_send_json_error(array('message'=>'Unknown operation.'),400);
}
add_action('wp_ajax_azp_img_ajax','azlinkpro_img_ajax');

function azlinkpro_img_columns($columns) {
    $columns['azp_img_asin']='ASIN';
    $columns['azp_img_shortcode']='Shortcode';
    return $columns;
}
add_filter('manage_azp_product_image_posts_columns','azlinkpro_img_columns');
function azlinkpro_img_column_content($column,$id) {
    if ($column==='azp_img_asin') { echo esc_html(get_post_meta($id,'_azp_img_asin',true)); }
    if ($column==='azp_img_shortcode') {
        azlinkpro_copy_shortcode_control('[azlinkpro_amazon_image id="'.absint($id).'"]',get_the_title($id));
    }
}
add_action('manage_azp_product_image_posts_custom_column','azlinkpro_img_column_content',10,2);

function azlinkpro_img_admin_assets($hook) {
    $screen=function_exists('get_current_screen')?get_current_screen():null;
    if (!$screen || $screen->post_type!=='azp_product_image') { return; }
    if (in_array($hook,array('post-new.php','post.php'),true)) {
        wp_enqueue_style('azp-img-admin',plugins_url('assets/admin.css',__FILE__),array(),AZLINKPRO_VERSION);
        wp_enqueue_script('azp-img-admin',plugins_url('assets/admin.js',__FILE__),array(),AZLINKPRO_VERSION,true);
        wp_localize_script('azp-img-admin','azpImageAdmin',array('ajax'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce('azp_img_ajax')));
    }
}
add_action('admin_enqueue_scripts','azlinkpro_img_admin_assets');
