<?php
/*
Plugin Name: Zibal - Tour Master Payment Plugin
Plugin URI: http://zibal.ir
Description: درگاه پرداخت زیبال جهت استفاده در افزونه تور مستر
Version: 1.0.0
Author: Yahya Kangi
Author URI: http://zibal.ir
License: 
*/

// define necessary variable for the site.
define('TOURMASTER_ZIBAL_URL', plugins_url('', __FILE__));
define('TOURMASTER_ZIBAL_LOCAL', dirname(__FILE__));
define('TOURMASTER_ZIBAL_AJAX_URL', admin_url('admin-ajax.php'));

// admin option
add_filter('goodlayers_plugin_payment_option', 'zibal_tourmaster_payment_option');
if( !function_exists('zibal_tourmaster_payment_option') ){
	function zibal_tourmaster_payment_option( $options ){
		$options['zibal'] = array(
			'title' => esc_html__('Zibal', 'tourmaster'),
			'options' => array(
				'zibal-enable' => array(
					'title' => __('Zibal Enable', 'tourmaster'),
					'type' => 'checkbox',
					'default' => 'Disable',
				),
				'zibal-MerchantID' => array(
					'title' => __('Zibal Merchant ID', 'tourmaster'),
					'type' => 'text',
					'default' => 'zibal',
				),			
			)
		);

		return $options;
	}
}

add_filter('tourmaster_zibal_button_atts', 'tourmaster_zibal_button_attribute');
	if( !function_exists('tourmaster_zibal_button_attribute') ){
		function tourmaster_zibal_button_attribute( $attributes ){

			$service_fee = tourmaster_get_option('payment', 'zibal-service-fee', '');

			return array('method' => 'ajax', 'type' => 'zibal', 'service-fee' => $service_fee);
		}
	}
	
//payment select
add_filter("tourmaster_payment_method_list","tourmaster_payment_zibal_list");
function tourmaster_payment_zibal_list($payment_method){
	$zibal = tourmaster_get_option('payment','zibal-enable');
	$zibal_enable = ($zibal == "enable") ? true : false;
	if($zibal_enable)
		$payment_method[] = "zibal";
	return $payment_method;
}

add_filter("tourmaster_payment_method_html","tourmaster_payment_zibal_html");
function tourmaster_payment_zibal_html($html){
	$zibal = tourmaster_get_option('payment','zibal-enable');
	$zibal_enable = ($zibal == "enable") ? true : false;
	
	if( $zibal_enable){
		$html .= '<div class="tourmaster-payment-gateway clearfix" >';
		$html .= '<div class="tourmaster-online-payment-method tourmaster-payment-paypal" >';
		$html .= '<img src="' . esc_attr(TOURMASTER_ZIBAL_URL) . '/images/zibal.png" alt="zibal" width="170" height="76" ';
		$html .= 'data-method="ajax" data-action="tourmaster_payment_selected" data-ajax="' . esc_url(TOURMASTER_ZIBAL_AJAX_URL) . '"  data-action-type="zibal" ';
		$html .= ' />';
		$html .= '</div>';
		$html .= '</div>';
	}
	return $html;
}


//payment form and connect to gateway
add_filter('goodlayers_zibal_payment_form', 'tourmaster_zibal_payment_form', 10, 2);
function tourmaster_zibal_payment_form( $ret = '', $tid = '' ){
	$t_data = apply_filters('goodlayers_payment_get_transaction_data', array(), $tid, array('price', 'tour_id'));
	$price = floatval($t_data['price']["total-price"]);
	$price = intval($price * 100) / 100;
	$MerchantID = tourmaster_get_option('payment', 'zibal-MerchantID', '');
	$factorNumber = '01' . date('dmY') . $tid;
	$redirect = (add_query_arg(array('zibal'=>'','factorNumber'=>$factorNumber), home_url('/')));
	
	$data = array(
		"merchant"=>$MerchantID,
		"amount"=>$price,
		"callbackUrl"=>$redirect,
		'description' => 'خرید آنلاین'
	);
	
	$result = post_to_zibal('request', $data);
	$result = (array)$result;

	ob_start();

	if($result['result'] == 100) {
		$form_url = "https://gateway.zibal.ir/start/".$result["trackId"];
	?>
		<div class="goodlayers-zibal-redirecting-message" ><?php esc_html_e('در حال انتقال به درگاه...', 'tourmaster') ?></div>
		<form id="goodlayers-zibal-redirection-form" method="get" action="<?php echo $form_url; ?>" >
			<input type="submit" style="visible:hidden" value="go" />
		</form>
		<script type="text/javascript">
			(function($){
				$('#goodlayers-zibal-redirection-form').submit();
			})(jQuery);
		</script>
	<?php
	} else {
		echo "<div class=\"goodlayers-zibal-redirecting-message\" >خطا در اتصال به درگاه</div>";
		echo "<div class=\"goodlayers-zibal-redirecting-message\" >خطا: ". result_codes($result['result']) . "</div>";
		echo '<input style="margin:20px 0" onclick="window.location.reload();" value="بازگشت به مرحله قبل" type="button">';
	}
	$ret = ob_get_contents();
	ob_end_clean();
	return $ret;
}

//payment verify 
add_action('init', 'tourmaster_zibal_process_verify');
function tourmaster_zibal_process_verify(){
	if( isset($_GET['zibal'])){
		$payment_info = array(
			'payment_method' => 'zibal'
		);
		
		if(isset($_GET['status']) && isset($_GET['trackId'])) {
			$trackId = $_GET['trackId'];
			$factorNumber = $_GET["factorNumber"];
			$status = intval($_GET["status"]);
			$tid = substr($factorNumber, 10);
			// $message = @$_POST['message'];

			$tdata = tourmaster_get_booking_data(array('id'=>$tid), array('single'=>true));
			$MerchantID = tourmaster_get_option('payment', 'zibal-MerchantID', '');
			
			// amount access: ceil($tdata->total_price)

			$data = array(
				'merchant' => $MerchantID, 
				'trackId' => $trackId, 
			);
			
			if($_GET['status'] == "2") {
				$result = post_to_zibal('verify', $data);
				// $result = (array)$result;

				// also check amounts
				if($result->result == 100) {
					$amount = $verify->amount;
					$payment_info['transaction_id'] = $trans_id;
					$payment_info['amount'] = $tdata->total_price;
					
					if( empty($payment_info['amount']) || tourmaster_compare_price($tdata->total_price, $payment_info['amount']) ){
						$order_status = 'online-paid';
					}else{
						$order_status = 'deposit-paid';
					}
					tourmaster_update_booking_data( 
						array(
							'payment_info' => json_encode($payment_info),
							'payment_date' => current_time('mysql'),
							'order_status' => $order_status,
						),
						array('id' => $tid),
						array('%s', '%s', '%s'),
						array('%d')
					);
					tourmaster_mail_notification('payment-made-mail', $tid);
					tourmaster_mail_notification('admin-online-payment-made-mail', $tid);
					$redirect = add_query_arg(array('tid' => $tid, 'step' => 4, 'payment_method' => 'zibal'), tourmaster_get_template_url('payment'));
					wp_redirect($redirect);
					exit();
				} else {
					$payment_info['error'] = "تراکنش ناموفق بود: " . result_codes($result->result);
					if( !empty($tid) ){
						tourmaster_update_booking_data( 
							array(
								'payment_info' => json_encode($payment_info),
							),
							array('id' => $tid, 'payment_date' => '0000-00-00 00:00:00'),
							array('%s'),
							array('%d', '%s')
						);
					}
				}
			}
		}
		$redirect = add_query_arg(array('tid' => $tid, 'step' => 3, 'error' => 1), tourmaster_get_template_url('payment'));
		wp_redirect($redirect);
		exit();
	}
}

//functions

/**
 * connects to zibal's rest api
 * @param $path
 * @param $parameters
 * @return stdClass
 */
function post_to_zibal($path, $parameters)
{
	$url ='https://gateway.zibal.ir/v1/'.$path;
	
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS,json_encode($parameters));
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	$response  = curl_exec($ch);
	curl_close($ch);
	return json_decode($response);
}

/**
 * returns a string message based on result parameter from curl response
 * @param $code
 * @return String
 */
function result_codes($code)
{
    switch ($code) 
    {
        case 100:
            return "با موفقیت تایید شد";
        
        case 102:
            return "merchant یافت نشد";

        case 103:
            return "merchant غیرفعال";

        case 104:
            return "merchant نامعتبر";

        case 201:
            return "قبلا تایید شده";
        
        case 105:
            return "amount بایستی بزرگتر از 1,000 ریال باشد";

        case 106:
            return "callbackUrl نامعتبر می‌باشد. (شروع با http و یا https)";

        case 113:
            return "amount مبلغ تراکنش از سقف میزان تراکنش بیشتر است.";

        case 201:
            return "قبلا تایید شده";
        
        case 202:
            return "سفارش پرداخت نشده یا ناموفق بوده است";

        case 203:
            return "trackId نامعتبر می‌باشد";

        default:
            return "وضعیت مشخص شده معتبر نیست";
    }
}

/**
 * returns a string message based on status parameter from $_GET
 * @param $code
 * @return String
 */
function status_codes($code)
{
    switch ($code) 
    {
        case -1:
            return "در انتظار پردخت";
        
        case -2:
            return "خطای داخلی";

        case 1:
            return "پرداخت شده - تاییدشده";

        case 2:
            return "پرداخت شده - تاییدنشده";

        case 3:
            return "لغوشده توسط کاربر";
        
        case 4:
            return "‌شماره کارت نامعتبر می‌باشد";

        case 5:
            return "‌موجودی حساب کافی نمی‌باشد";

        case 6:
            return "رمز واردشده اشتباه می‌باشد";

        case 7:
            return "‌تعداد درخواست‌ها بیش از حد مجاز می‌باشد";
        
        case 8:
            return "‌تعداد پرداخت اینترنتی روزانه بیش از حد مجاز می‌باشد";

        case 9:
            return "مبلغ پرداخت اینترنتی روزانه بیش از حد مجاز می‌باشد";

        case 10:
            return "‌صادرکننده‌ی کارت نامعتبر می‌باشد";
        
        case 11:
            return "خطای سوییچ";

        case 12:
            return "کارت قابل دسترسی نمی‌باشد";

        default:
            return "وضعیت مشخص شده معتبر نیست";
    }
}