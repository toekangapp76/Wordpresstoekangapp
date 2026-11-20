<?php
/**
 * Frontend plugin compatibility bootstrap.
 *
 * @since   ??
 * @package Divi
 */

namespace ET\Builder\FrontEnd\PluginCompat;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ET\Builder\FrontEnd\PluginCompat\MemberPress\MemberPress;

// Register MemberPress compatibility after Builder 5 frontend dependencies are initialized.
add_action( 'divi_frontend_initialize', [ MemberPress::class, 'register_hooks' ] );
