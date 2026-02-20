<?php
if (!defined('ABSPATH')) {
    exit;
}

class SCSB_Chat_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct(
            'scsb_chat_widget',
            'Science Class Chatbot',
            array('description' => 'Student lesson chatbot for science class')
        );
    }

    public function widget($args, $instance) {
        echo $args['before_widget'];
        echo $args['before_title'] . esc_html('Science Lesson Coach') . $args['after_title'];

        if (!is_user_logged_in()) {
            $login_url = wp_login_url(get_permalink());
            $settings = get_option('scsb_settings', array());
            $idle_image_id = isset($settings['image_idle_id']) ? absint($settings['image_idle_id']) : 0;
            $idle_image_url = $idle_image_id > 0 ? wp_get_attachment_image_url($idle_image_id, 'medium') : '';
            ?>
            <div class="scsb-chat-wrap">
                <p>Please log in to use the lesson coach.</p>
                <?php if ($idle_image_url) : ?>
                    <div class="scsb-state-photo-wrap">
                        <img class="scsb-state-photo" src="<?php echo esc_url($idle_image_url); ?>" alt="Lesson coach idle state" />
                    </div>
                <?php endif; ?>
                <p><a href="<?php echo esc_url($login_url); ?>">Log in</a></p>
            </div>
            <?php
            echo $args['after_widget'];
            return;
        }

        $user = wp_get_current_user();
        ?>
        <div class="scsb-chat-wrap">
            <label>Student</label>
            <input id="scsb-student-name" class="scsb-input" type="text" value="<?php echo esc_attr($user->display_name); ?>" readonly />

            <label for="scsb-subject-select">Subject</label>
            <select id="scsb-subject-select" class="scsb-input"></select>

            <label for="scsb-lesson-select">Lesson</label>
            <select id="scsb-lesson-select" class="scsb-input"></select>
            <div id="scsb-objective-count" class="scsb-objective-count" aria-live="polite"></div>

            <div id="scsb-chat-log" class="scsb-chat-log" aria-live="polite"></div>

            <div class="scsb-compose">
                <textarea id="scsb-chat-input" class="scsb-input" rows="3" placeholder="Teach me what you learned in this lesson, or ask me a question."></textarea>
                <button id="scsb-send-btn" class="scsb-send-btn" type="button">Send</button>
            </div>
            <div class="scsb-state-photo-wrap">
                <img id="scsb-state-photo" class="scsb-state-photo" src="" alt="Chatbot status" style="display:none;" />
                <div id="scsb-coins-earned" class="scsb-coins-earned" aria-live="polite"></div>
            </div>

            <div id="scsb-result" class="scsb-result"></div>
        </div>
        <div class="scsb-leaderboard-controls">
            <label for="scsb-leaderboard-subject">Leaderboard Subject</label>
            <select id="scsb-leaderboard-subject" class="scsb-input">
                <option value="">Overall</option>
            </select>
        </div>
        <div id="scsb-leaderboard-container" data-limit="10">
            <?php echo do_shortcode('[scsb_leaderboard limit="10"]'); ?>
        </div>
        <?php
        echo $args['after_widget'];
    }

    public function form($instance) {
        echo '<p>This widget has no additional options.</p>';
    }
}
