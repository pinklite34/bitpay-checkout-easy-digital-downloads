<?php
/**
 * Plugin Name: BitPay Checkout for Easy Digital Downloads
 * Plugin URI: http://www.bitpay.com
 * Description: Create Invoices and process through BitPay.  Configure in your <a href ="edit.php?post_type=download&page=edd-settings&tab=gateways">Easy Digital Downloads->Payment Gateways</a>.
 * Version: 1.1.1911
 * Author: BitPay
 * Author URI: mailto:integrations@bitpay.com?subject=BitPay Checkout for Easy Digital Downloads
 */
if (!defined('ABSPATH')): exit;endif;

global $current_user;

#check and see if requirements are met for turning on plugin
function BPC_EDD_isCurl()
{
    return function_exists('curl_version');
}

#create the table if it doesnt exist
function bitpayedd_checkout_plugin_setup()
{
    global $wpdb;
    $errors = array();

    if (!BPC_EDD_isCurl()) {
        $errors[] = 'cUrl needs to be installed/enabled for BitPay Checkout for Easy Digital Downloads to function';
    }

    if (empty($errors)):
        $table_name = '_bitpay_checkout_transactions';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table_name(
		            `id` int(11) NOT NULL AUTO_INCREMENT,
		            `order_id` int(11) NOT NULL,
		            `transaction_id` varchar(255) NOT NULL,
		            `transaction_status` varchar(50) NOT NULL DEFAULT 'new',
		            `date_added` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		            PRIMARY KEY (`id`)
		            ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        return false;
    else:
        $plugins_url = admin_url('plugins.php');
        wp_die($errors[0] . '<br><a href="' . $plugins_url . '">Return to plugins screen</a>');
    endif;

}
register_activation_hook(__FILE__, 'bitpayedd_checkout_plugin_setup');

add_action('rest_api_init', function () {
    register_rest_route('bitpay-edd/ipn', '/status', array(
        'methods' => 'POST,GET',
        'callback' => 'bitpay_edd_ipn',
    ));
    register_rest_route('bitpay-edd/cartfix', '/update', array(
        'methods' => 'POST,GET',
        'callback' => 'bitpay_edd_cart_fix',
    ));
});

function bitpay_edd_ipn(WP_REST_Request $request)
{
    global $wpdb;
    global $edd_options;

    $bitpay_checkout_options = $edd_options;

    $data = $request->get_body();

    $data = json_decode($data);
    $event = $data->event;
    $data = $data->data;

    $order_id = $data->orderId;
    $order_status = $data->status;
    $invoice_id = $data->id;

    $table_name = '_bitpay_checkout_transactions';
    $config = new BPC_Configuration($bitpay_checkout_token, bpcedd_getEndPoint($bitpay_checkout_options['test_mode']));

    $params = new stdClass();
    $params->invoiceID = $invoice_id;

    $item = new BPC_Item($config, $params);

    $invoice = new BPC_Invoice($item); //this creates the invoice with all of the config params
    $orderStatus = json_decode($invoice->BPC_checkInvoiceStatus($invoice_id));
    switch ($event->name) {
        #case 'invoice_completed':
        case 'invoice_confirmed':
            if ($orderStatus->data->status == 'confirmed'):
                $note = 'BitPay Invoice ID: <a target = "_blank" href = "' . bpcedd_getEndPointUrl($bitpay_checkout_options['test_mode'], $invoice_id) . '">' . $invoice_id . '</a> processing has been completed.';

               
            
                $now = date('Y-m-d H:i:s');
                $wpdb->query($wpdb->prepare("INSERT INTO ".$wpdb->comments." (comment_post_ID,comment_date,comment_date_gmt,comment_content,comment_type) 
                VALUES (%s,%s,%s,%s,%s)",$order_id,$now,$now,$note,'edd_payment_note'));

                $wpdb->query($wpdb->prepare("UPDATE ".$table_name.=" SET  transaction_status = %s WHERE order_id = %s AND transaction_id = %s",$event->name,$order_id,$invoice_id));
                #update the order status
                $wpdb->query($wpdb->prepare("UPDATE ". $wpdb->posts." SET post_status = 'publish' WHERE ID = %s",$order_id));


            endif;

            break;

        case 'invoice_paidInFull': #pending
            if ($orderStatus->data->status == 'paid'):
                $note = 'BitPay Invoice ID: <a target = "_blank" href = "' . bpcedd_getEndPointUrl($bitpay_checkout_options['test_mode'], $invoice_id) . '">' . $invoice_id . '</a> is processing.';

                $now = date('Y-m-d H:i:s');
                $wpdb->query($wpdb->prepare("INSERT INTO ".$wpdb->comments." (comment_post_ID,comment_date,comment_date_gmt,comment_content,comment_type) 
                VALUES (%s,%s,%s,%s,%s)",$order_id,$now,$now,$note,'edd_payment_note'));

                $wpdb->query($wpdb->prepare("UPDATE ".$table_name.=" SET  transaction_status = %s WHERE order_id = %s AND transaction_id = %s",$event->name,$order_id,$invoice_id));

                #update the order status
                $wpdb->query($wpdb->prepare("UPDATE ". $wpdb->posts." SET post_status = 'processing' WHERE ID = %s",$order_id));
            endif;

            break;

        case 'invoice_failedToConfirm':
            if ($orderStatus->data->status == 'invalid'):
                $note = 'BitPay Invoice ID: <a target = "_blank" href = "' . bpcedd_getEndPointUrl($bitpay_checkout_options['test_mode'], $invoice_id) . '">' . $invoice_id . '</a> has become invalid because of network congestion.  Order will automatically update when the status changes.';

                $now = date('Y-m-d H:i:s');
                $wpdb->query($wpdb->prepare("INSERT INTO ".$wpdb->comments." (comment_post_ID,comment_date,comment_date_gmt,comment_content,comment_type) 
                VALUES (%s,%s,%s,%s,%s)",$order_id,$now,$now,$note,'edd_payment_note'));

                $wpdb->query($wpdb->prepare("UPDATE ".$table_name.=" SET  transaction_status = %s WHERE order_id = %s AND transaction_id = %s",$event->name,$order_id,$invoice_id));

                #update the order status
                $wpdb->query($wpdb->prepare("UPDATE ". $wpdb->posts." SET post_status = 'failed' WHERE ID = %s",$order_id));
            endif;
            break;

        case 'invoice_expired':
            if ($orderStatus->data->status == 'expired'):
                //delete the previous order
                wp_delete_post($order_id, true);
                //delete any notes
                $wpdb->query($wpdb->prepare("DELETE FROM " . $wpdb->comments . " WHERE comment_post_ID = %s",$order_id));

                #delete from the bitpay transaction table
                $wpdb->query($wpdb->prepare("DELETE FROM " . $table_name . " WHERE order_id =%s",$order_id));
              
            endif;
            break;

        case 'invoice_refundComplete':
            $note = 'BitPay Invoice ID: <a target = "_blank" href = "' . bpcedd_getEndPointUrl($bitpay_checkout_options['test_mode'], $invoice_id) . '">' . $invoice_id . '</a> has been refunded.';
            $now = date('Y-m-d H:i:s');
            $wpdb->query($wpdb->prepare("INSERT INTO ".$wpdb->comments." (comment_post_ID,comment_date,comment_date_gmt,comment_content,comment_type) 
            VALUES (%s,%s,%s,%s,%s)",$order_id,$now,$now,$note,'edd_payment_note'));

            $wpdb->query($wpdb->prepare("UPDATE ".$table_name.=" SET  transaction_status = %s WHERE order_id = %s AND transaction_id = %s",$event->name,$order_id,$invoice_id));

           #update the order status
           $wpdb->query($wpdb->prepare("UPDATE ". $wpdb->posts." SET post_status = 'refunded' WHERE ID = %s",$order_id));
            break;
    }
}
//delete the order because they closed the modal
function bitpay_edd_cart_fix(WP_REST_Request $request)
{
    global $wpdb;
    $data = $request->get_params();
    $order_id = $data['orderid'];
    
    $table_name = '_bitpay_checkout_transactions';

    //delete the order
    wp_delete_post($order_id, true);
    #delete any comments/order notes

  
     $wpdb->query($wpdb->prepare("DELETE FROM " . $wpdb->comments . " WHERE comment_post_ID = %s",$order_id));

     #delete from the bitpay transaction table
     $wpdb->query($wpdb->prepare("DELETE FROM " . $table_name . " WHERE order_id =%s",$order_id));
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
}

function enable_bitpay_edd_js()
{
    global $edd_options;
    $bitpay_checkout_options = $edd_options;
    wp_enqueue_script('remote-bitpayquickpay-js', 'https://bitpay.com/bitpay.min.js', null, null, true);
    wp_enqueue_script('bitpayquickpay-js', plugins_url('/js/bitpay_edd.js', __FILE__), null, null, false);
    ?>
    <script type = "text/javascript">
    if (window.location.href.indexOf("&bpedd=1&invoiceID=") > -1) {
        setTimeout(function(){
            jQuery('#primary').css('opacity', '0.3');
        },
            200);


        var urlParams = new URLSearchParams(window.location.search);
        var $oid = urlParams.get('order_id')
        var $iid = urlParams.get('invoiceID')
        $cart_url = "<?php echo edd_get_checkout_uri(); ?>"
        $fix_url = "<?php echo get_home_url() . '/wp-json/bitpay-edd/cartfix/update'; ?>"

        setTimeout(function(){
            showBPInvoice('<?php echo bpcedd_getEndPoint($bitpay_checkout_options['test_mode']); ?>',$iid,$oid,$cart_url,$fix_url)

        },
            250);
    }

    </script>

    <?php
}
add_action('wp_enqueue_scripts', 'enable_bitpay_edd_js');

#autoloader
function BP_EDD_autoloader($class)
{
    if (strpos($class, 'BPC_') !== false):
        if (!class_exists('BitPayLib/' . $class, false)):
            #doesnt exist so include it
            include 'BitPayLib/' . $class . '.php';
        endif;
    endif;
}
spl_autoload_register('BP_EDD_autoloader');

#register the gateway
function bpc_pw_edd_register_gateway($gateways)
{
    $gateways['bp_checkout_edd'] = array('admin_label' => 'BitPay Checkout', 'checkout_label' => __('BitPay Checkout', 'pw_edd'));
    return $gateways;
}
add_filter('edd_payment_gateways', 'bpc_pw_edd_register_gateway');

#hide the credit card form
function bpc_pw_edd_bp_checkout_edd_cc_form()
{
    // register the action to remove default CC form
    return;
}
add_action('edd_bp_checkout_edd_cc_form', 'bpc_pw_edd_bp_checkout_edd_cc_form');

function bp_checkout_edd_register_gateway_section($gateway_sections)
{
    $gateway_sections['bp_checkout_edd'] = __('BitPay Checkout', 'easy-digital-downloads');
    return $gateway_sections;
}
add_filter('edd_settings_sections_gateways', 'bp_checkout_edd_register_gateway_section');

function bpc_pw_edd_payment_icon($icons)
{
    $icons[plugins_url('/bitpaycheckout.png', __FILE__)] = 'BitPay Checkout';
    return $icons;
}
add_filter('edd_accepted_payment_icons', 'bpc_pw_edd_payment_icon');
# adds the settings to the Payment Gateways section
function bpc_pw_edd_add_settings($gateway_settings)
{
    $bp_checkout_edd_settings = array(
        array(
            'id' => 'bp_checkout_edd_settings',
            'name' => '<strong>' . __(BP_EDD_getBitPayVersionInfo($clean = true), 'pw_edd') . '</strong>',
            'desc' => __('Configure your BitPay integration', 'pw_edd'),
            'type' => 'header',
        ),
        array(
            'id' => 'bp_checkout_edd_desc',
            'name' => __('', 'pw_edd'),
            'type' => 'descriptive_text',
            'desc' => __('If you have not created a BitPay Merchant Token, you can create one on your BitPay Dashboard.<br><a href = "https://test.bitpay.com/dashboard/merchant/api-tokens" target = "_blank">(Test)</a>  or <a href= "https://www.bitpay.com/dashboard/merchant/api-tokens" target = "_blank">(Production)</a> </p><br>To switch between the development and production environment, enable/disable <strong>Test Mode</strong> on the <a href = "edit.php?post_type=download&page=edd-settings&tab=gateways&section=main">Payment Gateways</a> page.', 'pw_edd'),
        ),
        array(
            'id' => 'bitpay_checkout_token_dev',
            'name' => __('Development Token', 'pw_edd'),
            'desc' => __('<br>Your <b>development</b> merchant token.  <a href = "https://test.bitpay.com/dashboard/merchant/api-tokens" target = "_blank">Create one here</a>.', 'pw_edd'),
            'type' => 'text',
            'size' => 'regular',
        ),
        array(
            'id' => 'bitpay_checkout_token_prod',
            'name' => __('Production Token', 'pw_edd'),
            'desc' => __('<br>Your <b>production</b> merchant token.  <a href = "https://www.bitpay.com/dashboard/merchant/api-tokens" target = "_blank">Create one here</a>.', 'pw_edd'),
            'type' => 'text',
            'size' => 'regular',
        ),
        array(
            'id' => 'bitpay_checkout_capture_email',
            'name' => __('Auto-Capture Email', 'pw_edd'),
            'desc' => __('<br>Should BitPay try to auto-add the client\'s email address?  If <b>Yes</b>, the client will not be able to change the email address on the BitPay invoice.  If <b>No</b>, they will be able to add their own email address when paying the invoice.', 'pw_edd'),
            'type' => 'select',
            'size' => 'regular',
            'options' => bpcedd_get_yes_no(),

        ),
        array(
            'id' => 'bitpay_checkout_flow',
            'name' => __('Checkout Flow', 'pw_edd'),
            'desc' => __('<br>If this is set to <b>Redirect</b>, then the customer will be redirected to <b>BitPay</b> to checkout, and return to the checkout page once the payment is made.<br>If this is set to <b>Modal</b>, the user will stay on <b>' . get_bloginfo('name', null) . '</b> and complete the transaction.', 'pw_edd'),
            'type' => 'select',
            'size' => 'regular',
            'options' => bpcedd_cflow(),

        ),
    );
    $bp_checkout_edd_settings = apply_filters('edd_bp_checkout_settings', $bp_checkout_edd_settings);
    $gateway_settings['bp_checkout_edd'] = $bp_checkout_edd_settings;
    return $gateway_settings;
}
add_filter('edd_settings_gateways', 'bpc_pw_edd_add_settings');

#yes no for select box
function bpcedd_get_yes_no()
{
    $options = array(
        '0' => __('No', 'easy-digital-downloads'),
        '1' => __('Yes', 'easy-digital-downloads'),
    );
    return apply_filters('bpcedd-get-yes-no', $options);
}
#checkout flow options
function bpcedd_cflow()
{
    $options = array(
        '1' => __('Modal', 'easy-digital-downloads'),
        '2' => __('Redirect', 'easy-digital-downloads'),
    );
    return apply_filters('bpcedd-cflow', $options);
}

function bpcedd_getBitPayToken($options)
{
    //dev or prod token
    switch ($edd_options['test_mode']) {
        case 'true':
        case '1':
        default:
            return $options['bitpay_checkout_token_dev'];
            break;
        case 'false':
        case '0':
            return $options['bitpay_checkout_token_prod'];
            break;
    }

}

function bpcedd_getEndPoint($env)
{
    if ($env == 1) {
        return 'test';
    } else {
        return 'prod';
    }

}

function bpcedd_getEndPointUrl($env, $invoice_id)
{
    if ($env == 1) {
        return '//test.bitpay.com/dashboard/payments/' . $invoice_id;
    } else {
        return '//bitpay.com/dashboard/payments/' . $invoice_id;
    }

}

function bpcedd_addOrderNote($order_id, $invoice_id, $status)
{

    global $wpdb;
    global $edd_options;
    $bitpay_checkout_options = $edd_options;
    switch ($status) {
        case 'processing':
        default:
            $note = 'BitPay Invoice ID: <a target = "_blank" href = "' . bpcedd_getEndPointUrl($bitpay_checkout_options['test_mode'], $invoice_id) . '">' . $invoice_id . '</a> is pending.';
            break;
    }
   

    $now = date('Y-m-d H:i:s');
    $wpdb->query($wpdb->prepare("INSERT INTO ".$wpdb->comments." (comment_post_ID,comment_date,comment_date_gmt,comment_content,comment_type) 
    VALUES (%s,%s,%s,%s,%s)",$order_id,$now,$now,$note,'edd_payment_note'));

    #update the order note to say processing
    #$sql = "UPDATE " . $wpdb->posts . " SET post_status = '$status' WHERE ID = $order_id";
    #$wpdb->get_results($sql);
    $wpdb->query($wpdb->prepare("UPDATE ".  $wpdb->posts." SET post_status = %s WHERE ID = %s",$status,$order_id));

    #update the bitpay transactions table
    $table_name = '_bitpay_checkout_transactions';
    switch ($status) {
        case 'pending':
        default:
            #$sql = "INSERT INTO " . $table_name . " (order_id,transaction_id,transaction_status) VALUES ('$order_id','$invoice_id','$status') ";
           # $wpdb->get_results($sql);
           $wpdb->query($wpdb->prepare("INSERT INTO ".$table_name." (order_id,transaction_id,transaction_status) 
           VALUES(%s,%s,%s)",$order_id,$invoice_id,$status));
            break;
    }

}

#process the payment
function bpc_pw_edd_process_payment($purchase_data)
{
    // payment processing happens here

    global $edd_options;
    $bitpay_checkout_options = $edd_options;
    $fail = false;
    $errors = edd_get_errors();
    if (!$errors) {
        $bitpay_checkout_token = bpcedd_getBitPayToken($bitpay_checkout_options);

        $purchase_summary = edd_get_purchase_summary($purchase_data);
        /**********************************
         * setup the payment details
         **********************************/

        $payment = array(
            'price' => $purchase_data['price'],
            'date' => $purchase_data['date'],
            'user_email' => $purchase_data['user_email'],
            'purchase_key' => $purchase_data['purchase_key'],
            'currency' => $edd_options['currency'],
            'downloads' => $purchase_data['downloads'],
            'cart_details' => $purchase_data['cart_details'],
            'user_info' => $purchase_data['user_info'],
            'status' => 'pending',
        );
        // record the pending payment
        $order_id = edd_insert_payment($payment);

        //BitPay
        $order = $purchase_data['cart_details'][0];

        $config = new BPC_Configuration($bitpay_checkout_token, bpcedd_getEndPoint($bitpay_checkout_options['test_mode']));
        //sample values to create an item, should be passed as an object'
        $params = new stdClass();
        $current_user = wp_get_current_user();
        #$params->fullNotifications = 'true';
        $params->extension_version = BP_EDD_getBitPayVersionInfo();
        $params->price = $order['price'];
        $params->currency = 'USD'; //set as needed
        if ($bitpay_checkout_options['bitpay_checkout_capture_email'] == 1):
            $current_user = wp_get_current_user();

            if ($current_user->user_email):
                $buyerInfo = new stdClass();
                $buyerInfo->name = $current_user->display_name;
                $buyerInfo->email = $current_user->user_email;
                $params->buyer = $buyerInfo;
            endif;
        endif;

        //orderid
        $params->orderId = trim($order_id);
        $params->notificationURL = get_home_url() . '/wp-json/bitpay-edd/ipn/status';
        $params->redirectURL = edd_get_success_page_uri() . '?order_id=' . $params->orderId;
        #http://<host>/wp-json/bitpay/ipn/status
        $params->extendedNotifications = true;

        $item = new BPC_Item($config, $params);
        $invoice = new BPC_Invoice($item);

        //this creates the invoice with all of the config params from the item
        $invoice->BPC_createInvoice();
        $invoiceData = json_decode($invoice->BPC_getInvoiceData());
        //now we have to append the invoice transaction id for the callback verification
        $invoiceID = $invoiceData->data->id;
        //set a cookie for redirects and updating the order status
        $cookie_name = "bitpay-edd-invoice-id";
        $cookie_value = $invoiceID;
        setcookie($cookie_name, $cookie_value, time() + (86400 * 30), "/");

        $use_modal = intval($bitpay_checkout_options['bitpay_checkout_flow']);
        //use the modal if '1', otherwise redirect

        if ($use_modal == 2):
            #add some order notes
            bpcedd_addOrderNote($params->orderId, $invoiceID, 'pending');
            wp_redirect($invoice->BPC_getInvoiceURL());
        else:
            #add some order notes
            bpcedd_addOrderNote($params->orderId, $invoiceID, 'pending');
            wp_redirect($params->redirectURL . '&bpedd=1&$invoiceID=' . $invoiceID);
        endif;
    } else {
        $fail = true;
    }
    //end error check

    if ($fail !== false) {
        // if errors are present, send the user back to the purchase page so they can be corrected
        edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
    }
}
add_action('edd_gateway_bp_checkout_edd', 'bpc_pw_edd_process_payment');

#get the extension version
function BP_EDD_getBitPayVersionInfo($clean = null)
{
    $plugin_data = get_file_data(__FILE__, array('Version' => 'Version', 'Plugin_Name' => 'Plugin Name'), false);
    $plugin_name = $plugin_data['Plugin_Name'];
    if ($clean):
        $plugin_version = $plugin_name . ' ' . $plugin_data['Version'];
    else:
        $plugin_name = str_replace(" ", "_", $plugin_name);
        $plugin_name = str_replace("_for_", "_", $plugin_name);
        $plugin_version = $plugin_name . '_' . $plugin_data['Version'];
    endif;

    return $plugin_version;
}
