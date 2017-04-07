<?php

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once 'vendor/autoload.php';

	WP_CLI::add_command( 'maintenance', Artem328\WP_CLI_Maintenance\Commands\MaintenanceCommand::class );
} else {
	WP_CLI::error( 'Run composer install first' );
}
