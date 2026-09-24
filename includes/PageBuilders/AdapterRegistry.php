<?php
/**
 * Registry of page builder adapters.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\PageBuilders;

use AIPageDesigner\PageBuilders\Classic\ClassicAdapter;
use AIPageDesigner\PageBuilders\Contracts\PageBuilderAdapterInterface;
use AIPageDesigner\PageBuilders\Elementor\ElementorAdapter;
use AIPageDesigner\PageBuilders\Gutenberg\GutenbergAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Holds adapters. Extensions register more on `aipd_register_page_builders`.
 */
final class AdapterRegistry {

	/**
	 * Adapters.
	 *
	 * @var array<string,PageBuilderAdapterInterface>
	 */
	private $adapters = array();

	/**
	 * Loaded flag.
	 *
	 * @var bool
	 */
	private $loaded = false;

	/**
	 * Registers an adapter.
	 *
	 * @param PageBuilderAdapterInterface $adapter Adapter.
	 * @return void
	 */
	public function register( PageBuilderAdapterInterface $adapter ) {
		$id = sanitize_key( $adapter->get_id() );
		if ( '' !== $id ) {
			$this->adapters[ $id ] = $adapter;
		}
	}

	/**
	 * All adapters.
	 *
	 * @return array<string,PageBuilderAdapterInterface>
	 */
	public function all() {
		$this->load();
		return $this->adapters;
	}

	/**
	 * Adapters that can be used right now.
	 *
	 * @return array<string,PageBuilderAdapterInterface>
	 */
	public function available() {
		return array_filter(
			$this->all(),
			function ( PageBuilderAdapterInterface $adapter ) {
				return $adapter->is_available();
			}
		);
	}

	/**
	 * Adapter by id.
	 *
	 * @param string $id Id.
	 * @return PageBuilderAdapterInterface|null
	 */
	public function get( $id ) {
		$this->load();
		return isset( $this->adapters[ $id ] ) ? $this->adapters[ $id ] : null;
	}

	/**
	 * Loads built-ins and extensions.
	 *
	 * @return void
	 */
	private function load() {
		if ( $this->loaded ) {
			return;
		}
		$this->loaded = true;
		$this->register( new GutenbergAdapter() );
		$this->register( new ElementorAdapter() );
		$this->register( new ClassicAdapter() );

		/**
		 * Fires when page builder adapters are registered.
		 *
		 * @since 1.0.0
		 *
		 * @param AdapterRegistry $registry Registry; call ->register( $adapter ).
		 */
		do_action( 'aipd_register_page_builders', $this );
	}
}
