<?php
class WC_Sendinblue_API
{
    /** transient delay time */
    const delayTime = HOUR_IN_SECONDS;

    //get list
    static public function get_list(){
        // get lists
        $general_settings = get_option('ws_main_option');
        $access_key = isset($general_settings['access_key']) ? $general_settings['access_key'] : '';
        $lists = get_transient( 'ws_list_' . md5( $access_key) );
        if ( $lists == false || $lists === false ) {

            $data = array();

            $account = new Mailin_Woo(WC_Sendinblue::sendinblue_api_url, $access_key);
            $lists = $account->get_lists($data);
            // update with default list id
            if(!isset(WC_Sendinblue::$customizations['ws_sendinblue_list'])) {
                WC_Sendinblue::$customizations['ws_sendinblue_list'] = $lists['data'][0]['id'];
                update_option('wc_sendinblue_settings', WC_Sendinblue::$customizations);
            }
            $list_data = array();

            foreach ($lists['data'] as $list) {
                if($list['name'] == 'Temp - DOUBLE OPTIN'){
                    WC_Sendinblue::$customizations['ws_dopt_list_id'] = $list['id'];
                    update_option('wc_sendinblue_settings', WC_Sendinblue::$customizations);
                    continue;
                }
                $list_data[$list['id']] = $list['name'];
            }
            $lists = $list_data;

            if ( sizeof( $lists ) > 0 )
                set_transient( 'ws_list_' . md5( $access_key ), $lists, self::delayTime );
        }
        return $lists;
    }

    static public function get_templates(){
        $general_settings = get_option('ws_main_option');
        $access_key = isset($general_settings['access_key']) ? $general_settings['access_key'] : '';
        // get templates
        $templates = get_transient( 'ws_temp_' . md5( $access_key ) );
        if ( $templates == false || $templates === false ) {
            $account = new Mailin_Woo(WC_Sendinblue::sendinblue_api_url, $access_key);

            $data = array(
                'type' => 'template',
                'status' => 'temp_active'
            );
            $templates = $account->get_campaigns_v2($data);
            $template_data = array();

            if($templates['code'] == 'success') {

                foreach ($templates['data']['campaign_records'] as $template) {
                    $template_data[$template['id']] = array(
                        'name' => $template['campaign_name'],
                        'content' => $template['html_content'],
                    );

                }
            }

            $templates = $template_data;

            if ( sizeof( $templates ) > 0 ) {
                set_transient('ws_temp_' . md5($access_key), $templates, self::delayTime);
            }
        }
        return $templates;
    }

    static public function get_account_info(){
        $general_settings = get_option('ws_main_option');
        $access_key = isset($general_settings['access_key']) ? $general_settings['access_key'] : '';
        // get account's info
        $account_info = get_transient( 'ws_credit_' . md5( $access_key ) );
        if ( $account_info == false || $account_info === false ) {
            $account = new Mailin_Woo(WC_Sendinblue::sendinblue_api_url, $access_key);
            $account_info = array();

            $acc_info = $account->get_account();
            $count = count($acc_info['data']);
            $account_data = array();
            foreach ($acc_info['data'] as $key=>$info) {
                if (isset($info['plan_type']) && isset($info['credits'])) {
                    $account_data[$key]['plan_type'] = $info['plan_type'];
                    $account_data[$key]['credits'] = $info['credits'];
                }
            }

            $account_info['SMS_credits'] = $account_data[1];
            $account_info['email_credits'] = $account_data[0];

            if ( isset($acc_info['data'][ $count -1 ]['plan_type']) )
            {
                $account_info['email'] = $acc_info['data'][ $count - 2 ]['email'];
                $account_info['user_name'] = $acc_info['data'][ $count - 2 ]['first_name'] . ' ' . $acc_info['data'][ $count - 2 ]['last_name'];
            }
            else{
                $account_info['email'] = $acc_info['data'][ $count - 1 ]['email'];
                $account_info['user_name'] = $acc_info['data'][ $count - 1 ]['first_name'] . ' ' . $acc_info['data'][ $count - 1 ]['last_name'];
            }

            $settings = array(
                'access_key' => $access_key,
                'account_email' => $account_info['email']
            );
            update_option('ws_main_option', $settings);

            if ( sizeof( $account_info ) > 0 )
                set_transient( 'ws_credit_' . md5( $access_key ), $account_info, self::delayTime );
        }

        return $account_info;
    }

    /** get all attributes */
    public static function get_attributes()
    {
        $general_settings = get_option('ws_main_option');
        $access_key = isset($general_settings['access_key']) ? $general_settings['access_key'] : '';
        // get attributes
        $attrs = get_transient('ws_attrs_' . md5($access_key));

        if ($attrs == false || $attrs === false) {
            $mailin = new Mailin_Woo(WC_Sendinblue::sendinblue_api_url, $access_key);
            $response = $mailin->get_attributes();
            $attributes = $response['data'];

            if (!is_array($attributes)) {
                $attributes = array(
                    'normal_attributes' => array(),
                    'category_attributes' => array(),
                );
            }
            $attrs = array('attributes' => $attributes);
            if (sizeof($attributes) > 0) {
                set_transient('ws_attrs_' . md5($access_key), $attrs, self::delayTime);
            }
        }

        return $attrs;

    }


    // BG20190425
    /** Get smtp status with MA */
    public static function get_ma_status() {
        $general_settings = get_option('ws_main_option');
        $access_key = isset($general_settings['access_key']) ? $general_settings['access_key'] : '';
        $mailin = new Mailin_Woo(WC_Sendinblue::sendinblue_api_url, $access_key);
        $response = $mailin->get_smtp_details(); // this fn returns MA status together with SMTP 
        $status = 'disabled';
        if ( 'success' == $response['code'] ) {
            // get Marketing Automation API key.
            if ( isset( $response['data']['marketing_automation'] ) && '1' == $response['data']['marketing_automation']['enabled'] ) {
                $ma_key = $response['data']['marketing_automation']['key'];
                
            } else {
                $ma_key = '';
            }
            $general_settings = get_option( 'ws_main_option', array() );
            $general_settings['ma_key'] = $ma_key;
            update_option( 'ws_main_option', $general_settings );
            if ($ma_key != '') { $status = 'enabled'; }
        }
        return $status;
    }


    /**
     * sync wp users to contact list
     * @param $info - user's attributes
     * @param $list_ids - array : desired list
     * @return string - success or failure
     */
    public static function sync_users($users_info, $list_ids)
    {
        $general_settings = get_option('ws_main_option');
        $access_key = isset($general_settings['access_key']) ? $general_settings['access_key'] : '';
        $mailin = new Mailin_Woo(WC_Sendinblue::sendinblue_api_url, $access_key);
        $data = array(
            "body" => $users_info,
            "listids" => $list_ids,
        );
        $res = $mailin->import_users($data);
        return $res;
    }

    /* remove all transients */
    public static function remove_transients(){
        // remove transients
        $general_settings = get_option('ws_main_option');
        $access_key = isset($general_settings['access_key']) ? $general_settings['access_key'] : '';
        delete_transient('ws_credit_' . md5( $access_key ));
        delete_transient('ws_temp_' . md5( $access_key ));
        delete_transient('ws_list_' . md5( $access_key ));
        delete_transient('ws_dopt_' . md5( $access_key ));
        delete_transient('ws_attrs_' . md5( $access_key ));
    }

}
