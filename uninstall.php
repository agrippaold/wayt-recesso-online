<?php
/**
 * Disinstallazione di WAYT Recesso Online.
 *
 * Comportamento (per scelta di compliance):
 * - Le IMPOSTAZIONI del plugin vengono sempre rimosse.
 * - I transient di lock vengono sempre puliti.
 * - Il REGISTRO dei recessi (tabella audit) e i meta sugli ordini vengono
 *   rimossi SOLO se l'amministratore ha esplicitamente attivato l'opzione
 *   "Elimina anche il registro recessi" (purge_on_uninstall = yes), perche'
 *   costituiscono una prova di conformita' che di norma va conservata.
 * - La pagina pubblica con lo shortcode [wayt_recesso] NON viene toccata
 *   (e' contenuto dell'utente).
 *
 * @package WAYT_Recesso_Online
 */

// Eseguito solo dal core di WordPress in fase di disinstallazione.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Esegue la pulizia per il blog corrente.
 *
 * @param bool $purge Se true rimuove anche tabella audit, meta ordini e transient.
 * @return void
 */
function wayt_recesso_uninstall_site( bool $purge ): void {
	global $wpdb;

	$option_main = 'wayt_recesso_options';
	$option_db   = 'wayt_recesso_db_version';

	// 1) Le impostazioni si rimuovono sempre.
	delete_option( $option_main );
	delete_option( $option_db );

	// 2) Pulizia transient di lock (best-effort; con object cache esterno
	//    potrebbero non essere in tabella, ma il SQL e' innocuo).
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '\_transient\_wayt\_recesso\_lock\_%'
		    OR option_name LIKE '\_transient\_timeout\_wayt\_recesso\_lock\_%'"
	);

	if ( ! $purge ) {
		return;
	}

	// 3) Drop della tabella audit.
	$table = $wpdb->prefix . 'wayt_recesso_requests';
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL

	// 4) Rimozione meta sugli ordini.
	// 4a) Storage legacy (CPT shop_order in postmeta).
	delete_post_meta_by_key( '_wayt_recesso' );
	delete_post_meta_by_key( '_wayt_recesso_date' );

	// 4b) Storage HPOS (tabella wc_orders_meta), se presente.
	$hpos_meta = $wpdb->prefix . 'wc_orders_meta';
	$exists    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_meta ) );
	if ( $exists === $hpos_meta ) {
		$wpdb->query(
			"DELETE FROM {$hpos_meta}
			 WHERE meta_key IN ( '_wayt_recesso', '_wayt_recesso_date' )"
		); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	// 5) Rimozione meta di esclusione dal recesso sui prodotti.
	delete_post_meta_by_key( '_wayt_recesso_excluded' );

	// 6) Rimozione cartella PDF temporanea (best-effort).
	$upload = wp_upload_dir();
	if ( empty( $upload['error'] ) ) {
		$tmp_dir = trailingslashit( $upload['basedir'] ) . 'wayt-recesso-tmp';
		if ( is_dir( $tmp_dir ) ) {
			$files = glob( trailingslashit( $tmp_dir ) . '*' );
			if ( is_array( $files ) ) {
				foreach ( $files as $f ) {
					if ( is_file( $f ) ) {
						wp_delete_file( $f );
					}
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			@rmdir( $tmp_dir );
		}
	}
}

// Decide se eseguire il purge completo leggendo l'opzione salvata.
$wayt_recesso_opts  = get_option( 'wayt_recesso_options', array() );
$wayt_recesso_purge = is_array( $wayt_recesso_opts )
	&& isset( $wayt_recesso_opts['purge_on_uninstall'] )
	&& 'yes' === $wayt_recesso_opts['purge_on_uninstall'];

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $site_ids as $wayt_recesso_site_id ) {
		switch_to_blog( (int) $wayt_recesso_site_id );

		// L'opzione e per-sito: rileggi il flag dentro ogni blog.
		$site_opts  = get_option( 'wayt_recesso_options', array() );
		$site_purge = is_array( $site_opts )
			&& isset( $site_opts['purge_on_uninstall'] )
			&& 'yes' === $site_opts['purge_on_uninstall'];

		wayt_recesso_uninstall_site( $site_purge );
		restore_current_blog();
	}
} else {
	wayt_recesso_uninstall_site( $wayt_recesso_purge );
}
