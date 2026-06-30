<?php
/**
 * Registers and runs all plugin hooks.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Loader {

	private array $actions = array();
	private array $filters = array();

	public function add_action( string $hook, object $component, string $callback, int $priority = 10, int $accepted_args = 1 ) {
		$this->actions[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
	}

	public function add_filter( string $hook, object $component, string $callback, int $priority = 10, int $accepted_args = 1 ) {
		$this->filters[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
	}

	public function run() {
		foreach ( $this->filters as $f ) {
			add_filter( $f['hook'], array( $f['component'], $f['callback'] ), $f['priority'], $f['accepted_args'] );
		}
		foreach ( $this->actions as $a ) {
			add_action( $a['hook'], array( $a['component'], $a['callback'] ), $a['priority'], $a['accepted_args'] );
		}
	}
}
