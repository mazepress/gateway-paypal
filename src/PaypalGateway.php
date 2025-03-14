<?php
/**
 * The PaypalGateway class file.
 *
 * @package    Mazepress\Gateway
 * @subpackage Paypal
 */

declare(strict_types=1);

namespace Mazepress\Gateway\Paypal;

use Mazepress\Gateway\Payment;
use Mazepress\Gateway\Transaction;
use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\ProductionEnvironment;
use PayPalCheckoutSdk\Core\SandboxEnvironment;
use PayPalCheckoutSdk\Core\PayPalEnvironment;
use PayPalCheckoutSdk\Orders\OrdersCaptureRequest;
use PayPalCheckoutSdk\Orders\OrdersCreateRequest;
use PayPalHttp\HttpException;
use WP_Error;

/**
 * The PaypalGateway abstract class.
 */
class PaypalGateway extends Payment {

	/**
	 * The public_key.
	 *
	 * @var string $public_key
	 */
	private $public_key;

	/**
	 * The private_key.
	 *
	 * @var string $private_key
	 */
	private $private_key;

	/**
	 * The live mode flag.
	 *
	 * @var bool $is_live
	 */
	private $is_live = false;

	/**
	 * Return URL.
	 *
	 * @var string $return_url
	 */
	private $return_url;

	/**
	 * Cancel URL.
	 *
	 * @var string $cancel_url
	 */
	private $cancel_url;

	/**
	 * The order ID.
	 *
	 * @var string $order_id
	 */
	private $order_id;

	/**
	 * The PayPalHttpClient client object.
	 *
	 * @var PayPalHttpClient $client
	 */
	private $client;

	/**
	 * Initiate class.
	 *
	 * @param string $public_key  The public key.
	 * @param string $private_key The private key.
	 * @param bool   $live        Live mode.
	 */
	public function __construct( string $public_key, string $private_key, bool $live = false ) {
		$this->set_public_key( $public_key );
		$this->set_private_key( $private_key );
		$this->set_is_live( $live );
	}

	/**
	 * Get the environment.
	 *
	 * @return PayPalEnvironment|WP_Error
	 */
	public function environment() {

		// Check the public and private keys.
		if ( empty( $this->get_public_key() ) || empty( $this->get_private_key() ) ) {
			return new WP_Error( 'invalid_keys', __( 'Invalid public or private keys.', 'gatewaypaypal' ) );
		}

		if ( $this->get_is_live() ) {
			$environment = new ProductionEnvironment( $this->get_public_key(), $this->get_private_key() );
		} else {
			$environment = new SandboxEnvironment( $this->get_public_key(), $this->get_private_key() );
		}

		return $environment;
	}

	/**
	 * Checkout the form.
	 *
	 * @param string $form The form name.
	 *
	 * @return void|WP_Error
	 */
	public function checkout( string $form = '' ) {

		$environment = $this->environment();

		if ( is_wp_error( $environment ) ) {
			return $environment;
		}

		// Check the return urls.
		if ( empty( $this->get_return_url() ) || empty( $this->get_cancel_url() ) ) {
			return new WP_Error( 'invalid_urls', __( 'Invalid return key.', 'gatewaypaypal' ) );
		}

		// Check the amount.
		$amount = $this->get_amount();
		if ( $amount <= 0 ) {
			return new WP_Error( 'invalid_amount', __( 'Invalid amount.', 'gatewaypaypal' ) );
		}

		$args = array(
			'intent'              => 'CAPTURE',
			'application_context' => array(
				'cancel_url' => (string) $this->get_cancel_url(),
				'return_url' => (string) $this->get_return_url(),
			),
			'purchase_units'      => array(
				array(
					'reference_id' => (string) $this->get_invoice_id(),
					'amount'       => array(
						'value'         => $amount,
						'currency_code' => $this->get_currency(),
					),
				),
			),
		);

		try {
			$request = new OrdersCreateRequest();
			$request->prefer( 'return=representation' );
			$request->body = $args;

			// Create a new PayPalHttpClient.
			$client   = ! is_null( $this->get_client() ) ? $this->get_client() : new PayPalHttpClient( $environment );
			$response = $client->execute( $request );

			if (
				empty( $response->result->status )
				|| 'CREATED' !== $response->result->status
				|| empty( $response->result->id )
				|| empty( $response->result->links )
				|| ! is_array( $response->result->links )
			) {
				return new WP_Error(
					'empty_response',
					__( ' Failed processing paypal response data!', 'gatewaypaypal' )
				);
			}

			$approve_url = '';

			foreach ( $response->result->links as $key => $value ) {
				if ( ! empty( $value->rel ) && 'approve' === $value->rel && ! empty( $value->href ) ) {
					$approve_url = $value->href;
				}
			}

			if ( empty( $approve_url ) ) {
				return new WP_Error(
					'empty_approve',
					__( ' Failed processing paypal response url!', 'gatewaypaypal' )
				);
			}

			$invoice_id = $this->get_invoice_id();
			if ( ! empty( $invoice_id ) && ! empty( $form ) ) {
				$transdata = array(
					'form'    => $form,
					'amount'  => $amount,
					'orderid' => $response->result->id,
				);
				set_transient( $invoice_id, $transdata, 600 );
			}

			header( 'HTTP/1.1 303 See Other' );
			header( 'Location: ' . $approve_url );

			if ( ! defined( 'GATEWAYPAYPAL_TEST_MODE' ) ) {
				exit();
			}
		} catch ( HttpException $ex ) {
			return new WP_Error( 'broke', $ex->getMessage() );
		}
	}

	/**
	 * Process the payment. If the payment fails,
	 * it should return a WP_Error object.
	 *
	 * @return Transaction|WP_Error
	 */
	public function process() {

		$environment = $this->environment();

		if ( is_wp_error( $environment ) ) {
			return $environment;
		}

		// Check the order ID.
		if ( empty( $this->get_order_id() ) ) {
			return new WP_Error( 'invalid_order_id', __( 'Invalid Order ID.', 'gatewaypaypal' ) );
		}

		$transaction = ( new Transaction() )
			->set_status( 'Pending' )
			->set_reference_id( $this->get_order_id() );

		try {
			$request = new OrdersCaptureRequest( $this->get_order_id() );
			$request->prefer( 'return=representation' );

			// Create a new PayPalHttpClient.
			$client   = ! is_null( $this->get_client() ) ? $this->get_client() : new PayPalHttpClient( $environment );
			$response = $client->execute( $request );
			$status   = 'PENDING';

			if ( ! empty( $response->result->status ) ) {
				$status = $response->result->status;
				if ( 'COMPLETED' === $response->result->status ) {
					$transaction->set_status( 'Paid' );
				}
			}

			$transaction->set_message(
				wp_sprintf(
					/* translators: {%1$s} The transaction status */
					__( ' Transaction is %1$s', 'gatewaypaypal' ),
					$status
				)
			);

			$invoice_id = $this->get_invoice_id();
			if ( ! empty( $invoice_id ) ) {
				delete_transient( $invoice_id );
			}
		} catch ( HttpException $ex ) {
			return new WP_Error( 'broke', $ex->getMessage() );
		}

		return $transaction;
	}

	/**
	 * Get the public key.
	 *
	 * @return string|null
	 */
	public function get_public_key(): ?string {
		return $this->public_key;
	}

	/**
	 * Set the public key.
	 *
	 * @param string $public_key The public key.
	 *
	 * @return self
	 */
	public function set_public_key( string $public_key ): self {
		$this->public_key = $public_key;
		return $this;
	}

	/**
	 * Get the private key.
	 *
	 * @return string|null
	 */
	public function get_private_key(): ?string {
		return $this->private_key;
	}

	/**
	 * Set the private key.
	 *
	 * @param string $private_key The private key.
	 *
	 * @return self
	 */
	public function set_private_key( string $private_key ): self {
		$this->private_key = $private_key;
		return $this;
	}

	/**
	 * Get the return url.
	 *
	 * @return string|null
	 */
	public function get_return_url(): ?string {
		return $this->return_url;
	}

	/**
	 * Set the return url.
	 *
	 * @param string $return_url The return url.
	 *
	 * @return self
	 */
	public function set_return_url( string $return_url ): self {
		$this->return_url = $return_url;
		return $this;
	}

	/**
	 * Get the cancel url.
	 *
	 * @return string|null
	 */
	public function get_cancel_url(): ?string {
		return $this->cancel_url;
	}

	/**
	 * Set the cancel url.
	 *
	 * @param string $cancel_url The cancel url.
	 *
	 * @return self
	 */
	public function set_cancel_url( string $cancel_url ): self {
		$this->cancel_url = $cancel_url;
		return $this;
	}

	/**
	 * Get the live mode.
	 *
	 * @return bool
	 */
	public function get_is_live(): bool {
		return $this->is_live;
	}

	/**
	 * Set the live mode.
	 *
	 * @param bool $live The live mode.
	 *
	 * @return self
	 */
	public function set_is_live( bool $live ): self {
		$this->is_live = $live;
		return $this;
	}

	/**
	 * Get the order ID.
	 *
	 * @return string|null
	 */
	public function get_order_id(): ?string {
		return $this->order_id;
	}

	/**
	 * Set the order ID.
	 *
	 * @param string $order_id The order ID.
	 *
	 * @return self
	 */
	public function set_order_id( string $order_id ): self {
		$this->order_id = $order_id;
		return $this;
	}

	/**
	 * Get the client.
	 *
	 * @return PayPalHttpClient|null
	 */
	public function get_client(): ?PayPalHttpClient {
		return $this->client;
	}

	/**
	 * Set the client.
	 *
	 * @param PayPalHttpClient $client The client.
	 *
	 * @return self
	 */
	public function set_client( PayPalHttpClient $client ): self {
		$this->client = $client;
		return $this;
	}
}
