<?php
/**
 * Peach Payments Gateway
 *
 * Provides an Peach Payments WPF Gateway
 *
 * @class   WC_Peach_Payments_Subscriptions
 * @extends WC_Peach_Payments
 * @package WC_Peach_Payments
 * @author  LightSpeed
 */
class WC_Peach_Payments_Subscriptions extends WC_Peach_Payments {

	function __construct() {

		parent::__construct();

		add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );
		add_action( 'woocommerce_subscriptions_changed_failing_payment_method_peach-payments', array( &$this, 'update_failing_payment_method' ), 10, 3 );

		add_action( 'woocommerce_api_wc_peach_payments_subscriptions', array( &$this, 'process_payment_status' ) );
		add_action( 'wcs_resubscribe_order_created', array( $this, 'delete_resubscribe_meta' ), 10 );
		// Allow store managers to manually set Simplify as the payment method on a subscription

		add_filter( 'woocommerce_subscription_payment_meta', array( $this, 'add_subscription_payment_meta' ), 10, 2 );
		add_filter( 'woocommerce_subscription_validate_payment_meta', array( $this, 'validate_subscription_payment_meta' ), 10, 2 );
	}

	/**
	 * Adds option for registering or using existing Peach Payments details
	 *
	 * @access public
	 * @return void
	 **/
	function payment_fields() {
		$description = $this->get_description();
		if ( $description ) {
			echo wpautop( wptexturize( $description ) ); // @codingStandardsIgnoreLine.
		}
		?>
		<fieldset>

		<?php if ( is_user_logged_in() && 'yes' == $this->card_storage ) : ?>
			<p class="form-row form-row-wide">

				<?php
				if (
					$credit_cards = get_user_meta( get_current_user_id(), '_peach_payment_id', false ) ) :
				?>

					<?php foreach ( $credit_cards as $i => $credit_card ) : ?>
						<input type="radio" id="peach_card_<?php echo esc_attr( $i ); ?>" name="peach_payment_id" style="width:auto;" value="<?php echo esc_attr( $i ); ?>" />
						<label style="display:inline;" for="peach_card_<?php echo esc_attr( $i ); ?>"><?php echo wp_kses_post( get_card_brand_image( $credit_card['brand'] ) ); ?> <?php echo '**** **** **** ' . esc_attr( $credit_card['active_card'] ); ?> (<?php echo esc_attr( $credit_card['exp_month'] ) . '/' . esc_attr( $credit_card['exp_year'] ); ?>)</label><br />
					<?php endforeach; ?>

					<br /> <a class="button" style="float:right;" href="<?php echo esc_url( get_permalink( get_option( 'woocommerce_myaccount_page_id' ) ) ); ?>#saved-cards"><?php esc_html_e( 'Manage cards', 'wc-gateway-peach-payments' ); ?></a>

				<?php endif; ?>

				<input type="radio" id="saveinfo" name="peach_payment_id" style="width:auto;" value="saveinfo"/> <label style="display:inline;" for="saveinfo"><?php esc_html_e( 'Use a new credit card and store method for future use.', 'wc-gateway-peach-payments' ); ?></label><br />
				<input type="radio" id="dontsave" name="peach_payment_id" style="width:auto;" value="dontsave"/> <label style="display:inline;" for="dontsave"><?php esc_html_e( 'Use a new credit card without storing.', 'wc-gateway-peach-payments' ); ?></label>

			</p>
			<div class="clear"></div>
		<?php endif; ?>

		</fieldset>
		<?php
	}

	//function payment_fields() {}
	/**
	 * Process the subscription payment and return the result
	 *
	 * @access public
	 * @param int $order_id
	 * @return array
	 */
	function process_payment( $order_id ) {

		$order = wc_get_order( $order_id );

		if ( function_exists( 'wcs_order_contains_subscription' ) && ( wcs_order_contains_subscription( $order_id ) || wcs_order_contains_renewal( $order_id ) ) ) {

			try {
				// Check if paying with registered payment method
				if ( isset( $_POST['peach_payment_id'] ) && wp_verify_nonce( $_POST['peach_payment_id'] ) && ctype_digit( $_POST['peach_payment_id'] ) ) {

					$payment_ids = get_user_meta( $this->get_customer_id( $order ), '_peach_payment_id', false );
					$payment_id = $payment_ids[ $_POST['peach_payment_id'] ]['payment_id'];

					//throw exception if payment method does not exist
					if ( ! isset( $payment_ids[ $_POST['peach_payment_id'] ]['payment_id'] ) ) {
						throw new Exception( __( 'Invalid', 'wc-gateway-peach-payments' ) );
					}

					$initial_payment = $order->get_total( $order );

					if ( $initial_payment > 0 ) {
						$response = $this->execute_post_subscription_payment_request( $order, $initial_payment, $payment_id );

						if ( is_wp_error( $response ) ) {
							throw new Exception( $response->get_error_message() );
						}

						$redirect_url = $this->get_return_url( $order );

						if ( 'NOK' == $response['PROCESSING.RESULT'] ) {
							$order->update_status( 'failed', sprintf( e_( 'Subscription Payment Failed: Payment Response is "%s" - Peach Payments.', 'wc-gateway-peach-payments' ), wc_clean( $response['PROCESSING.RETURN'] ) ) );
							$redirect_url = add_query_arg( 'registered_payment', 'NOK', $redirect_url );
						} elseif ( 'ACK' == $response['PROCESSING.RESULT'] ) {
							$order->payment_complete();
							$force_complete = false;
							if ( count( $order->get_items() ) > 0 ) {
								foreach ( $order->get_items() as $item ) {
									if (
										$_product = $this->get_item_product( $item, $order ) ) {
										if ( $_product->is_downloadable() || $_product->is_virtual() ) {
											$force_complete = true;
										}
									}
								}
							}
							if ( $force_complete ) {
								$order->update_status( 'completed' );
							}

							update_post_meta( $this->get_order_id( $order ), '_peach_subscription_payment_method', $payment_id );
							$this->save_subscription_meta( $this->get_order_id( $order ), $payment_id );
							$this->log( '128 Order ID ' . $this->get_order_id( $order ) . ' Parent ID ' . $this->get_order_id( $order ) . ' Payment ID ' . $payment_id );
							$order->add_order_note( sprintf( e_( 'Subscription Payment Completed: Payment Response is "%s" - Peach Payments.', 'wc-gateway-peach-payments' ), wc_clean( $response['PROCESSING.RETURN'] ) ) );
							$redirect_url = add_query_arg( 'registered_payment', 'ACK', $redirect_url );
						}
					} else {
						$order->payment_complete();
						update_post_meta( $this->get_order_id( $order ), '_peach_subscription_payment_method', $payment_id );
						$this->save_subscription_meta( $this->get_order_id( $order ), $payment_id );
						$this->log( '137 Order ID ' . $this->get_order_id( $order ) . ' Parent ID ' . $this->get_order_id( $order ) . ' Payment ID ' . $payment_id );
						$redirect_url = $this->get_return_url( $order );
					}

					return array(
						'result'   => 'success',
						'redirect' => $redirect_url,
					);

				} elseif ( isset( $_POST['peach_payment_id'] ) && ( 'dontsave' == $_POST['peach_payment_id'] ) ) {
					throw new Exception( __( 'You need to store your payment method in order to purchase a subscription.', 'wc-gateway-peach-payments' ) );
				} else /*if ( isset( $_POST['peach_payment_id'] ) && ( $_POST['peach_payment_id'] == 'saveinfo' ) )*/ {
					$subscription_request = array(
						'IDENTIFICATION.TRANSACTIONID' => $order_id,
						'IDENTIFICATION.SHOPPERID'     => $this->get_customer_id( $order ),
						'NAME.GIVEN'                   => $order->billing_first_name,
						'NAME.FAMILY'                  => $order->billing_last_name,
						'ADDRESS.STREET'               => $order->billing_address_1,
						'ADDRESS.ZIP'                  => $order->billing_postcode,
						'ADDRESS.CITY'                 => $order->billing_city,
						'ADDRESS.STATE'                => $order->billing_state,
						'ADDRESS.COUNTRY'              => $order->billing_country,
						'CONTACT.EMAIL'                => $order->billing_email,
						'CONTACT.IP'                   => $_SERVER['REMOTE_ADDR'],
						'PAYMENT.TYPE'                 => 'RG',
						'RECURRENCE.MODE'              => 'INITIAL',
					);

					if ( 'CONNECTOR_TEST' == $this->transaction_mode || 'LIVE' ) {
						$subscription_request['CRITERION.presentation.currency3D'] = 'ZAR';
						$subscription_request['CRITERION.presentation.amount3D']   = $order->get_total();
					}

					if ( 'CONNECTOR_TEST' == $this->transaction_mode ) {
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
						'redirect' => $order->get_checkout_payment_url( true ),
					);
				}
			} catch ( Exception $e ) {
				wc_add_notice( __( 'Error:', 'wc-gateway-peach-payments' ) . ' "' . $e->getMessage() . '"', 'error' );
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
	/*function scheduled_subscription_payment( $amount_to_charge, $renewal_order ) {

		$payment_id =get_post_meta( $order->get_id(), '_peach_subscription_payment_method', true );
		$result = $this->execute_post_subscription_payment_request( $renewal_order, $amount_to_charge, $payment_id );

		if ( is_wp_error( $result ) ) {
			$order_note = __('Scheduled Subscription Payment Failed: Couldn\'t connect to gateway server - Peach Payments', 'wc-gateway-peach-payments');
			$order->add_order_note($order_note);
			$renewal_order->update_status( 'failed', $order_note );
		} elseif ( $result['PROCESSING.RESULT'] == 'NOK' ) {
			$order_note = sprintf(__('Scheduled Subscription Payment Failed: Payment Response is "%s" - Peach Payments.', 'wc-gateway-peach-payments'), woocommerce_clean($result['PROCESSING.RETURN']) ) ;
			$order->add_order_note($order_note);
			$renewal_order->update_status( 'failed', $order_note );
		}

	}*/

	function scheduled_subscription_payment( $amount_to_charge, $order ) {

		if ( wcs_order_contains_renewal( $this->get_order_id( $order ) ) ) {
			$payment_method_order_id = WC_Subscriptions_Renewal_Order::get_parent_order_id( $this->get_order_id( $order ) );
			$payment_id              = get_post_meta( $payment_method_order_id, '_peach_subscription_payment_method', true );
		} else {
			$payment_id = get_post_meta( $this->get_order_id( $order ), '_peach_subscription_payment_method', true );
		}
		$result = $this->execute_post_subscription_payment_request( $order, $amount_to_charge, $payment_id );

		if ( is_wp_error( $result ) ) {
			$order->add_order_note( __( 'Scheduled Subscription Payment Failed: Couldn\'t connect to gateway server - Peach Payments', 'wc-gateway-peach-payments' ) );
			$this->log( print_r( $result, true ) );
			$order->update_status( 'failed' );
			WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $order );
		} elseif ( 'NOK' == $result['PROCESSING.RESULT'] ) {
			/* translators: %s: Scheduled Subscription Payment Failed */
			$order->add_order_note( sprintf( __( 'Scheduled Subscription Payment Failed: Payment Response is "%s" - Peach Payments.', 'wc-gateway-peach-payments' ), wc_clean( $result['PROCESSING.RETURN'] ) ) );
			WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $order );
			$order->update_status( 'failed' );
			$this->log( print_r( $result, true ) );
		} elseif ( 'ACK' == $result['PROCESSING.RESULT'] ) {
			/* translators: %s: Scheduled Subscription Payment Accepted */
			$order->add_order_note( sprintf( __( 'Scheduled Subscription Payment Accepted: Payment Response is "%s" - Peach Payments.', 'wc-gateway-peach-payments' ), wc_clean( $result['PROCESSING.RETURN'] ) ) );
			$order->payment_complete();
			WC_Subscriptions_Manager::process_subscription_payments_on_order( $order );
		} else {
			$order->add_order_note( __( 'Scheduled Subscription Payment Failed: An unknown error has occured - Peach Payments', 'wc-gateway-peach-payments' ) );
			$this->log( print_r( $result, true ) );
			$order->update_status( 'failed' );
			WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $order );
		}

	}

	/**
	 * Execute subscriptions payment request through POST endpoint and returns response array
	 *
	 * @access public
	 * @param object $order
	 * @param int $amount
	 * @param string $payment_method_id
	 * @return array|object
	 */
	function execute_post_subscription_payment_request( $order, $amount, $payment_method_id ) {
		global $woocommerce;

		$data = array();

		$order_items = $order->get_items();
		$product     = $this->get_item_product( array_shift( $order_items ), $order );
		/* translators: %s: Scheduled Subscription for */
		$subscription_name = sprintf( __( 'Subscription for "%s"', 'wc-gateway-peach-payments' ), $product->get_title() ) . ' ' . sprintf( __( '(Order %s)', 'wc-gateway-peach-payments' ), $order->get_order_number() );

		$payment_request = array(
			'PAYMENT.CODE'                 => 'CC.DB',

			'IDENTIFICATION.TRANSACTIONID' => $this->get_order_id( $order ),
			'IDENTIFICATION.SHOPPERID'     => $this->get_customer_id( $order ),

			'PRESENTATION.USAGE'           => $subscription_name,
			'PRESENTATION.AMOUNT'          => $amount,
			'PRESENTATION.CURRENCY'        => 'ZAR',

			'ACCOUNT.REGISTRATION'         => $payment_method_id,
			'RECURRENCE.MODE'              => 'REPEATED',
			'FRONTEND.ENABLED'             => 'false',
		);

		if ( 'CONNECTOR_TEST' == $this->transaction_mode ) {
			$payment_request['CRITERION.USE_3D_SIMULATOR'] = 'false';
		}

		$request = array_merge( $payment_request, $this->base_request );

		$response = wp_remote_post( $this->post_query_url, array(
			'method'     => 'POST',
			'body'       => $request,
			'timeout'    => 70,
			'sslverify'  => true,
			'user-agent' => 'WooCommerce ' . $woocommerce->version,
		));

		if ( is_wp_error( $response ) ) {
			$this->log( __( 'There was a problem connecting to the payment gateway.', 'wc-gateway-peach-payments' ) . ' ' . print_r( $response, true ) );
			return new WP_Error( 'peach_error', __( 'There was a problem connecting to the payment gateway.', 'wc-gateway-peach-payments' ) );
		}

		if ( empty( $response['body'] ) ) {
			$this->log( __( 'Empty response.', 'wc-gateway-peach-payments' ) . ' ' . print_r( $response, true ) );
			return new WP_Error( 'peach_error', __( 'Empty response.', 'wc-gateway-peach-payments' ) );
		}

		// Convert response string to array
		$vars = explode( '&', $response['body'] );
		foreach ( $vars as $key => $val ) {
			$var             = explode( '=', $val );
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
		$order_id        = $parsed_response->transaction->identification->transactionid;

		if ( function_exists( 'wcs_order_contains_subscription' ) && ( wcs_order_contains_subscription( $order_id ) || wcs_order_contains_renewal( $order_id ) ) ) {

			$order                = wc_get_order( $order_id );
			$current_order_status = $order->get_status();
			//Make sure we do not re process any complete or processing orders.
			$force_complete = false;

			if ( 'complete' !== $current_order_status && 'processing' !== $current_order_status ) {

				if ( is_wp_error( $parsed_response ) ) {
					$error_message = __( 'Subscription Payment Failed: Couldn\'t connect to gateway server - Peach Payments', 'wc-gateway-peach-payments' );
					$order->update_status( 'failed', $error_message );
					$this->log( $error_message . ' ' . print_r( $parsed_response, true ) );
					wp_safe_redirect( $this->get_return_url( $order ) );
					exit;
				}

				//handle failed registration
				if ( 'CC.RG' == $parsed_response->transaction->payment->code && 'NOK' == $parsed_response->transaction->processing->result ) {
					$error_message = __( 'Registration Failed: Card registration was not accepted - Peach Payments', 'wc-gateway-peach-payments' );
					$order->update_status( 'failed', $error_message );
					$this->log( $error_message . ' ' . print_r( $parsed_response, true ) );
					wp_safe_redirect( $this->get_return_url( $order ) );
					exit;
				}

				//handle card registration, you have to register a card for subscriptions.
				if ( 'CC.RG' == $parsed_response->transaction->payment->code ) {

					$this->add_customer( $parsed_response );

					$initial_payment = $order->get_total( $order );
					$payment_id      = $parsed_response->transaction->identification->uniqueId;

					if ( $initial_payment > 0 ) {

						$response = $this->execute_post_subscription_payment_request( $order, $initial_payment, $payment_id );

						if ( is_wp_error( $response ) ) {
							$this->log( $response->get_error_message() );
							$order->update_status( 'failed', $response->get_error_message() );
							wp_safe_redirect( $this->get_return_url( $order ) );
							exit;
						}

						$redirect_url = $this->get_return_url( $order );

						if ( 'NOK' == $response['PROCESSING.RESULT'] ) {
							/* translators: %s: Payment Failed */
							$order->update_status( 'failed', sprintf( __( 'Subscription Payment Failed: Payment Response is "%s" - Peach Payments.', 'wc-gateway-peach-payments' ), wc_clean( $response['PROCESSING.RETURN'] ) ) );
							$this->log( wc_clean( $response['PROCESSING.RETURN'] ) );
							$redirect_url = add_query_arg( 'registered_payment', 'NOK', $redirect_url );
						} elseif ( 'ACK' == $response['PROCESSING.RESULT'] ) {
							$order->payment_complete();
							$force_complete = $this->check_orders_products( $order );

							/* translators: %s: Subscription Payment Completed */
							$order->add_order_note( sprintf( __( 'Subscription Payment Completed: Payment Response is "%s" - Peach Payments.', 'wc-gateway-peach-payments' ), wc_clean( $response['PROCESSING.RETURN'] ) ) );
							update_post_meta( $this->get_order_id( $order ), '_peach_subscription_payment_method', $payment_id );
							$this->save_subscription_meta( $this->get_order_id( $order ), $payment_id );

							//Remove this
							$this->log( '415 Order ID ' . $this->get_order_id( $order ) . ' Parent ID ' . $this->get_order_id( $order ) . ' Payment ID ' . $payment_id );
							$redirect_url = add_query_arg( 'registered_payment', 'ACK', $redirect_url );

						}
					} else {
						$order->payment_complete();
						update_post_meta( $this->get_order_id( $order ), '_peach_subscription_payment_method', $payment_id );
						$this->save_subscription_meta( $this->get_order_id( $order ), $payment_id );
						$this->log( '423 Order ID ' . $this->get_order_id( $order ) . ' Parent ID ' . $this->get_order_id( $order ) . ' Payment ID ' . $payment_id );
						$redirect_url = $this->get_return_url( $order );
					}

					if ( 'yes' === $this->force_completed && $force_complete ) {
						$order->update_status( 'completed' );
					}

					wp_safe_redirect( $redirect_url );
					exit;
				}

				$error_message_index = 'errorMessage';
				if ( ! empty( $parsed_response->$error_message_index ) ) {
					$this->log( $parsed_response->$error_message_index );
					//$order->update_status('failed', sprintf(__('Subscription Payment Failed: Payment Response is "%s" - Peach Payments.', 'wc-gateway-peach-payments'), $parsed_response->errorMessage ) );
					wp_safe_redirect( $this->get_return_url( $order ) );
					exit;

				} elseif ( 'NOK' == $parsed_response->transaction->processing->result ) {
					/* translators: %s: Subscription Payment Failed */
					$order->update_status( 'failed', sprintf( __( 'Subscription Payment Failed: Payment Response is "%s" - Peach Payments.', 'wc-gateway-peach-payments' ), $parsed_response->transaction->processing->return->message ) );
					$this->log( print_r( $parsed_response->transaction->processing->return, true ) );
					wp_safe_redirect( $this->get_return_url( $order ) );
					exit;

				}
			}
		} else {

			parent::process_payment_status();
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

		if ( function_exists( 'wcs_order_contains_subscription' ) && ( wcs_order_contains_subscription( $order_id ) || wcs_order_contains_renewal( $order_id ) ) ) {

			$order = wc_get_order( $order_id );

			$payment_token     = get_post_meta( $order_id, '_peach_payment_token', true );
			$merchant_endpoint = add_query_arg( 'wc-api', 'WC_Peach_Payments_Subscriptions', home_url( '/' ) );

			$supported_cards = implode( ' ', $this->cards );
			return '<form action="' . $merchant_endpoint . '" id="' . $payment_token . '">' . $supported_cards . '</form>';

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
	 * @return string
	 */
	function remove_renewal_order_meta( $order_meta_query, $original_order_id, $renewal_order_id, $new_order_role ) {

		if ( 'parent' == $new_order_role )
			$order_meta_query .= " AND `meta_key` NOT LIKE '_peach_subscription_payment_method' "
								. " AND `meta_key` NOT LIKE '_peach_payment_token' ";

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
	 * @return array | string
	 */
	function update_failing_payment_method( $original_order, $renewal_order, $subscription_key ) {

		try {
			// Check if paying with registered payment method
			if ( isset( $_POST['peach_payment_id'] ) && wp_verify_nonce( $_POST['peach_payment_id'] ) && ctype_digit( $_POST['peach_payment_id'] ) ) {

				$payment_ids = get_user_meta( $this->get_customer_id( $original_order ), '_peach_payment_id', false );
				$payment_id  = $payment_ids[ $_POST['peach_payment_id'] ]['payment_id'];

				//throw exception if payment method does not exist
				if ( ! isset( $payment_ids[ $_POST['peach_payment_id'] ]['payment_id'] ) ) {
					throw new Exception( __( 'Invalid', 'wc-gateway-peach-payments' ) );
				} else {
					update_post_meta( $this->get_order_id( $original_order ), '_peach_subscription_payment_method', $payment_id );
				}
			} elseif ( isset( $_POST['peach_payment_id'] ) && ( 'saveinfo' == $_POST['peach_payment_id'] ) ) {
					$subscription_request = array(
						'IDENTIFICATION.TRANSACTIONID' => $this->get_order_id( $original_order ),
						'IDENTIFICATION.SHOPPERID'     => $this->get_customer_id( $original_order ),

						'NAME.GIVEN'                   => $original_order->billing_first_name,
						'NAME.FAMILY'                  => $original_order->billing_last_name,

						'ADDRESS.STREET'               => $original_order->billing_address_1,
						'ADDRESS.ZIP'                  => $original_order->billing_postcode,
						'ADDRESS.CITY'                 => $original_order->billing_city,
						'ADDRESS.STATE'                => $original_order->billing_state,
						'ADDRESS.COUNTRY'              => $original_order->billing_country,

						'CONTACT.EMAIL'                => $original_order->billing_email,
						'CONTACT.IP'                   => $_SERVER['REMOTE_ADDR'],

						'PAYMENT.TYPE'                 => 'RG',
						'RECURRENCE.MODE'              => 'INITAL',
					);

				if ( 'CONNECTOR_TEST' == $this->transaction_mode || 'LIVE' ) {
					$payment_request['CRITERION.presentation.currency3D'] = 'ZAR';
					$payment_request['CRITERION.presentation.amount3D']   = '1.0';
				}
				if ( 'CONNECTOR_TEST' == $this->transaction_mode ) {
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
						'redirect' => $original_order->get_checkout_payment_url( true ),
					);
			}
		} catch ( Exception $e ) {

			wc_add_notice( __( 'Error:', 'wc-gateway-peach-payments' ) . ' "' . $e->getMessage() . '"', 'error' );
			return '';
		}
	}

	/**
	 * Include the payment meta data required to process automatic recurring payments so that store managers can
	 * manually set up automatic recurring payments for a customer via the Edit Subscription screen in Subscriptions v2.0+.
	 *
	 * @since 2.4
	 * @param array $payment_meta associative array of meta data required for automatic payments
	 * @param WC_Subscription $subscription An instance of a subscription object
	 * @return array
	 */
	public function add_subscription_payment_meta( $payment_meta, $subscription ) {
		$payment_meta[ $this->id ] = array(
			'post_meta' => array(
				'_peach_payment_token' => array(
					'value' => get_post_meta( $this->get_order_id( $subscription ), '_peach_subscription_payment_method', true ),
					'label' => 'Peach Payment Method',
				),
			),
		);
		return $payment_meta;
	}
	/**
	 * Validate the payment meta data required to process automatic recurring payments so that store managers can
	 * manually set up automatic recurring payments for a customer via the Edit Subscription screen in Subscriptions 2.0+.
	 *
	 * @since 2.4
	 * @param string $payment_method_id The ID of the payment method to validate
	 * @param array $payment_meta associative array of meta data required for automatic payments
	 * @return array
	 */
	public function validate_subscription_payment_meta( $payment_method_id, $payment_meta ) {
		if ( $this->id === $payment_method_id ) {
			$this->log( 'Validate Payment Meta ' . print_r( $payment_meta, true ) );

			/*  @TODO Look at this for pre 2.6 compat */
			if ( ! isset( $payment_meta['post_meta']['_peach_payment_token']['value'] ) || empty( $payment_meta['post_meta']['_peach_payment_token']['value'] ) ) {
				throw new Exception( 'A "_peach_subscription_payment_method" value is required.' );
			}
		}
	}

	/**
	 * Store the customer and card IDs on the order and subscriptions in the order
	 *
	 * @param int $order_id
	 * @param string $payment_id
	 */
	protected function save_subscription_meta( $order_id, $payment_id ) {
		// Also store it on the subscriptions being purchased in the order
		foreach ( wcs_get_subscriptions_for_order( $order_id ) as $subscription ) {
			update_post_meta( $this->get_order_id( $subscription ), '_peach_subscription_payment_method', $payment_id );
		}
	}
}
