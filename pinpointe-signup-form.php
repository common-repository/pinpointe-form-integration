<?php
/**
 * Plugin Name: Pinpointe Form Integration
 * Plugin URI: https://help.pinpointe.com/support/solutions/articles/5000664320-wordpress-plugin-download
 * Description: Add Pinpointe forms to your WordPress site
 * Version: 1.7
 * Author: Pinpointe
 * Author URI: http://www.pinpointe.com/
 * Requires at least: 3.5
 * Tested up to: 6.5.2
 *
 * Text Domain: pinpointe
 * Domain Path: /languages
 * 
 * License:     GPL-3.0+
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package PinpointeSignupForm
 * @category Core
 * @author Pinpointe
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

session_start(); // Start the session first

// Define Constants
define('PINPOINTE_PLUGIN_PATH', untrailingslashit(plugin_dir_path(__FILE__)));
define('PINPOINTE_PLUGIN_URL', plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__)));
define('PINPOINTE_VERSION', '2.2.1'); // Increment this to force cached JS to reload

if (!class_exists('PinpointeSignupForm')) {

    /**
     * Main plugin class
     *
     * @package PinpointeSignupForm
     * @author RightPress
     */
    class PinpointeSignupForm
    {
        private static $instance = false;
        private $last_rendered_form = 0;
        private $popup_page_capping_in_effect = false;
        
        private $register_scripts_args = [
            // determines where the script is loaded(header/footer)
            'in_footer' => true // true for footer, false for header
        ];


        //define dynamically assigned vars
        public $settings;
        public $hints;
        public $validation;
        public $titles;
        public $options;
        public $section_info;
        public $default_tabs;
        public $opt;
        public $pinpointe;

        /**
         * Singleton control
         */
        public static function get_instance()
        {
            if (!self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Class constructor
         *
         * @access public
         * @return void
         */
        public function __construct()
        {
            $this->pinpointe = null;

            // Load translation
            load_plugin_textdomain('pinpointe', false, dirname(plugin_basename(__FILE__)) . '/languages/');

            // Load classes/includes
            require_once PINPOINTE_PLUGIN_PATH . '/includes/pinpointe-plugin-structure.inc.php';
            require_once PINPOINTE_PLUGIN_PATH . '/includes/pinpointe-form.inc.php';
            require_once PINPOINTE_PLUGIN_PATH . '/includes/pinpointe-widget.class.php';

            // Load configuration and current settings
            $this->get_config();
            $this->opt = $this->get_options();

            /**
             * For admin only
             */
            if (is_admin()) {

                if (!(defined('DOING_AJAX') && DOING_AJAX)) {
                    // General plugin setup
                    add_action('admin_menu', array($this, 'add_admin_menu'));
                    add_action('admin_init', array($this, 'admin_construct'));
                    add_filter('plugin_action_links', array($this, 'plugin_settings_link'), 10, 2);

                    // Load scripts/styles conditionally
                    $query_string = htmlspecialchars($_SERVER['QUERY_STRING'], ENT_QUOTES);
                    if (isset($query_string) && preg_match('/page=pinpointe/i', $query_string) && !preg_match('/page=pinpointe_lite/i', $query_string)) {
                        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts_and_styles'));
                     }
                }

                // Ajax handlers
                add_action('wp_ajax_pinpointe_status', array($this, 'ajax_pinpointe_status'));
                add_action('wp_ajax_pinpointe_get_lists', array($this, 'ajax_get_lists'));
                add_action('wp_ajax_pinpointe_get_lists_with_multiple_groups_and_fields', array($this, 'ajax_get_lists_groups_fields'));
                add_action('wp_ajax_pinpointe_update_groups_and_tags', array($this, 'ajax_groups_and_tags_in_array'));
                add_action('wp_ajax_pinpointe_get_tags', array($this, 'ajax_get_tags'));
                add_action('wp_ajax_pinpointe_get_tags_with_multiple_groups_and_fields', array($this, 'ajax_get_tags_groups_fields'));
            }

            /**
             * For frontend only
             */
            else {

                if (!(defined('DOING_AJAX') && DOING_AJAX)) {

                    // Hooks
                    add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts_and_styles'));
                    add_action('wp_footer', array($this, 'display_popup'));
                    add_filter('the_content', array($this, 'form_after_content'));
                    add_filter('the_content', array($this, 'content_lock'), 99);

                    // Add checkboxes
                    add_action('register_form', array($this, 'checkbox_render_registration'), 99);
                    add_action('comment_form', array($this,'checkbox_render_comment'), 99);

                    // Subscribe after checkbox checked
                    add_action('user_register', array($this, 'checkbox_subscribe_registration'), 99, 1);
                    add_action('comment_post', array($this, 'checkbox_subscribe_comments'), 99, 2);

                    // Track page hits for popup frequency capping
                    if ($this->opt['pinpointe_popup_page_limit'] > 1) {
                        $this->track_page_hits();
                    }
                }
            }

            /**
             * For all
             */


            add_action('widgets_init', function () {
                return register_widget("Pinpointe_Widget");
            });

            add_shortcode('pinpointe_form', array($this, 'subscription_shortcode'));
            add_shortcode('pinpointe_powered_by', array($this, 'powered_by_shortcode'));
            add_shortcode('pinpointe_email_sent_to', array($this, 'email_sent_to_shortcode'));
            add_shortcode('pinpointe_confirm_link', array($this, 'confirm_link_shortcode'));
            add_shortcode('pinpointe_confirm_link_href', array($this, 'confirm_link_href_shortcode'));
            register_uninstall_hook(__FILE__, array('PinpointeSignupForm', 'uninstall'));

            // validate the short codes before we do anything else
            $this->validate_all_shortcodes();

            /**
             * User sync
             */
            add_action('user_register', array($this, 'sync_create'));
            add_action('profile_update', array($this, 'sync_update'), 10, 2);
            add_action('delete_user', array($this, 'sync_delete'));

            /**
             * Ajax handlers
             */
            add_action('wp_ajax_pinpointe_subscribe', array($this, 'ajax_subscribe'));
            add_action('wp_ajax_nopriv_pinpointe_subscribe', array($this, 'ajax_subscribe'));

        }
       
         /**
         * Validate all shortcodes and display warnings for missing ones.
         * 
         * @return void
         */
        public function validate_all_shortcodes()
        {
            /**
             * Shortcodes to check for existence.
             *
             * @var string[]
             */
            $shortcodes_to_check = array(
                'pinpointe_form',
                'pinpointe_powered_by',
                'pinpointe_email_sent_to',
                'pinpointe_confirm_link',
                'pinpointe_confirm_link_href',
            );

            foreach ($shortcodes_to_check as $shortcode){
                if (!shortcode_exists($shortcode)){
                    $warning_message = sprintf(
                        esc_html__('Warning: Missing %s shortcode!', 'pinpointe'),
                        esc_html($shortcode)
                    );

                    add_action('admin_notices', function () use ($warning_message) {
                        echo '<div class="notice notice-error"><p>' . esc_html($warning_message) . '</p></div>';
                    });
                }
            }
        }

        /**
         * Loads (and sets) configuration values from structure file and database
         *
         * @access public
         * @return void
         */
        public function get_config()
        {
            // Settings tree
            $this->settings = pinpointe_plugin_settings();

            // Load some data from config
            $this->hints = $this->options('hint');
            $this->validation = $this->options('validation', true);
            $this->titles = $this->options('title');
            $this->options = $this->options('values');
            $this->section_info = $this->get_section_info();
            $this->default_tabs = $this->get_default_tabs();
        }

        /**
         * Get settings options: default, hint, validation, values
         *
         * @access public
         * @param string $name
         * @param bool $split_by_page
         * @return array
         */
        public function options($name, $split_by_subpage = false)
        {
            $results = array();

            // Iterate over settings array and extract values
            foreach ($this->settings as $page => $page_value) {
                $page_options = array();

                foreach ($page_value['children'] as $subpage => $subpage_value) {
                    foreach ($subpage_value['children'] as $section => $section_value) {
                        foreach ($section_value['children'] as $field => $field_value) {
                            if (isset($field_value[$name])) {
                                $page_options['pinpointe_' . $field] = $field_value[$name];
                            }
                        }
                    }

                    $results[preg_replace('/_/', '-', $subpage)] = $page_options;
                    $page_options = array();
                }
            }

            $final_results = array();

            // Do we need to split results by page?
            if (!$split_by_subpage) {
                foreach ($results as $value) {
                    $final_results = array_merge($final_results, $value);
                }
            }
            else {
                $final_results = $results;
            }

            return $final_results;
        }

        /**
         * Get default tab for each page
         *
         * @access public
         * @return array
         */
        public function get_default_tabs()
        {
            $tabs = array();

            // Iterate over settings array and extract values
            foreach ($this->settings as $page => $page_value) {
                reset($page_value['children']);
                $tabs[$page] = key($page_value['children']);
            }

            return $tabs;
        }

        /**
         * Get array of section info strings
         *
         * @access public
         * @return array
         */
        public function get_section_info()
        {
            $results = array();

            // Iterate over settings array and extract values
            foreach ($this->settings as $page_value) {
                foreach ($page_value['children'] as $subpage => $subpage_value) {
                    foreach ($subpage_value['children'] as $section => $section_value) {
                        if (isset($section_value['info'])) {
                            $results[$section] = $section_value['info'];
                        }
                    }
                }
            }

            return $results;
        }

        /*
         * Get plugin options set by user
         *
         * @access public
         * @return array
         */
        public function get_options()
        {
            $default_options = array_merge(
                $this->options('default'),
                array(
                    'pinpointe_checkout_fields' => array(),
                    'pinpointe_widget_fields' => array(),
                    'pinpointe_shortcode_fields' => array(),
                )
            );

            return array_merge(
                       $default_options,
                       get_option('pinpointe_options', $this->options('default'))
                   );
        }

        /*
         * Update options
         *
         * @access public
         * @return bool
         */
        public function update_options($args = array())
        {
            return update_option('pinpointe_options', array_merge($this->get_options(), $args));
        }

        /**
         * Add link to admin page
         *
         * @access public
         * @return void
         */
        public function add_admin_menu()
        {
            if (!current_user_can('manage_options')) {
                return;
            }

            add_options_page(
                $this->settings['pinpointe']['page_title'],
                $this->settings['pinpointe']['title'],
                $this->settings['pinpointe']['capability'],
                $this->settings['pinpointe']['slug'],
                array($this, 'set_up_admin_page')
            );
        }

        /*
         * Set up admin page
         *
         * @access public
         * @return void
         */
        public function set_up_admin_page()
        {
            // Check for general warnings
            if (!$this->curl_enabled()) {
                add_settings_error(
                    'error_type',
                    'general',
                    sprintf(__('Warning: PHP cURL extension is not enabled on this server. cURL is required for this plugin to function correctly. You can read more about cURL <a href="%s">here</a>.', 'pinpointe'), 'http://php.net/manual/en/book.curl.php')
                );
            }

            // Print notices
            settings_errors('pinpointe');

            // Print page tabs
            $this->render_tabs();

            // Print page content
            $this->render_page();
        }

        /**
         * Admin interface constructor
         *
         * @access public
         * @return void
         */
        public function admin_construct()
        {
            if (!current_user_can('manage_options')) {
                return;
            }

            // Iterate subpages
            foreach ($this->settings['pinpointe']['children'] as $subpage => $subpage_value) {

                register_setting(
                    'pinpointe_opt_group_' . $subpage,            // Option group
                    'pinpointe_options',                          // Option name
                    array($this, 'options_validate')                  // Sanitize
                );

                // Iterate sections
                foreach ($subpage_value['children'] as $section => $section_value) {

                    add_settings_section(
                        $section,
                        $section_value['title'],
                        array($this, 'render_section_info'),
                        'pinpointe-admin-' . str_replace('_', '-', $subpage)
                    );

                    // Iterate fields
                    foreach ($section_value['children'] as $field => $field_value) {

                        add_settings_field(
                            'pinpointe_' . $field,                                     // ID
                            $field_value['title'],                                      // Title
                            array($this, 'render_options_' . $field_value['type']),     // Callback
                            'pinpointe-admin-' . str_replace('_', '-', $subpage), // Page
                            $section,                                                   // Section
                            array(                                                      // Arguments
                                'name' => 'pinpointe_' . $field,
                                'options' => $this->get_options(),
                            )
                        );

                    }
                }
            }
        }

        /**
         * Render admin page navigation tabs
         *
         * @access public
         * @param string $current_tab
         * @return void
         */
        public function render_tabs()
        {
            // Get current page and current tab
            $current_page = preg_replace('/settings_page_/', '', $this->get_current_page_slug());
            $current_tab = $this->get_current_tab();

            // Output admin page tab navigation
            echo '<div class="pinpointe-container">';
            echo '<div id="icon-pinpointe" class="icon32 icon32-pinpointe"><br></div>';
            echo '<h2 class="nav-tab-wrapper">';
            foreach ($this->settings as $page => $page_value) {
                if ($page != $current_page) {
                    continue;
                }

                foreach ($page_value['children'] as $subpage => $subpage_value) {
                    $class = ($subpage == $current_tab) ? ' nav-tab-active' : '';
					$href = "?page=" . preg_replace('/_/', '-', $page)."&tab=".$subpage;
					$link_value = ((isset($subpage_value['icon']) && !empty($subpage_value['icon']))
							? $subpage_value['icon'] . ($subpage_value['title'] != '' ? '&nbsp;' : '')
							: '') .$subpage_value['title'];
                    echo '<a class="nav-tab'.esc_attr($class).'" href="'. esc_url($href).'">'.wp_kses_post($link_value)
						.'</a>';
                }
            }
            echo '</h2>';
            echo '</div>';
        }

        /**
         * Get current tab (fallback to default)
         *
         * @access public
         * @param bool $is_dash
         * @return string
         */
        public function get_current_tab($is_dash = false)
        {
            $tab = (isset($_GET['tab']) && $this->page_has_tab(sanitize_text_field($_GET['tab']))) ? preg_replace('/-/', '_', sanitize_text_field($_GET['tab'])) : $this->get_default_tab();

            return (!$is_dash) ? $tab : preg_replace('/_/', '-', $tab);
        }

        /**
         * Get default tab
         *
         * @access public
         * @return string
         */
        public function get_default_tab()
        {
            $current_page_slug = $this->get_current_page_slug();
            return $this->default_tabs[$current_page_slug];
        }

        /**
         * Get current page slug
         *
         * @access public
         * @return string
         */
        public function get_current_page_slug()
        {
            $current_screen = get_current_screen();
            $current_page = $current_screen->base;
            $current_page_slug = preg_replace('/settings_page_/', '', $current_page);
            return preg_replace('/-/', '_', $current_page_slug);
        }

        /**
         * Check if current page has requested tab
         *
         * @access public
         * @param string $tab
         * @return bool
         */
        public function page_has_tab($tab)
        {
            $current_page_slug = $this->get_current_page_slug();

            if (isset($this->settings[$current_page_slug]['children'][$tab]))
                return true;

            return false;
        }

        /**
         * Render settings page
         *
         * @access public
         * @param string $page
         * @return void
         */
        public function render_page(){

            $current_tab = $this->get_current_tab(true);
            
            
            ?>
                <div class="wrap pinpointe">
                    <div class="pinpointe-container">
                        <div class="pinpointe-left">
                            <form method="post" action="options.php" enctype="multipart/form-data">
                                <input type="hidden" name="current_tab" value="<?php echo esc_attr($current_tab); ?>" />
                                <?php wp_nonce_field("ajax_pinpointe_status","pinpointe_status");?>
                                <?php wp_nonce_field('ajax_get_lists', 'pinpointe_get_lists');?>

                                <?php
                                    settings_fields('pinpointe_opt_group_'.preg_replace('/-/', '_', $current_tab));
                                    do_settings_sections('pinpointe-admin-' . $current_tab);

	                                submit_button();

                                    if ($current_tab == 'forms') {
                                        echo '<div style="width:100%;text-align:center;border-top:1px solid #dfdfdf;padding:18px;"><a href="' . esc_url(admin_url('/options-general.php?page=pinpointe&tab=help')) . '">' . esc_html__('How do I display my forms?', 'pinpointe') . '</a></div>';
                                    }
                                    else if ($current_tab == 'help') {
                                        echo '<script>jQuery(document).ready(function() { jQuery(\'.submit\').remove(); });</script>';
                                    }
                                    else {
                                        echo '<div></div>';
                                    }

                                ?>

                            </form>
                        </div>
                        <div style="clear: both;"></div>
                    </div>
                </div>
            <?php

                /**
                 * Pass data on selected lists, groups and merge tags
                 */
                if (isset($this->opt['forms']) && is_array($this->opt['forms']) && !empty($this->opt['forms'])) {

                    $pinpointe_selected_lists = array();
                    $pinpointe_selected_tags = array();

                    foreach ($this->opt['forms'] as $form_key => $form) {
                        $pinpointe_selected_lists[$form_key] = array(
                            'list'      => $form['list'],
                            'groups'    => $form['groups'],
                            'merge'     => $form['fields']
                        );

                        $pinpointe_selected_tags[$form_key] = array(
                            'tags'       => $form['tags']
                        );
                    }
                }
                else {
                    $pinpointe_selected_lists = array();
                    $pinpointe_selected_tags = array();
                }

                /**
                 * Pass Forms tab field hints
                 */
                $forms_page_hints = array(
                    'pinpointe_forms_title_field'              => '<p>'. esc_html('Title of the form - will be displayed in a signup form header.', 'pinpointe').'</p>',
                    'pinpointe_forms_above_field'              => '<p>'. esc_html('Message to display above form fields.', 'pinpointe').'</p>',
                    'pinpointe_forms_below_field'              => '<p>'. esc_html('Message to display below form fields.', 'pinpointe').'</p>',
                    'pinpointe_forms_button_field'             => '<p>'. esc_html('Form submit button label.', 'pinpointe').'</p>',
                    'pinpointe_forms_color_scheme'             => '<p>'. esc_html('Select one of the predefined color schemes. You can further customize the look & feel of your forms by adding custom CSS rules to Settings > Override CSS.', 'pinpointe').'</p>',
                    'pinpointe_forms_redirect_url'             => '<p>'. esc_html('Optionaly provide an URL where subscribers should be redirected to after successful signup.', 'pinpointe').'</p><p>' . esc_html('Leave this field empty to simply display a thank you message.', 'pinpointe') . '</p>',
                    'pinpointe_forms_mailing_list'             => '<p>'. esc_html('Select one of your Pinpointe mailing databases for users to be subscribed to.', 'pinpointe').'</p>',
                    'pinpointe_forms_mailing_tag'              => '<p>'. esc_html('Select one of your Pinpointe lists for users to be added to.', 'pinpointe').'</p>',
                    'pinpointe_forms_groups'                   => '<p>'. esc_html('Select interest groups that you want to add automatically or allow users to choose in the form.', 'pinpointe').'</p><p>' .esc_html('If no interest groups are available, either mailing list has not been selected yet in the field above or you have no interest groups created for this list.', 'pinpointe'). '</p>',
                    'pinpointe_form_group_method'              => '<p>'. esc_html('Select how you would like interest groups to work with this form - you can either add all selected interest groups to subscribers profile by default or allow your visitors to manually select some.', 'pinpointe').'</p>',
                    'form_condition_key'                       => '<p>'. esc_html('Controls on which parts of the website this form will appear (or not appear).', 'pinpointe').'</p>',
                    'form_condition_value_pages'               => '<p>'. esc_html('List of pages to check current page against.', 'pinpointe').'</p>',
                    'form_condition_value_posts'               => '<p>'. esc_html('List of posts to check current post against.', 'pinpointe').'</p>',
                    'form_condition_value_post_categories'     => '<p>'. esc_html('List of post categories to check current post against.', 'pinpointe').'</p>',
                    'form_condition_value_url'                 => '<p>'. esc_html('URL fragment to search in the URL of the page.', 'pinpointe').'</p>',
                    'pinpointe_forms_send_confirmation'        => '<p>'. esc_html('Whether or not to send a confirmation email to subscribers when they submit a subscription form. If selected, subscriptions will be recorded, but subscribers will be marked as Unconfirmed until they receive and choose to confirm their subscription via email.', 'pinpointe').'</p>',
                );
            
            // sanitize $pinpointe_selected_lists
            array_walk_recursive($pinpointe_selected_lists, function (&$value, $key) {
                switch ($key) {
                    case 'name':
                    case 'tag':
                    case 'icon':
                    case 'type':
                        $value = sanitize_text_field($value);
                        break;
                    case 'req':
                        $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                        break;
                    case 'us_phone':
                        $value = filter_var($value, FILTER_VALIDATE_BOOL);
                        break;
                    case 'choices':
                        if (is_array($value)) {
                            // Add extra sanitization for 'choices' if necessary
                            $value = array_map(static fn ($choice) => sanitize_text_field($choice), $value);
                        }
                        break;
                    default:
                        // Perform no action for unhandled keys
                        break;
                }
            });
            
            // sanitize $pinpointe_selected_tags
            array_walk_recursive($pinpointe_selected_tags, static function (&$value, $key) {
                if (is_int($key)) {
                    // Do nothing for numerical keys
                    return;
                }
                
                if ($key === 'tags') {
                    $value = is_array($value) ? $value : [$value];
                    $value = array_map('sanitize_key', $value);
                    return;
                }
                
                $value = sanitize_text_field($value);
            });

            // Pass variables to JavaScript
            ?>
                <script>
                    var pinpointe_hints = <?php echo wp_json_encode($this->hints); ?>;
                    var pinpointe_forms_hints = <?php echo wp_json_encode($forms_page_hints); ?>;
                    var pinpointe_home_url = '<?php echo esc_url(site_url()); ?>';
                    var pinpointe_label_still_connecting_to_pinpointe = '<?php esc_html_e('Still connecting to Pinpointe...', 'pinpointe'); ?>';
                    var pinpointe_label_mailing_list = '<?php esc_html_e('Mailing database', 'pinpointe'); ?>';
                    var pinpointe_label_mailing_tag = '<?php esc_html_e('Mailing list', 'pinpointe'); ?>';
                    var pinpointe_label_no_results_match_list = '<?php esc_html_e('There are no databases named', 'pinpointe'); ?>';
                    var pinpointe_label_no_results_match_tag = '<?php esc_html_e('There are no lists named', 'pinpointe'); ?>';
                    var pinpointe_label_select_mailing_list = '<?php esc_html_e('Select a mailing database', 'pinpointe'); ?>';
                    var pinpointe_label_select_mailing_tag = '<?php esc_html_e('Select a mailing list', 'pinpointe');
					?>';
                    var pinpointe_label_no_results_match_groups = '<?php esc_html_e('Selected database does not have groups named', 'pinpointe'); ?>';
                    var pinpointe_label_select_some_groups = '<?php esc_html_e('Select some groups (optional)', 'pinpointe'); ?>';
                    var pinpointe_label_groups = '<?php esc_html_e('Interest groups', 'pinpointe'); ?>';
                    var pinpointe_label_fields_name = '<?php esc_html_e('Field Label', 'pinpointe'); ?>';
                    var pinpointe_label_fields_tag = '<?php esc_html_e('Pinpointe Field', 'pinpointe'); ?>'; //
					// Changed from 'Pinpointe Tag'
                    var pinpointe_label_fields_icon = '<?php esc_html_e('Icon', 'pinpointe'); ?>';
                    var pinpointe_label_add_new = '<?php esc_html_e('Add Field', 'pinpointe'); ?>'; // Changed from
					// 'Add Tag'
                    var pinpointe_label_no_results_match_tags = '<?php esc_html_e('Selected database does not have fields named', 'pinpointe'); ?>';
                    var pinpointe_label_select_tag = '<?php esc_html_e('Select a field', 'pinpointe'); ?>'; //
					// Changed from 'Select a tag'
                    var pinpointe_label_connecting_to_pinpointe = '<?php esc_html_e('Connecting to Pinpointe...', 'pinpointe'); ?>';
                    var pinpointe_label_no_results_match_pages = '<?php esc_html_e('No pages named', 'pinpointe'); ?>';
                    var pinpointe_label_select_some_pages = '<?php esc_html_e('Select some pages', 'pinpointe'); ?>';
                    var pinpointe_label_no_results_match_posts = '<?php esc_html_e('No posts named', 'pinpointe'); ?>';
                    var pinpointe_label_select_some_posts = '<?php esc_html_e('Select some posts', 'pinpointe'); ?>';
                    var pinpointe_label_no_results_match_post_categories = '<?php esc_html_e('No post categories named', 'pinpointe'); ?>';
                    var pinpointe_label_select_some_post_categories = '<?php esc_html_e('Select some post categories', 'pinpointe'); ?>';
                    var pinpointe_label_no_results_match_forms = '<?php esc_html_e('No forms named', 'pinpointe'); ?>';
                    var pinpointe_label_select_some_forms = '<?php esc_html_e('Select forms here', 'pinpointe'); ?>';
                    var pinpointe_label_signup_form_no = '<?php esc_html_e('Signup Form #', 'pinpointe'); ?>';
                    var pinpointe_label_email = '<?php esc_html_e('Email', 'pinpointe'); ?>';
                    var pinpointe_label_button = '<?php esc_html_e('Submit', 'pinpointe'); ?>';
                    var pinpointe_font_awesome_icons = <?php echo wp_json_encode($this->get_font_awesome_icons()); ?>;
                    var pinpointe_label_bad_ajax_response = '<?php printf(esc_html__('%s Response received from your server is malformed.', 'pinpointe'), '<i class="fa-solid fa-xmark" style="font-size: 1.5em; color: red;"></i>'); ?>';
                    var pinpointe_label_integration_status = '<?php esc_html_e('Integration status', 'pinpointe'); ?>';

                    <?php if ($current_tab == 'forms'): ?>
                        var pinpointe_selected_lists = <?php echo wp_json_encode($pinpointe_selected_lists); ?>;
                        var pinpointe_selected_tags = <?php echo wp_json_encode($pinpointe_selected_tags); ?>;
                    <?php endif; ?>

                    <?php if ($current_tab == 'checkboxes'): ?>
                        var pinpointe_selected_list = '<?php echo esc_html(isset($this->opt['pinpointe_checkbox_list']) ? $this->opt['pinpointe_checkbox_list'] : ''); ?>';
                    <?php endif; ?>

                    <?php if ($current_tab == 'sync'): ?>
                        var pinpointe_selected_list = '<?php echo esc_html(isset($this->opt['pinpointe_sync_list']) ? $this->opt['pinpointe_sync_list'] : ''); ?>';
                    <?php endif; ?>

                 </script>
            <?php
        }

        /**
         * Render section info
         *
         * @access public
         * @param array $section
         * @return void
         */
        public function render_section_info($section)
        {
            if (isset($this->section_info[$section['id']])) {
                echo esc_html($this->section_info[$section['id']]);
            }

            if ($section['id'] == 'forms') {

                if (!$this->opt['pinpointe_api_key']) {
                    ?>
                    <div class="pinpointe-forms">
                        <p><?php printf(__('You must <a href="%s">enter</a> your Pinpointe API key to use this feature.', 'pinpointe'), esc_url(admin_url('/options-general.php?page=pinpointe&tab=settings'))); ?></p>
                    </div>
                    <?php
                }
                else {

                    /**
                     * Load list of all pages
                     */
                    $pages = array('' => '');

                    $pages_raw = get_posts(array(
                        'posts_per_page'    => -1,
                        'post_type'         => 'page',
                        'post_status'       => 'publish'
                    ));

                    foreach ($pages_raw as $post_key => $post) {
                        $post_name = $post->post_title;

                        if ($post->post_parent) {
                            $parent_id = $post->post_parent;
                            $has_parent = true;

                            // Count iterations to make sure while does not get insane and crash the page
                            $post_iterations = array();

                            while ($has_parent) {

                                // Track iteration count
                                if (!isset($post_iterations[$parent_id])) {
                                    $post_iterations[$parent_id] = 1;
                                }
                                else {
                                    $post_iterations[$parent_id]++;

                                    if ($post_iterations[$parent_id] > 100) {
                                        break;
                                    }
                                }

                                foreach ($pages_raw as $parent_post_key => $parent_post) {
                                    if ($parent_post->ID == $parent_id) {
                                        $post_name = $parent_post->post_title . ' &rarr; ' . $post_name;

                                        if ($parent_post->post_parent) {
                                            $parent_id = $parent_post->post_parent;
                                        }
                                        else {
                                            $has_parent = false;
                                        }

                                        break;
                                    }
                                }
                            }
                        }

                        $pages[$post->ID] = $post_name;
                    }

                    /**
                     * Load list of all posts
                     */
                    $posts = array('' => '');

                    $posts_raw = get_posts(array(
                        'posts_per_page'    => -1,
                        'post_type'         => 'post',
                        'post_status'       => 'publish'
                    ));

                    foreach ($posts_raw as $post_key => $post) {
                        $post_name = $post->post_title;
                        $posts[$post->ID] = $post_name;
                    }

                    /**
                     * Load list of all post categories
                     */
                    $post_categories = array('' => '');

                    $post_categories_raw = get_categories(array(
                        'type'          => 'post',
                        'hide_empty'    => 0,
                    ));

                    foreach ($post_categories_raw as $post_cat_key => $post_cat) {
                        $category_name = $post_cat->name;

                        if ($post_cat->parent) {
                            $parent_id = $post_cat->parent;
                            $has_parent = true;

                            while ($has_parent) {
                                foreach ($post_categories_raw as $parent_post_cat_key => $parent_post_cat) {
                                    if ($parent_post_cat->term_id == $parent_id) {
                                        $category_name = $parent_post_cat->name . ' &rarr; ' . $category_name;

                                        if ($parent_post_cat->parent) {
                                            $parent_id = $parent_post_cat->parent;
                                        }
                                        else {
                                            $has_parent = false;
                                        }

                                        break;
                                    }
                                }
                            }
                        }

                        $post_categories[$post_cat->term_id] = $category_name;
                    }

                    /**
                     * Available assignment to groups methods
                     */
                    $group_methods = array(
                        array(
                            'title'     => __('Automatically', 'pinpointe'),
                            'children'  => array(
                                'auto'  => __('All groups selected above', 'pinpointe'),
                            ),
                        ),
                        array(
                            'title'     => __('Allow users to select (optional)', 'pinpointe'),
                            'children'  => array(
                                'multi'         => __('Checkbox group for each grouping', 'pinpointe'),
                                'single'        => __('Radio button group for each grouping', 'pinpointe'),
                                'select'        => __('Select field (dropdown) for each grouping', 'pinpointe'),
                            ),
                        ),
                        array(
                            'title'     => __('Require users to select (required)', 'pinpointe'),
                            'children'  => array(
                                'single_req'    => __('Radio button group for each grouping', 'pinpointe'),
                                'select_req'    => __('Select field (dropdown) for each grouping', 'pinpointe'),
                            ),
                        )
                    );

                    /**
                     * Available conditions
                     */
                    $condition_options = array(
                        array(
                            'title'     => __('No condition', 'pinpointe'),
                            'children'  => array(
                                'always'        => __('Always allow this form to be displayed', 'pinpointe'),
                                'disable'       => __('Disable this form', 'pinpointe'),
                            ),
                        ),
                        array(
                            'title'     => __('Conditions', 'pinpointe'),
                            'children'  => array(
                                'front'         => __('Front page only', 'pinpointe'),
                                'pages'         => __('Specific pages', 'pinpointe'),
                                'posts'         => __('Specific posts', 'pinpointe'),
                                'categories'    => __('Specific post categories', 'pinpointe'),
                                'url'           => __('URL contains', 'pinpointe'),
                            ),
                        ),
                        array(
                            'title'     => __('Inversed Conditions', 'pinpointe'),
                            'children'  => array(
                                'pages_not'         => __('Pages not', 'pinpointe'),
                                'posts_not'         => __('Posts not', 'pinpointe'),
                                'categories_not'    => __('Post categories not', 'pinpointe'),
                                'url_not'           => __('URL does not contain', 'pinpointe'),
                            ),
                        ),
                    );

                    /**
                     * Available color schemes
                     */
                    $color_schemes = array(
                        'cyan'      => __('Cyan', 'pinpointe'),
                        'red'       => __('Red', 'pinpointe'),
                        'orange'    => __('Orange', 'pinpointe'),
                        'green'     => __('Green', 'pinpointe'),
                        'purple'    => __('Purple', 'pinpointe'),
                        'pink'      => __('Pink', 'pinpointe'),
                        'yellow'    => __('Yellow', 'pinpointe'),
                        'blue'      => __('Blue', 'pinpointe'),
                        'black'     => __('Black', 'pinpointe'),
                    );

                    /**
                     * Load saved forms
                     */
                    if (isset($this->opt['forms']) && is_array($this->opt['forms']) && !empty($this->opt['forms'])) {

                        // Real forms
                        $saved_forms = $this->opt['forms'];

                        // Pass selected properties to Javascript
                        $pinpointe_selected_lists = array();
                        $pinpointe_selected_tags = array();

                        foreach ($saved_forms as $form_key => $form) {
                            $pinpointe_selected_lists[$form_key] = array(
                                'list'      => $form['list'],
                                'groups'    => $form['groups'],
                                'merge'     => $form['fields']
                            );
                            $pinpointe_selected_tags[$form_key] = array(
                                'tags'       => $form['tags']
                            );
                        }
                    }
                    else {

                        // Mockup
                        $saved_forms[1] = array(
                            'title'     => '',
                            'above'     => '',
                            'below'     => '',
                            'list'      => '',
                            'groups'    => array(),
                            'fields'    => array(),
                            'condition' => array(
                                'key'   =>  'always',
                                'value' =>  '',
                            ),
                            'color_scheme'  => 'cyan',
                        );

                        // Pass selected properties to Javascript
                        $pinpointe_selected_lists = array();
                        $pinpointe_selected_tags = array();
                    }
                    ob_start();
                    require PINPOINTE_PLUGIN_PATH . '/includes/pinpointe-confirmation-email.inc.php';
                    $default_confirmation_email_html = ob_get_clean();
                    ?>

                    <div class="pinpointe-forms">
                        <div id="pinpointe_forms_list">

                        <?php foreach ($saved_forms as $form_key => $form): ?>

                            <div id="pinpointe_forms_list_<?php echo esc_attr($form_key); ?>">
                                <h4 class="pinpointe_forms_handle"><span class="pinpointe_forms_title" id="pinpointe_forms_title_<?php echo esc_attr($form_key); ?>"><?php esc_html_e('Signup Form #', 'pinpointe'); ?><?php echo esc_html($form_key); ?></span>&nbsp;<span class="pinpointe_forms_title_name"><?php echo (!empty($form['title'])) ? '- ' . esc_html($form['title']) : ''; ?></span><span class="pinpointe_forms_remove" id="pinpointe_forms_remove_<?php echo esc_attr($form_key); ?>" title="<?php esc_html_e('Remove', 'pinpointe'); ?>"><i class="fa-solid fa-xmark"></i></span></h4>
                                <div style="clear:both;">

                                    <div class="pinpointe_forms_section"><?php esc_html_e('Main Settings', 'pinpointe'); ?></div>
                                    <table class="form-table"><tbody>
                                        <tr valign="top">
                                            <th scope="row"><?php esc_html_e('Form title', 'pinpointe'); ?></th>
                                            <td><input type="text" id="pinpointe_forms_title_field_<?php echo esc_attr($form_key); ?>" name="pinpointe_options[forms][<?php echo esc_attr($form_key); ?>][title]" value="<?php echo esc_attr($form['title']); ?>" class="pinpointe-field pinpointe_forms_title_field"></td>
                                        </tr>
                                        <tr valign="top">
                                            <th scope="row"><?php esc_html_e('Text above form', 'pinpointe'); ?></th>
                                            <td><input type="text" id="pinpointe_forms_above_field_<?php echo esc_attr($form_key); ?>" name="pinpointe_options[forms][<?php echo esc_attr($form_key); ?>][above]" value="<?php echo esc_attr($form['above']); ?>" class="pinpointe-field pinpointe_forms_above_field"></td>
                                        </tr>
                                        <tr valign="top">
                                            <th scope="row"><?php esc_html_e('Text below form', 'pinpointe'); ?></th>
                                            <td><input type="text" id="pinpointe_forms_below_field_<?php echo esc_attr($form_key); ?>" name="pinpointe_options[forms][<?php echo esc_attr($form_key); ?>][below]" value="<?php echo esc_attr($form['below']); ?>" class="pinpointe-field pinpointe_forms_below_field"></td>
                                        </tr>
                                        <tr valign="top">
                                            <th scope="row"><?php esc_html_e('Submit button label', 'pinpointe'); ?></th>
                                            <td><input type="text" id="pinpointe_forms_button_field_<?php echo esc_attr($form_key); ?>" name="pinpointe_options[forms][<?php echo esc_attr($form_key); ?>][button]" value="<?php echo isset($form['button']) ? esc_attr($form['button']) : ''; ?>" class="pinpointe-field pinpointe_forms_button_field"></td>
                                        </tr>
                                        <tr valign="top">
                                            <th scope="row"><?php esc_html_e('Color scheme', 'pinpointe'); ?></th>
                                            <td><select id="pinpointe_forms_color_scheme_<?php echo esc_attr($form_key); ?>" name="pinpointe_options[forms][<?php echo esc_attr($form_key); ?>][color_scheme]" class="pinpointe-field pinpointe_forms_color_scheme">

                                                <?php
                                                    foreach ($color_schemes as $scheme_value => $scheme_title) {
                                                        $is_selected = ((isset($form['color_scheme']) && $form['color_scheme'] == $scheme_value) ? 'selected="selected"' : '');
                                                        echo '<option value="' . esc_attr($scheme_value) . '" ' . esc_attr($is_selected) . '>' . esc_html($scheme_title) . '</option>';
                                                    }
                                                ?>

                                            </select></td>
                                        </tr>
                                        <tr valign="top">
                                            <th scope="row"><?php esc_html_e('Success redirect URL', 'pinpointe'); ?></th>
                                            <td><input type="text" id="pinpointe_forms_redirect_url_<?php echo esc_attr($form_key); ?>" name="pinpointe_options[forms][<?php echo esc_attr($form_key); ?>][redirect_url]" value="<?php echo isset($form['redirect_url']) ? esc_attr($form['redirect_url']) : ''; ?>" class="pinpointe-field pinpointe_forms_redirect_url"></td>
                                        </tr>
                                        <tr valign="top">
                                            <th scope="row"><?php esc_html_e('Send confirmation email', 'pinpointe'); ?></th>
                                            <td>
                                                <?php
                                                    echo '<input type="checkbox" id="pinpointe_forms_send_confirmation_' . esc_attr($form_key) . '" name="pinpointe_options[forms][' . esc_attr($form_key) . '][send_confirmation]" class="pinpointe-field pinpointe_forms_send_confirmation" ' . (isset($form['send_confirmation']) && $form['send_confirmation'] ? "checked" : '') . '>';
                                                ?>
                                            </td>
                                        </tr>
                                        <tr valign="top" class="pinpointe_forms_confirmation_email_trs">
                                            <th scope="row"><?php esc_html_e('Override default confirmation email', 'pinpointe'); ?></th>
                                            <td>
                                                <?php
                                                   echo '<input type="checkbox" id="pinpointe_forms_overide_confirmation_' . esc_attr($form_key) . '" name="pinpointe_options[forms][' . esc_attr($form_key) . '][overide_confirmation]" class="pinpointe-field pinpointe_forms_overide_confirmation" ' . (isset($form['overide_confirmation']) && $form['overide_confirmation'] ? "checked" : '') . '>';
                                                ?>
                                            </td>
                                        </tr>
                                        <tr valign="top" class="pinpointe_forms_confirmation_email_trs pinpointe_forms_confirmation_email_tr">
                                            <td scope="row" colspan="2" id="forms_confirmation_email_td_<?php echo esc_attr($form_key) ?>"><strong><?php esc_html_e('Confirmation email', 'pinpointe'); ?></strong>
                                            <br/>
                                            <?php 
                                                        wp_enqueue_editor();
                                                        $editor_key = 'pinpointe_forms_html_confirmation_email_' . $form_key;

                                                        $content = isset($form['html_confirmation_email']) && !empty($form['html_confirmation_email']) ? $form['html_confirmation_email'] :
                                                        $default_confirmation_email_html;

                                                        $textarea_name = 'pinpointe_options[forms][' . $form_key . '][html_confirmation_email]';

                                                        echo '<textarea class="pinpointe-field pinpointe_forms_html_confirmation_email" name="' .  esc_attr($textarea_name) . '" id="' . esc_attr($editor_key) . '" rows="25" style="width:100%">' .  esc_textarea($content) . '</textarea>';
                                                        
                                                        ?>
                                            </td>
                                        </tr>
                                    </tbody></table>

                                    <div class="pinpointe_forms_section">Database</div>
                                    <p id="pinpointe_forms_list_<?php echo esc_attr($form_key); ?>" class="pinpointe_loading_list pinpointe_forms_field_list_groups">
                                        <span class="pinpointe_loading_icon"></span>
                                        <?php wp_nonce_field('ajax_get_lists_groups_fields', 'pinpointe_get_lists_groups_fields'); ?>
                                        <?php esc_html_e('Connecting to Pinpointe...', 'pinpointe'); ?>
                                    </p>
                                  
                                    <div class="pinpointe_forms_section">List</div>
                                    <?php wp_nonce_field('ajax_get_tags_groups_fields', 'pinpointe_get_tags_with_multiple_groups_and_fields'); ?>
                                    <p id="pinpointe_forms_tag_<?php echo esc_attr($form_key); ?>" class="pinpointe_loading_tag pinpointe_forms_field_tag_groups">
                                        <span class="pinpointe_loading_icon"></span>
                                        <?php esc_html_e('Connecting to Pinpointe...', 'pinpointe'); ?>
                                    </p>
                                    <?php wp_nonce_field('ajax_get_lists', 'pinpointe_get_lists'); ?>
                                    
                                    <table class="form-table">
                                          <tbody>
                                              <tr valign="top">
                                                  <th scope="row"><?php esc_html_e('Show Mailing lists', 'pinpointe'); ?></th>
                                                  <td>
                                                      <?php
                                                       echo '<input type="checkbox" id="pinpointe_forms_show_mailing_list' . esc_attr($form_key) . '" name="pinpointe_options[forms][' . esc_attr($form_key) . '][show_mailing_list]" class="pinpointe-field pinpointe_forms_show_mailing_list" ' . (isset($form['show_mailing_list']) && $form['show_mailing_list'] === 'on' ? "checked" : '') . '>';
                                                       ?>
                                               </td>
                                            </tr>
                                        </tbody>                  
                                    </table>

                                    <div class="pinpointe_forms_section">Form Fields</div>
                                    <?php wp_nonce_field('ajax_groups_and_tags_in_array', 'pinpointe_update_groups_and_tags'); ?>
                                    <p id="pinpointe_fields_table_<?php echo esc_attr($form_key); ?>" class="pinpointe_loading_list pinpointe_forms_field_fields">
                                        <span class="pinpointe_loading_icon"></span>
                                        
                                        <?php esc_html_e('Connecting to Pinpointe...', 'pinpointe'); ?>
                                    </p>

                                    <div class="pinpointe_forms_section">Conditions</div>
                                    <table class="form-table"><tbody>
                                        <tr valign="top">
                                            <th scope="row"><?php esc_html_e('Display condition', 'pinpointe'); ?></th>
                                            <td><select id="pinpointe_forms_condition_<?php echo esc_attr($form_key); ?>" name="pinpointe_options[forms][<?php echo esc_attr($form_key); ?>][condition]" class="pinpointe-field form_condition_key">

                                                <?php
                                                foreach ($condition_options as $cond_cat) {
                                                    echo '<optgroup label="' . esc_attr($cond_cat['title']) . '">';

                                                    foreach ($cond_cat['children'] as $cond_value => $cond_title) {
                                                        $is_selected = (is_array($form['condition']) && isset($form['condition']['key']) && $form['condition']['key'] == $cond_value) ? 'selected="selected"' : '';
                                                        echo '<option value="' . esc_attr($cond_value) . '" ' . esc_attr($is_selected) . '>' . esc_html($cond_title) . '</option>';
                                                    }

                                                    echo '</optgroup>';
                                                }
                                                ?>

                                            </select></td>
                                        </tr>
                                        <tr valign="top">
                                            <th scope="row"><?php esc_html_e('Pages', 'pinpointe'); ?></th>
                                            <td><select multiple id="pinpointe_forms_condition_pages_<?php echo esc_attr($form_key); ?>" name="pinpointe_options[forms][<?php echo esc_attr($form_key); ?>][condition_pages][]" class="pinpointe-field form_condition_value form_condition_value_pages form_condition_value_pages_not">

                                                <?php
                                                    foreach ($pages as $key => $name) {
                                                        $is_selected = (is_array($form['condition']) && isset($form['condition']['key']) && in_array($form['condition']['key'], array('pages', 'pages_not')) && isset($form['condition']['value']) && is_array($form['condition']['value']) && in_array($key, $form['condition']['value'])) ? 'selected="selected"' : '';
                                                        echo '<option value="' . esc_attr($key) . '" ' . esc_attr($is_selected) . '>' . esc_html($name) . '</option>';
                                                    }
                                                ?>

                                            </select></td>
                                        </tr>
                                        <tr valign="top">
                                            <th scope="row"><?php esc_html_e('Posts', 'pinpointe'); ?></th>
                                            <td><select multiple id="pinpointe_forms_condition_posts_<?php echo esc_attr($form_key); ?>" name="pinpointe_options[forms][<?php echo esc_attr($form_key); ?>][condition_posts][]" class="pinpointe-field form_condition_value form_condition_value_posts form_condition_value_posts_not">

                                                <?php
                                                    foreach ($posts as $key => $name) {
                                                        $is_selected = (is_array($form['condition']) && isset($form['condition']['key']) && in_array($form['condition']['key'], array('posts', 'posts_not')) && isset($form['condition']['value']) && is_array($form['condition']['value']) && in_array($key, $form['condition']['value'])) ? 'selected="selected"' : '';
                                                        echo '<option value="' . esc_attr($key) . '" ' . esc_attr($is_selected) . '>' . esc_html($name) . '</option>';
                                                    }
                                                ?>

                                            </select></td>
                                        </tr>
                                        <tr valign="top">
                                            <th scope="row"><?php esc_html_e('Post categories', 'pinpointe'); ?></th>
                                            <td><select multiple id="pinpointe_forms_condition_categories_<?php echo esc_attr($form_key); ?>" name="pinpointe_options[forms][<?php echo esc_attr($form_key); ?>][condition_categories][]" class="pinpointe-field form_condition_value form_condition_value_categories form_condition_value_categories_not">

                                                <?php
                                                    foreach ($post_categories as $key => $name) {
                                                        $is_selected = (is_array($form['condition']) && isset($form['condition']['key']) && in_array($form['condition']['key'], array('categories', 'categories_not')) && isset($form['condition']['value']) && is_array($form['condition']['value']) && in_array($key, $form['condition']['value'])) ? 'selected="selected"' : '';
                                                        echo '<option value="' . esc_attr($key) . '" ' . esc_attr($is_selected) . '>' . esc_html($name) . '</option>';
                                                    }
                                                ?>

                                            </select></td>
                                        </tr>
                                        <tr valign="top">
                                            <th scope="row"><?php esc_html_e('URL fragment', 'pinpointe'); ?></th>
                                            <td><input type="text" id="pinpointe_forms_condition_url_<?php echo
												esc_attr($form_key); ?>" name="pinpointe_options[forms][<?php echo esc_attr($form_key); ?>][condition_url]" value="<?php echo ((isset($form['condition']['key']) && in_array($form['condition']['key'], array('url', 'url_not')) && isset($form['condition']['value'])) ? esc_attr($form['condition']['value']) : ''); ?>" class="pinpointe-field form_condition_value form_condition_value_url form_condition_value_url_not"></td>
                                        </tr>
                                    </tbody></table>

                                </div>
                                <div style="clear: both;"></div>
                            </div>

                        <?php endforeach; ?>

                        </div>
                        <div>
                            <button type="button" name="pinpointe_add_set" id="pinpointe_add_set" disabled="disabled"
                                    class="button button-primary" value="<?php esc_html_e('Add Form', 'pinpointe');
                                    ?>" title="<?php esc_html_e('Still connecting to Pinpointe...', 'pinpointe');
                                    ?>"><i class="fa-solid fa-plus">&nbsp;&nbsp;<?php esc_html_e('Add Form', 'pinpointe'); ?></i></button>
                            <div style="clear: both;"></div>
                        </div>
                    </div>

                    <?php

                }
            }
            else if ($section['id'] == 'help_display') {
                echo '<div class="pinpointe-section-info"><p style="line-height: 160%;">'
                   . esc_html__('You can create multiple newsletter signup forms on the "Forms" page. Each can be displayed in one of the following ways:', 'pinpointe')
                   . '</p><p style="line-height: 160%;">'
                   . '<strong style="color:#222;">' . esc_html__('Widget', 'pinpointe') . '</strong><br />'
                   . sprintf(__('Display any form on a sidebar of your choice by using a standard WordPress Widget. Go to the <a href="%s">Widgets page</a> and use the one named Pinpointe Signup. You can add as many instances of this widget as you like. You can optionally specify a comma-separated list of form IDs to limit which forms can be displayed on that particular spot.', 'pinpointe'), esc_url(admin_url('/widgets.php')))
                   . '</p><p style="line-height: 160%;">'
                   . '<strong style="color:#222;">' . esc_html__('Shortcode', 'pinpointe') . ' <code>[pinpointe_form]</code></strong><br />'
                   . esc_html__('Insert a form into individual posts or pages by placing a shortcode anywhere in the content. You can limit which forms are eligible to be displayed here by specifying <code>forms</code> with a comma-separated list of form IDs, e.g. <code>[pinpointe_form forms="1,3,4"]</code>. A single form will be chosen from the list of form IDs.', 'pinpointe')
                   . '</p><p style="line-height: 160%;">'
                   . '<strong style="color:#222;">' . esc_html__('Function', 'pinpointe') . ' <code>pinpointe_form()</code></strong><br />'
                   . __('To display a form in nonstandard parts of your website, take advantage of the PHP function <code>pinpointe_form()</code>. To limit which forms can be displayed at that particular spot, pass a list of allowed form IDs in an array, e.g. <code>pinpointe_form(array(\'1\', \'3\', \'4\'))</code>', 'pinpointe')
                   . '</p><p style="line-height: 160%;">'
                   . '<strong style="color:#222;">' . esc_html__('Popup', 'pinpointe') . '</strong><br />'
                   . sprintf(__('Increase the signup rate by displaying one of your signup forms in a <a href="%s">popup</a>. If you wish popup to be opened on click and not automatically on page load, simply assign ID <code>pinpointe_popup_open</code> to the element that you want to bind it to.', 'pinpointe'), esc_url(admin_url('/options-general.php?page=pinpointe&tab=popup')))
                   . '</p><p style="line-height: 160%;">'
                   . '<strong style="color:#222;">' . esc_html__('Under Post Content', 'pinpointe') . '</strong><br />'
                   . sprintf(__('You can easily configure this plugin to display signup forms under each <a href="%s">post</a> that you publish. You can exclude (or include only particular posts) by setting appropriate form displaying conditions.', 'pinpointe'), esc_url(admin_url('/options-general.php?page=pinpointe&tab=below')))
                   . '</p><p style="line-height: 160%;">'
                   . '<strong style="color:#222;">' . esc_html__('As Content Lock', 'pinpointe') . '</strong><br />'
                   . sprintf( __('If you have valuable content that your visitors are after, you may wish to <a href="%s">lock</a> some of it so only visitors who subscribe to your mailing list can access it.', 'pinpointe'), esc_url(admin_url('/options-general.php?page=pinpointe&tab=lock')))
                   . '</p></div>';
            }
            else if ($section['id'] == 'help_contact') {
                echo '<div class="pinpointe-section-info"><p>'
                   . sprintf(__('If you\'ve got any questions, feel free to contact us at <a href="mailto:%s">%s</a>.', 'pinpointe'), 'support@pinpointe.com', 'support@pinpointe.com')
                   . '</p><p>'
                   . '</p></div>';
            }

        }

        /*
         * Render a text field
         *
         * @access public
         * @param array $args
         * @return void
         */
        public function render_options_text($args = array())
        {
            printf(
                '<input type="text" id="%s" name="pinpointe_options[%s]" value="%s" class="pinpointe-field" />',
                esc_attr($args['name']),
				esc_attr($args['name']),
				esc_attr($args['options'][$args['name']])
            );
        }

        /*
         * Render a text area
         *
         * @access public
         * @param array $args
         * @return void
         */
        public function render_options_textarea($args = array())
        {
            printf(
                '<textarea id="%s" name="pinpointe_options[%s]" class="pinpointe-textarea">%s</textarea>',
				esc_attr($args['name']),
				esc_attr($args['name']),
				esc_html($args['options'][$args['name']])
            );
        }

        /*
         * Render a checkbox
         *
         * @access public
         * @param array $args
         * @return void
         */
        public function render_options_checkbox($args = array())
        {
            printf(
                '<input type="checkbox" id="%s" name="pinpointe_options[%s]" value="1" %s />',
				esc_attr($args['name']),
				esc_attr($args['name']),
                checked($args['options'][$args['name']], true, false)
            );
        }

        /*
         * Render a set of checkboxes
         *
         * @access public
         * @param array $args
         * @return void
         */
        public function render_options_checkbox_set($args = array())
        {
            echo '<ul style="margin-top:7px;">';

            foreach ($this->options[$args['name']] as $key => $name) {

                echo '<li>';

                $is_checked = false;

                if (isset($args['options'][$args['name']]) && is_array($args['options'][$args['name']])) {
                    $is_checked = in_array($key, $args['options'][$args['name']]) ? true : false;
                }

                printf(
                    '<input type="checkbox" id="%s_%s" name="pinpointe_options[%s][]" value="%s" %s />',
					esc_attr($args['name']),
					esc_attr($key),
					esc_attr($args['name']),
					esc_attr($key),
                    checked($is_checked, true, false)
                );

                echo esc_html($name) . '</li>';
            }

            echo '</ul>';
        }

        /*
         * Render a dropdown
         *
         * @access public
         * @param array $args
         * @return void
         */
        public function render_options_dropdown($args = array())
        {
            printf(
                '<select id="%s" name="pinpointe_options[%s]" class="pinpointe-field">',
				esc_attr($args['name']),
				esc_attr($args['name']),
            );

            foreach ($this->options[$args['name']] as $key => $name) {
                printf(
                    '<option value="%s" %s>%s</option>',
					esc_attr($key),
                    selected($key, $args['options'][$args['name']], false),
					esc_html($name)
                );
            }

            echo '</select>';
        }

        /*
         * Render a multi-select dropdown
         *
         * @access public
         * @param array $args
         * @return void
         */
        public function render_options_dropdown_multi($args = array())
        {
            printf(
                '<select multiple id="%s" name="pinpointe_options[%s][]" class="pinpointe-field">',
                esc_attr($args['name']),
                esc_attr($args['name'])
            );

            foreach ($this->options[$args['name']] as $key => $name) {
                printf(
                    '<option value="%s" %s>%s</option>',
					esc_attr($key),
                    (in_array($key, $args['options'][$args['name']]) ? 'selected="selected"' : ''),
					esc_html($name)
                );
            }

            echo '</select>';
        }

        /*
         * Render a password field
         *
         * @access public
         * @param array $args
         * @return void
         */
        public function render_options_password($args = array())
        {
            printf(
                '<input type="password" id="%s" name="pinpointe_options[%s]" value="%s" class="pinpointe-field" />',
                esc_attr($args['name']),
                esc_attr($args['name']),
				esc_attr($args['options'][$args['name']])
            );
        }

        /**
         * Validate admin form input
         *
         * @access public
         * @param array $input
         * @return array
         */
        public function options_validate($input)
        {
            $current_tab = isset($_POST['current_tab']) ? sanitize_text_field($_POST['current_tab']) : 'settings';
            $output = $original = $this->get_options();

            $revert = array();
            $errors = array();

            // Validate forms
            if ($current_tab == 'forms') {
                if (isset($input['forms']) ) {

                    $new_forms = array();
                    $form_number = 0;

                    foreach ($input['forms'] as $form) {

                        $form_number++;

                        $new_forms[$form_number] = array();

                        // Title
                        $new_forms[$form_number]['title'] = (isset($form['title']) && !empty($form['title'])) ? $form['title']: '';

                        // Above
                        $new_forms[$form_number]['above'] = (isset($form['above']) && !empty($form['above'])) ? $form['above']: '';

                        // Below
                        $new_forms[$form_number]['below'] = (isset($form['below']) && !empty($form['below'])) ? $form['below']: '';

                        // Button
                        $new_forms[$form_number]['button'] = (isset($form['button']) && !empty($form['button'])) ? $form['button']: '';

                        // Redirect URL
                        $new_forms[$form_number]['redirect_url'] = (isset($form['redirect_url']) && !empty($form['redirect_url'])) ? $form['redirect_url']: '';

                        // Send Confirmation
                        $new_forms[$form_number]['send_confirmation'] = (isset($form['send_confirmation']) && !empty($form['send_confirmation'])) ? $form['send_confirmation']: '';

                        // overide_confirmation
                        $new_forms[$form_number]['overide_confirmation'] = (isset($form['overide_confirmation']) && !empty($form['overide_confirmation'])) ? $form['overide_confirmation']: '';

                        // html_confirmation_email
                        if (isset($form['html_confirmation_email']) && !empty($form['html_confirmation_email'])) {
                            $shortcode_validation_errors = $this->validateShortcodes($form['html_confirmation_email']);
                            if (!empty($shortcode_validation_errors)) {
                                foreach($shortcode_validation_errors as $error){
                                    array_push(
                                            $errors,
                                            array(
                                                    'setting' => 'html_confirmation_email',
                                                    'custom' => printf(esc_html__('%s', 'pinpointe'), esc_html($error))
                                            )
                                    );
                                }
								$form['html_confirmation_email'] = '';
                            }
                            $new_forms[$form_number]['html_confirmation_email'] = $form['html_confirmation_email'];
                        }

                        // List
                        $new_forms[$form_number]['list'] = (isset($form['list_field']) && !empty($form['list_field'])) ? $form['list_field']: '';

                        // Tag
                        $new_forms[$form_number]['tags'] = (isset($form['tag_field']) && !empty($form['tag_field'])) ? $form['tag_field']: '';

                        // Show Mailing list
                        $new_forms[$form_number]['show_mailing_list'] = (isset($form['show_mailing_list']) && !empty($form['show_mailing_list'])) ? $form['show_mailing_list'] : 'off';

                        if (empty($new_forms[$form_number]['tags'])) {
                            array_push(
                                $errors,
                                array(
                                    'setting' => 'tags',
                                    'custom' => printf(esc_html__('%s', 'pinpointe'), esc_html("You must selected at least one mailing list"))
                                )
                            );
                        }

                        $this->saveTagMetadata($form_number, $new_forms[$form_number]['show_mailing_list'], $new_forms[$form_number]['tags']);

                        // Groups
                        $new_forms[$form_number]['groups'] = array();

                        if (isset($form['groups']) && is_array($form['groups'])) {
                            foreach ($form['groups'] as $group) {
                                $new_forms[$form_number]['groups'][] = htmlspecialchars($group, ENT_QUOTES);
                            }
                        }

                        // Group method
                        if (isset($form['group_method']) && in_array($form['group_method'], array('auto', 'single', 'single_req', 'multi', 'select', 'select_req'))) {
                            $new_forms[$form_number]['group_method'] = $form['group_method'];
                        }
                        else {
                            $new_forms[$form_number]['group_method'] = 'auto';
                        }

                        // Fields
                        $new_forms[$form_number]['fields'] = array();

                        if (isset($form['fields']) && is_array($form['fields'])) {

                            $field_number = 0;

                            foreach ($form['fields'] as $field) {

                                if (!is_array($field) || !isset($field['name']) || !isset($field['tag']) || empty($field['tag'])) {
                                    continue;
                                }

                                $field_number++;

                                $new_forms[$form_number]['fields'][$field_number] = array(
                                    'name'      => $field['name'],
                                    'tag'       => $field['tag'],
                                    'icon'      => $field['icon'],
                                    'type'      => $field['type'],
                                    'req'       => ($field['req'] == 'true' ? true : false),
                                    'us_phone'  => ($field['us_phone'] == 'true' ? true : false),
                                );

                                if (isset($field['choices']) && !empty($field['choices'])) {
                                    $new_forms[$form_number]['fields'][$field_number]['choices'] = preg_split('/%%%/', $field['choices']);
                                }
                                else {
                                    $new_forms[$form_number]['fields'][$field_number]['choices'] = array();
                                }
                            }
                        }

                        // Condition
                        $new_forms[$form_number]['condition'] = array();
                        $new_forms[$form_number]['condition']['key'] = (isset($form['condition']) && !empty($form['condition'])) ? $form['condition']: 'always';

                        // Condition value
                        if (in_array($new_forms[$form_number]['condition']['key'], array('pages', 'pages_not'))) {
                            if (isset($form['condition_pages']) && is_array($form['condition_pages']) && !empty($form['condition_pages'])) {
                                foreach ($form['condition_pages'] as $condition_item) {
                                    if (empty($condition_item)) {
                                        continue;
                                    }

                                    $new_forms[$form_number]['condition']['value'][] = $condition_item;
                                }
                            }
                            else {
                                $new_forms[$form_number]['condition']['key'] = 'always';
                                $new_forms[$form_number]['condition']['value'] = '';
                            }
                        }
                        else if (in_array($new_forms[$form_number]['condition']['key'], array('posts', 'posts_not'))) {
                            if (isset($form['condition_posts']) && is_array($form['condition_posts']) && !empty($form['condition_posts'])) {
                                foreach ($form['condition_posts'] as $condition_item) {
                                    if (empty($condition_item)) {
                                        continue;
                                    }

                                    $new_forms[$form_number]['condition']['value'][] = $condition_item;
                                }
                            }
                            else {
                                $new_forms[$form_number]['condition']['key'] = 'always';
                                $new_forms[$form_number]['condition']['value'] = '';
                            }
                        }
                        else if (in_array($new_forms[$form_number]['condition']['key'], array('categories', 'categories_not'))) {
                            if (isset($form['condition_categories']) && is_array($form['condition_categories']) && !empty($form['condition_categories'])) {
                                foreach ($form['condition_categories'] as $condition_item) {
                                    if (empty($condition_item)) {
                                        continue;
                                    }

                                    $new_forms[$form_number]['condition']['value'][] = $condition_item;
                                }
                            }
                            else {
                                $new_forms[$form_number]['condition']['key'] = 'always';
                                $new_forms[$form_number]['condition']['value'] = '';
                            }
                        }
                        else if (in_array($new_forms[$form_number]['condition']['key'], array('url', 'url_not'))) {
                            if (isset($form['condition_url']) && !empty($form['condition_url'])) {
                                $new_forms[$form_number]['condition']['value'] = $form['condition_url'];
                            }
                            else {
                                $new_forms[$form_number]['condition']['key'] = 'always';
                                $new_forms[$form_number]['condition']['value'] = '';
                            }
                        }
                        else {
                            $new_forms[$form_number]['condition']['value'] = '';
                        }

                        // Color scheme
                        $new_forms[$form_number]['color_scheme'] = (isset($form['color_scheme']) && !empty($form['color_scheme'])) ? $form['color_scheme']: 'cyan';

                    }
                }
                $output['forms'] = $new_forms;
            }

            // Validate other content
            else {

                // Iterate over fields and validate/sanitize input
                foreach ($this->validation[$current_tab] as $field => $rule) {

                    $allow_empty = true;

                    // Conditional validation
                    if (is_array($rule['empty']) && !empty($rule['empty'])) {
                        if (isset($input['pinpointe_' . $rule['empty'][0]]) && ($input['pinpointe_' . $rule['empty'][0]] != '0')) {
                            $allow_empty = false;
                        }
                    }
                    else if ($rule['empty'] == false) {
                        $allow_empty = false;
                    }

                    // Different routines for different field types
                    switch($rule['rule']) {

                        // Validate numbers
                        case 'number':
                            if (is_numeric($input[$field]) || ($input[$field] == '' && $allow_empty)) {
                                $output[$field] = $input[$field];
                            }
                            else {
                                if (is_array($rule['empty']) && !empty($rule['empty'])) {
                                    $revert[$rule['empty'][0]] = '0';
                                }
                                array_push($errors, array('setting' => $field, 'code' => 'number'));
                            }
                            break;

                        // Validate boolean values (actually 1 and 0)
                        case 'bool':
                            $input[$field] = !isset($input[$field]) || $input[$field] == '' ? '0' : $input[$field];
                            if (in_array($input[$field], array('0', '1')) || ($input[$field] == '' && $allow_empty)) {
                                $output[$field] = $input[$field];
                            }
                            else {
                                if (is_array($rule['empty']) && !empty($rule['empty'])) {
                                    $revert[$rule['empty'][0]] = '0';
                                }
                                array_push($errors, array('setting' => $field, 'code' => 'bool'));
                            }
                            break;

                        // Validate predefined options
                        case 'option':

                            // Check if this call is for mailing lists
                            if ($field == 'pinpointe_list_checkout') {
                                //$this->options[$field] = $this->get_lists();
                                if (is_array($rule['empty']) && !empty($rule['empty']) && $input['pinpointe_'.$rule['empty'][0]] != '1' && (empty($input[$field]) || $input[$field] == '0')) {
                                    if (is_array($rule['empty']) && !empty($rule['empty'])) {
                                        $revert[$rule['empty'][0]] = '1';
                                    }
                                    array_push($errors, array('setting' => $field, 'code' => 'option'));
                                }
                                else {
                                    $output[$field] = ($input[$field] == null ? '0' : $input[$field]);
                                }

                                break;
                            }
                            else if (in_array($field, array('pinpointe_list_widget', 'pinpointe_list_shortcode'))) {
                                //$this->options[$field] = $this->get_lists();
                                if (is_array($rule['empty']) && !empty($rule['empty']) && $input['pinpointe_'.$rule['empty'][0]] != '0' && (empty($input[$field]) || $input[$field] == '0')) {
                                    if (is_array($rule['empty']) && !empty($rule['empty'])) {
                                        $revert[$rule['empty'][0]] = '0';
                                    }
                                    array_push($errors, array('setting' => $field, 'code' => 'option'));
                                }
                                else {
                                    $output[$field] = ($input[$field] == null ? '0' : $input[$field]);
                                }

                                break;
                            }

                            if (isset($this->options[$field][$input[$field]]) || ($input[$field] == '' && $allow_empty)) {
                                $output[$field] = ($input[$field] == null ? '0' : $input[$field]);
                            }
                            else {
                                if (is_array($rule['empty']) && !empty($rule['empty'])) {
                                    $revert[$rule['empty'][0]] = '0';
                                }
                                array_push($errors, array('setting' => $field, 'code' => 'option'));
                            }
                            break;

                        // Multiple selections
                        case 'multiple_any':
                            if (empty($input[$field]) && !$allow_empty) {
                                if (is_array($rule['empty']) && !empty($rule['empty'])) {
                                    $revert[$rule['empty'][0]] = '0';
                                }
                                array_push($errors, array('setting' => $field, 'code' => 'multiple_any'));
                            }
                            else {
                                if (isset($input[$field]) && is_array($input[$field]) && !empty($input[$field])) {
                                    $temporary_output = array();

                                    foreach ($input[$field] as $field_val) {
                                        $temporary_output[] = htmlspecialchars($field_val, ENT_QUOTES);
                                    }

                                    $output[$field] = $temporary_output;
                                }
                                else {
                                    $output[$field] = array();
                                }
                            }
                            break;

                        // Validate emails
                        case 'email':
                            if (filter_var(trim($input[$field]), FILTER_VALIDATE_EMAIL) || ($input[$field] == '' && $allow_empty)) {
                                $output[$field] = esc_attr(trim($input[$field]));
                            }
                            else {
                                if (is_array($rule['empty']) && !empty($rule['empty'])) {
                                    $revert[$rule['empty'][0]] = '0';
                                }
                                array_push($errors, array('setting' => $field, 'code' => 'email'));
                            }
                            break;

                        // Validate URLs
                        case 'url':
                            // FILTER_VALIDATE_URL for filter_var() does not work as expected
                            if (($input[$field] == '' && !$allow_empty)) {
                                if (is_array($rule['empty']) && !empty($rule['empty'])) {
                                    $revert[$rule['empty'][0]] = '0';
                                }
                                array_push($errors, array('setting' => $field, 'code' => 'url'));
                            }
                            else {
                                $output[$field] = esc_attr(trim($input[$field]));
                            }
                            break;

                        // Custom validation function
                        case 'function':
                            $function_name = 'validate_' . $field;
                            $validation_results = $this->$function_name($input[$field]);

                            // Check if parent is disabled - do not validate then and reset to ''
                            if (is_array($rule['empty']) && !empty($rule['empty'])) {
                                if (empty($input['pinpointe_'.$rule['empty'][0]])) {
                                    $output[$field] = '';
                                    break;
                                }
                            }

                            if (($input[$field] == '' && $allow_empty) || $validation_results === true) {
                                $output[$field] = $input[$field];
                            }
                            else {
                                if (is_array($rule['empty']) && !empty($rule['empty'])) {
                                    $revert[$rule['empty'][0]] = '0';
                                }
                                array_push($errors, array('setting' => $field, 'code' => 'option', 'custom' => $validation_results));
                            }
                            break;

                        // Default validation rule (text fields etc)
                        default:
                            if (($input[$field] == '' && !$allow_empty)) {
                                if (is_array($rule['empty']) && !empty($rule['empty'])) {
                                    $revert[$rule['empty'][0]] = '0';
                                }
                                array_push($errors, array('setting' => $field, 'code' => 'string'));
                            }
                            else {
                                $output[$field] = esc_attr(trim($input[$field]));
                            }
                            break;
                    }
                }

                // Revert parent fields if needed
                if (!empty($revert)) {
                    foreach ($revert as $key => $value) {
                        $output['pinpointe_'.$key] = $value;
                    }
                }

            }

            // Display settings updated message
            add_settings_error(
                'pinpointe_settings_updated',
                'pinpointe_settings_updated',
                __('Your settings have been saved.', 'pinpointe'),
                'updated'
            );

            // Define error messages
            $messages = array(
                'number' => __('must be numeric', 'pinpointe'),
                'bool' => __('must be either 0 or 1', 'pinpointe'),
                'option' => __('is not allowed', 'pinpointe'),
                'email' => __('is not a valid email address', 'pinpointe'),
                'url' => __('is not a valid URL', 'pinpointe'),
                'string' => __('is not a valid text string', 'pinpointe'),
            );

            // Display errors
            foreach ($errors as $error) {
				$message = (!isset($error['custom']) ? $messages[$error['code']] : $error['custom']) . '. ' . __('Reverted to a previous state.', 'pinpointe');
				$setting = $error['setting'];
				
				if (array_key_exists($error['setting'], $this->titles) && isset($this->titles[$error['setting']])) {
					$setting = $this->titles[$error['setting']];
				}
				
				
				$code = array_key_exists('code', $error) && isset($error['code'])
					? $error['code']
					: $error['custom'];
				
				add_settings_error(
					'pinpointe_settings_updated',
					$code,
					__('Value of', 'pinpointe') . ' "' . $setting . '" ' . $message
				);
            }

            return $output;
        }

        /**
         * Save tag metadata to the database as a `pinpointe_tags_metadata` option
         *
         * @param int $form_id The ID of the form.
         * @param array $tag_ids An array of tag IDs to associate with the form.
         * @param bool $show_mailing_list Whether to show the mailing list for the form.
         *
         * @throws \Exception If options cannot be saved || tag_ids is empty 
         */
        public function saveTagMetadata($form_id, $show_mailing_list, $tag_ids = [])
        {
            if(empty($tag_ids)){
                throw new \Exception("Warning Tag Metadata: Cannot attempt to save empty tag_ids");
            }
            // Get all available  (from API)
            $all_tags = $this->get_tags();

            // select only the tags that are specified in $tag_ids
            // we are doing this so that we store only valid tags (tags from API)
            $tags = array_intersect_key($all_tags, array_flip($tag_ids));

            // get the saved tag_metatada
			$existing_metadata = $this->get_tag_metadata();
			
			if (empty($existing_metadata)) {
				$existing_metadata[] = [
					'form_id' => $form_id,
					'tags' => wp_json_encode($tags),
					'show_mailing_list' => $show_mailing_list,
					'hash' => md5(time())
				];
			} else {
				$is_form_id_found = false;
				
				// loop through $existing_metadata to update or add new entry
				// take note we are modifying the &$existing_metadata if form_id has been is_form_id_found
				foreach ($existing_metadata as &$metadata) {
					if ($metadata['form_id'] == $form_id) {
						$metadata['tags'] = wp_json_encode($tags);
						$metadata['show_mailing_list'] = $show_mailing_list;
						$metadata['hash'] = md5(time());
						
						$is_form_id_found = true;
						break; // exit the loop once we've modified the form_id's metadata
					}
				}
				
				unset($metadata); // unset the reference - cause we were modifying it and we are done
				
				// If metadata for the form_id doesn't exist, create a new entry
				if (!$is_form_id_found) {
					$new_metadata = [
						'form_id' => $form_id,
						'tags' => wp_json_encode($tags),
						'show_mailing_list' => $show_mailing_list,
						'hash' => md5(time())
					];
					$existing_metadata[] = $new_metadata;
				}
			}
			
            $status = update_option('pinpointe_tags_metadata', $existing_metadata);

            if (!$status) {
                throw new \Exception("Tag Metadata, Options: Could not save the tag metadata for some reason - E241");
            }
        }

        /**
         * Get tag metadata for a specific form or all forms.
         *
         * @param int|null $form_id The ID of the form. If null, retrieves metadata for all forms.
         *
         * @return array An array of tag metadata for the specified form or all forms.
         */
        public function get_tag_metadata($form_id = null)
        {
            $results = get_option('pinpointe_tags_metadata');

            // Check if a specific form ID is provided and if its metadata exists
            if ($form_id !== null && isset($results[$form_id])) {
              return $results[$form_id];
            } 

            return $results ? $results : [];
        }


        /**
         * Custom validation for shortcodes in the html email content
         *
         * @access public
         * @param string $key
         * @return mixed
         */
        public  function validateShortcodes($content)
        {
            $errors = [];
            /**
             * Shortcodes to check for existence.
             *
             * @var string[]
             */
            $shortcodes_to_check = array(
                'pinpointe_powered_by',
                'pinpointe_email_sent_to',
                'pinpointe_confirm_link',
                'pinpointe_confirm_link_href',
            );

            foreach($shortcodes_to_check as $shortcode) {
                $shortcode_pattern = '/' . preg_quote('[' . $shortcode, '/') . '/';
                if (!preg_match($shortcode_pattern, $content)) {
                    $errors[] =  ' missing '.$shortcode.'  in the confirmation email content';
                }
            }
            return $errors;
        }
        /**
         * Custom validation for service provider API key
         *
         * @access public
         * @param string $key
         * @return mixed
         */
        public function validate_pinpointe_api_key($key)
        {
            if (empty($key)) {
                return 'is empty';
            }

            $test_results = $this->test_pinpointe($key);

            if ($test_results === true) {
                return true;
            }
            else {
                return __(' is not valid or something went wrong. More details: ', 'pinpointe') . $test_results;
            }
        }

        /**
         * Custom validation for allowed forms after posts
         *
         * @access public
         * @param string $value
         * @return mixed
         */
        public function validate_pinpointe_after_posts_allowed_forms($value)
        {
            if (empty($value)) {
                return true;
            }

            if (preg_match('/^([0-9]+,?)+$/', $value)) {
                return true;
            }
            else {
                return __(' is not in a valid format', 'pinpointe');
            }
        }

        /**
         * Load scripts required for admin
         *
         * @access public
         * @return void
         */
        public function enqueue_admin_scripts_and_styles()
        {
            // Font awesome (icons)
            wp_register_style('pinpointe-font-awesome', PINPOINTE_PLUGIN_URL . '/assets/css/font-awesome/css/font-awesome.min.css', array(), '6.5.2');
            
            // register tom select
            wp_register_style('pinpointe-tom-select-styles', PINPOINTE_PLUGIN_URL . '/assets/css/tom-select/tom-select.css', array(), '2.3.1');
            wp_register_script('pinpointe-tom-select-scripts', PINPOINTE_PLUGIN_URL . '/assets/js/tom-select.js',
                array(), '2.3.1',$this->register_scripts_args);
            
            // Our own scripts and styles
            wp_register_script('pinpointe-admin-scripts', PINPOINTE_PLUGIN_URL . '/assets/js/pinpointe-admin.js',
                array('jquery'), PINPOINTE_VERSION,$this->register_scripts_args);
            wp_register_style('pinpointe-admin-styles', PINPOINTE_PLUGIN_URL . '/assets/css/style-admin.css', array(), PINPOINTE_VERSION);
            wp_register_style('pinpointe-jquery-ui-styles', PINPOINTE_PLUGIN_URL . '/assets/css/jquery-ui.theme.css', array(), PINPOINTE_VERSION);
            
            // Scripts
            wp_enqueue_script('jquery');
            wp_enqueue_script('jquery-ui-core');
            wp_enqueue_script('jquery-ui-accordion');
            wp_enqueue_script('jquery-ui-sortable');
            wp_enqueue_script('jquery-ui-tooltip');
            wp_enqueue_script('pinpointe-admin-scripts');
            wp_enqueue_script('pinpointe-tom-select-scripts');

            // Styles
            wp_enqueue_style('pinpointe-jquery-ui-styles');
            wp_enqueue_style('pinpointe-font-awesome');
            wp_enqueue_style('pinpointe-admin-styles');
            wp_enqueue_style('pinpointe-tom-select-styles');
        }

        /**
         * Load scripts required for frontend
         *
         * @access public
         * @return void
         */
        public function enqueue_frontend_scripts_and_styles()
        {
            if (!$this->opt['pinpointe_api_key'] || !isset($this->opt['forms']) || empty($this->opt['forms'])) {
                return;
            }

            // js cookie
            if (!wp_script_is('js-cookie', 'enqueued')) {
                wp_register_script('js-cookie', PINPOINTE_PLUGIN_URL . '/assets/js/js.cookie.js', array(), '3.0.4',
                    $this->register_scripts_args);
                wp_enqueue_script('js-cookie');
            }

            // Chimpy frontend scripts
            wp_register_script('pinpointe-frontend', PINPOINTE_PLUGIN_URL . '/assets/js/pinpointe-frontend.js', array('jquery'), PINPOINTE_VERSION,$this->register_scripts_args);
            wp_enqueue_script('pinpointe-frontend');

            // Chimpy frontent styles
            wp_register_style('pinpointe', PINPOINTE_PLUGIN_URL . '/assets/css/style-frontend.css', array(), PINPOINTE_VERSION);
            wp_enqueue_style('pinpointe');

            // Font awesome (icons)
            wp_register_style('pinpointe-font-awesome', PINPOINTE_PLUGIN_URL . '/assets/css/font-awesome/css/font-awesome.min.css', array(), '6.5.2');
            wp_enqueue_style('pinpointe-font-awesome');

            // Sky Forms scripts
            wp_register_script('pinpointe-sky-forms', PINPOINTE_PLUGIN_URL . '/assets/forms/js/jquery.form.min.js', array('jquery'), '4.3.0',$this->register_scripts_args);
            wp_enqueue_script('pinpointe-sky-forms');

            // Sky Forms scripts - validate
            wp_register_script('pinpointe-sky-forms-validate', PINPOINTE_PLUGIN_URL . '/assets/forms/js/jquery.validate.min.js', array('jquery'), '1.20.0',$this->register_scripts_args);
            wp_enqueue_script('pinpointe-sky-forms-validate');

            // Sky Forms scripts - masked input
            wp_register_script('pinpointe-sky-forms-maskedinput', PINPOINTE_PLUGIN_URL . '/assets/forms/js/jquery.maskedinput.min.js', array('jquery'), '1.3.1',$this->register_scripts_args);
            wp_enqueue_script('pinpointe-sky-forms-maskedinput');

            // Sky Forms main styles
            wp_register_style('pinpointe-sky-forms-style', PINPOINTE_PLUGIN_URL . '/assets/forms/css/sky-forms.css', array(), PINPOINTE_VERSION);
            wp_enqueue_style('pinpointe-sky-forms-style');

            // Sky Forms color schemes
            wp_register_style('pinpointe-sky-forms-color-schemes', PINPOINTE_PLUGIN_URL . '/assets/forms/css/sky-forms-color-schemes.css', array(), PINPOINTE_VERSION);
            wp_enqueue_style('pinpointe-sky-forms-color-schemes');

            // Check browser version
            if (strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE 6.') !== false) {
                $ie = 6;
            }
            else if (strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE 7.') !== false) {
                $ie = 7;
            }
            else if (strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE 8.') !== false) {
                $ie = 8;
            }
            else if (strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE 9.') !== false) {
                $ie = 9;
            }
            else {
                $ie = false;
            }

            // For IE < 9
            if ($ie && $ie < 9) {

                // Additional scripts
                wp_register_script('pinpointe-sky-forms-ie8', PINPOINTE_PLUGIN_URL . '/assets/forms/js/sky-forms-ie8.js', array('jquery'), PINPOINTE_VERSION,$this->register_scripts_args);
                wp_enqueue_script('pinpointe-sky-forms-ie8');

                // Additional styles
                wp_register_style('pinpointe-sky-forms-style-ie8', PINPOINTE_PLUGIN_URL . '/assets/forms/css/sky-forms-ie8.css', array(), PINPOINTE_VERSION);
                wp_enqueue_style('pinpointe-sky-forms-style-ie8');
            }

            // For IE < 10
            if ($ie && $ie < 10) {

                // Placeholder
                wp_register_script('pinpointe-sky-forms-placeholder', PINPOINTE_PLUGIN_URL . '/assets/forms/js/jquery.placeholder.min.js', array('jquery'), PINPOINTE_VERSION,$this->register_scripts_args);
                wp_enqueue_script('pinpointe-sky-forms-placeholder');

                // HTM5 Shim
                wp_register_script('pinpointe-sky-forms-html5shim', PINPOINTE_PLUGIN_URL . '/assets/forms/js/html5.js', array('jquery'), '3.7.3',$this->register_scripts_args);
                wp_enqueue_script('pinpointe-sky-forms-html5shim');
            }

        }

        /**
         * Add settings link on plugins page
         * (Note to myself: won't display if plugin is included as a symlink due to __FILE__)
         *
         * @access public
         * @return void
         */
        public function plugin_settings_link($links, $file)
        {
            if ($file == plugin_basename(__FILE__)){
                $settings_link = '<a href="https://help.pinpointe.com/support/tickets/new" target="_blank">'.__('Support', 'pinpointe').'</a>';
                array_unshift($links, $settings_link);
                $settings_link = '<a href="options-general.php?page=pinpointe">'.__('Settings', 'pinpointe').'</a>';
                array_unshift($links, $settings_link);
            }
            return $links;
        }

        /**
         * Handle plugin uninstall
         *
         * @access public
         * @return void
         */
        public function uninstall()
        {
            delete_option('pinpointe_options');
            delete_option('widget_pinpointe_form');
        }

        /**
         * Get lists from Pinpointe
         *
         * @access public
         * @return void
         */
        public function ajax_get_lists()
        {
            check_ajax_referer(__FUNCTION__, '_wpnonce');
            // Get lists
            $lists = $this->get_lists();
            $lists = array_map('sanitize_text_field', $lists);
            echo wp_json_encode(array('message' => array('lists' => $lists)));
            die();
        }

        /**
         * Get tags from Pinpointe
         *
         * @access public
         * @return boid
         */
        public function ajax_get_tags()
        {
            // Get lists
            $tags = $this->get_tags();
            array_walk_recursive($tags, function (&$value) {
                $value = is_string($value) ? sanitize_text_field($value) : $value;
            });
            
            echo wp_json_encode(array('message' => array('tags' => $tags)));
            die();
        }

        /**
         * Get all lists plus groups and fields for selected lists in array
         *
         * @access public
         * @return void
         */
        public function ajax_get_lists_groups_fields()
        {
            check_ajax_referer(__FUNCTION__, '_wpnonce');
            
            if (isset($_POST['data'])) {
                // Sanitize input data
                $data = array_map('wp_unslash', $_POST['data']);
                $data = array_map(function($item) {
                    if (is_array($item)) {
                        // Handle inner arrays recursively
                        $item = array_map(function($innerItem) {
                            if (is_array($innerItem)) {
                                // Merge variables
                                if (isset($innerItem['name'], $innerItem['tag'], $innerItem['icon'], $innerItem['type'], $innerItem['req'], $innerItem['us_phone'])) {
                                    return array_map('sanitize_text_field', $innerItem);
                                } elseif (isset($innerItem['name'], $innerItem['value'])) {
                                    unset($innerItem['value']);
                                    return array_map('sanitize_text_field', $innerItem);
                                }
                            }
                            return $innerItem;
                        }, $item);
                    }
                    return is_string($item) ? sanitize_text_field($item) : wp_unslash($item);
                }, $data);
            }
            else {
                $data = array();
            }

            // Get lists
            $lists = $this->get_lists();
            $lists = array_map('sanitize_text_field', $lists);

            // Check if we have something pre-selected
            if (!empty($data)) {

                $lists_to_get = array();

                // Get groups
                foreach ($data as $form_key => $form) {
                    $lists_to_get[] = $form['list'];
                }
				
				// Get merge vars
				// only fetch merge_vars with lists in lists_to_get instead of every list.
				$merge = $this->get_merge_vars(array_intersect_key($lists, array_flip($lists_to_get)));

            }
            if (!empty($merge)) {
                array_walk_recursive($merge, static function (&$value, $key) {
                    if (is_string($value)) {
                        $value = sanitize_text_field($value);
                    } elseif (is_bool($value)) {
                        $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    }
                });
            }

            echo wp_json_encode(array('message' => array('lists' => $lists, 'groups' => null, 'merge' => $merge)));
            die();
        }

        /**
         * Return all lists from Pinpointe to be used in select fields
         *
         * @access public
         * @return array
         */
        public function get_lists()
        {
            $this->load_pinpointe();

            try {
                if (!$this->pinpointe) {
                    throw new Exception(__('Unable to load lists', 'pinpointe'));
                }

                $lists = $this->pinpointe->lists_get_list();

                if (count($lists) < 1) {
                    throw new Exception(__('No lists found', 'pinpointe'));
                }

                $results = array('' => '');

                foreach ($lists as $list) {
                    $results[$list['listid']] = $list['name'];
                }

                return $results;
            }
            catch (Exception $e) {
                return array('' => '');
            }
        }

        /**
         * Get all tags plus groups and fields for selected lists in array
         *
         * @access public
         * @return void
         */
        public function ajax_get_tags_groups_fields()
        {
            check_ajax_referer(__FUNCTION__, '_wpnonce');
            // Get tags
            $tags = $this->get_tags();

            echo wp_json_encode(array('message' => compact('tags')));
            die();
        }

        /**
         * Return all tags from Pinpointe to be used in select fields
         *
         * @access public
         * @return array
         */
        public function get_tags()
        {
            $this->load_pinpointe();

            try {
                if (!$this->pinpointe) {
                    throw new Exception(__('Unable to load tags', 'pinpointe'));
                }

                $tags = $this->pinpointe->tags_get_tag();

                if (count($tags) < 1) {
                    throw new Exception(__('No tags found', 'pinpointe'));
                }

                $results = [];

                foreach ($tags as $tag) {
                    $tagInfo = array(
                        'name' => $tag['name'],
                        'description' => isset($tag['description']) || !empty($tag['description']) ? $tag['description'] : 'Default description for ' . $tag['name']
                    );
					
					if (isset($tag['tagid'])) { // for xml api
						$results[$tag['tagid']] = $tagInfo;
					} else { // for json api
						$results[$tag['id']] = $tagInfo;
					}
                
                }

                return $results;
            }
            catch (Exception $e) {
                return array('' => '');
            }
        }

        /**
         * Return all merge vars for all available lists
         *
         * @access public
         * @param array $lists
         * @return array
         */
        public function get_merge_vars($lists)
        {
            $this->load_pinpointe();

            // Unset blank list
            unset($lists['']);

            // Pre-populate results array with list ids as keys
            $results = array();

            foreach (array_keys($lists) as $list) {
                $results[$list] = array();
            }

            try {

                if (!$this->pinpointe) {
                    throw new Exception(__('Unable to load merge vars', 'pinpointe'));
                }

                $merge_vars = $this->pinpointe->lists_merge_vars(array_keys($lists));

                if (!$merge_vars || empty($merge_vars)) {
                    throw new Exception(__('No merge vars found', 'pinpointe'));
                }

                foreach ($merge_vars as $site => $merge_var) {
                    foreach ($merge_var as $id => $var) {
                        // Skip address field (may be added later)
                        if ($var['field_type'] == 'address') {
                            continue;
                        }

                        $results[$site][$id] = array(
                            'name'  => $var['name'],
                            'req'   => ($var['req'] ? true : false),
                            'type'  => $var['field_type'],           // enum: email, text, number, radio, dropdown, date, birthday, zip, phone, url
                        );

                        // Add choices (for radio, dropdown)
                        $results[$site][$id]['choices'] = (isset($var['choices']) && !empty($var['choices'])) ? join('%%%', $var['choices']) : '';

                        // US phone format?
                        $results[$site][$id]['us_phone'] = isset($var['phoneformat']) && $var['phoneformat'] == 'US' ? true : false;
                    }
                }

                return $results;
            }
            catch (Exception $e) {
                return $results;
            }
        }

        /**
         * Return all groupings/groups from Pinpointe to be used in select fields
         *
         * @access public
         * @param mixed $list_id
         * @return array
         */
        public function get_groups($list_id)
        {
            $this->load_pinpointe();
            return;

            try {

                if (!$this->pinpointe) {
                    throw new Exception(__('Unable to load groups', 'pinpointe'));
                }

                // Single list?
                if (in_array(gettype($list_id), array('integer', 'string'))) {
                    $groupings = $this->pinpointe->lists_interest_groupings($list_id);

                    if (!$groupings || empty($groupings)) {
                        throw new Exception(__('No groups found', 'pinpointe'));
                    }

                    $results = array('' => '');

                    foreach ($groupings as $grouping) {
                        foreach ($grouping['groups'] as $group) {
                            $results[$grouping['id'] . '%%%' . htmlspecialchars($grouping['name'], ENT_QUOTES) . '%%%' . htmlspecialchars($group['name'], ENT_QUOTES)] = htmlspecialchars($grouping['name'], ENT_QUOTES) . ': ' . htmlspecialchars($group['name'], ENT_QUOTES);
                        }
                    }
                }

                // Multiple lists...
                else {

                    $results = array();

                    foreach ($list_id as $list_id_value) {

                        $results[$list_id_value] = array('' => '');

                        try {
                            $groupings = $this->pinpointe->lists_interest_groupings($list_id_value);
                        }
                        catch(Exception $e) {
                            continue;
                        }

                        if (!$groupings || empty($groupings)) {
                            continue;
                        }

                        foreach ($groupings as $grouping) {
                            foreach ($grouping['groups'] as $group) {
                                $results[$list_id_value][$grouping['id'] . '%%%' . htmlspecialchars($grouping['name'], ENT_QUOTES) . '%%%' . htmlspecialchars($group['name'], ENT_QUOTES)] = htmlspecialchars($grouping['name'], ENT_QUOTES) . ': ' . htmlspecialchars($group['name'], ENT_QUOTES);
                            }
                        }
                    }
                }

                return $results;
            }
            catch (Exception $e) {
                return array();
            }
        }

        /**
         * Ajax - Return Pinpointe groups and tags as array for multiselect field
         */
        public function ajax_groups_and_tags_in_array()
        {
            check_ajax_referer(__FUNCTION__, '_wpnonce');
            // Check if we have received required data
            if (isset($_POST['data']['list'])) {
                $merge_vars = $this->get_merge_vars(array(sanitize_text_field($_POST['data']['list']) => ''));
            }
            else {
                $merge_vars = array('' => '');
            }

            echo wp_json_encode(array('message' => array('groups' => ['' => ''], 'merge' => $merge_vars)));
            die();
        }

        /**
         * Test Pinpointe key and connection
         *
         * @access public
         * @return bool
         */
        public function test_pinpointe($key = null)
        {
            // Try to get key from options if not set
            if ($key == null) {
                $key = $this->opt['pinpointe_api_key'];
            }

            // Check if api key is set now
            if (empty($key)) {
                return __('No API key provided', 'pinpointe');
            }

            // Check if curl extension is loaded
            if (!function_exists('curl_exec')) {
                return __('PHP Curl extension not loaded on your server', 'pinpointe');
            }

            // Load Pinpointe Wrapper
            if (!class_exists('PinpointeService')) {
                require_once PINPOINTE_PLUGIN_PATH . '/includes/pinpointe-service.class.php';
            }

            // Try to initialize the Pinpointe service
            $this->pinpointe = new PinpointeService($key);
            try {
                $this->pinpointe = new PinpointeService();
                $this->pinpointe->setCredentials($this->opt['pinpointe_username'], $this->opt['pinpointe_api_key']);
                $this->pinpointe->setApiUrl($this->opt['pinpointe_api_url']);
            }
            catch (Exception $e) {
                return __('Unable to initialize Pinpointe service class', 'pinpointe');
            }

            // Ping
            try {
                $results = $this->pinpointe->helper_ping();
                return $results;
                //throw new Exception($results['msg']);
            }
            catch (Exception $e) {
                if (strpos($e->getMessage(), 319) === false) {
                    return $e->getMessage();
                }
                else {
                    return 'Incorrect username or API key';
                }
            }

            return __('Something went wrong...', 'pinpointe');
        }

        /**
         * Get Pinpointe account details
         *
         * @access public
         * @return mixed
         */
        public function get_pinpointe_account_info()
        {
            if ($this->load_pinpointe()) {
                try {
                    $results = $this->pinpointe->helper_account_details();
                    return $results;
                }
                catch (Exception $e) {
                    return false;
                }
            }

            return false;
        }

        /**
         * Load Pinpointe object
         *
         * @access public
         * @return mixed
         */
        public function load_pinpointe()
        {
            if ($this->pinpointe) {
                return true;
            }

            // Load Pinpointe Wrapper
            if (!class_exists('PinpointeService')) {
                require_once PINPOINTE_PLUGIN_PATH . '/includes/pinpointe-service.class.php';
            }

            try {
                $this->pinpointe = new PinpointeService();
                $this->pinpointe->setCredentials($this->opt['pinpointe_username'], $this->opt['pinpointe_api_key']);
                $this->pinpointe->setApiUrl($this->opt['pinpointe_api_url']);
                return true;
            }
            catch (Exception $e) {
                return false;
            }
        }

        /**
         * Ajax - Render Pinpointe status
         *
         * @access public
         * @return void
         */
        public function ajax_pinpointe_status()
        {
            check_ajax_referer(__FUNCTION__, '_wpnonce');
            if (!$this->load_pinpointe()) {
                $message = '<h4 style="margin:0px;"><i class="fa-solid fa-check" style="font-size: 1.5em; color: green;"></i>&nbsp;&nbsp;&nbsp;' . __('Pinpointe credentials could not be set', 'pinpointe') .  '</h4>';
            }
            else if ( ($msg = $this->test_pinpointe()) !== true) {
                $message = '<h4 style="margin:0px;"><i class="fa-solid fa-xmark" style="font-size: 1.5em; color: red;"></i>&nbsp;&nbsp;&nbsp;' . $msg . '</h4>';
            }
            else if ($account_info = $this->get_pinpointe_account_info()) {
                $message =  '<h4 style="margin:0px;"><i class="fa-solid fa-check" style="font-size: 1.5em; color: green;"></i>&nbsp;&nbsp;&nbsp;' . __('Connected to account', 'pinpointe') . ' ' . $account_info['username'] . '</h4>';
            }
            else {
                $message = '<h4 style="margin:0px;"><i class="fa-solid fa-xmark" style="font-size: 1.5em; color: red;"></i>&nbsp;&nbsp;&nbsp;' . __('Connection to Pinpointe failed.', 'pinpointe') . '</h4>';
            }

            echo wp_json_encode(array('message' => $message));
            die();
        }

        /**
         * Check if curl is enabled
         *
         * @access public
         * @return void
         */
        public function curl_enabled()
        {
            if (function_exists('curl_version')) {
                return true;
            }

            return false;
        }

        /**
         * Select form based on conditions and request data
         *
         * @access public
         * @param array $forms
         * @return mixed
         */
        public static function select_form_by_conditions($forms, $allowed_forms = array())
        {
            $selected_form = [];

            // Iterate over forms and return the first form that matches conditions
            foreach ($forms as $form_key => $form) {

                // Check if form is enabled and has mailing list set
                if ($form['condition'] === 'disable' || empty($form['list'])) {
                    continue;
                }

                // Check if form is allowed by user
                if (is_array($allowed_forms) && !empty($allowed_forms)) {
                    if (!in_array((int)$form_key, $allowed_forms)) {
                        continue;
                    }
                }

                // Switch all possible scenarios
                switch ($form['condition']['key']) {

                    /**
                     * ALWAYS
                     */
                    case 'always':
                        $selected_form[$form_key] = $form;
                        break;

                    /**
                     * FRONT PAGE
                     */
                    case 'front':
                        if (is_front_page()) {
                            $selected_form[$form_key] = $form;
                        }
                        break;

                    /**
                     * PAGES/POSTS
                     */
                    case 'posts':
                    case 'pages':
                        global $post;

                        // Check if we have any pages selected
                        if (!$post || !isset($post->ID) || !array($form['condition']['value']) || empty($form['condition']['value'])) {
                            break;
                        }

                        // Check if current post is within selected posts
                        if (in_array($post->ID, $form['condition']['value'])) {
                            $selected_form[$form_key] = $form;
                        }

                        break;

                    /**
                     * PAGES NOT
                     */
                    case 'pages_not':
                        global $post;

                        // Check if we have any pages selected
                        if (!$post || !isset($post->ID) || !array($form['condition']['value']) || empty($form['condition']['value'])) {
                            $selected_form[$form_key] = $form;
                            break;
                        }

                        // Check if current post is NOT within selected posts
                        if (!in_array($post->ID, $form['condition']['value'])) {
                            $selected_form[$form_key] = $form;
                        }

                        break;
                        
                    /**
                     * POSTS NOT
                     */
                    case 'posts_not':
                        global $post;

                        // Check if we have any pages selected
                        if (!$post || !isset($post->ID) || !array($form['condition']['value']) || empty($form['condition']['value'])) {
                            $selected_form[$form_key] = $form;
                            break;
                        }

                        // Check if current post is within selected posts
                        if (!in_array($post->ID, $form['condition']['value'])) {
                            $selected_form[$form_key] = $form;
                        }

                        break;

                    /**
                     * CATEGORIES
                     */
                    case 'categories':
                        global $post;

                        // Check if we have any categories selected
                        if (!$post || !isset($post->ID) || !array($form['condition']['value']) || empty($form['condition']['value']) || is_front_page()) {
                            break;
                        }

                        // Get all categories with children
                        $category_with_children_ids = self::get_categories_with_children($form['condition']['value']);

                        if (!is_array($category_with_children_ids) || empty($category_with_children_ids)) {
                            break;
                        }

                        // Get all categories that this post is associated with
                        $post_category_ids = wp_get_post_categories($post->ID);

                        if (!is_array($post_category_ids) || empty($post_category_ids)) {
                            break;
                        }

                        // Check if there's at least one category match
                        foreach ($category_with_children_ids as $single_cat_id) {
                            if (in_array($single_cat_id, $post_category_ids)) {
                                $selected_form[$form_key] = $form;
                                break;
                            }
                        }

                        break;

                    /**
                     * CATEGORIES NOT
                     */
                    case 'categories_not':
                        global $post;

                        // Check if we have any categories selected
                        if (!$post || !isset($post->ID) || !array($form['condition']['value']) || empty($form['condition']['value'])) {
                            $selected_form[$form_key] = $form;
                            break;
                        }

                        // Get all categories with children
                        $category_with_children_ids = self::get_categories_with_children($form['condition']['value']);

                        if (!is_array($category_with_children_ids) || empty($category_with_children_ids)) {
                            $selected_form[$form_key] = $form;
                            break;
                        }

                        // Get all categories that this post is associated with
                        $post_category_ids = wp_get_post_categories($post->ID);

                        if (!is_array($post_category_ids) || empty($post_category_ids)) {
                            $selected_form[$form_key] = $form;
                            break;
                        }

                        // Make sure there are NO matches
                        $found = false;

                        foreach ($category_with_children_ids as $single_cat_id) {
                            if (in_array($single_cat_id, $post_category_ids)) {
                                $found = true;
                                break;
                            }
                        }

                        if (!$found) {
                            $selected_form[$form_key] = $form;
                        }

                        break;

                    /**
                     * URL
                     */
                    case 'url':
                        $request_url = sanitize_url(($_SERVER['HTTPS'] ? 'https' : 'http') . '://' .$_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);

                        if (preg_match('/' . preg_quote($form['condition']['value']) . '/i', $request_url)) {
                            $selected_form[$form_key] = $form;
                        }

                        break;

                    /**
                     * URL NOT
                     */
                    case 'url_not':
                        $request_url = sanitize_url(($_SERVER['HTTPS'] ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);

                        if (!preg_match('/' . preg_quote($form['condition']['value']) . '/i', $request_url)) {
                            $selected_form[$form_key] = $form;
                        }

                        break;

                    /**
                     * DEFAULT
                     */
                    default:
                        break;
                }

                // Check if we have selected this form
                if (!empty($selected_form)) {
                    break;
                }
            }

            return $selected_form;
        }

        /**
         * Get array of category ids with all child categories
         *
         * @access public
         * @param array $category_ids
         * @return array
         */
        public static function get_categories_with_children($category_ids)
        {
            $categories_with_children = array();

            // Get all child categories
            foreach ($category_ids as $category_id) {
                $categories_with_children[] = $category_id;

                $current_category = get_category($category_id);

                if (!$current_category) {
                    continue;
                }

                $child_categories = get_categories(array(
                    'type'          => 'post',
                    'child_of'      => $category_id,
                    'hide_empty'    => 0,
                ));

                if (!is_array($child_categories) || empty($child_categories)) {
                    continue;
                }

                foreach ($child_categories as $child_category) {
                    $categories_with_children[] = $child_category->term_id;
                }

            }

            return $categories_with_children;
        }

        /**
         * Display form after content
         *
         * @access public
         * @param $content
         * @return string
         */
        public function form_after_content($content)
        {
            if (is_archive() || is_search() || is_home() || is_front_page() || is_feed()) {
                return $content;
            }

            // Check if integration is enabled and at least one form configured
            if (!$this->opt['pinpointe_api_key'] || !isset($this->opt['forms']) || empty($this->opt['forms'])) {
                return $content;
            }

            // Check if form below content is enabled
            if (!(is_single() && in_array('1', $this->opt['pinpointe_after_posts_post_types'])) && !(is_page() && in_array('2', $this->opt['pinpointe_after_posts_post_types']))) {
                return $content;
            }

            // Select form that match the conditions best
            $form = self::select_form_by_conditions($this->opt['forms'], $this->opt['pinpointe_after_posts_allowed_forms']);

            if (!$form) {
                return $content;
            }

            require_once PINPOINTE_PLUGIN_PATH . '/includes/pinpointe-prepare-form.inc.php';

            $form_html = pinpointe_prepare_form($form, $this->opt, 'after_posts');

            return $content . $form_html;
        }

        /**
         * Form shortcode handler
         *
         * @access public
         * @param mixed $attributes
         * @return void
         */
        public function powered_by_shortcode($attributes)
        {
            return '<strong>Email marketing powered by <a style="display: inline-block; text-decoration: none; font-family: Helvetica, Arial, sans-serif; color: #262f57;" href="https://www.pinpointe.com/">Pinpointe</a></strong>';
        }

        /**
         * Form shortcode handler
         *
         * @access public
         * @param mixed $attributes
         * @return void
         */
        public function email_sent_to_shortcode($attributes)
        {
            return '<small style="font-size: 86%; font-weight: normal;">This email was sent to <a style="display: inline-block; text-decoration: none; font-family: Helvetica, Arial, sans-serif; color: #7a7a7a;" href="mailto:%%emailaddress%%">%%emailaddress%%</a>.</small>';
        }

        /**
         * Form shortcode handler
         *
         * @access public
         * @param mixed $attributes
         * @return void
         */
        public function confirm_link_shortcode($attributes)
        {
            return '<small style="font-size: 86%; font-weight: normal;">If the above link does not work, copy and paste the following link into your browser:<br/><a style="display: inline-block; text-decoration: none; font-family: Helvetica, Arial, sans-serif; color: #262f57;" href="%%CONFIRMLINK%%">%%CONFIRMLINK%%</a></small>';
        }

        /**
         * Form shortcode handler
         *
         * @access public
         * @param mixed $attributes
         * @return void
         */
        public function confirm_link_href_shortcode($attributes)
        {
            return '%%CONFIRMLINK%%';
        }

        /**
         * Form shortcode handler
         *
         * @access public
         * @param mixed $attributes
         * @return void
         */
        public function subscription_shortcode($attributes)
        {
            // Make sure this is not a feed
            if (is_home() || is_archive() || is_search() || is_feed()) {
                return '';
            }

            // Check if integration is enabled and at least one form configured
            if (!$this->opt['pinpointe_api_key'] || !isset($this->opt['forms']) || empty($this->opt['forms'])) {
                return '';
            }

            // Extract attributes
            extract(shortcode_atts(array(
                'forms' => ''
            ), $attributes));

            if ($forms != '') {
                $forms = preg_split('/,/', $forms);
                $normalized_allowed_forms = array();

                foreach ($forms as $allowed_form) {
                    if (is_numeric($allowed_form)) {
                        $normalized_allowed_forms[] = (int)$allowed_form;
                    }
                }

                $allowed_forms = $normalized_allowed_forms;
            }
            else {
                $allowed_forms = array();
            }

            // Select form that match the conditions best
            $form = self::select_form_by_conditions($this->opt['forms'], $allowed_forms);

            if (!$form) {
                return '';
            }

            require_once PINPOINTE_PLUGIN_PATH . '/includes/pinpointe-prepare-form.inc.php';

            $form_html = pinpointe_prepare_form($form, $this->opt, 'shortcode');

            return $form_html;
        }

        /**
         * Subscribe user to mailing list
         *
         * @access public
         * @param string $list_id
         * @param string $email
         * @param array $groups
         * @param array $custom_fields
         * @param bool $is_backend
         * @return mixed
         */
        public function subscribe($list_id, $email, $groups, $custom_fields, $is_backend = false, $tags = [], $send_confirmation = false, $custom_data = [] )
        {
            // Load Pinpointe
            if (!$this->load_pinpointe()) {
                return false;
            }

            $groupings = array();

            // Any groups to be set?
            if (!empty($groups)) {

                // First make an acceptable structure
                $groups_parent_children = array();

                foreach ($groups as $group) {
                    $parts = preg_split('/%%%/', htmlspecialchars_decode($group, ENT_QUOTES));

                    if (count($parts) == 3) {
                        $groups_parent_children[$parts[0]][] = $parts[2];
                    }
                }

                // Now populate groupings array
                foreach ($groups_parent_children as $parent => $child) {
                    $groupings[] = array(
                        'id' => $parent,
                        'groups' => $child
                    );
                }
            }

            // All merge vars
            $merge_vars = array(
                // 'groupings' => $groupings,
            );

            foreach ($custom_fields as $key => $value) {
                $merge_vars[$key] = $value;
            }

            // Opt-in IP and time
            if (!is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
                // $merge_vars['optin_ip'] = $this->get_visitor_ip();
                // $merge_vars['optin_time'] = date('Y-m-d H:i:s');
            }

            // Subscribe
            try {
                $results = $this->pinpointe->lists_subscribe(
                    $list_id,
                    array('email' => $email),
                    $merge_vars,
                    'html',
                    $this->opt['pinpointe_add_to_autoresponders'],
                    $this->opt['pinpointe_update_existing'],
                    $tags,
                    $send_confirmation,
                    $custom_data
                );

                if ($is_backend) {
                    return $results;
                }

                return true;
            }
            catch (Exception $e) {
                if (preg_match('/.+already exists.+/', $e->getMessage()) && !$this->opt['pinpointe_update_existing'] && !$is_backend) {
                    return $this->opt['pinpointe_label_already_subscribed'];
                }

                return false;
            }
        }

        /**
         * Update subscription (subscriber details)
         *
         * @access public
         * @param string $sync_key
         * @param string $list_id
         * @param array $groups
         * @param array $custom_fields
         * @return mixed
         */
        public function update_subscription($sync_key, $list_id, $groups, $custom_fields)
        {
            // Load Pinpointe
            if (!$this->load_pinpointe()) {
                return false;
            }

            $groupings = array();

            // Any groups to be set?
            if (!empty($groups)) {

                // First make an acceptable structure
                $groups_parent_children = array();

                foreach ($groups as $group) {
                    $parts = preg_split('/%%%/', htmlspecialchars_decode($group, ENT_QUOTES));

                    if (count($parts) == 3) {
                        $groups_parent_children[$parts[0]][] = $parts[2];
                    }
                }

                // Now populate groupings array
                foreach ($groups_parent_children as $parent => $child) {
                    $groupings[] = array(
                        'id' => $parent,
                        'groups' => $child
                    );
                }
            }

            // All merge vars
            $merge_vars = array(
                'groupings' => $groupings,
            );

            foreach ($custom_fields as $key => $value) {
                $merge_vars[$key] = $value;
            }

            // Update subscription
            try {
                $results = $this->pinpointe->lists_subscribe(
                    $list_id,
                    array('leid' => $sync_key),
                    $merge_vars,
                    'html',
                    false,
                    true,
                    $this->opt['pinpointe_replace_groups'],
                    false
                );

                return true;
            }
            catch (Exception $e) {
                return false;
            }
        }

        /**
         * Unsubscribe user
         *
         * @access public
         * @param string $list_id
         * @param string $key
         * @param bool $is_key_email
         * @return mixed
         */
        public function unsubscribe($list_id, $key, $is_key_email)
        {
            // Load Pinpointe
            if (!$this->load_pinpointe()) {
                return false;
            }

            $handle = array();

            if ($is_key_email) {
                $handle['email'] = $key;
            }
            else {
                $handle['leid'] = $key;
            }

            // Unsubscribe
            try {
                $results = $this->pinpointe->lists_unsubscribe(
                    $list_id,
                    $handle,
                    false,
                    false,
                    false
                );

                return true;
            }
            catch (Exception $e) {
                return false;
            }
        }

        /**
         * Ajax - process subscription
         *
         * @access public
         * @return void
         */
        public function ajax_subscribe()
        {
            // Check if integration is enabled
            if (!$this->opt['pinpointe_api_key'] || !isset($this->opt['forms']) || !is_array($this->opt['forms']) || empty($this->opt['forms'])) {
                $this->respond_with_error();
            }

            // Check if data has been received
            if (!isset($_POST['data'])) {
                $this->respond_with_error();
            }

            $data = array();
            parse_str($_POST['data'], $data);

            // Get context
            if (isset($data['pinpointe_widget_subscribe'])) {
                $context = 'widget';
            }
            else if (isset($data['pinpointe_after_posts_subscribe'])) {
                $context = 'after_posts';
            }
            else if (isset($data['pinpointe_popup_subscribe'])) {
                $context = 'popup';
            }
            else if (isset($data['pinpointe_shortcode_subscribe'])) {
                $context = 'shortcode';
            }
            else if (isset($data['pinpointe_lock_subscribe'])) {
                $context = 'lock';
            }
            else {
                $this->respond_with_error();
            }

            $data = $data['pinpointe_' . $context . '_subscribe'];

            // Load form
            if (isset($data['form']) && isset($this->opt['forms'][$data['form']])) {
                $form = $this->opt['forms'][$data['form']];
            }
            else {
                $this->respond_with_error();
            }

            // Check if form is enabled
            if ($form['condition'] == 'disable' || !isset($form['list']) || empty($form['list'])) {
                $this->respond_with_error();
            }

            // Parse custom fields
            $custom_fields = array();
            $email = null;

            if (isset($form['fields']) && is_array($form['fields']) && !empty($form['fields'])) {
                if (!isset($data['custom']) || !is_array($data['custom']) || empty($data['custom'])) {
                    $this->respond_with_error();
                }

                foreach ($form['fields'] as $field) {

                    // Value missing?
                    if (!isset($data['custom'][$field['tag']]) || $data['custom'][$field['tag']] == '') {
                        if ($field['req']) {
                            $this->respond_with_error();
                        }
                        else {
                            continue;
                        }
                    }

                    // If it's email address, extract it
                    if ($field['type'] == 'email') {
                        $email = $data['custom'][$field['tag']];
                        continue;
                    }

                    // If it's dropdown or radio - extract actual value
                    else if (in_array($field['type'], array('dropdown', 'radiobutton'))) {
                        if (isset($field['choices'][$data['custom'][$field['tag']]])) {
                            $custom_fields[$field['tag']] = $field['choices'][$data['custom'][$field['tag']]];
                        }
                        else {
                            if ($field['reg']) {
                                $this->respond_with_error();
                            }
                            else {
                                continue;
                            }
                        }
                    }

                    else if ($field['type'] == 'checkbox') {
                        if (isset($data['custom'][$field['tag']])) {
                            $custom_fields[$field['tag']] = $data['custom'][$field['tag']];
                        }
                        else {
                            if ($field['reg']) {
                                $this->respond_with_error();
                            }
                            else {
                                continue;
                            }
                        }
                    }

                    // If it's date - change to acceptable format
                    else if ($field['type'] == 'date') {

                        // Get date format
                        $date_format = self::get_date_pattern('date', $this->opt['pinpointe_date_format'], 'date_format');

                        if (version_compare(phpversion(), '7.2.0', '>=')){
                            $date_parsed = date_parse_from_format($date_format, $data['custom'][$field['tag']]);
                        }
                        else{
                            // Parse to array with date details (using strptime to support PHP 5.2)
                            $date_parsed = strptime($data['custom'][$field['tag']], $date_format);
                        }

                        $date_parsed = date_parse_from_format($date_format, $data['custom'][$field['tag']]);

                        if (!$date_parsed) {
                            if ($field['reg']) {
                                $this->respond_with_error();
                            }
                            else {
                                continue;
                            }
                        }

                        $custom_fields[$field['tag']] = ($date_parsed['tm_year'] + 1900) . '-' . ($date_parsed['tm_mon'] + 1 < 10 ? '0' : '') . ($date_parsed['tm_mon'] + 1) . '-' . (($date_parsed['tm_mday'] < 10 ? '0' : '') . $date_parsed['tm_mday']);
                    }

                    // If it's birthday - change to acceptable format
                    else if ($field['type'] == 'birthday') {

                        if (strlen($data['custom'][$field['tag']]) != 5) {
                            if ($field['reg']) {
                                $this->respond_with_error();
                            }
                            else {
                                continue;
                            }
                        }

                        // Get birthday format
                        $birthday_format = self::get_date_pattern('birthday', $this->opt['pinpointe_birthday_format'], 'date_format');

                        $month_first = in_array($birthday_format, array('%m/%d', '%m-%d', '%m.%d')) ? true : false;
                        $birthday_separator = in_array($birthday_format, array('%m/%d', '%d/%m')) ? '/' : (in_array($birthday_format, array('%m-%d', '%d-%m')) ? '-' : '.');

                        // Split to month and day
                        $birthday_parts = preg_split('/\\' . $birthday_separator . '/', $data['custom'][$field['tag']]);

                        if (count($birthday_parts) != 2 || strlen($birthday_parts[0]) != 2 || strlen($birthday_parts[1]) != 2) {
                            if ($field['reg']) {
                                $this->respond_with_error();
                            }
                            else {
                                continue;
                            }
                        }

                        // Glue them together to MM/DD
                        $custom_fields[$field['tag']] = ($month_first ? $birthday_parts[0] : $birthday_parts[1]) . '/' . ($month_first ? $birthday_parts[1] : $birthday_parts[0]);
                    }

                    // Regular field..
                    else {
                        $custom_fields[$field['tag']] = $data['custom'][$field['tag']];
                    }
                }
            }

            // Do we have email address?
            if (!$email) {
                $this->respond_with_error();
            }

            // Process groups
            $subscribe_groups = array();

            if (isset($form['group_method']) && in_array($form['group_method'], array('multi', 'single', 'single_req', 'select', 'select_req'))) {
                if (isset($data['groups']) && is_array($data['groups']) && !empty($data['groups'])) {
                    if ($form['group_method'] == 'multi') {
                        foreach ($data['groups'] as $grouping) {
                            foreach ($grouping as $group) {
                                $subscribe_groups[] = $group;
                            }
                        }
                    }
                    else {
                        foreach ($data['groups'] as $group) {
                            $subscribe_groups[] = $group;
                        }
                    }
                }
            }
            else {
                $subscribe_groups = $form['groups'];
            }

            $custom_data = [
                'html_confirmation_email' => $form['send_confirmation'] && !empty($form['html_confirmation_email']) && $form['overide_confirmation'] ? do_shortcode($form['html_confirmation_email']) : null,
            ];

            $tags = isset($data['custom']['selected_tags']) ? $data['custom']['selected_tags'] : $form['tags'];
            // Subscribe user
			$subscribe_result = $this->subscribe(
                $form['list'],
					$email,
					$subscribe_groups,
					$custom_fields,
					false,
					$tags,
					$form['send_confirmation'],
					$custom_data
			);


            if (is_bool($subscribe_result)) {
                if ($subscribe_result) {

                    $response = array(
                        'error' => 0,
                        'message' => $this->opt['pinpointe_label_success']
                    );

                    // Do we need to redirect user to some other page?
                    if (isset($form['redirect_url']) && $form['redirect_url']) {
                        $response['redirect_url'] = $form['redirect_url'];
                    }

                    // Send Ajax response
                    echo wp_json_encode($response);
                    die();
                }
                else {
                    $this->respond_with_error();
                }
            }
            else {
                echo wp_json_encode(array(
                        'error' => 1,
                        'message' => is_string($subscribe_result) ? sanitize_text_field($subscribe_result) : $subscribe_result
                ));
                die();
            }
        }

        /**
         * Respond to signup ajax request with standard error
         *
         * @access public
         * @return void
         */
        public function respond_with_error()
        {
            echo wp_json_encode(array('error' => 1, 'message' => sanitize_text_field($this->opt['pinpointe_label_error'])));
            die();
        }

        /**
         * Return list of Font Awesome icon names and unicode codes
         *
         * @access public
         * @return array
         */
        public function get_font_awesome_icons()
        {
            return array(
                'fa-glass' => '&#xf000;',
                'fa-music' => '&#xf001;',
                'fa-search' => '&#xf002;',
                'fa-envelope-o' => '&#xf003;',
                'fa-heart' => '&#xf004;',
                'fa-star' => '&#xf005;',
                'fa-star-o' => '&#xf006;',
                'fa-user' => '&#xf007;',
                'fa-film' => '&#xf008;',
                'fa-th-large' => '&#xf009;',
                'fa-th' => '&#xf00a;',
                'fa-th-list' => '&#xf00b;',
                'fa-check' => '&#xf00c;',
                'fa-times' => '&#xf00d;',
                'fa-search-plus' => '&#xf00e;',
                'fa-search-minus' => '&#xf010;',
                'fa-power-off' => '&#xf011;',
                'fa-signal' => '&#xf012;',
                'fa-cog' => '&#xf013;',
                'fa-trash-o' => '&#xf014;',
                'fa-home' => '&#xf015;',
                'fa-file-o' => '&#xf016;',
                'fa-clock-o' => '&#xf017;',
                'fa-road' => '&#xf018;',
                'fa-download' => '&#xf019;',
                'fa-arrow-circle-o-down' => '&#xf01a;',
                'fa-arrow-circle-o-up' => '&#xf01b;',
                'fa-inbox' => '&#xf01c;',
                'fa-play-circle-o' => '&#xf01d;',
                'fa-repeat' => '&#xf01e;',
                'fa-refresh' => '&#xf021;',
                'fa-list-alt' => '&#xf022;',
                'fa-lock' => '&#xf023;',
                'fa-flag' => '&#xf024;',
                'fa-headphones' => '&#xf025;',
                'fa-volume-off' => '&#xf026;',
                'fa-volume-down' => '&#xf027;',
                'fa-volume-up' => '&#xf028;',
                'fa-qrcode' => '&#xf029;',
                'fa-barcode' => '&#xf02a;',
                'fa-tag' => '&#xf02b;',
                'fa-tags' => '&#xf02c;',
                'fa-book' => '&#xf02d;',
                'fa-bookmark' => '&#xf02e;',
                'fa-print' => '&#xf02f;',
                'fa-camera' => '&#xf030;',
                'fa-font' => '&#xf031;',
                'fa-bold' => '&#xf032;',
                'fa-italic' => '&#xf033;',
                'fa-text-height' => '&#xf034;',
                'fa-text-width' => '&#xf035;',
                'fa-align-left' => '&#xf036;',
                'fa-align-center' => '&#xf037;',
                'fa-align-right' => '&#xf038;',
                'fa-align-justify' => '&#xf039;',
                'fa-list' => '&#xf03a;',
                'fa-outdent' => '&#xf03b;',
                'fa-indent' => '&#xf03c;',
                'fa-video-camera' => '&#xf03d;',
                'fa-picture-o' => '&#xf03e;',
                'fa-pencil' => '&#xf040;',
                'fa-map-marker' => '&#xf041;',
                'fa-adjust' => '&#xf042;',
                'fa-tint' => '&#xf043;',
                'fa-pencil-square-o' => '&#xf044;',
                'fa-share-square-o' => '&#xf045;',
                'fa-check-square-o' => '&#xf046;',
                'fa-arrows' => '&#xf047;',
                'fa-step-backward' => '&#xf048;',
                'fa-fast-backward' => '&#xf049;',
                'fa-backward' => '&#xf04a;',
                'fa-play' => '&#xf04b;',
                'fa-pause' => '&#xf04c;',
                'fa-stop' => '&#xf04d;',
                'fa-forward' => '&#xf04e;',
                'fa-fast-forward' => '&#xf050;',
                'fa-step-forward' => '&#xf051;',
                'fa-eject' => '&#xf052;',
                'fa-chevron-left' => '&#xf053;',
                'fa-chevron-right' => '&#xf054;',
                'fa-plus-circle' => '&#xf055;',
                'fa-minus-circle' => '&#xf056;',
                'fa-times-circle' => '&#xf057;',
                'fa-check-circle' => '&#xf058;',
                'fa-question-circle' => '&#xf059;',
                'fa-info-circle' => '&#xf05a;',
                'fa-crosshairs' => '&#xf05b;',
                'fa-times-circle-o' => '&#xf05c;',
                'fa-check-circle-o' => '&#xf05d;',
                'fa-ban' => '&#xf05e;',
                'fa-arrow-left' => '&#xf060;',
                'fa-arrow-right' => '&#xf061;',
                'fa-arrow-up' => '&#xf062;',
                'fa-arrow-down' => '&#xf063;',
                'fa-share' => '&#xf064;',
                'fa-expand' => '&#xf065;',
                'fa-compress' => '&#xf066;',
                'fa-plus' => '&#xf067;',
                'fa-minus' => '&#xf068;',
                'fa-asterisk' => '&#xf069;',
                'fa-exclamation-circle' => '&#xf06a;',
                'fa-gift' => '&#xf06b;',
                'fa-leaf' => '&#xf06c;',
                'fa-fire' => '&#xf06d;',
                'fa-eye' => '&#xf06e;',
                'fa-eye-slash' => '&#xf070;',
                'fa-exclamation-triangle' => '&#xf071;',
                'fa-plane' => '&#xf072;',
                'fa-calendar' => '&#xf073;',
                'fa-random' => '&#xf074;',
                'fa-comment' => '&#xf075;',
                'fa-magnet' => '&#xf076;',
                'fa-chevron-up' => '&#xf077;',
                'fa-chevron-down' => '&#xf078;',
                'fa-retweet' => '&#xf079;',
                'fa-shopping-cart' => '&#xf07a;',
                'fa-folder' => '&#xf07b;',
                'fa-folder-open' => '&#xf07c;',
                'fa-arrows-v' => '&#xf07d;',
                'fa-arrows-h' => '&#xf07e;',
                'fa-bar-chart-o' => '&#xf080;',
                'fa-twitter-square' => '&#xf081;',
                'fa-facebook-square' => '&#xf082;',
                'fa-camera-retro' => '&#xf083;',
                'fa-key' => '&#xf084;',
                'fa-cogs' => '&#xf085;',
                'fa-comments' => '&#xf086;',
                'fa-thumbs-o-up' => '&#xf087;',
                'fa-thumbs-o-down' => '&#xf088;',
                'fa-star-half' => '&#xf089;',
                'fa-heart-o' => '&#xf08a;',
                'fa-sign-out' => '&#xf08b;',
                'fa-linkedin-square' => '&#xf08c;',
                'fa-thumb-tack' => '&#xf08d;',
                'fa-external-link' => '&#xf08e;',
                'fa-sign-in' => '&#xf090;',
                'fa-trophy' => '&#xf091;',
                'fa-github-square' => '&#xf092;',
                'fa-upload' => '&#xf093;',
                'fa-lemon-o' => '&#xf094;',
                'fa-phone' => '&#xf095;',
                'fa-square-o' => '&#xf096;',
                'fa-bookmark-o' => '&#xf097;',
                'fa-phone-square' => '&#xf098;',
                'fa-twitter' => '&#xf099;',
                'fa-facebook' => '&#xf09a;',
                'fa-github' => '&#xf09b;',
                'fa-unlock' => '&#xf09c;',
                'fa-credit-card' => '&#xf09d;',
                'fa-rss' => '&#xf09e;',
                'fa-hdd-o' => '&#xf0a0;',
                'fa-bullhorn' => '&#xf0a1;',
                'fa-bell' => '&#xf0f3;',
                'fa-certificate' => '&#xf0a3;',
                'fa-hand-o-right' => '&#xf0a4;',
                'fa-hand-o-left' => '&#xf0a5;',
                'fa-hand-o-up' => '&#xf0a6;',
                'fa-hand-o-down' => '&#xf0a7;',
                'fa-arrow-circle-left' => '&#xf0a8;',
                'fa-arrow-circle-right' => '&#xf0a9;',
                'fa-arrow-circle-up' => '&#xf0aa;',
                'fa-arrow-circle-down' => '&#xf0ab;',
                'fa-globe' => '&#xf0ac;',
                'fa-wrench' => '&#xf0ad;',
                'fa-tasks' => '&#xf0ae;',
                'fa-filter' => '&#xf0b0;',
                'fa-briefcase' => '&#xf0b1;',
                'fa-arrows-alt' => '&#xf0b2;',
                'fa-users' => '&#xf0c0;',
                'fa-link' => '&#xf0c1;',
                'fa-cloud' => '&#xf0c2;',
                'fa-flask' => '&#xf0c3;',
                'fa-scissors' => '&#xf0c4;',
                'fa-files-o' => '&#xf0c5;',
                'fa-paperclip' => '&#xf0c6;',
                'fa-floppy-o' => '&#xf0c7;',
                'fa-square' => '&#xf0c8;',
                'fa-bars' => '&#xf0c9;',
                'fa-list-ul' => '&#xf0ca;',
                'fa-list-ol' => '&#xf0cb;',
                'fa-strikethrough' => '&#xf0cc;',
                'fa-underline' => '&#xf0cd;',
                'fa-table' => '&#xf0ce;',
                'fa-magic' => '&#xf0d0;',
                'fa-truck' => '&#xf0d1;',
                'fa-pinterest' => '&#xf0d2;',
                'fa-pinterest-square' => '&#xf0d3;',
                'fa-google-plus-square' => '&#xf0d4;',
                'fa-google-plus' => '&#xf0d5;',
                'fa-money' => '&#xf0d6;',
                'fa-caret-down' => '&#xf0d7;',
                'fa-caret-up' => '&#xf0d8;',
                'fa-caret-left' => '&#xf0d9;',
                'fa-caret-right' => '&#xf0da;',
                'fa-columns' => '&#xf0db;',
                'fa-sort' => '&#xf0dc;',
                'fa-sort-asc' => '&#xf0dd;',
                'fa-sort-desc' => '&#xf0de;',
                'fa-envelope' => '&#xf0e0;',
                'fa-linkedin' => '&#xf0e1;',
                'fa-undo' => '&#xf0e2;',
                'fa-gavel' => '&#xf0e3;',
                'fa-tachometer' => '&#xf0e4;',
                'fa-comment-o' => '&#xf0e5;',
                'fa-comments-o' => '&#xf0e6;',
                'fa-bolt' => '&#xf0e7;',
                'fa-sitemap' => '&#xf0e8;',
                'fa-umbrella' => '&#xf0e9;',
                'fa-clipboard' => '&#xf0ea;',
                'fa-lightbulb-o' => '&#xf0eb;',
                'fa-exchange' => '&#xf0ec;',
                'fa-cloud-download' => '&#xf0ed;',
                'fa-cloud-upload' => '&#xf0ee;',
                'fa-user-md' => '&#xf0f0;',
                'fa-stethoscope' => '&#xf0f1;',
                'fa-suitcase' => '&#xf0f2;',
                'fa-bell-o' => '&#xf0a2;',
                'fa-coffee' => '&#xf0f4;',
                'fa-cutlery' => '&#xf0f5;',
                'fa-file-text-o' => '&#xf0f6;',
                'fa-building-o' => '&#xf0f7;',
                'fa-hospital-o' => '&#xf0f8;',
                'fa-ambulance' => '&#xf0f9;',
                'fa-medkit' => '&#xf0fa;',
                'fa-fighter-jet' => '&#xf0fb;',
                'fa-beer' => '&#xf0fc;',
                'fa-h-square' => '&#xf0fd;',
                'fa-plus-square' => '&#xf0fe;',
                'fa-angle-double-left' => '&#xf100;',
                'fa-angle-double-right' => '&#xf101;',
                'fa-angle-double-up' => '&#xf102;',
                'fa-angle-double-down' => '&#xf103;',
                'fa-angle-left' => '&#xf104;',
                'fa-angle-right' => '&#xf105;',
                'fa-angle-up' => '&#xf106;',
                'fa-angle-down' => '&#xf107;',
                'fa-desktop' => '&#xf108;',
                'fa-laptop' => '&#xf109;',
                'fa-tablet' => '&#xf10a;',
                'fa-mobile' => '&#xf10b;',
                'fa-circle-o' => '&#xf10c;',
                'fa-quote-left' => '&#xf10d;',
                'fa-quote-right' => '&#xf10e;',
                'fa-spinner' => '&#xf110;',
                'fa-circle' => '&#xf111;',
                'fa-reply' => '&#xf112;',
                'fa-github-alt' => '&#xf113;',
                'fa-folder-o' => '&#xf114;',
                'fa-folder-open-o' => '&#xf115;',
                'fa-smile-o' => '&#xf118;',
                'fa-frown-o' => '&#xf119;',
                'fa-meh-o' => '&#xf11a;',
                'fa-gamepad' => '&#xf11b;',
                'fa-keyboard-o' => '&#xf11c;',
                'fa-flag-o' => '&#xf11d;',
                'fa-flag-checkered' => '&#xf11e;',
                'fa-terminal' => '&#xf120;',
                'fa-code' => '&#xf121;',
                'fa-reply-all' => '&#xf122;',
                'fa-mail-reply-all' => '&#xf122;',
                'fa-star-half-o' => '&#xf123;',
                'fa-location-arrow' => '&#xf124;',
                'fa-crop' => '&#xf125;',
                'fa-code-fork' => '&#xf126;',
                'fa-chain-broken' => '&#xf127;',
                'fa-question' => '&#xf128;',
                'fa-info' => '&#xf129;',
                'fa-exclamation' => '&#xf12a;',
                'fa-superscript' => '&#xf12b;',
                'fa-subscript' => '&#xf12c;',
                'fa-eraser' => '&#xf12d;',
                'fa-puzzle-piece' => '&#xf12e;',
                'fa-microphone' => '&#xf130;',
                'fa-microphone-slash' => '&#xf131;',
                'fa-shield' => '&#xf132;',
                'fa-calendar-o' => '&#xf133;',
                'fa-fire-extinguisher' => '&#xf134;',
                'fa-rocket' => '&#xf135;',
                'fa-maxcdn' => '&#xf136;',
                'fa-chevron-circle-left' => '&#xf137;',
                'fa-chevron-circle-right' => '&#xf138;',
                'fa-chevron-circle-up' => '&#xf139;',
                'fa-chevron-circle-down' => '&#xf13a;',
                'fa-html5' => '&#xf13b;',
                'fa-css3' => '&#xf13c;',
                'fa-anchor' => '&#xf13d;',
                'fa-unlock-alt' => '&#xf13e;',
                'fa-bullseye' => '&#xf140;',
                'fa-ellipsis-h' => '&#xf141;',
                'fa-ellipsis-v' => '&#xf142;',
                'fa-rss-square' => '&#xf143;',
                'fa-play-circle' => '&#xf144;',
                'fa-ticket' => '&#xf145;',
                'fa-minus-square' => '&#xf146;',
                'fa-minus-square-o' => '&#xf147;',
                'fa-level-up' => '&#xf148;',
                'fa-level-down' => '&#xf149;',
                'fa-check-square' => '&#xf14a;',
                'fa-pencil-square' => '&#xf14b;',
                'fa-external-link-square' => '&#xf14c;',
                'fa-share-square' => '&#xf14d;',
                'fa-compass' => '&#xf14e;',
                'fa-caret-square-o-down' => '&#xf150;',
                'fa-caret-square-o-up' => '&#xf151;',
                'fa-caret-square-o-right' => '&#xf152;',
                'fa-eur' => '&#xf153;',
                'fa-gbp' => '&#xf154;',
                'fa-usd' => '&#xf155;',
                'fa-inr' => '&#xf156;',
                'fa-jpy' => '&#xf157;',
                'fa-rub' => '&#xf158;',
                'fa-krw' => '&#xf159;',
                'fa-btc' => '&#xf15a;',
                'fa-file' => '&#xf15b;',
                'fa-file-text' => '&#xf15c;',
                'fa-sort-alpha-asc' => '&#xf15d;',
                'fa-sort-alpha-desc' => '&#xf15e;',
                'fa-sort-amount-asc' => '&#xf160;',
                'fa-sort-amount-desc' => '&#xf161;',
                'fa-sort-numeric-asc' => '&#xf162;',
                'fa-sort-numeric-desc' => '&#xf163;',
                'fa-thumbs-up' => '&#xf164;',
                'fa-thumbs-down' => '&#xf165;',
                'fa-youtube-square' => '&#xf166;',
                'fa-youtube' => '&#xf167;',
                'fa-xing' => '&#xf168;',
                'fa-xing-square' => '&#xf169;',
                'fa-youtube-play' => '&#xf16a;',
                'fa-dropbox' => '&#xf16b;',
                'fa-stack-overflow' => '&#xf16c;',
                'fa-instagram' => '&#xf16d;',
                'fa-flickr' => '&#xf16e;',
                'fa-adn' => '&#xf170;',
                'fa-bitbucket' => '&#xf171;',
                'fa-bitbucket-square' => '&#xf172;',
                'fa-tumblr' => '&#xf173;',
                'fa-tumblr-square' => '&#xf174;',
                'fa-long-arrow-down' => '&#xf175;',
                'fa-long-arrow-up' => '&#xf176;',
                'fa-long-arrow-left' => '&#xf177;',
                'fa-long-arrow-right' => '&#xf178;',
                'fa-apple' => '&#xf179;',
                'fa-windows' => '&#xf17a;',
                'fa-android' => '&#xf17b;',
                'fa-linux' => '&#xf17c;',
                'fa-dribbble' => '&#xf17d;',
                'fa-skype' => '&#xf17e;',
                'fa-foursquare' => '&#xf180;',
                'fa-trello' => '&#xf181;',
                'fa-female' => '&#xf182;',
                'fa-male' => '&#xf183;',
                'fa-gittip' => '&#xf184;',
                'fa-sun-o' => '&#xf185;',
                'fa-moon-o' => '&#xf186;',
                'fa-archive' => '&#xf187;',
                'fa-bug' => '&#xf188;',
                'fa-vk' => '&#xf189;',
                'fa-weibo' => '&#xf18a;',
                'fa-renren' => '&#xf18b;',
                'fa-pagelines' => '&#xf18c;',
                'fa-stack-exchange' => '&#xf18d;',
                'fa-arrow-circle-o-right' => '&#xf18e;',
                'fa-arrow-circle-o-left' => '&#xf190;',
                'fa-caret-square-o-left' => '&#xf191;',
                'fa-dot-circle-o' => '&#xf192;',
                'fa-wheelchair' => '&#xf193;',
                'fa-vimeo-square' => '&#xf194;',
                'fa-try' => '&#xf195;',
                'fa-plus-square-o' => '&#xf196;',
            );
        }

        /**
         * Return corresponding date/birthday pattern
         *
         * @access public
         * @param string $type
         * @param string $key
         * @return array
         */
        public static function get_date_pattern($type, $key, $what)
        {
            $patterns = array(
                'date' => array(
                    '0' => array(
                        'pattern'       => '([0]?[1-9]|1[0-9]|2[0-9]|3[01])\/([0]?[1-9]|1[012])\/[0-9]{4}',
                        'mask'          => '99/99/9999',
                        'placeholder'   => __('dd/mm/yyyy', 'pinpointe'),
                        'date_format'   => '%d/%m/%Y',
                    ),
                    '1' => array(
                        'pattern'       => '([0]?[1-9]|1[0-9]|2[0-9]|3[01])-([0]?[1-9]|1[012])-[0-9]{4}',
                        'mask'          => '99-99-9999',
                        'placeholder'   => __('dd-mm-yyyy', 'pinpointe'),
                        'date_format'   => '%d-%m-%Y',
                    ),
                    '2' => array(
                        'pattern'       => '([0]?[1-9]|1[0-9]|2[0-9]|3[01])\.([0]?[1-9]|1[012])\.[0-9]{4}',
                        'mask'          => '99.99.9999',
                        'placeholder'   => __('dd.mm.yyyy', 'pinpointe'),
                        'date_format'   => '%d.%m.%Y',
                    ),
                    '3' => array(
                        'pattern'       => '([0]?[1-9]|1[012])\/([0]?[1-9]|1[0-9]|2[0-9]|3[01])\/[0-9]{4}',
                        'mask'          => '99/99/9999',
                        'placeholder'   => __('mm/dd/yyyy', 'pinpointe'),
                        'date_format'   => '%m/%d/%Y',
                    ),
                    '4' => array(
                        'pattern'       => '([0]?[1-9]|1[012])-([0]?[1-9]|1[0-9]|2[0-9]|3[01])-[0-9]{4}',
                        'mask'          => '99-99-9999',
                        'placeholder'   => __('mm-dd-yyyy', 'pinpointe'),
                        'date_format'   => '%m-%d-%Y',
                    ),
                    '5' => array(
                        'pattern'       => '([0]?[1-9]|1[012])\.([0]?[1-9]|1[0-9]|2[0-9]|3[01])\.[0-9]{4}',
                        'mask'          => '99.99.9999',
                        'placeholder'   => __('mm.dd.yyyy', 'pinpointe'),
                        'date_format'   => '%m.%d.%Y',
                    ),
                    '6' => array(
                        'pattern'       => '[0-9]{4}\/([0]?[1-9]|1[012])\/([0]?[1-9]|1[0-9]|2[0-9]|3[01])',
                        'mask'          => '9999/99/99',
                        'placeholder'   => __('yyyy/mm/dd', 'pinpointe'),
                        'date_format'   => '%Y/%m/%d',
                    ),
                    '7' => array(
                        'pattern'       => '[0-9]{4}-([0]?[1-9]|1[012])-([0]?[1-9]|1[0-9]|2[0-9]|3[01])',
                        'mask'          => '9999-99-99',
                        'placeholder'   => __('yyyy-mm-dd', 'pinpointe'),
                        'date_format'   => '%Y-%m-%d',
                    ),
                    '8' => array(
                        'pattern'       => '[0-9]{4}\.([0]?[1-9]|1[012])\.([0]?[1-9]|1[0-9]|2[0-9]|3[01])',
                        'mask'          => '9999.99.99',
                        'placeholder'   => __('yyyy.mm.dd', 'pinpointe'),
                        'date_format'   => '%Y.%m.%d',
                    ),
                    '9' => array(
                        'pattern'       => '([0]?[1-9]|1[0-9]|2[0-9]|3[01])\/([0]?[1-9]|1[012])\/[0-9]{2}',
                        'mask'          => '99/99/99',
                        'placeholder'   => __('dd/mm/yy', 'pinpointe'),
                        'date_format'   => '%d/%m/%y',
                    ),
                    '10' => array(
                        'pattern'       => '([0]?[1-9]|1[0-9]|2[0-9]|3[01])-([0]?[1-9]|1[012])-[0-9]{2}',
                        'mask'          => '99-99-99',
                        'placeholder'   => __('dd-mm-yy', 'pinpointe'),
                        'date_format'   => '%d-%m-%y',
                    ),
                    '11' => array(
                        'pattern'       => '([0]?[1-9]|1[0-9]|2[0-9]|3[01])\.([0]?[1-9]|1[012])\.[0-9]{2}',
                        'mask'          => '99.99.99',
                        'placeholder'   => __('dd.mm.yy', 'pinpointe'),
                        'date_format'   => '%d.%m.%y',
                    ),
                    '12' => array(
                        'pattern'       => '([0]?[1-9]|1[012])\/([0]?[1-9]|1[0-9]|2[0-9]|3[01])\/[0-9]{2}',
                        'mask'          => '99/99/99',
                        'placeholder'   => __('mm/dd/yy', 'pinpointe'),
                        'date_format'   => '%m/%d/%y',
                    ),
                    '13' => array(
                        'pattern'       => '([0]?[1-9]|1[012])-([0]?[1-9]|1[0-9]|2[0-9]|3[01])-[0-9]{2}',
                        'mask'          => '99-99-99',
                        'placeholder'   => __('mm-dd-yy', 'pinpointe'),
                        'date_format'   => '%m-%d-%y',
                    ),
                    '14' => array(
                        'pattern'       => '([0]?[1-9]|1[012])\.([0]?[1-9]|1[0-9]|2[0-9]|3[01])\.[0-9]{2}',
                        'mask'          => '99.99.99',
                        'placeholder'   => __('mm.dd.yy', 'pinpointe'),
                        'date_format'   => '%m.%d.%y',
                    ),
                    '15' => array(
                        'pattern'       => '[0-9]{2}\/([0]?[1-9]|1[012])\/([0]?[1-9]|1[0-9]|2[0-9]|3[01])',
                        'mask'          => '99/99/99',
                        'placeholder'   => __('yy/mm/dd', 'pinpointe'),
                        'date_format'   => '%y/%m/%d',
                    ),
                    '16' => array(
                        'pattern'       => '[0-9]{2}-([0]?[1-9]|1[012])-([0]?[1-9]|1[0-9]|2[0-9]|3[01])',
                        'mask'          => '99-99-99',
                        'placeholder'   => __('yy-mm-dd', 'pinpointe'),
                        'date_format'   => '%y-%m-%d',
                    ),
                    '17' => array(
                        'pattern'       => '[0-9]{2}\.([0]?[1-9]|1[012])\.([0]?[1-9]|1[0-9]|2[0-9]|3[01])',
                        'mask'          => '99.99.99',
                        'placeholder'   => __('yy.mm.dd', 'pinpointe'),
                        'date_format'   => '%y.%m.%d',
                    ),
                ),
                'birthday' => array(
                    '0' => array(
                        'pattern'       => '([0]?[1-9]|1[0-9]|2[0-9]|3[01])\/([0]?[1-9]|1[012])',
                        'mask'          => '99/99',
                        'placeholder'   => __('dd/mm', 'pinpointe'),
                        'date_format'   => '%d/%m',
                    ),
                    '1' => array(
                        'pattern'       => '([0]?[1-9]|1[0-9]|2[0-9]|3[01])-([0]?[1-9]|1[012])',
                        'mask'          => '99/99',
                        'placeholder'   => __('dd-mm', 'pinpointe'),
                        'date_format'   => '%d-%m',
                    ),
                    '2' => array(
                        'pattern'       => '([0]?[1-9]|1[0-9]|2[0-9]|3[01])\.([0]?[1-9]|1[012])',
                        'mask'          => '99/99',
                        'placeholder'   => __('dd.mm', 'pinpointe'),
                        'date_format'   => '%d.%m',
                    ),
                    '3' => array(
                        'pattern'       => '([0]?[1-9]|1[012])\/([0]?[1-9]|1[0-9]|2[0-9]|3[01])',
                        'mask'          => '99/99',
                        'placeholder'   => __('mm/dd', 'pinpointe'),
                        'date_format'   => '%m/%d',
                    ),
                    '4' => array(
                        'pattern'       => '([0]?[1-9]|1[012])-([0]?[1-9]|1[0-9]|2[0-9]|3[01])',
                        'mask'          => '99-99',
                        'placeholder'   => __('mm-dd', 'pinpointe'),
                        'date_format'   => '%m-%d',
                    ),
                    '5' => array(
                        'pattern'       => '([0]?[1-9]|1[012])\.([0]?[1-9]|1[0-9]|2[0-9]|3[01])',
                        'mask'          => '99.99',
                        'placeholder'   => __('mm.dd', 'pinpointe'),
                        'date_format'   => '%m.%d',
                    ),
                )
            );

            return $patterns[$type][$key][$what];
        }

        /**
         * Get list of all user roles in this installation
         *
         * @access public
         * @return array
         */
        public static function get_all_user_roles()
        {
            global $wp_roles;

            if (!isset($wp_roles)) {
                $wp_roles = new WP_Roles();
            }

            return $wp_roles->get_names();
        }

        /**
         * Get next form id (to control multiple forms on the same page)
         *
         * @access public
         * @return int
         */
        public function get_next_rendered_form_id()
        {
            $this->last_rendered_form++;

            return $this->last_rendered_form;
        }

        /**
         * Return list of forms for dropdown
         *
         * @access public
         * @return array
         */
        public static function get_list_of_forms($first_empty = true)
        {
            $results = array();

            if ($first_empty) {
                $results[''] = '';
            }

            $opt = get_option('pinpointe_options', $results);

            // Check if integration is enabled
            if (!$opt || !is_array($opt) || empty($opt) || !isset($opt['pinpointe_api_key']) || !$opt['pinpointe_api_key']) {
                return $results;
            }

            // Check if at least one form is defined
            if (!isset($opt['forms']) || empty($opt['forms'])) {
                return $results;
            }

            foreach ($opt['forms'] as $form_key => $form) {
                $results[$form_key] = '#' . $form_key . ' - ' . $form['title'];
            }

            return $results;
        }

        /**
         * Display signup popup (if needed)
         *
         * @access public
         * @return void
         */
        public function display_popup()
        {
            // Check if page frequency capping is not in effect
            if ($this->popup_page_capping_in_effect) {
                return;
            }

            // Check if user has not opt out of popup (i.e. dismissed it)
            if (isset($_COOKIE['pinpointe_d'])) {
                return;
            }

            // Check if popup is enabled and has a form selected
            if (!$this->opt['pinpointe_popup_enabled'] || !$this->opt['pinpointe_popup_form']) {
                return;
            }

            // Check if integration is enabled and at least one form configured
            if (!$this->opt['pinpointe_api_key'] || !isset($this->opt['forms']) || empty($this->opt['forms'])) {
                return;
            }

            // Check if selected form exists within configured forms
            if (!isset($this->opt['forms'][$this->opt['pinpointe_popup_form']])) {
                return;
            }

            // Check if form can be displayed on this page
            $is_allowed_page = false;

            if (is_front_page() && in_array(1, $this->opt['pinpointe_popup_display_on'])) {
                $is_allowed_page = true;
            }

            if (!$is_allowed_page && is_page() && !is_front_page() && in_array(2, $this->opt['pinpointe_popup_display_on'])) {
                $is_allowed_page = true;
            }

            if (!$is_allowed_page && is_single() && in_array(3, $this->opt['pinpointe_popup_display_on'])) {
                $is_allowed_page = true;
            }

            if (!$is_allowed_page && !is_front_page() && !is_page() && !is_single() && in_array(4, $this->opt['pinpointe_popup_display_on'])) {
                $is_allowed_page = true;
            }

            if (!$is_allowed_page) {
                return;
            }

            // Check time frequency capping (cookie "pinpointe_t")
            if (isset($_COOKIE['pinpointe_t']) && $this->opt['pinpointe_popup_time_limit']) {
                return;
            }

            // Check if form can be displayed
            $form = self::select_form_by_conditions($this->opt['forms'], array((int)$this->opt['pinpointe_popup_form']));

            if (!$form) {
                return;
            }

            require_once PINPOINTE_PLUGIN_PATH . '/includes/pinpointe-prepare-form.inc.php';

            $prepared_form = pinpointe_prepare_form($form, $this->opt, 'popup', null, true);
            $form_html = $prepared_form['html'];
            $prepared_form_id = $prepared_form['id'];

            ?>
                 <script>
                    jQuery(function()
                    {
                       if (jQuery('#pinpointe_popup_open').length > 0) {
                            jQuery('#pinpointe_popup_open').click(function() {
                                pinpointe_open_popup();
                            });
                        }
                        else {
                            setTimeout(function() {
                                pinpointe_open_popup();
                            }, <?php echo esc_js($this->opt['pinpointe_popup_delay'] != '' ? ($this->opt['pinpointe_popup_delay'] * 1000) : 1); ?>);
                        }

                        function pinpointe_open_popup()
                        {
                            if (!jQuery('#sky-form-modal-overlay').length) {
                                jQuery('body').append('<div id="sky-form-modal-overlay" class="sky-form-modal-overlay"></div>');
                            }

                            form = jQuery('#pinpointe_popup_<?php echo esc_attr($prepared_form_id); ?>');

                            jQuery('#sky-form-modal-overlay').fadeIn();
                            //form.fadeIn();
                            form.css('top', '50%').css('left', '50%').css('margin-top', -form.outerHeight()/2).css('margin-left', -form.outerWidth()/2).fadeIn();

                            jQuery('#sky-form-modal-overlay').on('click', function() {
                                pinpointe_close_popup();
                            });

                            jQuery('#pinpointe_popup_close').click(function() {
                                pinpointe_close_popup();
                            });

                            jQuery('#pinpointe_dismiss').click(function() {
                                pinpointe_write_cookie('pinpointe_d', '1', (5 * 365 * 24 * 60));
                                pinpointe_close_popup();
                            });

                            <?php if ($this->opt['pinpointe_popup_time_limit']): ?>
                                var minutes = <?php echo esc_html($this->opt['pinpointe_popup_time_limit']); ?>;
                                pinpointe_write_cookie('pinpointe_t', '1', minutes);
                            <?php endif; ?>

                            <?php if ($this->opt['pinpointe_popup_page_limit'] > 1): ?>
                                pinpointe_write_cookie('pinpointe_p', '1', (30 * 24 * 60));
                            <?php endif; ?>

                        }

                        function pinpointe_close_popup()
                        {
                            jQuery('#sky-form-modal-overlay').fadeOut();
                            jQuery('.sky-form-modal').fadeOut();
                        }

                        function pinpointe_write_cookie(key, value, minutes)
                        {
                            var date = new Date();
                            date.setTime(date.getTime() + (minutes * 60 * 1000));
                            Cookies.set(key, value, { expires: date, path: '/' });
                        }
                    });
                 </script>
            <?php

            // Output the sanitized form HTML
            echo $form_html;
        }

        /**
         * Track page hits (for popup frequency capping)
         *
         * @access public
         * @return void
         */
        public function track_page_hits()
        {
            if (isset($_COOKIE['pinpointe_p'])) {
                $currentPageHits = intval(sanitize_text_field($_COOKIE['pinpointe_p']));
                $maxAllowedHits = intval($this->opt['pinpointe_popup_page_limit']);
                
                if ($currentPageHits < $maxAllowedHits) {
                    setcookie('pinpointe_p', ($currentPageHits + 1), (time()+60*60*24*30), '/');
                    $this->popup_page_capping_in_effect = true;
                }
            }
        }

        /**
         * Maybe lock content
         *
         * @access public
         * @return void
         */
        public function content_lock($content)
        {
            // Do not ever lock these pages
            if (is_archive() || is_search() || is_home() || is_front_page() || is_feed()) {
                return $content;
            }

            // Check if integration is enabled and at least one form configured
            if (!$this->opt['pinpointe_api_key'] || !isset($this->opt['forms']) || empty($this->opt['forms'])) {
                return $content;
            }

            // Check if locking is enabled and form selected
            if (!$this->opt['pinpointe_lock_enabled'] || !$this->opt['pinpointe_lock_form']) {
                return $content;
            }

            // Check if user has already subscribed
            if (isset($_COOKIE['pinpointe_s'])) {
                return $content;
            }

            // Select form that match the conditions best
            $form = self::select_form_by_conditions($this->opt['forms'], array($this->opt['pinpointe_lock_form']));

            if (!$form) {
                return $content;
            }

            require_once PINPOINTE_PLUGIN_PATH . '/includes/pinpointe-prepare-form.inc.php';

            $form_html = pinpointe_prepare_form($form, $this->opt, 'lock');

            return $form_html;
        }

        /**
         * Maybe render checkbox below registration form
         *
         * @access public
         * @return void
         */
        public function checkbox_render_registration()
        {
            if ($this->opt['pinpointe_api_key'] && in_array('1', $this->opt['pinpointe_checkbox_add_to']) && $this->opt['pinpointe_checkbox_list']) {
                $this->render_checkbox('registration');
            }
        }

        /**
         * Maybe render checkbox below comments form
         *
         * @access public
         * @return void
         */
        public function checkbox_render_comment()
        {
            if ($this->opt['pinpointe_api_key'] && in_array('2', $this->opt['pinpointe_checkbox_add_to']) && $this->opt['pinpointe_checkbox_list']) {
                $this->render_checkbox('comment');
            }
        }

        /**
         * Render checkbox
         *
         * @access public
         * @param string $context
         * @return void
         */
        public function render_checkbox($context)
        {
            echo '<p style="margin: 2px 6px 16px 0px;"><label for="pinpointe_checkbox_signup"><input type="checkbox" id="pinpointe_checkbox_signup" name="pinpointe_checkbox_signup" value="1" ' . esc_attr($this->opt['pinpointe_checkbox_state'] == '1' ? 'checked="checked"' : '') . ' /> ' . esc_html($this->opt['pinpointe_checkbox_label']) . '</label></p>';
        }

        /**
         * Subscribe from registration checkbox
         *
         * @access public
         * @param string $user_id
         * @return void
         */
        public function checkbox_subscribe_registration($user_id)
        {
            // Check configuration
            if (!$this->opt['pinpointe_api_key'] || !in_array('1', $this->opt['pinpointe_checkbox_add_to']) || !$this->opt['pinpointe_checkbox_list']) {
                return;
            }

            // Check if checkbox has been checked
            if (!isset($_POST['pinpointe_checkbox_signup']) || $_POST['pinpointe_checkbox_signup'] != '1') {
                return;
            }

            // Get user
            $user = get_userdata($user_id);

            if (!$user) {
                return;
            }

            // Email
            $email = $user->user_email;

            // Get merge fields
            $merge = array();

            // First name
            if (isset($user->user_firstname) && !empty($user->user_firstname)) {
                $merge['FNAME'] = $user->user_firstname;
            }

            // Last name
            if (isset($user->user_lastname) && !empty($user->user_lastname)) {
                $merge['LNAME'] = $user->user_firstname;
            }

            // Role
            $array = array_values($user->roles);
            $merge['ROLE'] = array_shift($array);

            // User name
            $merge['USERNAME'] = $user->user_login;

            $this->subscribe($this->opt['pinpointe_checkbox_list'], $email, array(), $merge);
        }

        /**
         * Subscribe from comments checkbox
         *
         * @access public
         * @param string $comment_id
         * @param string $approved
         * @return void
         */
        public function checkbox_subscribe_comments($comment_id, $approved)
        {
            // Check configuration
            if (!$this->opt['pinpointe_api_key'] || !in_array('2', $this->opt['pinpointe_checkbox_add_to']) || !$this->opt['pinpointe_checkbox_list']) {

            }

            // Check if checkbox has been checked
            if (!isset($_POST['pinpointe_checkbox_signup']) || $_POST['pinpointe_checkbox_signup'] != '1') {
                return;
            }

            // Check if comment is not spam
            if ($approved === 'spam') {
                return;
            }

            // Get visitor email
            $comment = get_comment($comment_id);
            $email = $comment->comment_author_email;

            $this->subscribe($this->opt['pinpointe_checkbox_list'], $email, array(), array());
        }

        /**
         * Sync - user created
         *
         * @access public
         * @param string $user_id
         * @return void
         */
        public function sync_create($user_id)
        {
            // Check configuration
            if (!$this->opt['pinpointe_api_key'] || !$this->opt['pinpointe_sync_list']) {
                return;
            }

            // Get user
            $user = get_userdata($user_id);

            if (!$user) {
                return;
            }

            // Check if user with this role can be synced
            $proceed = false;

            foreach ($user->roles as $role) {
                if (in_array($role, $this->opt['pinpointe_sync_roles'])) {
                    $proceed = true;
                }
            }

            if (!$proceed) {
                return;
            }

            // Email
            $email = $user->user_email;

            // Get merge fields
            $merge = array();

            // First name
            if (isset($user->user_firstname) && !empty($user->user_firstname)) {
                $merge['2'] = $user->user_firstname;
            }

            // Last name
            if (isset($user->user_lastname) && !empty($user->user_lastname)) {
                $merge['3'] = $user->user_lastname;
            }

            // Role
            $array = array_values($user->roles);
            $merge['ROLE'] = array_shift($array);

            // User name
            $merge['USERNAME'] = $user->user_login;

            // Subscribe user
            if ($response = $this->subscribe($this->opt['pinpointe_sync_list'], $email, array(), $merge, true)) {
                update_user_meta($user_id, 'pinpointe_sync_key', $response['leid']);
            }
        }

        /**
         * Sync - user updated
         *
         * @access public
         * @param string $user_id
         * @param object $user_old
         * @return void
         */
        public function sync_update($user_id, $user_old)
        {
            // Check configuration
            if (!$this->opt['pinpointe_api_key'] || !$this->opt['pinpointe_sync_list']) {
                return;
            }

            // Get user
            $user = get_userdata($user_id);

            if (!$user) {
                return;
            }

            // Check if user with this role can be synced
            $proceed = false;

            foreach ($user->roles as $role) {
                if (in_array($role, $this->opt['pinpointe_sync_roles'])) {
                    $proceed = true;
                }
            }

            if (!$proceed) {
                return;
            }

            // Check if user has been synced previously
            $sync_key = get_user_meta($user_id, 'pinpointe_sync_key', true);

            if ($sync_key == '') {
                $this->sync_create($user_id);
                return;
            }

            // Get new values and check if anything changed
            $changes = false;
            $merge = array();

            // Email
            $email = $user->user_email;
            $email_old = $user_old->user_email;

            if ($email != $email_old) {
                $merge['new-email'] = $email;
                $changes = true;
            }

            // First name
            $first_name = (isset($user->user_firstname) && !empty($user->user_firstname)) ? $user->user_firstname : '';
            $first_name_old = (isset($user_old->user_firstname) && !empty($user_old->user_firstname)) ? $user_old->user_firstname : '';

            if ($first_name != $first_name_old) {
                $merge['FNAME'] = $first_name;
                $changes = true;
            }

            // Last name
            $last_name = (isset($user->user_lastname) && !empty($user->user_lastname)) ? $user->user_lastname : '';
            $last_name_old = (isset($user_old->user_lastname) && !empty($user_old->user_lastname)) ? $user_old->user_lastname : '';

            if ($last_name != $last_name_old) {
                $merge['LNAME'] = $last_name;
                $changes = true;
            }

            // Role
            $roles = array_values($user->roles);
            $role = array_shift($roles);
            $old_roles = array_values($user_old->roles);
            $role_old = array_shift($old_roles);

            if ($role != $role_old) {
                $merge['ROLE'] = $role;
                $changes = true;
            }

            if ($changes) {
                $this->update_subscription($sync_key, $this->opt['pinpointe_sync_list'], array(), $merge);
            }
        }

        /**
         * Sync - user deleted
         *
         * @access public
         * @param string $user_id
         * @return void
         */
        public function sync_delete($user_id)
        {
            // Check configuration
            if (!$this->opt['pinpointe_api_key'] || !$this->opt['pinpointe_sync_list']) {
                return;
            }

            // Get user
            $user = get_userdata($user_id);

            if (!$user) {
                return;
            }

            // Check if user with this role can be synced
            $proceed = false;

            foreach ($user->roles as $role) {
                if (in_array($role, $this->opt['pinpointe_sync_roles'])) {
                    $proceed = true;
                }
            }

            if (!$proceed) {
                return;
            }

            // Get sync key
            $sync_key = get_user_meta($user_id, 'pinpointe_sync_key', true);

            if ($sync_key != '') {
                $this->unsubscribe($this->opt['pinpointe_sync_list'], $sync_key, false);
            }
        }

        /**
         * Get visitor IP address
         *
         * @access public
         * @return string
         */
        public function get_visitor_ip()
        {
            $ip_address = '0.0.0.0';
            
            if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                if (strpos($_SERVER['HTTP_X_FORWARDED_FOR'], ',') > 0) {
                    $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                    $ip_address = trim($ip[0]);
                }
                else {
                    $ip_address = sanitize_text_field($_SERVER['HTTP_X_FORWARDED_FOR']);
                }
            }
            else {
                $ip_address = sanitize_text_field($_SERVER['REMOTE_ADDR']);
            }
            
            if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
                error_log("Invalid IP address detected: {$ip_address}");
                return '0.0.0.0'; // Return invalid IP when invalid input received
            }
            
            return $ip_address;
        }

    }

}

$GLOBALS['PinpointeSignupForm'] = PinpointeSignupForm::get_instance();
