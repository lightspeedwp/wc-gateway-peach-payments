<?php
/**
 * Peach Payments Gateway
 *
 * Provides an Peach Payments WPF Gateway
 *
 * @class 		WC_Peach_Payments
 * @extends		WC_Payment_Gateway
 * @version		1.6.6
 * @package		WooCommerce/Classes/Payment
 * @author 		Domenico Nusca, Warwick Booth
 */

class WC_Peach_Payments extends WC_Payment_Gateway {

	public $payment = '';
	/**
     * Constructor for the gateway.
     *
     * @access public
     * @return void
     */
	public function __construct() {
		global $woocommerce;

		$this->id 			= 'peach-payments';
		$this->method_title = __( 'Peach Payments', 'woocommerce-gateway-peach-payments' );
		$this->icon 		= '';
		$this->has_fields 	= true;
		$this->supports 			= array(
			'subscriptions',
			'products',
			'subscription_cancellation',
			'subscription_reactivation',
			'subscription_suspension',
			'subscription_amount_changes',
			'subscription_payment_method_change',
			'subscription_date_changes'
		);

		$this->available_currencies = array( 'ZAR' );

		// Load the form fields.
		$this->init_form_fields();

		$this->order_button_text = __( 'Proceed to payment', 'woocommerce-gateway-peach-payment' );

		// Load the settings.
		$this->init_settings();

		// Get setting values
		foreach ( $this->settings as $key => $val ) $this->$key = $val;

		// Switch the Gateway to the Live url if it is set to live.
		if ( $this->transaction_mode == 'LIVE' ) {
			$this->gateway_url = 'https://ctpe.net/frontend/';
			$this->query_url = 'https://ctpe.io/payment/ctpe';	
			$this->post_query_url = 'https://ctpe.net/frontend/payment.prc';		
		} else {
			$this->gateway_url = 'https://test.ctpe.net/frontend/';
			$this->query_url = 'https://test.ctpe.io/payment/ctpe';	
			$this->post_query_url = 'https://test.ctpe.net/frontend/payment.prc';			
		}

		$this->base_request = array(
	     		'REQUEST.VERSION' 				=> '1.0',
		      	'SECURITY.SENDER'				=> $this->sender,
		      	'USER.LOGIN'					=> $this->username,
		      	'USER.PWD'						=> $this->password,

		      	'TRANSACTION.MODE'				=> $this->transaction_mode,
		      	'TRANSACTION.RESPONSE'			=> 'SYNC',
		      	'TRANSACTION.CHANNEL'			=> $this->channel,
				);
				
		// Hooks
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );  // WC >= 2.0
		add_action( 'admin_notices', array( $this, 'ecommerce_ssl_check' ) );
		
		// Add Copy and Pay form to receipt_page
		add_action( 'woocommerce_receipt_peach-payments', array( $this, 'receipt_page' ) );

		// API Handler
		add_action( 'woocommerce_api_wc_peach_payments', array( $this, 'process_payment_status') );

		//Scripts
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Initialize Gateway Settings form fields
	 *
	 * @access public
	 * @return void
	 */
	function init_form_fields() {
		$this->form_fields = array(
				'enabled'     	=> array(
			        'title'       	=> __( 'Enable/Disable', 'woocommerce-gateway-peach-payments' ),
			        'label'       	=> __( 'Enable Peach Payments', 'woocommerce-gateway-peach-payments' ),
			        'type'        	=> 'checkbox',
			        'description' 	=> '',
			        'default'     	=> 'no'
		        ),

				'card_storage'     	=> array(
			        'title'       	=> __( 'Card Storage', 'woocommerce-gateway-peach-payments' ),
			        'label'       	=> __( 'Enable Card Storage', 'woocommerce-gateway-peach-payments' ),
			        'type'        	=> 'checkbox',
			        'description' 	=> __( 'Allow customers to store cards against their account. Required for subscriptions.', 'woocommerce-gateway-peach-payments' ),
			        'default'     	=> 'yes'
		        ),

				'title'       	=> array(
					'title'       	=> __( 'Title', 'woocommerce-gateway-peach-payments' ),
					'type'        	=> 'text',
					'description' 	=> __( 'This controls the title which the user sees during checkout.', 'woocommerce-gateway-peach-payments' ),
					'default'     	=> __( 'Credit Card', 'woocommerce-gateway-peach-payments' )
				),

				'description' 	=> array(
					'title'       	=> __( 'Description', 'woocommerce-gateway-peach-payments' ),
					'type'        	=> 'textarea',
					'description'	=> __( 'This controls the description which the user sees during checkout.', 'woocommerce-gateway-peach-payments' ),
					'default'	    => 'Pay with your credit card via Peach Payments.'
				),

				'cards' 		=> array(
					'title'			=> __( 'Supported Cards', 'woocommerce-gateway-peach-payments'),
					'type'			=> 'multiselect',
					'description'	=> __( 'Choose the cards you wish to accept.', 'woocommerce-gateway-peach-payments'),
					'options'		=> array( 
										'VISA' => 'VISA', 
										'MASTER' => 'Master Card', 
										'AMEX' => 'American Express',
									),
					'default'		=> array( 'VISA', 'MASTER' ),
					'class'         => 'chosen_select',
                    'css'           => 'width: 450px;'
				),

				'username'    => array(
					'title'		    => __( 'User Login', 'woocommerce-gateway-peach-payments' ),
					'type'        	=> 'text',
					'description' 	=> __( 'This is the API username generated within the Peach Payments Commerce gateway.', 'woocommerce-gateway-peach-payments' ),
					'default'     	=> ''
				),

				'password'    => array(
					'title'       	=> __( 'User Password', 'woocommerce-gateway-peach-payments' ),
					'type'        	=> 'password',
					'description' 	=> __( 'This is the API user password generated within the Peach Payments Commerce gateway.', 'woocommerce-gateway-peach-payments' ),
					'default'     	=> ''
				),

				'sender'	=> array(
					'title'       => __( 'Sender ID', 'woocommerce-gateway-peach-payments' ),
					'type'        => 'text',
					'description' => __( '', 'woocommerce-gateway-peach-payments' ),
					'default'     => ''
				),

				'channel'    => array(
					'title'       => __( 'Channel ID', 'woocommerce-gateway-peach-payments' ),
					'type'        => 'text',
					'description' => __( '', 'woocommerce-gateway-peach-payments' ),
					'default'     => ''
				),	

				'channel_3ds'    => array(
					'title'       => __( '3DSecure Channel ID', 'woocommerce-gateway-peach-payments' ),
					'type'        => 'text',
					'description' => __( 'If there is no specific 3DSecure Channel ID then use Channel ID here again.', 'woocommerce-gateway-peach-payments' ),
					'default'     => ''
				),

				'transaction_mode'   => array(
					'title'       => __( 'Transaction Mode', 'woocommerce-gateway-peach-payments' ),
					'type'        => 'select',
					'description' => __( 'Set your gateway to live when you are ready.', 'woocommerce-gateway-peach-payments' ),
					'default'     => 'INTEGRATOR_TEST',
					'options'     => array(
							'INTEGRATOR_TEST'	      => 'Integrator Test',
							'CONNECTOR_TEST'		  => 'Connector Test',
							'LIVE'		     		  => 'Live'
					)
				)
		);
	}
	
	
	/**
	 * Register and enqueue specific JavaScript.
	 *
	 * @access public
	 * @return    void    Return early if no settings page is registered.
	 */
	public function enqueue_scripts() {
	
		if ( is_checkout_pay_page() && !isset($_GET['registered_payment']) )  {		
			wp_enqueue_script( 'peach-payments-widget-js', $this->gateway_url. 'widget/v3/widget.js?language=en&style=none');
			wp_enqueue_style( 'peach-payments-widget-css', plugins_url( 'assets/css/cc-form.css', dirname(__FILE__) ) );
		}
	
	}	
	
	/**
	 * Check if SSL is enabled.
     *
     * @access public
     * @return void
     */
	function ecommerce_ssl_check() {
		if ( get_option( 'woocommerce_force_ssl_checkout' ) == 'no' && $this->enabled == 'yes' ) {
			echo '<div class="error"><p>We have detected that you currently don\'t have SSL enabled. Peach Payments recommends using SSL on your website. Please enable SSL and ensure your server has a valid SSL certificate.</p></div>';
		}
	}	

	/**
	 * Adds option for registering or using existing Peach Payments details
	 * 
	 * @access public
	 * @return void
	 **/
	function payment_fields() {
		?>
		<fieldset>

        <?php if ( is_user_logged_in() && $this->card_storage == 'yes' ) : ?>

				<p class="form-row form-row-wide">

					<?php if ( $credit_cards = get_user_meta( get_current_user_id(), '_peach_payment_id', false ) ) : ?>

						<?php foreach ( $credit_cards as $i => $credit_card ) : ?>
							<input type="radio" id="peach_card_<?php echo $i; ?>" name="peach_payment_id" style="width:auto;" value="<?php echo $i; ?>" />
							<label style="display:inline;" for="peach_card_<?php echo $i; ?>"><?php echo get_card_brand_image( $credit_card['brand'] ); ?> <?php echo '**** **** **** ' . $credit_card['active_card']; ?> (<?php echo $credit_card['exp_month'] . '/' . $credit_card['exp_year'] ?>)</label><br />
						<?php endforeach; ?>

						<br /> <a class="button" style="float:right;" href="<?php echo get_permalink( get_option( 'woocommerce_myaccount_page_id' ) ); ?>#saved-cards"><?php _e( 'Manage cards', 'woocommerce-gateway-peach-payments' ); ?></a>

					<?php endif; ?>

					<input type="radio" id="saveinfo" name="peach_payment_id" style="width:auto;" value="saveinfo"/> <label style="display:inline;" for="saveinfo"><?php _e( 'Use a new credit card and store method for future use.', 'woocommerce-gateway-peach-payments' ); ?></label><br />

					<input type="radio" id="dontsave" name="peach_payment_id" style="width:auto;" value="dontsave"/> <label style="display:inline;" for="dontsave"><?php _e( 'Use a new credit card without storing.', 'woocommerce-gateway-peach-payments' ); ?></label>

				</p>
				<div class="clear"></div>

		<?php else: ?>

				<p><?php _e( 'Pay using a credit card', 'woocommerce-gateway-peach-payments' ); ?></p>

		<?php endif; ?>

		</fieldset>
		<?php
	}

	/**
	 * Display admin options
	 *
	 * @access public
	 * @return void
	 */
	function admin_options() {
		?>
		<h3><?php _e( 'Peach Payments', 'woocommerce-gateway-peach-payments' ); ?></h3>

		<?php if ( 'ZAR' == get_option( 'woocommerce_currency' ) ) { ?>
	    	<table class="form-table">
	    	<?php
				// Generate the HTML For the settings form.
				$this->generate_settings_html();
			?>
			</table><!--/.form-table-->
		<?php } else { ?>
			<div class="inline error"><p><strong><?php _e( 'Gateway Disabled', 'woocommerce-gateway-peach-payments' ); ?></strong> <?php echo sprintf( __( 'Choose South African Rands as your store currency in <a href="%s">General Options</a> to enable the Peach Payments Gateway.', 'woocommerce-gateway-peach-payments' ), admin_url( '?page=woocommerce_settings&tab=general' ) ); ?></p></div>
		<?php }
	}

	/**
     * Process the payment and return the result
     *
     * @access public
     * @param int $order_id
     * @return array
     */
     function process_payment( $order_id ) {
     	global $woocommerce;

     	$order = new WC_Order( $order_id );

     	
     	try {
     		if ( isset( $_POST['peach_payment_id'] ) && ctype_digit( $_POST['peach_payment_id'] ) ) {
				
				$payment_ids = get_user_meta( $order->user_id, '_peach_payment_id', false );
				$payment_id = $payment_ids[ $_POST['peach_payment_id'] ]['payment_id'];

				//throw exception if payment method does not exist
				if ( ! isset( $payment_ids[ $_POST['peach_payment_id'] ]['payment_id'] ) ) {
					throw new Exception( __( 'Invalid Payment Method', 'woocommerce-gateway-peach-payments' ) );
				}

				$redirect_url = $this->execute_post_payment_request( $order, $order->order_total, $payment_id );

				//throw exception if payment is not accepted
				if ( is_wp_error( $redirect_url ) ) {
					throw new Exception( $redirect_url->get_error_message() );
				}

				return array(
				          'result'   => 'success',
				          'redirect' => $redirect_url
				        );
			}
			else {

				$order_request = array(
			     		'IDENTIFICATION.TRANSACTIONID'	=> $order_id,
			     		'IDENTIFICATION.SHOPPERID'		=> $order->user_id,     		

			     		'NAME.GIVEN'					=> $order->billing_first_name,
				     	'NAME.FAMILY'					=> $order->billing_last_name, 
				     	       		
				     	'ADDRESS.STREET'				=> $order->billing_address_1,        		
				        'ADDRESS.ZIP'					=> $order->billing_postcode,
				        'ADDRESS.CITY'					=> $order->billing_city,        		
				        'ADDRESS.STATE'					=> $order->billing_state,
				        'ADDRESS.COUNTRY'				=> $order->billing_country,
				        
				        'CONTACT.EMAIL'					=> $order->billing_email,
				        'CONTACT.IP'					=> $_SERVER['REMOTE_ADDR']
			     		);

				if ( $_POST['peach_payment_id'] == 'saveinfo' ) {
					$payment_request = array(
						'PAYMENT.TYPE'					=> 'RG',
						'RECURRENCE.MODE'				=> 'INITIAL'
				      	);

					if ( $this->transaction_mode == 'CONNECTOR_TEST' || 'LIVE' ) {
						$payment_request['CRITERION.presentation.currency3D'] = 'ZAR';
						$payment_request['CRITERION.presentation.amount3D'] = '1.0';
					}

					if ( $this->transaction_mode == 'CONNECTOR_TEST' ) {
						$payment_request['CRITERION.USE_3D_SIMULATOR'] = 'false';
					}
				} 
				else {
					$payment_request = array(
						'PAYMENT.TYPE'					=> 'DB',
						'PRESENTATION.USAGE'			=> 'Order ' . $order->get_order_number(),
			     		'PRESENTATION.AMOUNT'			=> $order->order_total,
				      	'PRESENTATION.CURRENCY'			=> 'ZAR' 
				      	);

					if ( $this->transaction_mode == 'CONNECTOR_TEST' ) {
						$payment_request['CRITERION.USE_3D_SIMULATOR'] = 'false';
					}
				}

				$order_request = array_merge( $order_request, $this->base_request );
				$request = array_merge( $payment_request, $order_request );

				//use 3dSecure
				$request['TRANSACTION.CHANNEL'] = $this->channel_3ds;

				$json_token_response = $this->generate_token( $request );

				if ( is_wp_error( $json_token_response ) ) {
					throw new Exception( $json_token_response->get_error_message() );
				}

				//token received - offload payment processing to copyandpay form
				return array(
		          'result'   => 'success',
		          'redirect' => $order->get_checkout_payment_url( true )
		        );

			}

     	} catch( Exception $e ) {
				wc_add_notice( __('Error:', 'woocommerce-gateway-peach-payments') . ' "' . $e->getMessage() . '"' , 'error' );
				return;
		}
		
     }
		
	/**
	 * Trigger the payment form for the payment page shortcode.
	 *
	 * @access public
	 * @param object $order
	 * @return null
	 */	
	function receipt_page( $order_id ) {

		if ( isset( $_GET['registered_payment'] ) ) {
			$status = $_GET['registered_payment'];
			$this->process_registered_payment_status( $order_id, $status );
		} else {
			echo $this->generate_peach_payments_form( $order_id );
		}

	}

	/**
	 * Generate the Peach Payments Copy and Pay form
     *
     * @access public
     * @param mixed $order_id
     * @return string
     */
    function generate_peach_payments_form( $order_id ) {
		global $woocommerce;

		$order = new WC_Order( $order_id );

		$payment_token = get_post_meta( $order_id, '_peach_payment_token', true );
		$merchant_endpoint = add_query_arg( 'wc-api', 'WC_Peach_Payments', home_url( '/' ) );

		$supported_cards = implode( ' ', $this->cards );
		
		return '<form action="' . $merchant_endpoint . '" id="' . $payment_token . '">' . $supported_cards . '</form>';

	}

	/**
	 * WC API endpoint for Copy and Pay response
     *
     * @access public
     * @return void
     */
	function process_payment_status() {
		global $woocommerce;

		$token = $_GET['token'];

		$parsed_response = $this->get_token_status( $token );

		if ( is_wp_error( $parsed_response ) ) {
			$order->update_status('failed', __('Payment Failed: Couldn\'t connect to gateway server - Peach Payments', 'woocommerce-gateway-peach-payments') );
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		$order_id = $parsed_response->transaction->identification->transactionid;
		$order = new WC_Order( $order_id );

		//handle failed registration
		if ( $parsed_response->transaction->payment->code == 'CC.RG' && $parsed_response->transaction->processing->result == 'NOK' ) {
			$order->update_status('pending', __('Registration Failed: Card registration was not accpeted - Peach Payments', 'woocommerce-gateway-peach-payments') );
			wp_safe_redirect( $this->get_checkout_payment_url( true ) );
			exit;
		}

		//handle card registration
		if ( $parsed_response->transaction->payment->code == 'CC.RG' ) {

			$this->add_customer( $parsed_response );

			$redirect_url = $this->execute_post_payment_request( $order, $order->order_total, $parsed_response->transaction->identification->uniqueId );

			if ( is_wp_error( $redirect_url ) ) {
				$order->update_status('failed', __('Payment Failed: Couldn\'t connect to gateway server - Peach Payments', 'woocommerce-gateway-peach-payments') );
				wp_safe_redirect( $this->get_return_url( $order ) );
				exit;
			}

			wp_safe_redirect( $redirect_url );
			exit;
		}


		//handle non-registered payment response 
		if ( $parsed_response->transaction->payment->code == 'CC.DB' ) {

			if ( $parsed_response->transaction->processing->result == 'ACK' ) {
				$order->payment_complete();
				wp_safe_redirect( $this->get_return_url( $order ) );
				exit;
			} 
			elseif ( $parsed_response->transaction->processing->result == 'NOK' ) {
				$order->update_status('failed');
				wp_safe_redirect( $this->get_return_url( $order ) );
				exit;
			}
			
		}

		if ( ! empty( $parsed_response->errorMessage ) ) {
			
			$order->update_status('failed', sprintf(__('Payment Failed: Payment Response is "%s" - Peach Payments.', 'woocommerce-gateway-peach-payments'), $parsed_response->errorMessage ) );
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;

		} elseif ( $parsed_response->transaction->processing->result == 'NOK' ) {

			$order->update_status('failed', sprintf(__('Payment Failed: Payment Response is "%s" - Peach Payments.', 'woocommerce-gateway-peach-payments'), $parsed_response->transaction->processing->return->message ) );
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		} 
		
	}

	/**
	 * Process respnse from registered payment request on POST api 
	 *
	 * @access public
	 * @param string $order_id
	 * @param string $status
	 * @return void
	 */
	function process_registered_payment_status( $order_id, $status ) {
		global $woocommerce;

		$order = new WC_Order( $order_id );

		if ( $status == 'NOK' ) {
			$order->update_status('failed');
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}
		elseif ( $status == 'ACK' ) {
			$order->payment_complete();
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}
	}

	/**
	 * Generate token for Copy and Pay API 
	 *
	 * @access public
	 * @param object $response
	 * @return void
	 */
	function generate_token( $request ) {
		global $woocommerce;

		$response = wp_remote_post( $this->gateway_url . 'GenerateToken', array(
			'method'		=> 'POST', 
			'body' 			=> $request,
			'timeout' 		=> 70,
			'sslverify' 	=> false,
			'user-agent' 	=> 'WooCommerce ' . $woocommerce->version
		));

		if ( is_wp_error($response) )
			return new WP_Error( 'peach_error', __('There was a problem connecting to the payment gateway.', 'woocommerce-gateway-peach-payments') );

		if( empty($response['body']) )
			return new WP_Error( 'peach_error', __('Empty response.', 'woocommerce-gateway-peach-payments') );

		$parsed_response = json_decode( $response['body'] );

		// Handle response
		if ( ! empty( $parsed_response->error ) ) {

			return new WP_Error( 'peach_error', $parsed_response->error->message );

		} else {
			update_post_meta( $request['IDENTIFICATION.TRANSACTIONID'], '_peach_payment_token', $parsed_response->transaction->token );
		}

		return $parsed_response;
		
	}

	/**
	 * Get status of token after Copy and Pay API
	 *
	 * @access public
	 * @param string $token
	 * @return object
	 */
	function get_token_status( $token ) {
		global $woocommerce;
		
		$url = $this->gateway_url . "GetStatus;jsessionid=" . $token;

		$response = wp_remote_post( $url, array(
			'method'		=> 'POST', 
			'timeout' 		=> 70,
			'sslverify' 	=> false,
			'user-agent' 	=> 'WooCommerce ' . $woocommerce->version
		));

		if ( is_wp_error($response) )
			return new WP_Error( 'peach_error', __('There was a problem connecting to the payment gateway.', 'woocommerce-gateway-peach-payments') );

		if( empty($response['body']) )
			return new WP_Error( 'peach_error', __('Empty response.', 'woocommerce-gateway-peach-payments') );

		$parsed_response = json_decode( $response['body'] );

		return $parsed_response;
	}

	/**
	 * Execute payment request through POST endpoint and returns redirect URL
	 *
	 * @access public
	 * @param object $order
	 * @param string $amount
	 * @param string $payment_method_id
	 * @return string 
	 */
	function execute_post_payment_request( $order, $amount, $payment_method_id ) {
		global $woocommerce;

		$payment_request = array(
	      	'PAYMENT.CODE'					=> 'CC.DB',

	      	'IDENTIFICATION.TRANSACTIONID'	=> $order->id,
     		'IDENTIFICATION.SHOPPERID'		=> $order->user_id,  

	      	'PRESENTATION.USAGE'			=> 'Order #' . $order->get_order_number(),
     		'PRESENTATION.AMOUNT'			=> $amount,
	      	'PRESENTATION.CURRENCY'			=> 'ZAR',

	      	'ACCOUNT.REGISTRATION'			=> $payment_method_id,
	      	'RECURRENCE.MODE'				=> 'REPEATED',
	      	'FRONTEND.ENABLED'				=> 'false'
	      	);

		if ( $this->transaction_mode == 'CONNECTOR_TEST' ) {
			$payment_request['CRITERION.USE_3D_SIMULATOR'] = 'false';
		}

		$request = array_merge( $payment_request, $this->base_request );

        $response = wp_remote_post( $this->post_query_url, array(
			'method'		=> 'POST', 
			'body'			=> $request,
			'timeout' 		=> 70,
			'sslverify' 	=> false,
			'user-agent' 	=> 'WooCommerce ' . $woocommerce->version
		));

		if ( is_wp_error($response) )
			return new WP_Error( 'peach_error', __('There was a problem connecting to the payment gateway.', 'woocommerce-gateway-peach-payments') );

		if( empty($response['body']) )
			return new WP_Error( 'peach_error', __('Empty response.', 'woocommerce-gateway-peach-payments') );

		// Convert response string to array
	    $vars = explode( '&', $response['body'] );
		foreach ( $vars as $key => $val ) {
	        $var = explode( '=', $val );
	        $data[ $var[0] ] = $var[1];
	    }

	    //create redirect link
	    $redirect_url = $this->get_return_url( $order );

		if ( $data['PROCESSING.RESULT'] == 'NOK' ) {

			$order->update_status('failed', sprintf(__('Payment Failed: Payment Response is "%s" - Peach Payments.', 'woocommerce-gateway-peach-payments'), woocommerce_clean( $data['PROCESSING.RETURN'] ) ) );
			return add_query_arg ('registered_payment', 'NOK', $redirect_url );

		} elseif ( $data['PROCESSING.RESULT'] == 'ACK' ) {

			$order->payment_complete();
			$order->add_order_note( sprintf(__('Payment Completed: Payment Response is "%s" - Peach Payments.', 'woocommerce-gateway-peach-payments'), woocommerce_clean( $data['PROCESSING.RETURN'] ) ) );
			return add_query_arg( 'registered_payment', 'ACK', $redirect_url );
		}

		
	}


	/**
	 * add_customer function.
	 *
	 * @access public
	 * @param object $response
	 * @return void
	 */
	function add_customer( $response ) {

		$user_id = $response->transaction->identification->shopperid;

		if ( isset( $response->transaction->account->last4Digits ) )
			add_user_meta( $user_id, '_peach_payment_id', array(
				'payment_id' 	=> $response->transaction->identification->uniqueId,
				'active_card' 	=> $response->transaction->account->last4Digits,
				'brand'			=> $response->transaction->account->brand,
				'exp_year'		=> $response->transaction->account->expiry->year,
				'exp_month'		=> $response->transaction->account->expiry->month,
			) );
	
	}

}