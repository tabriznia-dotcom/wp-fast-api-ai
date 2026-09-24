<?php
/**
 * Registry of AI providers.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\AI;

use AIPageDesigner\AI\Contracts\ProviderInterface;
use AIPageDesigner\AI\Providers\OpenAICompatibleProvider;
use AIPageDesigner\AI\Providers\OpenCodeProvider;
use AIPageDesigner\AI\Providers\WPAIClientProvider;
use AIPageDesigner\Core\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Holds provider instances. Third parties register theirs on `aipd_register_providers`.
 */
final class ProviderRegistry {

	/**
	 * Registered providers.
	 *
	 * @var array<string,ProviderInterface>
	 */
	private $providers = array();

	/**
	 * Whether built-ins and extensions were loaded.
	 *
	 * @var bool
	 */
	private $loaded = false;

	/**
	 * Registers a provider.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @return void
	 */
	public function register( ProviderInterface $provider ) {
		$id = sanitize_key( $provider->get_id() );
		if ( '' !== $id ) {
			$this->providers[ $id ] = $provider;
		}
	}

	/**
	 * Removes a provider.
	 *
	 * @param string $id Provider id.
	 * @return void
	 */
	public function unregister( $id ) {
		unset( $this->providers[ $id ] );
	}

	/**
	 * All providers.
	 *
	 * @return array<string,ProviderInterface>
	 */
	public function all() {
		$this->load();
		return $this->providers;
	}

	/**
	 * A provider by id.
	 *
	 * @param string $id Provider id.
	 * @return ProviderInterface|null
	 */
	public function get( $id ) {
		$this->load();
		return isset( $this->providers[ $id ] ) ? $this->providers[ $id ] : null;
	}

	/**
	 * The provider selected in settings, if available.
	 *
	 * @return ProviderInterface|null
	 */
	public function active() {
		$provider = $this->get( (string) Options::get( 'active_provider' ) );
		return ( $provider && $provider->is_available() ) ? $provider : null;
	}

	/**
	 * Loads built-in providers and fires the registration action once.
	 *
	 * @return void
	 */
	private function load() {
		if ( $this->loaded ) {
			return;
		}
		$this->loaded = true;

		$this->register( new OpenAICompatibleProvider() );
		$this->register( new OpenCodeProvider() );
		$this->register( new WPAIClientProvider() );

		/**
		 * Fires when AI providers are registered.
		 *
		 * @since 1.0.0
		 *
		 * @param ProviderRegistry $registry Registry; call ->register( $provider ).
		 */
		do_action( 'aipd_register_providers', $this );
	}
}
