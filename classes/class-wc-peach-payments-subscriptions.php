<?php
/**
 * Peach Payments Gateway
 *
 * Provides an Peach Payments WPF Gateway
 *
 * @class 		WC_Peach_Payments_Subscriptions
 * @extends		WC_Peach_Payments
 * @version		1.6.6
 * @package		WC_Peach_Payments
 * @author 		Domenico Nusca
 */
class WC_Peach_Payments_Subscriptions extends WC_Peach_Payments {

	function __construct() {

		parent::__construct();

		add_action( 'scheduled_subscription_payment_' . $this->id, array( &$this, 'scheduled_subscription_payment' ), 10, 3 );

		add_filter( 'woocommerce_subscriptions_renewal_order_meta_query', array( &$this, 'remove_renewal_order_meta' ), 10, 4 );

		add_action( 'woocommerce_subscriptions_changed_failing_payment_method_peach-payments', array( &$this, 'update_failing_payment_method' ), 10, 3 );

		add_action( 'woocommerce_api_wc_peach_payments_subscriptions', array( &$this, 'process_payment_status') );
	}

	/**
     * Process the subscription payment and return the result
     *
     * @access public
     * @param int $order_id
     * @return array
     */
	function process_payment( $order_id ) {
		global $woocommerce;

		$order = new WC_Order( $order_id );

		if ( class_exists( 'WC_Subscriptions_Order' ) && WC_Subscriptions_Order::order_contains_subscription( $order_id ) ) {

			try {
				// Check if paying with registered payment method
				if ( isset( $_POST['peach_payment_id'] ) && ctype_digit( $_POST['peach_payment_id'] ) ) {
					
					$payment_ids = get_user_meta( $order->user_id, '_peach_payment_id', false );
					$payment_id = $payment_ids[ $_POST['peach_payment_id'] ]['payment_id'];

					//throw exception if payment method does not exist
					if ( ! isset( $payment_ids[ $_POST['peach_payment_id'] ]['payment_id'] ) ) {
						throw new Exception( __( 'Invalid', 'woocommerce-gateway-peach-payments' ) );
					}

					$initial_payment = WC_Subscriptions_Order::get_total_initial_payment( $order );

					if ( $initial_payment > 0 ) {
						$response = $this->execute_post_subscription_payment_request( $order, $initial_payment, $payment_id );

						if ( is_wp_error( $response ) ) {
							throw new Exception( $response->get_error_message() );
						}

						$redirect_url = $this->get_return_url( $order );

						if ( $response['PROCESSING.RESULT'] == 'NOK' ) {
							$order->update_status('failed', sprintf(__('Subscription Payment Failed: Payment Response is "%s" - Peach Payments.', 'woocommerce-gateway-peach-payments'), woocommerce_clean( $data['PROCESSING.RETURN'] ) ) );
							$redirect_url = add_query_arg ('registered_payment', 'NOK', $redirect_url );
						} elseif ( $response['PROCESSING.RESULT'] == 'ACK' ) {
							$order->payment_complete();
							$order->add_order_note( sprintf(__('Subscription Payment Completed: Payment Response is "%s" - Peach Payments.', 'woocommerce-gateway-peach-payments'), woocommerce_clean( $data['PROCESSING.RETURN'] ) ) );
							$redirect_url = add_query_arg( 'registered_payment', 'ACK', $redirect_url );
						}

					} else {
						update_post_meta( $order->id, '_peach_subscription_payment_method', $payment_id );
						$redirect_url = $this->get_return_url( $order );
					}

					return array(
			          'result'   => 'success',
			          'redirect' => $redirect_url
			        );
		
				}
				elseif ( isset( $_POST['peach_payment_id'] ) && ( $_POST['peach_payment_id'] == 'dontsave' ) ) {
					throw new Exception( __( 'You need to store your payment method in order to purchase a subscription.', 'woocommerce-gateway-peach-payments' ) );
				}
				else/*if ( isset( $_POST['peach_payment_id'] ) && ( $_POST['peach_payment_id'] == 'saveinfo' ) )*/ {
					$subscription_request = array(
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
				        'CONTACT.IP'					=> $_SERVER['REMOTE_ADDR'],

				        'PAYMENT.TYPE'					=> 'RG',
				        'RECURRENCE.MODE'				=> 'INITIAL'
			     		);

					if ( $this->transaction_mode == 'CONNECTOR_TEST' || 'LIVE' ) {
						$subscription_request['CRITERION.presentation.currency3D'] = 'ZAR';
						$subscription_request['CRITERION.presentation.amount3D'] = '1.0';
					}

					if ( $this->transaction_mode == 'CONNECTOR_TEST' ) {
						$subscription_request['CRITERION.USE_3D_SIMULATOR'] = 'false';
					}

					$request = array_merge( $subscription_request, $this->base_request );

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

		} else {

			return parent::process_payment( $order_id );

		}
	}

	/**
	 * scheduled_subscription_payment function.
	 *
	 * @param $amount_to_charge float The amount to charge.
	 * @param $order WC_Order The WC_Order object of the order which the subscription was purchased in.
	 * @param $product_id int The ID of the subscription product for which this payment relates.
	 * @access public
	 * @return void
	 */
	function scheduled_subscription_payment( $amount_to_charge, $order, $product_id ) {

		$payment_id =get_post_meta( $order->id, '_peach_subscription_payment_method', true );
		$result = $this->execute_post_subscription_payment_request( $order, $amount_to_charge, $payment_id );

		if ( is_wp_error( $result ) ) {
			$order->add_order_note( __('Scheduled Subscription Payment Failed: Couldn\'t connect to gateway server - Peach Payments', 'woocommerce-gateway-peach-payments') );
			WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $order, $product_id );	
		} elseif ( $result['PROCESSING.RESULT'] == 'NOK' ) {
			$order->add_order_note( sprintf(__('Scheduled Subscription Payment Failed: Payment Response is "%s" - Peach Payments.', 'woocommerce-gateway-peach-payments'), woocommerce_clean($result['PROCESSING.RETURN']) ) );
			WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $order, $product_id );		
		} elseif ( $result['PROCESSING.RESULT'] == 'ACK' ) {
			$order->add_order_note( sprintf(__('Scheduled Subscription Payment Accepted: Payment Response is "%s" - Peach Payments.', 'woocommerce-gateway-peach-payments'), woocommerce_clean($result['PROCESSING.RETURN']) )  );
			WC_Subscriptions_Manager::process_subscription_payments_on_order( $order );
		}else{
			$order->add_order_note( __('Scheduled Subscription Payment Failed: An unknown error has occured - Peach Payments', 'woocommerce-gateway-peach-payments') );
			WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $order, $product_id );				
		}

	}

	/**
	 * Execute subscriptions payment request through POST endpoint and returns response array
	 * 
	 * @access public
	 * @param object $order
	 * @param int $amount
	 * @param string $payment_method_id
	 * @return array
	 */
	function execute_post_subscription_payment_request( $order, $amount, $payment_method_id ) {
		global $woocommerce;

		$order_items = $order->get_items();
		$product = $order->get_product_from_item( array_shift( $order_items ) );
		$subscription_name = sprintf( __( 'Subscription for "%s"', 'woocommerce-gateway-peach-payments' ), $product->get_title() ) . ' ' . sprintf( __( '(Order %s)', 'woocommerce-gateway-peach-payments' ), $order->get_order_number() );
	

		$payment_request = array(
	      	'PAYMENT.CODE'					=> 'CC.DB',

	      	'IDENTIFICATION.TRANSACTIONID'	=> $order->id,
     		'IDENTIFICATION.SHOPPERID'		=> $order->user_id,  

	      	'PRESENTATION.USAGE'			=> $subscription_name,
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

		return $data;
	}

	/**
	 * WC API endpoint for Subscriptions Copy and Pay response 
	 *
	 * @access public
	 * @return void
	 */
	function process_payment_status() {
		global $woocommerce;

		$token = $_GET['token'];

		$parsed_response = $this->get_token_status( $token );

		if ( $parsed_response->transaction->payment->code == 'CC.RG' && $parsed_response->transaction->processing->result == 'NOK' ) {
			$order->update_status('pending', __('Registration Failed: Card registration was not accpeted - Peach Payments', 'woocommerce-gateway-peach-payments') );
			wp_safe_redirect( $this->get_checkout_payment_url( true ) );
			exit;
		}

		if ( is_wp_error( $parsed_response ) ) {
			$order->update_status('failed', __('Subscription Payment Failed: Couldn\'t connect to gateway - Peach Payments', 'woocommerce-gateway-peach-payments') );
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		$order_id = $parsed_response->transaction->identification->transactionid;

		if ( class_exists( 'WC_Subscriptions_Order' ) && WC_Subscriptions_Order::order_contains_subscription( $order_id ) ) {

			$order = new WC_Order( $order_id );

			//handle card registration
			if (  $parsed_response->transaction->payment->code == 'CC.RG'  ) {
				
				$this->add_customer( $parsed_response );

				$initial_payment = WC_Subscriptions_Order::get_total_initial_payment( $order );
				$payment_id = $parsed_response->transaction->identification->uniqueId;

				if ( $initial_payment > 0 ) {

					$response = $this->execute_post_subscription_payment_request( $order, $initial_payment, $payment_id );

					if ( is_wp_error( $response ) ) {
						$order->update_status('failed', $response->get_error_message() );
						wp_safe_redirect( $this->get_return_url( $order ) );
						exit;
					}

					$redirect_url = $this->get_return_url( $order ); 

					if ( $response['PROCESSING.RESULT'] == 'NOK' ) {
						$order->update_status('failed', sprintf(__('Subscription Payment Failed: Payment Response is "%s" - Peach Payments.', 'woocommerce-gateway-peach-payments'), woocommerce_clean( $data['PROCESSING.RETURN'] ) ) );
						$redirect_url = add_query_arg ('registered_payment', 'NOK', $redirect_url );
					} elseif ( $response['PROCESSING.RESULT'] == 'ACK' ) {
						$order->payment_complete();
						$order->add_order_note( sprintf(__('Subscription Payment Completed: Payment Response is "%s" - Peach Payments.', 'woocommerce-gateway-peach-payments'), woocommerce_clean( $data['PROCESSING.RETURN'] ) ) );
						update_post_meta( $order->id, '_peach_subscription_payment_method', $payment_id );
						$redirect_url = add_query_arg( 'registered_payment', 'ACK', $redirect_url );
					}

				} else {
					update_post_meta( $order->id, '_peach_subscription_payment_method', $payment_id );
					$redirect_url = $this->get_return_url( $order );
				}

				wp_safe_redirect( $redirect_url );
				exit;
			}

			if ( ! empty( $parsed_response->errorMessage ) ) {
				
				$order->update_status('failed', sprintf(__('Subscription Payment Failed: Payment Response is "%s" - Peach Payments.', 'woocommerce-gateway-peach-payments'), $parsed_response->errorMessage ) );
				wp_safe_redirect( $this->get_return_url( $order ) );
				exit;

			} elseif ( $parsed_response->transaction->processing->result == 'NOK' ) {

				$order->update_status('failed', sprintf(__('Subscription Payment Failed: Payment Response is "%s" - Peach Payments.', 'woocommerce-gateway-peach-payments'), $parsed_response->transaction->processing->return->message ) );
				wp_safe_redirect( $this->get_return_url( $order ) );
				exit;

			} 

		} else {

			return parent::process_payment_status();

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

		if ( class_exists( 'WC_Subscriptions_Order' ) && WC_Subscriptions_Order::order_contains_subscription( $order_id ) ) {

			$order = new WC_Order( $order_id );

			$payment_token = get_post_meta( $order_id, '_peach_payment_token', true );
			$merchant_endpoint = add_query_arg( 'wc-api', 'WC_Peach_Payments_Subscriptions', home_url( '/' ) );
			
			return '<form action="' . $merchant_endpoint . '" id="' . $payment_token . '">MASTER VISA</form>';
		} else {
			return parent::generate_peach_payments_form( $order_id );
		} 

	}

		/**
	 * Don't transfer Peach Payments payment/token meta when creating a parent renewal order.
	 *
	 * @access public
	 * @param array $order_meta_query MySQL query for pulling the metadata
	 * @param int $original_order_id Post ID of the order being used to purchased the subscription being renewed
	 * @param int $renewal_order_id Post ID of the order created for renewing the subscription
	 * @param string $new_order_role The role the renewal order is taking, one of 'parent' or 'child'
	 * @return void
	 */
	function remove_renewal_order_meta( $order_meta_query, $original_order_id, $renewal_order_id, $new_order_role ) {

		if ( 'parent' == $new_order_role )
			$order_meta_query .= " AND `meta_key` NOT LIKE '_peach_subscription_payment_method' "
							  .  " AND `meta_key` NOT LIKE '_peach_payment_token' ";

		return $order_meta_query;
	}

	/**
	 * Update the payment_id for a subscription after using Peach Payments to complete a payment to make up for
	 * an automatic renewal payment which previously failed.
	 *
	 * @access public
	 * @param WC_Order $original_order The original order in which the subscription was purchased.
	 * @param WC_Order $renewal_order The order which recorded the successful payment (to make up for the failed automatic payment).
	 * @param string $subscription_key A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 * @return void
	 */
	function update_failing_payment_method( $original_order, $renewal_order, $subscription_key ) {
		global $woocommerce;
		

		try {
			// Check if paying with registered payment method
			if ( isset( $_POST['peach_payment_id'] ) && ctype_digit( $_POST['peach_payment_id'] ) ) {
				
				$payment_ids = get_user_meta( $original_order->user_id, '_peach_payment_id', false );
				$payment_id = $payment_ids[ $_POST['peach_payment_id'] ]['payment_id'];

				//throw exception if payment method does not exist
				if ( ! isset( $payment_ids[ $_POST['peach_payment_id'] ]['payment_id'] ) ) {
					throw new Exception( __( 'Invalid', 'woocommerce-gateway-peach-payments' ) );
				} else {
					update_post_meta( $original_order->id, '_peach_subscription_payment_method', $payment_id );	
				}
			} elseif ( isset( $_POST['peach_payment_id'] ) && ( $_POST['peach_payment_id'] == 'saveinfo' ) ) {
					$subscription_request = array(
			     		'IDENTIFICATION.TRANSACTIONID'	=> $original_order->id,
			     		'IDENTIFICATION.SHOPPERID'		=> $original_order->user_id,     		

			     		'NAME.GIVEN'					=> $original_order->billing_first_name,
				     	'NAME.FAMILY'					=> $original_order->billing_last_name, 
				     	       		
				     	'ADDRESS.STREET'				=> $original_order->billing_address_1,        		
				        'ADDRESS.ZIP'					=> $original_order->billing_postcode,
				        'ADDRESS.CITY'					=> $original_order->billing_city,        		
				        'ADDRESS.STATE'					=> $original_order->billing_state,
				        'ADDRESS.COUNTRY'				=> $original_order->billing_country,
				        
				        'CONTACT.EMAIL'					=> $original_order->billing_email,
				        'CONTACT.IP'					=> $_SERVER['REMOTE_ADDR'],

				        'PAYMENT.TYPE'					=> 'RG',
				        'RECURRENCE.MODE'				=> 'INITAL'
			     		);

					if ( $this->transaction_mode == 'CONNECTOR_TEST' || 'LIVE' ) {
						$payment_request['CRITERION.presentation.currency3D'] = 'ZAR';
						$payment_request['CRITERION.presentation.amount3D'] = '1.0';
					}

					if ( $this->transaction_mode == 'CONNECTOR_TEST' ) {
						$payment_request['CRITERION.USE_3D_SIMULATOR'] = 'false';
					}

					$request = array_merge( $subscription_request, $this->base_request );

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

}