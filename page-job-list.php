<?php
/**
 * Template Name: 加盟教室用の募集求人一覧ページ
 * * ログインユーザーが投稿した求人を一覧表示するテンプレート
 */

// 専用のヘッダーを読み込み
include(get_stylesheet_directory() . '/agency-header.php');

// メディアアップローダーのスクリプトを読み込む
wp_enqueue_media();
wp_enqueue_script('jquery-ui-sortable');

// ログインチェック
if (!is_user_logged_in()) {
    // 非ログインの場合はログインページにリダイレクト
    wp_redirect(wp_login_url(get_permalink()));
    exit;
}

// 現在のユーザー情報を取得
$current_user = wp_get_current_user();
$current_user_id = $current_user->ID;

// ユーザーが加盟教室（agency）の権限を持っているかチェック
$is_agency = in_array('agency', $current_user->roles);
if (!$is_agency && !current_user_can('administrator')) {
    // 権限がない場合はエラーメッセージ表示
    echo '<div class="error-message">この機能を利用する権限がありません。</div>';
    include(get_stylesheet_directory() . '/agency-footer.php');
    exit;
}

// 共通情報の更新処理
if (isset($_POST['update_common_info']) && isset($_POST['common_nonce']) && 
    wp_verify_nonce($_POST['common_nonce'], 'update_common_info')) {
    
    // 勤務地域の準備
    $job_location_slugs_to_save = array();
    if (!empty($_POST['region_value'])) $job_location_slugs_to_save[] = sanitize_text_field($_POST['region_value']);
    if (!empty($_POST['prefecture_value'])) $job_location_slugs_to_save[] = sanitize_text_field($_POST['prefecture_value']);
    if (!empty($_POST['city_value'])) $job_location_slugs_to_save[] = sanitize_text_field($_POST['city_value']);
    
    // 事業所情報の準備
    $facility_info_to_save = array(
        'facility_name' => sanitize_text_field($_POST['facility_name']),
        'facility_company' => sanitize_text_field($_POST['facility_company']),
        'company_url' => esc_url_raw($_POST['company_url']),
        'facility_zipcode' => sanitize_text_field($_POST['facility_zipcode']),
        'facility_address_detail' => sanitize_text_field($_POST['facility_address_detail']),
        'facility_map' => wp_kses(stripslashes_deep($_POST['facility_map']), array( 
            'iframe' => array(
                'src'             => true,
                'width'           => true,
                'height'          => true,
                'frameborder'     => true,
                'style'           => true,
                'allowfullscreen' => true,
                'loading'         => true, 
                'referrerpolicy'  => true, 
            )
        )),
        'capacity' => sanitize_text_field($_POST['capacity']),
        'staff_composition' => wp_kses_post($_POST['staff_composition']),
        'facility_tel' => sanitize_text_field($_POST['facility_tel']),
        'facility_hours' => sanitize_text_field($_POST['facility_hours']),
        'facility_url' => esc_url_raw($_POST['facility_url'])
    );
    
    // 施設形態の準備
    $facility_type_to_save = isset($_POST['facility_type']) && is_array($_POST['facility_type']) ? array_map('sanitize_text_field', $_POST['facility_type']) : array();

    // 完全な住所の計算
    $prefecture_name_for_address = '';
    $city_name_for_address = '';
    if (!empty($_POST['prefecture_value'])) {
        $term_pref = get_term_by('slug', sanitize_text_field($_POST['prefecture_value']), 'job_location');
        if ($term_pref && !is_wp_error($term_pref)) $prefecture_name_for_address = $term_pref->name;
    }
    if (!empty($_POST['city_value'])) {
        $term_city = get_term_by('slug', sanitize_text_field($_POST['city_value']), 'job_location');
        if ($term_city && !is_wp_error($term_city)) $city_name_for_address = $term_city->name;
    }
    
    $full_address_to_save = '';
    if (!empty($facility_info_to_save['facility_zipcode'])) {
        $full_address_to_save = '〒' . $facility_info_to_save['facility_zipcode'];
    }
    $address_parts_for_full = array_filter([$prefecture_name_for_address, $city_name_for_address, $facility_info_to_save['facility_address_detail']]);
    if (!empty($address_parts_for_full)){
        $full_address_to_save .= (empty($full_address_to_save) ? '' : ' ') . implode(' ', $address_parts_for_full);
    }
    $full_address_to_save = trim($full_address_to_save);

    // ユーザーメタとして共通情報を保存
    update_user_meta($current_user_id, 'common_job_location_slugs', $job_location_slugs_to_save);
    update_user_meta($current_user_id, 'common_facility_info', $facility_info_to_save);
    update_user_meta($current_user_id, 'common_facility_type', $facility_type_to_save);
    update_user_meta($current_user_id, 'common_full_address', $full_address_to_save);
    
    // ユーザーの全求人投稿を取得
    $user_jobs_args_for_update = array(
        'post_type' => 'job',
        'posts_per_page' => -1,
        'author' => $current_user_id,
        'post_status' => array('publish', 'draft', 'pending')
    );
    if (current_user_can('administrator')) { 
        unset($user_jobs_args_for_update['author']);
        // 管理者の場合、特定のユーザーの求人のみを対象にする場合は、
        // 別途ユーザーIDを指定するUIとロジックが必要
    }
    $user_jobs_to_update = get_posts($user_jobs_args_for_update);
    
    // 各求人投稿に共通情報を適用 (既存求人がある場合)
    if (!empty($user_jobs_to_update)) {
        foreach ($user_jobs_to_update as $job_to_update_item) {
            // 勤務地域の更新
            if (!empty($job_location_slugs_to_save)) {
                wp_set_object_terms($job_to_update_item->ID, $job_location_slugs_to_save, 'job_location');
            } else { 
                wp_set_object_terms($job_to_update_item->ID, array(), 'job_location');
            }
            
            // 施設形態の更新
            if (!empty($facility_type_to_save)) {
                wp_set_object_terms($job_to_update_item->ID, $facility_type_to_save, 'facility_type');
            } else { 
                wp_set_object_terms($job_to_update_item->ID, array(), 'facility_type');
            }
            
            // 事業所情報の更新
            foreach ($facility_info_to_save as $key_facility => $value_facility) {
                update_post_meta($job_to_update_item->ID, $key_facility, $value_facility);
            }
            // 完全な住所も更新
            update_post_meta($job_to_update_item->ID, 'facility_address', $full_address_to_save);
        }
    }
    
    $common_update_success = true;
}

// 共通情報をユーザーメタから取得 (表示用)
$saved_common_location_slugs = get_user_meta($current_user_id, 'common_job_location_slugs', true);
$saved_common_facility_info = get_user_meta($current_user_id, 'common_facility_info', true);
$saved_common_facility_type = get_user_meta($current_user_id, 'common_facility_type', true);

// フォーム表示用の値がない場合は、配列や空文字で初期化
if (!is_array($saved_common_location_slugs)) $saved_common_location_slugs = array();
if (!is_array($saved_common_facility_info)) $saved_common_facility_info = array();
if (!is_array($saved_common_facility_type)) $saved_common_facility_type = array();

// ユーザーメタに主要情報がない場合のフォールバック用ID
$sample_job_id_for_fallback_display = null; 
if (empty($saved_common_facility_info['facility_name'])) { 
    $user_jobs_for_sample_fb_display = get_posts(array(
        'post_type' => 'job', 'posts_per_page' => 1,
        'author' => current_user_can('administrator') ? null : $current_user_id,
        'post_status' => array('publish', 'draft', 'pending')
    ));
    if (!empty($user_jobs_for_sample_fb_display)) {
        $sample_job_id_for_fallback_display = $user_jobs_for_sample_fb_display[0]->ID;
    }
}

// 表示用ヘルパー関数
function get_common_info_value_for_display($key_display, $default_display = '') {
    global $saved_common_facility_info, $sample_job_id_for_fallback_display;
    if (isset($saved_common_facility_info[$key_display]) && $saved_common_facility_info[$key_display] !== '') {
        return $saved_common_facility_info[$key_display];
    } elseif ($sample_job_id_for_fallback_display) {
        $meta_value_display = get_post_meta($sample_job_id_for_fallback_display, $key_display, true);
        if ($meta_value_display !== '') return $meta_value_display;
    }
    return $default_display;
}

// JavaScript で使用する初期値のための PHP 変数
$js_initial_region_slug_val = '';
$js_initial_prefecture_slug_val = '';
$js_initial_city_slug_val = '';

if (!empty($saved_common_location_slugs)) {
    if (isset($saved_common_location_slugs[0])) $js_initial_region_slug_val = $saved_common_location_slugs[0];
    if (isset($saved_common_location_slugs[1])) $js_initial_prefecture_slug_val = $saved_common_location_slugs[1];
    if (isset($saved_common_location_slugs[2])) $js_initial_city_slug_val = $saved_common_location_slugs[2];
} elseif ($sample_job_id_for_fallback_display) { 
    $terms_fb_display = wp_get_object_terms($sample_job_id_for_fallback_display, 'job_location', array('fields' => 'all'));
    if(!is_wp_error($terms_fb_display) && !empty($terms_fb_display)){
        foreach($terms_fb_display as $term_fb_display) {
            $ancestors_fb_display = get_ancestors($term_fb_display->term_id, 'job_location', 'taxonomy');
            if (count($ancestors_fb_display) == 0) $js_initial_region_slug_val = $term_fb_display->slug;
            else if (count($ancestors_fb_display) == 1) $js_initial_prefecture_slug_val = $term_fb_display->slug;
            else if (count($ancestors_fb_display) == 2) $js_initial_city_slug_val = $term_fb_display->slug;
        }
    }
}
?>

<div class="job-list-container">
    <h1 class="page-title">求人情報管理</h1>
    
    <div class="job-action-buttons">
        <a href="<?php echo esc_url(home_url('/post-job/')); // 新規投稿ページのURLを適切に設定 ?>" class="btn-new-job">新しい求人を投稿</a>
    </div>

    <?php if (isset($common_update_success) && $common_update_success): ?>
    <div class="status-message success">共通情報を更新しました。</div>
    <?php endif; ?>

    <div class="common-info-section">
        <div class="accordion-header" id="common-info-header">
            <h2 class="section-title">
                <span class="accordion-title">共通情報の編集</span>
                <span class="accordion-icon">▼</span>
            </h2>
            <p class="section-description">ここで設定した情報は、あなたの全ての求人投稿に適用されます。求人がまだない場合でも、ここで設定した情報は保存され、新規求人投稿時に利用されます。</p>
        </div>
        
        <div class="accordion-content" id="common-info-content" style="display: none;">
            <form method="post" class="common-info-form">
                <?php wp_nonce_field('update_common_info', 'common_nonce'); ?>
                
                <div class="form-section">
                    <h3 class="form-section-title">勤務地域</h3>
                    <div class="form-row">
                        <label>勤務地域 <span class="required">*</span></label>
                        <div class="location-selector">
                            <div class="location-level">
                                <select id="region-select" class="location-dropdown">
                                    <option value="">地域を選択</option>
                                    <?php 
                                    $parent_terms_for_form = get_terms(array('taxonomy' => 'job_location','hide_empty' => false,'parent' => 0));
                                    if ($parent_terms_for_form && !is_wp_error($parent_terms_for_form)) {
                                        foreach ($parent_terms_for_form as $term_for_form) {
                                            echo '<option value="' . esc_attr($term_for_form->term_id) . '" data-slug="' . esc_attr($term_for_form->slug) . '">' . esc_html($term_for_form->name) . '</option>';
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="location-level">
                                <select id="prefecture-select" class="location-dropdown" disabled><option value="">都道府県を選択</option></select>
                            </div>
                            <div class="location-level">
                                <select id="city-select" class="location-dropdown" disabled><option value="">市区町村を選択</option></select>
                            </div>
                        </div>
                        <div class="selected-location-display">
                            <span>選択中: </span>
                            <span id="selected-region-text"></span>
                            <span id="selected-prefecture-text"></span>
                            <span id="selected-city-text"></span>
                        </div>
                        <input type="hidden" id="region-value" name="region_value" value="<?php echo esc_attr($js_initial_region_slug_val); ?>">
                        <input type="hidden" id="prefecture-value" name="prefecture_value" value="<?php echo esc_attr($js_initial_prefecture_slug_val); ?>">
                        <input type="hidden" id="city-value" name="city_value" value="<?php echo esc_attr($js_initial_city_slug_val); ?>">
                    </div>
                </div>
                
                <div class="form-section">
                    <h3 class="form-section-title">事業所の情報</h3>
                    <div class="form-row">
                        <label for="facility_name">施設名 <span class="required">*</span></label>
                        <input type="text" id="facility_name" name="facility_name" value="<?php echo esc_attr(get_common_info_value_for_display('facility_name')); ?>" required>
                    </div>
                    <div class="form-row">
                        <label for="facility_company">運営会社名 <span class="required">*</span></label>
                        <input type="text" id="facility_company" name="facility_company" value="<?php echo esc_attr(get_common_info_value_for_display('facility_company')); ?>" required>
                    </div>
                    <div class="form-row">
                        <label for="company_url">運営会社のWebサイトURL</label>
                        <input type="url" id="company_url" name="company_url" value="<?php echo esc_url(get_common_info_value_for_display('company_url')); ?>" placeholder="https://example.com">
                    </div>
                    <div class="form-row">
                        <label>施設住所 <span class="required">*</span></label>
                        <div class="address-container">
                            <div class="address-row">
                                <label for="facility_zipcode">郵便番号 <span class="required">*</span></label>
                                <input type="text" id="facility_zipcode" name="facility_zipcode" value="<?php echo esc_attr(get_common_info_value_for_display('facility_zipcode')); ?>" placeholder="123-4567" required>
                            </div>
                            <div class="address-row">
                                <label>都道府県・市区町村</label>
                                <div id="location_display" class="location-display">
                                    <span class="location-empty">上記で選択した地域が反映されます</span>
                                </div>
                                <p class="form-hint">※ 上記「勤務地域」で選択した都道府県・市区町村が反映されます</p>
                            </div>
                            <div class="address-row">
                                <label for="facility_address_detail">町名番地・ビル名 <span class="required">*</span></label>
                                <input type="text" id="facility_address_detail" name="facility_address_detail" value="<?php echo esc_attr(get_common_info_value_for_display('facility_address_detail')); ?>" placeholder="○○町1-2-3 △△ビル5階" required>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <label for="facility_map">GoogleMap埋め込みコード <span class="required">*</span></label>
                        <textarea id="facility_map" name="facility_map" rows="5" required placeholder="GoogleMapの埋め込みコード（iframeタグ）を貼り付けてください"><?php echo esc_textarea(get_common_info_value_for_display('facility_map')); ?></textarea>
                        <span class="form-hint">GoogleMapで場所を検索後、「共有」→「地図を埋め込む」からiframeコードをコピーしてください。</span>
                    </div>
                    <div class="form-row">
                        <label>施設形態 <span class="required">*</span></label>
                        <div class="taxonomy-select">
                            <?php 
                            $facility_type_terms_for_form = get_terms(array('taxonomy' => 'facility_type','hide_empty' => false));
                            $current_facility_type_slugs_for_form = !empty($saved_common_facility_type) ? $saved_common_facility_type : ($sample_job_id_for_fallback_display ? wp_get_object_terms($sample_job_id_for_fallback_display, 'facility_type', array('fields' => 'slugs')) : array());
                            if ($facility_type_terms_for_form && !is_wp_error($facility_type_terms_for_form)) {
                                foreach ($facility_type_terms_for_form as $term_item_form) {
                                    $checked_facility_attr = (is_array($current_facility_type_slugs_for_form) && in_array($term_item_form->slug, $current_facility_type_slugs_for_form)) ? 'checked' : '';
                                    echo '<label class="radio-label"><input type="radio" name="facility_type[]" value="' . esc_attr($term_item_form->slug) . '" ' . $checked_facility_attr . ' required>' . esc_html($term_item_form->name) . '</label>';
                                }
                            }
                            ?>
                        </div>
                    </div>
                    <div class="form-row">
                        <label for="capacity">利用者定員数</label>
                        <input type="text" id="capacity" name="capacity" value="<?php echo esc_attr(get_common_info_value_for_display('capacity')); ?>" placeholder="例: 20名">
                    </div>
                    <div class="form-row">
                        <label for="staff_composition">スタッフ構成</label>
                        <textarea id="staff_composition" name="staff_composition" rows="4" placeholder="例: 児童発達支援管理責任者1名、指導員2名…"><?php echo esc_textarea(get_common_info_value_for_display('staff_composition')); ?></textarea>
                    </div>
                    <div class="form-row">
                        <label for="facility_tel">施設電話番号</label>
                        <input type="text" id="facility_tel" name="facility_tel" value="<?php echo esc_attr(get_common_info_value_for_display('facility_tel')); ?>" placeholder="03-1234-5678">
                    </div>
                    <div class="form-row">
                        <label for="facility_hours">施設営業時間</label>
                        <textarea id="facility_hours" name="facility_hours" rows="3" placeholder="例: 月～金 9:00～18:00"><?php echo esc_textarea(get_common_info_value_for_display('facility_hours')); ?></textarea>
                    </div>
                    <div class="form-row">
                        <label for="facility_url">施設WebサイトURL</label>
                        <input type="url" id="facility_url" name="facility_url" value="<?php echo esc_url(get_common_info_value_for_display('facility_url')); ?>" placeholder="https://example.com">
                    </div>
                </div>
                
                <div class="form-actions">
                    <input type="submit" name="update_common_info" value="共通情報を更新" class="btn-submit">
                </div>
            </form>
        </div>
    </div>
    <?php
    // ステータスメッセージの表示
    if (isset($_GET['status'])) {
        $status_msg_get = sanitize_text_field($_GET['status']);
        if ($status_msg_get === 'published') {
            echo '<div class="status-message success">求人を公開しました。</div>';
        } elseif ($status_msg_get === 'drafted') {
            echo '<div class="status-message info">求人を下書きに変更しました。</div>';
        } elseif ($status_msg_get === 'deleted') {
            echo '<div class="status-message warning">求人を削除しました。</div>';
        }
    }
    
    // 求人投稿の取得
    $job_query_list_args = array(
        'post_type' => 'job',
        'posts_per_page' => -1, // 全件取得
        'author' => $current_user_id,
        'post_status' => array('publish', 'draft', 'pending') // 表示するステータス
    );
    
    // 管理者の場合は全ユーザーの投稿を表示（オプション）
    if (current_user_can('administrator')) {
        unset($job_query_list_args['author']); // author指定を解除すると全ユーザーの求人が対象になる
    }
    
    $job_query_obj = new WP_Query($job_query_list_args);
    
    if ($job_query_obj->have_posts()) :
    ?>

    <div class="job-lis">
        <div class="job-list-header">
            <div class="job-header-item job-title-header">求人タイトル</div>
            <div class="job-header-item job-status-header">ステータス</div>
            <div class="job-header-item job-date-header">最終更新日</div>
            <div class="job-header-item job-actions-header">操作</div>
        </div>
       
        <?php while ($job_query_obj->have_posts()) : $job_query_obj->the_post(); ?>
        <div class="job-list-item">
            <div class="job-item-cell job-title-cell">
                <a href="<?php the_permalink(); ?>" class="job-title-link"><?php the_title(); ?></a>
                <div class="job-taxonomy-info">
                    <?php
                    // 職種と雇用形態の表示（ヘルパー関数が定義されている前提）
                    if (function_exists('get_job_position_display_text')) {
                        $job_pos_text = get_job_position_display_text(get_the_ID());
                        if (!empty($job_pos_text)) echo '<span class="job-position-tag">' . esc_html($job_pos_text) . '</span>';
                    }
                    if (function_exists('get_job_type_display_text')) {
                        $job_type_text_val = get_job_type_display_text(get_the_ID());
                        if (!empty($job_type_text_val)) echo '<span class="job-type-tag">' . esc_html($job_type_text_val) . '</span>';
                    }
                    ?>
                </div>
            </div>
            <div class="job-item-cell job-status-cell">
                <?php
                $post_status_val = get_post_status();
                $status_label_html = '';
                switch ($post_status_val) {
                    case 'publish': $status_label_html = '<span class="status-publish">公開中</span>'; break;
                    case 'draft': $status_label_html = '<span class="status-draft">下書き</span>'; break;
                    case 'pending': $status_label_html = '<span class="status-pending">承認待ち</span>'; break;
                    default: $status_label_html = '<span class="status-other">' . esc_html($post_status_val) . '</span>';
                }
                echo $status_label_html;
                ?>
            </div>
            <div class="job-item-cell job-date-cell"><?php echo get_the_modified_date('Y年m月d日 H:i'); ?></div>
            <?php
// page-job-list.php の158-165行目あたりを以下に置き換えてください

?>
<div class="job-item-cell job-actions-cell">
    <a href="<?php echo esc_url(home_url('/edit-job/?job_id=' . get_the_ID())); ?>" class="btn-edit">編集</a>
    <?php if (get_post_status() == 'publish') : ?>
    <button class="btn-draft frontend-action" data-action="draft" data-job-id="<?php echo get_the_ID(); ?>" data-nonce="<?php echo wp_create_nonce('frontend_job_action'); ?>">下書き</button>
    <?php else : ?>
    <button class="btn-publish frontend-action" data-action="publish" data-job-id="<?php echo get_the_ID(); ?>" data-nonce="<?php echo wp_create_nonce('frontend_job_action'); ?>">公開</button>
    <?php endif; ?>
    <button class="btn-delete frontend-action" data-action="delete" data-job-id="<?php echo get_the_ID(); ?>" data-nonce="<?php echo wp_create_nonce('frontend_job_action'); ?>">削除</button>
</div>
        </div>
        <?php endwhile; ?>
    <?php
    else :
        echo '<div class="no-jobs-message">';
        echo '<p>投稿した求人情報はありません。</p>';
        echo '<p><a href="' . esc_url(home_url('/post-job/')) . '" class="btn-new-job">最初の求人を投稿する</a></p>'; // 新規投稿ページのURL
        echo '</div>';
    endif;
    wp_reset_postdata();
    ?>
</div>
</div>

<script>
jQuery(document).ready(function($) {
    // アコーディオンの初期設定とクリックイベント
    $('#common-info-content').hide(); 
    $('#common-info-header').on('click', function() {
        var $content = $('#common-info-content');
        var $icon = $(this).find('.accordion-icon');
        $content.slideToggle(300, function() {
            $icon.text($content.is(':visible') ? '▲' : '▼');
        });
        $(this).toggleClass('active');
    });
    
    // --- 勤務地域選択のJavaScript ---
    var initialRegionSlugJS = $('#region-value').val();
    var initialPrefectureSlugJS = $('#prefecture-value').val();
    var initialCitySlugJS = $('#city-value').val();

    // スラッグからタームIDと名前を取得する関数
    function findTermDataBySlugJS(taxonomy, slug, callback) {
        if (!slug) { 
            callback(null, null, null); // ID, Name, Slug
            return; 
        }
        $.ajax({
            url: '<?php echo admin_url('admin-ajax.php'); ?>', 
            type: 'POST', 
            dataType: 'json',
            data: { 
                action: 'get_term_id_by_slug', // functions.php で定義するアクション名
                taxonomy: taxonomy, 
                slug: slug, 
                _wpnonce: '<?php echo wp_create_nonce("get_term_id_by_slug_nonce"); ?>' 
            },
            success: function(response) { 
                if (response.success && response.data && response.data.term_id) {
                    callback(response.data.term_id, response.data.name, slug); // ID, Name, Slug を返す
                } else {
                    console.warn("Term not found for slug via AJAX: " + slug + (response.data && response.data.message ? " - " + response.data.message : ""));
                    callback(null, null, slug); // 見つからなくても slug は返す
                }
            },
            error: function(jqXHR, textStatus, errorThrown) { 
                console.error("AJAX Error in findTermDataBySlugJS for slug: " + slug, textStatus, errorThrown);
                callback(null, null, slug); // エラー時も slug は返す
            }
        });
    }
    
    // 初期ロード時にプルダウンと表示テキストを設定
    function initializeLocationDropdowns() {
        if (initialRegionSlugJS) {
            findTermDataBySlugJS('job_location', initialRegionSlugJS, function(regionId, regionName, regionSlug) {
                if (regionId) {
                    $('#region-select').val(regionId);
                    // 地域が選択されたので、都道府県をロード
                    loadPrefecturesJS(regionId, initialPrefectureSlugJS, initialCitySlugJS, true);
                } else {
                     // 地域IDが見つからなくても、スラッグがあれば表示を試みる (主にデバッグ用)
                    if(regionSlug) $('#selected-region-text').text(escapeHtmlJS(regionSlug) + ' (ID未発見)');
                    updateLocationDisplayInFormJS(); // 住所欄の表示も更新
                }
                // 地域選択後、常に表示テキスト全体を更新
                 updateSelectedLocationTextJS(regionName, null, null);
            });
        } else {
            // 初期地域スラッグがない場合は、テキスト表示のみ更新
            updateSelectedLocationTextJS(null, null, null);
            updateLocationDisplayInFormJS();
        }
    }
    
    initializeLocationDropdowns(); // ページロード時に実行

    // 地域選択プルダウンの変更イベント
    $('#region-select').on('change', function() {
        var regionId = $(this).val();
        var selectedOption = $(this).find('option:selected');
        var regionSlug = selectedOption.data('slug') || '';
        var regionName = selectedOption.text();

        $('#region-value').val(regionSlug);
        // 下位の選択肢と値をクリア
        $('#prefecture-value, #city-value').val('');
        $('#prefecture-select, #city-select').html('<option value="">選択してください</option>').prop('disabled', true);
        
        if (regionId) {
            loadPrefecturesJS(regionId, null, null, false); // 通常の変更なので初期ロードフラグはfalse
        }
        updateSelectedLocationTextJS(regionName, null, null);
        updateLocationDisplayInFormJS();
    });

    // 都道府県選択プルダウンの変更イベント
    $('#prefecture-select').on('change', function() {
        var prefectureId = $(this).val();
        var selectedOption = $(this).find('option:selected');
        var prefectureSlug = selectedOption.data('slug') || '';
        var prefectureName = selectedOption.text();
        
        $('#prefecture-value').val(prefectureSlug);
        // 市区町村の値をクリア
        $('#city-value').val('');
        $('#city-select').html('<option value="">選択してください</option>').prop('disabled', true);
        
        if (prefectureId) {
            loadCitiesJS(prefectureId, null, false); // 通常の変更
        }
        // 地域名はそのままに、都道府県名と市区町村名を更新
        updateSelectedLocationTextJS($('#region-select option:selected').text(), prefectureName, null);
        updateLocationDisplayInFormJS();
    });

    // 市区町村選択プルダウンの変更イベント
    $('#city-select').on('change', function() {
        var selectedOption = $(this).find('option:selected');
        var citySlug = selectedOption.data('slug') || '';
        var cityName = selectedOption.text();

        $('#city-value').val(citySlug);
        // 地域名、都道府県名はそのままに、市区町村名を更新
        updateSelectedLocationTextJS($('#region-select option:selected').text(), $('#prefecture-select option:selected').text(), cityName);
        updateLocationDisplayInFormJS();
    });

    // 都道府県をロードする関数
    function loadPrefecturesJS(regionId, targetPrefectureSlug, targetCitySlug, isInitialLoad) {
        $('#prefecture-select').prop('disabled', true).html('<option value="">読み込み中...</option>');
        $.ajax({
            url: '<?php echo admin_url('admin-ajax.php'); ?>', type: 'POST', dataType: 'json',
            data: { 
                action: 'get_taxonomy_children', 
                taxonomy: 'job_location', 
                parent_id: regionId, 
                _wpnonce: '<?php echo wp_create_nonce("get_taxonomy_children"); ?>'
            },
            success: function(response) {
                var options = '<option value="">都道府県を選択</option>';
                if (response.success && response.data.length > 0) {
                    $.each(response.data, function(i, term) { options += '<option value="' + term.term_id + '" data-slug="' + term.slug + '">' + term.name + '</option>'; });
                    $('#prefecture-select').html(options).prop('disabled', false);
                    
                    if (targetPrefectureSlug) {
                        findTermDataBySlugJS('job_location', targetPrefectureSlug, function(prefId, prefName, prefSlug){
                            if(prefId) {
                                $('#prefecture-select').val(prefId);
                                // 都道府県が選択されたので、市区町村をロード
                                loadCitiesJS(prefId, targetCitySlug, isInitialLoad);
                            } else if (isInitialLoad) {
                                // 初期ロード時、都道府県IDが見つからなくてもテキスト更新は試みる
                                updateSelectedLocationTextJS($('#region-select option:selected').text(), prefName, null);
                            }
                            // 常に住所欄の表示は更新
                            updateLocationDisplayInFormJS();
                        });
                    } else if (isInitialLoad) { // 対象都道府県スラッグがない場合でも、初期ロードならテキスト更新
                         updateSelectedLocationTextJS($('#region-select option:selected').text(), null, null);
                         updateLocationDisplayInFormJS();
                    }
                } else { 
                    $('#prefecture-select').html('<option value="">都道府県がありません</option>'); 
                    if (isInitialLoad) { // 選択肢がなくても初期ロードならテキスト更新
                        updateSelectedLocationTextJS($('#region-select option:selected').text(), null, null);
                        updateLocationDisplayInFormJS();
                    }
                }
            },
            error: function() { 
                $('#prefecture-select').html('<option value="">エラーが発生しました</option>'); 
                if (isInitialLoad) { // エラーでも初期ロードならテキスト更新
                    updateSelectedLocationTextJS($('#region-select option:selected').text(), null, null);
                    updateLocationDisplayInFormJS();
                }
            }
        });
    }

    // 市区町村をロードする関数
    function loadCitiesJS(prefectureId, targetCitySlug, isInitialLoad) {
        $('#city-select').prop('disabled', true).html('<option value="">読み込み中...</option>');
        $.ajax({
            url: '<?php echo admin_url('admin-ajax.php'); ?>', type: 'POST', dataType: 'json',
            data: { 
                action: 'get_taxonomy_children', 
                taxonomy: 'job_location', 
                parent_id: prefectureId, 
                _wpnonce: '<?php echo wp_create_nonce("get_taxonomy_children"); ?>'
            },
            success: function(response) {
                var options = '<option value="">市区町村を選択</option>';
                if (response.success && response.data.length > 0) {
                    $.each(response.data, function(i, term) { options += '<option value="' + term.term_id + '" data-slug="' + term.slug + '">' + term.name + '</option>'; });
                    $('#city-select').html(options).prop('disabled', false);
                    if (targetCitySlug) {
                         findTermDataBySlugJS('job_location', targetCitySlug, function(cityId, cityName, citySlug){
                            if(cityId) $('#city-select').val(cityId);
                            // 市区町村の選択(または失敗)後、テキスト表示を更新
                            updateSelectedLocationTextJS($('#region-select option:selected').text(), $('#prefecture-select option:selected').text(), cityName);
                            updateLocationDisplayInFormJS();
                         });
                    } else if (isInitialLoad) { // 対象市区町村スラッグがない場合でも、初期ロードならテキスト更新
                        updateSelectedLocationTextJS($('#region-select option:selected').text(), $('#prefecture-select option:selected').text(), null);
                        updateLocationDisplayInFormJS();
                    }
                } else { 
                    $('#city-select').html('<option value="">市区町村がありません</option>'); 
                    if (isInitialLoad) {
                        updateSelectedLocationTextJS($('#region-select option:selected').text(), $('#prefecture-select option:selected').text(), null);
                        updateLocationDisplayInFormJS();
                    }
                }
            },
            error: function() { 
                $('#city-select').html('<option value="">エラーが発生しました</option>'); 
                if (isInitialLoad) {
                     updateSelectedLocationTextJS($('#region-select option:selected').text(), $('#prefecture-select option:selected').text(), null);
                     updateLocationDisplayInFormJS();
                }
            }
        });
    }
    
    // 「選択中: 」のテキスト表示を更新する関数
    function updateSelectedLocationTextJS(rName, pName, cName) {
        var regionText = (typeof rName === 'string' && rName !== '地域を選択' && rName !== '') ? escapeHtmlJS(rName) : 
                         ($('#region-select option:selected:not([value=""])').length ? escapeHtmlJS($('#region-select option:selected').text()) : '');
        
        var prefectureText = (typeof pName === 'string' && pName !== '都道府県を選択' && pName !== '読み込み中...' && pName !== '都道府県がありません' && pName !== 'エラーが発生しました' && pName !== '') ? escapeHtmlJS(pName) : 
                              ($('#prefecture-select option:selected:not([value=""])').length ? escapeHtmlJS($('#prefecture-select option:selected').text()) : '');
        
        var cityText = (typeof cName === 'string' && cName !== '市区町村を選択' && cName !== '読み込み中...' && cName !== '市区町村がありません' && cName !== 'エラーが発生しました' && cName !== '') ? escapeHtmlJS(cName) : 
                       ($('#city-select option:selected:not([value=""])').length ? escapeHtmlJS($('#city-select option:selected').text()) : '');

        $('#selected-region-text').text(regionText);
        $('#selected-prefecture-text').text(prefectureText ? ' > ' + prefectureText : '');
        $('#selected-city-text').text(cityText ? ' > ' + cityText : '');
    }

    // フォーム内の住所表示（都道府県・市区町村）を更新する関数
    function updateLocationDisplayInFormJS() {
        // 「選択中: 」のテキストを取得して住所表示欄に反映する方が一貫性があるかもしれない
        var currentPrefectureText = $('#prefecture-select option:selected:not([value=""])').text();
        var currentCityText = $('#city-select option:selected:not([value=""])').text();
        var displayHtmlInForm = '';

        if (currentPrefectureText && !currentPrefectureText.match(/選択|読み込み中|ありません|エラー/)) {
            displayHtmlInForm += escapeHtmlJS(currentPrefectureText);
            if (currentCityText && !currentCityText.match(/選択|読み込み中|ありません|エラー/)) {
                displayHtmlInForm += ' ' + escapeHtmlJS(currentCityText);
            }
        }
        
        if(displayHtmlInForm) {
            $('#location_display').text(displayHtmlInForm);
        } else {
            // 初期値やユーザーメタから復元する場合、PHP側で設定されたスラッグから名前を引いて表示する方が確実
            // JavaScriptの初期化完了後に、PHPから渡されたスラッグを使って名前を取得し、ここに表示する処理を検討
            // ここでは、選択がクリアされた場合の表示
            $('#location_display').html('<span class="location-empty">上記で選択した地域が反映されます</span>');
        }
    }

    // HTMLエスケープ用関数
    function escapeHtmlJS(string) {
        if (typeof string !== 'string') return '';
        return string.replace(/[&<>"']/g, function (match) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[match];
        });
    }
    
    // フロントエンドでのアクション処理 (ステータス変更、削除)
    $('.frontend-action').on('click', function(e) {
        e.preventDefault();
        var $button = $(this);
        var action = $button.data('action');
        var jobId = $button.data('job-id');
        var nonce = $button.data('nonce'); 

        if (action === 'delete' && !confirm('本当にこの求人を削除しますか？この操作は元に戻せません。')) {
            return;
        }
        
        $.ajax({
            url: '<?php echo admin_url('admin-ajax.php'); ?>',
            type: 'POST',
            data: {
                action: 'frontend_' + action + '_job', 
                job_id: jobId,
                nonce: nonce 
            },
            beforeSend: function() {
                $button.prop('disabled', true).text('処理中...');
            },
            success: function(response) {
                if (response.success) {
                    if (response.data && response.data.redirect) {
                        window.location.href = response.data.redirect; 
                    } else {
                        window.location.reload(); // リダイレクト先がない場合はリロード
                    }
                } else {
                    alert( (response.data && response.data.message) ? response.data.message : 'エラーが発生しました。ページを再読み込みしてください。');
                    var originalText = '';
                    if(action === 'draft') originalText = '下書き';
                    else if(action === 'publish') originalText = '公開';
                    else if(action === 'delete') originalText = '削除';
                    $button.prop('disabled', false).text(originalText);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                alert('通信エラーが発生しました: ' + textStatus + ' - ' + errorThrown);
                var originalText = '';
                if(action === 'draft') originalText = '下書き';
                else if(action === 'publish') originalText = '公開';
                else if(action === 'delete') originalText = '削除';
                $button.prop('disabled', false).text(originalText);
            }
        });
    });
});
</script>

<style>
/* アコーディオンのスタイル */
.accordion-header {
    cursor: pointer;
    transition: all 0.3s ease;
    padding: 15px 20px;
    background-color: #f8f9fa;
    border: 1px solid #dee2e6;
    margin-bottom: 0;
}

.accordion-header:hover {
    background-color: #e9ecef;
}

.accordion-header .section-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0;
    border-bottom: none;
    padding-bottom: 0;
}

.accordion-title {
    flex: 1;
}

.accordion-icon {
    font-size: 14px;
    color: #007cba;
    font-weight: bold;
    transition: transform 0.3s ease;
}

.accordion-header.active .accordion-icon {
    transform: rotate(180deg);
}

.accordion-content {
    border: 1px solid #dee2e6;
    border-top: none;
    border-radius: 0 0 8px 8px;
    background-color: #ffffff;
    padding: 20px;
}

/* 共通情報編集フォームのスタイル */
.common-info-section {
    margin-bottom: 30px;
}

.section-description {
    color: #6c757d;
    margin-bottom: 0;
    margin-top: 5px;
    font-size: 14px;
}

.common-info-form .form-section {
    background-color: #ffffff;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    padding: 20px;
    margin-bottom: 20px;
}

.form-section-title {
    font-size: 16px;
    color: #495057;
    margin-top: 0;
    margin-bottom: 15px;
    padding-bottom: 8px;
    border-bottom: 1px solid #e0e0e0;
}

.form-row {
    margin-bottom: 15px;
}

.form-row label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    color: #495057;
}

.form-row input[type="text"],
.form-row input[type="url"],
.form-row textarea,
.form-row select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 14px;
    background-color: #fff;
    box-sizing: border-box;
}

.form-row input[type="text"]:focus,
.form-row input[type="url"]:focus,
.form-row textarea:focus,
.form-row select:focus {
    border-color: #80bdff;
    outline: 0;
    box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
}

.form-hint {
    display: block;
    font-size: 12px;
    color: #6c757d;
    margin-top: 5px;
}

.required {
    color: #dc3545;
}

/* 勤務地域選択のスタイル */
.location-selector {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 15px;
}

.location-level {
    flex: 1;
    min-width: 150px;
}

.location-dropdown {
    width: 100%;
    padding: 8px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    background-color: #fff;
}

.location-dropdown:disabled {
    background-color: #e9ecef;
    cursor: not-allowed;
}

.selected-location-display {
    margin-top: 5px;
    padding: 8px;
    background-color: #e9ecef;
    border-radius: 4px;
    font-size: 14px;
}

.selected-location-display span {
    display: inline-block;
}

/* 住所コンテナのスタイル */
.address-container {
    margin-bottom: 15px;
}

.address-row {
    margin-bottom: 10px;
}

.address-row label {
    display: block;
    margin-bottom: 5px;
    font-weight: normal;
}

#facility_zipcode {
    width: 150px;
}

.location-display {
    padding: 8px;
    background-color: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    min-height: 20px;
}

.location-empty {
    color: #6c757d;
    font-style: italic;
}

/* タクソノミー選択のスタイル */
.taxonomy-select {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.radio-label {
    display: inline-block;
    margin: 0;
    padding: 6px 12px;
    background-color: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    font-size: 14px;
    cursor: pointer;
}

.radio-label:hover {
    background-color: #e9ecef;
}

.radio-label input {
    margin-right: 5px;
}

/* ボタンのスタイル */
.form-actions {
    text-align: center;
    margin-top: 20px;
}

.btn-submit {
    background-color: #007cba;
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 4px;
    font-size: 16px;
    cursor: pointer;
    transition: background-color 0.3s;
}

.btn-submit:hover {
    background-color: #005a87;
}

/* レスポンシブ対応 */
@media (max-width: 768px) {
    .location-selector {
        flex-direction: column;
    }
    
    .location-level {
        width: 100%;
    }
    
    .accordion-header {
        padding: 12px 15px;
    }
    
    .accordion-content {
        padding: 15px;
    }
    
    .common-info-form .form-section {
        padding: 15px;
    }
}
</style>
<?php
// 専用のフッターを読み込み
include(get_stylesheet_directory() . '/agency-footer.php');
?>