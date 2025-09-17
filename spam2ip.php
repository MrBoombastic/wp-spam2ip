<?php
/**
 * Plugin Name:          Spam2IP
 * Description:          Export spam comments to a file, extract IPs
 * Version:              1.0.0
 * Author:               MrBoombastic
 * License:              MIT
 * Requires at least:    6.7.1
 * Requires PHP:         8.2
 * Plugin URI:           https://github.com/MrBoombastic/wp-spam2ip
 * Author URI:           https://amroz.xyz
 * License:              MIT
 * License URI:          https://choosealicense.com/licenses/mit/
 */

if (!defined('ABSPATH')) {
    exit;
}

const SPAM2IP_VERSION = '1.0.0';
define('SPAM2IP_PATH', plugin_dir_path(__FILE__));
define('SPAM2IP_URL', plugin_dir_url(__FILE__));

function spam2ip_menu()
{
    add_submenu_page(
            'edit-comments.php',
            'Spam2IP - IP Export',
            'Spam2IP',
            'manage_options',
            'spam2ip',
            'spam2ip_page'
    );
}

add_action('admin_menu', 'spam2ip_menu');

function spam2ip_settings_link($links)
{
    $settings_link = '<a href="edit-comments.php?page=spam2ip">Settings</a>';
    array_push($links, $settings_link);
    return $links;
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'spam2ip_settings_link');

function spam2ip_page()
{
    $spam_count = get_comments(array('status' => 'spam', 'count' => true));
    ?>
    <div class="wrap">
        <h1>Spam2IP - Export IP addresses from spam comments</h1>
        <div class="card">
            <h2>Stats</h2>
            <p>Found <?php echo $spam_count; ?> comments marked as spam.</p>
            <?php if ($spam_count > 0): ?>
                <form method="post" action="">
                    <?php wp_nonce_field('spam2ip_export', 'spam2ip_nonce'); ?>
                    <p>
                        <label>
                            <input type="checkbox" name="remove_duplicates" value="1" checked>
                            Remove duplicated IP addresses
                        </label>
                    </p>
                    <p>
                        <label>
                            <input type="radio" name="format" value="txt" checked>
                            TXT format (plain list of IPs)
                        </label>
                    </p>
                    <p>
                        <label>
                            <input type="radio" name="format" value="csv">
                            CSV format (IP, Author, Email, Date, Content, User-Agent)
                        </label>
                    </p>
                    <p>
                        <label>
                            <input type="checkbox" name="include_date" value="1" checked>
                            Include the current date in the filename
                        </label>
                    </p>
                    <p>
                        <input type="submit" name="export_ips" class="button button-primary"
                               value="Export IP addresses">
                    </p>
                </form>
            <?php else: ?>
                <p>No comments marked as spam</p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

function spam2ip_init()
{
    if (isset($_POST['export_ips']) && isset($_POST['spam2ip_nonce']) && check_admin_referer('spam2ip_export', 'spam2ip_nonce')) {
        spam2ip_process();
    }
}

add_action('init', 'spam2ip_init');

function spam2ip_process()
{
    if (!is_admin() || !current_user_can('manage_options')) {
        return;
    }

    $spam_comments = get_comments(array('status' => 'spam', 'number' => 0));

    if (empty($spam_comments)) {
        wp_die('Can\'t find any comments marked as spam', 'Spam2IP - error', array('back_link' => true));
    }

    $ip_addresses = array();
    $ip_data = array();

    foreach ($spam_comments as $comment) {
        $ip = $comment->comment_author_IP;

        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip_addresses[] = $ip;

            $user_agent = $comment->comment_agent;

            if (empty($user_agent)) {
                $user_agent = 'Unknown';
            }

            $ip_data[] = array(
                    'ip' => $ip,
                    'author' => sanitize_text_field($comment->comment_author),
                    'email' => sanitize_email($comment->comment_author_email),
                    'date' => $comment->comment_date,
                    'content' => strip_tags($comment->comment_content),
                    'user_agent' => sanitize_text_field($user_agent)
            );
        }
    }

    if (isset($_POST['remove_duplicates'])) {
        $ip_addresses = array_unique($ip_addresses);
        $unique_ips = array();
        $unique_data = array();

        foreach ($ip_data as $data) {
            if (!in_array($data['ip'], $unique_ips)) {
                $unique_ips[] = $data['ip'];
                $unique_data[] = $data;
            }
        }

        $ip_data = $unique_data;
    }

    $format = isset($_POST['format']) ? sanitize_text_field($_POST['format']) : 'txt';
    $filename = 'spam2ip';

    if (isset($_POST['include_date'])) {
        $filename .= '_' . date('Y-m-d');
    }

    $filename .= '.' . $format;

    if (ob_get_level()) {
        ob_clean();
    }

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    if ($format === 'csv') {
        echo "IP,Author,Email,Date,Content,User-Agent\n";

        foreach ($ip_data as $data) {
            $escaped_ip = '"' . str_replace('"', '""', $data['ip']) . '"';
            $escaped_author = '"' . str_replace('"', '""', $data['author']) . '"';
            $escaped_email = '"' . str_replace('"', '""', $data['email']) . '"';
            $escaped_date = '"' . str_replace('"', '""', $data['date']) . '"';
            $escaped_content = '"' . str_replace('"', '""', $data['content']) . '"';
            $escaped_user_agent = '"' . str_replace('"', '""', $data['user_agent']) . '"';

            echo $escaped_ip . ',' .
                    $escaped_author . ',' .
                    $escaped_email . ',' .
                    $escaped_date . ',' .
                    $escaped_content . ',' .
                    $escaped_user_agent . "\n";
        }
    } else {
        echo implode("\n", $ip_addresses);
    }

    exit;
}

function spam2ip_dashboard_widget()
{
    wp_add_dashboard_widget(
            'spam2ip_dashboard_widget',
            'Spam2IP - Stats',
            'spam2ip_dashboard_display'
    );
}

add_action('wp_dashboard_setup', 'spam2ip_dashboard_widget');

function spam2ip_dashboard_display()
{
    $spam_count = get_comments(array('status' => 'spam', 'count' => true));

    echo '<p>Spam count: <strong>' . $spam_count . '</strong></p>';

    if ($spam_count > 0) {
        echo '<p><a href="edit-comments.php?page=spam2ip" class="button button-primary">Export IP addresses</a></p>';
    }
}
