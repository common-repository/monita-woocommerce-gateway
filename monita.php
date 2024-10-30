<?php
/*
* Plugin Name: Monita Woocommerce Gateway
* Plugin URI: https://monita.tk/
* Description: Monita woocommerce payment gateway by monita
* Version: 0.1
* Author: Monita - Hassan Jahangir & Bilal Raja
* Author URI: https://monita.tk
* License: GPLv2 or later
*/

add_action('plugins_loaded', 'Monita', 0);
function Monita(){
	if(!class_exists('WC_Payment_Gateway')) return;

	class WC_Monita extends WC_Payment_Gateway {

		public $liveURL = null;
		public $liveURLOnly = null;

		public $paymentStatus = null;

		public function __construct(){
			$this->liveURL = 'https://www.monita.tk';
			$this->liveURLOnly = 'www.monita.tk';

			$this->id = 'monita';
			$this->icon = plugins_url('/img/logo.png', __FILE__);
			$this->method_title = 'Monita';
			$this->has_fields = false;

			$this->init_form_fields();
			$this->init_settings();

			$this->title = $this->settings['title'];
		    $this->description = $this->settings['description'];
		    $this->merchant_id = $this->settings['merchant_id'];
		    $this->logo = $this->settings['logo'];
		    $this->render = $this->settings['render'];

		    $this->salt = $this->settings['salt'];
			if($this->salt != "") {
				if(get_transient( 'fmonita-admin-notice' )) {
					delete_transient('fmonita-admin-notice');
				}
			}else if($this->salt == "") {
				if(false === (get_transient( 'fmonita-admin-notice' ))) {
					set_transient( 'fmonita-admin-notice', true);
				}
			}
		    $this->success_redirect_page_id = $this->settings['success_redirect_page_id'];
		    $this->failure_redirect_page_id = $this->settings['failure_redirect_page_id'];
		    $this->pending_redirect_page_id = $this->settings['pending_redirect_page_id'];

		    $this->msg['message'] = "";
		    $this->msg['class'] = "";

		    add_action('init', array(&$this, 'check_monita_response'));
			add_action('woocommerce_update_options_payment_gateways_'.$this->id, array( &$this, 'process_admin_options' ));

		    add_action('woocommerce_receipt_monita', array(&$this, 'receipt_page'));
			add_action('woocommerce_api_wc_redirectpage', array($this, 'redirectpage' ) );
			add_action('woocommerce_api_wc_monita', array($this, 'monita_ipn_response' ) );

		}

		function init_form_fields(){
			/*
			if($_GET['monita_transactionReference']){
		        $order_id=$_GET['order'];
		        $order = new WC_Order( $order_id );
		        $order -> update_status('completed');
		        echo $return_url;
		        $return_url = $order->get_checkout_order_received_url();
		        wp_redirect($return_url);
		    }*/

		    $this->form_fields = array(
		        'enabled' => array(
		            'title' => __('Enable/Disable'),
		            'type' => 'checkbox',
		            'label' => __('Enable monita.'),
		            'default' => 'no'
		        ),
		        'title' => array(
		            'title' => __('Title:'),
		            'type'=> 'text',
		            'description' => __('This controls the title which the user sees during checkout.'),
		            'default' => __('Monita')
		        ),
		        'description' => array(
		            'title' => __('Description:'),
		            'type' => 'textarea',
		            'description' => __('Pay with monita.tk'),
		            'default' => __('Pay with Monita Money Transfer System')
		        ),
		        'merchant_id' => array(
		            'title' => __('Merchant ID'),
		            'type' => 'text',
		            'description' => __('ID of the merchant account created from <a href="https://monita.tk/pages/monita_become_merchant" target="_blank">monita.tk</a>')
		        ),
		        'salt' => array(
		            'title' => __('Security Key(Recommended)'),
		            'type' => 'text',
		            'description' => __('Copy and paste key from your Merchant Page to secure the transaction data')
		        ),
		        'logo' => array(
		            'title' => __('Display logo'),
		            'type' => 'text',
		            'default'=>plugins_url('/img/logo.png?', __FILE__),
		            'description' => __('Full URL of logo to be displayed by monita<br>125px X 125px Square Logo Recommended')
		        ),
		        'pending_redirect_page_id' => array(
                    'title' => __('Pending Return Page(Required)'),
                    'type' => 'select',
                    'options' => $this -> get_pages('Select Pending Message Page'),
                    'description' => "Return Page for pending payment. "
                ),
		        'success_redirect_page_id' => array(
                    'title' => __('Success Return Page(Required)'),
                    'type' => 'select',
                    'options' => $this -> get_pages('Select Success Message Page'),
                    'description' => "Return Page for success payment"
                ),
		        'failure_redirect_page_id' => array(
                    'title' => __('Failure Return Page(Required)'),
                    'type' => 'select',
                    'options' => $this -> get_pages('Select Failure Message Page'),
                    'description' => "Return Page for failed payment"
                ),
                'render' => array(
		          'title'   => __('Render'),
		          'label'   => __('Render'),
		          'type'    => 'select',
		          'default' => 'form',
		          'options' => array("form"=>"Form","widget"=>"Widget"),
		          'description' => "<em>Form</em> - Processes from monita page(More Stable)<br><em>Widget</em> - Processes from within your site/shop"
		        )
		    );
		}

		public function admin_options(){
			echo '<h3>'.__('Monita Payment Gateway').'</h3>';
		    echo '<p>'.__('Monita is a very popular payment gateway for online shopping with multiple payment options. Your customer will have all the reasons to pay you.').'</p>';
		    echo '<table class="form-table">';
		    $this -> generate_settings_html();
		    echo '</table>';
		}

		function receipt_page($order) {
	       	if($_GET['monita_transactionId']) {
	          $order_id = $_GET['order'];
	          $order = new WC_Order( $order_id );
	          $order -> update_status('completed');
	        }
	        echo $this->get_payment_button($order);
	    }

	    public function get_payment_button($order_id) {
	    	global $woocommerce;

	    	$protocol = 'http';
	    	if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') $protocol = 'https';

	    	$order = new WC_Order( $order_id );
	    	$monita_transactionReference = $order_id.'_'.date("ymds");

	    	$monita_redirectURL = "$protocol://$_SERVER[HTTP_HOST]/".(($_SERVER[HTTP_HOST]=='demo.blackfirehost.com')?'monita/':'')."?wc-api=WC_Redirectpage&txnid=".$monita_transactionReference."&order_id=".$order_id;
	    	$monita_successURL = "$protocol://$_SERVER[HTTP_HOST]/".(($_SERVER[HTTP_HOST]=='demo.blackfirehost.com')?'monita/':'')."?wc-api=WC_Monita&txnid=".$monita_transactionReference."&order_id=".$order_id;
	    	$currency_code = trim(get_woocommerce_currency());
	    	$environment_url = $this->liveURL;

	    	$monita_itemId = $order_id;
	    	$monita_itemName = "Order $order_id";
			$temppname = array();
			foreach($order->get_items() as $item) {
				$temppname[] = $item['name']. "|" . $item['total']. "|" . $item['quantity'];
			}
			$strs = join("||", $temppname);
	    	$monita_itemList = $strs;
	    	$monita_amount = $order->order_total;
	    	$monita_shipping = $order->get_shipping_total;
	    	$monita_currency = $currency_code;
	    	$monita_merchantId = $this->merchant_id;
	    	$monita_logo = $this->logo;
	    	$monita_hash = '';

	    	if(!empty($this->salt)){
	            $stringData = $monita_merchantId . $monita_amount . $monita_currency . $monita_itemId . $monita_itemName . $monita_transactionReference;
	            $monita_hash = hash_hmac('sha1', $stringData, $this->salt);
	        }

	    	ob_start();
	    	?>

	    	<?php if($this->render=='form') { ?>
		    <form id='myForm' method="post" action="<?php echo $environment_url; ?>/clientarea/monitapay/?merchant=<?php echo $monita_merchantId; ?>" target="_self">
		        <input type="hidden" name="monita_merchantId" value="<?php echo $monita_merchantId?>" required>
		        <input type="hidden" name="monita_amount" value="<?php echo $monita_amount;?>" optional>
		        <input type="hidden" name="monita_currency" value="<?php echo $monita_currency;?>" required>
		        <input type="hidden" name="monita_itemId" value="<?php echo $monita_itemId;?>" required>
		        <input type="hidden" name="monita_itemName" value="<?php echo $monita_itemName;?>" required>
		        <input type="hidden" name="monita_itemList" value="<?php echo $monita_itemList;?>" required>
		        <input type="hidden" name="monita_shipping" value="<?php echo $monita_shipping;?>" required>
		        <input type="hidden" name="monita_transactionReference" value="<?php echo $monita_transactionReference;?>" required>
		       	<input type="hidden" name="monita_redirectURL" value="<?php echo $monita_redirectURL;?>" optional>
		        <input type="hidden" name="monita_successURL" value="<?php echo $monita_successURL;?>" optional>
		        <input type="hidden" name="monita_logo" value="<?php echo $monita_logo;?>" optional>
		        <?php if(!empty($monita_hash)):?>
		        	<input type="hidden" name="monita_hash" value="<?php echo $monita_hash;?>" required>
		    	<?php endif;?>
		        <input type="hidden" name="submit" src="<?php echo plugins_url('/img/monitabtn.png', __FILE__) ?>" />
		    </form>
			<style>
				html,
				body,
				.container {
				  width: 100%;
				  height: 100%;
				}
				.container {
				  display: flex;
				  justify-content: center;
				  align-items: center;
				  max-width: 800px;
				  margin: 0 auto;
				}
				.circle {
				  background-color: #E74C3C;
				  position: relative;
				  min-width: 64px;
				  min-height: 64px;
				  border-radius: 50%;
				  -webkit-animation: bounceBackAndForth 1200ms infinite;
						  animation: bounceBackAndForth 1200ms infinite;
				}
				@-webkit-keyframes bounceBackAndForth {
				  0% {
					left: -25%;
					width: 64px;
					-webkit-animation-timing-function: ease-in;
							animation-timing-function: ease-in;
				  }
				  25% {
					left: 0%;
					width: 89.6px;
					-webkit-animation-timing-function: cubic-bezier(0.08, 0.82, 0.17, 1);
							animation-timing-function: cubic-bezier(0.08, 0.82, 0.17, 1);
				  }
				  50% {
					left: 25%;
					width: 64px;
					-webkit-animation-timing-function: ease-in;
							animation-timing-function: ease-in;
				  }
				  75% {
					left: 0%;
					width: 89.6px;
					-webkit-animation-timing-function: cubic-bezier(0.08, 0.82, 0.17, 1);
							animation-timing-function: cubic-bezier(0.08, 0.82, 0.17, 1);
				  }
				  100% {
					left: -25%;
					width: 64px;
					-webkit-animation-timing-function: ease-out;
							animation-timing-function: ease-out;
				  }
				}
				@keyframes bounceBackAndForth {
				  0% {
					left: -25%;
					width: 64px;
					-webkit-animation-timing-function: ease-in;
							animation-timing-function: ease-in;
				  }
				  25% {
					left: 0%;
					width: 89.6px;
					-webkit-animation-timing-function: cubic-bezier(0.08, 0.82, 0.17, 1);
							animation-timing-function: cubic-bezier(0.08, 0.82, 0.17, 1);
				  }
				  50% {
					left: 25%;
					width: 64px;
					-webkit-animation-timing-function: ease-in;
							animation-timing-function: ease-in;
				  }
				  75% {
					left: 0%;
					width: 89.6px;
					-webkit-animation-timing-function: cubic-bezier(0.08, 0.82, 0.17, 1);
							animation-timing-function: cubic-bezier(0.08, 0.82, 0.17, 1);
				  }
				  100% {
					left: -25%;
					width: 64px;
					-webkit-animation-timing-function: ease-out;
							animation-timing-function: ease-out;
				  }
				}



			</style>
			<div class="container">
			  <div class="circle"> <img src="https://demo.blackfirehost.com/monita/wp-content/plugins/monita-gateway-woocommerce/img/logo.png" /></div>
			</div>
			<script type="text/javascript">
			    window.onload=function() {
					document.forms["myForm"].style.visibility='hidden';
					document.forms["myForm"].submit();
			    }
			</script>
		    <?php } elseif ($this->render=='widget') { ?>
			    <div id="monita-btn"><a href="javascript:void();"><img src="<?php echo plugins_url('/img/monitabtn6.png', __FILE__) ?>" /></a></div>
				<script
				monita_merchantId="<?php echo $monita_merchantId?>"
				monita_amount="<?php echo $monita_amount;?>"
				monita_currency="<?php echo $monita_currency;?>"
				monita_itemId="<?php echo $monita_itemId;?>"
				monita_itemName="<?php echo $monita_itemName;?>"
				monita_itemList="<?php echo $monita_itemList;?>"
				monita_shipping="<?php echo $monita_shipping;?>"
				monita_transactionReference="<?php echo $monita_transactionReference;?>"
				monita_render="widget"
				monita_logo="<?php echo $monita_logo;?>"
				monita_redirectURL="<?php echo urlencode($monita_redirectURL);?>"
				monita_successURL="<?php echo urlencode($monita_successURL);?>"

				id="monita" type="text/javascript" async=true>
				document.write(unescape('%3Cscript src="' +
				    (("https:" == document.location.protocol) ? "https://<?php echo $this->liveURLOnly; ?>" : "http://<?php echo $this->liveURLOnly; ?>") +
					'/js/widgets.js" type="text/javascript"%3E%3C/script%3E'
				));
				</script>
		    <?php } ?>
	    	<?php
	    	$monita_button = ob_get_clean();
	    	return $monita_button;
	    }

	    function process_payment($order_id) {
		    global $woocommerce;
		    $order = new WC_Order( $order_id );

			$monita_redirectURL = "$protocol://$_SERVER[HTTP_HOST]/".(($_SERVER[HTTP_HOST]=='demo.blackfirehost.com')?'monita/':'')."?wc-api=WC_Redirectpage&monita_action=pay&order_id=".$order_id;

			// Return thankyou redirect
		    return array(
		        'result' => 'success',
		        'redirect' => $monita_redirectURL
		    );

			return array(
		    	'result' => 'success',
		    	'redirect' => add_query_arg(
		    		'order',
		          	$order->id,
		          	add_query_arg(
		          		'key',
		          		$order->order_key,
		          		get_permalink(get_option('woocommerce_pay_page_id'))
		          	)
		        )
		    );
	    }

		function monita_ipn_response() {

			$order_id = null;
		    $monita_transactionReference = null;

			$ipn = file_get_contents('php://input');
			@$ipn = json_decode($ipn);

			if (isset($_REQUEST['txnid'])) {
	            $order_id = explode('_', $_REQUEST['txnid']);
	            $order_id = (int)$order_id[0];
	        } elseif (isset($ipn->Response->monita_itemId)) {
	        	$order_id = $ipn->Response->monita_itemId;
	        }

	        if (isset($_REQUEST['monita_transactionReference'])) {
	        	$monita_transactionReference = $_REQUEST['monita_transactionReference'];
	        } elseif (isset($ipn->Response->monita_transactionReference)) {
	        	$monita_transactionReference = $ipn->Response->monita_transactionReference;
	        }

			if (!empty($order_id) || empty($monita_transactionReference)) {
				header('HTTP/1.1 403 Invalid Request');
				exit();
			}

	    	if ($this->validate_order_payment($order_id,$monita_transactionReference,true)) {
    			header('HTTP/1.1 20 OK');
    		} else {
    			header('HTTP/1.1 403 Validation Failed!');
    		}

	    	exit();
		}

		function redirectpage() {

			if(!empty($_GET['monita_action']) && $_GET['monita_action']=='pay'){
				$this->receipt_page($_GET['order_id']);
				exit;
			}

			$order_id = null;
			$monita_transactionReference = null;

			if (isset($_REQUEST['txnid'])) {
	            $order_id = explode('_', $_REQUEST['txnid']);
	            $order_id = (int)$order_id[0];
	        }

	        if (empty($order_id) && isset($_REQUEST['monita_itemId'])) {
	        	$order_id = (int)$_REQUEST['monita_itemId'];
	        	echo $_REQUEST['monita_itemId'];
	        }

	        if (isset($_REQUEST['monita_transactionReference'])) {
	        	$monita_transactionReference = $_REQUEST['monita_transactionReference'];
	        }

	        $protocol = 'http';
	    	if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') $protocol = 'https';
	        $home = "$protocol://$_SERVER[HTTP_HOST]/".(($_SERVER[HTTP_HOST]=='demo.blackfirehost.com')?'monita/':'');
	        if (empty($order_id) || empty($monita_transactionReference)) {
	        	wc_print_notice(__('Transaction details not found. Please contact us to complete your request. Thanks.','woocommerce'), 'notice');
	        	echo "<div><a href='".$home."'>Return Home</a></div>";
	        	exit();
	        }

	        $this->validate_order_payment($order_id,$monita_transactionReference,true);
    		$status = $this->paymentStatus;
    		$redirect = '';

    		if($status == 'COMPLETE') {
       			$redirect = $home . '?page_id='.$this->success_redirect_page_id;
          	} elseif($status == 'PENDING') {
            	$redirect = $home . '?page_id='.$this->pending_redirect_page_id;
          	} elseif($status == 'FAILED' || $status == 'CANCELLED') {
          		$redirect = $home . '?page_id='.$this->failure_redirect_page_id;
          	} else {
           		wc_print_notice(__('Payment Status is currently unknown. Please contact us.','woocommerce'), 'notice');
	        	echo "<div><a href='".$home."'>Return Home</a></div>";
	        	exit();
          	}

			header("location: ".$redirect);
			exit();
		}

		function checkStatus($url) {
		    $response = wp_remote_get($url);

		    $status = wp_remote_retrieve_body($response);

		    if(wp_remote_retrieve_response_code( $response ) != 200){
		      $status =  null;
		    }

		    if(!empty($status)){
		      @$status = json_decode($status);
		      if(!empty($status) && isset($status->Response->status)){
		        return ucwords($status->Response->monita_transactionStatus);
		      }
		    }
		    return null;
		}


	    function validate_order_payment($order_id=null,$monita_transactionReference=null,$returnable=false) {
	        global $woocommerce;

	        if (isset($_REQUEST['txnid']) && isset($_REQUEST['monita_transactionReference'])) {
	            $order_id = explode('_', $_REQUEST['txnid']);
	            $order_id = (int)$order_id[0];
	        }

	        if (isset($_REQUEST['monita_transactionReference'])) {
	        	$monita_transactionReference = $_REQUEST['monita_transactionReference'];
	        }

	        if($order_id != '') {
                try {
                	$environment_url = $this->liveURL;
		      		$url = $environment_url . '/transactions/check_status/'.$this->merchant_id.'/';
		          	$status = $this->checkStatus($url.$monita_transactionReference.'.json');
		          	$this->paymentStatus = $status;

                    $order = new WC_Order( $order_id );
                    $transauthorised = false;

                    if($status == 'COMPLETE') {
	           			$transauthorised = true;
                        $this->msg['message'] = "You have successfully paid for your order. It will be shipped very soon.";
                        $this->msg['class'] = 'woocommerce_message';
                        if ($order->status!= 'processing') {
                        	$order-> payment_complete();
                            $order-> add_order_note('Payment succeded');
                            $order-> add_order_note($this->msg['message']);
                            $woocommerce->cart->empty_cart();
                        }
		          	} elseif($status == 'PENDING') {
		            	$this->msg['message'] = "You have paid for your order and your payment staus is currently pending, We will contact you regarding your order status through your e-mail";
                        $this->msg['class'] = 'woocommerce_message woocommerce_message_info';
                        $order->add_order_note('Payment is pending');
                        $order->add_order_note($this->msg['message']);
                        $order->update_status('on-hold');
                        $woocommerce->cart->empty_cart();
		          	} elseif($status == 'FAILED') {
		            	$this->msg['class'] = 'woocommerce_error';
                        $this->msg['message'] = "Thank you for shopping with us. However, the transaction has been failed. Please contact us for any inquiries";
                        $order->add_order_note('Transaction failed');
		          	} elseif($status == 'CANCELLED') {
		            	$this->msg['class'] = 'woocommerce_error';
                        $this->msg['message'] = "Thank you for shopping with us. However, the transaction has been cancelled.";
                        $order->add_order_note('Transaction Cancelled');
		          	} else {
		           		$this->msg['class'] = 'woocommerce_error';
                        $this->msg['message'] = "Thank you for shopping with us. However, the transaction has been declined.";
                        $order->add_order_note('Transaction Declined');
		          	}

		          	if($transauthorised==false) {
                        $order->update_status('failed');
                        $order->add_order_note('Failed');
                        $order->add_order_note($this->msg['message']);
                    }

                    if ($returnable) return $transauthorised;

                    add_action('the_content', array(&$this, 'showMessage'));

                } catch(Exception $e) {
                    $msg = "Error";
                    if ($returnable) return false;
                }
            } else {
            	if ($returnable) return false;
            }
	    }

	    function showMessage($content) {
	      return '<div class="box '.$this->msg['class'].'-box">'.$this->msg['message'].'</div>'.$content;
	    }

	    function get_pages($title = false, $indent = true) {
	        $wp_pages = get_pages('sort_column=menu_order');
	        $page_list = array();
	        if ($title) $page_list[] = $title;
	        foreach ($wp_pages as $page) {
	            $prefix = '';
	            if ($indent) {
	                $has_parent = $page->post_parent;
	                while($has_parent) {
	                    $prefix .=  ' - ';
	                    $next_page = get_page($has_parent);
	                    $has_parent = $next_page->post_parent;
	                }
	            }
	            $page_list[$page->ID] = $prefix . $page->post_title;
	        }
	        return $page_list;
	    }
	}
	
	function monita_admin_notice_activate_notice() {

		/* Check transient, if available display notice */
		if( get_transient( 'fmonita-admin-notice' ) ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p>You have not Set-up Monita Woocommerce Gateway <br> Please Set-up this plugin <a href="admin.php?page=wc-settings&tab=checkout&section=monita">here</a>.</p>
			</div>
			<?php
		}
	}
	add_action( 'admin_notices', 'monita_admin_notice_activate_notice' );
	
	function woocommerce_add_monita($methods) {
    	$methods[] = 'WC_Monita';
    	return $methods;
  	}
  	add_filter('woocommerce_payment_gateways', 'woocommerce_add_monita' );
}

register_activation_hook( __FILE__, 'monita_admin_notice_activation_hook' );
function monita_admin_notice_activation_hook() {
	set_transient( 'fmonita-admin-notice', true);
}


function monita_loadcss() {
 wp_enqueue_style( 'monita', plugins_url('/css/monita.css', __FILE__), array(), '1.0.0', 'all' );
}
add_action('wp_enqueue_scripts', "monita_loadcss");

function monita_loadjs() {
wp_enqueue_script( 'monita', plugins_url('/js/monita.js', __FILE__), array('jquery'), '1.0.0', true );
}
add_action('admin_enqueue_scripts', 'monita_loadjs'); 

add_filter( 'plugin_action_links', 'monita_get_plugin_action_links', 10, 5 );
function monita_get_plugin_action_links( $action_links, $plugin_file ){
	static $plugin;
	if(!isset($plugin)) $plugin = plugin_basename(__FILE__);
	if ($plugin == $plugin_file) {
	    $otherLinks = array(
	        'Create Merchant Account' => '<a href="https://monita.tk/pages/signup.php" target="_blank">Create Web/Merchant Account</a>',
	        'Help/Support' => '<a href="http://monita.tk/support" target="_blank">Help/Support</a>',
	        'Register' => '<a href="https://monita.tk/pages/signup.php" target="_blank">Register</a>',
	    );
    	$action_links = array_merge($otherLinks, $action_links);
	}
	return $action_links;
}
?>