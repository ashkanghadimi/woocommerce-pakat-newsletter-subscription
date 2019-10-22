<?php
/*if (!class_exists('Mailin_Woo'))
    require_once 'mailin.php';*/
class WC_Sendinblue {

    public static $customizations;
    /**
     * Access key
     */
    public static $access_key;
    /**
     * Sendinblue lists
     */
    public static $lists;
    /**
     * Sendinblue templates
     */
    public static $templates;
    /**
     * Sendinblue Double opt-in templates
     */
    public static $dopt_templates;
    /**
     * Sendinblue statistics
     */
    public static $statistics;
    /**
     * Sendinblue account info
     */
    public static $account_info;
    /**
     * Error type
     */
    public static $ws_error_type;
    /**
     * Wordpress senders
     */
    public static $senders;
    /**
     * Request url of sendinblue api
     */
    const sendinblue_api_url = 'https://api.sendinblue.com/v2.0';

    public function __construct()
    {

    }

    /**
     * Initialize of setting values for admin user
     */
    public static function init(){

        self::$customizations = get_option('wc_sendinblue_settings', array());

        $general_settings = get_option('ws_main_option');
        self::$access_key = isset($general_settings['access_key']) ? $general_settings['access_key'] : '';

        $error_settings = get_option('ws_error_type', array());
        self::$ws_error_type = isset($error_settings['error_type']) ? $error_settings['error_type'] : '';
        delete_option('ws_error_type');

        //to connect and get account details and lists
        if (self::$access_key != '') {

            try {
                $account = new Mailin_Woo(self::sendinblue_api_url, self::$access_key);
            } catch (Exception $e) {
                $account = null;
                self::$access_key = null;
                update_option('ws_main_option', self::$access_key);
            }

            // get lists
            self::$lists = WC_Sendinblue_API::get_list();

            // get templates
            self::$templates = WC_Sendinblue_API::get_templates();

            self::$dopt_templates = get_transient( 'ws_dopt_' . md5( self::$access_key ) );
            if( self::$dopt_templates == false || self::$dopt_templates === false ){

                $dopt_template = array('0'=>'Default');
                // for double opt-in
                foreach(self::$templates as $id=>$template) {
                    if (strpos($template['content'], 'DOUBLEOPTIN') !== false || strpos( $template['content'], 'doubleoptin' ) != false)
                        $dopt_template[$id] = $template['name'];
                }
                self::$dopt_templates = $dopt_template;
                if ( sizeof( self::$dopt_templates ) > 0 ) {
                    set_transient('ws_dopt_' . md5(self::$access_key), self::$dopt_templates, 60 * 60 * 1);
                }
            }


            // get account's info
            self::$account_info = WC_Sendinblue_API::get_account_info();

            // get statistics
            self::get_wc_templates(); // option - ws_email_templates
            self::$statistics = array();
            $startDate = $endDate = date("Y-m-d");  // format: "Y-m-d";

            self::getActivatedEmailList();

            if((isset($_GET['section']) && $_GET['section'] == '') || !isset($_GET['section'])) {
                self::get_statistics($account, $startDate, $endDate);
            }

            // get senders from wp
            $blogusers = get_users( 'role=Administrator' );
            $senders = array('-1'=>'- Select a sender -');
            foreach($blogusers as $user){
                $senders[$user->user_nicename] = $user->user_email;
            }
            self::$senders = $senders;

        }
    }

    // when admin set up on WC email setting
    static public function getActivatedEmailList(){
        $wc_plugin_id = 'woocommerce_';
        $notification_activation = array();
        $wc_emails_enabled = get_option('wc_emails_enabled');
        foreach ($wc_emails_enabled as $filed => $id){
            $email_settings = get_option($wc_plugin_id . $id . '_settings', null);
            // default emails (ex, New order is checked but value is empty) template don't have a value
            if(!isset($email_settings) || $email_settings['enabled'] == 'yes'){
                array_push($notification_activation, str_replace('_', ' ', str_replace('Customer_', '', str_replace('WC_Email_', '', $filed))));
            }
        }
        update_option('ws_notification_activation',$notification_activation);
    }

    /**
     * Get current SMS credits
     * @return credits
    */
    public static function ws_get_credits(){


        $general_settings = get_option('ws_main_option');
        $account = new Mailin_Woo(self::sendinblue_api_url, $general_settings['access_key']);

        $account_info = $account->get_account();
        $account_data = array();
        foreach ($account_info['data'] as $key=>$info) {
            if (isset($info['plan_type']) && isset($info['credits'])) {
                $account_data[$key]['plan_type'] = $info['plan_type'];
                $account_data[$key]['credits'] = $info['credits'];
            }
        }
        $sms_info = $account_data[1];

        return $sms_info['credits'];
    }

    /**
     * Get SendinBlue email templates regarding to settings
     */
    static function get_wc_templates(){
        $customizations = get_option('wc_sendinblue_settings', array());
        $ws_email_templates = array(
            'New Order' => isset($customizations['ws_new_order_template']) ? $customizations['ws_new_order_template'] : '0', // template id
            'Processing Order' => isset($customizations['ws_processing_order_template']) ? $customizations['ws_processing_order_template'] : '0',
            'Refunded Order' => isset($customizations['ws_refunded_order_template']) ? $customizations['ws_refunded_order_template'] : '0',
            'Cancelled Order' => isset($customizations['ws_cancelled_order_template']) ? $customizations['ws_cancelled_order_template'] : '0',
            'Completed Order' => isset($customizations['ws_completed_order_template']) ? $customizations['ws_completed_order_template'] : '0',
            'Failed Order' => isset($customizations['ws_failed_order_template']) ? $customizations['ws_failed_order_template'] : '0',
            'Order On-Hold' => isset($customizations['ws_on_hold_order_template']) ? $customizations['ws_on_hold_order_template'] : '0',
            'Customer Note' => isset($customizations['ws_customer_note_template']) ? $customizations['ws_customer_note_template'] : '0',
            'New Account' => isset($customizations['ws_new_account_template']) ? $customizations['ws_new_account_template'] : '0',
        );
        update_option('ws_email_templates',$ws_email_templates);
    }
    /**
     * Get statistics regarding to order's status
     */
    public static function get_statistics($account, $startDate, $endDate){

        $ws_notification_activation = get_option('ws_notification_activation',array());
        $statistics = array();

        $customization = get_option('wc_sendinblue_settings', array());
        if(!isset($customization['ws_smtp_enable']) || $customization['ws_smtp_enable'] != 'yes'){
            return array();
        }

        foreach($ws_notification_activation as $template_name){

			$data = array(
                "aggregate" => 0,
                "tag" => $template_name,
                "start_date" => $startDate,
                "end_date" => $endDate,
            );

            $result = $account->get_statistics($data);
            $sent = $delivered = $open = $click = 0;
            foreach($result['data'] as $data){
                $sent += isset($data['requests']) ? $data['requests'] : 0;
                $delivered += isset($data['delivered']) ? $data['delivered'] : 0;
                $open += isset($data['unique_opens']) ? $data['unique_opens'] : 0;//opens
                $click += isset($data['unique_clicks']) ? $data['unique_clicks'] : 0;//clicks
            }
            $statistics[$template_name] = array(
                'name' => $template_name,
                'sent' => $sent,
                'delivered' => $sent != 0 ? round($delivered/$sent*100,2)."%" : "0%",
                'open_rate' => $sent != 0 ? round($open/$sent*100,2)."%" : "0%",
                'click_rate' => $sent != 0 ? round($click/$sent*100,2)."%" : "0%",
            );
        }
        self::$statistics = $statistics;

        return $statistics;
    }
    /**
     * create_subscriber function.
     *
     * @access public
     * @param string $email
     * @param string $list_id
     * @param string $name
     * @return void
     */
    public function create_subscriber($email, $list_id, $info)
    {
        $general_settings = get_option('ws_main_option', array());
        try {

            $account = new Mailin_Woo(self::sendinblue_api_url, $general_settings['access_key']);

            $data = array(
                "email" => $email,
                "attributes" => $info,
                "blacklisted" => 0,
                "listid" => array(intval($list_id)),
                "listid_unlink" => null,
                "blacklisted_sms" => 0
            );
            $response = $account->create_update_user($data);

            return $response['code'];
        } catch (Exception $e) {
            //Authorization is invalid
            //if ($e->type === 'UnauthorizedError')
                //$this->deauthorize();
        }
    } // End create_subscriber()
    /**
     * Check if the user is in list already
     */
    public function check_subscriber($email){
        $general_settings = get_option('ws_main_option', array());
        $account = new Mailin_Woo(self::sendinblue_api_url, $general_settings['access_key']);
        $data = array( "email" => $email );
        $result = $account->get_user($data);
        if($result['code'] == 'success') {
            $is_dopt_checked = isset($result['data']['attributes']['DOUBLE_OPT-IN']) ? $result['data']['attributes']['DOUBLE_OPT-IN'] : '1'; // 1 - yes, 2 - no
            if($is_dopt_checked == '2')
                return 'failure';
        }

        return $result['code'];
    }

    /**
     * Subscribe process for submit on confirmation email
     */
    public static function subscribe()
    {
        $site_domain = str_replace('https://', '', home_url());
        $site_domain = str_replace('http://', '', $site_domain);
        $general_settings = get_option('ws_main_option', array());

        $mailin = new Mailin_Woo(self::sendinblue_api_url, $general_settings['access_key']);
        $code = esc_attr($_GET['code']);
        $list_id = intval($_GET['li']);
        $temp_dopt_id = intval($_GET['temp_id']);

        $contact_info = SIB_Model_Contact::get_data_by_code($code);

        if ($contact_info != false) {
            $email = $contact_info['email'];

            $attributes = maybe_unserialize($contact_info['info']);

            $data = array(
                "email" => $email,
                "attributes" => $attributes,
                "blacklisted" => 0,
                "listid" => array(intval($list_id)),
                "listid_unlink" => array(intval($temp_dopt_id)),
                "blacklisted_sms" => 0
            );
            $response = $mailin->create_update_user($data);
        }
        ?>
        <body style="margin:0; padding:0;">
        <table style="background-color:#ffffff" cellpadding="0" cellspacing="0" border="0" width="100%">
            <tbody>
            <tr style="border-collapse:collapse;">
                <td style="border-collapse:collapse;" align="center">
                    <table cellpadding="0" cellspacing="0" border="0" width="540">
                        <tbody>
                        <tr>
                            <td style="line-height:0; font-size:0;" height="20"></td>
                        </tr>
                        </tbody>
                    </table>
                    <table cellpadding="0" cellspacing="0" border="0" width="540">
                        <tbody>
                        <tr>
                            <td style="line-height:0; font-size:0;" height="20">
                                <div
                                    style="font-family:arial,sans-serif; color:#61a6f3; font-size:20px; font-weight:bold; line-height:28px;">
                                    <?php _e('Thank you for subscribing', 'wc_sendinblue'); ?></div>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                    <table cellpadding="0" cellspacing="0" border="0" width="540">
                        <tbody>
                        <tr>
                            <td style="line-height:0; font-size:0;" height="20"></td>
                        </tr>
                        </tbody>
                    </table>
                    <table cellpadding="0" cellspacing="0" border="0" width="540">
                        <tbody>
                        <tr>
                            <td align="left">

                                <div
                                    style="font-family:arial,sans-serif; font-size:14px; margin:0; line-height:24px; color:#555555;">
                                    <br>
                                    <?php echo __('You have just subscribed to the newsletter of ', 'wc_sendinblue') . $site_domain . ' .'; ?>
                                    <br><br>
                                    <?php _e('-SendinBlue', 'wc_sendinblue'); ?></div>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                    <table cellpadding="0" cellspacing="0" border="0" width="540">
                        <tbody>
                        <tr>
                            <td style="line-height:0; font-size:0;" height="20">
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
            </tbody>
        </table>
        </body>
        <?php
        exit;
    }

    /**
     * @return boolean
     */
    static function wp_mail_native( $to, $subject, $message, $headers = '', $attachments = array() ) {
        require plugin_dir_path( __FILE__ ) . 'function.wp_mail.php';
    }
    // hook wp_mail
    static function sib_email($to, $subject, $message, $headers = '', $attachments = array(),$tags = array(),$from_name = '',$from_email = ''){
        // From email and name
        if ( $from_email == '' ) {
            $from_email = trim(get_bloginfo('admin_email'));
            $from_name = trim(get_bloginfo('name'));
        }
        //
        $from_email  = apply_filters('wp_mail_from', $from_email);
        $from_name = apply_filters('wp_mail_from_name', $from_name);

        // Headers
        if ( empty( $headers ) ) {
            $headers = $reply = $bcc = $cc = array();
        } else {
            if ( !is_array( $headers ) ) {
                // Explode the headers out, so this function can take both
                // string headers and an array of headers.
                $tempheaders = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
            } else {
                $tempheaders = $headers;
            }
            $headers = $reply = $bcc = $cc = array();

            // If it's actually got contents
            if ( !empty( $tempheaders ) ) {
                // Iterate through the raw headers
                foreach ( (array) $tempheaders as $header ) {
                    if ( strpos($header, ':') === false ) {
                        if ( false !== stripos( $header, 'boundary=' ) ) {
                            $parts = preg_split('/boundary=/i', trim( $header ) );
                            $boundary = trim( str_replace( array( "'", '"' ), '', $parts[1] ) );
                        }
                        continue;
                    }
                    // Explode them out
                    list( $name, $content ) = explode( ':', trim( $header ), 2 );

                    // Cleanup crew
                    $name    = trim( $name );
                    $content = trim( $content );

                    switch ( strtolower( $name ) ) {
                        case 'content-type':
                            $headers[trim( $name )] =  trim( $content );
                            break;
                        case 'x-mailin-tag':
                            $headers[trim( $name )] =  trim( $content );
                            break;
                        case 'from':
                            if ( strpos($content, '<' ) !== false ) {
                                // So... making my life hard again?
                                $from_name = substr( $content, 0, strpos( $content, '<' ) - 1 );
                                $from_name = str_replace( '"', '', $from_name );
                                $from_name = trim( $from_name );

                                $from_email = substr( $content, strpos( $content, '<' ) + 1 );
                                $from_email = str_replace( '>', '', $from_email );
                                $from_email = trim( $from_email );
                            } else {
                                $from_name  = '';
                                $from_email = trim( $content );
                            }
                            break;
                        case 'bcc':
                            $bcc[trim( $content )] = '';
                            break;
                        case 'cc':
                            $cc[trim( $content )] = '';
                            break;
                        case 'reply-to':
                            //$reply[] = trim( $content );
                            if ( strpos($content, '<' ) !== false ) {
                                // So... making my life hard again?
                                $reply_to = substr( $content, strpos( $content, '<' ) + 1 );
                                $reply_to = str_replace( '>', '', $reply_to );
                                $reply[] = trim( $reply_to );
                            } else {
                                $reply[] = trim( $content );
                            }
                            break;
                        default:
                            break;
                    }


                }
            }
        }

        // Set destination addresses
        if( !is_array($to) ) $to = explode(',', preg_replace('/\s+/', '', $to)); // strip all whitespace

        $processed_to = array();
        foreach ( $to as $email ) {
            if ( is_array($email) ) {
                $processed_to[] = $email;
            } else {
                $processed_to[$email] = '';
            }
        }
        $to = $processed_to;

        // attachments
        $attachment_content =array();
        if ( !empty( $attachments ) ) {
            foreach ($attachments as $attachment) {
                $content = self::getAttachmentStruct($attachment);
                if (!is_wp_error($content))
                    $attachment_content = array_merge($attachment_content, $content);
            }
        }
        // Common transformations for the HTML part
        // if it is text/plain, New line break found;
        if(strpos($message, "</table>") !== FALSE || strpos($message, "</div>") !== FALSE) {
            // html type
        }else {
            if (strpos($message, "\n") !== FALSE) {
                if (is_array($message)) {
                    foreach ($message as &$value) {
                        $value['content'] = preg_replace('#<(https?://[^*]+)>#', '$1', $value['content']);
                        $value['content'] = nl2br($value['content']);
                    }
                } else {
                    $message = preg_replace('#<(https?://[^*]+)>#', '$1', $message);
                    $message = nl2br($message);
                }
            }
        }

        // sending
        $general_settings = get_option('ws_main_option', array());
        $mailin = new Mailin_Woo(self::sendinblue_api_url, $general_settings['access_key']);

        $data = array(
            "to" => $to,
            "from" => array($from_email, $from_name),
            "cc" => $cc,
            "bcc" => $bcc,
            "replyto" => $reply,
            "subject" => $subject,
            "headers" => $headers,
            "attachment" => $attachment_content,
            "html" => $message,
        );
        try{
            $sent = $mailin->send_email($data);
            return $sent;
        }catch ( Exception $e) {
            return new WP_Error( $e->getMessage() );
        }
    }
    static function getAttachmentStruct($path) {

        $struct = array();

        try {

            if ( !@is_file($path) ) throw new Exception($path.' is not a valid file.');

            $filename = basename($path);

            if ( !function_exists('get_magic_quotes') ) {
                function get_magic_quotes() { return false; }
            }
            if ( !function_exists('set_magic_quotes') ) {
                function set_magic_quotes($value) { return true;}
            }

            $isMagicQuotesSupported = version_compare(PHP_VERSION, '5.3.0', '<')
                && function_exists('get_magic_quotes_runtime')
                && function_exists('set_magic_quotes_runtime');

            if ($isMagicQuotesSupported) {
                // Escape linters check.
                $getMagicQuotesRuntimeFunc = 'get_magic_quotes_runtime';
                $setMagicQuotesRuntimeFunc = 'set_magic_quotes_runtime';

                // Save magic quotes value.
                $magicQuotes = $getMagicQuotesRuntimeFunc();
                $setMagicQuotesRuntimeFunc (0);
            }

            $file_buffer  = file_get_contents($path);
            $file_buffer  = chunk_split(base64_encode($file_buffer), 76, "\n");

            if ($isMagicQuotesSupported) {
                // Restore magic quotes value.
                $setMagicQuotesRuntimeFunc($magicQuotes);
            }

            $struct[$filename]     = $file_buffer;

        } catch (Exception $e) {
            return new WP_Error('Error creating the attachment structure: '.$e->getMessage());
        }

        return $struct;
    }

    /**
     * logout process
     * @return void
     */
    public static function logout()
    {
        $setting = array();
        update_option('ws_main_option', $setting);

        $home_settings = array(
            'activate_email' => 'no'
        );
        update_option('ws_home_option', $home_settings);
        update_option('wc_sendinblue_settings', $setting);
        update_option('ws_email_templates',$setting);
        delete_option('ws_credits_notice');
        // remove sync users option
        delete_option('ws_sync_users');
        // remove transients
        delete_transient('ws_credit_' . md5( WC_Sendinblue::$access_key ));
        delete_transient('ws_temp_' . md5( WC_Sendinblue::$access_key ));
        delete_transient('ws_list_' . md5( WC_Sendinblue::$access_key ));

        wp_redirect(add_query_arg('page', 'wc-settings&tab=sendinblue', admin_url('admin.php')));
        exit;
    }
    /**
     * ajax module for validation of API access key
     *
     * @options :
     *  ws_main_option
     *  ws_token_store
     *  ws_error_type
     */
    public static function ajax_validation_process()
    {

        $access_key = trim($_POST['access_key']);

        try {
            $mailin = new Mailin_Woo(self::sendinblue_api_url, $access_key);
        }catch( Exception $e ){
            if( $e->getMessage() == 'Mailin requires CURL module' ) {
                $ws_error_type = __('Please install curl on site to use sendinblue plugin.', 'wc_sendinblue');
            }else{
                $ws_error_type = __('Curl error.', 'wc_sendinblue');
            }
            $settings = array(
                'error_type' => $ws_error_type,
            );
            update_option('ws_error_type', $settings);
            die();
        }

        $response = $mailin->get_access_tokens();
        if(is_array($response)) {
            if($response['code'] == 'success') {

                // store api info
                $settings = array(
                    'access_key' => $access_key,
                );
                update_option('ws_main_option', $settings);

                // update a default settings
                $customizations = array(
                    'ws_subscription_enabled' => 'yes',
                    'ws_order_event' => 'on-hold',
                    'ws_smtp_enable' => 'yes',
                    'ws_email_templates_enable' => 'no',
                    'ws_sms_enable' => 'no',
                    'ws_marketingauto_enable' => 'no', // BG20190425
                );
                update_option('wc_sendinblue_settings', $customizations);

                // Create woocommerce attributes on SendinBlue
                $data = array(
                    "type" => "transactional",
                    "data" => array('ORDER_ID' => 'ID', 'ORDER_DATE' => 'DATE', 'ORDER_PRICE' => 'NUMBER')
                );
                $mailin->create_attribute($data);

                $mailin->partnerWordpress('WOOCOMMERCE');

                wp_send_json('success') ;
            }
            else {
                $settings = array(
                    'error_type' => __('Please input correct information.', 'wc_sendinblue'),
                );
                update_option('ws_error_type', $settings);
            }
        } else {
            wp_send_json('fail');
        }
    }
} 
