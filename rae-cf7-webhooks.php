<?php

/**
 * Plugin Name:       RAE CF7 Webhooks
 * Description:        Fans out Contact Form 7 submissions to any number of webhooks. Each webhook is tagged Zapier (raw JSON) or Slack (formatted message). Configure under Contact → Webhooks. Toggle on/off from the Plugins screen.
 * Version:           0.2
 * Author: Rae Agency (Jere Paajanen)
 * Author URI: https://www.rae.fi
 * License:           GPL-2.0-or-later
 * Requires Plugins:  contact-form-7
 */

if (!defined('ABSPATH')) {
    exit;
}

class Rae_CF7_Webhooks
{

    const OPT_HOOKS = 'rae_cf7_webhooks';
    const OPT_LOG   = 'rae_cf7_webhooks_log';
    const GROUP     = 'rae_cf7_webhooks';
    const CRON_HOOK = 'rae_cf7_webhooks_send';
    const LOG_MAX   = 10;

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wpcf7_mail_sent', [$this, 'queue_send']);
        add_action(self::CRON_HOOK, [$this, 'do_send'], 10, 6);
        register_deactivation_hook(__FILE__, [__CLASS__, 'deactivate']);
    }

    /* --- Cleanup on deactivate: drop pending sends + stored options --- */

    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        delete_option(self::OPT_HOOKS);
        delete_option(self::OPT_LOG);
    }

    /* --- Admin menu (under CF7's "Contact" menu) --- */

    public function add_menu()
    {
        add_submenu_page(
            'wpcf7',                        // parent slug = CF7's Contact menu
            'CF7 → Webhooks',               // page title
            'Webhooks',                     // menu label
            'wpcf7_edit_contact_forms',     // same cap CF7 uses
            'rae-cf7-webhooks',
            [$this, 'render_page']
        );
    }

    public function register_settings()
    {
        register_setting(self::GROUP, self::OPT_HOOKS, [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_hooks'],
            'default'           => [],
        ]);
    }

    public function sanitize_hooks($input)
    {
        $out = [];
        if (!is_array($input)) {
            return $out;
        }
        foreach ($input as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = isset($row['url']) ? esc_url_raw(trim($row['url'])) : '';
            if ($url === '') {
                continue; // drop empty rows
            }
            $type = (isset($row['type']) && $row['type'] === 'slack') ? 'slack' : 'zapier';
            $out[] = [
                'type'        => $type,
                'url'         => $url,
                'form_id'     => isset($row['form_id']) ? absint($row['form_id']) : 0,
                'label'       => isset($row['label']) ? sanitize_text_field($row['label']) : '',
                'show_labels' => empty($row['show_labels']) ? 0 : 1,
            ];
        }
        return array_values($out);
    }

    public function render_page()
    {
        if (!current_user_can('wpcf7_edit_contact_forms') && !current_user_can('manage_options')) {
            return;
        }
        $hooks = get_option(self::OPT_HOOKS, []);
        if (!is_array($hooks) || empty($hooks)) {
            $hooks = [['type' => 'zapier', 'url' => '', 'form_id' => 0, 'label' => '']];
        }
        $forms = $this->get_cf7_forms();
        $log   = get_option(self::OPT_LOG, []);
?>
        <div class="wrap">
            <h1>Contact Form 7 &rarr; Webhooks</h1>
            <p>Posts CF7 submissions to one or more webhooks. Tag each as
                <strong>Zapier</strong> (raw JSON to a Zapier Catch Hook) or
                <strong>Slack</strong> (formatted message to a Slack Incoming Webhook URL).
                Sends run in the background, so the form never waits on the network.</p>

            <form method="post" action="options.php">
                <?php settings_fields(self::GROUP); ?>
                <table class="widefat striped" id="rae-hooks" style="max-width:960px">
                    <thead>
                        <tr>
                            <th style="width:110px">Type</th>
                            <th>URL</th>
                            <th style="width:200px">Limit to form</th>
                            <th style="width:160px">Label</th>
                            <th style="width:40px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($hooks as $i => $row) : ?>
                            <?php echo $this->row_html($i, $row, $forms); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button" id="rae-add-hook">+ Add webhook</button></p>
                <?php submit_button('Save'); ?>
            </form>

            <template id="rae-hook-template">
                <?php echo $this->row_html('__INDEX__', ['type' => 'zapier', 'url' => '', 'form_id' => 0, 'label' => ''], $forms); ?>
            </template>

            <script>
                (function() {
                    var tbody = document.querySelector('#rae-hooks tbody');
                    var tpl = document.getElementById('rae-hook-template').innerHTML;
                    var next = <?php echo (int) count($hooks); ?>;

                    document.getElementById('rae-add-hook').addEventListener('click', function() {
                        var html = tpl.replace(/__INDEX__/g, next++);
                        var tr = document.createElement('tbody');
                        tr.innerHTML = html.trim();
                        tbody.appendChild(tr.firstChild);
                    });

                    tbody.addEventListener('click', function(e) {
                        if (e.target.classList.contains('rae-remove-hook')) {
                            var rows = tbody.querySelectorAll('tr');
                            if (rows.length > 1) {
                                e.target.closest('tr').remove();
                            } else {
                                e.target.closest('tr').querySelectorAll('input').forEach(function(inp) {
                                    inp.value = '';
                                });
                            }
                        }
                    });
                })();
            </script>

            <h2>Recent deliveries</h2>
            <?php if (empty($log)) : ?>
                <p>No submissions sent yet. Submit a test entry to populate this.</p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:760px">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Form</th>
                            <th>Destination</th>
                            <th>Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($log as $row) : ?>
                            <tr>
                                <td><?php echo esc_html($row['time']); ?></td>
                                <td><?php echo esc_html($row['form']); ?></td>
                                <td><?php echo esc_html($row['dest'] ?? '-'); ?></td>
                                <td>
                                    <?php if (!empty($row['ok'])) : ?>
                                        <span style="color:#138a36;font-weight:600;">&#10003; <?php echo esc_html($row['status']); ?></span>
                                    <?php else : ?>
                                        <span style="color:#b32d2e;font-weight:600;">&#10007; <?php echo esc_html($row['status']); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
<?php
    }

    private function row_html($i, $row, $forms)
    {
        $name   = self::OPT_HOOKS . '[' . $i . ']';
        $type   = $row['type'] ?? 'zapier';
        $url    = $row['url'] ?? '';
        $fid    = (int) ($row['form_id'] ?? 0);
        $lbl    = $row['label'] ?? '';
        $labels = !isset($row['show_labels']) || !empty($row['show_labels']); // default on
        ob_start();
?>
        <tr>
            <td>
                <select name="<?php echo esc_attr($name); ?>[type]">
                    <option value="zapier" <?php selected($type, 'zapier'); ?>>Zapier</option>
                    <option value="slack" <?php selected($type, 'slack'); ?>>Slack</option>
                </select>
            </td>
            <td>
                <input name="<?php echo esc_attr($name); ?>[url]" type="url" class="large-text"
                    value="<?php echo esc_attr($url); ?>"
                    placeholder="https://hooks.zapier.com/... or https://hooks.slack.com/services/...">
            </td>
            <td>
                <select name="<?php echo esc_attr($name); ?>[form_id]">
                    <option value="0" <?php selected($fid, 0); ?>>All forms</option>
                    <?php foreach ($forms as $id => $title) : ?>
                        <option value="<?php echo esc_attr($id); ?>" <?php selected($fid, $id); ?>>
                            <?php echo esc_html($title . ' (#' . $id . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <input name="<?php echo esc_attr($name); ?>[label]" type="text" class="regular-text"
                    value="<?php echo esc_attr($lbl); ?>" placeholder="optional">
                <label style="display:block;margin-top:4px;font-size:12px;">
                    <input name="<?php echo esc_attr($name); ?>[show_labels]" type="checkbox"
                        value="1" <?php checked($labels); ?>>
                    Show field names <span style="color:#888;">(Slack)</span>
                </label>
            </td>
            <td><button type="button" class="button-link rae-remove-hook" title="Remove">&times;</button></td>
        </tr>
    <?php
        return ob_get_clean();
    }

    private function get_cf7_forms()
    {
        $out = [];
        if (class_exists('WPCF7_ContactForm')) {
            foreach (WPCF7_ContactForm::find() as $form) {
                $out[$form->id()] = $form->title();
            }
        }
        return $out;
    }

    /* --- Queue: runs on submission, schedules one send per matching webhook --- */

    public function queue_send($contact_form)
    {
        $hooks = get_option(self::OPT_HOOKS, []);
        if (!is_array($hooks) || empty($hooks)) {
            return;
        }

        $submission = WPCF7_Submission::get_instance();
        if (!$submission) {
            return;
        }

        $data = $submission->get_posted_data();
        foreach (array_keys($data) as $key) {
            if (strpos($key, '_wpcf7') === 0) {
                unset($data[$key]);
            }
        }

        $payload = [
            'form_id'      => $contact_form->id(),
            'form_title'   => $contact_form->title(),
            'site'         => home_url(),
            'submitted_at' => current_time('c'),
            'fields'       => $data,
        ];

        $form_id = (int) $contact_form->id();
        foreach ($hooks as $i => $hook) {
            if (empty($hook['url'])) {
                continue;
            }
            $limit = (int) ($hook['form_id'] ?? 0);
            if ($limit && $limit !== $form_id) {
                continue;
            }
            $type   = ($hook['type'] ?? 'zapier') === 'slack' ? 'slack' : 'zapier';
            $label  = $hook['label'] ?? '';
            $labels = empty($hook['show_labels']) ? 0 : 1;
            // Hand off to background cron so the visitor's response isn't delayed.
            // $i is passed so two rows with identical type/url/label/show_labels
            // produce distinct args — otherwise wp_schedule_single_event drops the
            // second as a duplicate event due within 10 minutes, and that webhook
            // never fires.
            wp_schedule_single_event(time(), self::CRON_HOOK, [$type, $hook['url'], $payload, $label, $labels, $i]);
        }
    }

    /* --- Background worker: format by type, blocking POST + log result --- */

    public function do_send($type, $url, $payload, $label = '', $show_labels = 1, $row = 0)
    {
        // $row only disambiguates the cron args (see queue_send); unused here.
        if ($type === 'slack') {
            $body = wp_json_encode(['text' => $this->slack_text($payload, $show_labels)]);
        } else {
            $body = wp_json_encode($payload);
        }

        $response = wp_remote_post($url, [
            'body'     => $body,
            'headers'  => ['Content-Type' => 'application/json'],
            'timeout'  => 15,
            'blocking' => true,
        ]);

        if (is_wp_error($response)) {
            $ok     = false;
            $status = $response->get_error_message();
        } else {
            $code   = (int) wp_remote_retrieve_response_code($response);
            $ok     = ($code >= 200 && $code < 300);
            $status = 'HTTP ' . $code;
        }

        $this->log_entry([
            'time'   => current_time('Y-m-d H:i'),
            'form'   => $payload['form_title'] ?? '-',
            'dest'   => $label !== '' ? $label : $type,
            'ok'     => $ok,
            'status' => $status,
        ]);
    }

    private function slack_text($payload, $show_labels = 1)
    {
        $lines   = [];
        $lines[] = '*New submission: ' . ($payload['form_title'] ?? 'Untitled') . '* (' . ($payload['site'] ?? '') . ')';
        foreach (($payload['fields'] ?? []) as $key => $value) {
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $lines[] = $show_labels ? $key . ': ' . $value : $value;
        }
        return implode("\n", $lines);
    }

    private function log_entry($entry)
    {
        $log = get_option(self::OPT_LOG, []);
        array_unshift($log, $entry);
        $log = array_slice($log, 0, self::LOG_MAX);
        update_option(self::OPT_LOG, $log, false);
    }
}

new Rae_CF7_Webhooks();
