<?php
/**
 * Plugin Name: Science Class Sidebar Chatbot
 * Description: Sidebar chatbot for lesson coaching with teacher personality, lesson objectives, and progress tracking.
 * Version: 0.1.10
 * Author: Yohei
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SCSB_VERSION', '0.1.10');
define('SCSB_PLUGIN_FILE', __FILE__);
define('SCSB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SCSB_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once SCSB_PLUGIN_DIR . 'includes/class-science-chatbot-widget.php';

class Science_Class_Sidebar_Chatbot {
    private $option_name = 'scsb_settings';
    private $lessons_option_name = 'scsb_lessons';
    private $table_name;
    private $coin_balance_meta_key = 'scsb_yohei_coin_balance';
    private $objective_rewards_meta_key = 'scsb_objective_rewards';
    private $coins_per_objective = 10;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'scsb_progress';

        register_activation_hook(SCSB_PLUGIN_FILE, array($this, 'on_activate'));

        add_action('widgets_init', array($this, 'register_widget'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_public_assets'));

        add_action('admin_menu', array($this, 'register_admin_pages'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        add_action('wp_ajax_scsb_send_message', array($this, 'handle_send_message'));
        add_action('wp_ajax_scsb_get_leaderboard', array($this, 'handle_get_leaderboard'));
        add_shortcode('scsb_leaderboard', array($this, 'render_leaderboard_shortcode'));
    }

    public function on_activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            student_id VARCHAR(191) NOT NULL,
            lesson_slug VARCHAR(191) NOT NULL,
            objective_met TINYINT(1) NOT NULL DEFAULT 0,
            tasks_text LONGTEXT NULL,
            token VARCHAR(191) NULL,
            conversation LONGTEXT NULL,
            PRIMARY KEY (id),
            KEY student_id (student_id),
            KEY lesson_slug (lesson_slug),
            KEY created_at (created_at)
        ) $charset_collate";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function register_widget() {
        register_widget('SCSB_Chat_Widget');
    }

    public function enqueue_public_assets() {
        wp_enqueue_style('scsb-chatbot-css', SCSB_PLUGIN_URL . 'assets/css/chatbot.css', array(), SCSB_VERSION);
        wp_enqueue_script('scsb-chatbot-js', SCSB_PLUGIN_URL . 'assets/js/chatbot.js', array(), SCSB_VERSION, true);

        $settings = $this->get_settings();
        $current_user = wp_get_current_user();
        wp_localize_script('scsb-chatbot-js', 'SCSBConfig', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('scsb_chat_nonce'),
            'lessons' => $this->get_configured_lessons(),
            'isLoggedIn' => is_user_logged_in(),
            'currentUser' => array(
                'id' => is_user_logged_in() ? (int) $current_user->ID : 0,
                'displayName' => is_user_logged_in() ? $current_user->display_name : '',
            ),
            'stateImages' => array(
                'idle' => $this->get_attachment_url($settings['image_idle_id']),
                'thinking' => $this->get_attachment_url($settings['image_thinking_id']),
                'objectiveMet' => $this->get_attachment_url($settings['image_objective_met_id']),
                'keepTrying' => $this->get_attachment_url($settings['image_keep_trying_id']),
            ),
        ));
    }

    public function enqueue_admin_assets($hook_suffix) {
        if ($hook_suffix !== 'toplevel_page_scsb-settings') {
            return;
        }
        wp_enqueue_media();
    }

    public function register_admin_pages() {
        add_menu_page(
            'Science Chatbot',
            'Science Chatbot',
            'manage_options',
            'scsb-settings',
            array($this, 'render_settings_page'),
            'dashicons-format-chat',
            58
        );

        add_submenu_page(
            'scsb-settings',
            'Lessons',
            'Lessons',
            'manage_options',
            'scsb-lessons',
            array($this, 'render_lessons_page')
        );

        add_submenu_page(
            'scsb-settings',
            'Student Progress',
            'Student Progress',
            'manage_options',
            'scsb-progress',
            array($this, 'render_progress_page')
        );

        add_submenu_page(
            'scsb-settings',
            'Student Coins',
            'Student Coins',
            'manage_options',
            'scsb-coins',
            array($this, 'render_student_coins_page')
        );
    }

    public function register_settings() {
        register_setting('scsb_settings_group', $this->option_name, array($this, 'sanitize_settings'));
    }

    public function sanitize_settings($input) {
        return array(
            'teacher_personality_md' => isset($input['teacher_personality_md']) ? wp_kses_post($input['teacher_personality_md']) : '',
            'lessons_md' => isset($input['lessons_md']) ? wp_kses_post($input['lessons_md']) : '',
            'api_endpoint' => isset($input['api_endpoint']) ? esc_url_raw($input['api_endpoint']) : '',
            'api_key' => isset($input['api_key']) ? sanitize_text_field($input['api_key']) : '',
            'api_model' => isset($input['api_model']) ? sanitize_text_field($input['api_model']) : '',
            'image_idle_id' => isset($input['image_idle_id']) ? absint($input['image_idle_id']) : 0,
            'image_thinking_id' => isset($input['image_thinking_id']) ? absint($input['image_thinking_id']) : 0,
            'image_objective_met_id' => isset($input['image_objective_met_id']) ? absint($input['image_objective_met_id']) : 0,
            'image_keep_trying_id' => isset($input['image_keep_trying_id']) ? absint($input['image_keep_trying_id']) : 0,
        );
    }

    private function get_settings() {
        $defaults = array(
            'teacher_personality_md' => "You are a high-school science teacher chatbot. Stay encouraging and concise.",
            'lessons_md' => "## Lesson: Cell Structure\n- Objective: Explain the function of the nucleus, membrane, and mitochondria.",
            'api_endpoint' => 'https://api.openai.com/v1/chat/completions',
            'api_key' => '',
            'api_model' => 'gpt-4o-mini',
            'image_idle_id' => 0,
            'image_thinking_id' => 0,
            'image_objective_met_id' => 0,
            'image_keep_trying_id' => 0,
        );

        $stored = get_option($this->option_name, array());
        return wp_parse_args($stored, $defaults);
    }

    private function get_attachment_url($attachment_id) {
        $attachment_id = absint($attachment_id);
        if ($attachment_id <= 0) {
            return '';
        }
        $url = wp_get_attachment_image_url($attachment_id, 'medium');
        return $url ? esc_url_raw($url) : '';
    }

    private function parse_lessons($lessons_md) {
        $lines = preg_split('/\r\n|\r|\n/', (string) $lessons_md);
        $lessons = array();
        $current = null;

        foreach ($lines as $line) {
            $trim = trim($line);
            if (stripos($trim, '## Lesson:') === 0) {
                if ($current) {
                    $lessons[] = $current;
                }
                $title = trim(substr($trim, strlen('## Lesson:')));
                $current = array(
                    'slug' => sanitize_title($title),
                    'title' => $title,
                    'objective' => '',
                );
            } elseif ($current && stripos($trim, '- Objective:') === 0) {
                $current['objective'] = trim(substr($trim, strlen('- Objective:')));
            }
        }

        if ($current) {
            $lessons[] = $current;
        }

        return $lessons;
    }

    private function get_lessons_from_option() {
        $stored = get_option($this->lessons_option_name, array());
        if (!is_array($stored)) {
            return array();
        }

        $lessons = array();
        foreach ($stored as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = !empty($item['id']) ? sanitize_key($item['id']) : '';
            $subject = !empty($item['subject']) ? sanitize_text_field($item['subject']) : '';
            $lesson_name = !empty($item['lesson_name']) ? sanitize_text_field($item['lesson_name']) : '';
            $objectives = isset($item['objectives']) && is_array($item['objectives']) ? $item['objectives'] : array();
            $clean_objectives = array();
            foreach ($objectives as $objective) {
                $clean = sanitize_text_field((string) $objective);
                if ($clean !== '') {
                    $clean_objectives[] = $clean;
                }
            }

            if ($id === '' || $subject === '' || $lesson_name === '' || empty($clean_objectives)) {
                continue;
            }

            $lessons[] = array(
                'id' => $id,
                'subject' => $subject,
                'lesson_name' => $lesson_name,
                'objectives' => $clean_objectives,
                'slug' => sanitize_title($lesson_name),
                'title' => $lesson_name,
                'objective' => implode(' ', $clean_objectives),
            );
        }

        return $lessons;
    }

    private function get_configured_lessons() {
        $lessons = $this->get_lessons_from_option();
        if (!empty($lessons)) {
            return $lessons;
        }

        $settings = $this->get_settings();
        $legacy = $this->parse_lessons($settings['lessons_md']);
        $mapped = array();
        foreach ($legacy as $lesson) {
            $mapped[] = array(
                'id' => sanitize_key($lesson['slug']),
                'subject' => 'General Science',
                'lesson_name' => $lesson['title'],
                'objectives' => array($lesson['objective']),
                'slug' => $lesson['slug'],
                'title' => $lesson['title'],
                'objective' => $lesson['objective'],
            );
        }
        return $mapped;
    }

    private function format_lessons_for_prompt($lessons) {
        if (empty($lessons)) {
            return 'No lessons configured.';
        }

        $parts = array();
        foreach ($lessons as $lesson) {
            $parts[] = sprintf(
                "Lesson: %s\nObjective: %s",
                $lesson['title'],
                $lesson['objective'] ?: 'No objective provided.'
            );
        }

        return implode("\n\n", $parts);
    }

    private function request_llm($settings, $payload) {
        return wp_remote_post($settings['api_endpoint'], array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $settings['api_key'],
            ),
            'body' => wp_json_encode($payload),
            'timeout' => 45,
        ));
    }

    private function extract_provider_error_message($body) {
        $decoded = json_decode((string) $body, true);
        if (is_array($decoded) && isset($decoded['error']['message'])) {
            return sanitize_text_field($decoded['error']['message']);
        }
        return '';
    }

    private function extract_model_content($decoded) {
        if (!is_array($decoded)) {
            return '';
        }

        // Chat Completions shape.
        if (isset($decoded['choices'][0]['message']['content'])) {
            $content = $decoded['choices'][0]['message']['content'];
            return is_array($content) ? wp_json_encode($content) : (string) $content;
        }

        // Responses API shape.
        if (isset($decoded['output'][0]['content'][0]['text'])) {
            return (string) $decoded['output'][0]['content'][0]['text'];
        }

        return '';
    }

    private function parse_history($raw_history) {
        if (!is_string($raw_history) || $raw_history === '') {
            return array();
        }

        $decoded = json_decode($raw_history, true);
        if (!is_array($decoded)) {
            return array();
        }

        $messages = array();
        foreach ($decoded as $item) {
            if (!is_array($item) || empty($item['role']) || empty($item['content'])) {
                continue;
            }

            $role = $item['role'] === 'bot' ? 'assistant' : 'user';
            $content = sanitize_textarea_field((string) $item['content']);
            if ($content === '') {
                continue;
            }

            $messages[] = array(
                'role' => $role,
                'content' => $content,
            );
        }

        return array_slice($messages, -10);
    }

    private function objective_keyword_list($objective_text) {
        $objective_text = strtolower((string) $objective_text);
        $objective_text = preg_replace('/[^a-z0-9\s]/', ' ', $objective_text);
        $parts = preg_split('/\s+/', trim($objective_text));
        if (!is_array($parts)) {
            return array();
        }

        $stop = array(
            'the', 'and', 'with', 'that', 'this', 'from', 'into', 'about', 'explain', 'describe',
            'identify', 'define', 'lesson', 'objective', 'student', 'their', 'they', 'what', 'when',
            'where', 'which', 'will', 'able', 'your', 'for', 'are', 'how',
        );
        $keywords = array();
        foreach ($parts as $p) {
            if (strlen($p) < 5 || in_array($p, $stop, true)) {
                continue;
            }
            $keywords[$p] = true;
        }
        return array_keys($keywords);
    }

    private function objective_heuristic_met($objective_text, $student_text) {
        $keywords = $this->objective_keyword_list($objective_text);
        if (count($keywords) < 2) {
            return false;
        }

        $student_text = strtolower((string) $student_text);
        $matched = 0;
        foreach ($keywords as $keyword) {
            if (strpos($student_text, $keyword) !== false) {
                $matched++;
            }
        }

        return $matched >= 2;
    }

    private function build_objective_key($lesson_slug, $objective_text) {
        return sanitize_key($lesson_slug . '_' . md5((string) $objective_text));
    }

    private function get_user_objective_rewards($user_id) {
        $stored = get_user_meta($user_id, $this->objective_rewards_meta_key, true);
        return is_array($stored) ? $stored : array();
    }

    private function get_user_coin_balance($user_id) {
        return (int) get_user_meta($user_id, $this->coin_balance_meta_key, true);
    }

    private function get_subjects() {
        $lessons = $this->get_configured_lessons();
        $subjects = array();
        foreach ($lessons as $lesson) {
            $subject = isset($lesson['subject']) ? sanitize_text_field($lesson['subject']) : '';
            if ($subject !== '') {
                $subjects[$subject] = true;
            }
        }
        $subject_names = array_keys($subjects);
        sort($subject_names, SORT_NATURAL | SORT_FLAG_CASE);
        return $subject_names;
    }

    private function get_lesson_subject_map() {
        $lessons = $this->get_configured_lessons();
        $map = array();
        foreach ($lessons as $lesson) {
            if (empty($lesson['slug']) || empty($lesson['subject'])) {
                continue;
            }
            $map[sanitize_title($lesson['slug'])] = sanitize_text_field($lesson['subject']);
        }
        return $map;
    }

    private function get_user_subject_balance($user_id, $subject_filter) {
        $subject_filter = sanitize_text_field((string) $subject_filter);
        if ($subject_filter === '') {
            return $this->get_user_coin_balance($user_id);
        }

        $rewards = $this->get_user_objective_rewards($user_id);
        if (!is_array($rewards) || empty($rewards)) {
            return 0;
        }

        $slug_to_subject = $this->get_lesson_subject_map();
        $total = 0;
        foreach ($rewards as $reward) {
            if (!is_array($reward)) {
                continue;
            }
            $subject = '';
            if (!empty($reward['subject'])) {
                $subject = sanitize_text_field((string) $reward['subject']);
            } elseif (!empty($reward['lesson_slug'])) {
                $slug = sanitize_title((string) $reward['lesson_slug']);
                $subject = isset($slug_to_subject[$slug]) ? $slug_to_subject[$slug] : '';
            }
            if ($subject !== $subject_filter) {
                continue;
            }
            $total += isset($reward['coins_awarded']) ? (int) $reward['coins_awarded'] : 0;
        }
        return $total;
    }

    public function render_leaderboard_shortcode($atts = array()) {
        $atts = shortcode_atts(array('limit' => 10, 'subject' => ''), $atts, 'scsb_leaderboard');
        $limit = max(1, min(50, (int) $atts['limit']));
        $subject_filter = sanitize_text_field((string) $atts['subject']);

        $users = get_users(array(
            'number' => 500,
            'fields' => array('ID', 'display_name'),
        ));
        $rows = array();
        foreach ($users as $user) {
            $coins = $this->get_user_subject_balance((int) $user->ID, $subject_filter);
            if ($coins <= 0) {
                continue;
            }
            $rows[] = array(
                'name' => $user->display_name,
                'coins' => $coins,
            );
        }
        usort($rows, function ($a, $b) {
            return $b['coins'] <=> $a['coins'];
        });
        $rows = array_slice($rows, 0, $limit);

        ob_start();
        ?>
        <div class="scsb-leaderboard">
            <h4>Yohei Coin Leaderboard<?php echo $subject_filter ? ' - ' . esc_html($subject_filter) : ''; ?></h4>
            <table class="scsb-leaderboard-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student</th>
                        <th>Coins</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)) : ?>
                    <tr><td colspan="3">No leaderboard entries yet.</td></tr>
                <?php else : ?>
                    <?php $rank = 1; ?>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td><?php echo esc_html((string) $rank); ?></td>
                            <td><?php echo esc_html($row['name']); ?></td>
                            <td><?php echo esc_html((string) $row['coins']); ?></td>
                        </tr>
                        <?php $rank++; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    public function handle_get_leaderboard() {
        check_ajax_referer('scsb_chat_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(array('error' => 'Login required.'), 401);
        }
        $limit = isset($_POST['limit']) ? absint(wp_unslash($_POST['limit'])) : 10;
        $subject = isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : '';
        $html = $this->render_leaderboard_shortcode(array('limit' => $limit, 'subject' => $subject));
        wp_send_json_success(array('html' => $html));
    }

    private function maybe_handle_progress_delete_action() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!isset($_POST['scsb_action']) || $_POST['scsb_action'] !== 'delete_progress') {
            return;
        }

        check_admin_referer('scsb_delete_progress', 'scsb_delete_nonce');

        $selected_ids = isset($_POST['progress_ids']) ? wp_unslash($_POST['progress_ids']) : array();
        if (!is_array($selected_ids) || empty($selected_ids)) {
            add_settings_error('scsb_progress', 'no_rows_selected', 'No progress rows selected.', 'warning');
            return;
        }

        $ids = array_values(array_filter(array_map('absint', $selected_ids)));
        if (empty($ids)) {
            add_settings_error('scsb_progress', 'invalid_rows_selected', 'Invalid progress rows selected.', 'error');
            return;
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = $wpdb->prepare("DELETE FROM {$this->table_name} WHERE id IN ($placeholders)", $ids);
        $deleted = $wpdb->query($sql);

        if ($deleted === false) {
            add_settings_error('scsb_progress', 'delete_failed', 'Failed to delete selected rows.', 'error');
            return;
        }

        add_settings_error('scsb_progress', 'delete_success', sprintf('Deleted %d progress row(s).', (int) $deleted), 'updated');
    }

    private function maybe_handle_lessons_action() {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!isset($_POST['scsb_lessons_action'])) {
            return;
        }

        check_admin_referer('scsb_lessons_action', 'scsb_lessons_nonce');
        $action = sanitize_key(wp_unslash($_POST['scsb_lessons_action']));
        $lessons = $this->get_lessons_from_option();

        if ($action === 'save') {
            $lesson_id = isset($_POST['lesson_id']) ? sanitize_key(wp_unslash($_POST['lesson_id'])) : '';
            $subject = isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : '';
            $lesson_name = isset($_POST['lesson_name']) ? sanitize_text_field(wp_unslash($_POST['lesson_name'])) : '';
            $objectives_raw = isset($_POST['objectives']) ? (string) wp_unslash($_POST['objectives']) : '';
            $objective_lines = preg_split('/\r\n|\r|\n/', $objectives_raw);
            $objectives = array();
            foreach ($objective_lines as $line) {
                $clean = sanitize_text_field(trim((string) $line));
                if ($clean !== '') {
                    $objectives[] = $clean;
                }
            }

            if ($subject === '' || $lesson_name === '' || empty($objectives)) {
                add_settings_error('scsb_lessons', 'missing_fields', 'Subject, lesson name, and at least one objective are required.', 'error');
                return;
            }

            if ($lesson_id === '') {
                $lesson_id = sanitize_key('lesson_' . wp_generate_password(10, false, false));
                $lessons[] = array(
                    'id' => $lesson_id,
                    'subject' => $subject,
                    'lesson_name' => $lesson_name,
                    'objectives' => $objectives,
                );
                update_option($this->lessons_option_name, $lessons, false);
                add_settings_error('scsb_lessons', 'lesson_added', 'Lesson added.', 'updated');
                return;
            }

            foreach ($lessons as &$lesson) {
                if (!isset($lesson['id']) || sanitize_key($lesson['id']) !== $lesson_id) {
                    continue;
                }
                $lesson['subject'] = $subject;
                $lesson['lesson_name'] = $lesson_name;
                $lesson['objectives'] = $objectives;
                break;
            }
            unset($lesson);
            update_option($this->lessons_option_name, $lessons, false);
            add_settings_error('scsb_lessons', 'lesson_updated', 'Lesson updated.', 'updated');
            return;
        }

        if ($action === 'delete') {
            $lesson_id = isset($_POST['lesson_id']) ? sanitize_key(wp_unslash($_POST['lesson_id'])) : '';
            if ($lesson_id === '') {
                add_settings_error('scsb_lessons', 'invalid_delete', 'Invalid lesson selected for deletion.', 'error');
                return;
            }
            $filtered = array_values(array_filter($lessons, function ($lesson) use ($lesson_id) {
                return isset($lesson['id']) && sanitize_key($lesson['id']) !== $lesson_id;
            }));
            update_option($this->lessons_option_name, $filtered, false);
            add_settings_error('scsb_lessons', 'lesson_deleted', 'Lesson deleted.', 'updated');
        }
    }

    private function maybe_handle_student_coins_action() {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!isset($_POST['scsb_coins_action'])) {
            return;
        }

        check_admin_referer('scsb_coins_action', 'scsb_coins_nonce');

        $action = sanitize_key(wp_unslash($_POST['scsb_coins_action']));
        $user_id = isset($_POST['user_id']) ? absint(wp_unslash($_POST['user_id'])) : 0;
        $operation = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';
        $amount = isset($_POST['amount']) ? absint(wp_unslash($_POST['amount'])) : 0;

        $user = get_user_by('id', $user_id);
        if (!$user) {
            add_settings_error('scsb_coins', 'user_not_found', 'Student account not found.', 'error');
            return;
        }

        if ($action === 'adjust') {
            $adjust_subject = isset($_POST['adjust_subject']) ? sanitize_text_field(wp_unslash($_POST['adjust_subject'])) : '';
            if ($user_id <= 0 || !in_array($operation, array('add', 'remove'), true) || $amount <= 0 || $adjust_subject === '') {
                add_settings_error('scsb_coins', 'invalid_input', 'Invalid coin update request.', 'error');
                return;
            }

            $current = $this->get_user_coin_balance($user_id);
            $new_balance = $operation === 'add' ? $current + $amount : max(0, $current - $amount);
            update_user_meta($user_id, $this->coin_balance_meta_key, $new_balance);
            $delta = $new_balance - $current;

            // Record manual subject-linked adjustments so subject leaderboards stay consistent.
            if ($delta !== 0) {
                $rewards = $this->get_user_objective_rewards($user_id);
                $manual_key = sanitize_key('manual_' . time() . '_' . wp_generate_password(6, false, false));
                $rewards[$manual_key] = array(
                    'token' => '',
                    'lesson_slug' => '',
                    'subject' => $adjust_subject,
                    'objective' => 'Manual coin adjustment',
                    'coins_awarded' => $delta,
                    'completed_at' => current_time('mysql'),
                    'manual' => 1,
                );
                update_user_meta($user_id, $this->objective_rewards_meta_key, $rewards);
            }

            $verb = $operation === 'add' ? 'Added' : 'Removed';
            add_settings_error(
                'scsb_coins',
                'coins_updated',
                sprintf('%s %d Yohei Coin for %s in %s. New balance: %d.', $verb, $amount, $user->display_name, $adjust_subject, $new_balance),
                'updated'
            );
            return;
        }

        if ($action === 'clear_rewards') {
            $clear_subject = isset($_POST['clear_subject']) ? sanitize_text_field(wp_unslash($_POST['clear_subject'])) : '';
            $rewards = $this->get_user_objective_rewards($user_id);
            if (empty($rewards)) {
                add_settings_error('scsb_coins', 'no_records', 'No earned subject records to clear for this student.', 'warning');
                return;
            }

            $slug_to_subject = $this->get_lesson_subject_map();
            $kept = array();
            $removed_records = 0;
            $removed_coins = 0;
            foreach ($rewards as $key => $reward) {
                if (!is_array($reward)) {
                    $kept[$key] = $reward;
                    continue;
                }

                $subject = '';
                if (!empty($reward['subject'])) {
                    $subject = sanitize_text_field((string) $reward['subject']);
                } elseif (!empty($reward['lesson_slug'])) {
                    $slug = sanitize_title((string) $reward['lesson_slug']);
                    $subject = isset($slug_to_subject[$slug]) ? $slug_to_subject[$slug] : '';
                }
                $matches = ($clear_subject === '') || ($subject === $clear_subject);
                if ($matches) {
                    $removed_records++;
                    $removed_coins += isset($reward['coins_awarded']) ? (int) $reward['coins_awarded'] : 0;
                } else {
                    $kept[$key] = $reward;
                }
            }

            if ($removed_records === 0) {
                add_settings_error('scsb_coins', 'no_matching_records', 'No matching subject records were found to clear.', 'warning');
                return;
            }

            if (empty($kept)) {
                delete_user_meta($user_id, $this->objective_rewards_meta_key);
            } else {
                update_user_meta($user_id, $this->objective_rewards_meta_key, $kept);
            }

            $current = $this->get_user_coin_balance($user_id);
            $new_balance = max(0, $current - $removed_coins);
            update_user_meta($user_id, $this->coin_balance_meta_key, $new_balance);

            $subject_label = $clear_subject === '' ? 'all subjects' : $clear_subject;
            add_settings_error(
                'scsb_coins',
                'records_cleared',
                sprintf(
                    'Cleared %d earned record(s) for %s (%s), removed %d earned coins. New balance: %d.',
                    $removed_records,
                    $user->display_name,
                    $subject_label,
                    $removed_coins,
                    $new_balance
                ),
                'updated'
            );
            return;
        }

        add_settings_error('scsb_coins', 'invalid_action', 'Invalid coins action.', 'error');
    }

    public function handle_send_message() {
        check_ajax_referer('scsb_chat_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(array('error' => 'Login required.'), 401);
        }

        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
        $lesson_id = isset($_POST['lessonId']) ? sanitize_key(wp_unslash($_POST['lessonId'])) : '';
        $lesson_slug = isset($_POST['lessonSlug']) ? sanitize_title(wp_unslash($_POST['lessonSlug'])) : '';
        $history_raw = isset($_POST['history']) ? wp_unslash($_POST['history']) : '';
        $user = wp_get_current_user();
        $user_id = (int) $user->ID;

        if ($message === '' || ($lesson_id === '' && $lesson_slug === '')) {
            wp_send_json_error(array('error' => 'Missing required fields.'), 400);
        }

        $settings = $this->get_settings();
        if (empty($settings['api_key']) || empty($settings['api_endpoint']) || empty($settings['api_model'])) {
            wp_send_json_error(array('error' => 'LLM API settings are incomplete.'), 500);
        }

        $lessons = $this->get_configured_lessons();
        $selected_lesson = null;

        foreach ($lessons as $lesson) {
            if (
                ($lesson_id !== '' && isset($lesson['id']) && $lesson['id'] === $lesson_id) ||
                ($lesson_slug !== '' && isset($lesson['slug']) && $lesson['slug'] === $lesson_slug)
            ) {
                $selected_lesson = $lesson;
                break;
            }
        }

        if (!$selected_lesson) {
            wp_send_json_error(array('error' => 'Lesson not found.'), 404);
        }

        $system_prompt = "Teacher personality:\n" . $settings['teacher_personality_md'] . "\n\n";
        $system_prompt .= "Lessons and objectives:\n" . $this->format_lessons_for_prompt($lessons) . "\n\n";
        $system_prompt .= "Current lesson: {$selected_lesson['title']}\nCurrent objective: {$selected_lesson['objective']}\n\n";
        $system_prompt .= "You are coaching a student. Ask brief probing questions.\n";
        $system_prompt .= "Do not repeat the same question twice in a row. Build on prior student answers.\n";
        $system_prompt .= "Set objective_met=true once the student demonstrates the objective with at least two accurate ideas.\n";
        $system_prompt .= "If objective_met is false, assign 2-3 concrete tasks in tasks.\n";
        $system_prompt .= "Return JSON only with keys: reply (string), objective_met (boolean), tasks (array of short strings).\n";
        $system_prompt .= "If objective_met is true, include a brief congratulatory message in reply and tasks should be empty.";

        $history_messages = $this->parse_history($history_raw);
        $messages = array(array('role' => 'system', 'content' => $system_prompt));
        foreach ($history_messages as $history_message) {
            $messages[] = $history_message;
        }
        $messages[] = array('role' => 'user', 'content' => $message);

        $payload = array(
            'model' => $settings['api_model'],
            'messages' => $messages,
            'response_format' => array('type' => 'json_object'),
        );

        $response = $this->request_llm($settings, $payload);

        if (is_wp_error($response)) {
            wp_send_json_error(array('error' => $response->get_error_message()), 500);
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        // Some providers/models reject strict JSON mode. Retry once without response_format.
        if ($status < 200 || $status >= 300) {
            unset($payload['response_format']);
            $retry = $this->request_llm($settings, $payload);
            if (!is_wp_error($retry)) {
                $retry_status = wp_remote_retrieve_response_code($retry);
                if ($retry_status >= 200 && $retry_status < 300) {
                    $response = $retry;
                    $status = $retry_status;
                    $body = wp_remote_retrieve_body($retry);
                }
            }
        }

        if ($status < 200 || $status >= 300) {
            $provider_message = $this->extract_provider_error_message($body);
            wp_send_json_error(array(
                'error' => 'LLM request failed.',
                'status' => $status,
                'providerMessage' => $provider_message,
                'details' => $body,
            ), 500);
        }

        $decoded = json_decode($body, true);
        $content = $this->extract_model_content($decoded);
        $chat_result = json_decode($content, true);

        if (!is_array($chat_result) || !isset($chat_result['reply'])) {
            wp_send_json_error(array('error' => 'Invalid LLM response.', 'raw' => $content), 500);
        }

        $objective_met = !empty($chat_result['objective_met']);
        $tasks = isset($chat_result['tasks']) && is_array($chat_result['tasks']) ? $chat_result['tasks'] : array();

        if (!$objective_met && $this->objective_heuristic_met($selected_lesson['objective'], $message)) {
            $objective_met = true;
            $tasks = array();
            $chat_result['reply'] .= ' You clearly met the objective.';
        }

        $token = '';
        $coins_awarded = 0;
        $coin_balance = $this->get_user_coin_balance($user_id);
        if ($lesson_slug === '' && !empty($selected_lesson['slug'])) {
            $lesson_slug = $selected_lesson['slug'];
        }
        $objective_key = $this->build_objective_key($lesson_slug, $selected_lesson['objective']);

        if ($objective_met) {
            $rewards = $this->get_user_objective_rewards($user_id);
            if (isset($rewards[$objective_key]['token'])) {
                $token = (string) $rewards[$objective_key]['token'];
            } else {
                $token = 'YH-' . strtoupper(wp_generate_password(8, false, false));
                $coins_awarded = $this->coins_per_objective;
                $coin_balance += $coins_awarded;
                update_user_meta($user_id, $this->coin_balance_meta_key, $coin_balance);
                $rewards[$objective_key] = array(
                    'token' => $token,
                    'lesson_slug' => $lesson_slug,
                    'objective' => (string) $selected_lesson['objective'],
                    'coins_awarded' => $coins_awarded,
                    'completed_at' => current_time('mysql'),
                );
                update_user_meta($user_id, $this->objective_rewards_meta_key, $rewards);
            }
        }

        global $wpdb;
        $wpdb->insert(
            $this->table_name,
            array(
                'created_at' => current_time('mysql'),
                'student_id' => sprintf('%d:%s', $user_id, $user->display_name),
                'lesson_slug' => $lesson_slug,
                'objective_met' => $objective_met ? 1 : 0,
                'tasks_text' => maybe_serialize($tasks),
                'token' => $token,
                'conversation' => wp_json_encode(array(
                    'user_id' => $user_id,
                    'student' => $message,
                    'assistant' => $chat_result['reply'],
                    'objective_key' => $objective_key,
                    'coins_awarded' => $coins_awarded,
                    'coin_balance' => $coin_balance,
                )),
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s', '%s')
        );

        wp_send_json_success(array(
            'reply' => $chat_result['reply'],
            'objectiveMet' => $objective_met,
            'tasks' => $tasks,
            'token' => $token,
            'coinsAwarded' => $coins_awarded,
            'coinBalance' => $coin_balance,
        ));
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
        ?>
        <div class="wrap">
            <h1>Science Chatbot Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('scsb_settings_group'); ?>

                <h2>1) Teacher Personality Config (Markdown)</h2>
                <textarea name="<?php echo esc_attr($this->option_name); ?>[teacher_personality_md]" rows="10" style="width:100%;"><?php echo esc_textarea($settings['teacher_personality_md']); ?></textarea>

                <h2>2) Lessons with Objectives</h2>
                <p>Manage lessons on the <a href="<?php echo esc_url(admin_url('admin.php?page=scsb-lessons')); ?>">Lessons page</a> (Subject, Lesson, Objectives).</p>

                <h2>3) LLM API</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label>API Endpoint</label></th>
                        <td><input type="url" style="width:100%;" name="<?php echo esc_attr($this->option_name); ?>[api_endpoint]" value="<?php echo esc_attr($settings['api_endpoint']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>API Key</label></th>
                        <td><input type="password" style="width:100%;" name="<?php echo esc_attr($this->option_name); ?>[api_key]" value="<?php echo esc_attr($settings['api_key']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Model</label></th>
                        <td><input type="text" style="width:100%;" name="<?php echo esc_attr($this->option_name); ?>[api_model]" value="<?php echo esc_attr($settings['api_model']); ?>" /></td>
                    </tr>
                </table>

                <h2>5) Chatbot State Images</h2>
                <p>Choose optional images from the media library for each chatbot state.</p>
                <?php
                $image_fields = array(
                    'image_idle_id' => 'Idle',
                    'image_thinking_id' => 'Thinking',
                    'image_objective_met_id' => 'Objective Met',
                    'image_keep_trying_id' => 'Keep Trying',
                );
                ?>
                <table class="form-table" role="presentation">
                    <?php foreach ($image_fields as $field_key => $field_label) : ?>
                        <?php $img_id = isset($settings[$field_key]) ? absint($settings[$field_key]) : 0; ?>
                        <?php $img_url = $this->get_attachment_url($img_id); ?>
                        <tr>
                            <th scope="row"><label><?php echo esc_html($field_label); ?> Image</label></th>
                            <td>
                                <input type="hidden" id="scsb-<?php echo esc_attr($field_key); ?>" name="<?php echo esc_attr($this->option_name); ?>[<?php echo esc_attr($field_key); ?>]" value="<?php echo esc_attr((string) $img_id); ?>" />
                                <button type="button" class="button scsb-select-image-btn" data-target="scsb-<?php echo esc_attr($field_key); ?>">Choose Image</button>
                                <button type="button" class="button scsb-clear-image-btn" data-target="scsb-<?php echo esc_attr($field_key); ?>" data-preview="scsb-preview-<?php echo esc_attr($field_key); ?>">Clear</button>
                                <div style="margin-top:8px;">
                                    <img id="scsb-preview-<?php echo esc_attr($field_key); ?>" src="<?php echo esc_url($img_url); ?>" alt="" style="max-width:160px;height:auto;<?php echo $img_url ? '' : 'display:none;'; ?>" />
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <?php submit_button('Save Settings'); ?>
            </form>
            <script>
            (function () {
                function bindSelectButton(button) {
                    if (button.dataset.bound === '1') {
                        return;
                    }
                    button.dataset.bound = '1';

                    button.addEventListener('click', function () {
                        var targetId = button.getAttribute('data-target');
                        var hiddenInput = document.getElementById(targetId);
                        var preview = document.getElementById('scsb-preview-' + targetId.replace('scsb-', ''));
                        if (!hiddenInput || typeof wp === 'undefined' || !wp.media) {
                            return;
                        }

                        var frame = wp.media({
                            title: 'Select Chatbot Image',
                            button: { text: 'Use this image' },
                            multiple: false
                        });

                        frame.on('select', function () {
                            var attachment = frame.state().get('selection').first().toJSON();
                            hiddenInput.value = attachment.id || '';
                            if (preview && attachment.url) {
                                preview.src = attachment.url;
                                preview.style.display = '';
                            }
                        });

                        frame.open();
                    });
                }

                function bindClearButton(button) {
                    if (button.dataset.bound === '1') {
                        return;
                    }
                    button.dataset.bound = '1';

                    button.addEventListener('click', function () {
                        var targetId = button.getAttribute('data-target');
                        var previewId = button.getAttribute('data-preview');
                        var hiddenInput = document.getElementById(targetId);
                        var preview = document.getElementById(previewId);
                        if (hiddenInput) {
                            hiddenInput.value = '';
                        }
                        if (preview) {
                            preview.src = '';
                            preview.style.display = 'none';
                        }
                    });
                }

                function tryInit(attempt) {
                    var selectButtons = document.querySelectorAll('.scsb-select-image-btn');
                    var clearButtons = document.querySelectorAll('.scsb-clear-image-btn');

                    if ((typeof wp === 'undefined' || !wp.media) && attempt < 20) {
                        setTimeout(function () {
                            tryInit(attempt + 1);
                        }, 150);
                        return;
                    }

                    selectButtons.forEach(bindSelectButton);
                    clearButtons.forEach(bindClearButton);
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', function () {
                        tryInit(0);
                    });
                } else {
                    tryInit(0);
                }
            })();
            </script>
        </div>
        <?php
    }

    public function render_lessons_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $this->maybe_handle_lessons_action();
        $lessons = $this->get_lessons_from_option();
        $editing_id = isset($_GET['edit']) ? sanitize_key(wp_unslash($_GET['edit'])) : '';
        $editing = null;
        foreach ($lessons as $lesson) {
            if (isset($lesson['id']) && $lesson['id'] === $editing_id) {
                $editing = $lesson;
                break;
            }
        }
        ?>
        <div class="wrap">
            <h1>Lessons</h1>
            <?php settings_errors('scsb_lessons'); ?>
            <h2><?php echo $editing ? 'Edit Lesson' : 'Add Lesson'; ?></h2>
            <form method="post">
                <?php wp_nonce_field('scsb_lessons_action', 'scsb_lessons_nonce'); ?>
                <input type="hidden" name="scsb_lessons_action" value="save" />
                <input type="hidden" name="lesson_id" value="<?php echo esc_attr($editing ? $editing['id'] : ''); ?>" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="scsb-subject">Subject</label></th>
                        <td><input id="scsb-subject" name="subject" type="text" class="regular-text" value="<?php echo esc_attr($editing ? $editing['subject'] : ''); ?>" placeholder="Biology, Chemistry, Physics..." /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="scsb-lesson-name">Lesson Name</label></th>
                        <td><input id="scsb-lesson-name" name="lesson_name" type="text" class="regular-text" value="<?php echo esc_attr($editing ? $editing['lesson_name'] : ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="scsb-objectives">Objectives</label></th>
                        <td>
                            <textarea id="scsb-objectives" name="objectives" rows="6" class="large-text" placeholder="One objective per line"><?php echo esc_textarea($editing ? implode("\n", $editing['objectives']) : ''); ?></textarea>
                        </td>
                    </tr>
                </table>
                <?php submit_button($editing ? 'Update Lesson' : 'Add Lesson'); ?>
            </form>

            <h2 style="margin-top:24px;">Current Lessons</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Subject</th>
                        <th>Lesson</th>
                        <th>Objectives</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($lessons)) : ?>
                    <tr><td colspan="4">No lessons created yet.</td></tr>
                <?php else : ?>
                    <?php foreach ($lessons as $lesson) : ?>
                        <tr>
                            <td><?php echo esc_html($lesson['subject']); ?></td>
                            <td><?php echo esc_html($lesson['lesson_name']); ?></td>
                            <td><?php echo esc_html(implode('; ', $lesson['objectives'])); ?></td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=scsb-lessons&edit=' . rawurlencode($lesson['id']))); ?>">Edit</a>
                                <form method="post" style="display:inline-block; margin-left:8px;">
                                    <?php wp_nonce_field('scsb_lessons_action', 'scsb_lessons_nonce'); ?>
                                    <input type="hidden" name="scsb_lessons_action" value="delete" />
                                    <input type="hidden" name="lesson_id" value="<?php echo esc_attr($lesson['id']); ?>" />
                                    <button type="submit" class="button-link-delete" onclick="return confirm('Delete this lesson?');">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function render_student_coins_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $this->maybe_handle_student_coins_action();
        $subjects = $this->get_subjects();

        $users = get_users(array(
            'number' => 500,
            'orderby' => 'display_name',
            'order' => 'ASC',
            'fields' => array('ID', 'display_name', 'user_email'),
        ));
        ?>
        <div class="wrap">
            <h1>Student Coins</h1>
            <?php settings_errors('scsb_coins'); ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Email</th>
                        <th>Current Balance</th>
                        <th>Coin Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($users)) : ?>
                    <tr><td colspan="4">No users found.</td></tr>
                <?php else : ?>
                    <?php foreach ($users as $user) : ?>
                        <?php $balance = $this->get_user_coin_balance((int) $user->ID); ?>
                        <tr>
                            <td><?php echo esc_html($user->display_name); ?></td>
                            <td><?php echo esc_html($user->user_email); ?></td>
                            <td><strong><?php echo esc_html((string) $balance); ?></strong></td>
                            <td>
                                <form method="post" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:8px;">
                                    <?php wp_nonce_field('scsb_coins_action', 'scsb_coins_nonce'); ?>
                                    <input type="hidden" name="scsb_coins_action" value="adjust" />
                                    <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $user->ID); ?>" />
                                    <input type="number" name="amount" min="1" step="1" value="10" style="width:90px;" />
                                    <select name="adjust_subject" required>
                                        <option value="">Subject...</option>
                                        <?php foreach ($subjects as $subject_name) : ?>
                                            <option value="<?php echo esc_attr($subject_name); ?>"><?php echo esc_html($subject_name); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" name="operation" value="add" class="button button-primary">Add</button>
                                    <button type="submit" name="operation" value="remove" class="button">Remove</button>
                                </form>
                                <form method="post" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                    <?php wp_nonce_field('scsb_coins_action', 'scsb_coins_nonce'); ?>
                                    <input type="hidden" name="scsb_coins_action" value="clear_rewards" />
                                    <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $user->ID); ?>" />
                                    <select name="clear_subject">
                                        <option value="">All subjects</option>
                                        <?php foreach ($subjects as $subject_name) : ?>
                                            <option value="<?php echo esc_attr($subject_name); ?>"><?php echo esc_html($subject_name); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="button button-secondary" onclick="return confirm('Clear earned records for this student?');">Clear Earned Records</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function render_progress_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $this->maybe_handle_progress_delete_action();

        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY created_at DESC LIMIT 200");
        ?>
        <div class="wrap">
            <h1>4) Student Progress Dashboard</h1>
            <?php settings_errors('scsb_progress'); ?>
            <form method="post">
                <?php wp_nonce_field('scsb_delete_progress', 'scsb_delete_nonce'); ?>
                <input type="hidden" name="scsb_action" value="delete_progress" />
                <p>
                    <button type="submit" class="button button-secondary" onclick="return confirm('Delete selected progress rows?');">Delete Selected</button>
                </p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="scsb-select-all" /></th>
                        <th>Time</th>
                        <th>Student</th>
                        <th>Lesson</th>
                        <th>Objective Met</th>
                        <th>Tasks</th>
                        <th>Token</th>
                        <th>Coins</th>
                        <th>Balance</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)) : ?>
                    <tr><td colspan="9">No progress records yet.</td></tr>
                <?php else : ?>
                    <?php foreach ($rows as $row) : ?>
                        <?php $tasks = maybe_unserialize($row->tasks_text); ?>
                        <?php $conversation = json_decode((string) $row->conversation, true); ?>
                        <?php $coins_awarded = is_array($conversation) && isset($conversation['coins_awarded']) ? (int) $conversation['coins_awarded'] : 0; ?>
                        <?php $coin_balance = is_array($conversation) && isset($conversation['coin_balance']) ? (int) $conversation['coin_balance'] : 0; ?>
                        <tr>
                            <td><input type="checkbox" name="progress_ids[]" value="<?php echo esc_attr((string) $row->id); ?>" /></td>
                            <td><?php echo esc_html($row->created_at); ?></td>
                            <td><?php echo esc_html($row->student_id); ?></td>
                            <td><?php echo esc_html($row->lesson_slug); ?></td>
                            <td><?php echo $row->objective_met ? 'Yes' : 'No'; ?></td>
                            <td><?php echo esc_html(is_array($tasks) ? implode('; ', $tasks) : ''); ?></td>
                            <td><?php echo esc_html($row->token ?: '-'); ?></td>
                            <td><?php echo esc_html((string) $coins_awarded); ?></td>
                            <td><?php echo esc_html((string) $coin_balance); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            </form>
            <script>
            (function () {
                var selectAll = document.getElementById('scsb-select-all');
                if (!selectAll) {
                    return;
                }
                selectAll.addEventListener('change', function () {
                    var boxes = document.querySelectorAll('input[name="progress_ids[]"]');
                    boxes.forEach(function (box) {
                        box.checked = selectAll.checked;
                    });
                });
            })();
            </script>
        </div>
        <?php
    }
}

new Science_Class_Sidebar_Chatbot();
