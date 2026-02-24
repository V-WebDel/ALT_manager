<?php
/**
 * Plugin Name: ALT Manager (Batch + Total Counter)
 * Description: Lists images without ALT, pre-fills ALT from Page Title (if found) or Filename, lets you edit, then Save/Skip per row. Cleans "-" "_" and refreshes list after save. Shows total missing ALT count.
 * Version: 1.0.0
 * Author: VIKTOR KRYZHNYI 
 */

if (!defined('ABSPATH')) exit;

class ALT_Manager_Batch {
    const PER_PAGE = 100;
    const NONCE_ACTION = 'alt_manager_save_nonce';

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
    }

    public static function add_menu(): void {
        add_media_page(
            'ALT Manager',
            'ALT Manager',
            'manage_options',
            'alt-manager',
            [__CLASS__, 'render_page']
        );
    }

    /** Replace - _ with spaces, collapse spaces, trim */
    private static function clean_text(string $s): string {
        $s = str_replace(['-', '_'], ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    /** Total count of image attachments without ALT */
    private static function count_images_without_alt(): int {
        global $wpdb;

        $sql = "
            SELECT COUNT(*)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm
              ON pm.post_id = p.ID AND pm.meta_key = '_wp_attachment_image_alt'
            WHERE p.post_type = 'attachment'
              AND p.post_mime_type LIKE 'image/%'
              AND (pm.meta_value IS NULL OR pm.meta_value = '')
        ";

        return (int) $wpdb->get_var($sql);
    }

    /** Fetch up to PER_PAGE image attachment IDs without ALT */
    private static function get_images_without_alt(int $limit): array {
        global $wpdb;

        $sql = $wpdb->prepare("
            SELECT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm
              ON pm.post_id = p.ID AND pm.meta_key = '_wp_attachment_image_alt'
            WHERE p.post_type = 'attachment'
              AND p.post_mime_type LIKE 'image/%'
              AND (pm.meta_value IS NULL OR pm.meta_value = '')
            ORDER BY p.ID ASC
            LIMIT %d
        ", $limit);

        $ids = (array) $wpdb->get_col($sql);
        return array_map('intval', $ids);
    }

    /** Suggest ALT from filename */
    private static function suggest_from_filename(int $attachment_id): string {
        $file = get_attached_file($attachment_id);
        if (!is_string($file) || $file === '') return '';
        $name = pathinfo($file, PATHINFO_FILENAME);
        return self::clean_text((string)$name);
    }

    /**
     * Find the first post/page ID where the image is used.
     * Strategy:
     *  1) Featured image: _thumbnail_id = attachment_id
     *  2) post_content contains "wp-image-{id}"
     *  3) post_content contains attachment URL path
     *
     * Returns 0 if not found.
     */
    private static function find_first_usage_post_id(int $attachment_id): int {
        global $wpdb;

        // 1) Featured image
        $sql = $wpdb->prepare("
            SELECT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm
              ON pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id' AND pm.meta_value = %d
            WHERE p.post_type NOT IN ('revision','attachment','nav_menu_item')
              AND p.post_status IN ('publish','private','draft','pending','future')
            ORDER BY p.ID ASC
            LIMIT 1
        ", $attachment_id);

        $post_id = (int) $wpdb->get_var($sql);
        if ($post_id > 0) return $post_id;

        // 2) wp-image-{id} in content
        $needle = '%wp-image-' . $wpdb->esc_like((string)$attachment_id) . '%';
        $sql = $wpdb->prepare("
            SELECT ID
            FROM {$wpdb->posts}
            WHERE post_type NOT IN ('revision','attachment','nav_menu_item')
              AND post_status IN ('publish','private','draft','pending','future')
              AND post_content LIKE %s
            ORDER BY ID ASC
            LIMIT 1
        ", $needle);

        $post_id = (int) $wpdb->get_var($sql);
        if ($post_id > 0) return $post_id;

        // 3) Attachment URL path in content
        $url = (string) wp_get_attachment_url($attachment_id);
        if ($url !== '') {
            $path = parse_url($url, PHP_URL_PATH);
            $path = is_string($path) ? $path : '';
            if ($path !== '') {
                $like = '%' . $wpdb->esc_like($path) . '%';
                $sql = $wpdb->prepare("
                    SELECT ID
                    FROM {$wpdb->posts}
                    WHERE post_type NOT IN ('revision','attachment','nav_menu_item')
                      AND post_status IN ('publish','private','draft','pending','future')
                      AND post_content LIKE %s
                    ORDER BY ID ASC
                    LIMIT 1
                ", $like);

                $post_id = (int) $wpdb->get_var($sql);
                if ($post_id > 0) return $post_id;
            }
        }

        return 0;
    }

    /** Suggest ALT from first usage post/page title */
    private static function suggest_from_page_title(int $attachment_id): string {
        $post_id = self::find_first_usage_post_id($attachment_id);
        if ($post_id <= 0) return '';
        return self::clean_text((string) get_the_title($post_id));
    }

    /** Update ALT only if currently empty (never overwrite) */
    private static function set_alt_if_empty(int $attachment_id, string $alt): bool {
        $current = (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        if (trim($current) !== '') return false;

        $alt = self::clean_text($alt);
        if ($alt === '') return false;

        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
        return true;
    }

    /**
     * Save batch:
     * - save: take value from input field (user can edit)
     * - skip: ignore
     */
    private static function handle_save(array $ids, array $mode_map, array $alt_map): array {
        $updated = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            $id = (int) $id;

            // If alt already exists - skip
            $existing = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
            if (trim($existing) !== '') { $skipped++; continue; }

            $mode = isset($mode_map[$id]) ? sanitize_text_field((string)$mode_map[$id]) : 'skip';
            if ($mode !== 'save') { $skipped++; continue; }

            $alt = isset($alt_map[$id]) ? (string) $alt_map[$id] : '';
            $alt = wp_strip_all_tags($alt);

            if (self::set_alt_if_empty($id, $alt)) $updated++;
            else $skipped++;
        }

        return compact('updated', 'skipped');
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) wp_die('Недостаточно прав.');

        // After redirect notice
        $saved_msg = '';
        if (isset($_GET['saved']) && $_GET['saved'] !== '') {
            $saved_msg = sanitize_text_field((string) $_GET['saved']);
        }

        // Handle POST save
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['alt_manager_action']) && $_POST['alt_manager_action'] === 'save') {
            check_admin_referer(self::NONCE_ACTION);

            $ids  = isset($_POST['ids']) ? array_map('intval', (array)$_POST['ids']) : [];
            $mode = isset($_POST['mode']) ? (array)$_POST['mode'] : [];
            $alt  = isset($_POST['alt']) ? (array)$_POST['alt'] : [];

            $result = self::handle_save($ids, $mode, $alt);

            // Redirect to refresh list and prevent resubmission
            $msg = rawurlencode("Сохранено: обновлено {$result['updated']}, пропущено {$result['skipped']}. Список обновлён.");
            wp_safe_redirect(admin_url('upload.php?page=alt-manager&saved=' . $msg));
            exit;
        }

        // Load totals and current list
        $total_missing = self::count_images_without_alt();
        $ids = self::get_images_without_alt(self::PER_PAGE);

        echo '<div class="wrap">';
        echo '<h1>ALT Manager</h1>';

        echo '<p><strong>Изображений без ALT:</strong> ' . number_format_i18n($total_missing) .
            ' &nbsp;|&nbsp; <strong>Показано:</strong> ' . number_format_i18n(min(self::PER_PAGE, $total_missing)) . '</p>';

        echo '<p>Поле ALT автоматически заполняется: сначала из <b>title страницы/поста</b> (если найдено использование), иначе из <b>названия файла</b>. Далее вы можете отредактировать ALT вручную и выбрать режим <b>Сохранить</b> или <b>Пропустить</b>. При сохранении список обновляется.</p>';

        if ($saved_msg !== '') {
            echo '<div class="notice notice-success"><p>' . esc_html($saved_msg) . '</p></div>';
        }

        if (empty($ids)) {
            echo '<div class="notice notice-success"><p>Изображений без ALT не найдено 🎉</p></div>';
            echo '</div>';
            return;
        }

        echo '<form method="post">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="alt_manager_action" value="save">';

        echo '<p style="margin: 12px 0;">
                <button class="button button-primary">Сохранить</button>
                <span style="margin-left:10px; opacity:.8;">(Сохраняет строки с режимом “Сохранить” и непустым ALT; остальные останутся в списке)</span>
              </p>';

        echo '<table class="widefat striped" style="max-width: 1500px;">';
        echo '<thead><tr>
                <th style="width:50px;">ID</th>
                <th style="width:140px;">Превью</th>
                <th style="width:440px;">Файл</th>
                <th style="width:140px;">ALT по title страницы/поста</th>
                <th style="width:140px;">ALT по названию файла</th>
                <th style="width:180px;">ALT (ввод/правка)</th>
                <th style="width:200px;">URL первой страницы</th>
                <th style="width:140px;">Режим</th>
              </tr></thead><tbody>';

        foreach ($ids as $id) {
            $file = (string) get_attached_file($id);
            $url  = (string) wp_get_attachment_url($id);

            $usage_post_id = self::find_first_usage_post_id($id);
            $page_s = $usage_post_id > 0 ? self::clean_text((string) get_the_title($usage_post_id)) : '';
            $file_s = self::suggest_from_filename($id);

            // Prefill ALT input: page_s -> file_s -> empty
            $prefill = $page_s !== '' ? $page_s : ($file_s !== '' ? $file_s : '');

            // Default mode: Save if prefilled, else Skip
            $default_mode = 'skip';

            $usage_url = $usage_post_id > 0 ? get_permalink($usage_post_id) : '';

            echo '<tr>';
            echo '<td>' . (int)$id . '<input type="hidden" name="ids[]" value="' . (int)$id . '"></td>';
            echo '<td>' . wp_get_attachment_image($id, [90, 90]) . '</td>';
            echo '<td><code style="word-break:break-all;">' . esc_html($file) . '</code><br><a href="' . esc_url($url) . '" target="_blank" rel="noopener">Открыть</a></td>';

            echo '<td>' . esc_html($page_s !== '' ? $page_s : '—') . '</td>';
            echo '<td>' . esc_html($file_s !== '' ? $file_s : '—') . '</td>';

            echo '<td>
              <textarea id="alt_input_' . (int)$id . '" name="alt[' . (int)$id . ']" rows="4" style="width:100%;" placeholder="Введите ALT">' . esc_textarea($prefill) . '</textarea>
          </td>';

            if ($usage_url !== '') {
                echo '<td><a href="' . esc_url($usage_url) . '" target="_blank" rel="noopener">' . esc_html($usage_url) . '</a></td>';
            } else {
                echo '<td>—</td>';
            }

            echo '<td>';
            echo '<label style="display:block; margin-bottom:4px;">
                    <input type="radio" name="mode[' . (int)$id . ']" value="save" ' . checked($default_mode, 'save', false) . '> Сохранить
                  </label>';
            echo '<label style="display:block;">
                    <input type="radio" name="mode[' . (int)$id . ']" value="skip" ' . checked($default_mode, 'skip', false) . '> Пропустить
                  </label>';
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<p style="margin: 12px 0;">
                <button class="button button-primary">Сохранить</button>
              </p>';

        echo '</form>';
        echo '</div>';
    }
}

ALT_Manager_Batch::init();
