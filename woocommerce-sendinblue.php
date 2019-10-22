<?php
/**
 * Plugin Name: WooCommerce Sendinblue Newsletter Subscription
 * Description: Allow users to subscribe to your newsletter via the checkout page and a client to send SMS campaign.
 * Author: SendinBlue
 * Author URI: https://www.sendinblue.com/?r=wporg
 * Version: 1.2.16
 * Requires at least: 4.3
 * Tested up to: 5.0.3
 *
 * WC requires at least: 2.1
 * WC tested up to: 3.4.4
 * License: GPLv2 or later
 */
/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.
This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Check if WooCommerce is active
if ( ! function_exists( 'is_plugin_active' ) ) {
    require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
}

if ( !is_plugin_active( 'woocommerce/woocommerce.php' ) )
{
    return;
}

// WC version check
if ( version_compare( get_option( 'woocommerce_db_version' ), '2.1', '<' ) ) {

    function woocommerce_sendinblue_outdated_version_notice() {

        $message = sprintf(
            __( '%sWooCommerce SendinBlue is inactive.%s This version requires WooCommerce 2.1 or newer. Please %supdate WooCommerce to version 2.1 or newer%s', 'wc_sendinblue' ),
            '<strong>',
            '</strong>',
            '<a href="' . admin_url( 'plugins.php' ) . '">',
            '&nbsp;&raquo;</a>'
        );

        echo sprintf( '<div class="error"><p>%s</p></div>', $message );
    }

    add_action( 'admin_notices', 'woocommerce_sendinblue_outdated_version_notice' );

    return;
}

if(!class_exists('WC_Sendinblue_Integration')) {

    register_deactivation_hook(__FILE__, array('WC_Sendinblue_Integration', 'deactivate'));
    register_activation_hook(__FILE__, array('WC_Sendinblue_Integration', 'activate'));
    register_uninstall_hook(__FILE__, array('WC_Sendinblue_Integration', 'uninstall'));

    if(!class_exists('SIB_Model_Contact')) {
        require_once 'model/model-contacts.php';
    }
    if (!class_exists('Mailin_Woo')) {
        require_once 'includes/mailin.php';
    }
    require_once 'model/model-country.php';
    require_once 'includes/wc-sendinblue.php';
    require_once 'includes/wc-sendinblue-sms.php';
    require_once 'includes/wc-sendinblue-smtp.php';
    require_once 'includes/wc-sendinblue-api.php';

    /**
     *
     */
    class WC_Sendinblue_Integration
    {
        /** @var \WC_Sendinblue_Integration_Settings instance */
        public $settings;

        /** var array the active filters */
        public $filters;

        /** SendinBlue SMTP is enabled to send all WC emails*/
        public static $ws_smtp_enabled;

        /** check if wp_mail is declared  */
        static $wp_mail_conflict;

        /** order id when get started sending email */
        public $order_id;

        /**
         * @var int $customer_id - customer id.
         */
        public $customer_id;

        /**
         * @var array $customer_data  - customer data.
         */
        public $customer_data;

        /** email template of sendinblue when send order email */
        public static $order_template_sib;
        
        /**
         * Vars for marketing automation tracking data  BG20190428
         */
        public $tracking_event_type;
        public $tracking_event_data;

        /**
         * Initializes the plugin
         */
        public function __construct()
        {
            // notify the sms limit
            add_action('ws_sms_alert_event', array($this,'do_sms_limit_notify'));
            // load translation
            add_action('init', array($this, 'init'));

            $this->customizations = get_option('wc_sendinblue_settings', array());

            // admin
            if (is_admin() && !defined('DOING_AJAX')) {

                // load settings page
                add_filter('woocommerce_get_settings_pages', array($this, 'add_settings_page'));

                // add a 'Configure' link to the plugin action links
                //add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_plugin_action_links'));

                // Add a new interval of a week
                add_filter( 'cron_schedules', array($this, 'ws_add_weekly_cron_schedule'));

            }

            // Init variables
            $this->ws_subscribe_enable = isset($this->customizations['ws_subscription_enabled']) ? $this->customizations['ws_subscription_enabled'] : 'yes';
            $this->ws_smtp_enable  = self::$ws_smtp_enabled  = isset($this->customizations['ws_smtp_enable']) ? $this->customizations['ws_smtp_enable'] : 'yes';
            $this->ws_template_enable  = isset($this->customizations['ws_email_templates_enable']) ? $this->customizations['ws_email_templates_enable'] : 'no';
            $this->ws_sms_enable       = isset($this->customizations['ws_sms_enable']) ? $this->customizations['ws_sms_enable'] : 'no';

            self::$wp_mail_conflict = false;

            add_action('woocommerce_init', array($this, 'load_customizations'));

            // Register style sheet.
            add_action('admin_enqueue_scripts', array($this, 'register_plugin_scripts'));

            //
            add_action('admin_print_scripts', array($this, 'admin_inline_js'));

            //get customer id when create customer
            add_action('woocommerce_created_customer', array( $this, 'get_new_customer_id' ), 10, 2);

            // Maybe add an "opt-in" field to the checkout
            add_action('woocommerce_checkout_after_terms_and_conditions', array($this, 'maybe_add_checkout_fields_terms'));
            add_filter('woocommerce_checkout_fields', array($this, 'maybe_add_checkout_fields'));

            // hook into woocommerce order status changed hook to handle the desired subscription event trigger
            add_action('woocommerce_order_status_changed', array($this, 'ws_order_status_changed'), 10, 3);

            // Maybe save the "opt-in" field on the checkout
            add_action('woocommerce_checkout_update_order_meta', array($this, 'maybe_save_checkout_fields'));

            // Alert credit is not enough
            add_action( 'admin_notices', array($this, 'ws_admin_credits_notice'));

            // Ajax
            add_action('wp_ajax_ws_validation_process', array('WC_Sendinblue', 'ajax_validation_process'));
            add_action('wp_ajax_ws_sms_test_send', array('WC_Sendinblue_SMS', 'ajax_sms_send'));
            add_action('wp_ajax_ws_sms_refresh', array('WC_Sendinblue_SMS', 'ajax_sms_refresh'));
            add_action('wp_ajax_ws_sms_campaign_send', array('WC_Sendinblue_SMS', 'ajax_sms_campaign_send'));
            add_action('wp_ajax_ws_get_daterange', array('WC_Sendinblue_SMTP', 'ajax_get_daterange'));
            add_action('wp_ajax_ws_email_campaign_send', array('WC_Sendinblue_SMTP', 'ajax_email_campaign_send'));
            add_action('wp_ajax_ws_dismiss_alert', array($this, 'ajax_dismiss_alert'));
            add_action('wp_ajax_ws_transient_refresh', array($this, 'ajax_transient_refresh'));
            /* to sync all customers to contact list */
            add_action('wp_ajax_ws_sync_users', array($this, 'ajax_sync_users'));

            // hook to send woocommerce email
            add_filter('wc_get_template', array($this, 'ws_get_template_type'),10, 2);
            add_filter('woocommerce_email_headers', array($this, 'woocommerce_mail_header'),15);
            add_filter('woocommerce_mail_content', array($this, 'woocommerce_mail_content'),20);
            add_filter('woocommerce_email_styles', array($this, 'ws_get_email_style'));

            // get order info from $arg when get started sending woo emails
            add_action('woocommerce_before_template_part', array($this, 'ws_before_template_part'),10,4);//$template_name, $template_path, $located, $args );)

            $order_track_status = isset($this->customizations['ws_marketingauto_enable']) ? $this->customizations['ws_marketingauto_enable'] : '';
            // Marketing automation events hooks start BG20190425
            if ($order_track_status == 'yes') {
                add_action( 'wp_head', array( $this, 'install_ma_script' ) );
                add_action( 'wp_head', array( $this, 'install_ma_event_listeners' ) );

                // adding items to cart
                add_action( 'woocommerce_add_to_cart', array( $this, 'ws_add_to_cart' ), 10, 6 ); // with params
                // fires on changes in cart qouantities (work with change to 0)
                add_action( 'woocommerce_update_cart_action_cart_updated', array( $this, 'ws_update_cart_action_cart_updated' ), 10, 1 ); 
                add_action( 'woocommerce_cart_item_removed', array( $this, 'ws_cart_item_removed' ), 10, 2 );
                add_action( 'woocommerce_cart_item_restored', array( $this, 'ws_cart_item_restored' ), 10, 2 );
                add_action( 'woocommerce_thankyou', array( $this, 'ws_checkout_completed' ) );          
                // cart data rendering
                add_action( 'wp_footer', array( $this, 'ws_cart_custom_fragment_load' ) );
                add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'ws_cart_custom_fragment'), 10, 1 );
                // identification for logged in user
                add_action( 'wp_login', array( $this, 'set_email_id_cookie' ));
                // Marketing automation events hooks end
            }

            /**
             * hook wp_mail to send transactional emails
             */

            // check if wp_mail function is already declared by others
            if( function_exists('wp_mail') ) {
                self::$wp_mail_conflict = true;
            }
            $home_settings = get_option('sib_home_option', array());
            $nf_sib_settings = get_option('ninja_forms_settings', array());

            if($this->ws_smtp_enable == 'yes' && self::$wp_mail_conflict == false) {
                function wp_mail($to, $subject, $message, $headers = '', $attachments = array())
                {
                    try {
                        $sent = WC_Sendinblue::sib_email($to, $subject, $message, $headers, $attachments);
                        if (is_wp_error($sent) || !isset($sent['code']) || $sent['code'] != 'success') {
                            return WC_Sendinblue::wp_mail_native($to, $subject, $message, $headers, $attachments);
                        }
                        return true;
                    } catch (Exception $e) {
                        return WC_Sendinblue::wp_mail_native($to, $subject, $message, $headers, $attachments);
                    }
                }
            }
            elseif($this->ws_smtp_enable == 'yes' && self::$wp_mail_conflict == true){
                if(isset($nf_sib_settings['nf_sib_smtp_enable']) && $nf_sib_settings['nf_sib_smtp_enable'] == 'yes' || isset($home_settings['activate_email']) && $home_settings['activate_email'] == 'yes')
                {

                }
                else{
                    add_action('admin_notices', array(&$this, 'wpMailNotices'));
                    return;
                }
            }

        }

        public function ws_before_template_part($template_name, $template_path, $located, $args){
            if(isset($args['order']) && is_object($args['order'])) {
                if( version_compare( get_option( 'woocommerce_db_version'), '3.0', '>=' )){
                    $this->order_id = $args['order']->get_id();
                }
                else{
                    $this->order_id = $args['order']->id;
                }
            }
        }

        /**
         * Inline scripts
         */
        public function admin_inline_js()
        {
            // login and logout button
            if( isset(WC_Sendinblue::$access_key) && WC_Sendinblue::$access_key != '') {
                $logBtn = '<div style="padding-left: 24px;"><a href="' . esc_url(add_query_arg('ws_action', 'logout')) . '" class = "button-primary" style="padding: 0 20px; ">' . __('Logout', 'wc_sendinblue') . '</a></div>';// sprintf( __('If you want logout, please click %s. ', 'wc_sendinblue'),'<a href="'. esc_url(add_query_arg('sib_action', 'logout')).'">'. __('Logout','wc_sendinblue') .'</a>');
            }else {
                //login
                $logBtn = '<div><img id="ws_login_gif" src="'.WC()->plugin_url()."/assets/images/select2-spinner.gif".
                    '" style="margin-right: 12px;vertical-align: middle;display:none;"><a href="javascript:void(0);" class = "ws_api_key_active button-primary" style="padding: 0 20px; margin-top: 24px;">' . __('Login', 'wc_sendinblue') . '</a></div>';
            }

            $loading_gif = '<img class="ws_loading_gif" src="'.WC()->plugin_url()."/assets/images/select2-spinner.gif".
                '" style="margin-right: 12px;vertical-align: middle;display:none;">';
            //variable in send SMS page

            echo "<script type='text/javascript'>\n";

            if ((isset($_GET['section']) && $_GET['section'] == 'sms_options') || (isset($_GET['section']) && $_GET['section'] == 'campaigns')) {
                echo 'var VAR_SMS_MSG_DESC = "' . sprintf(__('If you want to personalize the SMS, you can use the variables below:%s - For first name use {first_name}%s - For last name use {last_name}%s - For order price use {order_price}%s - For order date use {order_date}', 'wc_sendinblue'),'<br>','<br>','<br>','<br>') . '";';
            }
            echo 'var ws_tab ="' . (isset($_GET['tab']) ? $_GET['tab'] : '') . '";';
            echo 'var ws_section ="' . (isset($_GET['section']) ? $_GET['section'] : '') . '";';
            echo 'var SEND_BTN ="'. __('Send','wc_sendinblue') .'";';
            echo  'var SENDING_BTN ="'.__('Sending','wc_sendinblue') .'";';
            echo 'var SEND_CAMP_BTN ="'. __('Send the campaign','wc_sendinblue') .'";';
            echo 'var ws_alert_msg_failed ="'. __('Message has not been sent successfully.','wc_sendinblue') .'";';
            echo 'var ws_alert_msg_contact_failed ="'. __('Message has not been sent successfully. Please check format of contacts','wc_sendinblue') .'";';
            echo 'var ws_alert_msg_success ="'. __('Message has been sent successfully.','wc_sendinblue') .'";';
            echo "var Loading_Gif ='". $loading_gif ."';";
            echo "var LOG_BTN ='". $logBtn ."';";

            echo "\n</script>";

        }

        /**
         * Load scripts
         */
        public function register_plugin_scripts($hook)
        {
            if (!isset($_GET['tab'])) return;

            if ($hook == 'woocommerce_page_wc-settings' && $_GET['tab'] == 'sendinblue') {
                wp_enqueue_script('wc_sendinblue_js', plugin_dir_url(__FILE__) . 'assets/js/sendinblue_admin.js', false, time());
                wp_enqueue_script('ws-ui-js',  plugin_dir_url(__FILE__)  . '/assets/js/jquery-ui.js');
                wp_enqueue_script('ws-moment-js', plugin_dir_url(__FILE__) . '/assets/js/moment.js');
                wp_enqueue_script('wc-date-js', plugin_dir_url(__FILE__) . '/assets/js/jquery.comiseo.daterangepicker.js');
                wp_localize_script('wc_sendinblue_js', 'ajax_object', array('ajax_url' => admin_url('admin-ajax.php')));

                wp_enqueue_style('wc_sendinblue_css', plugin_dir_url(__FILE__) . '/assets/css/sendinblue_admin.css', false, time());
                wp_enqueue_style('ws-ui-css',  plugin_dir_url(__FILE__) . '/assets/css/jquery-ui.css');
                wp_enqueue_style('wc-date-css', plugin_dir_url(__FILE__) . '/assets/css/jquery.comiseo.daterangepicker.css');
                wp_enqueue_script('wc-chosen-js', plugin_dir_url(__FILE__) . '/assets/js/chosen.jquery.min.js', array('jquery'), null);
                wp_enqueue_style('wc-chosen-css', plugin_dir_url(__FILE__) . '/assets/css/chosen.min.css');

            }
            return;
        }

        /**
         * Add settings page
         *
         * @param array $settings
         * @return array
         */
        public function add_settings_page($settings)
        {
            $settings[] = require_once('includes/wc-sendinblue-settings.php');
            return $settings;
        }

        /**
         * Load customizations after WC is loaded
         */
        public function load_customizations()
        {
            if(!isset($_GET['tab']) || $_GET['tab'] != 'sendinblue') return;
            //
            add_action('admin_notices', array($this, 'ws_api_check'));

            WC_Sendinblue::init();

        }

        /**
         * Initialize method.
         *
         */
        public function init()
        {
            // Redirect after activate plugin
            if (get_option('ws_do_activation_redirect', false)) {
                delete_option('ws_do_activation_redirect');
                if(!isset($_GET['activate-multi'])) {
                    wp_redirect(add_query_arg('page', 'wc-settings&tab=sendinblue', admin_url('admin.php')));
                }
            }

            // localization in the init action for WPML support
            load_plugin_textdomain('wc_sendinblue', false, dirname(plugin_basename(__FILE__)) . '/languages');

            // subscribe
            if (isset($_GET['ws_action']) && ($_GET['ws_action'] == 'subscribe')) {
                WC_Sendinblue::subscribe();
                exit;
            }
            if((isset($_GET['ws_action'])) && ($_GET['ws_action'] == 'logout')) {
                WC_Sendinblue::logout();
            }

        }

        /** Lifecycle methods ******************************************************/
        /**
         * Run every time.  Used since the activation hook is not executed when updating a plugin
         */
        private function install()
        {

        }

        /**
         * Check if an api key is correct
         **/
        function ws_api_check() {
            // Check required fields
            if ( WC_Sendinblue::$access_key == '' && WC_Sendinblue::$ws_error_type != '') {
                // Show notice
                echo $this->get_message( __( 'SendinBlue error: ', 'wc_sendinblue' ) . WC_Sendinblue::$ws_error_type );
            }
        }

        /**
         * Get message
         * @return string Error
         */
        private function get_message( $message, $type = 'error' ) {
            ob_start();

            ?>
            <div class="<?php echo $type ?>">
                <p><?php echo $message ?></p>
            </div>
            <?php
            return ob_get_clean();
        }

        /**
         * Display alert when you don't have enough credits
         */
        function ws_admin_credits_notice(){
            if(WC_Sendinblue::$account_info['email_credits'] < 2 && get_option('ws_credits_notice') != 'closed' && get_option('ws_credits_notice') != null) {
                $class = "error notice is-dismissible ws_credits_notice";
                $message = __('You don\'t have enough credits to send email through <b>SendinBlue SMTP</b>. ', 'wc_sendinblue');
                $url = '<i>'.sprintf(__('To buy more credits, please click %s.', 'wc_sendinblue').'</i>',
                    "<a target='_blank' href='https://www.sendinblue.com/pricing?utm_source=wordpress_plugin&utm_medium=plugin&utm_campaign=module_link' class='ws_refresh'>".__('here','wc_sendinblue')."</a>");
                $button = '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>';
                echo "<div class=\"$class\"> <p>$message$url</p>$button</div>";
            }
        }

        /**
         * WooCommerce 2.2 support for wc_get_order
         * @access private
         * @param int $order_id
         * @return void
         */
        private function wc_get_order($order_id)
        {
            if (function_exists('wc_get_order')) {
                return wc_get_order($order_id);
            } else {
                return new WC_Order($order_id);
            }
        }

        /**
         * by Subscription and SMS Options
         * order_status_changed function.
         * @access public
         * @return void
         */
        public function ws_order_status_changed($id, $status = 'pending',$new_status = 'on-hold')
        {
            self::$order_template_sib = $new_status;
            // current status of an order ($new_status) will be "on-hold" or "processing " after created
            if($this->customizations['ws_order_event'] == 'on-hold'){
                $compareStatus = 'on-hold|processing';
            }else{
                $compareStatus = $this->customizations['ws_order_event'];
            }

            // Get WC order
            $order = $this->wc_get_order($id);

            // Customer will be added to a list after subscribe event occur
            if ($this->ws_subscribe_enable == 'yes' && strpos($compareStatus, $new_status) !== false) {

                $ws_dopt_enabled = isset($this->customizations['ws_dopt_enabled']) ? $this->customizations['ws_dopt_enabled'] : 'no';
                // get the ws_opt_in value from the post meta. (it is coming form front)
                $ws_opt_in = get_post_meta($id, 'ws_opt_in', true); // yes or no
                $info = array();
                if(isset($this->customizations['ws_matched_lists']))
                {
                    foreach ( $this->customizations['ws_matched_lists'] as $key => $attr)
                    {
                        if ( version_compare( get_option( 'woocommerce_db_version'), '3.0', '>=' ))  {
                            $billing_data = $order->get_data(); 
                            $info[$key] = $billing_data['billing'][str_replace('billing_', '', $attr)];
                        }
                        else{
                            $info[$key] = $order->$attr;
                        }
                    }
                }
                if( version_compare( get_option( 'woocommerce_db_version'), '3.0', '>=' )){
                    $info = array_merge($info, array(
                        /* woocommerce attrs */
                        //'PRICE' => $order->order_total,
                        //'PAYMENT METHOD' => $order->payment_method_title,
                        'ORDER_ID' => $id,
                        'ORDER_DATE' => date('d-m-Y', strtotime($order->get_date_created())),
                        'ORDER_PRICE' => $order->get_total(),
                    )) ;
                    $subscribe_email = $order->get_billing_email();
                }
                else{
                    $info = array_merge($info, array(
                        /* woocommerce attrs */
                        //'PRICE' => $order->order_total,
                        //'PAYMENT METHOD' => $order->payment_method_title,
                        'ORDER_ID' => $id,
                        'ORDER_DATE' => strtotime( $order->order_date),
                        'ORDER_PRICE' => $order->order_total,
                    )) ;
                    $subscribe_email = $order->billing_email;
                }

                // to check if the email is in list already
                $wc_sib = new WC_Sendinblue();
                $exist_user = $wc_sib->check_subscriber($subscribe_email); // 'success'- exist or 'failure' - new

                if( $exist_user != 'success' ) { // we should add new customers and unconfirmed doptin customers
                    if ($ws_dopt_enabled == 'yes' && $ws_opt_in == 'yes') {
                        $wc_sib_smtp = new WC_Sendinblue_SMTP();
                        $dopt_template_id = !isset($this->customizations['ws_dopt_templates']) ? 0 : $this->customizations['ws_dopt_templates'];

                        $temp_list_id = $this->customizations['ws_dopt_list_id']; // Temp - DOUBLE OPTIN
                        $wc_sib_smtp->double_optin_signup($subscribe_email, $this->customizations['ws_sendinblue_list'], $info, $dopt_template_id, $temp_list_id);

                        // subscribe with double opt-in
                        $info['DOUBLE_OPT-IN'] = '2'; // no
                        $wc_sib->create_subscriber($subscribe_email, $temp_list_id, $info);
                    } else if ($ws_opt_in == 'yes') {
                        $wc_sib->create_subscriber($subscribe_email, $this->customizations['ws_sendinblue_list'], $info);
                    }
                }else if($exist_user == 'success'){
                    // update(add) transactional variables for existing users already
                    $wc_sib->create_subscriber($subscribe_email, $this->customizations['ws_sendinblue_list'], $info);
                }
            }

            // send confirmation SMS
            if($this->ws_sms_enable == 'yes') {

                $wc_sib_sms = new WC_Sendinblue_SMS();
                $ws_sms_send_after = isset($this->customizations['ws_sms_send_after']) ? $this->customizations['ws_sms_send_after'] : 'no';
                $ws_sms_send_shipment = isset($this->customizations['ws_sms_send_shipment']) ? $this->customizations['ws_sms_send_shipment'] : 'no';

                // send a SMS confirmation for order confirmation
                if ($ws_sms_send_after == 'yes' && strpos($compareStatus, $new_status) !== false){
                    $from = $this->customizations['ws_sms_sender_after'];
                    $text = $this->customizations['ws_sms_send_msg_desc_after'];
                    $wc_sib_sms->ws_send_confirmation_sms($order, $from, $text);
                }
                // send a SMS confirmation for the shipment of the order
                if ($ws_sms_send_shipment == 'yes' && $new_status == 'completed'){
                    $from = $this->customizations['ws_sms_sender_shipment'];
                    $text = $this->customizations['ws_sms_send_msg_desc_shipment'];
                    $wc_sib_sms->ws_send_confirmation_sms($order, $from, $text);
                }
            }
        }

        /**
         * Add the opt-in checkbox to the checkout fields (to be displayed on checkout).
         */
        function maybe_add_checkout_fields_terms($checkout_fields)
        {
            $ws_opt_field = isset($this->customizations['ws_opt_field']) ? $this->customizations['ws_opt_field'] : 'no';
            $display_location = isset($this->customizations['ws_opt_checkbox_location']) ? $this->customizations['ws_opt_checkbox_location'] : '';
            if ('yes' == $ws_opt_field && 'terms_condition' == $display_location) {
                ?>
                <p class="form-row terms woocommerce-validated" id="ws_opt_in_field" style="clear:both;">
                    <label class="checkbox">
                        <input type="checkbox" class="input-checkbox" name="ws_opt_in" <?php echo ($this->customizations['ws_opt_default_status'] == 'checked' ? 'checked' : '');?>>
                        <?php echo esc_attr($this->customizations['ws_opt_field_label']); ?>
                    </label>
                </p>
                <?php

            }
        }
    
       function maybe_add_checkout_fields($checkout_fields)
        {
            $display_location = isset($this->customizations['ws_opt_checkbox_location']) ? $this->customizations['ws_opt_checkbox_location'] : '';

            if (empty($display_location)) {
                $display_location = 'billing';
            }
            $ws_opt_field = isset($this->customizations['ws_opt_field']) ? $this->customizations['ws_opt_field'] : 'no';
            if ('yes' == $ws_opt_field) {
                $checkout_fields[$display_location]['ws_opt_in'] = array(
                    'type' => 'checkbox',
                    'label' => esc_attr($this->customizations['ws_opt_field_label']),
                    'default' => $this->customizations['ws_opt_default_status'] == 'checked' ? 1 : 0,
                );
            }

            return $checkout_fields;
        }

        /**
         * When the checkout form is submitted, save opt-in value.
         */
        function maybe_save_checkout_fields($order_id)
        {
            $ws_opt_enable = isset($this->customizations['ws_opt_field']) ? $this->customizations['ws_opt_field'] : 'no';
            if ('yes' == $ws_opt_enable) {
                $opt_in = isset($_POST['ws_opt_in']) ? 'yes' : 'no';
                update_post_meta($order_id, 'ws_opt_in', $opt_in);
            }else {
                //customer will be added to a list
                update_post_meta($order_id, 'ws_opt_in', 'yes');
            }
        }

        /**
         * When SendinBlue is enabled to send Woocommerce emails
         * replace email template with one of SendinBlue instead of Woo template
         */
        function ws_get_template_type($path,$file){
            $files = explode('/', $file); if(!is_array($files) || !isset($files[1])) return $path;
            $files = explode('.', $files[1]); if(!is_array($files)) return $path;
            $type = $files[0];
            // ex, admin-new-order.php to admin-new-order
            $email_type = array(
                'admin-new-order' => 'New Order',
                'admin-cancelled-order' => 'Cancelled Order',
                'customer-completed-order' => 'Completed Order',
                'customer-new-account' => 'New Account',
                'customer-processing-order' => 'Processing Order',
                'customer-refunded-order' => 'Refunded Order',
                'customer-note' => 'Customer Note',
                'customer-on-hold-order' => 'Order On-Hold',
                'admin-failed-order' => 'Failed Order'
            );
            if(array_key_exists($type, $email_type)) {
                $template_type = $email_type[$type]; // ex, New order
                $template_ids = get_option('ws_email_templates', array());
                $template_id = $template_ids[$template_type];
                
                $template = array(
                    'id' => $template_id,
                    'type' => $template_type
                );
                self::$order_template_sib = $template;
            }
            return $path;
        }

        /**
         * Get new customer id
         *
         * @param int $customer_id - new customer id.
         */
        function get_new_customer_id($customer_id, $customer_data){
            $this->customer_id = $customer_id;
            $this->customer_data = $customer_data;
        }

        // Add template tags in email header
        function woocommerce_mail_header($header){

            $template = self::$order_template_sib;
            $header .= 'X-Mailin-Tag: ' . $template['type'] . "\r\n";
            // from name
            $from_name = WC_Emails::instance()->get_from_name();
            $from_address = WC_Emails::instance()->get_from_address();
            $header .= 'From: ' . $from_name . ' <' . $from_address . '>' . "\r\n";

            return $header;
        }
        // Replace email template with one of SendinBlue
        function woocommerce_mail_content($message){
            if($this->ws_template_enable == 'yes'){

                $template = self::$order_template_sib;
                if(empty($template) || $template['id'] == '0') return $message;

                $sib_templates = WC_Sendinblue_API::get_templates();
                $sib_template = $sib_templates[$template['id']];
                // replace a transactional attributes (ORDER_ID,ORDER_DATE,ORDER_PRICE,...)
                $order = $this->wc_get_order($this->order_id);
                if($order != null) {
                    $items = $order->get_items();
                    $show_download_link = $order->is_download_permitted();
                    $refunded_orders= $order->get_refunds();
                    $refunded_amount = 0;
                    if ( !empty($refunded_orders) ) {
                        foreach ( $refunded_orders as $refunded_order )
                        {
                            $refunded_amount += $refunded_order->get_amount();
                        }
                    }
                    // get download product link
                    ob_start();
                    if ($show_download_link) {
                        foreach ($items as $item_id => $item) {
                            if( version_compare( get_option( 'woocommerce_db_version'), '3.0', '>=' )){
                                wc_display_item_downloads($item);
                            }
                            else
                            {
                                $order->display_item_downloads($item);
                            }
                        }
                    }
                    $order_download_link = ob_get_contents();
                    ob_clean();

                    // get order product details
                    $order_detail = '<table style="padding-left: 0px;width: 100%;text-align: left;"><tr><th>'.__('Products', 'wc_sendinblue').'</th><th>'.__('Quantity', 'wc_sendinblue').'</th><th>'.__('Price', 'wc_sendinblue').'</th></tr>';
                    foreach ($items as $item) {
                        if(isset($item['variation_id']) && $item['variation_id'] != '' )
                        {
                            $product = new WC_Product_Variation($item['variation_id']);
                        }
                        else {
                            $product = new WC_Product($item['product_id']);
                        }
                        $product_name = $item['name'];
                        $product_quantity = $item['qty'];
                        $sub_total = $product->get_price() * $product_quantity;
                        if( version_compare( get_option( 'woocommerce_db_version'), '3.0', '>=' )){
                            $product_price = wc_price($sub_total, array('currency' => $order->get_currency()));
                        }
                        else{
                            $product_price = wc_price($sub_total, array('currency' => $order->order_currency));
                        }
                        $order_detail .= '<tr><td>' . $product_name . '</td><td>' . $product_quantity . '</td><td>' . $product_price . '</td></tr>';
                    }
                    $order_detail .= '</table>';
                    if( version_compare( get_option( 'woocommerce_db_version'), '3.0', '>=' ))
                    {
                        $orders = array(
                            '{ORDER_ID}' => $order->get_id(),
                            '{BILLING_FIRST_NAME}' => $order->get_billing_first_name(),
                            '{BILLING_LAST_NAME}' => $order->get_billing_last_name(),
                            '{BILLING_COMPANY}' => $order->get_billing_company(),
                            '{BILLING_ADDRESS_1}' => $order->get_billing_address_1(),
                            '{BILLING_ADDRESS_2}' => $order->get_billing_address_2(),
                            '{BILLING_CITY}' => $order->get_billing_city(),
                            '{BILLING_STATE}' => $order->get_billing_state(),
                            '{BILLING_POSTCODE}' => $order->get_billing_postcode(),
                            '{BILLING_COUNTRY}' => $order->get_billing_country(),
                            '{BILLING_PHONE}' => $order->get_billing_phone(),
                            '{BILLING_EMAIL}' => $order->get_billing_email(),
                            '{SHIPPING_FIRST_NAME}' => $order->get_shipping_first_name(),
                            '{SHIPPING_LAST_NAME}' => $order->get_shipping_last_name(),
                            '{SHIPPING_COMPANY}' => $order->get_shipping_company(),
                            '{SHIPPING_ADDRESS_1}' => $order->get_shipping_address_1(),
                            '{SHIPPING_ADDRESS_2}' => $order->get_shipping_address_2(),
                            '{SHIPPING_CITY}' => $order->get_shipping_city(),
                            '{SHIPPING_STATE}' => $order->get_shipping_state(),
                            '{SHIPPING_POSTCODE}' => $order->get_shipping_postcode(),
                            '{SHIPPING_COUNTRY}' => $order->get_shipping_country(),
                            '{CART_DISCOUNT}' => $order->get_discount_total(),
                            '{CART_DISCOUNT_TAX}' => $order->get_discount_tax(),
                            '{SHIPPING_METHOD_TITLE}' => $order->get_shipping_method(),
                            '{CUSTOMER_USER}' => $order->get_customer_user_agent(),
                            '{ORDER_KEY}' => $order->get_order_key(),
                            '{ORDER_DISCOUNT}' => wc_price($order->get_discount_total(), array('currency' => $order->get_currency())),
                            '{ORDER_TAX}' => wc_price($order->get_total_tax(), array('currency' => $order->get_currency())),
                            '{ORDER_SHIPPING_TAX}' => wc_price($order->get_shipping_tax(), array('currency' => $order->get_currency())),
                            '{ORDER_SHIPPING}' => wc_price($order->get_shipping_total(), array('currency' => $order->get_currency())),
                            '{ORDER_PRICE}' => wc_price($order->get_total(), array('currency' => $order->get_currency())),
                            '{ORDER_DATE}' => date('d-m-Y', strtotime($order->get_date_created())),
                            '{ORDER_SUBTOTAL}' => wc_price($order->get_subtotal(), array('currency' => $order->get_currency())),
                            '{ORDER_DOWNLOAD_LINK}' => $order_download_link,
                            '{ORDER_PRODUCTS}' => $order_detail,
                            '{PAYMENT_METHOD}' => $order->get_payment_method(),
                            '{PAYMENT_METHOD_TITLE}' => $order->get_payment_method_title(),
                            '{CUSTOMER_IP_ADDRESS}' => $order->get_customer_ip_address(),
                            '{CUSTOMER_USER_AGENT}' => $order->get_customer_user_agent(),
                            '{REFUNDED_AMOUNT}' => wc_price( $refunded_amount, array('currency' => $order->get_currency()) ),
                            // for admin
                            '[ORDER_LINK]' => admin_url('post.php?post=' . $order->get_id() . '&action=edit')
                        );
                    }
                    else{
                        $orders = array(
                            '{ORDER_ID}' => $order->id,
                            '{BILLING_FIRST_NAME}' => $order->billing_first_name,
                            '{BILLING_LAST_NAME}' => $order->billing_last_name,
                            '{BILLING_COMPANY}' => $order->billing_company,
                            '{BILLING_ADDRESS_1}' => $order->billing_address_1,
                            '{BILLING_ADDRESS_2}' => $order->billing_address_2,
                            '{BILLING_CITY}' => $order->billing_city,
                            '{BILLING_STATE}' => $order->billing_state,
                            '{BILLING_POSTCODE}' => $order->billing_postcode,
                            '{BILLING_COUNTRY}' => $order->billing_country,
                            '{BILLING_PHONE}' => $order->billing_phone,
                            '{BILLING_EMAIL}' => $order->billing_email,
                            '{SHIPPING_FIRST_NAME}' => $order->shipping_first_name,
                            '{SHIPPING_LAST_NAME}' => $order->shipping_last_name,
                            '{SHIPPING_COMPANY}' => $order->shipping_company,
                            '{SHIPPING_ADDRESS_1}' => $order->shipping_address_1,
                            '{SHIPPING_ADDRESS_2}' => $order->shipping_address_2,
                            '{SHIPPING_CITY}' => $order->shipping_city,
                            '{SHIPPING_STATE}' => $order->shipping_state,
                            '{SHIPPING_POSTCODE}' => $order->shipping_postcode,
                            '{SHIPPING_COUNTRY}' => $order->shipping_country,
                            '{CART_DISCOUNT}' => $order->cart_discount,
                            '{CART_DISCOUNT_TAX}' => $order->cart_discount_tax,
                            '{SHIPPING_METHOD_TITLE}' => $order->shipping_method_title,
                            '{CUSTOMER_USER}' => $order->customer_user,
                            '{ORDER_KEY}' => $order->order_key,
                            '{ORDER_DISCOUNT}' => wc_price( $order->order_discount, array('currency' => $order->order_currency)),
                            '{ORDER_TAX}' => wc_price( $order->order_tax, array('currency' => $order->order_currency)),
                            '{ORDER_SHIPPING_TAX}' => wc_price( $order->order_shipping_tax, array('currency' => $order->order_currency)),
                            '{ORDER_SHIPPING}' => wc_price( $order->order_shipping, array('currency' => $order->order_currency)),
                            '{ORDER_PRICE}' => wc_price( $order->order_total, array('currency' => $order->order_currency)),
                            '{ORDER_DATE}' => $order->order_date,
                            '{ORDER_SUBTOTAL}' => wc_price($order->order_total - $order->order_shipping,array('currency' => $order->order_currency)),
                            '{ORDER_DOWNLOAD_LINK}' => $order_download_link,
                            '{ORDER_PRODUCTS}' => $order_detail,
                            '{PAYMENT_METHOD}' => $order->payment_method,
                            '{PAYMENT_METHOD_TITLE}' => $order->payment_method_title,
                            '{CUSTOMER_IP_ADDRESS}' => $order->customer_ip_address,
                            '{CUSTOMER_USER_AGENT}' => $order->customer_user_agent,
                            '{REFUNDED_AMOUNT}' => wc_price( $refunded_amount, array('currency' => $order->order_currency) ),
                            // for admin
                            '[ORDER_LINK]' => admin_url( 'post.php?post=' . $order->id . '&action=edit' )
                        );
                    }
                }
                else { // for new account email
                    $customer_id = $this->customer_id;
                    $customer_data = $this->customer_data;
                    if( $customer_id ) {
                        $orders = array(
                            '{USER_LOGIN}' => $customer_data['user_login'],
                            '{USER_PASSWORD}'  => $customer_data['user_pass']
                        );
                    }
                    else {
                        $orders = array();
                    }
                }

                foreach ($orders as $tag => $value) {
                    $sib_template['content'] = str_replace($tag, $value, $sib_template['content']);
                }
                return $sib_template['content'];
            }
            return $message;
        }
        // Replace css of email template
        function ws_get_email_style($css){
            //
            return $css;
        }
        /* end of replace email template */

        /**
         * send notify email for limit of sms credits
         */
        function do_sms_limit_notify() {
            // do something every day
            $sms_limit = isset($this->customizations['ws_sms_credits_limit']) ? $this->customizations['ws_sms_credits_limit'] : 0;
            $sms_limit_email = isset($this->customizations['ws_sms_credits_notify_email']) ? $this->customizations['ws_sms_credits_notify_email'] : '';
            $notify_status = isset($this->customizations['ws_sms_credits_notify']) ? $this->customizations['ws_sms_credits_notify'] : 'no';
            $current_sms_num = WC_Sendinblue::ws_get_credits();

            if( $notify_status == 'yes' && $sms_limit_email != '' && $sms_limit != 0 && $current_sms_num < $sms_limit ){
                $subject = __('Notification of your credits', 'wc_sendinblue');
                WC_Sendinblue_SMTP::send_email('notify', $sms_limit_email, $subject , $current_sms_num);
            }
        }

        // custom schedule
        function ws_add_weekly_cron_schedule( $schedules ) {
            $schedules['weekly'] = array(
                'interval' => 604800, // 1 week in seconds
                'display'  => __( 'Once Weekly' ),
            );
            return $schedules;
        }
        /**
         * Uninstall method is called once uninstall this plugin
         * delete tables, options that used in plugin
         */
        static function uninstall()
        {
            $setting = array();
            update_option('ws_main_option', $setting);
            update_option('wc_sendinblue_settings', $setting);
            update_option('ws_email_templates',$setting);
        }

        /**
         * Deactivate method is called once deactivate this plugin
         */
        static function deactivate()
        {
            SIB_Model_Country::remove_table();

            wp_clear_scheduled_hook('ws_hourly_event');

            // remove sync users option
            delete_option('ws_sync_users');

            // remove transients
            WC_Sendinblue_API::remove_transients();
        }

        /**
         * Install method is called once install this plugin.
         * create tables, default option ...
         */
        static function activate()
        {
            SIB_Model_Contact::create_table();

            // Get the country code data
            SIB_Model_Country::create_table();

            $file = fopen(plugin_dir_path(__FILE__) . "/model/country_code.csv", "r");
            $country_code = array();
            while (!feof($file)) {
                $code = fgetcsv($file);
                $country_code[$code[0]] = $code[1];
            }
            fclose($file);

            SIB_Model_Country::Initialize($country_code);

            // redirect option
            update_option('ws_do_activation_redirect', true);
        }



        /* ajax module for dismiss alert */
        public function ajax_dismiss_alert(){
            update_option('ws_credits_notice', 'closed');
            wp_send_json('success');
        }

        /* ajax module for initialize transients */
        public function ajax_transient_refresh(){
            // remove transients
            WC_Sendinblue_API::remove_transients();
            wp_send_json('success');
        }

        /* ajax module for sync customers to contact list */
        public function ajax_sync_users(){
            $postData = $_POST['data'];
            if(isset($postData['errAttr'])) {  wp_send_json(array('code'=>'attr_duplicated', 'message'=>sprintf(__('The attribute %s is duplicated. You can select one at a time.','wc_sendinblue'), '<b>'.$_POST['data']['errAttr'].'</b>')));}

            $listIDs = (array)$postData['list_id'];
            unset($postData['list_id']);

            $usersData = 'EMAIL';
            foreach($postData as $attrSibName=>$attrWP){
                $usersData.= ";".$attrSibName;
            }

            // sync users to sendinblue
            // create body data like csv
            // NAME;SURNAME;EMAIL\n Name1;Surname1;example1@example.net\nName2;Surname2;example2@example.net
            $contentData = '';
                $users = get_users(array('role' => 'customer'));
                $count_customers = count($users); //count_users();
                if(!empty($users))
                {
                    foreach($users as $user){
                        $userId = $user->ID;
                        $user_info = get_userdata($userId);
                        $userData = $user_info->user_email;
                        foreach($postData as $attrSibName=>$attrWP){
                            $userData.= ";".$user_info->$attrWP;
                        }
                        $contentData .= "\n".$userData;
                    }
                }
            if($contentData == '') { wp_send_json(array('code'=>'empty_users', 'message'=>__('There is not any user in the roles.','wc_sendinblue'))); }

            $usersData .= $contentData;
            $result = WC_Sendinblue_API::sync_users($usersData, $listIDs);
            if($result['code'] == 'success') update_option('ws_sync_users', $count_customers);
             wp_send_json($result);
        }
        /**
         * Notice wp_mail is not possible
         */
        static function wpMailNotices() {
            if ( self::$wp_mail_conflict ) {
                echo '<div class="error"><p>'.__('You cannot to use SendinBlue SMTP now because wp_mail has been declared by another process or plugin. ', 'ninja_forms_sib') . '</p></div>';
            }
        }
        




        

        // Marketing automation fn start BG201904
        /**
         * Install marketing automation script in header
         */
        public function install_ma_script() {
            if ($this->checkWpPlugin() == true) { return; }
            
            $general_settings = get_option('ws_main_option');
            if (isset($general_settings['ma_key'])) {
                 $ma_key = $general_settings['ma_key'];
                 if ($ma_key != '') {
                    if ($this->customizations['ws_marketingauto_enable'] == 'yes') {
                        $output = '<!-- MA by SendinBlue WooCommerce integration -->
                            <script type="text/javascript">
                                (function() {window.sib ={equeue:[],client_key:"'. $ma_key .'"};';
                                if ( $found_email_id = $this->get_email_id() ) {
                                    $output .= 'window.sib.email_id = "' . $found_email_id . '";';
                                }                               
                                $output .= 'window.sendinblue = {}; for (var j = [\'track\', \'identify\', \'trackLink\', \'page\'], i = 0; i < j.length; i++) { (function(k) { window.sendinblue[k] = function() { var arg = Array.prototype.slice.call(arguments); (window.sib[k] || function() { var t = {}; t[k] = arg; window.sib.equeue.push(t);})(arg[0], arg[1], arg[2]);};})(j[i]);}var n = document.createElement("script"),i = document.getElementsByTagName("script")[0]; n.type = "text/javascript", n.id = "sendinblue-js", n.async = !0, n.src = "https://sibautomation.com/sa.js?key=" + window.sib.client_key, i.parentNode.insertBefore(n, i), window.sendinblue.page();})();
                            </script>';
                        echo $output;
                    }                   
                }
            }
        }
        /**
         * Helper fn for install_ma_script (Install marketing automation script in header)
         */
        public function checkWpPlugin() {
            $wp_plugin_options = get_option('sib_home_option');
            if (isset($wp_plugin_options) && is_array($wp_plugin_options)) {
                if (isset($wp_plugin_options['activate_ma'])) {
                    if ($wp_plugin_options['activate_ma'] == 'yes') { return true; }
                }
            }
            return false;
        }
        /**
         * Add events submiting JS
         */
        public function install_ma_event_listeners(){
            ?>
            <!-- SendinBlue Marketing automation start -->
            <script type="text/javascript">
                var tracking_event_type = '';
                var tracking_event_data = '';
                var ws_disable_next_event_cart_deleted = 0; <?php // cart is deleted on purchase but for this we do not want event cart_deleted to be triggered ?>
                
                function ws_ma_submit_event(submit_tracking_event_type, submit_tracking_event_data) {
                    sendinblue.track(submit_tracking_event_type, {}, JSON.parse(submit_tracking_event_data));
                }
                <?php // shop page ?>
                jQuery(function($) { 
                    $(document.body) 
                        .on("added_to_cart", function (event, fragments, hash, button) { <?php // handle add to cart button ?>
                            ws_ma_submit_event(fragments.tracking_event_type, fragments.tracking_event_data);
                        })
                        .on("removed_from_cart", function (event, fragments, hash, button) { <?php // handle remove from cart in mini-cart ?>
                            ws_ma_submit_event(fragments.tracking_event_type, fragments.tracking_event_data);
                        });
                });
                <?php // cart page ?>
                jQuery(function($) { 
                    $(document.body).on("wc_fragments_refreshed", function () {
                        if (ws_disable_next_event_cart_deleted == 1) {
                            // cart is deleted because order is completed
                            ws_disable_next_event_cart_deleted = 0;
                        } else {
                            tracking_event_type = $("#ws_ma_event_type").attr("data-ws_ma_event_type_data");
                            tracking_event_data = $("#ws_ma_event_data").attr("data-ws_ma_event_data_data");
                            ws_ma_submit_event(tracking_event_type, tracking_event_data);
                        }
                    });
                });
            </script>
            <!-- SendinBlue Marketing automation end -->
            <?php
        }
        
        /**
         * Cart update/change event hooks
         */     
        public function ws_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
            // additional actions if needed... 
            $this->ws_cart_updated();
        } 
        public function ws_update_cart_action_cart_updated($cart_updated) {
            $this->ws_cart_updated();
        } 
        public function ws_cart_item_removed($cart_item_key, $caller_this) {
            $this->ws_cart_updated();
        }
        public function ws_cart_item_restored($cart_item, $caller_this) {
            $this->ws_cart_updated();
        }
        /**
         * Cart updated/changed 
         */
        public function ws_cart_updated() {
            if (is_ajax()) {
                // JS + ajax will do event tracking through fragments
            } else {
                // prepare data 
                $this->load_tracking_data_cart();
                $email_id = $this->get_email_id();
                // prepare entire code (beside all casees when "ajax add to cart" is disabled this is code is used for single page item add button when "ajax add to cart" is enabled)
                $code = $this->build_event_code('track', $this->tracking_event_type, $email_id, $this->tracking_event_data);    
                wc_enqueue_js( $code );
            }
        }
        /**
         * Cart fragments helping fns
         */
        function ws_cart_custom_fragment_load() {
            echo '<input id="ws_ma_event_type" type="hidden" style="display: none" />';
            echo '<input id="ws_ma_event_data" type="hidden" style="display: none" />';
        }
        function ws_cart_custom_fragment( $cart_fragments ) {
            $this->load_tracking_data_cart();
            // for events without fragments in parmeters - fragments transfered over html
            $cart_fragments['#ws_ma_event_type']= '<input id="ws_ma_event_type" type="hidden" style="display: none" data-ws_ma_event_type_data="' . $this->tracking_event_type . '" />';
            $cart_fragments['#ws_ma_event_data']= "<input id='ws_ma_event_data' type='hidden' style='display: none' data-ws_ma_event_data_data='" . $this->tracking_event_data . "' />";
            // for events with fragments in parmeters
            $cart_fragments['tracking_event_type'] = $this->tracking_event_type;
            $cart_fragments['tracking_event_data'] = $this->tracking_event_data;
            return $cart_fragments;
        }
        /**
         * Event checkout completed 
         */
        public function ws_checkout_completed($order_id) {
            $this->load_tracking_data_order($order_id);
            $email_id = $this->get_email_id();
            
            $code = $this->build_event_code('track', $this->tracking_event_type, $email_id, $this->tracking_event_data, 'ws_disable_next_event_cart_deleted = 1;');
            wc_enqueue_js( $code );
        }

        /**
         * Get specific data from cart formated according to trackin needs 
         */
        public function load_tracking_data_cart() {
            $data = new stdClass;
            if (WC()->cart->is_empty()) {
                $this->tracking_event_type = 'cart_deleted';
                $data->id = $this->get_wc_cart_id(); 
            } else {
                $this->tracking_event_type = 'cart_updated';
                $data->id = $this->get_wc_cart_id();

                $data->data = new stdClass;
                $cartitems = WC()->cart->get_cart();
                $totals = WC()->cart->get_totals();
                $data->data->affiliation = get_bloginfo( 'name' ); 
                $data->data->subtotal = !empty($totals['subtotal']) ? $totals['subtotal'] : 0;
                $data->data->discount = !empty($totals['discount_total']) ? $totals['discount_total'] : 0;
                $data->data->shipping = !empty($totals['shipping_total']) ? $totals['shipping_total'] : 0;
                $data->data->total_before_tax = !empty($totals['cart_contents_total']) ? $totals['cart_contents_total'] : 0;    
                $data->data->tax = !empty($totals['total_tax']) ? $totals['total_tax'] : 0;
                $data->data->total = !empty($totals['total']) ? $totals['total'] : 0;
                $data->data->currency = get_woocommerce_currency();
                $data->data->url = wc_get_cart_url();
                $data->data->checkouturl = wc_get_checkout_url();
                
                $data->data->items = array();
                foreach ($cartitems as $key => $cartitem) {
                    // $cartitem => array, $cartitem['data'] => class [ (WC_Product_Simple extends WC_Product) OR (WC_Product_Variation extends WC_Product_Simple)]
                    $item = new stdClass;
                    $item->name = $cartitem['data']->get_title();
                    $item->sku = $cartitem['data']->get_sku(); 
                    $item->id = !empty($cartitem['product_id']) ? $cartitem['product_id'] : '';
                    $cats_array = wp_get_post_terms($item->id, 'product_cat', array('fields' => 'names'));  
                    $item->category = implode(',', $cats_array);
                    $item->variant_id = !empty($cartitem['variation_id']) ? $cartitem['variation_id'] : ''; 
                    $item->variant_name = implode(',', $cartitem['variation']); 
                    $item->price = !empty($cartitem['line_total']) ? $cartitem['line_total'] : 0;  
                    $item->quantity = !empty($cartitem['quantity']) ? $cartitem['quantity'] : 0; 
                    $image_full = $cartitem['data']->get_image('woocommerce_single');
                    $dom = new DOMDocument;
                    @$dom->loadHTML($image_full);
                    $nodelist = $dom->getElementsByTagName('img');
                    foreach ($nodelist as $node) { $item->image = $node->getAttribute('src'); }
                    $item->url = $cartitem['data']->get_permalink(); 
                    array_push($data->data->items, $item);
                }
                                        
            }
            $this->tracking_event_data = json_encode($data, JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        /**
         * Get specific data from order formated according to tracking needs
         */
        public function load_tracking_data_order($order_id) {
            $this->tracking_event_type = 'order_completed';

            $order = wc_get_order( $order_id );
            
            $data = new stdClass;
            $data->id = $this->get_wc_cart_id();
            
            $data->data = new stdClass;
            $data->data->id = $order->get_order_number();
            $data->data->affiliation = get_bloginfo( 'name' ); // site name is ok?
            $data->data->date = $order->get_date_created()->date(DATE_ATOM);
            $data->data->subtotal = (int) $order->get_subtotal();
            $data->data->discount = $order->get_total_discount();
            $data->data->shipping = (int) $order->get_shipping_total();
            $data->data->total_before_tax = (int) ($data->data->subtotal - $data->data->discount);  
            $data->data->tax = (int) $order->get_total_tax();
            $data->data->revenue = (int) $order->get_total(); 
            $data->data->currency = $order->get_currency();
            $data->data->url = $order->get_checkout_order_received_url();
            
            $data->data->shipping_address = new stdClass;
            $data->data->shipping_address->firstname = $order->get_shipping_first_name();
            $data->data->shipping_address->lastname = $order->get_shipping_last_name();
            $data->data->shipping_address->company = $order->get_shipping_company();
            $data->data->shipping_address->phone = ""; // does not exist, so just empty value
            $data->data->shipping_address->address1 = $order->get_shipping_address_1();
            $data->data->shipping_address->address2 = $order->get_shipping_address_2();
            $data->data->shipping_address->city = $order->get_shipping_city();
            $data->data->shipping_address->country = $order->get_shipping_country();
            $data->data->shipping_address->state = $order->get_shipping_state();
            $data->data->shipping_address->zipcode = $order->get_shipping_postcode();
            
            $data->data->billing_address = new stdClass;
            $data->data->billing_address->firstname = $order->get_billing_first_name();
            $data->data->billing_address->lastname = $order->get_billing_last_name();
            $data->data->billing_address->company = $order->get_billing_company();
            $data->data->billing_address->phone = $order->get_billing_phone();
            $data->data->billing_address->address1 = $order->get_billing_address_1();
            $data->data->billing_address->address2 = $order->get_billing_address_2();
            $data->data->billing_address->city = $order->get_billing_city();
            $data->data->billing_address->country = $order->get_billing_country();
            $data->data->billing_address->state = $order->get_billing_state();
            $data->data->billing_address->zipcode = $order->get_billing_postcode();

            $data->data->items = array();
            foreach ($order->get_items() as $item_key => $orderitem ) {
                //$orderitem => class WC_Order_Item_Product extends WC_Order_Item
                $product = wc_get_product($orderitem->get_product_id()); // class WC_Product

                $item = new stdClass;   
                $item->name = $orderitem->get_name(); // woo automatically add variants
                $item->sku = $product->get_sku(); 
                // $item->id = $orderitem->get_id(); //  id of the item in order 
                $item->id = $orderitem->get_product_id();
                $cats_array = wp_get_post_terms($item->id, 'product_cat', array('fields' => 'names'));  
                $item->category = implode(',', $cats_array);
                $item->variant_id = $orderitem->get_variation_id(); 
                $variation = new WC_Product_Variation( $item->variant_id );
                $attributes = $variation->get_attributes();
                $item->variant_name = $item->variant_name = implode(',', $attributes);
                $item->price = (int) $orderitem->get_total(); 
                $item->quantity = (int) $orderitem->get_quantity(); 
                $image_full = $product->get_image('woocommerce_single');
                $dom = new DOMDocument;
                @$dom->loadHTML($image_full);
                $nodelist = $dom->getElementsByTagName('img');
                foreach ($nodelist as $node) { $item->image = $node->getAttribute('src'); }
                $item->url = $product->get_permalink(); 
                array_push($data->data->items, $item);
            }

            $this->tracking_event_data = json_encode($data, JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
            
        /**
         * Helper fns
         */     
        public function build_event_code($method, $event, $email_id, $data, $prefix = '') {
            return sprintf( $prefix . 'sendinblue.%s("%s",{"email":"' . $email_id . '"}, %s);', $method, $event, $data );
        }
        public function set_email_id_cookie() {
            $current_user = wp_get_current_user();
            if ($current_user->ID == 0) { // try to find in cookies
                setcookie("email_id",$_COOKIE['email_id'] = $current_user->user_email, time() + 86400, "/");    
            }
        }
        public function get_email_id() {
            $found_email_id = '';
            $current_user = wp_get_current_user();
            if ($current_user->ID == 0) { // try to find in cookies
                if (isset($_COOKIE['email_id']) && $_COOKIE['email_id'] != '') {
                    $found_email_id = $_COOKIE['email_id'];         
                }
            } else {
                $found_email_id = $current_user->user_email;
            }
            return $found_email_id;         
        }
        public function get_wc_cart_id() {
            $cookie_id = 'wp_woocommerce_session_';
            $cart_id = '';
            foreach ($_COOKIE as $key => $val) {
                if (strpos($key, $cookie_id) !== false) { $cart_id = $key; }
            }
            return $cart_id;
        }

        // Marketing automation fn end  
        
    }

    /**
     * The WC_Sendinblue_Integration global object
     * @name $WC_Sendinblue_Integration
     * @global WC_Sendinblue_Integration $GLOBALS ['WC_Sendinblue_Integration']
     */
    $GLOBALS['WC_Sendinblue_Integration'] = new WC_Sendinblue_Integration();
}
