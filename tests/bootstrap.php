<?php
/**
 * The PhpUnit bootstrap file.
 *
 * @package    Mazepress\Core
 * @subpackage Tests
 */

// Load the composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
define( 'GATEWAYPAYPAL_TEST_MODE', true );

// Bootstrap WP_Mock to initialize built-in features.
WP_Mock::bootstrap();
