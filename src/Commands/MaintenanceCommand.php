<?php

namespace Artem328\WP_CLI_Maintenance\Commands;

use WP_CLI;
use WP_CLI_Command;

/**
 * Controls maintenance mode
 */
class MaintenanceCommand extends WP_CLI_Command {

	const VERSION = '1.0.0';

	/**
	 * Default max duration of maintenance mode
	 *
	 * @var int
	 */
	protected $wordpress_default_maintenance_mode_duration = 600;

	/**
	 * Enables maintenance mode
	 *
	 * ## OPTIONS
	 * [--duration=<time>]
	 * : When maintenance mode should be disabled automatically
	 * Allowed values:
	 *  - 'default'
	 *  - 'forever' (must be used with --force option)
	 *  - <unix timestamp>
	 *  - <datetime string> (parsed by strtotime)
	 *
	 * [--force]
	 * : Allow overwrite current maintenance mode duration
	 * or set maintenance mode for unlimited time
	 *
	 * ## EXAMPLES
	 *  # Enable maintenance mode for 10 minutes (by default)
	 *  $ wp maintenance enable
	 *  Success: Maintenance mode enabled
	 *
	 *  # Enable maintenance mode for 1 hour
	 *  $ wp maintenance enable --duration=3600
	 *  Success: Maintenance mode enabled
	 *
	 *  # Enable maintenance mode for unlimited time
	 *  $ wp maintenance enable --duration=forever --force
	 *  Success: Maintenance mode enabled
	 *
	 *  # Enable maintenance mode till Jan 1st, 2020, 1:30 am
	 *  $ wp maintenance enable --duration='2020-01-01 01:30:00'
	 *  Success: Maintenance mode enabled
	 *
	 * @param array $args
	 * @param array $assoc_args
	 *
	 * @when before_wp_config_load
	 */
	public function enable( $args, $assoc_args ) {
		if ( $this->validate_parameters( $args, $assoc_args ) ) {
			$upgrading_value  = $this->parse_upgrading_var_value( $assoc_args );
			$wordpress_dir    = $this->get_wordpress_installation_dir( $assoc_args );
			$maintenance_file = $this->get_maintenance_file_path( $assoc_args );
			$is_enabled       = $this->is_maintenance_mode_enabled( $assoc_args );

			if ( ! file_exists( $maintenance_file ) && ! is_writable( $wordpress_dir ) ) {
				WP_CLI::error( sprintf( "Insufficient permission to create file '%s'.", $maintenance_file ) );
			}

			if ( file_exists( $maintenance_file ) && ! is_writable( $maintenance_file ) ) {
				WP_CLI::error( sprintf( "Insufficient permission to overwrite file '%s'.", $maintenance_file ) );
			}

			if ( $is_enabled && ! WP_CLI\Utils\get_flag_value( $assoc_args, 'force' ) ) {
				WP_CLI::error( 'Maintenance mode already enabled. For overwrite duration use --force option' );
			}

			if ( false == file_put_contents( $maintenance_file, sprintf( '<?php $upgrading = %s;', $upgrading_value ) ) ) {
				WP_CLI::error( 'Maintenance mode couldn\'t be enabled. Please, try again' );
			}

			$was_enabled = $is_enabled;

			WP_CLI::success( $was_enabled ? 'Maintenance mode duration updated' : 'Maintenance mode enabled' );
		}
	}

	/**
	 * Disables maintenance mode
	 *
	 * ## EXAMPLES
	 *  # Disable maintenance mode
	 *  $ wp maintenance disable
	 *  Success: Maintenance mode disabled
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function disable( $args, $assoc_args ) {
		if ( $this->is_maintenance_mode_enabled( $assoc_args ) ) {
			$maintenance_file = $this->get_maintenance_file_path( $assoc_args );

			if ( is_file( $maintenance_file ) ) {
				if ( false === unlink( $maintenance_file ) ) {
					WP_CLI::error( sprintf( 'Couldn\'t delete file %s', $maintenance_file ) );
				}

				WP_CLI::success( 'Maintenance mode disabled' );
			}
		} else {
			WP_CLI::warning( 'Maintenance mode already disabled' );
		}
	}

	/**
	 * Displays maintenance mode status
	 *
	 * Shows 1 if maintenance mode enabled
	 * or 0 if maintenance mode is disabled
	 *
	 * ## EXAMPLES
	 *  # Check status of wordpress installation with maintenance mode enabled
	 *  $ wp maintenance check
	 *  1
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function status( $args, $assoc_args ) {
		WP_CLI::line( (int) $this->is_maintenance_mode_enabled( $assoc_args ) );
	}

	/**
	 * @param array $args
	 * @param array $assoc_args
	 *
	 * @return bool
	 */
	private function validate_parameters( $args, $assoc_args ) {
		if ( ! isset( $assoc_args['duration'] ) ) {
			return true;
		}

		if ( ! $assoc_args['duration'] ) {
			WP_CLI::warning( 'No value passed for duration option. Using \'default\'' );

			return true;
		}

		if ( is_numeric( $assoc_args['duration'] ) ) {
			if ( (int) $assoc_args['duration'] < 1 ) {
				WP_CLI::error( 'Duration must be a time in seconds greater than zero' );

				return false;
			}

			return true;
		}

		if ( is_string( $assoc_args['duration'] ) ) {
			if ( 'forever' === $assoc_args['duration'] ) {
				if ( ! WP_CLI\Utils\get_flag_value( $assoc_args, 'force' ) ) {
					WP_CLI::error( 'Value \'forever\' must be used with --force option together' );

					return false;
				}

				return true;
			}

			if ( 'default' === $assoc_args['duration'] ) {
				return true;
			}

			if ( $time = strtotime( $assoc_args['duration'] ) ) {
				if ( time() > $time ) {
					WP_CLI::error( 'Duration time must be greater than current time' );
				}

				return true;
			}
		}

		return false;
	}

	/**
	 * @param $assoc_args
	 *
	 * @return string|int
	 */
	private function parse_upgrading_var_value( $assoc_args ) {

		$offset = 0;

		if ( isset( $assoc_args['duration'] ) ) {
			$duration = $assoc_args['duration'];

			if ( 'forever' === $assoc_args['duration'] ) {
				return 'time()';
			}

			$time = 0;

			if ( is_numeric( $duration ) ) {
				$time = (int) $duration;
			} elseif ( $duration && 'default' !== $duration ) {
				$time = strtotime( $duration ) - time();
			}

			if ( $time ) {
				$offset = $time - $this->wordpress_default_maintenance_mode_duration;
			}
		}

		return time() + $offset;
	}

	/**
	 * @param array $assoc_args
	 *
	 * @return string
	 */
	private function get_wordpress_installation_dir( $assoc_args ) {
		return isset( $assoc_args['path'] ) ? $assoc_args['path'] : ABSPATH;
	}

	/**
	 * @param array $assoc_args
	 *
	 * @return string
	 */
	private function get_maintenance_file_path( $assoc_args ) {
		return $this->get_wordpress_installation_dir( $assoc_args ) . '.maintenance';
	}

	/**
	 * @param array $assoc_args
	 *
	 * @return bool
	 */
	private function is_maintenance_mode_enabled( $assoc_args ) {
		$upgrading = 0;

		$maintenance_file = $this->get_maintenance_file_path( $assoc_args );

		if ( ! file_exists( $maintenance_file ) ) {
			return false;
		}

		require $maintenance_file;

		if ( time() - $upgrading >= $this->wordpress_default_maintenance_mode_duration ) {
			return false;
		}

		return true;
	}
}