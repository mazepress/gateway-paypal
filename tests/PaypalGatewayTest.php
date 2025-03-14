<?php
/**
 * The PaypalGatewayTest class file.
 *
 * @package    Mazepress\Gateway\Paypal
 * @subpackage Tests
 */

declare(strict_types=1);

namespace Mazepress\Gateway\Paypal\Tests;

use Mockery;
use Mazepress\Gateway\Transaction;
use Mazepress\Gateway\Paypal\PaypalGateway;
use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\PayPalEnvironment;
use PayPalHttp\HttpException;
use PayPalHttp\HttpResponse;
use WP_Mock;
use WP_Error;

/**
 * The PaypalGatewayTest class.
 */
class PaypalGatewayTest extends WP_Mock\Tools\TestCase {

	/**
	 * Test class properites.
	 *
	 * @return void
	 */
	public function test_properties(): void {

		$object = new PaypalGateway( '', '' );

		$this->assertInstanceOf( PaypalGateway::class, $object->set_public_key( 'public1' ) );
		$this->assertEquals( 'public1', $object->get_public_key() );

		$this->assertInstanceOf( PaypalGateway::class, $object->set_private_key( 'private1' ) );
		$this->assertEquals( 'private1', $object->get_private_key() );

		$order_id = uniqid();
		$this->assertInstanceOf( PaypalGateway::class, $object->set_order_id( $order_id ) );
		$this->assertEquals( $order_id, $object->get_order_id() );

		$this->assertFalse( $object->get_is_live() );
		$this->assertInstanceOf( PaypalGateway::class, $object->set_is_live( true ) );
		$this->assertTrue( $object->get_is_live() );

		$url = 'http://localhost.com/success';
		$this->assertInstanceOf( PaypalGateway::class, $object->set_return_url( $url ) );
		$this->assertEquals( $url, $object->get_return_url() );

		$url = 'http://localhost.com/cancel';
		$this->assertInstanceOf( PaypalGateway::class, $object->set_cancel_url( $url ) );
		$this->assertEquals( $url, $object->get_cancel_url() );
	}

	/**
	 * Test checkout payment.
	 *
	 * @return void
	 */
	public function test_environment(): void {

		$object = new PaypalGateway( '', '' );
		$output = $object->environment();
		$this->assertInstanceOf( WP_Error::class, $output );
		$this->assertEquals( 'invalid_keys', $output->get_error_code() );

		$object->set_public_key( 'public1' )
			->set_private_key( 'private1' )
			->set_is_live( true );

		$output = $object->environment();
		$this->assertInstanceOf( PayPalEnvironment::class, $output );
	}

	/**
	 * Test checkout payment error.
	 *
	 * @return void
	 */
	public function test_checkout_error(): void {

		$object = new PaypalGateway( '', '' );
		$output = $object->checkout();
		$this->assertInstanceOf( WP_Error::class, $output );
		$this->assertEquals( 'invalid_keys', $output->get_error_code() );

		$object->set_public_key( 'public1' )
			->set_private_key( 'private1' );

		$output = $object->checkout();
		$this->assertInstanceOf( WP_Error::class, $output );
		$this->assertEquals( 'invalid_urls', $output->get_error_code() );

		$object->set_return_url( 'http://localhost.com/success' )
			->set_cancel_url( 'http://localhost.com/cancel' );

		$output = $object->checkout();
		$this->assertInstanceOf( WP_Error::class, $output );
		$this->assertEquals( 'invalid_amount', $output->get_error_code() );

		$object->set_amount( 100 );

		$client = Mockery::mock( PayPalHttpClient::class );

		// @phpstan-ignore-next-line
		$client->shouldReceive( 'execute' )
			->once()
			->andThrow( new HttpException( 'Error message', 500, array() ) );

		// @phpstan-ignore-next-line
		$object->set_client( $client );

		$output = $object->checkout();
		$this->assertInstanceOf( WP_Error::class, $output );
		$this->assertEquals( 'broke', $output->get_error_code() );

		$body  = $this->get_checkout_response();
		$links = $body->links;

		// Unset the links.
		$body->links = array();

		// @phpstan-ignore-next-line
		$client->shouldReceive( 'execute' )
			->once()
			->andReturn(
				new HttpResponse( 200, $body, array() )
			);

		// @phpstan-ignore-next-line
		$object->set_client( $client );

		$output = $object->checkout();
		$this->assertInstanceOf( WP_Error::class, $output );
		$this->assertEquals( 'empty_response', $output->get_error_code() );

		foreach ( $links as $key => $link ) {
			if ( 'approve' === $link->rel ) {
				unset( $links[ $key ] );
			}
		}

		// Set the links.
		$body->links = $links;

		// @phpstan-ignore-next-line
		$client->shouldReceive( 'execute' )
			->once()
			->andReturn(
				new HttpResponse( 200, $body, array() )
			);

		// @phpstan-ignore-next-line
		$object->set_client( $client );

		$output = $object->checkout();
		$this->assertInstanceOf( WP_Error::class, $output );
		$this->assertEquals( 'empty_approve', $output->get_error_code() );
	}

	/**
	 * Test checkout payment success.
	 *
	 * @runInSeparateProcess
	 *
	 * @return void
	 */
	public function test_checkout_success(): void {

		$object = new PaypalGateway( 'public1', 'private1' );

		$object->set_return_url( 'http://localhost.com/success' )
			->set_cancel_url( 'http://localhost.com/cancel' );

		$object->set_invoice_id( uniqid() );
		$object->set_amount( 100 );

		$client = Mockery::mock( PayPalHttpClient::class );
		$body   = $this->get_checkout_response();

		// @phpstan-ignore-next-line
		$client->shouldReceive( 'execute' )
			->once()
			->andReturn(
				new HttpResponse( 200, $body, array() )
			);

		// @phpstan-ignore-next-line
		$object->set_client( $client );

		WP_Mock::passthruFunction( 'set_transient' );

		$this->expectOutputRegex( '/.*/' );
		$object->checkout( 'test' );
	}

	/**
	 * Test process payment error.
	 *
	 * @return void
	 */
	public function test_process_error(): void {

		$object = new PaypalGateway( '', '' );
		$output = $object->process();
		$this->assertInstanceOf( WP_Error::class, $output );
		$this->assertEquals( 'invalid_keys', $output->get_error_code() );

		$object->set_public_key( 'public1' )
			->set_private_key( 'private1' );

		$output = $object->process();
		$this->assertInstanceOf( WP_Error::class, $output );
		$this->assertEquals( 'invalid_order_id', $output->get_error_code() );

		$order_id = uniqid();
		$object->set_order_id( $order_id );

		$client = Mockery::mock( PayPalHttpClient::class );

		// @phpstan-ignore-next-line
		$client->shouldReceive( 'execute' )
			->once()
			->andThrow( new HttpException( 'Error message', 500, array() ) );

		// @phpstan-ignore-next-line
		$object->set_client( $client );

		$output = $object->process();
		$this->assertInstanceOf( WP_Error::class, $output );
		$this->assertEquals( 'broke', $output->get_error_code() );
	}

	/**
	 * Test process payment success.
	 *
	 * @runInSeparateProcess
	 *
	 * @return void
	 */
	public function test_process_success(): void {

		$object = new PaypalGateway( 'public1', 'private1' );

		$order_id = uniqid();
		$object->set_order_id( $order_id );

		$client = Mockery::mock( PayPalHttpClient::class );
		$body   = $this->get_process_response();

		// @phpstan-ignore-next-line
		$client->shouldReceive( 'execute' )
			->once()
			->andReturn(
				new HttpResponse( 200, $body, array() )
			);

		// @phpstan-ignore-next-line
		$object->set_client( $client );

		WP_Mock::passthruFunction( 'wp_sprintf' );

		$output = $object->process();
		$this->assertInstanceOf( Transaction::class, $output );
		$this->assertEquals( 'Paid', $output->get_status() );
	}

	/**
	 * Get the HttpResponse body object
	 *
	 * @return \stdClass
	 */
	private function get_checkout_response(): \stdClass {
		return (object) array(
			'id'             => '8GB67279RC051624C',
			'intent'         => 'CAPTURE',
			'status'         => 'CREATED',
			'create_time'    => '2018-08-06T23:34:31Z',
			'purchase_units' => array(
				array(
					'amount' => array(
						'currency_code' => 'USD',
						'value'         => '100.00',
					),
				),
			),
			'links'          => array(
				(object) array(
					'href'   => 'https://api.sandbox.paypal.com/v2/checkout/orders/8GB67279RC051624C',
					'rel'    => 'self',
					'method' => 'GET',
				),
				(object) array(
					'href'   => 'https://www.sandbox.paypal.com/checkoutnow?token=8GB67279RC051624C',
					'rel'    => 'approve',
					'method' => 'GET',
				),
				(object) array(
					'href'   => 'https://api.sandbox.paypal.com/v2/checkout/orders/8GB67279RC051624C/capture',
					'rel'    => 'capture',
					'method' => 'POST',
				),
			),
		);
	}

	/**
	 * Get the HttpResponse body object
	 *
	 * @return \stdClass
	 */
	private function get_process_response(): \stdClass {
		return (object) array(
			'id'          => '8GB67279RC051624C',
			'intent'      => 'CAPTURE',
			'status'      => 'COMPLETED',
			'create_time' => '2018-08-06T23:34:31Z',
			'Payer'       => (object) array(
				'Email_address' => 'test-buyer@paypal.com',
				'Payer_id'      => 'KWADC7LXRRWCE',
			),
			'links'       => array(
				(object) array(
					'href'   => 'https://api.sandbox.paypal.com/v2/checkout/orders/8GB67279RC051624C',
					'rel'    => 'self',
					'method' => 'GET',
				),
			),
		);
	}
}
