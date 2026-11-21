<?php
/**
 * Plugin Name: Dynamic Document & Link QR Portal
 * Plugin URI: https://github.com/solokool66/wp-document-qr-portal
 * Description: High-resolution branded QR code generator, dynamic shortlink router, and document analytics engine for WordPress. Includes live client-side vector Canvas/SVG customization (logos, gradients, dot patterns) and download telemetry.
 * Version: 2.6
 * Author: Solomon Kingsley Eze
 * Author URI: https://linkedin.com/in/solokool
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

// ================================================================
// 1. DATABASE SETUP & MIGRATIONS
// ================================================================
register_activation_hook(__FILE__, 'tka_doc_qr_install_db');

function tka_doc_qr_install_db() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    $table_name = $wpdb->prefix . 'tka_qr_documents';
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        title varchar(255) NOT NULL,
        file_name varchar(255) NOT NULL,
        file_url varchar(1000) NOT NULL,
        text_content longtext NULL,
        file_path varchar(500) NOT NULL,
        file_type varchar(50) NOT NULL,
        file_size bigint(20) NOT NULL DEFAULT 0,
        download_count int(11) NOT NULL DEFAULT 0,
        short_code varchar(100) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY short_code (short_code)
    ) $charset_collate;";

    dbDelta($sql);
}

// Auto-check table exists & migrate columns on admin load
add_action('admin_init', function() {
    global $wpdb;
    $table = $wpdb->prefix . 'tka_qr_documents';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
        tka_doc_qr_install_db();
    } else {
        // Upgrade short_code column to varchar(100) for custom slugs
        $col_code = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'short_code'");
        if (!empty($col_code) && strpos($col_code[0]->Type, 'varchar(20)') !== false) {
            $wpdb->query("ALTER TABLE $table MODIFY short_code varchar(100) NOT NULL");
        }
        // Upgrade file_url column to varchar(1000)
        $col_url = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'file_url'");
        if (!empty($col_url) && strpos($col_url[0]->Type, 'varchar(500)') !== false) {
            $wpdb->query("ALTER TABLE $table MODIFY file_url varchar(1000) NOT NULL");
        }
        // Add text_content column if missing
        $col_text = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'text_content'");
        if (empty($col_text)) {
            $wpdb->query("ALTER TABLE $table ADD text_content longtext NULL AFTER file_url");
        }
    }
});

// Enqueue local/CDN qr-code-styling script
add_action('wp_enqueue_scripts', 'tka_doc_qr_register_assets');
add_action('admin_enqueue_scripts', 'tka_doc_qr_register_assets');

function tka_doc_qr_register_assets() {
    wp_register_script(
        'tka-qr-styling',
        plugins_url('qr-code-styling.js', __FILE__),
        [],
        '1.5.0',
        true
    );
}

// ================================================================
// 2. HELPER FUNCTIONS (SLUGS, SHORTLINKS, URL FORMATTING)
// ================================================================

/**
 * Build clean shortlink URL (e.g. https://yoursite.com/qr/myslug)
 */
function tka_doc_qr_get_shortlink_url($slug) {
    return home_url('/qr/' . rawurlencode($slug));
}

/**
 * Sanitize custom shortlink slug
 */
function tka_doc_qr_sanitize_slug($slug) {
    $slug = trim($slug);
    // Allow alphanumeric, dashes, and underscores
    $slug = preg_replace('/[^a-zA-Z0-9_\-]/', '', $slug);
    return substr($slug, 0, 60);
}

/**
 * Check if a slug conflicts with reserved WordPress paths
 */
function tka_doc_qr_is_slug_reserved($slug) {
    $reserved = [
        'wp-admin', 'wp-content', 'wp-includes', 'feed', 'api', 'admin', 'login',
        'dashboard', 'qr', 'q', 'index', 'xmlrpc', 'robots', 'sitemap', 'page', 'author'
    ];
    return in_array(strtolower($slug), $reserved, true);
}

/**
 * Check if a slug is available in database
 */
function tka_doc_qr_is_slug_available($slug, $exclude_id = 0) {
    global $wpdb;
    $table = $wpdb->prefix . 'tka_qr_documents';
    if (tka_doc_qr_is_slug_reserved($slug)) {
        return false;
    }
    if ($exclude_id > 0) {
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE short_code = %s AND id != %d", $slug, $exclude_id));
    } else {
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE short_code = %s", $slug));
    }
    return intval($count) === 0;
}

/**
 * Generate a unique clean random slug (5 to 6 chars)
 */
function tka_doc_qr_generate_slug($length = 6) {
    global $wpdb;
    $table = $wpdb->prefix . 'tka_qr_documents';
    $chars = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
    for ($i = 0; $i < 15; $i++) {
        $slug = substr(str_shuffle($chars), 0, $length);
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE short_code = %s", $slug));
        if (!$exists) {
            return $slug;
        }
    }
    return substr(md5(uniqid(rand(), true)), 0, $length);
}

// ================================================================
// 3. URL ROUTING & REQUEST INTERCEPTION (/qr/{slug}, /q/{slug}, ?tka_doc=)
// ================================================================
add_action('init', function() {
    // Register standard WordPress rewrite rules
    add_rewrite_rule('^qr/([a-zA-Z0-9_\-]+)/?$', 'index.php?tka_doc=$matches[1]', 'top');
    add_rewrite_rule('^q/([a-zA-Z0-9_\-]+)/?$', 'index.php?tka_doc=$matches[1]', 'top');

    // Intercept incoming requests
    $code = '';
    if (isset($_GET['tka_doc']) && !empty($_GET['tka_doc'])) {
        $code = sanitize_text_field($_GET['tka_doc']);
    } else {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $uri_path = trim(parse_url($request_uri, PHP_URL_PATH) ?? '', '/');

        // If WordPress is installed in a subdirectory, strip home path
        $home_path = trim(parse_url(home_url(), PHP_URL_PATH) ?? '', '/');
        if (!empty($home_path) && strpos($uri_path, $home_path) === 0) {
            $uri_path = trim(substr($uri_path, strlen($home_path)), '/');
        }

        if (preg_match('#^(qr|q)/([a-zA-Z0-9_\-]+)/?$#i', $uri_path, $matches)) {
            $code = sanitize_text_field($matches[2]);
        }
    }

    if (!empty($code)) {
        global $wpdb;
        $table = $wpdb->prefix . 'tka_qr_documents';
        $doc = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE short_code = %s", $code));

        if ($doc) {
            // Increment scan/download count
            $wpdb->query($wpdb->prepare("UPDATE $table SET download_count = download_count + 1 WHERE id = %d", $doc->id));

            // If it's a plain text note, render the reader card
            if ($doc->file_type === 'TEXT') {
                tka_doc_qr_render_text_card($doc);
                exit;
            }

            // Otherwise, redirect immediately to destination URL (Document or Web Link)
            wp_redirect($doc->file_url, 302);
            exit;
        } else {
            tka_doc_qr_render_not_found($code);
            exit;
        }
    }
});

add_filter('query_vars', function($vars) {
    $vars[] = 'tka_doc';
    return $vars;
});

/**
 * Render stylish Elevate Reader Card for Text / Announcement QR Codes
 */
function tka_doc_qr_render_text_card($doc) {
    $title = !empty($doc->title) ? $doc->title : 'Announcement';
    $content = $doc->text_content ?: $doc->file_url;
    $date = date('F j, Y', strtotime($doc->created_at));
    $scans = number_format($doc->download_count);
    $short_url = tka_doc_qr_get_shortlink_url($doc->short_code);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo esc_html($title); ?> &bull; Elevate QR</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
                background: #0f172a;
                color: #0f172a;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 24px;
            }
            .tka-card {
                background: #ffffff;
                width: 100%;
                max-width: 600px;
                border-radius: 28px;
                box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.4);
                overflow: hidden;
                border: 1px solid rgba(255, 255, 255, 0.1);
            }
            .tka-topbar {
                background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
                padding: 24px 30px;
                color: #ffffff;
                display: flex;
                align-items: center;
                justify-content: space-between;
            }
            .tka-badge {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                background: rgba(255, 255, 255, 0.2);
                padding: 4px 12px;
                border-radius: 999px;
                font-size: 11px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 1px;
            }
            .tka-meta-scan {
                font-size: 11px;
                opacity: 0.9;
                font-weight: 600;
            }
            .tka-body {
                padding: 36px 30px;
            }
            .tka-title {
                font-size: 26px;
                font-weight: 900;
                color: #0f172a;
                margin-bottom: 8px;
                line-height: 1.3;
                letter-spacing: -0.5px;
            }
            .tka-date {
                font-size: 12px;
                font-weight: 600;
                color: #64748b;
                margin-bottom: 24px;
                display: flex;
                align-items: center;
                gap: 6px;
            }
            .tka-content-box {
                background: #f8fafc;
                border: 1.5px solid #e2e8f0;
                border-radius: 18px;
                padding: 22px;
                font-size: 15px;
                line-height: 1.7;
                color: #1e293b;
                white-space: pre-wrap;
                word-break: break-word;
                font-family: inherit;
            }
            .tka-content-box a {
                color: #dc2626;
                text-decoration: underline;
                font-weight: 600;
            }
            .tka-footer {
                padding: 20px 30px 28px 30px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                flex-wrap: wrap;
                gap: 12px;
                border-top: 1px solid #f1f5f9;
            }
            .tka-btn-copy {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                background: #0f172a;
                color: #ffffff;
                border: none;
                border-radius: 12px;
                padding: 12px 20px;
                font-size: 13px;
                font-weight: 700;
                cursor: pointer;
                transition: 0.2s;
                font-family: inherit;
            }
            .tka-btn-copy:hover {
                background: #334155;
            }
            .tka-brand-foot {
                font-size: 11px;
                font-weight: 700;
                color: #94a3b8;
                text-transform: uppercase;
                letter-spacing: 0.8px;
            }
        </style>
    </head>
    <body>
        <div class="tka-card">
            <div class="tka-topbar">
                <span class="tka-badge">📝 QR Announcement</span>
                <span class="tka-meta-scan">⚡ <?php echo $scans; ?> views</span>
            </div>
            <div class="tka-body">
                <h1 class="tka-title"><?php echo esc_html($title); ?></h1>
                <div class="tka-date">📅 Created on <?php echo esc_html($date); ?></div>
                <div class="tka-content-box" id="tka-note-text"><?php echo make_clickable(nl2br(esc_html($content))); ?></div>
            </div>
            <div class="tka-footer">
                <button type="button" class="tka-btn-copy" onclick="copyNoteText(this)">
                    <span>📋 Copy Text</span>
                </button>
                <div class="tka-brand-foot">Powered by Elevate QR Studio</div>
            </div>
        </div>
        <script>
        function copyNoteText(btn) {
            var raw = <?php echo json_encode($content); ?>;
            navigator.clipboard.writeText(raw).then(function() {
                btn.innerHTML = '<span>✅ Copied to Clipboard!</span>';
                setTimeout(function() { btn.innerHTML = '<span>📋 Copy Text</span>'; }, 2200);
            });
        }
        </script>
    </body>
    </html>
    <?php
}

/**
 * Render 404 page for missing QR codes or expired slugs
 */
function tka_doc_qr_render_not_found($code) {
    status_header(404);
    nocache_headers();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>QR Link Not Found &bull; Elevate</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                font-family: 'Inter', -apple-system, sans-serif;
                background: #0f172a;
                color: #ffffff;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 24px;
                text-align: center;
            }
            .tka-box {
                background: #1e293b;
                border: 1px solid #334155;
                border-radius: 24px;
                padding: 42px 32px;
                max-width: 480px;
                width: 100%;
                box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
            }
            .tka-icon { font-size: 48px; margin-bottom: 16px; }
            .tka-title { font-size: 24px; font-weight: 900; margin-bottom: 10px; color: #f8fafc; }
            .tka-desc { font-size: 14px; color: #94a3b8; line-height: 1.6; margin-bottom: 24px; }
            .tka-code { font-family: monospace; background: #0f172a; padding: 4px 8px; border-radius: 6px; color: #f87171; }
            .tka-btn { display: inline-block; background: #dc2626; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 12px; font-weight: 800; font-size: 13px; transition: 0.2s; }
            .tka-btn:hover { background: #b91c1c; }
        </style>
    </head>
    <body>
        <div class="tka-box">
            <div class="tka-icon">🔍</div>
            <h1 class="tka-title">QR Code Not Found</h1>
            <p class="tka-desc">The shortlink <span class="tka-code">/qr/<?php echo esc_html($code); ?></span> does not exist or has been removed.</p>
            <a href="<?php echo esc_url(home_url('/')); ?>" class="tka-btn">Return to Home</a>
        </div>
    </body>
    </html>
    <?php
}

// ================================================================
// 4. AJAX CHECK SLUG AVAILABILITY
// ================================================================
add_action('wp_ajax_tka_doc_qr_check_slug', 'tka_doc_qr_ajax_check_slug');
add_action('wp_ajax_nopriv_tka_doc_qr_check_slug', 'tka_doc_qr_ajax_check_slug');

function tka_doc_qr_ajax_check_slug() {
    $raw_slug = sanitize_text_field($_POST['slug'] ?? '');
    $doc_id = intval($_POST['doc_id'] ?? 0);
    $slug = tka_doc_qr_sanitize_slug($raw_slug);

    if (empty($slug)) {
        wp_send_json_error(['message' => 'Slug is empty']);
    }

    if (strlen($slug) < 2) {
        wp_send_json_error(['message' => 'Slug must be at least 2 characters']);
    }

    if (tka_doc_qr_is_slug_reserved($slug)) {
        wp_send_json_error(['message' => "'$slug' is a reserved system path."]);
    }

    if (!tka_doc_qr_is_slug_available($slug, $doc_id)) {
        wp_send_json_error(['message' => "Slug '$slug' is already taken."]);
    }

    wp_send_json_success([
        'available' => true,
        'slug' => $slug,
        'url' => tka_doc_qr_get_shortlink_url($slug),
        'message' => "✓ Available: " . tka_doc_qr_get_shortlink_url($slug)
    ]);
}

// ================================================================
// 5. AJAX UPDATE SLUG HANDLER (FOR QR STUDIO & ADMIN)
// ================================================================
add_action('wp_ajax_tka_doc_qr_update_slug', 'tka_doc_qr_ajax_update_slug');

function tka_doc_qr_ajax_update_slug() {
    check_ajax_referer('tka_doc_qr_nonce', 'security');

    $doc_id = intval($_POST['doc_id'] ?? 0);
    $raw_slug = sanitize_text_field($_POST['new_slug'] ?? '');
    $slug = tka_doc_qr_sanitize_slug($raw_slug);

    if ($doc_id <= 0) {
        wp_send_json_error('Invalid item ID.');
    }

    if (empty($slug) || strlen($slug) < 2) {
        wp_send_json_error('Shortlink slug must be at least 2 characters.');
    }

    if (!tka_doc_qr_is_slug_available($slug, $doc_id)) {
        wp_send_json_error("Slug '$slug' is already taken or reserved. Please choose another.");
    }

    global $wpdb;
    $table = $wpdb->prefix . 'tka_qr_documents';
    $updated = $wpdb->update($table, ['short_code' => $slug], ['id' => $doc_id]);

    if ($updated !== false) {
        $new_url = tka_doc_qr_get_shortlink_url($slug);
        wp_send_json_success([
            'short_code' => $slug,
            'qr_url' => $new_url
        ]);
    } else {
        wp_send_json_error('Database update failed.');
    }
}

// ================================================================
// 6. AJAX CREATE LINK / TEXT QR CODE HANDLER
// ================================================================
add_action('wp_ajax_tka_doc_qr_create_link', 'tka_doc_qr_handle_create_link');
add_action('wp_ajax_nopriv_tka_doc_qr_create_link', 'tka_doc_qr_handle_create_link');

/**
 * Check active shortlink capacity (Community Edition Capped at 5)
 */
function tka_doc_qr_check_limit() {
    $is_pro = (bool) get_option('tka_qr_pro_license', false);
    if ($is_pro) {
        return true;
    }
    global $wpdb;
    $table = $wpdb->prefix . 'tka_qr_documents';
    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
    if ($count >= 5) {
        wp_send_json_error('Community Edition Limit Reached (Max 5 active QR codes / shortlinks). Upgrade to Pro for unlimited dynamic shortlinks, custom domain mapping, and bulk generation!');
        exit;
    }
    return true;
}

function tka_doc_qr_handle_create_link() {
    tka_doc_qr_check_limit();
    check_ajax_referer('tka_doc_qr_nonce', 'security');

    // Security PIN check
    $required_pin = get_option('tka_doc_qr_pin', '');
    if (!empty($required_pin)) {
        $submitted_pin = sanitize_text_field($_POST['upload_pin'] ?? '');
        if ($submitted_pin !== $required_pin) {
            wp_send_json_error('Incorrect Security PIN.');
        }
    }

    $mode = sanitize_text_field($_POST['mode'] ?? 'link');
    $title = sanitize_text_field($_POST['doc_title'] ?? '');
    $raw_custom_slug = sanitize_text_field($_POST['custom_slug'] ?? '');
    
    // Validate or generate short code
    if (!empty($raw_custom_slug)) {
        $short_code = tka_doc_qr_sanitize_slug($raw_custom_slug);
        if (strlen($short_code) < 2) {
            wp_send_json_error('Custom shortlink must be at least 2 characters.');
        }
        if (!tka_doc_qr_is_slug_available($short_code)) {
            wp_send_json_error("Shortlink slug '$short_code' is already taken. Please choose another.");
        }
    } else {
        $short_code = tka_doc_qr_generate_slug(6);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'tka_qr_documents';

    if ($mode === 'text') {
        // Plain Text Note Mode
        $text_content = trim($_POST['text_content'] ?? '');
        if (empty($text_content)) {
            wp_send_json_error('Please enter your text note or announcement content.');
        }

        if (empty($title)) {
            $first_line = strtok($text_content, "\n");
            $title = wp_trim_words($first_line, 6, '...');
            if (empty($title)) {
                $title = 'QR Announcement';
            }
        }

        $short_url = tka_doc_qr_get_shortlink_url($short_code);

        $wpdb->insert($table, [
            'title'          => $title,
            'file_name'      => 'Plain Text Note',
            'file_url'       => $short_url,
            'text_content'   => $text_content,
            'file_path'      => '',
            'file_type'      => 'TEXT',
            'file_size'      => strlen($text_content),
            'download_count' => 0,
            'short_code'     => $short_code,
            'created_at'     => current_time('mysql')
        ]);

        $item_id = $wpdb->insert_id;

        wp_send_json_success([
            'id'          => $item_id,
            'title'       => $title,
            'file_name'   => 'Text Note',
            'file_type'   => 'TEXT',
            'file_size'   => strlen($text_content) . ' chars',
            'direct_url'  => $short_url,
            'qr_url'      => $short_url,
            'short_code'  => $short_code
        ]);

    } else {
        // Web Link / URL Mode
        $destination_url = trim($_POST['destination_url'] ?? '');
        if (empty($destination_url)) {
            wp_send_json_error('Please enter a destination website URL.');
        }

        // Auto-prepend https:// if missing
        if (!preg_match('#^https?://#i', $destination_url)) {
            $destination_url = 'https://' . $destination_url;
        }

        $destination_url = esc_url_raw($destination_url);
        if (!filter_var($destination_url, FILTER_VALIDATE_URL)) {
            wp_send_json_error('Please enter a valid URL (e.g. https://thekingsassembly.org/give).');
        }

        $host = parse_url($destination_url, PHP_URL_HOST) ?: 'Website Link';

        if (empty($title)) {
            $title = $host;
        }

        $short_url = tka_doc_qr_get_shortlink_url($short_code);

        $wpdb->insert($table, [
            'title'          => $title,
            'file_name'      => $host,
            'file_url'       => $destination_url,
            'text_content'   => '',
            'file_path'      => '',
            'file_type'      => 'LINK',
            'file_size'      => 0,
            'download_count' => 0,
            'short_code'     => $short_code,
            'created_at'     => current_time('mysql')
        ]);

        $item_id = $wpdb->insert_id;

        wp_send_json_success([
            'id'          => $item_id,
            'title'       => $title,
            'file_name'   => $host,
            'file_type'   => 'LINK',
            'file_size'   => 'Web Link',
            'direct_url'  => $destination_url,
            'qr_url'      => $short_url,
            'short_code'  => $short_code
        ]);
    }
}

// ================================================================
// 7. AJAX FILE UPLOAD HANDLER (DOCUMENTS)
// ================================================================
add_action('wp_ajax_tka_doc_qr_upload', 'tka_doc_qr_handle_upload');
add_action('wp_ajax_nopriv_tka_doc_qr_upload', 'tka_doc_qr_handle_upload');

function tka_doc_qr_handle_upload() {
    tka_doc_qr_check_limit();
    check_ajax_referer('tka_doc_qr_nonce', 'security');

    // Check optional security PIN if enabled
    $required_pin = get_option('tka_doc_qr_pin', '');
    if (!empty($required_pin)) {
        $submitted_pin = sanitize_text_field($_POST['upload_pin'] ?? '');
        if ($submitted_pin !== $required_pin) {
            wp_send_json_error('Incorrect Upload Security PIN.');
        }
    }

    if (empty($_FILES['doc_file']) || $_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
        wp_send_json_error('No file uploaded or upload error occurred.');
    }

    $file = $_FILES['doc_file'];
    $max_mb = intval(get_option('tka_doc_qr_max_size', 50));
    $max_bytes = $max_mb * 1024 * 1024;

    if ($file['size'] > $max_bytes) {
        wp_send_json_error("File exceeds maximum allowed size of {$max_mb} MB.");
    }

    // Allowed file types
    $allowed_extensions = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'rtf', 'png', 'jpg', 'jpeg', 'webp', 'zip'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($file_ext, $allowed_extensions)) {
        wp_send_json_error('File type not allowed. Allowed types: ' . strtoupper(implode(', ', $allowed_extensions)));
    }

    // Custom Slug validation or generation
    $raw_custom_slug = sanitize_text_field($_POST['custom_slug'] ?? '');
    if (!empty($raw_custom_slug)) {
        $short_code = tka_doc_qr_sanitize_slug($raw_custom_slug);
        if (strlen($short_code) < 2) {
            wp_send_json_error('Custom shortlink must be at least 2 characters.');
        }
        if (!tka_doc_qr_is_slug_available($short_code)) {
            wp_send_json_error("Shortlink slug '$short_code' is already taken. Please choose another.");
        }
    } else {
        $short_code = tka_doc_qr_generate_slug(6);
    }

    require_once(ABSPATH . 'wp-admin/includes/file.php');
    $upload_overrides = ['test_form' => false];
    $uploaded_file = wp_handle_upload($file, $upload_overrides);

    if (isset($uploaded_file['error'])) {
        wp_send_json_error('Upload failed: ' . $uploaded_file['error']);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'tka_qr_documents';

    $title = sanitize_text_field($_POST['doc_title'] ?? '');
    if (empty($title)) {
        $title = pathinfo($file['name'], PATHINFO_FILENAME);
    }

    $wpdb->insert($table, [
        'title'          => $title,
        'file_name'      => sanitize_file_name($file['name']),
        'file_url'       => esc_url_raw($uploaded_file['url']),
        'text_content'   => '',
        'file_path'      => sanitize_text_field($uploaded_file['file']),
        'file_type'      => strtoupper($file_ext),
        'file_size'      => intval($file['size']),
        'download_count' => 0,
        'short_code'     => $short_code,
        'created_at'     => current_time('mysql')
    ]);

    $doc_id = $wpdb->insert_id;
    $qr_destination_url = tka_doc_qr_get_shortlink_url($short_code);

    wp_send_json_success([
        'id'          => $doc_id,
        'title'       => $title,
        'file_name'   => $file['name'],
        'file_type'   => strtoupper($file_ext),
        'file_size'   => size_format($file['size']),
        'direct_url'  => $uploaded_file['url'],
        'qr_url'      => $qr_destination_url,
        'short_code'  => $short_code
    ]);
}

// ================================================================
// 8. AJAX DELETE HANDLER
// ================================================================
add_action('wp_ajax_tka_doc_qr_delete', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    check_ajax_referer('tka_doc_qr_admin_nonce', 'security');

    global $wpdb;
    $table = $wpdb->prefix . 'tka_qr_documents';
    $id = intval($_POST['doc_id'] ?? 0);

    $doc = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
    if ($doc) {
        if (!empty($doc->file_path) && file_exists($doc->file_path)) {
            @unlink($doc->file_path);
        }
        $wpdb->delete($table, ['id' => $id]);
        wp_send_json_success('Item deleted successfully.');
    }
    wp_send_json_error('Item not found.');
});

// ================================================================
// 9. FRONTEND SHORTCODE: [tka_document_qr]
// ================================================================
add_shortcode('tka_document_qr', function($atts) {
    $a = shortcode_atts([
        'title' => 'Elevate QR & Shortlink Studio',
        'subtitle' => 'Create custom-branded QR codes and shortened links for Documents, Websites, and Announcements.',
        'pin' => '',
    ], $atts);

    wp_enqueue_script('jquery');
    wp_enqueue_script('tka-qr-styling');

    $ajax_url = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce('tka_doc_qr_nonce');
    $configured_pin = get_option('tka_doc_qr_pin', '');
    $requires_pin = !empty($configured_pin);
    $script_local_url = plugins_url('qr-code-styling.js', __FILE__);
    $short_domain = str_replace(['http://', 'https://'], '', home_url('/qr/'));

    ob_start();
    ?>
    <!-- QR Code Styling with local and CDN fallback -->
    <script>
    if (typeof QRCodeStyling === 'undefined') {
        var s = document.createElement('script');
        s.src = '<?php echo esc_url($script_local_url); ?>';
        s.onerror = function() {
            var cdn = document.createElement('script');
            cdn.src = 'https://cdn.jsdelivr.net/npm/qr-code-styling@1.5.0/lib/qr-code-styling.js';
            document.head.appendChild(cdn);
        };
        document.head.appendChild(s);
    }
    </script>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap');
        
        .tka-dqr-container {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            width: 100%;
            max-width: 650px;
            margin: 30px auto;
            box-sizing: border-box;
            transition: max-width 0.35s ease;
        }
        .tka-dqr-container.studio-mode {
            max-width: 980px;
        }
        .tka-dqr-container *, .tka-dqr-container *::before, .tka-dqr-container *::after {
            box-sizing: border-box;
        }

        .tka-dqr-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 28px;
            box-shadow: 0 20px 45px -12px rgba(15, 23, 42, 0.08), 0 0 0 1px rgba(15, 23, 42, 0.03);
            padding: 40px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        .tka-dqr-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 5px;
            background: linear-gradient(90deg, #dc2626, #ea580c, #f59e0b, #ec4899);
        }

        .tka-dqr-header {
            text-align: center;
            margin-bottom: 24px;
        }
        .tka-dqr-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #dc2626;
            background: #fef2f2;
            border: 1px solid #fee2e2;
            padding: 5px 14px;
            border-radius: 999px;
            margin-bottom: 12px;
        }
        .tka-dqr-title {
            font-size: 28px;
            font-weight: 900;
            color: #0f172a;
            margin: 0 0 8px 0;
            line-height: 1.2;
            letter-spacing: -0.6px;
        }
        .tka-dqr-subtitle {
            font-size: 14px;
            color: #64748b;
            margin: 0;
            line-height: 1.5;
        }

        /* FORMAT SWITCHER TABS */
        .tka-format-tabs {
            display: flex;
            background: #f1f5f9;
            padding: 5px;
            border-radius: 16px;
            gap: 6px;
            margin-bottom: 24px;
        }
        .tka-format-tab {
            flex: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 14px;
            border: none;
            background: transparent;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 800;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            font-family: inherit;
        }
        .tka-format-tab:hover {
            color: #0f172a;
        }
        .tka-format-tab.active {
            background: #ffffff;
            color: #dc2626;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.04);
        }

        /* PANELS */
        .tka-panel-mode {
            display: none;
            animation: tkaPanelIn 0.25s ease;
        }
        .tka-panel-mode.active {
            display: block;
        }
        @keyframes tkaPanelIn {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* DROPZONE (FILE) */
        .tka-dqr-dropzone {
            border: 2px dashed #cbd5e1;
            border-radius: 22px;
            padding: 38px 20px;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            text-align: center;
            position: relative;
        }
        .tka-dqr-dropzone:hover, .tka-dqr-dropzone.dragover {
            border-color: #dc2626;
            background: #fff5f5;
            transform: translateY(-2px);
            box-shadow: 0 12px 24px -8px rgba(220, 38, 38, 0.12);
        }
        .tka-dqr-drop-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            background: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.06);
            margin-bottom: 12px;
            transition: transform 0.2s;
        }
        .tka-dqr-dropzone:hover .tka-dqr-drop-icon {
            transform: scale(1.08);
        }
        .tka-dqr-drop-title {
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
            margin: 0 0 4px 0;
        }
        .tka-dqr-drop-sub {
            font-size: 12px;
            color: #64748b;
            margin: 0 0 12px 0;
        }
        .tka-dqr-chips {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 6px;
        }
        .tka-chip {
            font-size: 10px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            background: #e2e8f0;
            color: #475569;
            font-family: monospace;
        }

        /* INPUTS */
        .tka-dqr-input {
            width: 100%;
            padding: 14px 18px;
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            font-size: 14px;
            font-family: inherit;
            margin-top: 14px;
            outline: none;
            transition: 0.2s;
            background: #ffffff;
            color: #0f172a;
        }
        .tka-dqr-input:focus {
            border-color: #dc2626;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }
        textarea.tka-dqr-input {
            resize: vertical;
            min-height: 110px;
            line-height: 1.6;
        }

        /* CUSTOM SHORTLINK FIELD */
        .tka-shortlink-box {
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 16px;
            padding: 14px 16px;
            margin-top: 16px;
        }
        .tka-shortlink-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        .tka-shortlink-lbl {
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #334155;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .tka-slug-indicator {
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
        }
        .tka-slug-indicator.avail { color: #059669; font-weight: 800; }
        .tka-slug-indicator.taken { color: #dc2626; font-weight: 800; }
        .tka-slug-indicator.checking { color: #f59e0b; font-weight: 700; }

        .tka-slug-input-row {
            display: flex;
            align-items: center;
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 12px;
            overflow: hidden;
            transition: 0.2s;
        }
        .tka-slug-input-row:focus-within {
            border-color: #dc2626;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }
        .tka-slug-domain-prefix {
            background: #f1f5f9;
            color: #64748b;
            font-size: 12px;
            font-weight: 700;
            padding: 12px 14px;
            border-right: 1px solid #e2e8f0;
            user-select: none;
            white-space: nowrap;
        }
        .tka-slug-input-field {
            flex: 1;
            border: none;
            outline: none;
            padding: 12px 14px;
            font-size: 13px;
            font-family: monospace;
            font-weight: 600;
            color: #0f172a;
            background: transparent;
        }
        .tka-slug-btn-rand {
            background: transparent;
            border: none;
            padding: 8px 12px;
            cursor: pointer;
            font-size: 16px;
            color: #64748b;
            transition: 0.2s;
        }
        .tka-slug-btn-rand:hover {
            color: #dc2626;
            transform: rotate(45deg);
        }
        .tka-slug-hint {
            font-size: 11px;
            color: #64748b;
            margin: 6px 0 0 0;
        }

        /* PRIMARY BUTTON */
        .tka-dqr-btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            color: #ffffff;
            border: none;
            border-radius: 16px;
            font-size: 15px;
            font-weight: 800;
            letter-spacing: 0.5px;
            cursor: pointer;
            margin-top: 20px;
            transition: all 0.25s ease;
            box-shadow: 0 10px 24px -6px rgba(220, 38, 38, 0.35);
            font-family: inherit;
        }
        .tka-dqr-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 30px -6px rgba(220, 38, 38, 0.45);
        }
        .tka-dqr-btn-primary:disabled {
            background: #94a3b8;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }

        .tka-dqr-progress-wrap {
            display: none;
            margin-top: 20px;
        }
        .tka-dqr-progress-bar {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 99px;
            overflow: hidden;
        }
        .tka-dqr-progress-fill {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #f59e0b, #dc2626);
            transition: width 0.25s ease;
        }
        .tka-dqr-progress-lbl {
            font-size: 12px;
            color: #64748b;
            margin-top: 6px;
            font-weight: 600;
            text-align: center;
        }

        /* ================= STUDIO LAYOUT ================= */
        .tka-studio-view {
            display: none;
            animation: tkaSlideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes tkaSlideUp {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .tka-studio-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            padding: 14px 20px;
            margin-bottom: 25px;
        }
        .tka-studio-docinfo {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .tka-file-badge {
            padding: 6px 12px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 900;
            font-family: monospace;
            background: #fee2e2;
            color: #991b1b;
        }
        .tka-file-badge.badge-link { background: #e0e7ff; color: #3730a3; }
        .tka-file-badge.badge-text { background: #f3e8ff; color: #6b21a8; }
        .tka-file-badge.badge-doc, .tka-file-badge.badge-docx { background: #dbeafe; color: #1e40af; }
        .tka-file-badge.badge-xls, .tka-file-badge.badge-xlsx { background: #dcfce7; color: #166534; }
        .tka-file-badge.badge-ppt, .tka-file-badge.badge-pptx { background: #ffedd5; color: #9a3412; }
        .tka-file-badge.badge-png, .tka-file-badge.badge-jpg, .tka-file-badge.badge-webp { background: #ccfbf1; color: #115e59; }

        .tka-studio-grid {
            display: grid;
            grid-template-columns: 360px 1fr;
            gap: 30px;
            align-items: start;
        }
        @media (max-width: 820px) {
            .tka-studio-grid { grid-template-columns: 1fr; }
        }

        /* PREVIEW COLUMN (LEFT) */
        .tka-preview-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 24px;
            padding: 24px;
            box-shadow: 0 15px 35px -8px rgba(15, 23, 42, 0.07);
            text-align: center;
        }
        .tka-qr-canvas-wrap {
            display: flex;
            align-items: center;
            justify-content: center;
            background: #ffffff;
            border-radius: 20px;
            padding: 16px;
            margin: 0 auto 16px auto;
            min-height: 310px;
            border: 1.5px solid #f1f5f9;
            box-shadow: inset 0 2px 6px rgba(0,0,0,0.02);
            position: relative;
        }
        .tka-qr-canvas-wrap canvas, .tka-qr-canvas-wrap svg {
            display: block;
            max-width: 100%;
            height: auto;
            border-radius: 12px;
        }
        .tka-frame-caption {
            display: inline-block;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: #0f172a;
            background: #f1f5f9;
            padding: 5px 14px;
            border-radius: 20px;
            margin-top: 10px;
        }

        .tka-url-box {
            display: flex;
            align-items: center;
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            padding: 6px 10px;
            margin: 16px 0 10px 0;
            gap: 6px;
        }
        .tka-url-input {
            border: none;
            background: transparent;
            font-family: monospace;
            font-size: 12px;
            font-weight: 700;
            color: #0f172a;
            flex: 1;
            outline: none;
        }
        .tka-btn-copy {
            background: #0f172a;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            padding: 7px 12px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            transition: 0.2s;
            font-family: inherit;
        }
        .tka-btn-copy:hover {
            background: #334155;
        }

        /* INLINE SLUG EDIT IN STUDIO */
        .tka-slug-edit-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            padding: 0 4px;
        }
        .tka-btn-slug-edit {
            background: transparent;
            border: none;
            color: #dc2626;
            font-size: 11px;
            font-weight: 800;
            cursor: pointer;
            padding: 4px;
            text-decoration: underline;
        }
        .tka-inline-edit-box {
            display: none;
            background: #f1f5f9;
            padding: 10px;
            border-radius: 12px;
            margin-bottom: 14px;
        }

        .tka-export-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 10px;
        }
        .tka-btn-export {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 13px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 800;
            cursor: pointer;
            text-decoration: none;
            border: none;
            transition: all 0.2s ease;
            font-family: inherit;
        }
        .tka-btn-png {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #ffffff;
            box-shadow: 0 6px 16px -4px rgba(15, 23, 42, 0.3);
        }
        .tka-btn-png:hover {
            transform: translateY(-1px);
            background: #000000;
        }
        .tka-btn-svg {
            background: #f8fafc;
            color: #0f172a;
            border: 1.5px solid #cbd5e1;
        }
        .tka-btn-svg:hover {
            background: #e2e8f0;
        }
        .tka-btn-link {
            width: 100%;
            background: transparent;
            border: 1px dashed #cbd5e1;
            color: #64748b;
            padding: 11px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 10px;
            transition: 0.2s;
            text-align: center;
            display: block;
            text-decoration: none;
        }
        .tka-btn-link:hover {
            border-color: #dc2626;
            color: #dc2626;
            background: #fff5f5;
        }

        /* CONTROLS COLUMN (RIGHT) */
        .tka-studio-panel {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 24px;
            padding: 24px;
            box-shadow: 0 15px 35px -8px rgba(15, 23, 42, 0.05);
        }
        .tka-tabs-nav {
            display: flex;
            background: #f1f5f9;
            padding: 4px;
            border-radius: 14px;
            margin-bottom: 22px;
            gap: 4px;
        }
        .tka-tab-btn {
            flex: 1;
            padding: 10px 8px;
            border: none;
            background: transparent;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 800;
            color: #64748b;
            cursor: pointer;
            transition: 0.2s;
            text-align: center;
            font-family: inherit;
        }
        .tka-tab-btn.active {
            background: #ffffff;
            color: #0f172a;
            box-shadow: 0 3px 10px rgba(0,0,0,0.06);
        }

        .tka-tab-content {
            display: none;
        }
        .tka-tab-content.active {
            display: block;
            animation: tkaTabFade 0.25s ease;
        }
        @keyframes tkaTabFade {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .tka-control-group {
            margin-bottom: 22px;
        }
        .tka-control-group:last-child {
            margin-bottom: 0;
        }
        .tka-control-lbl {
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #475569;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* PRESET PALETTES */
        .tka-preset-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-bottom: 16px;
        }
        .tka-preset-pill {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 10px;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            background: #f8fafc;
            cursor: pointer;
            font-size: 11px;
            font-weight: 700;
            color: #1e293b;
            transition: all 0.2s;
        }
        .tka-preset-pill:hover, .tka-preset-pill.active {
            border-color: #dc2626;
            background: #fff5f5;
            color: #dc2626;
        }
        .tka-preset-dot {
            width: 14px;
            height: 14px;
            border-radius: 99px;
            flex-shrink: 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.15);
        }

        /* COLOR PICKERS ROW */
        .tka-color-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        .tka-color-field {
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            padding: 10px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .tka-color-info {
            display: flex;
            flex-direction: column;
        }
        .tka-color-title {
            font-size: 11px;
            font-weight: 800;
            color: #334155;
        }
        .tka-color-hex {
            font-size: 11px;
            font-family: monospace;
            color: #64748b;
        }
        .tka-color-input {
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            padding: 0;
            background: transparent;
        }

        /* SEGMENTED BUTTONS */
        .tka-seg-group {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .tka-seg-btn {
            flex: 1;
            min-width: 80px;
            padding: 9px 10px;
            border: 1.5px solid #e2e8f0;
            background: #f8fafc;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 800;
            color: #475569;
            cursor: pointer;
            transition: 0.2s;
            text-align: center;
            font-family: inherit;
        }
        .tka-seg-btn:hover, .tka-seg-btn.active {
            border-color: #dc2626;
            background: #dc2626;
            color: #ffffff;
            box-shadow: 0 4px 12px -2px rgba(220, 38, 38, 0.3);
        }

        /* LOGO PRESETS */
        .tka-logo-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-bottom: 14px;
        }
        .tka-logo-item {
            border: 1.5px solid #e2e8f0;
            background: #f8fafc;
            border-radius: 14px;
            padding: 12px 6px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .tka-logo-item:hover, .tka-logo-item.active {
            border-color: #dc2626;
            background: #fff5f5;
        }
        .tka-logo-item-icon {
            font-size: 20px;
            display: block;
            margin-bottom: 4px;
        }
        .tka-logo-item-lbl {
            font-size: 10px;
            font-weight: 800;
            color: #1e293b;
        }

        /* UPLOAD LOGO BUTTON */
        .tka-logo-upload-box {
            border: 2px dashed #cbd5e1;
            border-radius: 14px;
            padding: 14px;
            text-align: center;
            background: #f8fafc;
            cursor: pointer;
            transition: 0.2s;
            margin-bottom: 16px;
        }
        .tka-logo-upload-box:hover {
            border-color: #dc2626;
            background: #fff5f5;
        }
        .tka-logo-upload-text {
            font-size: 12px;
            font-weight: 700;
            color: #334155;
            margin: 0;
        }

        /* SLIDER */
        .tka-slider-wrap {
            margin-top: 6px;
        }
        .tka-slider {
            width: 100%;
            height: 6px;
            border-radius: 3px;
            background: #e2e8f0;
            outline: none;
            -webkit-appearance: none;
            accent-color: #dc2626;
        }
    </style>

    <div class="tka-dqr-container" id="tka-dqr-container">
        <div class="tka-dqr-card">
            
            <!-- CREATION SECTION -->
            <div id="tka-dqr-upload-section">
                <div class="tka-dqr-header">
                    <span class="tka-dqr-pill">⚡ Elevate QR Studio</span>
                    <h2 class="tka-dqr-title"><?php echo esc_html($a['title']); ?></h2>
                    <p class="tka-dqr-subtitle"><?php echo esc_html($a['subtitle']); ?></p>
                </div>

                <!-- MODE SELECTOR TABS -->
                <div class="tka-format-tabs" id="tka-format-tabs">
                    <button type="button" class="tka-format-tab active" data-mode="file" onclick="tkaSelectMode('file', this)">
                        <span>📄 Document File</span>
                    </button>
                    <button type="button" class="tka-format-tab" data-mode="link" onclick="tkaSelectMode('link', this)">
                        <span>🔗 Web Link / URL</span>
                    </button>
                    <button type="button" class="tka-format-tab" data-mode="text" onclick="tkaSelectMode('text', this)">
                        <span>📝 Plain Text / Note</span>
                    </button>
                </div>

                <form id="tka-dqr-form" enctype="multipart/form-data">
                    <input type="hidden" id="tka-current-mode" name="mode" value="file">

                    <!-- PANEL 1: DOCUMENT FILE UPLOAD -->
                    <div class="tka-panel-mode active" id="tka-panel-file">
                        <input type="file" id="tka-dqr-file" name="doc_file" style="display:none;" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.png,.jpg,.jpeg,.webp,.zip">
                        
                        <div class="tka-dqr-dropzone" id="tka-dqr-dropzone" onclick="document.getElementById('tka-dqr-file').click();">
                            <div class="tka-dqr-drop-icon" id="tka-drop-icon">📄</div>
                            <p class="tka-dqr-drop-title" id="tka-dqr-drop-label">Choose a Document or Drag &amp; Drop here</p>
                            <p class="tka-dqr-drop-sub">Fast cloud upload &bull; Max 50MB per document</p>
                            <div class="tka-dqr-chips">
                                <span class="tka-chip">PDF</span>
                                <span class="tka-chip">WORD</span>
                                <span class="tka-chip">EXCEL</span>
                                <span class="tka-chip">PPT</span>
                                <span class="tka-chip">IMAGES</span>
                                <span class="tka-chip">ZIP</span>
                            </div>
                        </div>
                    </div>

                    <!-- PANEL 2: WEB LINK / URL -->
                    <div class="tka-panel-mode" id="tka-panel-link">
                        <input type="url" id="tka-destination-url" name="destination_url" class="tka-dqr-input" style="margin-top:0;" placeholder="https://example.com/giving or any website link">
                        <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:8px;">
                            <span class="tka-chip" style="cursor:pointer;" onclick="tkaPrefillUrl('https://')">https://</span>
                            <span class="tka-chip" style="cursor:pointer;" onclick="tkaPrefillUrl('https://thekingsassembly.org/')">thekingsassembly.org</span>
                            <span class="tka-chip" style="cursor:pointer;" onclick="tkaPrefillUrl('https://forms.gle/')">forms.gle</span>
                            <span class="tka-chip" style="cursor:pointer;" onclick="tkaPrefillUrl('https://wa.me/')">WhatsApp</span>
                        </div>
                    </div>

                    <!-- PANEL 3: PLAIN TEXT / NOTE -->
                    <div class="tka-panel-mode" id="tka-panel-text">
                        <textarea id="tka-text-content" name="text_content" class="tka-dqr-input" style="margin-top:0;" placeholder="Type or paste announcement text, church event instructions, scripture note, or contact info..."></textarea>
                    </div>

                    <!-- COMMON TITLE INPUT -->
                    <input type="text" name="doc_title" class="tka-dqr-input" placeholder="Title / Label (Optional)" id="tka-dqr-title-input">

                    <!-- CUSTOM SHORTLINK BOX (SHORTEN LINK & CUSTOM SLUG) -->
                    <div class="tka-shortlink-box">
                        <div class="tka-shortlink-top">
                            <label class="tka-shortlink-lbl">
                                <span>⚡ Custom Shortlink (Optional)</span>
                            </label>
                            <span class="tka-slug-indicator" id="tka-slug-indicator">Auto-generated if blank</span>
                        </div>
                        <div class="tka-slug-input-row">
                            <span class="tka-slug-domain-prefix"><?php echo esc_html($short_domain); ?></span>
                            <input type="text" id="tka-custom-slug" name="custom_slug" class="tka-slug-input-field" placeholder="custom-slug" maxlength="60" autocomplete="off" spellcheck="false">
                            <button type="button" class="tka-slug-btn-rand" onclick="tkaGenerateRandomSlug()" title="Generate random short code">🎲</button>
                        </div>
                        <p class="tka-slug-hint">Letters, numbers, and hyphens (e.g. <code>bulletin</code>, <code>giving</code>, <code>event2026</code>)</p>
                    </div>

                    <?php if ($requires_pin): ?>
                        <input type="password" name="upload_pin" class="tka-dqr-input" placeholder="Security PIN Required" required id="tka-dqr-pin-input">
                    <?php endif; ?>

                    <div class="tka-dqr-progress-wrap" id="tka-dqr-progress">
                        <div class="tka-dqr-progress-bar">
                            <div class="tka-dqr-progress-fill" id="tka-dqr-progress-fill"></div>
                        </div>
                        <div class="tka-dqr-progress-lbl" id="tka-dqr-progress-label">Processing...</div>
                    </div>

                    <button type="submit" class="tka-dqr-btn-primary" id="tka-dqr-submit-btn">
                        <span id="tka-submit-btn-text">⚡ Upload &amp; Open QR Studio</span>
                    </button>
                </form>
            </div>

            <!-- RESULT STUDIO SECTION -->
            <div class="tka-studio-view" id="tka-studio-view">
                
                <!-- TOP BAR -->
                <div class="tka-studio-topbar">
                    <div class="tka-studio-docinfo">
                        <span class="tka-file-badge" id="tka-res-badge">LINK</span>
                        <div>
                            <h3 id="tka-res-title" style="margin:0; font-size:16px; font-weight:800; color:#0f172a;">Item Title</h3>
                            <span id="tka-res-meta" style="font-size:11px; color:#64748b; font-weight:600;">Shortlink Active</span>
                        </div>
                    </div>
                    <button type="button" class="tka-btn-copy" onclick="tkaResetStudioForm()" style="background:#ffffff; color:#0f172a; border:1.5px solid #cbd5e1;">
                        <span>+ Create Another QR</span>
                    </button>
                </div>

                <!-- 2-COLUMN STUDIO GRID -->
                <div class="tka-studio-grid">
                    
                    <!-- PREVIEW & DOWNLOADS (LEFT) -->
                    <div class="tka-preview-card">
                        <div class="tka-qr-canvas-wrap">
                            <div id="tka-studio-canvas"></div>
                        </div>

                        <span class="tka-frame-caption" id="tka-frame-caption">⚡ SCAN TO OPEN</span>

                        <!-- SHORTENED URL & COPY -->
                        <div class="tka-url-box">
                            <input type="text" readonly id="tka-res-qr-url" class="tka-url-input">
                            <button type="button" class="tka-btn-copy" id="tka-copy-btn" onclick="tkaStudioCopyLink()">
                                <span>📋 Copy</span>
                            </button>
                        </div>

                        <!-- SLUG CUSTOMIZER IN STUDIO -->
                        <div class="tka-slug-edit-bar">
                            <span style="font-size:11px; color:#64748b;">Shortlink Slug</span>
                            <button type="button" class="tka-btn-slug-edit" onclick="tkaToggleSlugEditor()">✏️ Edit Slug</button>
                        </div>

                        <div class="tka-inline-edit-box" id="tka-inline-edit-box">
                            <div style="display:flex; gap:6px;">
                                <input type="text" id="tka-edit-slug-input" class="tka-slug-input-field" style="background:#fff; border-radius:8px; border:1px solid #cbd5e1; padding:8px;" placeholder="new-slug">
                                <button type="button" class="tka-btn-copy" onclick="tkaSaveSlugEdit()">Save</button>
                            </div>
                            <span id="tka-inline-slug-msg" style="font-size:10px; color:#64748b; margin-top:4px; display:block;"></span>
                        </div>

                        <!-- EXPORT BUTTONS -->
                        <div class="tka-export-grid">
                            <button type="button" class="tka-btn-export tka-btn-png" id="tka-dl-png-btn">
                                <span>📥 Download PNG</span>
                            </button>
                            <button type="button" class="tka-btn-export tka-btn-svg" id="tka-dl-svg-btn">
                                <span>📐 Download SVG</span>
                            </button>
                        </div>

                        <a href="#" target="_blank" class="tka-btn-link" id="tka-res-open-link">
                            <span>🔗 Test Destination Link &rarr;</span>
                        </a>
                    </div>

                    <!-- CUSTOMIZATION CONTROLS (RIGHT) -->
                    <div class="tka-studio-panel">
                        
                        <!-- STUDIO TABS -->
                        <div class="tka-tabs-nav">
                            <button type="button" class="tka-tab-btn active" onclick="tkaSwitchTab('colors', this)">🎨 Colors</button>
                            <button type="button" class="tka-tab-btn" onclick="tkaSwitchTab('logo', this)">🖼️ Center Logo</button>
                            <button type="button" class="tka-tab-btn" onclick="tkaSwitchTab('shapes', this)">📐 Shapes</button>
                        </div>

                        <!-- TAB 1: COLORS -->
                        <div class="tka-tab-content active" id="tka-tab-colors">
                            <div class="tka-control-group">
                                <label class="tka-control-lbl">Brand Presets</label>
                                <div class="tka-preset-grid">
                                    <div class="tka-preset-pill active" onclick="tkaApplyPalette('#dc2626', '#991b1b', '#991b1b', '#ffffff', this)">
                                        <span class="tka-preset-dot" style="background:#dc2626;"></span>
                                        <span>Elevate</span>
                                    </div>
                                    <div class="tka-preset-pill" onclick="tkaApplyPalette('#0f172a', '#000000', '#000000', '#ffffff', this)">
                                        <span class="tka-preset-dot" style="background:#0f172a;"></span>
                                        <span>Obsidian</span>
                                    </div>
                                    <div class="tka-preset-pill" onclick="tkaApplyPalette('#4f46e5', '#312e81', '#4f46e5', '#ffffff', this)">
                                        <span class="tka-preset-dot" style="background:#4f46e5;"></span>
                                        <span>Indigo</span>
                                    </div>
                                    <div class="tka-preset-pill" onclick="tkaApplyPalette('#059669', '#064e3b', '#059669', '#ffffff', this)">
                                        <span class="tka-preset-dot" style="background:#059669;"></span>
                                        <span>Emerald</span>
                                    </div>
                                    <div class="tka-preset-pill" onclick="tkaApplyPalette('#ea580c', '#9a3412', '#ea580c', '#ffffff', this)">
                                        <span class="tka-preset-dot" style="background:#ea580c;"></span>
                                        <span>Sunset</span>
                                    </div>
                                    <div class="tka-preset-pill" onclick="tkaApplyPalette('#0284c7', '#0369a1', '#0284c7', '#ffffff', this)">
                                        <span class="tka-preset-dot" style="background:#0284c7;"></span>
                                        <span>Ocean</span>
                                    </div>
                                </div>
                            </div>

                            <div class="tka-control-group">
                                <label class="tka-control-lbl">Custom Colors</label>
                                <div class="tka-color-row">
                                    <div class="tka-color-field">
                                        <div class="tka-color-info">
                                            <span class="tka-color-title">Dots / Pattern</span>
                                            <span class="tka-color-hex" id="tka-hex-dots">#dc2626</span>
                                        </div>
                                        <input type="color" id="tka-color-dots" value="#dc2626" class="tka-color-input" onchange="tkaUpdateLiveColor()">
                                    </div>
                                    <div class="tka-color-field">
                                        <div class="tka-color-info">
                                            <span class="tka-color-title">Corner Outer</span>
                                            <span class="tka-color-hex" id="tka-hex-corners">#991b1b</span>
                                        </div>
                                        <input type="color" id="tka-color-corners" value="#991b1b" class="tka-color-input" onchange="tkaUpdateLiveColor()">
                                    </div>
                                    <div class="tka-color-field">
                                        <div class="tka-color-info">
                                            <span class="tka-color-title">Corner Inner Eye</span>
                                            <span class="tka-color-hex" id="tka-hex-cornerdot">#991b1b</span>
                                        </div>
                                        <input type="color" id="tka-color-cornerdot" value="#991b1b" class="tka-color-input" onchange="tkaUpdateLiveColor()">
                                    </div>
                                    <div class="tka-color-field">
                                        <div class="tka-color-info">
                                            <span class="tka-color-title">Background</span>
                                            <span class="tka-color-hex" id="tka-hex-bg">#ffffff</span>
                                        </div>
                                        <input type="color" id="tka-color-bg" value="#ffffff" class="tka-color-input" onchange="tkaUpdateLiveColor()">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 2: LOGO & BRANDING -->
                        <div class="tka-tab-content" id="tka-tab-logo">
                            <div class="tka-control-group">
                                <label class="tka-control-lbl">Preset Badges</label>
                                <div class="tka-logo-grid">
                                    <div class="tka-logo-item active" onclick="tkaSelectLogo('elevate', this)">
                                        <span class="tka-logo-item-icon">🔴</span>
                                        <span class="tka-logo-item-lbl">Elevate</span>
                                    </div>
                                    <div class="tka-logo-item" onclick="tkaSelectLogo('doc', this)">
                                        <span class="tka-logo-item-icon">📄</span>
                                        <span class="tka-logo-item-lbl">Document</span>
                                    </div>
                                    <div class="tka-logo-item" onclick="tkaSelectLogo('link', this)">
                                        <span class="tka-logo-item-icon">🔗</span>
                                        <span class="tka-logo-item-lbl">Link</span>
                                    </div>
                                    <div class="tka-logo-item" onclick="tkaSelectLogo('cross', this)">
                                        <span class="tka-logo-item-icon">✝️</span>
                                        <span class="tka-logo-item-lbl">Cross</span>
                                    </div>
                                </div>
                            </div>

                            <div class="tka-control-group">
                                <label class="tka-control-lbl">Upload Your Own Logo</label>
                                <input type="file" id="tka-logo-file-input" accept="image/png,image/jpeg,image/webp,image/svg+xml" style="display:none;" onchange="tkaHandleLogoUpload(this)">
                                <div class="tka-logo-upload-box" onclick="document.getElementById('tka-logo-file-input').click();">
                                    <p class="tka-logo-upload-text">📁 Click to choose Custom Logo (PNG, SVG, JPG)</p>
                                    <span style="font-size:11px; color:#64748b;" id="tka-logo-filename">Square transparent logo recommended</span>
                                </div>
                            </div>

                            <div class="tka-control-group">
                                <div class="tka-control-lbl">
                                    <span>Logo Size</span>
                                    <span id="tka-logo-size-val">30%</span>
                                </div>
                                <div class="tka-slider-wrap">
                                    <input type="range" id="tka-logo-size-slider" min="15" max="36" value="30" class="tka-slider" oninput="tkaUpdateLogoSize(this.value)">
                                </div>
                            </div>

                            <div class="tka-control-group">
                                <div class="tka-control-lbl">
                                    <span>Logo Safe Margin</span>
                                    <span id="tka-logo-margin-val">4px</span>
                                </div>
                                <div class="tka-slider-wrap">
                                    <input type="range" id="tka-logo-margin-slider" min="0" max="10" value="4" class="tka-slider" oninput="tkaUpdateLogoMargin(this.value)">
                                </div>
                            </div>
                        </div>

                        <!-- TAB 3: SHAPES & STYLES -->
                        <div class="tka-tab-content" id="tka-tab-shapes">
                            <div class="tka-control-group">
                                <label class="tka-control-lbl">Dots Pattern Style</label>
                                <div class="tka-seg-group">
                                    <button type="button" class="tka-seg-btn active" onclick="tkaSetDotType('rounded', this)">Rounded</button>
                                    <button type="button" class="tka-seg-btn" onclick="tkaSetDotType('dots', this)">Dots</button>
                                    <button type="button" class="tka-seg-btn" onclick="tkaSetDotType('classy', this)">Classy</button>
                                    <button type="button" class="tka-seg-btn" onclick="tkaSetDotType('extra-rounded', this)">Bubble</button>
                                    <button type="button" class="tka-seg-btn" onclick="tkaSetDotType('square', this)">Square</button>
                                </div>
                            </div>

                            <div class="tka-control-group">
                                <label class="tka-control-lbl">Corner Outer Shape</label>
                                <div class="tka-seg-group">
                                    <button type="button" class="tka-seg-btn active" onclick="tkaSetCornerType('extra-rounded', this)">Extra Rounded</button>
                                    <button type="button" class="tka-seg-btn" onclick="tkaSetCornerType('dot', this)">Circle</button>
                                    <button type="button" class="tka-seg-btn" onclick="tkaSetCornerType('square', this)">Square</button>
                                </div>
                            </div>

                            <div class="tka-control-group">
                                <label class="tka-control-lbl">Corner Inner Eye Shape</label>
                                <div class="tka-seg-group">
                                    <button type="button" class="tka-seg-btn active" onclick="tkaSetCornerDotType('dot', this)">Circle Eye</button>
                                    <button type="button" class="tka-seg-btn" onclick="tkaSetCornerDotType('square', this)">Square Eye</button>
                                </div>
                            </div>

                            <div class="tka-control-group">
                                <label class="tka-control-lbl">Bottom Banner Label</label>
                                <input type="text" id="tka-banner-input" class="tka-dqr-input" value="⚡ SCAN TO OPEN" style="margin-top:0;" placeholder="Leave empty to hide label" oninput="document.getElementById('tka-frame-caption').innerText = this.value || ''; document.getElementById('tka-frame-caption').style.display = this.value ? 'inline-block' : 'none';">
                            </div>
                        </div>

                    </div>
                </div>

            </div>

        </div>
    </div>

    <script>
    (function($) {
        var fileInput   = document.getElementById('tka-dqr-file');
        var dropzone    = document.getElementById('tka-dqr-dropzone');
        var dropLabel   = document.getElementById('tka-dqr-drop-label');
        var dropIcon    = document.getElementById('tka-drop-icon');
        var form        = document.getElementById('tka-dqr-form');
        var submitBtn   = document.getElementById('tka-dqr-submit-btn');
        var submitText  = document.getElementById('tka-submit-btn-text');
        var slugInput   = document.getElementById('tka-custom-slug');
        var slugInd     = document.getElementById('tka-slug-indicator');

        // Embedded Logo SVGs
        var LOGO_ELEVATE = 'data:image/svg+xml;utf8,' + encodeURIComponent(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">' +
            '<rect width="100" height="100" rx="28" fill="#dc2626"/>' +
            '<path d="M28 26 h44 v12 h-30 v14 h26 v12 h-26 v14 h30 v12 h-44 z" fill="#ffffff"/>' +
            '</svg>'
        );
        var LOGO_DOC = 'data:image/svg+xml;utf8,' + encodeURIComponent(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">' +
            '<rect width="100" height="100" rx="28" fill="#0284c7"/>' +
            '<path d="M34 22 h22 l16 16 v40 h-38 z" fill="#ffffff"/>' +
            '<path d="M56 22 v16 h16 z" fill="#93c5fd"/>' +
            '<line x1="42" y1="52" x2="62" y2="52" stroke="#0284c7" stroke-width="4" stroke-linecap="round"/>' +
            '<line x1="42" y1="64" x2="56" y2="64" stroke="#0284c7" stroke-width="4" stroke-linecap="round"/>' +
            '</svg>'
        );
        var LOGO_LINK = 'data:image/svg+xml;utf8,' + encodeURIComponent(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">' +
            '<rect width="100" height="100" rx="28" fill="#4f46e5"/>' +
            '<path d="M42 34 h-8 a14 14 0 0 0 0 28 h8 m16 -28 h8 a14 14 0 0 1 0 28 h-8 m-20 -14 h24" stroke="#ffffff" stroke-width="8" stroke-linecap="round" fill="none"/>' +
            '</svg>'
        );
        var LOGO_CROSS = 'data:image/svg+xml;utf8,' + encodeURIComponent(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">' +
            '<rect width="100" height="100" rx="28" fill="#0f172a"/>' +
            '<path d="M44 20 h12 v20 h20 v12 h-20 v28 h-12 v-28 h-20 v-12 h20 z" fill="#fbbf24"/>' +
            '</svg>'
        );

        // Studio State
        var currentDocData = null;
        var qrStudioInstance = null;
        var qrStudioOptions = {
            width: 300,
            height: 300,
            type: 'canvas',
            data: '',
            image: LOGO_ELEVATE,
            dotsOptions: {
                type: 'rounded',
                color: '#dc2626'
            },
            backgroundOptions: {
                color: '#ffffff'
            },
            cornersSquareOptions: {
                type: 'extra-rounded',
                color: '#991b1b'
            },
            cornersDotOptions: {
                type: 'dot',
                color: '#991b1b'
            },
            imageOptions: {
                crossOrigin: 'anonymous',
                hideBackgroundDots: true,
                imageSize: 0.30,
                margin: 4
            },
            qrOptions: {
                errorCorrectionLevel: 'H'
            }
        };

        // Mode Switching
        window.tkaSelectMode = function(mode, btn) {
            $('.tka-format-tab').removeClass('active');
            $(btn).addClass('active');

            $('.tka-panel-mode').removeClass('active');
            $('#tka-panel-' + mode).addClass('active');
            $('#tka-current-mode').val(mode);

            if (mode === 'file') {
                submitText.innerText = '⚡ Upload & Open QR Studio';
            } else if (mode === 'link') {
                submitText.innerText = '⚡ Shorten Link & Open Studio';
            } else if (mode === 'text') {
                submitText.innerText = '⚡ Create Note & Open Studio';
            }
        };

        window.tkaPrefillUrl = function(val) {
            var input = $('#tka-destination-url');
            input.val(val).focus();
        };

        // Random Slug Generator
        window.tkaGenerateRandomSlug = function() {
            var chars = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
            var res = '';
            for (var i = 0; i < 6; i++) {
                res += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            $('#tka-custom-slug').val(res).trigger('input');
        };

        // Real-time Slug Availability Check
        var slugCheckTimeout = null;
        $('#tka-custom-slug').on('input', function() {
            var raw = $(this).val().trim();
            // Clean client side
            var cleaned = raw.replace(/[^a-zA-Z0-9_\-]/g, '');
            if (raw !== cleaned) $(this).val(cleaned);

            clearTimeout(slugCheckTimeout);
            if (!cleaned) {
                slugInd.className = 'tka-slug-indicator';
                slugInd.innerText = 'Auto-generated if blank';
                return;
            }

            if (cleaned.length < 2) {
                slugInd.className = 'tka-slug-indicator';
                slugInd.innerText = 'Minimum 2 characters';
                return;
            }

            slugInd.className = 'tka-slug-indicator checking';
            slugInd.innerText = 'Checking availability...';

            slugCheckTimeout = setTimeout(function() {
                $.post('<?php echo esc_url($ajax_url); ?>', {
                    action: 'tka_doc_qr_check_slug',
                    slug: cleaned
                }, function(res) {
                    if (res.success) {
                        slugInd.className = 'tka-slug-indicator avail';
                        slugInd.innerText = '✓ Available';
                    } else {
                        slugInd.className = 'tka-slug-indicator taken';
                        slugInd.innerText = '✗ Taken or reserved';
                    }
                });
            }, 300);
        });

        // Drag & Drop for File Upload
        if (dropzone) {
            ['dragenter', 'dragover'].forEach(function(ev) {
                dropzone.addEventListener(ev, function(e) { e.preventDefault(); dropzone.classList.add('dragover'); });
            });
            ['dragleave', 'drop'].forEach(function(ev) {
                dropzone.addEventListener(ev, function(e) { e.preventDefault(); dropzone.classList.remove('dragover'); });
            });
            dropzone.addEventListener('drop', function(e) {
                if (e.dataTransfer.files.length) {
                    fileInput.files = e.dataTransfer.files;
                    updateFileSelection();
                }
            });
        }
        if (fileInput) {
            fileInput.addEventListener('change', updateFileSelection);
        }

        function updateFileSelection() {
            if (fileInput.files.length > 0) {
                var f = fileInput.files[0];
                var name = f.name;
                var sizeMb = (f.size / (1024 * 1024)).toFixed(2);
                dropLabel.innerHTML = '<strong>' + name + '</strong> (' + sizeMb + ' MB)';
                dropIcon.innerText = '✅';
            }
        }

        // Form Submission
        $('#tka-dqr-form').on('submit', function(e) {
            e.preventDefault();
            var mode = $('#tka-current-mode').val();

            if (mode === 'file') {
                if (!fileInput.files.length) {
                    alert('Please choose a document file to upload.');
                    return;
                }

                var formData = new FormData(form);
                formData.append('action', 'tka_doc_qr_upload');
                formData.append('security', '<?php echo esc_js($nonce); ?>');

                submitBtn.disabled = true;
                submitText.innerText = '⏳ Uploading Document...';
                $('#tka-dqr-progress').show();
                $('#tka-dqr-progress-fill').css('width', '25%');

                $.ajax({
                    url: '<?php echo esc_url($ajax_url); ?>',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    xhr: function() {
                        var xhr = new window.XMLHttpRequest();
                        xhr.upload.addEventListener('progress', function(evt) {
                            if (evt.lengthComputable) {
                                var pct = Math.round((evt.loaded / evt.total) * 100);
                                $('#tka-dqr-progress-fill').css('width', pct + '%');
                                $('#tka-dqr-progress-label').text('Uploading ' + pct + '%...');
                            }
                        }, false);
                        return xhr;
                    },
                    success: function(res) {
                        submitBtn.disabled = false;
                        submitText.innerText = '⚡ Upload & Open QR Studio';
                        $('#tka-dqr-progress').hide();

                        if (res.success) {
                            launchStudio(res.data);
                        } else {
                            alert('❌ ' + (res.data || 'Upload failed.'));
                        }
                    },
                    error: function() {
                        submitBtn.disabled = false;
                        submitText.innerText = '⚡ Upload & Open QR Studio';
                        $('#tka-dqr-progress').hide();
                        alert('❌ Server upload error. Please check file size or internet connection.');
                    }
                });

            } else if (mode === 'link') {
                var destUrl = $('#tka-destination-url').val().trim();
                if (!destUrl) {
                    alert('Please enter a website URL.');
                    $('#tka-destination-url').focus();
                    return;
                }

                submitBtn.disabled = true;
                submitText.innerText = '⏳ Generating Shortlink...';

                $.post('<?php echo esc_url($ajax_url); ?>', {
                    action: 'tka_doc_qr_create_link',
                    security: '<?php echo esc_js($nonce); ?>',
                    mode: 'link',
                    destination_url: destUrl,
                    doc_title: $('#tka-dqr-title-input').val(),
                    custom_slug: $('#tka-custom-slug').val(),
                    upload_pin: $('#tka-dqr-pin-input').val() || ''
                }, function(res) {
                    submitBtn.disabled = false;
                    submitText.innerText = '⚡ Shorten Link & Open Studio';
                    if (res.success) {
                        launchStudio(res.data);
                    } else {
                        alert('❌ ' + (res.data || 'Link creation failed.'));
                    }
                }).fail(function() {
                    submitBtn.disabled = false;
                    submitText.innerText = '⚡ Shorten Link & Open Studio';
                    alert('❌ Connection error. Please try again.');
                });

            } else if (mode === 'text') {
                var text = $('#tka-text-content').val().trim();
                if (!text) {
                    alert('Please enter text content for your note or announcement.');
                    $('#tka-text-content').focus();
                    return;
                }

                submitBtn.disabled = true;
                submitText.innerText = '⏳ Creating Note...';

                $.post('<?php echo esc_url($ajax_url); ?>', {
                    action: 'tka_doc_qr_create_link',
                    security: '<?php echo esc_js($nonce); ?>',
                    mode: 'text',
                    text_content: text,
                    doc_title: $('#tka-dqr-title-input').val(),
                    custom_slug: $('#tka-custom-slug').val(),
                    upload_pin: $('#tka-dqr-pin-input').val() || ''
                }, function(res) {
                    submitBtn.disabled = false;
                    submitText.innerText = '⚡ Create Note & Open Studio';
                    if (res.success) {
                        launchStudio(res.data);
                    } else {
                        alert('❌ ' + (res.data || 'Failed to create note.'));
                    }
                }).fail(function() {
                    submitBtn.disabled = false;
                    submitText.innerText = '⚡ Create Note & Open Studio';
                    alert('❌ Connection error. Please try again.');
                });
            }
        });

        // Launch QR Studio
        function launchStudio(data) {
            currentDocData = data;
            $('#tka-dqr-upload-section').hide();
            $('#tka-dqr-container').addClass('studio-mode');

            $('#tka-res-title').text(data.title);
            $('#tka-res-meta').text(data.file_type + ' • ' + data.file_size + ' • Shortlink Live');
            $('#tka-res-badge').text(data.file_type);
            $('#tka-res-badge').attr('class', 'tka-file-badge badge-' + data.file_type.toLowerCase());
            $('#tka-res-qr-url').val(data.qr_url);
            $('#tka-edit-slug-input').val(data.short_code);
            $('#tka-res-open-link').attr('href', data.direct_url);

            // Select initial badge icon matching type
            if (data.file_type === 'LINK') {
                qrStudioOptions.image = LOGO_LINK;
                $('.tka-logo-item').removeClass('active');
                $('.tka-logo-item:has(.tka-logo-item-icon:contains("🔗"))').addClass('active');
            } else if (data.file_type === 'TEXT') {
                qrStudioOptions.image = LOGO_ELEVATE;
                $('.tka-logo-item').removeClass('active');
                $('.tka-logo-item:has(.tka-logo-item-icon:contains("🔴"))').addClass('active');
            } else {
                qrStudioOptions.image = LOGO_DOC;
                $('.tka-logo-item').removeClass('active');
                $('.tka-logo-item:has(.tka-logo-item-icon:contains("📄"))').addClass('active');
            }

            qrStudioOptions.data = data.qr_url;

            // Render live canvas
            var container = document.getElementById('tka-studio-canvas');
            container.innerHTML = '';
            
            if (typeof QRCodeStyling !== 'undefined') {
                qrStudioInstance = new QRCodeStyling(qrStudioOptions);
                qrStudioInstance.append(container);
            } else {
                var img = document.createElement('img');
                img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' + encodeURIComponent(data.qr_url);
                container.appendChild(img);
            }

            $('#tka-studio-view').fadeIn();
        }

        // Inline Slug Editor in Studio
        window.tkaToggleSlugEditor = function() {
            $('#tka-inline-edit-box').slideToggle(200);
            $('#tka-inline-slug-msg').text('');
        };

        window.tkaSaveSlugEdit = function() {
            if (!currentDocData || !currentDocData.id) return;
            var newSlug = $('#tka-edit-slug-input').val().trim().replace(/[^a-zA-Z0-9_\-]/g, '');
            if (!newSlug || newSlug.length < 2) {
                $('#tka-inline-slug-msg').css('color', '#dc2626').text('Slug must be at least 2 characters.');
                return;
            }

            $('#tka-inline-slug-msg').css('color', '#64748b').text('Updating shortlink...');

            $.post('<?php echo esc_url($ajax_url); ?>', {
                action: 'tka_doc_qr_update_slug',
                security: '<?php echo esc_js($nonce); ?>',
                doc_id: currentDocData.id,
                new_slug: newSlug
            }, function(res) {
                if (res.success) {
                    currentDocData.short_code = res.data.short_code;
                    currentDocData.qr_url = res.data.qr_url;
                    $('#tka-res-qr-url').val(res.data.qr_url);
                    qrStudioOptions.data = res.data.qr_url;
                    refreshQrCode();
                    $('#tka-inline-slug-msg').css('color', '#059669').text('✓ Shortlink updated successfully!');
                    setTimeout(function() { $('#tka-inline-edit-box').slideUp(); }, 1500);
                } else {
                    $('#tka-inline-slug-msg').css('color', '#dc2626').text('❌ ' + (res.data || 'Failed to update.'));
                }
            }).fail(function() {
                $('#tka-inline-slug-msg').css('color', '#dc2626').text('❌ Connection error.');
            });
        };

        // Tab Switching
        window.tkaSwitchTab = function(tabName, btn) {
            $('.tka-tab-btn').removeClass('active');
            $(btn).addClass('active');
            $('.tka-tab-content').removeClass('active');
            $('#tka-tab-' + tabName).addClass('active');
        };

        // Update Live QR Instance
        function refreshQrCode() {
            if (qrStudioInstance) {
                qrStudioInstance.update(qrStudioOptions);
            }
        }

        // Apply Preset Palettes
        window.tkaApplyPalette = function(dotsColor, cornerColor, cornerDotColor, bgColor, pill) {
            $('.tka-preset-pill').removeClass('active');
            if (pill) $(pill).addClass('active');

            $('#tka-color-dots').val(dotsColor);
            $('#tka-hex-dots').text(dotsColor);

            $('#tka-color-corners').val(cornerColor);
            $('#tka-hex-corners').text(cornerColor);

            $('#tka-color-cornerdot').val(cornerDotColor);
            $('#tka-hex-cornerdot').text(cornerDotColor);

            $('#tka-color-bg').val(bgColor);
            $('#tka-hex-bg').text(bgColor);

            qrStudioOptions.dotsOptions.color = dotsColor;
            qrStudioOptions.cornersSquareOptions.color = cornerColor;
            qrStudioOptions.cornersDotOptions.color = cornerDotColor;
            qrStudioOptions.backgroundOptions.color = bgColor;

            refreshQrCode();
        };

        // Live Color Inputs
        window.tkaUpdateLiveColor = function() {
            $('.tka-preset-pill').removeClass('active');

            var dots = $('#tka-color-dots').val();
            var corners = $('#tka-color-corners').val();
            var cornerDot = $('#tka-color-cornerdot').val();
            var bg = $('#tka-color-bg').val();

            $('#tka-hex-dots').text(dots);
            $('#tka-hex-corners').text(corners);
            $('#tka-hex-cornerdot').text(cornerDot);
            $('#tka-hex-bg').text(bg);

            qrStudioOptions.dotsOptions.color = dots;
            qrStudioOptions.cornersSquareOptions.color = corners;
            qrStudioOptions.cornersDotOptions.color = cornerDot;
            qrStudioOptions.backgroundOptions.color = bg;

            refreshQrCode();
        };

        // Select Preset Logo
        window.tkaSelectLogo = function(type, item) {
            $('.tka-logo-item').removeClass('active');
            $(item).addClass('active');

            if (type === 'elevate') qrStudioOptions.image = LOGO_ELEVATE;
            else if (type === 'doc') qrStudioOptions.image = LOGO_DOC;
            else if (type === 'link') qrStudioOptions.image = LOGO_LINK;
            else if (type === 'cross') qrStudioOptions.image = LOGO_CROSS;
            else qrStudioOptions.image = '';

            $('#tka-logo-filename').text('Preset badge active');
            refreshQrCode();
        };

        // Custom Logo Upload via FileReader
        window.tkaHandleLogoUpload = function(input) {
            if (input.files && input.files[0]) {
                var file = input.files[0];
                var reader = new FileReader();
                reader.onload = function(e) {
                    qrStudioOptions.image = e.target.result;
                    $('.tka-logo-item').removeClass('active');
                    $('#tka-logo-filename').text('Active: ' + file.name);
                    refreshQrCode();
                };
                reader.readAsDataURL(file);
            }
        };

        // Logo Size & Margin
        window.tkaUpdateLogoSize = function(val) {
            $('#tka-logo-size-val').text(val + '%');
            qrStudioOptions.imageOptions.imageSize = parseFloat(val) / 100;
            refreshQrCode();
        };

        window.tkaUpdateLogoMargin = function(val) {
            $('#tka-logo-margin-val').text(val + 'px');
            qrStudioOptions.imageOptions.margin = parseInt(val);
            refreshQrCode();
        };

        // Dots Pattern Style
        window.tkaSetDotType = function(type, btn) {
            $(btn).siblings().removeClass('active');
            $(btn).addClass('active');
            qrStudioOptions.dotsOptions.type = type;
            refreshQrCode();
        };

        // Corner Frame Style
        window.tkaSetCornerType = function(type, btn) {
            $(btn).siblings().removeClass('active');
            $(btn).addClass('active');
            qrStudioOptions.cornersSquareOptions.type = type;
            refreshQrCode();
        };

        // Corner Dot Eye Style
        window.tkaSetCornerDotType = function(type, btn) {
            $(btn).siblings().removeClass('active');
            $(btn).addClass('active');
            qrStudioOptions.cornersDotOptions.type = type;
            refreshQrCode();
        };

        // High-Res PNG Download
        $('#tka-dl-png-btn').on('click', function() {
            var btn = $(this);
            btn.prop('disabled', true).text('⏳ Generating 1200px PNG...');

            var hiResOpts = $.extend(true, {}, qrStudioOptions, {
                width: 1200,
                height: 1200,
                imageOptions: {
                    margin: Math.round(qrStudioOptions.imageOptions.margin * 3.5)
                }
            });

            var hiRes = new QRCodeStyling(hiResOpts);
            var filename = 'QR-' + (currentDocData ? currentDocData.short_code : 'elevate');
            hiRes.download({ name: filename, extension: 'png' }).then(function() {
                btn.prop('disabled', false).html('<span>📥 Download PNG</span>');
            }).catch(function() {
                btn.prop('disabled', false).html('<span>📥 Download PNG</span>');
            });
        });

        // Vector SVG Download
        $('#tka-dl-svg-btn').on('click', function() {
            var btn = $(this);
            btn.prop('disabled', true).text('⏳ Exporting SVG...');

            var svgOpts = $.extend(true, {}, qrStudioOptions, { type: 'svg' });
            var svgInstance = new QRCodeStyling(svgOpts);
            var filename = 'QR-' + (currentDocData ? currentDocData.short_code : 'elevate');

            svgInstance.download({ name: filename, extension: 'svg' }).then(function() {
                btn.prop('disabled', false).html('<span>📐 Download SVG</span>');
            }).catch(function() {
                btn.prop('disabled', false).html('<span>📐 Download SVG</span>');
            });
        });

        // Copy Shortlink Link
        window.tkaStudioCopyLink = function() {
            var input = document.getElementById('tka-res-qr-url');
            input.select();
            input.setSelectionRange(0, 99999);
            navigator.clipboard.writeText(input.value).then(function() {
                var btn = $('#tka-copy-btn');
                btn.text('✅ Copied!');
                setTimeout(function() { btn.text('📋 Copy'); }, 2000);
            });
        };

        // Reset
        window.tkaResetStudioForm = function() {
            form.reset();
            if (dropLabel) dropLabel.innerHTML = 'Choose a Document or Drag &amp; Drop here';
            if (dropIcon) dropIcon.innerText = '📄';
            $('#tka-custom-slug').val('');
            slugInd.className = 'tka-slug-indicator';
            slugInd.innerText = 'Auto-generated if blank';
            $('#tka-studio-view').hide();
            $('#tka-dqr-container').removeClass('studio-mode');
            $('#tka-dqr-upload-section').fadeIn();
        };

    })(jQuery);
    </script>
    <?php
    return ob_get_clean();
});

// ================================================================
// 10. ADMIN DASHBOARD & MANAGEMENT (DOCUMENTS & LINKS)
// ================================================================
add_action('admin_menu', function() {
    add_menu_page(
        'Elevate QR & Links',
        'QR & Links',
        'manage_options',
        'tka-doc-qr',
        'tka_doc_qr_render_admin',
        'dashicons-share',
        30
    );
});

function tka_doc_qr_render_admin() {
    global $wpdb;
    $table = $wpdb->prefix . 'tka_qr_documents';

    // Handle settings update
    if (isset($_POST['tka_save_settings'])) {
        check_admin_referer('tka_doc_qr_save_settings_nonce');
        update_option('tka_doc_qr_pin', sanitize_text_field($_POST['tka_doc_qr_pin'] ?? ''));
        update_option('tka_doc_qr_max_size', intval($_POST['tka_doc_qr_max_size'] ?? 50));
        echo '<div class="notice notice-success is-dismissible"><p>✅ Settings saved successfully.</p></div>';
    }

    $items = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC");
    $total_items = count($items);
    $total_links = 0;
    $total_docs = 0;
    foreach ($items as $item) {
        if ($item->file_type === 'LINK') $total_links++;
        else $total_docs++;
    }

    $total_scans = $wpdb->get_var("SELECT SUM(download_count) FROM $table") ?: 0;
    $admin_nonce = wp_create_nonce('tka_doc_qr_admin_nonce');
    $user_nonce = wp_create_nonce('tka_doc_qr_nonce');
    $script_local_url = plugins_url('qr-code-styling.js', __FILE__);
    $short_domain = str_replace(['http://', 'https://'], '', home_url('/qr/'));
    ?>
    <script>
    if (typeof QRCodeStyling === 'undefined') {
        var s = document.createElement('script');
        s.src = '<?php echo esc_url($script_local_url); ?>';
        s.onerror = function() {
            var cdn = document.createElement('script');
            cdn.src = 'https://cdn.jsdelivr.net/npm/qr-code-styling@1.5.0/lib/qr-code-styling.js';
            document.head.appendChild(cdn);
        };
        document.head.appendChild(s);
    }
    </script>

    <div class="wrap">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
            <h1 style="font-weight: 800; font-size: 26px; margin:0; display: flex; align-items: center; gap: 10px;">
                ⚡ Elevate QR &amp; Shortlinks
            </h1>
            <button type="button" class="button button-primary button-hero" onclick="tkaOpenAdminCreateModal()" style="display:inline-flex; align-items:center; gap:6px;">
                <span>+ Create QR (Link or Document)</span>
            </button>
        </div>

        <style>
            .tka-admin-stats { display: flex; gap: 18px; margin: 20px 0; flex-wrap: wrap; }
            .tka-admin-stat-card { background: #fff; padding: 20px 24px; border-radius: 12px; border: 1px solid #dcdcde; flex: 1; min-width: 180px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
            .tka-admin-stat-num { font-size: 28px; font-weight: 800; color: #0f172a; margin-top: 6px; }
            .tka-admin-stat-lbl { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
            
            .tka-table-badge { padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 800; font-family: monospace; display: inline-block; }
            .badge-link { background: #e0e7ff; color: #3730a3; }
            .badge-text { background: #f3e8ff; color: #6b21a8; }
            .badge-pdf { background: #fee2e2; color: #991b1b; }
            .badge-doc, .badge-docx { background: #dbeafe; color: #1e40af; }
            .badge-xls, .badge-xlsx { background: #dcfce7; color: #166534; }
            .badge-ppt, .badge-pptx { background: #ffedd5; color: #9a3412; }
            .badge-default { background: #f1f5f9; color: #475569; }
            
            .tka-qr-thumb { width: 44px; height: 44px; border-radius: 6px; cursor: pointer; transition: 0.2s; border: 1px solid #cbd5e1; }
            .tka-qr-thumb:hover { transform: scale(1.1); box-shadow: 0 4px 10px rgba(0,0,0,0.15); }
            .tka-shortlink-link { font-family: monospace; font-weight: 700; color: #dc2626; text-decoration: none; }
            .tka-shortlink-link:hover { text-decoration: underline; }
        </style>

        <div class="tka-admin-stats">
            <div class="tka-admin-stat-card">
                <div class="tka-admin-stat-lbl">Total QR Codes</div>
                <div class="tka-admin-stat-num"><?php echo number_format($total_items); ?></div>
            </div>
            <div class="tka-admin-stat-card" style="border-left: 4px solid #4f46e5;">
                <div class="tka-admin-stat-lbl">Web Links &amp; URLs</div>
                <div class="tka-admin-stat-num" style="color: #4f46e5;"><?php echo number_format($total_links); ?></div>
            </div>
            <div class="tka-admin-stat-card" style="border-left: 4px solid #0284c7;">
                <div class="tka-admin-stat-lbl">Documents &amp; Files</div>
                <div class="tka-admin-stat-num" style="color: #0284c7;"><?php echo number_format($total_docs); ?></div>
            </div>
            <div class="tka-admin-stat-card" style="border-left: 4px solid #dc2626;">
                <div class="tka-admin-stat-lbl">Total Scans / Opens</div>
                <div class="tka-admin-stat-num" style="color: #dc2626;"><?php echo number_format($total_scans); ?></div>
            </div>
        </div>

        <!-- ITEMS TABLE -->
        <div style="background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #dcdcde; margin-bottom: 25px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:10px;">
                <h2 style="margin:0; font-size:18px;">📂 QR Codes &amp; Shortlinks</h2>
                <div>
                    <code style="font-size: 13px; padding: 6px 10px; background: #f1f5f9; border-radius: 6px;">[tka_document_qr]</code>
                    <a href="#tka-settings-box" class="button" style="margin-left:8px;">⚙️ Settings</a>
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th width="60">QR</th>
                        <th>Title &amp; Shortlink</th>
                        <th width="85">Type</th>
                        <th>Target Destination</th>
                        <th width="90">Scans</th>
                        <th width="150">Created</th>
                        <th width="210" style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($items)): foreach ($items as $d): 
                        $qr_link = tka_doc_qr_get_shortlink_url($d->short_code);
                        $qr_img = "https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=" . urlencode($qr_link);
                        
                        $type_class = 'badge-' . strtolower($d->file_type);
                        if (!in_array($type_class, ['badge-link','badge-text','badge-pdf','badge-doc','badge-docx','badge-xls','badge-xlsx','badge-ppt','badge-pptx'])) {
                            $type_class = 'badge-default';
                        }
                    ?>
                    <tr id="doc-row-<?php echo $d->id; ?>">
                        <td>
                            <img src="<?php echo esc_url($qr_img); ?>" class="tka-qr-thumb" onclick="tkaPreviewQr('<?php echo esc_attr($d->title); ?>', '<?php echo esc_attr($qr_link); ?>', '<?php echo esc_attr($d->short_code); ?>', '<?php echo esc_attr($d->file_type); ?>')" title="Click to open QR Studio">
                        </td>
                        <td>
                            <strong><?php echo esc_html($d->title); ?></strong><br>
                            <a href="<?php echo esc_url($qr_link); ?>" target="_blank" class="tka-shortlink-link">
                                /qr/<?php echo esc_html($d->short_code); ?>
                            </a>
                        </td>
                        <td>
                            <span class="tka-table-badge <?php echo $type_class; ?>"><?php echo esc_html($d->file_type); ?></span>
                        </td>
                        <td style="max-width:250px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                            <?php if ($d->file_type === 'TEXT'): ?>
                                <span style="font-size:12px; color:#64748b;"><?php echo esc_html(wp_trim_words($d->text_content ?: $d->title, 10)); ?></span>
                            <?php else: ?>
                                <a href="<?php echo esc_url($d->file_url); ?>" target="_blank" style="font-size:12px; color:#475569; text-decoration:none;">
                                    <?php echo esc_html($d->file_url); ?>
                                </a>
                            <?php endif; ?>
                        </td>
                        <td><strong><?php echo number_format($d->download_count); ?></strong></td>
                        <td><?php echo date('M d, Y H:i', strtotime($d->created_at)); ?></td>
                        <td style="text-align:right;">
                            <button type="button" class="button button-small" onclick="tkaCopyText('<?php echo esc_attr($qr_link); ?>')" title="Copy Shortlink">📋 Link</button>
                            <button type="button" class="button button-small button-primary" onclick="tkaPreviewQr('<?php echo esc_attr($d->title); ?>', '<?php echo esc_attr($qr_link); ?>', '<?php echo esc_attr($d->short_code); ?>', '<?php echo esc_attr($d->file_type); ?>')" title="Customize QR">🎨 QR</button>
                            <a href="<?php echo esc_url($d->file_url); ?>" target="_blank" class="button button-small" title="Open Destination">👁️</a>
                            <button type="button" class="button button-small button-link-delete" onclick="tkaDeleteDoc(<?php echo $d->id; ?>)" style="color:#ef4444;" title="Delete Item">🗑️</button>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding:50px; color:#64748b;">
                            No QR codes created yet. Click <strong>+ Create QR</strong> above or use <code>[tka_document_qr]</code> on your site!
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- SETTINGS BOX -->
        <div id="tka-settings-box" style="background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #dcdcde; max-width: 600px;">
            <h3 style="margin-top:0;">⚙️ Portal Settings</h3>
            <form method="post">
                <?php wp_nonce_field('tka_doc_qr_save_settings_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="tka_doc_qr_max_size">Max Upload Size (MB)</label></th>
                        <td>
                            <input type="number" name="tka_doc_qr_max_size" id="tka_doc_qr_max_size" value="<?php echo esc_attr(get_option('tka_doc_qr_max_size', 50)); ?>" min="1" max="500" class="regular-text">
                            <p class="description">Maximum allowed document file size in megabytes (Default: 50 MB).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tka_doc_qr_pin">Security PIN (Optional)</label></th>
                        <td>
                            <input type="text" name="tka_doc_qr_pin" id="tka_doc_qr_pin" value="<?php echo esc_attr(get_option('tka_doc_qr_pin', '')); ?>" class="regular-text" placeholder="Leave empty for open public access">
                            <p class="description">If set, users on the frontend portal must enter this PIN to create QR codes.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="tka_save_settings" class="button button-primary" value="Save Settings">
                </p>
            </form>
        </div>
    </div>

    <!-- ADMIN QUICK CREATE MODAL -->
    <div id="tka-admin-create-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.65); z-index:99999; align-items:center; justify-content:center; padding:20px; box-sizing:border-box;">
        <div style="background:#fff; border-radius:20px; max-width:520px; width:100%; padding:30px; box-shadow:0 25px 60px rgba(0,0,0,0.3); position:relative;">
            <h2 style="margin-top:0; font-size:20px; font-weight:800;">⚡ Create QR Code &amp; Shortlink</h2>
            
            <div style="display:flex; gap:6px; margin:16px 0;">
                <button type="button" class="button button-primary" id="tka-adm-btn-link" onclick="tkaAdminSwitchTab('link')">🔗 Web Link</button>
                <button type="button" class="button" id="tka-adm-btn-file" onclick="tkaAdminSwitchTab('file')">📄 Upload Document</button>
                <button type="button" class="button" id="tka-adm-btn-text" onclick="tkaAdminSwitchTab('text')">📝 Plain Text Note</button>
            </div>

            <form id="tka-admin-create-form" enctype="multipart/form-data">
                <input type="hidden" id="tka-adm-mode" value="link">

                <!-- Link Mode Target -->
                <div id="tka-adm-pane-link">
                    <label style="font-weight:700; font-size:12px; display:block; margin-bottom:5px;">Destination Website URL *</label>
                    <input type="url" id="tka-adm-url" class="widefat" placeholder="https://example.com/giving" style="padding:8px 12px; border-radius:8px; margin-bottom:12px;">
                </div>

                <!-- File Mode Target -->
                <div id="tka-adm-pane-file" style="display:none;">
                    <label style="font-weight:700; font-size:12px; display:block; margin-bottom:5px;">Choose Document File *</label>
                    <input type="file" id="tka-adm-file" class="widefat" style="margin-bottom:12px;">
                </div>

                <!-- Text Mode Target -->
                <div id="tka-adm-pane-text" style="display:none;">
                    <label style="font-weight:700; font-size:12px; display:block; margin-bottom:5px;">Announcement / Note Content *</label>
                    <textarea id="tka-adm-text" class="widefat" rows="3" placeholder="Enter announcement text..." style="border-radius:8px; margin-bottom:12px;"></textarea>
                </div>

                <label style="font-weight:700; font-size:12px; display:block; margin-bottom:5px;">Title / Label (Optional)</label>
                <input type="text" id="tka-adm-title" class="widefat" placeholder="e.g. Sunday Service Giving" style="padding:8px 12px; border-radius:8px; margin-bottom:12px;">

                <label style="font-weight:700; font-size:12px; display:block; margin-bottom:5px;">Custom Shortlink Slug (Optional)</label>
                <div style="display:flex; align-items:center; border:1px solid #cbd5e1; border-radius:8px; overflow:hidden; margin-bottom:16px;">
                    <span style="background:#f1f5f9; padding:8px 10px; font-size:12px; color:#64748b; font-family:monospace;"><?php echo esc_html($short_domain); ?></span>
                    <input type="text" id="tka-adm-slug" style="border:none; outline:none; padding:8px 10px; font-family:monospace; font-size:12px; flex:1;" placeholder="custom-slug">
                </div>

                <div style="display:flex; gap:8px; justify-content:flex-end;">
                    <button type="button" class="button" onclick="document.getElementById('tka-admin-create-modal').style.display='none'">Cancel</button>
                    <button type="button" class="button button-primary" id="tka-adm-submit-btn" onclick="tkaAdminSubmitCreate()">⚡ Create &amp; Generate QR</button>
                </div>
            </form>
        </div>
    </div>

    <!-- QR PREVIEW & CUSTOMIZE MODAL -->
    <div id="tka-admin-qr-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.65); z-index:99999; align-items:center; justify-content:center; padding:20px; box-sizing:border-box;">
        <div style="background:#fff; border-radius:24px; max-width:440px; width:100%; padding:28px; text-align:center; box-shadow:0 25px 60px rgba(0,0,0,0.3); position:relative;">
            <span id="tka-admin-modal-badge" class="tka-table-badge badge-link" style="margin-bottom:10px;">LINK</span>
            <h3 id="tka-admin-modal-title" style="margin:6px 0; font-size:18px; font-weight:800;">Elevate QR Preview</h3>
            <p style="font-size:12px; color:#64748b; margin:0 0 16px 0;">Live styled QR code with Elevate branding</p>
            
            <div id="tka-admin-canvas-target" style="display:flex; justify-content:center; margin:0 auto 16px auto; min-height:260px;"></div>
            
            <div style="display:flex; gap:6px; justify-content:center; margin-bottom:16px;">
                <button type="button" class="button button-small" onclick="tkaAdminSetColor('#dc2626', '#991b1b')">🔴 Elevate</button>
                <button type="button" class="button button-small" onclick="tkaAdminSetColor('#0f172a', '#000000')">⚫ Dark</button>
                <button type="button" class="button button-small" onclick="tkaAdminSetColor('#4f46e5', '#312e81')">🟣 Indigo</button>
                <button type="button" class="button button-small" onclick="tkaAdminSetColor('#059669', '#064e3b')">🟢 Green</button>
            </div>

            <div style="margin-bottom:16px;">
                <input type="text" id="tka-admin-modal-url" readonly style="width:100%; font-size:11px; font-family:monospace; text-align:center; padding:8px; border:1px solid #cbd5e1; border-radius:8px; background:#f8fafc;">
            </div>

            <div style="display:flex; gap:8px;">
                <button type="button" class="button button-primary" style="flex:1;" onclick="tkaAdminDownloadPng()">📥 Download 1200px PNG</button>
                <button type="button" class="button" onclick="document.getElementById('tka-admin-qr-modal').style.display='none'">Close</button>
            </div>
        </div>
    </div>

    <script>
    var adminQrInstance = null;
    var adminCurrentCode = 'elevate';
    var LOGO_ELEVATE = 'data:image/svg+xml;utf8,' + encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">' +
        '<rect width="100" height="100" rx="28" fill="#dc2626"/>' +
        '<path d="M28 26 h44 v12 h-30 v14 h26 v12 h-26 v14 h30 v12 h-44 z" fill="#ffffff"/>' +
        '</svg>'
    );

    var adminQrOptions = {
        width: 250,
        height: 250,
        type: 'canvas',
        data: '',
        image: LOGO_ELEVATE,
        dotsOptions: { type: 'rounded', color: '#dc2626' },
        cornersSquareOptions: { type: 'extra-rounded', color: '#991b1b' },
        cornersDotOptions: { type: 'dot', color: '#991b1b' },
        imageOptions: { hideBackgroundDots: true, imageSize: 0.30, margin: 4 },
        qrOptions: { errorCorrectionLevel: 'H' }
    };

    function tkaCopyText(text) {
        navigator.clipboard.writeText(text).then(function() {
            alert('Copied link: ' + text);
        });
    }

    function tkaPreviewQr(title, qrLink, shortCode, type) {
        adminCurrentCode = shortCode || 'elevate';
        document.getElementById('tka-admin-modal-title').innerText = title;
        document.getElementById('tka-admin-modal-url').value = qrLink;
        document.getElementById('tka-admin-modal-badge').innerText = type || 'LINK';
        adminQrOptions.data = qrLink;

        var target = document.getElementById('tka-admin-canvas-target');
        target.innerHTML = '';

        if (typeof QRCodeStyling !== 'undefined') {
            adminQrInstance = new QRCodeStyling(adminQrOptions);
            adminQrInstance.append(target);
        } else {
            var img = document.createElement('img');
            img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' + encodeURIComponent(qrLink);
            target.appendChild(img);
        }

        document.getElementById('tka-admin-qr-modal').style.display = 'flex';
    }

    function tkaAdminSetColor(dots, corners) {
        adminQrOptions.dotsOptions.color = dots;
        adminQrOptions.cornersSquareOptions.color = corners;
        adminQrOptions.cornersDotOptions.color = corners;
        if (adminQrInstance) {
            adminQrInstance.update(adminQrOptions);
        }
    }

    function tkaAdminDownloadPng() {
        if (typeof QRCodeStyling !== 'undefined') {
            var hiRes = new QRCodeStyling(Object.assign({}, adminQrOptions, { width: 1200, height: 1200 }));
            hiRes.download({ name: 'QR-' + adminCurrentCode, extension: 'png' });
        }
    }

    function tkaDeleteDoc(id) {
        if (!confirm('Are you sure you want to permanently delete this item and its shortlink?')) return;
        jQuery.post(ajaxurl, {
            action: 'tka_doc_qr_delete',
            doc_id: id,
            security: '<?php echo esc_js($admin_nonce); ?>'
        }, function(res) {
            if (res.success) {
                jQuery('#doc-row-' + id).fadeOut(300, function() { jQuery(this).remove(); });
            } else {
                alert('Error: ' + (res.data || 'Could not delete item.'));
            }
        });
    }

    // Admin Quick Create Functions
    function tkaOpenAdminCreateModal() {
        document.getElementById('tka-admin-create-modal').style.display = 'flex';
    }

    function tkaAdminSwitchTab(mode) {
        document.getElementById('tka-adm-mode').value = mode;
        document.getElementById('tka-adm-pane-link').style.display = (mode === 'link') ? 'block' : 'none';
        document.getElementById('tka-adm-pane-file').style.display = (mode === 'file') ? 'block' : 'none';
        document.getElementById('tka-adm-pane-text').style.display = (mode === 'text') ? 'block' : 'none';

        document.getElementById('tka-adm-btn-link').className = (mode === 'link') ? 'button button-primary' : 'button';
        document.getElementById('tka-adm-btn-file').className = (mode === 'file') ? 'button button-primary' : 'button';
        document.getElementById('tka-adm-btn-text').className = (mode === 'text') ? 'button button-primary' : 'button';
    }

    function tkaAdminSubmitCreate() {
        var mode = document.getElementById('tka-adm-mode').value;
        var title = document.getElementById('tka-adm-title').value;
        var slug = document.getElementById('tka-adm-slug').value;
        var btn = document.getElementById('tka-adm-submit-btn');

        if (mode === 'link') {
            var url = document.getElementById('tka-adm-url').value.trim();
            if (!url) { alert('Please enter a destination URL.'); return; }

            btn.disabled = true;
            btn.innerText = 'Creating...';

            jQuery.post(ajaxurl, {
                action: 'tka_doc_qr_create_link',
                security: '<?php echo esc_js($user_nonce); ?>',
                mode: 'link',
                destination_url: url,
                doc_title: title,
                custom_slug: slug
            }, function(res) {
                btn.disabled = false;
                btn.innerText = '⚡ Create & Generate QR';
                if (res.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (res.data || 'Creation failed.'));
                }
            });

        } else if (mode === 'text') {
            var text = document.getElementById('tka-adm-text').value.trim();
            if (!text) { alert('Please enter note text.'); return; }

            btn.disabled = true;
            btn.innerText = 'Creating...';

            jQuery.post(ajaxurl, {
                action: 'tka_doc_qr_create_link',
                security: '<?php echo esc_js($user_nonce); ?>',
                mode: 'text',
                text_content: text,
                doc_title: title,
                custom_slug: slug
            }, function(res) {
                btn.disabled = false;
                btn.innerText = '⚡ Create & Generate QR';
                if (res.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (res.data || 'Creation failed.'));
                }
            });

        } else if (mode === 'file') {
            var fileField = document.getElementById('tka-adm-file');
            if (!fileField.files.length) { alert('Please select a file to upload.'); return; }

            btn.disabled = true;
            btn.innerText = 'Uploading...';

            var formData = new FormData();
            formData.append('action', 'tka_doc_qr_upload');
            formData.append('security', '<?php echo esc_js($user_nonce); ?>');
            formData.append('doc_file', fileField.files[0]);
            formData.append('doc_title', title);
            formData.append('custom_slug', slug);

            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(res) {
                    btn.disabled = false;
                    btn.innerText = '⚡ Create & Generate QR';
                    if (res.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + (res.data || 'Upload failed.'));
                    }
                },
                error: function() {
                    btn.disabled = false;
                    btn.innerText = '⚡ Create & Generate QR';
                    alert('Upload failed.');
                }
            });
        }
    }
    </script>
    <?php
}
