<?php
/**
 * Peach Payments Gateway
 *
 * Provides an Peach Payments WPF Gateway
 *
 * @class      WC_Peach_Payments
 * @extends    WC_Payment_Gateway
 * @package    WC_Peach_Payments
 * @author     LightSpeed
 */

class WC_Peach_Payments extends WC_Payment_Gateway {

	/**
	 * Holds the current payment
	 */
	public $payment = '';

	/**
	 * Hold the Gateway URLs for Peach
	 */
	protected $gateway_url    = '';
	protected $query_url      = '';
	protected $post_query_url = '';

	/**
	 * Is the gateway set to live or dev.
	 */
	protected $transaction_mode = '';

	/**
	 * Peach Payments Sender ID
	 */
	protected $sender = '';

	/**
	 * Peach Payments Username
	 */
	protected $username = '';

	/**
	 * Peach Payments Password
	 */
	protected $password = '';

	/**
	 * Peach Payments Channel
	 */
	public $channel = '';

	/**
	 * Store the credit cards
	 */
	public $card_storage = 'no';

	/**
	 * The Credit Cars available
	 */
	public $cards = array();

	/**
	 * Holds the currencies this gateway can use
	 */
	public $available_currencies = array();

	/**
	 * Hold a the base request before it is sent out.
	 */
	protected $base_request = '';

	/**
	 * Hold the get_token_status response, used if the subscriptions plugin is active.
	 */
	protected $token_response = false;

	/**
	 * If 3DS is active
	 */
	protected $channel_3ds = false;

	/**
	 * If the debug is active and we need to log the info
	 */
	public $debug = false;

	/** @var bool Whether or not logging is enabled */
	public static $log_enabled = false;
	/** @var WC_Logger Logger instance */
	public static $log = false;

	/**
	 * Constructor for the gateway.
	 *
	 * @access public
	 */
	public function __construct() {
		$this->id           = 'peach-payments';
		$this->method_title = __( 'Peach Payments', 'woocommerce-gateway-peach-payments' );
		$this->icon         = '';
		$this->has_fields   = true;
		$this->supports     = array(
			'subscriptions',
			'products',
			'subscription_cancellation',
			'subscription_reactivation',
			'subscription_suspension',
			'subscription_amount_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_admin',
			'subscription_date_changes',
			'multiple_subscriptions',
		);

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		$this->available_currencies = array( 'ZAR' );

		// Load the form fields.
		$this->init_form_fields();

		$this->order_button_text = __( 'Proceed to payment', 'woocommerce-gateway-peach-payment' );

		// Load the settings.
		$this->init_settings();

		// Get setting values
		foreach ( $this->settings as $key => $val )
		$this->$key = $val;

		// Switch the Gateway to the Live url if it is set to live.
		if ( 'LIVE' == $this->transaction_mode ) {
			$this->gateway_url    = 'https://ctpe.net/frontend/';
			$this->query_url      = 'https://ctpe.io/payment/ctpe';
			$this->post_query_url = 'https://ctpe.net/frontend/payment.prc';
		} else {
			$this->gateway_url    = 'https://test.ctpe.net/frontend/';
			$this->query_url      = 'https://test.ctpe.io/payment/ctpe';
			$this->post_query_url = 'https://test.ctpe.net/frontend/payment.prc';
		}

		//set the debug to a boolean
		self::$log_enabled = $this->debug;

		$this->base_request = array(
			'REQUEST.VERSION'      => '1.0',
			'SECURITY.SENDER'      => $this->sender,
			'USER.LOGIN'           => $this->username,
			'USER.PWD'             => $this->password,

			'TRANSACTION.MODE'     => $this->transaction_mode,
			'TRANSACTION.RESPONSE' => 'SYNC',
			'TRANSACTION.CHANNEL'  => $this->channel,
		);

		// Hooks
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );  // WC >= 2.0
		add_action( 'admin_notices', array( $this, 'ecommerce_ssl_check' ) );

		// Add Copy and Pay form to receipt_page
		add_action( 'woocommerce_receipt_peach-payments', array( $this, 'receipt_page' ) );

		// API Handler
		add_action( 'woocommerce_api_wc_peach_payments', array( $this, 'process_payment_status' ) );

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
			'enabled'          => array(
				'title'       => __( 'Enable/Disable', 'woocommerce-gateway-peach-payments' ),
				'label'       => __( 'Enable Peach Payments', 'woocommerce-gateway-peach-payments' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),

			'card_storage'     => array(
				'title'       => __( 'Card Storage', 'woocommerce-gateway-peach-payments' ),
				'label'       => __( 'Enable Card Storage', 'woocommerce-gateway-peach-payments' ),
				'type'        => 'checkbox',
				'description' => __( 'Allow customers to store cards against their account. Required for subscriptions.', 'woocommerce-gateway-peach-payments' ),
				'default'     => 'yes',
			),

			'title'            => array(
				'title'       => __( 'Title', 'woocommerce-gateway-peach-payments' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-gateway-peach-payments' ),
				'default'     => __( 'Credit Card', 'woocommerce-gateway-peach-payments' ),
			),

			'description'      => array(
				'title'       => __( 'Description', 'woocommerce-gateway-peach-payments' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-gateway-peach-payments' ),
				'default'     => 'Pay with your credit card via Peach Payments.',
			),

			'cards'            => array(
				'title'       => __( 'Supported Cards', 'woocommerce-gateway-peach-payments' ),
				'type'        => 'multiselect',
				'description' => __( 'Choose the cards you wish to accept.', 'woocommerce-gateway-peach-payments' ),
				'options'     => array(
					'VISA'   => 'VISA',
					'MASTER' => 'Master Card',
					'AMEX'   => 'American Express',
					'DINERS' => 'Diners Club',
				),
				'default'     => array( 'VISA', 'MASTER' ),
				'class'       => 'chosen_select',
				'css'         => 'width: 450px;',
			),

			'username'         => array(
				'title'       => __( 'User Login', 'woocommerce-gateway-peach-payments' ),
				'type'        => 'text',
				'description' => __( 'This is the API username generated within the Peach Payments Commerce gateway.', 'woocommerce-gateway-peach-payments' ),
				'default'     => '',
			),

			'password'         => array(
				'title'       => __( 'User Password', 'woocommerce-gateway-peach-payments' ),
				'type'        => 'password',
				'description' => __( 'This is the API user password generated within the Peach Payments Commerce gateway.', 'woocommerce-gateway-peach-payments' ),
				'default'     => '',
			),

			'sender'           => array(
				'title'       => __( 'Sender ID', 'woocommerce-gateway-peach-payments' ),
				'type'        => 'text',
				'description' => __( 'Sender ID', 'woocommerce-gateway-peach-payments' ),
				'default'     => '',
			),

			'channel'          => array(
				'title'       => __( 'Channel ID', 'woocommerce-gateway-peach-payments' ),
				'type'        => 'text',
				'description' => __( 'Channel ID', 'woocommerce-gateway-peach-payments' ),
				'default'     => '',
			),

			'channel_3ds'      => array(
				'title'       => __( '3DSecure Channel ID', 'woocommerce-gateway-peach-payments' ),
				'type'        => 'text',
				'description' => __( 'If there is no specific 3DSecure Channel ID then use Channel ID here again.', 'woocommerce-gateway-peach-payments' ),
				'default'     => '',
			),

			'transaction_mode' => array(
				'title'       => __( 'Transaction Mode', 'woocommerce-gateway-peach-payments' ),
				'type'        => 'select',
				'description' => __( 'Set your gateway to live when you are ready.', 'woocommerce-gateway-peach-payments' ),
				'default'     => 'INTEGRATOR_TEST',
				'options'     => array(
					'INTEGRATOR_TEST' => 'Integrator Test',
					'CONNECTOR_TEST'  => 'Connector Test',
					'LIVE'            => 'Live',
				),
			),
			'debug'            => array(
				'title'       => __( 'Debug', 'woocommerce-gateway-peach-payments' ),
				'label'       => __( 'On / Off', 'woocommerce-gateway-peach-payments' ),
				'type'        => 'checkbox',
				'description' => __( 'Records the specifics of the transactions to the the WC Log', 'woocommerce-gateway-peach-payments' ),
				'default'     => 'no',
			),
		);
	}


	/**
	 * Register and enqueue specific JavaScript.
	 *
	 * @access public
	 * @return    void    Return early if no settings page is registered.
	 */
	public function enqueue_scripts() {

		if ( is_checkout_pay_page() && ! isset( $_GET['registered_payment'] ) ) {
			$order_id = get_query_var( 'order-pay', 'false' );
			if ( false !== $order_id ) {
				$payment_token = get_post_meta( $order_id, '_peach_payment_token', true );
				if ( false !== $payment_token ) {
					wp_enqueue_script( 'peach-payments-widget-js', $this->gateway_url . 'widget/v4/widget.js;jsessionid=' . $payment_token . '?language=en&style=none' );
				}
			}
			wp_enqueue_style( 'peach-payments-widget-css', plugins_url( 'assets/css/cc-form.css', dirname( __FILE__ ) ) );
		}

	}

	/**
	 * Check if SSL is enabled.
	 *
	 * @access public
	 * @return void
	 */
	function ecommerce_ssl_check() {
		if ( get_option( 'woocommerce_force_ssl_checkout' ) == 'no' && 'yes' == $this->enabled ) {
			echo '<div class="error"><p>We have detected that you currently don\'t have SSL enabled. Peach Payments recommends using SSL on your website. Please enable SSL and ensure your server has a valid SSL certificate.</p></div>';
		}
	}

	/**
	 * Logging method.
	 * @param string $message
	 */
	public static function log( $message ) {
		if ( self::$log_enabled ) {
			if ( empty( self::$log ) ) {
				self::$log = new WC_Logger();
			}
			self::$log->add( 'woocommerce-gateway-peach-payments', $message );
		}
	}


	/**
	 * Grab the ID from the WC Order Object, handles 2.5 -> 3.0 compatibility
	 *
	 * @param object $order WC_Order
	 * @return string post_id
	 */
	public function get_order_id( $order ) {
		$return = 0;
		if ( is_object( $order ) ) {
			if ( defined( 'WC_VERSION' ) && WC_VERSION >= 2.6 ) {
				$return = $order->get_id();
			} else {
				$return = $order->id;
			}
		}
		return $return;
	}

	/**
	 * Grab the Customer ID from the WC Order Object, handles 2.5 -> 3.0 compatibility
	 *
	 * @param object $order WC_Order
	 * @return string user_id
	 */
	public function get_customer_id( $order ) {
		$return = 0;
		if ( is_object( $order ) ) {
			if ( defined( 'WC_VERSION' ) && WC_VERSION >= 2.6 ) {
				$return = $order->get_customer_id();
			} else {
				$return = $order->user_id;
			}
		}
		return $return;
	}

	/**
	 * Grab the product from the $item WC Order Object, handles 3.0 compatibility
	 *
	 * @param object $item
	 * @param object $order WC_Order
	 * @return string user_id
	 */
	public function get_item_product( $item = false, $order = false ) {
		$return = 0;
		if ( false !== $item ) {
			if ( defined( 'WC_VERSION' ) && WC_VERSION >= 3.0 ) {
				$return = $item->get_product();
			} else {
				$return = $order->get_product_from_item( $item );
			}
		}
		return $return;
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
							<label style="display:inline;" for="peach_card_<?php echo esc_attr( $i ); ?>"><?php echo esc_html( get_card_brand_image( $credit_card['brand'] ) ); ?> <?php echo '**** **** **** ' . esc_attr( $credit_card['active_card'] ); ?> (<?php echo esc_attr( $credit_card['exp_month'] ) . '/' . esc_attr( $credit_card['exp_year'] ); ?>)</label><br />
						<?php endforeach; ?>

						<br /> <a class="button" style="float:right;" href="<?php echo esc_url( get_permalink( get_option( 'woocommerce_myaccount_page_id' ) ) ); ?>#saved-cards"><?php esc_html_e( 'Manage cards', 'woocommerce-gateway-peach-payments' ); ?></a>

					<?php endif; ?>

					<input type="radio" id="saveinfo" name="peach_payment_id" style="width:auto;" value="saveinfo"/> <label style="display:inline;" for="saveinfo"><?php esc_html_e( 'Use a new credit card and store method for future use.', 'woocommerce-gateway-peach-payments' ); ?></label><br />
					<input type="radio" id="dontsave" name="peach_payment_id" style="width:auto;" value="dontsave"/> <label style="display:inline;" for="dontsave"><?php esc_html_e( 'Use a new credit card without storing.', 'woocommerce-gateway-peach-payments' ); ?></label>

				</p>
				<div class="clear"></div>

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
		<h3><?php esc_html_e( 'Peach Payments', 'woocommerce-gateway-peach-payments' ); ?></h3>

		<?php if ( 'ZAR' == get_option( 'woocommerce_currency' ) ) { ?>
			<table class="form-table">
			<?php
				// Generate the HTML For the settings form.
				$this->generate_settings_html();
			?>
			</table><!--/.form-table-->
		<?php } else { ?>
			<div class="inline error"><p><strong><?php esc_html_e( 'Gateway Disabled', 'woocommerce-gateway-peach-payments' ); ?></strong> <?php /* translators: %s: store currency */ echo sprintf( esc_html_e( 'Choose South African Rands as your store currency in <a href="%s">General Options</a> to enable the Peach Payments Gateway.', 'woocommerce-gateway-peach-payments' ), esc_url( admin_url( '?page=woocommerce_settings&tab=general' ) ) ); ?></p></div>
		<?php
}
	}

	/**
	 * Process the payment and return the result
	 *
	 * @access public
	 * @param int $order_id
	 * @return array
	 */
	function process_payment( $order_id ) {

		$order = wc_get_order( $order_id );

		try {
			if ( isset( $_POST['peach_payment_id'] ) && wp_verify_nonce( $_POST['peach_payment_id'] ) && ctype_digit( $_POST['peach_payment_id'] ) ) {

				$payment_ids = get_user_meta( $this->get_customer_id( $order ), '_peach_payment_id', false );
				$payment_id  = $payment_ids[ $_POST['peach_payment_id'] ]['payment_id'];

				//throw exception if payment method does not exist
				if ( ! isset( $payment_ids[ $_POST['peach_payment_id'] ]['payment_id'] ) ) {
					throw new Exception( __( 'Invalid Payment Method', 'woocommerce-gateway-peach-payments' ) );
				}

				$redirect_url = $this->execute_post_payment_request( $order, $order->get_total(), $payment_id );

				//throw exception if payment is not accepted
				if ( is_wp_error( $redirect_url ) ) {
					throw new Exception( $redirect_url->get_error_message() );
				}

				return array(
					'result'   => 'success',
					'redirect' => $redirect_url,
				);
			} else {

				$order_request = array(
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
				);

				if ( 'saveinfo' == $_POST['peach_payment_id'] ) {
					$payment_request = array(
						'PAYMENT.TYPE'    => 'RG',
						'RECURRENCE.MODE' => 'INITIAL',
					);

					if ( 'CONNECTOR_TEST' == $this->transaction_mode || 'LIVE' ) {
						$payment_request['CRITERION.presentation.currency3D'] = 'ZAR';
						$payment_request['CRITERION.presentation.amount3D']   = $order->get_total();
					}

					if ( 'CONNECTOR_TEST' == $this->transaction_mode ) {
						$payment_request['CRITERION.USE_3D_SIMULATOR'] = 'false';
					}
				} else {
					$payment_request = array(
						'PAYMENT.TYPE'          => 'DB',
						'PRESENTATION.USAGE'    => 'Order ' . $order->get_order_number(),
						'PRESENTATION.AMOUNT'   => $order->get_total(),
						'PRESENTATION.CURRENCY' => 'ZAR',
					);

					if ( 'CONNECTOR_TEST' == $this->transaction_mode ) {
						$payment_request['CRITERION.USE_3D_SIMULATOR'] = 'false';
					}
				}

				$order_request = array_merge( $order_request, $this->base_request );
				$request       = array_merge( $payment_request, $order_request );

				//use 3dSecure
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
			$error_message = __( 'Error:', 'woocommerce-gateway-peach-payments' ) . ' "' . $e->getMessage() . '"';
			wc_add_notice( $error_message, 'error' );
			$this->log( $error_message );
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

		if ( isset( $_GET['registered_payment'] ) && wp_verify_nonce( $_POST['registered_payment'] ) ) {
			$status = $_GET['registered_payment'];
			$this->process_registered_payment_status( $order_id, $status );
		} else {
			echo wp_kses_post( $this->generate_peach_payments_form( $order_id ) );
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
		$order = wc_get_order( $order_id );

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

		$token = $_GET['token'];

		$parsed_response = $this->get_token_status( $token );
		$order_id        = $parsed_response->transaction->identification->transactionid;
		$order           = wc_get_order( $order_id );

		if ( false !== $order ) {
			$current_order_status = $order->get_status();

			if ( 'complete' !== $current_order_status && 'processing' !== $current_order_status ) {

				if ( is_wp_error( $parsed_response ) ) {
					$error_message = __( 'Payment Failed: Couldn\'t connect to gateway server - Peach Payments', 'woocommerce-gateway-peach-payments' );
					$order->update_status( 'failed', $error_message );
					$this->log( $error_message . ' ' . print_r( $parsed_response, true ) );
					wp_safe_redirect( $this->get_return_url( $order ) );
					exit;
				}

				//handle failed registration
				if ( 'CC.RG' == $parsed_response->transaction->payment->code && 'NOK' == $parsed_response->transaction->processing->result ) {
					$error_message = __( 'Registration Failed: Card registration was not accepted - Peach Payments', 'woocommerce-gateway-peach-payments' );
					$order->update_status( 'failed', $error_message );
					$this->log( $error_message . ' ' . print_r( $parsed_response, true ) );
					wp_safe_redirect( $this->get_return_url( $order ) );
					exit;
				}

				//If you choose
				if ( 'CC.RG' == $parsed_response->transaction->payment->code ) {

					$this->add_customer( $parsed_response );

					$initial_payment = $order->get_total( $order );
					$payment_id      = $parsed_response->transaction->identification->uniqueId;

					$response = $this->execute_post_payment_request( $order, $initial_payment, $payment_id );

					if ( is_wp_error( $response ) ) {
						$order->update_status( 'failed', __( 'Payment Failed: Couldn\'t connect to gateway server - Peach Payments', 'woocommerce-gateway-peach-payments' ) );
						$this->log( print_r( $response, true ) );
						wp_safe_redirect( $this->get_return_url( $order ) );
						exit;
					}

					$redirect_url = $this->get_return_url( $order );

					if ( 'NOK' == $response['PROCESSING.RESULT'] ) {
						/* translators: %s: Payment Failed */
						$order->update_status( 'failed', sprintf( __( 'Payment Failed: Payment Response is "%s" - Peach Payments.', 'woocommerce-gateway-peach-payments' ), wc_clean( $response['PROCESSING.RETURN'] ) ) );
						$this->log( wc_clean( $response['PROCESSING.RETURN'] ) );
						$redirect_url = add_query_arg( 'registered_payment', 'NOK', $redirect_url );
					} elseif ( 'ACK' == $response['PROCESSING.RESULT'] ) {
						$order->payment_complete();
						/* translators: %s: Payment Completed */
						$order->add_order_note( sprintf( __( 'Payment Completed: Payment Response is "%s" - Peach Payments.', 'woocommerce-gateway-peach-payments' ), wc_clean( $response['PROCESSING.RETURN'] ) ) );
						$redirect_url = add_query_arg( 'registered_payment', 'ACK', $redirect_url );
					}
					wp_safe_redirect( $redirect_url );
					exit;
				}

				//If you are using a Stored card,  or not storing a card at all this will process the completion of the order.
				if ( 'CC.DB' == $parsed_response->transaction->payment->code ) {

					if ( 'ACK' == $parsed_response->transaction->processing->result ) {
						$order->payment_complete();
						wp_safe_redirect( $this->get_return_url( $order ) );
						exit;
					} elseif ( 'NOK' == $parsed_response->transaction->processing->result ) {
						$order->update_status( 'failed' );
						$this->log( wc_clean( $parsed_response->transaction->processing->return->message ) );
						wp_safe_redirect( $this->get_return_url( $order ) );
						exit;
					}
				}

				//This is jsut incase there are any errors that we have not handled yet.
				$error_message_index = 'errorMessage';
				if ( ! empty( $parsed_response->$error_message_index ) ) {
					$this->log( $parsed_response->$error_message_index );
					if ( false !== $order ) {
						/* translators: %s: Payment Failed */
						$order->update_status( 'failed', sprintf( __( 'Payment Failed: Payment Response is "%s" - Peach Payments.', 'woocommerce-gateway-peach-payments' ), $parsed_response->$error_message_index ) );
					}
					wp_safe_redirect( $this->get_return_url( $order ) );
					exit;

				} elseif ( 'NOK' == $parsed_response->transaction->processing->result ) {
					/* translators: %s: search term */
					$order->update_status( 'failed', sprintf( __( 'Payment Failed: Payment Response is "%s" - Peach Payments.', 'woocommerce-gateway-peach-payments' ), $parsed_response->transaction->processing->return->message ) );
					$this->log( print_r( $parsed_response->transaction->processing->return, true ) );
					wp_safe_redirect( $this->get_return_url( $order ) );
					exit;
				}
			}
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

		$order = wc_get_order( $order_id );

		if ( 'NOK' == $status ) {
			$order->update_status( 'failed' );
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		} elseif ( 'ACK' == $status ) {
			$order->payment_complete();
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}
	}

	/**
	 * Generate token for Copy and Pay API
	 *
	 * @access public
	 * @param array $request
	 * @return object
	 */
	function generate_token( $request ) {
		global $woocommerce;

		$response = wp_remote_post( $this->gateway_url . 'GenerateToken', array(
			'method'     => 'POST',
			'body'       => $request,
			'timeout'    => 70,
			'sslverify'  => false,
			'user-agent' => 'WooCommerce ' . $woocommerce->version,
		));

		if ( is_wp_error( $response ) )
			return new WP_Error( 'peach_error', __( 'There was a problem connecting to the payment gateway.', 'woocommerce-gateway-peach-payments' ) );

		if ( empty( $response['body'] ) )
			return new WP_Error( 'peach_error', __( 'Empty response.', 'woocommerce-gateway-peach-payments' ) );

		$parsed_response = json_decode( $response['body'] );

		// Handle response
		if ( ! empty( $parsed_response->error ) ) {

			return new WP_Error( 'peach_error', $parsed_response->error->message );

		} else {
			update_post_meta( $request['IDENTIFICATION.TRANSACTIONID'], '_peach_payment_token', $parsed_response->transaction->token );
		}
		$this->log( 'Generated Token ' . $parsed_response->transaction->token );

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

		//Prevent 2 token requests from going out if the subscriptions plugin is active.
		if ( false === $this->token_response ) {

			$url = $this->gateway_url . 'GetStatus;jsessionid=' . $token;

			$response = wp_remote_post($url, array(
				'method'     => 'POST',
				'timeout'    => 70,
				'sslverify'  => false,
				'user-agent' => 'WooCommerce ' . $woocommerce->version,
			));

			$this->token_response = $response;

		} else {
			$response = $this->token_response;
		}

		if ( is_wp_error( $response ) ) {
			$this->log( __( 'There was a problem connecting to the payment gateway.', 'woocommerce-gateway-peach-payments' ) . ' ' . print_r( $response, true ) );
			return new WP_Error( 'peach_error', __( 'There was a problem connecting to the payment gateway.', 'woocommerce-gateway-peach-payments' ) );
		}

		if ( empty( $response['body'] ) ) {
			$this->log( __( 'Empty response.', 'woocommerce-gateway-peach-payments' ) . ' ' . print_r( $response, true ) );
			return new WP_Error( 'peach_error', __( 'Empty response.', 'woocommerce-gateway-peach-payments' ) );
		}

		$parsed_response = json_decode( $response['body'] );
		$this->log( 'Token Status Check ' . $parsed_response->transaction->processing->return->message );
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
			'PAYMENT.CODE'                 => 'CC.DB',

			'IDENTIFICATION.TRANSACTIONID' => $this->get_order_id( $order ),
			'IDENTIFICATION.SHOPPERID'     => $this->get_customer_id( $order ),

			'PRESENTATION.USAGE'           => 'Order #' . $this->get_order_id( $order ),
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
			$this->log( __( 'There was a problem connecting to the payment gateway.', 'woocommerce-gateway-peach-payments' ) . ' ' . print_r( $response, true ) );
			return new WP_Error( 'peach_error', __( 'There was a problem connecting to the payment gateway.', 'woocommerce-gateway-peach-payments' ) );
		}

		if ( empty( $response['body'] ) ) {
			$this->log( __( 'Empty response.', 'woocommerce-gateway-peach-payments' ) . ' ' . print_r( $response, true ) );
			return new WP_Error( 'peach_error', __( 'Empty response.', 'woocommerce-gateway-peach-payments' ) );
		}

		// Convert response string to array
		$vars = explode( '&', $response['body'] );
		foreach ( $vars as $key => $val ) {
			$var             = explode( '=', $val );
			$data[ $var[0] ] = $var[1];
		}

		//create redirect link
		$redirect_url = $this->get_return_url( $order );

		if ( isset( $data['PROCESSING.RESULT'] ) ) {
			if ( 'NOK' == $data['PROCESSING.RESULT'] ) {
				$this->log( wc_clean( $data['PROCESSING.RETURN'] ) );
				/* translators: %s: Payment Response */
				$order->update_status( 'failed', sprintf( __( 'Payment Failed: Payment Response is "%s" - Peach Payments.', 'woocommerce-gateway-peach-payments' ), wc_clean( $data['PROCESSING.RETURN'] ) ) );
				return add_query_arg( 'registered_payment', 'NOK', $redirect_url );

			} elseif ( 'ACK' == $data['PROCESSING.RESULT'] ) {
				$this->log( wc_clean( $data['PROCESSING.RETURN'] ) );
				$order->payment_complete();
				/* translators: %s: Payment Response */
				$order->add_order_note( sprintf( __( 'Payment Completed: Payment Response is "%s" - Peach Payments.', 'woocommerce-gateway-peach-payments' ), wc_clean( $data['PROCESSING.RETURN'] ) ) );
				return add_query_arg( 'registered_payment', 'ACK', $redirect_url );
			}
		} else {
			$order->update_status( 'failed', __( 'Payment Failed: The was an error contacting the API.', 'woocommerce-gateway-peach-payments' ) );
			return add_query_arg( 'registered_payment', 'NOK', $redirect_url );
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
				'payment_id'  => $response->transaction->identification->uniqueId,
				'active_card' => $response->transaction->account->last4Digits,
				'brand'       => $response->transaction->account->brand,
				'exp_year'    => $response->transaction->account->expiry->year,
				'exp_month'   => $response->transaction->account->expiry->month,
			) );

	}
}
