<?php
/**
 * Instagram token admin bootstrap.
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\Admin\InstagramToken;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

use ET\Builder\Framework\DependencyManagement\Interfaces\DependencyInterface;

/**
 * Registers Instagram token admin notices.
 *
 * @since ??
 */
class InstagramTokenAdmin implements DependencyInterface {

	/**
	 * Load admin hooks.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	public function load(): void {
		add_action( 'admin_notices', [ InstagramTokenAdminNotices::class, 'render_admin_notices' ] );
		add_action( 'admin_enqueue_scripts', [ InstagramTokenAdminNotices::class, 'enqueue_admin_scripts' ] );
	}
}
