<?php
/**
 * Plugin Name:       WAYT Recesso Online (art. 54-bis Cod. Consumo)
 * Plugin URI:        https://wayt.it/
 * Description:       Funzione di recesso digitale conforme all'art. 54-bis del Codice del Consumo (D.Lgs 209/2025, Dir. UE 2023/2673) per WooCommerce: pulsante "Recedi dal contratto qui", dichiarazione + conferma, avviso di ricevimento su supporto durevole, audit log ed export CSV.
 * Version:           0.2.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            WAYT
 * Author URI:        https://wayt.it/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wayt-recesso
 * Domain Path:       /languages
 *
 * WC requires at least: 7.0
 * WC tested up to:      9.9
 *
 * @package WAYT_Recesso_Online
 *
 * NOTA LEGALE: questo plugin implementa i requisiti TECNICI dell'art. 54-bis.
 * Le condizioni generali di vendita e l'informativa precontrattuale restano
 * responsabilita' del professionista e del suo consulente legale.
 */

// Impedisci accesso diretto.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WAYT_RECESSO_VERSION', '0.2.0' );
define( 'WAYT_RECESSO_DB_VERSION', '1.1.0' );
define( 'WAYT_RECESSO_FILE', __FILE__ );
define( 'WAYT_RECESSO_PATH', plugin_dir_path( __FILE__ ) );
define( 'WAYT_RECESSO_URL', plugin_dir_url( __FILE__ ) );
define( 'WAYT_RECESSO_BASENAME', plugin_basename( __FILE__ ) );
define( 'WAYT_RECESSO_OPTION', 'wayt_recesso_options' );
define( 'WAYT_RECESSO_TABLE', 'wayt_recesso_requests' );
define( 'WAYT_RECESSO_STATUS', 'recesso' ); // slug interno (diventa wc-recesso).

// Dipendenze interne.
require_once WAYT_RECESSO_PATH . 'includes/class-wayt-recesso-pdf.php';

/**
 * Dichiarazione di compatibilita' HPOS (High-Performance Order Storage).
 * Deve girare prima dell'init di WooCommerce.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				WAYT_RECESSO_FILE,
				true
			);
		}
	}
);

/**
 * Classe principale (singleton).
 */
final class WAYT_Recesso_Online {

	/**
	 * Istanza singleton.
	 *
	 * @var WAYT_Recesso_Online|null
	 */
	private static ?WAYT_Recesso_Online $instance = null;

	/**
	 * Cache opzioni.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $opts = null;

	/**
	 * Cache per-request dell'esclusione prodotti (art. 59).
	 *
	 * @var array<int,bool>
	 */
	private array $excluded_cache = [];

	/**
	 * Cache per-request dei recessi totali gia' registrati per ordine.
	 *
	 * @var array<int,bool>
	 */
	private array $withdrawn_cache = [];

	/**
	 * Bootstrap.
	 */
	public static function instance(): WAYT_Recesso_Online {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Costruttore: registra gli hook.
	 */
	private function __construct() {
		// i18n.
		add_action( 'init', [ $this, 'load_textdomain' ], 0 );

		// Verifica WooCommerce attivo.
		add_action( 'plugins_loaded', [ $this, 'check_dependencies' ], 20 );

		// Migrazione schema su aggiornamento del plugin (idempotente, guardata dalla DB version).
		add_action( 'plugins_loaded', [ $this, 'maybe_upgrade' ], 21 );

		// Stato ordine custom.
		add_action( 'init', [ $this, 'register_order_status' ] );
		add_filter( 'wc_order_statuses', [ $this, 'add_order_status' ] );

		// Shortcodes.
		add_shortcode( 'wayt_recesso', [ $this, 'shortcode_recesso' ] );
		add_shortcode( 'wayt_recesso_info', [ $this, 'shortcode_info' ] );

		// Frontend: pulsanti nell'area "Il mio account" + link nelle email.
		add_filter( 'woocommerce_my_account_my_orders_actions', [ $this, 'my_orders_action' ], 10, 2 );
		add_action( 'woocommerce_order_details_after_order_table', [ $this, 'order_details_box' ], 20 );
		add_action( 'woocommerce_email_after_order_table', [ $this, 'email_withdrawal_link' ], 20, 3 );

		// Info precontrattuale al checkout (opzionale).
		add_action( 'woocommerce_review_order_before_submit', [ $this, 'checkout_precontractual_notice' ] );
		// Checkout a blocchi: inietta la nota lato server (no JS build).
		add_filter( 'render_block', [ $this, 'inject_blocks_notice' ], 10, 2 );

		// Prodotto: checkbox "escluso dal recesso" (art. 59).
		add_action( 'woocommerce_product_options_general_product_data', [ $this, 'product_exclusion_field' ] );
		add_action( 'woocommerce_process_product_meta', [ $this, 'product_exclusion_save' ] );

		// Admin.
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_filter( 'plugin_action_links_' . WAYT_RECESSO_BASENAME, [ $this, 'plugin_action_links' ] );

		// Handler admin-post (export CSV, azioni sulle richieste, download PDF).
		add_action( 'admin_post_wayt_recesso_export', [ $this, 'handle_export_csv' ] );
		add_action( 'admin_post_wayt_recesso_request_action', [ $this, 'handle_request_action' ] );
		add_action( 'admin_post_wayt_recesso_pdf', [ $this, 'handle_pdf_download' ] );
	}

	/*
	 * ATTIVAZIONE / DISATTIVAZIONE
	 */

	/**
	 * Attivazione: crea tabella, opzioni di default, pagina recesso.
	 */
	public static function activate(): void {
		self::install_table();

		// Opzioni di default.
		$defaults = self::default_options();
		$existing = get_option( WAYT_RECESSO_OPTION, [] );
		if ( ! is_array( $existing ) ) {
			$existing = [];
		}
		update_option( WAYT_RECESSO_OPTION, wp_parse_args( $existing, $defaults ) );
		update_option( 'wayt_recesso_db_version', WAYT_RECESSO_DB_VERSION );

		self::maybe_create_page();

		flush_rewrite_rules();
	}

	/**
	 * Disattivazione.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	/**
	 * Crea/aggiorna la tabella di audit con dbDelta.
	 */
	private static function install_table(): void {
		global $wpdb;
		$table           = $wpdb->prefix . WAYT_RECESSO_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			order_number VARCHAR(60) NOT NULL DEFAULT '',
			consumer_name VARCHAR(191) NOT NULL DEFAULT '',
			consumer_email VARCHAR(191) NOT NULL DEFAULT '',
			scope VARCHAR(20) NOT NULL DEFAULT 'full',
			items LONGTEXT NULL,
			reason TEXT NULL,
			declaration LONGTEXT NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'ricevuto',
			ip VARCHAR(100) NOT NULL DEFAULT '',
			token VARCHAR(64) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			created_at_gmt DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			ack_sent_at DATETIME NULL,
			full_order_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY status (status),
			KEY token (token),
			UNIQUE KEY full_order_id (full_order_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Applica la migrazione dello schema quando il plugin viene aggiornato senza
	 * passare dall'hook di attivazione. Idempotente: gira solo se la DB version
	 * memorizzata e' diversa da quella corrente e dbDelta applica solo le differenze.
	 */
	public function maybe_upgrade(): void {
		if ( WAYT_RECESSO_DB_VERSION === get_option( 'wayt_recesso_db_version' ) ) {
			return;
		}
		self::install_table();
		update_option( 'wayt_recesso_db_version', WAYT_RECESSO_DB_VERSION );
	}

	/**
	 * Opzioni di default.
	 *
	 * @return array<string,mixed>
	 */
	private static function default_options(): array {
		return [
			'withdrawal_days'        => 14,
			'start_trigger'          => 'date_completed', // date_completed|date_paid|date_created|delivery_meta.
			'delivery_meta_key'      => '_delivery_date',
			'eligible_statuses'      => [ 'processing', 'completed' ],
			'button_label'           => __( 'Recedi dal contratto qui', 'wayt-recesso' ),
			'confirm_label'          => __( 'Conferma recesso', 'wayt-recesso' ),
			'merchant_email'         => get_option( 'admin_email' ),
			'enable_reason'          => 'yes',
			'enable_partial'         => 'yes',
			'set_status'             => WAYT_RECESSO_STATUS, // recesso|on-hold|none.
			'email_subject'          => __( 'Avviso di ricevimento del recesso - Ordine #{order_number}', 'wayt-recesso' ),
			'email_heading'          => __( 'Abbiamo ricevuto la tua richiesta di recesso', 'wayt-recesso' ),
			'precontractual_text'    => __( 'Hai diritto di recedere dal contratto entro 14 giorni senza fornire alcuna motivazione. Potrai esercitare il recesso in modo semplice e diretto tramite l\'apposita funzione "Recedi dal contratto qui" disponibile nella tua area ordini e alla pagina dedicata al recesso, oltre che tramite il modulo tipo di recesso.', 'wayt-recesso' ),
			'modulo_tipo_url'        => '',
			'recesso_page_id'        => 0,
			'checkout_notice'        => 'yes',
			'checkout_blocks_notice' => 'yes',
			'reasons_list'           => '', // una motivazione per riga (vuoto = campo libero).
			'excluded_products'      => '', // ID prodotto separati da virgola/righe.
			'excluded_categories'    => '', // slug categoria separati da virgola/righe.
			'auto_refund'            => 'off', // off|items|full.
			'pdf_enabled'            => 'yes', // allega PDF attestato all'avviso.
			'email_from_name'        => '', // mittente avviso (vuoto = default WooCommerce).
			'email_reply_to'         => '', // reply-to avviso.
			'email_body'             => '', // testo introduttivo email (vuoto = default).
			'accent_color'           => '#111111',
			'custom_css'             => '',
			'purge_on_uninstall'     => 'no', // 'yes' = elimina tabella audit + meta alla disinstallazione.
		];
	}

	/**
	 * Crea la pagina "Recesso" con lo shortcode se non esiste.
	 */
	private static function maybe_create_page(): void {
		$opts = get_option( WAYT_RECESSO_OPTION, [] );
		$pid  = is_array( $opts ) ? (int) ( $opts['recesso_page_id'] ?? 0 ) : 0;

		if ( $pid && 'page' === get_post_type( $pid ) && 'trash' !== get_post_status( $pid ) ) {
			return;
		}

		$page_id = wp_insert_post(
			[
				'post_title'   => __( 'Recesso dal contratto', 'wayt-recesso' ),
				'post_name'    => 'recesso',
				'post_content' => '[wayt_recesso]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			],
			true
		);

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			$opts                    = is_array( $opts ) ? $opts : [];
			$opts['recesso_page_id'] = (int) $page_id;
			update_option( WAYT_RECESSO_OPTION, $opts );
		}
	}

	/*
	 * INFRASTRUTTURA
	 */

	/**
	 * Carica il text domain.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'wayt-recesso', false, dirname( WAYT_RECESSO_BASENAME ) . '/languages' );
	}

	/**
	 * Avvisa se WooCommerce non e' attivo.
	 */
	public function check_dependencies(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'WAYT Recesso Online richiede WooCommerce attivo.', 'wayt-recesso' );
					echo '</p></div>';
				}
			);
		}
	}

	/**
	 * Restituisce un'opzione.
	 *
	 * @param string $key      Chiave.
	 * @param mixed  $fallback Valore di fallback.
	 * @return mixed
	 */
	public function opt( string $key, $fallback = '' ) {
		if ( null === $this->opts ) {
			$stored     = get_option( WAYT_RECESSO_OPTION, [] );
			$this->opts = wp_parse_args( is_array( $stored ) ? $stored : [], self::default_options() );
		}
		return $this->opts[ $key ] ?? $fallback;
	}

	/*
	 * STATO ORDINE CUSTOM
	 */

	/**
	 * Registra lo stato post per l'ordine.
	 */
	public function register_order_status(): void {
		register_post_status(
			'wc-' . WAYT_RECESSO_STATUS,
			[
				'label'                     => _x( 'Recesso richiesto', 'Order status', 'wayt-recesso' ),
				'public'                    => false,
				'internal'                  => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: numero di ordini */
				'label_count'               => _n_noop(
					'Recesso richiesto <span class="count">(%s)</span>',
					'Recesso richiesto <span class="count">(%s)</span>',
					'wayt-recesso'
				),
			]
		);
	}

	/**
	 * Aggiunge lo stato alla lista WooCommerce.
	 *
	 * @param array<string,string> $statuses Stati.
	 * @return array<string,string>
	 */
	public function add_order_status( array $statuses ): array {
		$new = [];
		foreach ( $statuses as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'wc-processing' === $key ) {
				$new[ 'wc-' . WAYT_RECESSO_STATUS ] = _x( 'Recesso richiesto', 'Order status', 'wayt-recesso' );
			}
		}
		// Fallback nel caso non ci sia processing.
		if ( ! isset( $new[ 'wc-' . WAYT_RECESSO_STATUS ] ) ) {
			$new[ 'wc-' . WAYT_RECESSO_STATUS ] = _x( 'Recesso richiesto', 'Order status', 'wayt-recesso' );
		}
		return $new;
	}

	/*
	 * ELEGGIBILITA'
	 */

	/**
	 * Calcola la data di scadenza del recesso per un ordine.
	 *
	 * @param WC_Order $order Ordine.
	 * @return DateTimeImmutable|null
	 */
	public function get_deadline( WC_Order $order ): ?DateTimeImmutable {
		$days    = max( 1, (int) $this->opt( 'withdrawal_days', 14 ) );
		$trigger = (string) $this->opt( 'start_trigger', 'date_completed' );
		$start   = null;

		switch ( $trigger ) {
			case 'date_paid':
				$start = $order->get_date_paid();
				break;
			case 'date_created':
				$start = $order->get_date_created();
				break;
			case 'delivery_meta':
				$raw = (string) $order->get_meta( (string) $this->opt( 'delivery_meta_key', '_delivery_date' ) );
				if ( '' !== $raw ) {
					$ts = strtotime( $raw );
					if ( $ts ) {
						try {
							$start = new WC_DateTime( gmdate( 'Y-m-d H:i:s', $ts ), new DateTimeZone( 'UTC' ) );
						} catch ( Exception $e ) {
							$start = null;
						}
					}
				}
				break;
			case 'date_completed':
			default:
				$start = $order->get_date_completed();
				break;
		}

		// Fallback: data creazione.
		if ( ! $start ) {
			$start = $order->get_date_created();
		}
		if ( ! $start ) {
			return null;
		}

		try {
			$deadline = ( new DateTimeImmutable( '@' . $start->getTimestamp() ) )
				->setTimezone( wp_timezone() )
				->modify( '+' . $days . ' days' )
				->setTime( 23, 59, 59 );
		} catch ( Exception $e ) {
			return null;
		}

		return $deadline;
	}

	/**
	 * Verifica se l'ordine e' eleggibile al recesso ORA.
	 *
	 * @param WC_Order $order Ordine.
	 * @return bool
	 */
	public function is_eligible( WC_Order $order ): bool {
		$statuses = (array) $this->opt( 'eligible_statuses', [ 'processing', 'completed' ] );
		if ( ! in_array( $order->get_status(), $statuses, true ) ) {
			return false;
		}
		// Se tutti gli articoli sono esclusi dal recesso (art. 59), niente pulsante.
		if ( $this->order_fully_excluded( $order ) ) {
			return false;
		}
		$deadline = $this->get_deadline( $order );
		if ( ! $deadline ) {
			return false;
		}
		$now = new DateTimeImmutable( 'now', wp_timezone() );
		return $now <= $deadline;
	}

	/**
	 * Giorni residui (>=0) o null.
	 *
	 * @param WC_Order $order Ordine.
	 * @return int|null
	 */
	public function days_left( WC_Order $order ): ?int {
		$deadline = $this->get_deadline( $order );
		if ( ! $deadline ) {
			return null;
		}
		$now  = new DateTimeImmutable( 'now', wp_timezone() );
		$diff = $now->diff( $deadline );
		return $now <= $deadline ? (int) $diff->days : 0;
	}

	/**
	 * Verifica se esiste gia' un recesso totale registrato per l'ordine.
	 *
	 * @param int $order_id ID ordine.
	 * @return bool
	 */
	public function already_withdrawn( int $order_id ): bool {
		if ( isset( $this->withdrawn_cache[ $order_id ] ) ) {
			return $this->withdrawn_cache[ $order_id ];
		}
		global $wpdb;
		$table = $wpdb->prefix . WAYT_RECESSO_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome tabella da $wpdb->prefix; valori passati via prepare().
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE order_id = %d AND scope = %s", $order_id, 'full' ) );

		$this->withdrawn_cache[ $order_id ] = $count > 0;
		return $this->withdrawn_cache[ $order_id ];
	}

	/**
	 * URL della funzione di recesso per uno specifico ordine (con order_key).
	 *
	 * @param WC_Order $order Ordine.
	 * @return string
	 */
	public function withdrawal_url( WC_Order $order ): string {
		$pid  = (int) $this->opt( 'recesso_page_id', 0 );
		$base = $pid ? get_permalink( $pid ) : home_url( '/recesso/' );
		if ( ! $base ) {
			$base = home_url( '/recesso/' );
		}
		return add_query_arg(
			[
				'order' => $order->get_id(),
				'key'   => $order->get_order_key(),
			],
			$base
		);
	}

	/*
	 * FRONTEND — INGRESSO ALLA FUNZIONE
	 */

	/**
	 * Bottone "Recedi" nella lista ordini di "Il mio account".
	 *
	 * @param array<string,array<string,string>> $actions Azioni.
	 * @param WC_Order                           $order   Ordine.
	 * @return array<string,array<string,string>>
	 */
	public function my_orders_action( array $actions, WC_Order $order ): array {
		if ( $this->is_eligible( $order ) && ! $this->already_withdrawn( $order->get_id() ) ) {
			$actions['wayt_recesso'] = [
				'url'  => $this->withdrawal_url( $order ),
				'name' => (string) $this->opt( 'button_label', __( 'Recedi dal contratto qui', 'wayt-recesso' ) ),
			];
		}
		return $actions;
	}

	/**
	 * Box recesso nel dettaglio ordine.
	 *
	 * @param WC_Order $order Ordine.
	 */
	public function order_details_box( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( ! $this->is_eligible( $order ) || $this->already_withdrawn( $order->get_id() ) ) {
			return;
		}
		$left = $this->days_left( $order );
		echo '<section class="wayt-recesso-box" style="margin:1.5em 0;padding:1em 1.25em;border:1px solid #d9d9d9;border-radius:8px;background:#fafafa;">';
		echo '<h2 style="margin-top:0;font-size:1.1em;">' . esc_html__( 'Diritto di recesso', 'wayt-recesso' ) . '</h2>';
		if ( null !== $left ) {
			echo '<p style="margin:.3em 0;">' . sprintf(
				/* translators: %d: giorni residui */
				esc_html__( 'Puoi esercitare il recesso per ancora %d giorni.', 'wayt-recesso' ),
				(int) $left
			) . '</p>';
		}
		printf(
			'<a class="button" href="%1$s" style="background:#1f6feb;color:#fff;">%2$s</a>',
			esc_url( $this->withdrawal_url( $order ) ),
			esc_html( (string) $this->opt( 'button_label', __( 'Recedi dal contratto qui', 'wayt-recesso' ) ) )
		);
		echo '</section>';
	}

	/**
	 * Inserisce il link alla funzione di recesso nelle email ordine.
	 *
	 * @param WC_Order $order         Ordine.
	 * @param bool     $sent_to_admin Inviata all'admin.
	 * @param bool     $plain_text    Email testuale.
	 */
	public function email_withdrawal_link( $order, $sent_to_admin = false, $plain_text = false ): void {
		if ( ! $order instanceof WC_Order || $sent_to_admin ) {
			return;
		}
		// Solo nelle email cliente di ordini eleggibili.
		if ( ! $this->is_eligible( $order ) || $this->already_withdrawn( $order->get_id() ) ) {
			return;
		}
		$url   = $this->withdrawal_url( $order );
		$label = (string) $this->opt( 'button_label', __( 'Recedi dal contratto qui', 'wayt-recesso' ) );

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Diritto di recesso:', 'wayt-recesso' ) . ' ' . esc_url_raw( $url ) . "\n";
			return;
		}
		echo '<p style="margin:16px 0;"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:10px 16px;background:#1f6feb;color:#ffffff;text-decoration:none;border-radius:4px;">' . esc_html( $label ) . '</a></p>';
	}

	/*
	 * FRONTEND — INFO PRECONTRATTUALE
	 */

	/**
	 * Shortcode [wayt_recesso_info]: nota informativa precontrattuale.
	 *
	 * @return string
	 */
	public function shortcode_info(): string {
		$text = (string) $this->opt( 'precontractual_text', '' );
		$pid  = (int) $this->opt( 'recesso_page_id', 0 );
		$mod  = (string) $this->opt( 'modulo_tipo_url', '' );

		ob_start();
		echo '<div class="wayt-recesso-info" style="font-size:.95em;">';
		echo wp_kses_post( wpautop( $text ) );
		echo '<ul style="margin:.3em 0 0 1.2em;">';
		if ( $pid ) {
			echo '<li><a href="' . esc_url( get_permalink( $pid ) ) . '">' . esc_html__( 'Vai alla funzione di recesso online', 'wayt-recesso' ) . '</a></li>';
		}
		if ( '' !== $mod ) {
			echo '<li><a href="' . esc_url( $mod ) . '">' . esc_html__( 'Scarica il modulo tipo di recesso', 'wayt-recesso' ) . '</a></li>';
		}
		echo '</ul></div>';
		return (string) ob_get_clean();
	}

	/**
	 * Nota precontrattuale al checkout (opzionale).
	 */
	public function checkout_precontractual_notice(): void {
		if ( 'yes' !== $this->opt( 'checkout_notice', 'yes' ) ) {
			return;
		}
		$text = trim( (string) $this->opt( 'precontractual_text', '' ) );
		if ( '' === $text ) {
			return;
		}
		echo '<div class="wayt-recesso-checkout-notice" style="margin:1em 0;font-size:.85em;color:#555;">';
		echo esc_html( $text );
		echo '</div>';
	}

	/*
	 * FRONTEND — FUNZIONE DI RECESSO (FLOW)
	 */

	/**
	 * Shortcode [wayt_recesso]: la funzione di recesso (lookup -> dichiarazione -> conferma -> esito).
	 *
	 * @return string
	 */
	public function shortcode_recesso(): string {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return '<p>' . esc_html__( 'WooCommerce non disponibile.', 'wayt-recesso' ) . '</p>';
		}

		ob_start();
		$this->print_styles_once();

		// Solo routing dello stage: ogni handler verifica il proprio nonce (process_confirm/render_confirm_step/handle_lookup) o l'order_key.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$stage = isset( $_POST['wayt_stage'] ) ? sanitize_key( wp_unslash( $_POST['wayt_stage'] ) ) : '';

		// 1) Conferma definitiva (comando di conferma di legge).
		if ( 'confirm' === $stage ) {
			$this->process_confirm();
			return (string) ob_get_clean();
		}

		// 2) Step dichiarazione -> mostra riepilogo + conferma.
		if ( 'declare' === $stage ) {
			$this->render_confirm_step();
			return (string) ob_get_clean();
		}

		// 3) Ingresso diretto con order_key (da account/email).
		$order = $this->resolve_order_from_request();
		if ( $order instanceof WC_Order ) {
			$this->render_declaration_step( $order );
			return (string) ob_get_clean();
		}

		// 4) Lookup tramite numero ordine + email (ospiti).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- il nonce e' verificato in handle_lookup().
		if ( isset( $_POST['wayt_stage'] ) && 'lookup' === sanitize_key( wp_unslash( $_POST['wayt_stage'] ) ) ) {
			$lookup = $this->handle_lookup();
			if ( $lookup instanceof WC_Order ) {
				$this->render_declaration_step( $lookup );
				return (string) ob_get_clean();
			}
		}

		// 5) Utente loggato: lista ordini eleggibili.
		if ( is_user_logged_in() ) {
			$this->render_account_orders_list();
		}

		// 6) Form di lookup.
		$this->render_lookup_form();
		return (string) ob_get_clean();
	}

	/**
	 * Recupera l'ordine da ?order=&key= verificando l'order_key (pattern WooCommerce).
	 *
	 * @return WC_Order|null
	 */
	private function resolve_order_from_request(): ?WC_Order {
		// L'accesso e' autenticato dall'order_key (hash_equals piu' avanti), non da nonce: pattern WooCommerce per i link in email/account.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$order_id = isset( $_GET['order'] ) ? absint( wp_unslash( $_GET['order'] ) ) : 0;
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! $order_id || '' === $key ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		if ( ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			return null;
		}
		return $order;
	}

	/**
	 * Lookup ospite: numero ordine + email.
	 *
	 * @return WC_Order|null
	 */
	private function handle_lookup(): ?WC_Order {
		if ( ! isset( $_POST['wayt_lookup_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wayt_lookup_nonce'] ) ), 'wayt_recesso_lookup' ) ) {
			wc_print_notice( __( 'Sessione scaduta, riprova.', 'wayt-recesso' ), 'error' );
			return null;
		}

		$number = isset( $_POST['wayt_order_number'] ) ? sanitize_text_field( wp_unslash( $_POST['wayt_order_number'] ) ) : '';
		$email  = isset( $_POST['wayt_email'] ) ? sanitize_email( wp_unslash( $_POST['wayt_email'] ) ) : '';

		$number   = ltrim( $number, '#' );
		$order_id = (int) $number;
		// Supporta anche numeri ordine custom (sequential order numbers).
		if ( $order_id <= 0 ) {
			wc_print_notice( __( 'Numero ordine non valido.', 'wayt-recesso' ), 'error' );
			return null;
		}

		// Throttling anti-enumerazione legato al BERSAGLIO (numero ordine ed email),
		// non all'IP: dietro reverse proxy/CDN un limite per IP bloccherebbe tutti gli
		// ospiti che condividono l'IP del proxy. Si contano i tentativi falliti per
		// uno stesso ordine (e per una stessa email); un lookup riuscito li azzera,
		// cosi' il cliente legittimo non viene mai penalizzato.
		$throttle_keys = array( 'wayt_recesso_lk_o_' . md5( (string) $order_id ) );
		if ( '' !== $email ) {
			$throttle_keys[] = 'wayt_recesso_lk_e_' . md5( strtolower( $email ) );
		}
		foreach ( $throttle_keys as $tk ) {
			if ( (int) get_transient( $tk ) >= 10 ) {
				wc_print_notice( __( 'Troppi tentativi di ricerca. Riprova tra qualche minuto.', 'wayt-recesso' ), 'error' );
				return null;
			}
		}
		foreach ( $throttle_keys as $tk ) {
			set_transient( $tk, (int) get_transient( $tk ) + 1, 15 * MINUTE_IN_SECONDS );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! is_email( $email ) || strtolower( $order->get_billing_email() ) !== strtolower( $email ) ) {
			wc_print_notice( __( 'Ordine non trovato o email non corrispondente.', 'wayt-recesso' ), 'error' );
			return null;
		}

		// Lookup riuscito: azzera i contatori del bersaglio.
		foreach ( $throttle_keys as $tk ) {
			delete_transient( $tk );
		}
		return $order;
	}

	/**
	 * Form di lookup ospite.
	 */
	private function render_lookup_form(): void {
		?>
		<form method="post" class="wayt-recesso-form">
			<h2><?php echo esc_html__( 'Recedi dal contratto qui', 'wayt-recesso' ); ?></h2>
			<p><?php echo esc_html__( 'Inserisci il numero del tuo ordine e l\'email utilizzata per l\'acquisto.', 'wayt-recesso' ); ?></p>
			<p class="form-row">
				<label for="wayt_order_number"><?php echo esc_html__( 'Numero ordine', 'wayt-recesso' ); ?> <span class="required">*</span></label>
				<input type="text" id="wayt_order_number" name="wayt_order_number" aria-required="true" required>
			</p>
			<p class="form-row">
				<label for="wayt_email"><?php echo esc_html__( 'Email', 'wayt-recesso' ); ?> <span class="required">*</span></label>
				<input type="email" id="wayt_email" name="wayt_email" aria-required="true" required>
			</p>
			<?php wp_nonce_field( 'wayt_recesso_lookup', 'wayt_lookup_nonce' ); ?>
			<input type="hidden" name="wayt_stage" value="lookup">
			<p><button type="submit" class="button wayt-recesso-btn"><?php echo esc_html__( 'Continua', 'wayt-recesso' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Lista ordini eleggibili per l'utente loggato.
	 */
	private function render_account_orders_list(): void {
		$orders   = wc_get_orders(
			[
				'customer' => get_current_user_id(),
				'limit'    => 20,
				'status'   => (array) $this->opt( 'eligible_statuses', [ 'processing', 'completed' ] ),
				'orderby'  => 'date',
				'order'    => 'DESC',
			]
		);
		$eligible = array_filter(
			$orders,
			function ( $o ) {
				return $o instanceof WC_Order && $this->is_eligible( $o ) && ! $this->already_withdrawn( $o->get_id() );
			}
		);
		if ( empty( $eligible ) ) {
			return;
		}
		echo '<div class="wayt-recesso-orders"><h2>' . esc_html__( 'I tuoi ordini per cui puoi recedere', 'wayt-recesso' ) . '</h2><ul>';
		foreach ( $eligible as $o ) {
			printf(
				'<li>#%1$s — %2$s — <a href="%3$s">%4$s</a></li>',
				esc_html( $o->get_order_number() ),
				esc_html( wc_format_datetime( $o->get_date_created() ) ),
				esc_url( $this->withdrawal_url( $o ) ),
				esc_html( (string) $this->opt( 'button_label', __( 'Recedi dal contratto qui', 'wayt-recesso' ) ) )
			);
		}
		echo '</ul></div>';
	}

	/**
	 * Step 1: dichiarazione di recesso (nome, identificazione ordine, mezzo elettronico, item, motivo).
	 *
	 * @param WC_Order $order Ordine.
	 */
	private function render_declaration_step( WC_Order $order ): void {
		// Guardie.
		if ( ! $this->is_eligible( $order ) ) {
			wc_print_notice( __( 'Il periodo di recesso per questo ordine non e\' attivo.', 'wayt-recesso' ), 'error' );
			return;
		}
		if ( $this->already_withdrawn( $order->get_id() ) ) {
			wc_print_notice( __( 'Per questo ordine risulta gia\' registrato un recesso.', 'wayt-recesso' ), 'notice' );
			return;
		}

		$name  = trim( $order->get_formatted_billing_full_name() );
		$email = $order->get_billing_email();
		$left  = $this->days_left( $order );
		?>
		<form method="post" class="wayt-recesso-form">
			<h2><?php echo esc_html__( 'Dichiarazione di recesso', 'wayt-recesso' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: numero ordine, 2: data */
					esc_html__( 'Ordine #%1$s del %2$s.', 'wayt-recesso' ),
					esc_html( $order->get_order_number() ),
					esc_html( wc_format_datetime( $order->get_date_created() ) )
				);
				if ( null !== $left ) {
					echo ' ';
					printf(
						/* translators: %d: giorni */
						esc_html__( 'Giorni residui: %d.', 'wayt-recesso' ),
						(int) $left
					);
				}
				?>
			</p>

			<p class="form-row">
				<label for="wayt_name"><?php echo esc_html__( 'Nome e cognome', 'wayt-recesso' ); ?> <span class="required">*</span></label>
				<input type="text" id="wayt_name" name="wayt_name" value="<?php echo esc_attr( $name ); ?>" aria-required="true" required>
			</p>
			<p class="form-row">
				<label for="wayt_confirm_email"><?php echo esc_html__( 'Email per la conferma di recesso', 'wayt-recesso' ); ?> <span class="required">*</span></label>
				<input type="email" id="wayt_confirm_email" name="wayt_confirm_email" value="<?php echo esc_attr( $email ); ?>" aria-required="true" required>
				<small><?php echo esc_html__( 'Riceverai a questo indirizzo l\'avviso di ricevimento su supporto durevole.', 'wayt-recesso' ); ?></small>
			</p>

			<?php if ( 'yes' === $this->opt( 'enable_partial', 'yes' ) ) : ?>
				<fieldset style="border:1px solid #e0e0e0;padding:.75em 1em;border-radius:6px;">
					<legend><?php echo esc_html__( 'Articoli oggetto del recesso', 'wayt-recesso' ); ?></legend>
					<label style="display:block;margin:.2em 0;">
						<input type="radio" name="wayt_scope" value="full" checked>
						<?php echo esc_html__( 'Recedo dall\'intero ordine', 'wayt-recesso' ); ?>
					</label>
					<label style="display:block;margin:.2em 0;">
						<input type="radio" name="wayt_scope" value="partial">
						<?php echo esc_html__( 'Recedo solo da alcuni articoli:', 'wayt-recesso' ); ?>
					</label>
					<div style="margin-left:1.5em;">
						<?php
						foreach ( $order->get_items() as $item_id => $item ) :
							$pid = ( $item instanceof WC_Order_Item_Product ) ? $item->get_product_id() : 0;
							if ( $pid && $this->is_item_excluded( $pid ) ) {
								continue;
							}
							?>
							<label style="display:block;margin:.15em 0;">
								<input type="checkbox" name="wayt_items[]" value="<?php echo esc_attr( $item_id ); ?>">
								<?php echo esc_html( $item->get_name() ); ?>
								<?php echo esc_html( ' × ' . $item->get_quantity() ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				</fieldset>
			<?php else : ?>
				<input type="hidden" name="wayt_scope" value="full">
			<?php endif; ?>

			<?php if ( 'yes' === $this->opt( 'enable_reason', 'yes' ) ) : ?>
				<?php $reasons = $this->get_reasons_list(); ?>
				<?php if ( ! empty( $reasons ) ) : ?>
					<p class="form-row">
						<label for="wayt_reason_select"><?php echo esc_html__( 'Motivo (facoltativo)', 'wayt-recesso' ); ?></label>
						<select id="wayt_reason_select" name="wayt_reason_select">
							<option value=""><?php echo esc_html__( '— Nessuna indicazione —', 'wayt-recesso' ); ?></option>
							<?php foreach ( $reasons as $r ) : ?>
								<option value="<?php echo esc_attr( $r ); ?>"><?php echo esc_html( $r ); ?></option>
							<?php endforeach; ?>
							<option value="__other__"><?php echo esc_html__( 'Altro (specifica)…', 'wayt-recesso' ); ?></option>
						</select>
						<textarea id="wayt_reason" name="wayt_reason" rows="2" placeholder="<?php echo esc_attr__( 'Specifica il motivo (solo se hai scelto «Altro»)', 'wayt-recesso' ); ?>"></textarea>
						<small><?php echo esc_html__( 'Il recesso non richiede alcuna motivazione: questo campo e\' del tutto facoltativo.', 'wayt-recesso' ); ?></small>
					</p>
				<?php else : ?>
					<p class="form-row">
						<label for="wayt_reason"><?php echo esc_html__( 'Motivo (facoltativo)', 'wayt-recesso' ); ?></label>
						<textarea id="wayt_reason" name="wayt_reason" rows="3"></textarea>
						<small><?php echo esc_html__( 'Il recesso non richiede alcuna motivazione: questo campo e\' del tutto facoltativo.', 'wayt-recesso' ); ?></small>
					</p>
				<?php endif; ?>
			<?php endif; ?>

			<?php wp_nonce_field( 'wayt_recesso_declare', 'wayt_declare_nonce' ); ?>
			<input type="hidden" name="wayt_stage" value="declare">
			<input type="hidden" name="wayt_order_id" value="<?php echo esc_attr( (string) $order->get_id() ); ?>">
			<input type="hidden" name="wayt_order_key" value="<?php echo esc_attr( $order->get_order_key() ); ?>">

			<p><button type="submit" class="button wayt-recesso-btn"><?php echo esc_html__( 'Prosegui', 'wayt-recesso' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Step 2: riepilogo + comando di conferma ("Conferma recesso").
	 */
	private function render_confirm_step(): void {
		// Verifica nonce dello step precedente.
		if ( ! isset( $_POST['wayt_declare_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wayt_declare_nonce'] ) ), 'wayt_recesso_declare' ) ) {
			wc_print_notice( __( 'Sessione scaduta, riprova.', 'wayt-recesso' ), 'error' );
			return;
		}

		$data  = $this->collect_posted_data();
		$order = $data['order'];
		if ( ! $order instanceof WC_Order || ! $this->is_eligible( $order ) ) {
			wc_print_notice( __( 'Ordine non valido o periodo di recesso non attivo.', 'wayt-recesso' ), 'error' );
			return;
		}

		?>
		<form method="post" class="wayt-recesso-form">
			<h2><?php echo esc_html__( 'Conferma il recesso', 'wayt-recesso' ); ?></h2>
			<table class="shop_table" style="width:100%;margin-bottom:1em;">
				<tbody>
					<tr><th><?php echo esc_html__( 'Ordine', 'wayt-recesso' ); ?></th><td>#<?php echo esc_html( $order->get_order_number() ); ?></td></tr>
					<tr><th><?php echo esc_html__( 'Nome', 'wayt-recesso' ); ?></th><td><?php echo esc_html( $data['name'] ); ?></td></tr>
					<tr><th><?php echo esc_html__( 'Email conferma', 'wayt-recesso' ); ?></th><td><?php echo esc_html( $data['email'] ); ?></td></tr>
					<tr><th><?php echo esc_html__( 'Oggetto', 'wayt-recesso' ); ?></th><td>
						<?php
						if ( 'partial' === $data['scope'] ) {
							echo esc_html__( 'Recesso parziale:', 'wayt-recesso' ) . '<br>';
							echo esc_html( implode( ', ', $data['item_names'] ) );
						} else {
							echo esc_html__( 'Intero ordine', 'wayt-recesso' );
						}
						?>
					</td></tr>
					<?php if ( '' !== $data['reason'] ) : ?>
						<tr><th><?php echo esc_html__( 'Motivo', 'wayt-recesso' ); ?></th><td><?php echo esc_html( $data['reason'] ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>

			<p><?php echo esc_html__( 'Premendo "Conferma recesso" invii la dichiarazione. Riceverai un avviso di ricevimento su supporto durevole con data e ora.', 'wayt-recesso' ); ?></p>

			<?php
			// Ripropone i dati in hidden.
			wp_nonce_field( 'wayt_recesso_confirm', 'wayt_confirm_nonce' );
			echo '<input type="hidden" name="wayt_stage" value="confirm">';
			echo '<input type="hidden" name="wayt_order_id" value="' . esc_attr( (string) $order->get_id() ) . '">';
			echo '<input type="hidden" name="wayt_order_key" value="' . esc_attr( $order->get_order_key() ) . '">';
			echo '<input type="hidden" name="wayt_name" value="' . esc_attr( $data['name'] ) . '">';
			echo '<input type="hidden" name="wayt_confirm_email" value="' . esc_attr( $data['email'] ) . '">';
			echo '<input type="hidden" name="wayt_scope" value="' . esc_attr( $data['scope'] ) . '">';
			echo '<input type="hidden" name="wayt_reason" value="' . esc_attr( $data['reason'] ) . '">';
			foreach ( $data['items'] as $iid ) {
				echo '<input type="hidden" name="wayt_items[]" value="' . esc_attr( $iid ) . '">';
			}
			$token = wp_generate_password( 20, false );
			echo '<input type="hidden" name="wayt_form_token" value="' . esc_attr( $token ) . '">';
			?>
			<p>
				<button type="submit" class="button wayt-recesso-confirm-btn" style="background:#1f6feb;color:#fff;font-weight:600;">
					<?php echo esc_html( (string) $this->opt( 'confirm_label', __( 'Conferma recesso', 'wayt-recesso' ) ) ); ?>
				</button>
			</p>
		</form>
		<?php
	}

	/**
	 * Raccoglie e sanitizza i dati postati (usato in confirm e process).
	 *
	 * @return array<string,mixed>
	 */
	private function collect_posted_data(): array {
		// Nonce gia' verificato dai chiamanti (render_confirm_step/process_confirm) prima dell'invocazione; l'order_key viene comunque ricontrollato sotto.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$order_id  = isset( $_POST['wayt_order_id'] ) ? absint( wp_unslash( $_POST['wayt_order_id'] ) ) : 0;
		$order_key = isset( $_POST['wayt_order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['wayt_order_key'] ) ) : '';
		$order     = $order_id ? wc_get_order( $order_id ) : null;

		// Sicurezza: l'order_key deve combaciare.
		if ( $order instanceof WC_Order && ! hash_equals( (string) $order->get_order_key(), $order_key ) ) {
			$order = null;
		}

		$name  = isset( $_POST['wayt_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wayt_name'] ) ) : '';
		$email = isset( $_POST['wayt_confirm_email'] ) ? sanitize_email( wp_unslash( $_POST['wayt_confirm_email'] ) ) : '';
		$scope = isset( $_POST['wayt_scope'] ) && 'partial' === $_POST['wayt_scope'] ? 'partial' : 'full';

		// Motivo: dropdown configurabile oppure campo libero. Il valore del menu
		// e' accettato solo se corrisponde a un motivo realmente configurato
		// (o a "Altro"); altrimenti si ricade sul testo libero.
		$reason_select = isset( $_POST['wayt_reason_select'] ) ? sanitize_text_field( wp_unslash( $_POST['wayt_reason_select'] ) ) : '';
		$reason_free   = isset( $_POST['wayt_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wayt_reason'] ) ) : '';
		if ( '__other__' === $reason_select ) {
			$reason = $reason_free;
		} elseif ( '' !== $reason_select && in_array( $reason_select, $this->get_reasons_list(), true ) ) {
			$reason = $reason_select;
		} else {
			$reason = $reason_free;
		}

		$items      = [];
		$item_names = [];
		if ( 'partial' === $scope && isset( $_POST['wayt_items'] ) && is_array( $_POST['wayt_items'] ) && $order instanceof WC_Order ) {
			$order_items = $order->get_items();
			foreach ( wp_unslash( $_POST['wayt_items'] ) as $iid ) {
				$iid = absint( $iid );
				if ( ! isset( $order_items[ $iid ] ) ) {
					continue;
				}
				// Difesa in profondita': un articolo escluso (art. 59) non e' recedibile.
				$pid = ( $order_items[ $iid ] instanceof WC_Order_Item_Product ) ? $order_items[ $iid ]->get_product_id() : 0;
				if ( $pid && $this->is_item_excluded( $pid ) ) {
					continue;
				}
				$items[]      = $iid;
				$item_names[] = $order_items[ $iid ]->get_name() . ' × ' . $order_items[ $iid ]->get_quantity();
			}
			if ( empty( $items ) ) {
				// Nessun item valido selezionato -> trattato come intero ordine.
				$scope = 'full';
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return [
			'order'      => $order,
			'name'       => $name,
			'email'      => $email,
			'scope'      => $scope,
			'reason'     => $reason,
			'items'      => $items,
			'item_names' => $item_names,
		];
	}

	/**
	 * Step finale: registra il recesso, invia avviso di ricevimento, notifica merchant.
	 */
	private function process_confirm(): void {
		if ( ! isset( $_POST['wayt_confirm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wayt_confirm_nonce'] ) ), 'wayt_recesso_confirm' ) ) {
			wc_print_notice( __( 'Sessione scaduta, riprova.', 'wayt-recesso' ), 'error' );
			return;
		}

		// Idempotenza: blocca doppio invio dello stesso form.
		$form_token = isset( $_POST['wayt_form_token'] ) ? sanitize_text_field( wp_unslash( $_POST['wayt_form_token'] ) ) : '';
		if ( '' !== $form_token ) {
			$lock_key = 'wayt_recesso_lock_' . md5( $form_token );
			if ( get_transient( $lock_key ) ) {
				wc_print_notice( __( 'Questa richiesta di recesso risulta gia\' inviata.', 'wayt-recesso' ), 'notice' );
				return;
			}
			set_transient( $lock_key, 1, HOUR_IN_SECONDS );
		}

		$data  = $this->collect_posted_data();
		$order = $data['order'];

		if ( ! $order instanceof WC_Order ) {
			wc_print_notice( __( 'Ordine non valido.', 'wayt-recesso' ), 'error' );
			return;
		}
		if ( ! $this->is_eligible( $order ) ) {
			wc_print_notice( __( 'Il periodo di recesso per questo ordine non e\' attivo.', 'wayt-recesso' ), 'error' );
			return;
		}
		// Idempotenza: un recesso totale gia' registrato per l'ordine blocca nuove richieste.
		if ( $this->already_withdrawn( $order->get_id() ) ) {
			wc_print_notice( __( 'Per questo ordine risulta gia\' registrato un recesso.', 'wayt-recesso' ), 'notice' );
			return;
		}
		if ( '' === $data['name'] || ! is_email( $data['email'] ) ) {
			wc_print_notice( __( 'Dati incompleti: nome ed email sono obbligatori.', 'wayt-recesso' ), 'error' );
			return;
		}

		// Lock per-ordine: serializza richieste concorrenti ed evita il doppio invio
		// quando il token di form e' stato rigenerato da un nuovo render della pagina.
		$proc_lock = 'wayt_recesso_proc_' . $order->get_id();
		if ( get_transient( $proc_lock ) ) {
			wc_print_notice( __( 'Richiesta di recesso gia\' in elaborazione: attendi qualche istante e ricarica la pagina.', 'wayt-recesso' ), 'notice' );
			return;
		}
		set_transient( $proc_lock, 1, MINUTE_IN_SECONDS );

		$now_local = new DateTimeImmutable( 'now', wp_timezone() );
		$now_gmt   = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		$declaration = $this->build_declaration_text( $order, $data, $now_local );

		// PDF attestato (opzionale): generato su file temporaneo per l'allegato email.
		$pdf_path = '';
		if ( 'yes' === $this->opt( 'pdf_enabled', 'yes' ) ) {
			$pdf_path = $this->generate_pdf_file( $order, $data, $declaration, $now_local );
		}

		// Salva nel registro (audit).
		global $wpdb;
		$table = $wpdb->prefix . WAYT_RECESSO_TABLE;
		$token = wp_generate_password( 32, false );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			$table,
			[
				'order_id'       => $order->get_id(),
				'order_number'   => $order->get_order_number(),
				'consumer_name'  => $data['name'],
				'consumer_email' => $data['email'],
				'scope'          => $data['scope'],
				'items'          => wp_json_encode( $data['items'] ),
				'reason'         => $data['reason'],
				'declaration'    => $declaration,
				'status'         => 'ricevuto',
				'ip'             => $this->get_ip(),
				'token'          => $token,
				'created_at'     => $now_local->format( 'Y-m-d H:i:s' ),
				'created_at_gmt' => $now_gmt->format( 'Y-m-d H:i:s' ),
				// Backstop anti-doppione a livello DB: valorizzato solo per il recesso
				// totale (UNIQUE), NULL per i parziali (i NULL non collidono).
				'full_order_id'  => ( 'full' === $data['scope'] ) ? $order->get_id() : null,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
		);

		// Race persa: il UNIQUE su full_order_id ha rifiutato un secondo recesso
		// totale per lo stesso ordine (il lock a transient non e' atomico: questo e'
		// il vero backstop). Si esce con lo stesso avviso del controllo a monte.
		if ( false === $inserted && 'full' === $data['scope'] ) {
			$this->withdrawn_cache = [];
			if ( $this->already_withdrawn( $order->get_id() ) ) {
				delete_transient( $proc_lock );
				wc_print_notice( __( 'Per questo ordine risulta gia\' registrato un recesso.', 'wayt-recesso' ), 'notice' );
				return;
			}
		}

		$request_id = $inserted ? (int) $wpdb->insert_id : 0;

		// Invalida la cache: un'eventuale ri-verifica nella stessa richiesta
		// (es. hook wayt_recesso/confermato) deve vedere il nuovo record.
		unset( $this->withdrawn_cache[ $order->get_id() ] );

		// Nota ordine.
		$note = sprintf(
			/* translators: 1: scope, 2: data/ora */
			__( 'Recesso esercitato dal cliente tramite funzione online (art. 54-bis). Oggetto: %1$s. Trasmesso il %2$s.', 'wayt-recesso' ),
			'partial' === $data['scope'] ? __( 'parziale', 'wayt-recesso' ) : __( 'intero ordine', 'wayt-recesso' ),
			$now_local->format( 'd/m/Y H:i:s' )
		);
		$order->add_order_note( $note );

		// Cambio stato (configurabile).
		$set_status = (string) $this->opt( 'set_status', WAYT_RECESSO_STATUS );
		if ( 'none' !== $set_status && in_array( $set_status, [ WAYT_RECESSO_STATUS, 'on-hold' ], true ) ) {
			$order->update_status( $set_status, '', true );
		}

		// Meta marcatore.
		$order->update_meta_data( '_wayt_recesso', 'yes' );
		$order->update_meta_data( '_wayt_recesso_date', $now_gmt->format( 'Y-m-d H:i:s' ) );
		$order->save();

		// Rimborso automatico (opt-in, default disattivato).
		$refund_id = $this->maybe_refund( $order, $data );
		if ( $refund_id ) {
			$order->add_order_note(
				sprintf(
					/* translators: %d: id rimborso */
					__( 'Rimborso automatico creato in seguito al recesso (rimborso #%d).', 'wayt-recesso' ),
					$refund_id
				)
			);
		}

		// Avviso di ricevimento su supporto durevole (comma 6), con eventuale PDF allegato.
		$attachments = ( '' !== $pdf_path && file_exists( $pdf_path ) ) ? [ $pdf_path ] : [];
		$ack_ok      = $this->send_acknowledgement( $order, $data, $declaration, $now_local, $attachments );
		if ( $ack_ok && $request_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$table,
				[ 'ack_sent_at' => $now_local->format( 'Y-m-d H:i:s' ) ],
				[ 'id' => $request_id ],
				[ '%s' ],
				[ '%d' ]
			);
		}

		// Pulizia del PDF temporaneo (per l'admin viene rigenerato on-demand dal registro).
		if ( '' !== $pdf_path && file_exists( $pdf_path ) ) {
			wp_delete_file( $pdf_path );
		}

		// Notifica merchant.
		$this->notify_merchant( $order, $data, $declaration );

		/**
		 * Hook estensibilita': recesso confermato.
		 *
		 * @param int                 $request_id ID record audit.
		 * @param WC_Order            $order      Ordine.
		 * @param array<string,mixed> $data       Dati dichiarazione.
		 */
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- namespacing intenzionale con "/" per gli hook pubblici del plugin.
		do_action( 'wayt_recesso/confermato', $request_id, $order, $data );

		// Elaborazione completata: rilascia il lock (per i recessi totali subentra
		// comunque already_withdrawn() a bloccare ulteriori invii).
		delete_transient( $proc_lock );

		// Esito. Il bordo diventa ambra se l'avviso email non e' partito.
		$ok_border = $ack_ok ? '#2e7d32' : '#b8860b';
		$ok_bg     = $ack_ok ? '#edf7ed' : '#fff8e6';
		echo '<div class="wayt-recesso-success" style="padding:1.25em;border:1px solid ' . esc_attr( $ok_border ) . ';border-radius:8px;background:' . esc_attr( $ok_bg ) . ';">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'Recesso registrato', 'wayt-recesso' ) . '</h2>';
		if ( $ack_ok ) {
			echo '<p>' . sprintf(
				/* translators: %s: email */
				esc_html__( 'Abbiamo ricevuto la tua dichiarazione di recesso. Un avviso di ricevimento con data e ora di trasmissione e\' stato inviato a %s.', 'wayt-recesso' ),
				'<strong>' . esc_html( $data['email'] ) . '</strong>'
			) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Abbiamo registrato la tua dichiarazione di recesso, ma non e\' stato possibile inviare l\'avviso via email in questo momento. La registrazione resta valida: conserva o stampa questa pagina come ricevuta e, se non ricevi l\'avviso a breve, contatta il venditore.', 'wayt-recesso' ) . '</p>';
		}
		echo '<p>' . sprintf(
			/* translators: %s: data ora */
			esc_html__( 'Data e ora di trasmissione: %s', 'wayt-recesso' ),
			'<strong>' . esc_html( $now_local->format( 'd/m/Y H:i:s' ) ) . '</strong>'
		) . '</p>';
		echo '</div>';
	}

	/**
	 * Costruisce il testo della dichiarazione (snapshot per supporto durevole).
	 *
	 * @param WC_Order            $order Ordine.
	 * @param array<string,mixed> $data  Dati.
	 * @param DateTimeImmutable   $when  Data/ora.
	 * @return string
	 */
	private function build_declaration_text( WC_Order $order, array $data, DateTimeImmutable $when ): string {
		$lines   = [];
		$lines[] = __( 'Dichiarazione di recesso (art. 54-bis Codice del Consumo)', 'wayt-recesso' );
		/* translators: %s: numero ordine. */
		$lines[] = sprintf( __( 'Ordine: #%s', 'wayt-recesso' ), $order->get_order_number() );
		/* translators: %s: data dell'ordine. */
		$lines[] = sprintf( __( 'Data ordine: %s', 'wayt-recesso' ), wc_format_datetime( $order->get_date_created() ) );
		/* translators: %s: nome del consumatore. */
		$lines[] = sprintf( __( 'Consumatore: %s', 'wayt-recesso' ), $data['name'] );
		/* translators: %s: email per la conferma. */
		$lines[] = sprintf( __( 'Email per la conferma: %s', 'wayt-recesso' ), $data['email'] );

		if ( 'partial' === $data['scope'] && ! empty( $data['item_names'] ) ) {
			$lines[] = __( 'Oggetto: recesso parziale dai seguenti articoli:', 'wayt-recesso' );
			foreach ( $data['item_names'] as $n ) {
				$lines[] = ' - ' . $n;
			}
		} else {
			$lines[] = __( 'Oggetto: recesso dall\'intero ordine.', 'wayt-recesso' );
		}

		if ( '' !== $data['reason'] ) {
			/* translators: %s: motivo (facoltativo) indicato dal consumatore. */
			$lines[] = sprintf( __( 'Motivo (facoltativo): %s', 'wayt-recesso' ), $data['reason'] );
		}
		/* translators: %s: data e ora di trasmissione. */
		$lines[] = sprintf( __( 'Trasmesso il: %s', 'wayt-recesso' ), $when->format( 'd/m/Y H:i:s' ) );

		return implode( "\n", $lines );
	}

	/*
	 * EMAIL
	 */

	/**
	 * Invia l'avviso di ricevimento su supporto durevole al consumatore.
	 *
	 * @param WC_Order            $order       Ordine.
	 * @param array<string,mixed> $data        Dati.
	 * @param string              $declaration Testo dichiarazione.
	 * @param DateTimeImmutable   $when        Data/ora.
	 * @param array               $attachments Allegati (percorsi file) all'avviso.
	 * @return bool
	 */
	private function send_acknowledgement( WC_Order $order, array $data, string $declaration, DateTimeImmutable $when, array $attachments = [] ): bool {
		$subject = str_replace( '{order_number}', $order->get_order_number(), (string) $this->opt( 'email_subject', '' ) );
		$heading = (string) $this->opt( 'email_heading', '' );

		$intro = trim( (string) $this->opt( 'email_body', '' ) );
		if ( '' === $intro ) {
			$intro = __( 'Il presente messaggio costituisce avviso di ricevimento della tua dichiarazione di recesso su supporto durevole, ai sensi dell\'art. 54-bis del Codice del Consumo.', 'wayt-recesso' );
		}

		$body  = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;max-width:600px;">';
		$body .= '<h2>' . esc_html( $heading ) . '</h2>';
		$body .= '<p>' . esc_html( $intro ) . '</p>';
		$body .= '<div style="background:#f6f6f6;border:1px solid #e0e0e0;border-radius:6px;padding:12px 16px;white-space:pre-line;">' . esc_html( $declaration ) . '</div>';
		$body .= '<p style="margin-top:16px;"><strong>' . esc_html__( 'Data e ora di trasmissione:', 'wayt-recesso' ) . '</strong> ' . esc_html( $when->format( 'd/m/Y H:i:s' ) ) . '</p>';
		$body .= '<p style="color:#666;font-size:12px;">' . esc_html( get_bloginfo( 'name' ) ) . '</p>';
		$body .= '</div>';

		$headers   = [ 'Content-Type: text/html; charset=UTF-8' ];
		$from_name = trim( (string) $this->opt( 'email_from_name', '' ) );
		if ( '' !== $from_name ) {
			$from_email = get_option( 'woocommerce_email_from_address' );
			if ( ! is_email( $from_email ) ) {
				$from_email = get_option( 'admin_email' );
			}
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );
		}
		$reply_to = trim( (string) $this->opt( 'email_reply_to', '' ) );
		if ( '' !== $reply_to && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		/**
		 * Allegati all'avviso di ricevimento (es. PDF). Il PDF attestato, se attivo,
		 * e' gia' incluso in $attachments; il filtro consente di aggiungerne altri.
		 *
		 * @param array               $attachments Percorsi file.
		 * @param WC_Order            $order       Ordine.
		 * @param array<string,mixed> $data        Dati.
		 */
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- namespacing intenzionale con "/" per gli hook pubblici del plugin.
		$attachments = apply_filters( 'wayt_recesso/attachments', $attachments, $order, $data );
		$attachments = array_values( array_filter( (array) $attachments ) );

		// Usa il mailer di WooCommerce per coerenza di template/branding quando possibile.
		$mailer = function_exists( 'WC' ) && WC()->mailer() ? WC()->mailer() : null;
		if ( $mailer ) {
			$wrapped = $mailer->wrap_message( $heading, $body );
			return (bool) $mailer->send( $data['email'], $subject, $wrapped, $headers, $attachments );
		}
		return (bool) wp_mail( $data['email'], $subject, $body, $headers, $attachments );
	}

	/**
	 * Notifica il merchant.
	 *
	 * @param WC_Order            $order       Ordine.
	 * @param array<string,mixed> $data        Dati.
	 * @param string              $declaration Dichiarazione.
	 */
	private function notify_merchant( WC_Order $order, array $data, string $declaration ): void {
		$to = (string) $this->opt( 'merchant_email', get_option( 'admin_email' ) );
		if ( ! is_email( $to ) ) {
			return;
		}
		$subject = sprintf(
			/* translators: %s: numero ordine */
			__( '[Recesso] Nuova richiesta per ordine #%s', 'wayt-recesso' ),
			$order->get_order_number()
		);
		$body  = '<p>' . esc_html__( 'E\' stata registrata una richiesta di recesso online.', 'wayt-recesso' ) . '</p>';
		$body .= '<div style="white-space:pre-line;background:#f6f6f6;padding:12px;border-radius:6px;">' . esc_html( $declaration ) . '</div>';
		$body .= '<p><a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html__( 'Apri l\'ordine', 'wayt-recesso' ) . '</a></p>';

		wp_mail( $to, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
	}

	/*
	 * UTILITY
	 */

	/**
	 * IP del client (best-effort, per audit).
	 *
	 * @return string
	 */
	private function get_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return $ip;
	}

	/**
	 * Stili minimali (una sola volta).
	 */
	private function print_styles_once(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		$accent = (string) $this->opt( 'accent_color', '#111111' );
		if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $accent ) ) {
			$accent = '#111111';
		}
		// Contrasto (WCAG): testo scuro su accent chiaro, testo chiaro su accent scuro.
		$r           = (int) hexdec( substr( $accent, 1, 2 ) );
		$g           = (int) hexdec( substr( $accent, 3, 2 ) );
		$b           = (int) hexdec( substr( $accent, 5, 2 ) );
		$accent_text = ( ( $r * 0.299 + $g * 0.587 + $b * 0.114 ) > 150 ) ? '#111111' : '#ffffff';
		$custom      = (string) $this->opt( 'custom_css', '' );

		echo '<style>
		.wayt-recesso-form{--wayt-accent:' . esc_html( $accent ) . ';max-width:600px}
		.wayt-recesso-form .form-row{margin-bottom:1em;display:flex;flex-direction:column}
		.wayt-recesso-form label{font-weight:600;margin-bottom:.25em}
		.wayt-recesso-form input[type=text],.wayt-recesso-form input[type=email],.wayt-recesso-form textarea,.wayt-recesso-form select{padding:.55em;border:1px solid #ccc;border-radius:5px;width:100%}
		.wayt-recesso-form small{color:#666;font-size:.85em;margin-top:.25em}
		.wayt-recesso-btn,.wayt-recesso-confirm-btn{cursor:pointer}
		.wayt-recesso-confirm-btn{background:var(--wayt-accent)!important;border-color:var(--wayt-accent)!important;color:' . esc_html( $accent_text ) . '!important}
		.wayt-recesso-info{border-left:4px solid ' . esc_html( $accent ) . ';padding:.5em 1em;background:#fafafa;margin:1em 0}
		</style>';

		if ( '' !== trim( $custom ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS depurato da sanitize_css() (rimuove < > e i tag style); impostato solo da admin con manage_woocommerce.
			echo '<style>' . $this->sanitize_css( $custom ) . '</style>';
		}
	}

	/*
	 * ADMIN
	 */

	/**
	 * Link rapidi nella lista plugin.
	 *
	 * @param array<int,string> $links Link.
	 * @return array<int,string>
	 */
	public function plugin_action_links( array $links ): array {
		$url = admin_url( 'admin.php?page=wayt-recesso&tab=settings' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Impostazioni', 'wayt-recesso' ) . '</a>' );
		return $links;
	}

	/**
	 * Menu admin sotto WooCommerce.
	 */
	public function admin_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Recesso Online', 'wayt-recesso' ),
			__( 'Recesso Online', 'wayt-recesso' ),
			'manage_woocommerce',
			'wayt-recesso',
			[ $this, 'render_admin_page' ]
		);
	}

	/**
	 * Registra le settings.
	 */
	public function register_settings(): void {
		register_setting(
			'wayt_recesso_settings_group',
			WAYT_RECESSO_OPTION,
			[ 'sanitize_callback' => [ $this, 'sanitize_settings' ] ]
		);
	}

	/**
	 * Sanitizza le settings.
	 *
	 * @param mixed $input Input.
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( $input ): array {
		$out      = self::default_options();
		$existing = get_option( WAYT_RECESSO_OPTION, [] );
		if ( is_array( $existing ) ) {
			$out = wp_parse_args( $existing, $out );
		}
		if ( ! is_array( $input ) ) {
			return $out;
		}

		$out['withdrawal_days']        = isset( $input['withdrawal_days'] ) ? max( 1, absint( $input['withdrawal_days'] ) ) : 14;
		$out['start_trigger']          = in_array( $input['start_trigger'] ?? '', [ 'date_completed', 'date_paid', 'date_created', 'delivery_meta' ], true ) ? $input['start_trigger'] : 'date_completed';
		$out['delivery_meta_key']      = isset( $input['delivery_meta_key'] ) ? sanitize_text_field( $input['delivery_meta_key'] ) : '_delivery_date';
		$out['button_label']           = isset( $input['button_label'] ) ? sanitize_text_field( $input['button_label'] ) : $out['button_label'];
		$out['confirm_label']          = isset( $input['confirm_label'] ) ? sanitize_text_field( $input['confirm_label'] ) : $out['confirm_label'];
		$out['merchant_email']         = isset( $input['merchant_email'] ) && is_email( $input['merchant_email'] ) ? sanitize_email( $input['merchant_email'] ) : get_option( 'admin_email' );
		$out['enable_reason']          = ( isset( $input['enable_reason'] ) && 'yes' === $input['enable_reason'] ) ? 'yes' : 'no';
		$out['enable_partial']         = ( isset( $input['enable_partial'] ) && 'yes' === $input['enable_partial'] ) ? 'yes' : 'no';
		$out['checkout_notice']        = ( isset( $input['checkout_notice'] ) && 'yes' === $input['checkout_notice'] ) ? 'yes' : 'no';
		$out['checkout_blocks_notice'] = ( isset( $input['checkout_blocks_notice'] ) && 'yes' === $input['checkout_blocks_notice'] ) ? 'yes' : 'no';
		$out['pdf_enabled']            = ( isset( $input['pdf_enabled'] ) && 'yes' === $input['pdf_enabled'] ) ? 'yes' : 'no';
		$out['auto_refund']            = in_array( $input['auto_refund'] ?? '', [ 'off', 'items', 'full' ], true ) ? $input['auto_refund'] : 'off';
		$out['reasons_list']           = isset( $input['reasons_list'] ) ? sanitize_textarea_field( $input['reasons_list'] ) : '';
		$out['excluded_products']      = isset( $input['excluded_products'] ) ? sanitize_textarea_field( $input['excluded_products'] ) : '';
		$out['excluded_categories']    = isset( $input['excluded_categories'] ) ? sanitize_textarea_field( $input['excluded_categories'] ) : '';
		$out['email_from_name']        = isset( $input['email_from_name'] ) ? sanitize_text_field( $input['email_from_name'] ) : '';
		$out['email_reply_to']         = ( isset( $input['email_reply_to'] ) && is_email( $input['email_reply_to'] ) ) ? sanitize_email( $input['email_reply_to'] ) : '';
		$out['email_body']             = isset( $input['email_body'] ) ? sanitize_textarea_field( $input['email_body'] ) : '';
		$out['accent_color']           = ( isset( $input['accent_color'] ) && preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $input['accent_color'] ) ) ? $input['accent_color'] : '#111111';
		$out['custom_css']             = isset( $input['custom_css'] ) ? $this->sanitize_css( (string) $input['custom_css'] ) : '';
		$out['purge_on_uninstall']     = ( isset( $input['purge_on_uninstall'] ) && 'yes' === $input['purge_on_uninstall'] ) ? 'yes' : 'no';
		$out['set_status']             = in_array( $input['set_status'] ?? '', [ WAYT_RECESSO_STATUS, 'on-hold', 'none' ], true ) ? $input['set_status'] : WAYT_RECESSO_STATUS;
		$out['email_subject']          = isset( $input['email_subject'] ) ? sanitize_text_field( $input['email_subject'] ) : $out['email_subject'];
		$out['email_heading']          = isset( $input['email_heading'] ) ? sanitize_text_field( $input['email_heading'] ) : $out['email_heading'];
		$out['precontractual_text']    = isset( $input['precontractual_text'] ) ? sanitize_textarea_field( $input['precontractual_text'] ) : $out['precontractual_text'];
		$out['modulo_tipo_url']        = isset( $input['modulo_tipo_url'] ) ? esc_url_raw( $input['modulo_tipo_url'] ) : '';
		$out['recesso_page_id']        = isset( $input['recesso_page_id'] ) ? absint( $input['recesso_page_id'] ) : (int) ( $existing['recesso_page_id'] ?? 0 );

		$statuses = [];
		if ( isset( $input['eligible_statuses'] ) && is_array( $input['eligible_statuses'] ) ) {
			$valid = array_keys( wc_get_order_statuses() );
			foreach ( $input['eligible_statuses'] as $s ) {
				$s = sanitize_key( $s );
				if ( in_array( 'wc-' . $s, $valid, true ) || in_array( $s, [ 'processing', 'completed', 'on-hold' ], true ) ) {
					$statuses[] = $s;
				}
			}
		}
		$out['eligible_statuses'] = ! empty( $statuses ) ? $statuses : [ 'processing', 'completed' ];

		// Reset cache.
		$this->opts = null;
		return $out;
	}

	/**
	 * Render pagina admin (tab: richieste / impostazioni / conformita').
	 */
	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sola selezione di tab in lettura; pagina protetta da manage_woocommerce.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'requests';
		echo '<div class="wrap"><h1>' . esc_html__( 'Recesso Online (art. 54-bis)', 'wayt-recesso' ) . '</h1>';

		$tabs = [
			'requests'   => __( 'Richieste', 'wayt-recesso' ),
			'settings'   => __( 'Impostazioni', 'wayt-recesso' ),
			'compliance' => __( 'Conformità', 'wayt-recesso' ),
		];
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab %2$s">%3$s</a>',
				esc_url( admin_url( 'admin.php?page=wayt-recesso&tab=' . $key ) ),
				$tab === $key ? 'nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</h2>';

		if ( 'settings' === $tab ) {
			$this->render_settings_tab();
		} elseif ( 'compliance' === $tab ) {
			$this->render_compliance_tab();
		} else {
			$this->render_requests_tab();
		}
		echo '</div>';
	}

	/**
	 * Tab impostazioni.
	 */
	private function render_settings_tab(): void {
		$o = get_option( WAYT_RECESSO_OPTION, self::default_options() );
		$o = wp_parse_args( is_array( $o ) ? $o : [], self::default_options() );
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'wayt_recesso_settings_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="withdrawal_days"><?php esc_html_e( 'Giorni di recesso', 'wayt-recesso' ); ?></label></th>
					<td><input type="number" min="1" id="withdrawal_days" name="<?php echo esc_attr( WAYT_RECESSO_OPTION ); ?>[withdrawal_days]" value="<?php echo esc_attr( $o['withdrawal_days'] ); ?>"> <span class="description"><?php esc_html_e( 'Default di legge: 14.', 'wayt-recesso' ); ?></span></td>
				</tr>
				<tr>
					<th><label for="start_trigger"><?php esc_html_e( 'Da quando decorre', 'wayt-recesso' ); ?></label></th>
					<td>
						<select id="start_trigger" name="<?php echo esc_attr( WAYT_RECESSO_OPTION ); ?>[start_trigger]">
							<?php
							$triggers = [
								'date_completed' => __( 'Ordine completato', 'wayt-recesso' ),
								'date_paid'      => __( 'Pagamento ricevuto', 'wayt-recesso' ),
								'date_created'   => __( 'Creazione ordine', 'wayt-recesso' ),
								'delivery_meta'  => __( 'Data consegna (meta)', 'wayt-recesso' ),
							];
							foreach ( $triggers as $k => $lbl ) {
								printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $o['start_trigger'], $k, false ), esc_html( $lbl ) );
							}
							?>
						</select>
						<p class="description"><?php esc_html_e( 'Per i beni il termine decorre dal ricevimento. Se usi un campo "data consegna", indica la meta key qui sotto.', 'wayt-recesso' ); ?></p>
						<input type="text" name="<?php echo esc_attr( WAYT_RECESSO_OPTION ); ?>[delivery_meta_key]" value="<?php echo esc_attr( $o['delivery_meta_key'] ); ?>" placeholder="_delivery_date">
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Stati ordine eleggibili', 'wayt-recesso' ); ?></th>
					<td>
						<?php
						$all = wc_get_order_statuses();
						foreach ( $all as $slug => $label ) {
							$clean = str_replace( 'wc-', '', $slug );
							if ( in_array( $clean, [ WAYT_RECESSO_STATUS, 'cancelled', 'refunded', 'failed', 'pending', 'trash' ], true ) ) {
								continue;
							}
							printf(
								'<label style="display:block;"><input type="checkbox" name="%1$s[eligible_statuses][]" value="%2$s" %3$s> %4$s</label>',
								esc_attr( WAYT_RECESSO_OPTION ),
								esc_attr( $clean ),
								checked( in_array( $clean, (array) $o['eligible_statuses'], true ), true, false ),
								esc_html( $label )
							);
						}
						?>
					</td>
				</tr>
				<tr>
					<th><label for="button_label"><?php esc_html_e( 'Etichetta pulsante', 'wayt-recesso' ); ?></label></th>
					<td><input type="text" id="button_label" class="regular-text" name="<?php echo esc_attr( WAYT_RECESSO_OPTION ); ?>[button_label]" value="<?php echo esc_attr( $o['button_label'] ); ?>"> <p class="description"><?php esc_html_e( 'La norma richiede "Recedere dal contratto qui" o formula equivalente.', 'wayt-recesso' ); ?></p></td>
				</tr>
				<tr>
					<th><label for="confirm_label"><?php esc_html_e( 'Etichetta conferma', 'wayt-recesso' ); ?></label></th>
					<td><input type="text" id="confirm_label" class="regular-text" name="<?php echo esc_attr( WAYT_RECESSO_OPTION ); ?>[confirm_label]" value="<?php echo esc_attr( $o['confirm_label'] ); ?>"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Opzioni form', 'wayt-recesso' ); ?></th>
					<td>
						<label style="display:block;"><input type="checkbox" name="<?php echo esc_attr( WAYT_RECESSO_OPTION ); ?>[enable_partial]" value="yes" <?php checked( $o['enable_partial'], 'yes' ); ?>> <?php esc_html_e( 'Consenti recesso parziale (selezione articoli)', 'wayt-recesso' ); ?></label>
						<label style="display:block;"><input type="checkbox" name="<?php echo esc_attr( WAYT_RECESSO_OPTION ); ?>[enable_reason]" value="yes" <?php checked( $o['enable_reason'], 'yes' ); ?>> <?php esc_html_e( 'Mostra campo motivo (sempre facoltativo)', 'wayt-recesso' ); ?></label>
						<label style="display:block;"><input type="checkbox" name="<?php echo esc_attr( WAYT_RECESSO_OPTION ); ?>[checkout_notice]" value="yes" <?php checked( $o['checkout_notice'], 'yes' ); ?>> <?php esc_html_e( 'Mostra nota precontrattuale al checkout (classico)', 'wayt-recesso' ); ?></label>
						<label style="display:block;"><input type="checkbox" name="<?php echo esc_attr( WAYT_RECESSO_OPTION ); ?>[checkout_blocks_notice]" value="yes" <?php checked( $o['checkout_blocks_notice'], 'yes' ); ?>> <?php esc_html_e( 'Mostra nota precontrattuale al checkout a blocchi', 'wayt-recesso' ); ?></label>
					</td>
				</tr>
				<tr>
					<th><label for="set_status"><?php esc_html_e( 'Stato dopo il recesso', 'wayt-recesso' ); ?></label></th>
					<td>
						<select id="set_status" name="<?php echo esc_attr( WAYT_RECESSO_OPTION ); ?>[set_status]">
							<option value="<?php echo esc_attr( WAYT_RECESSO_STATUS ); ?>" <?php selected( $o['set_status'], WAYT_RECESSO_STATUS ); ?>><?php esc_html_e( 'Recesso richiesto (custom)', 'wayt-recesso' ); ?></option>
							<option value="on-hold" <?php selected( $o['set_status'], 'on-hold' ); ?>><?php esc_html_e( 'In sospeso', 'wayt-recesso' ); ?></option>
							<option value="none" <?php selected( $o['set_status'], 'none' ); ?>><?php esc_html_e( 'Non cambiare (solo nota)', 'wayt-recesso' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="merchant_email"><?php esc_html_e( 'Email notifiche merchant', 'wayt-recesso' ); ?></label></th>
					<td><input type="email" id="merchant_email" class="regular-text" name="<?php echo esc_attr( WAYT_RECESSO_OPTION ); ?>[merchant_email]" value="<?php echo esc_attr( $o['merchant_email'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="email_subject"><?php esc_html_e( 'Oggetto email cliente', 'wayt-recesso' ); ?></label></th>
					<td><input type="text" id="email_subject" class="large-text" name="<?php echo esc_attr( WAYT_RECESSO_OPTION ); ?>[email_subject]" value="<?php echo esc_attr( $o['email_subject'] ); ?>"> <p class="description"><?php esc_html_e( 'Placeholder disponibile: {order_number}', 'wayt-recesso' ); ?></p></td>
				</tr>
				<tr>
					<th><label for="email_heading"><?php esc_html_e( 'Titolo email cliente', 'wayt-recesso' ); ?></label></th>
					<td><input type="text" id="email_heading" class="large-text" name="<?php echo esc_attr( WAYT_RECESSO_OPTION ); ?>[email_heading]" value="<?php echo esc_attr( $o['email_heading'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="precontractual_text"><?php esc_html_e( 'Testo informativa precontrattuale', 'wayt-recesso' ); ?></label></th>
					<td><textarea id="precontractual_text" class="large-text" rows="4" name="<?php echo esc_attr( WAYT_RECESSO_OPTION ); ?>[precontractual_text]"><?php echo esc_textarea( $o['precontractual_text'] ); ?></textarea><p class="description"><?php esc_html_e( 'Usa lo shortcode [wayt_recesso_info] dove serve.', 'wayt-recesso' ); ?></p></td>
				</tr>
				<tr>
					<th><label for="modulo_tipo_url"><?php esc_html_e( 'URL modulo tipo di recesso', 'wayt-recesso' ); ?></label></th>
					<td><input type="url" id="modulo_tipo_url" class="large-text" name="<?php echo esc_attr( WAYT_RECESSO_OPTION ); ?>[modulo_tipo_url]" value="<?php echo esc_attr( $o['modulo_tipo_url'] ); ?>" placeholder="https://..."> <p class="description"><?php esc_html_e( 'Allegato I, parte B: resta obbligatorio oltre alla funzione online.', 'wayt-recesso' ); ?></p></td>
				</tr>
				<tr>
					<th><label for="recesso_page_id"><?php esc_html_e( 'Pagina funzione di recesso', 'wayt-recesso' ); ?></label></th>
					<td>
						<?php
						// wp_dropdown_pages() effettua internamente l'escape del markup generato.
						// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
						wp_dropdown_pages(
							[
								'name'             => WAYT_RECESSO_OPTION . '[recesso_page_id]',
								'id'               => 'recesso_page_id',
								'selected'         => (int) $o['recesso_page_id'],
								'show_option_none' => __( '— Seleziona —', 'wayt-recesso' ),
							]
						);
						// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
						<p class="description"><?php esc_html_e( 'Pagina che contiene lo shortcode [wayt_recesso].', 'wayt-recesso' ); ?></p>
					</td>
				</tr>
				<tr><th colspan="2"><hr><h2 style="margin:.2em 0;"><?php esc_html_e( 'Funzioni avanzate', 'wayt-recesso' ); ?></h2></th></tr>
				<tr>
					<th><?php esc_html_e( 'PDF attestato', 'wayt-recesso' ); ?></th>
					<td>
						<label style="display:block;"><input type="checkbox" name="<?php echo esc_attr( WAYT_RECESSO_OPTION ); ?>[pdf_enabled]" value="yes" <?php checked( $o['pdf_enabled'], 'yes' ); ?>> <?php esc_html_e( 'Allega un PDF attestato all\'avviso di ricevimento e rendilo scaricabile dal registro', 'wayt-recesso' ); ?></label>
						<p class="description"><?php esc_html_e( 'Documento generato senza servizi esterni. L\'email resta comunque un supporto durevole valido anche senza PDF.', 'wayt-recesso' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="auto_refund"><?php esc_html_e( 'Rimborso automatico', 'wayt-recesso' ); ?></label></th>
					<td>
						<select id="auto_refund" name="<?php echo esc_attr( WAYT_RECESSO_OPTION ); ?>[auto_refund]">
							<option value="off" <?php selected( $o['auto_refund'], 'off' ); ?>><?php esc_html_e( 'Disattivato (consigliato)', 'wayt-recesso' ); ?></option>
							<option value="items" <?php selected( $o['auto_refund'], 'items' ); ?>><?php esc_html_e( 'Crea rimborso per gli articoli oggetto di recesso', 'wayt-recesso' ); ?></option>
							<option value="full" <?php selected( $o['auto_refund'], 'full' ); ?>><?php esc_html_e( 'Crea rimborso totale dell\'ordine', 'wayt-recesso' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Crea un rimborso in WooCommerce alla conferma del recesso. NON contatta automaticamente il gateway di pagamento (refund_payment = false): il rimborso effettivo va processato manualmente dal pannello o dal PSP. In caso di recesso entro 14 giorni le spese di spedizione standard vanno comunque rimborsate: valuta la voce manualmente.', 'wayt-recesso' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="reasons_list"><?php esc_html_e( 'Motivi di recesso configurabili', 'wayt-recesso' ); ?></label></th>
					<td>
						<textarea id="reasons_list" class="large-text" rows="4" name="<?php echo esc_attr( WAYT_RECESSO_OPTION ); ?>[reasons_list]" placeholder="<?php echo esc_attr__( 'Una motivazione per riga…', 'wayt-recesso' ); ?>"><?php echo esc_textarea( $o['reasons_list'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Se compilato, il campo motivo diventa un menu a tendina (con opzione «Altro»). Lasciato vuoto, resta un campo di testo libero. Il motivo è sempre facoltativo.', 'wayt-recesso' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Esclusioni dal recesso (art. 59)', 'wayt-recesso' ); ?></th>
					<td>
						<p>
							<label for="excluded_products"><?php esc_html_e( 'ID prodotti esclusi', 'wayt-recesso' ); ?></label><br>
							<input type="text" id="excluded_products" class="large-text" name="<?php echo esc_attr( WAYT_RECESSO_OPTION ); ?>[excluded_products]" value="<?php echo esc_attr( $o['excluded_products'] ); ?>" placeholder="es. 1024, 1180, 1322">
						</p>
						<p>
							<label for="excluded_categories"><?php esc_html_e( 'Slug categorie escluse', 'wayt-recesso' ); ?></label><br>
							<input type="text" id="excluded_categories" class="large-text" name="<?php echo esc_attr( WAYT_RECESSO_OPTION ); ?>[excluded_categories]" value="<?php echo esc_attr( $o['excluded_categories'] ); ?>" placeholder="es. su-misura, gift-card, deperibili">
						</p>
						<p class="description"><?php esc_html_e( 'Gli articoli esclusi non sono selezionabili nel form; se l\'intero ordine è composto solo da articoli esclusi, il pulsante non viene mostrato. Puoi anche spuntare «Escluso dal recesso» nella singola scheda prodotto.', 'wayt-recesso' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="email_from_name"><?php esc_html_e( 'Mittente avviso (nome)', 'wayt-recesso' ); ?></label></th>
					<td>
						<input type="text" id="email_from_name" class="regular-text" name="<?php echo esc_attr( WAYT_RECESSO_OPTION ); ?>[email_from_name]" value="<?php echo esc_attr( $o['email_from_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Vuoto = usa il mittente predefinito di WooCommerce.', 'wayt-recesso' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="email_reply_to"><?php esc_html_e( 'Reply-To avviso', 'wayt-recesso' ); ?></label></th>
					<td><input type="email" id="email_reply_to" class="regular-text" name="<?php echo esc_attr( WAYT_RECESSO_OPTION ); ?>[email_reply_to]" value="<?php echo esc_attr( $o['email_reply_to'] ); ?>" placeholder="recesso@tuonegozio.it"></td>
				</tr>
				<tr>
					<th><label for="email_body"><?php esc_html_e( 'Testo introduttivo avviso', 'wayt-recesso' ); ?></label></th>
					<td>
						<textarea id="email_body" class="large-text" rows="3" name="<?php echo esc_attr( WAYT_RECESSO_OPTION ); ?>[email_body]"><?php echo esc_textarea( $o['email_body'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Sostituisce il paragrafo introduttivo dell\'email di avviso. Vuoto = testo predefinito (riferimento all\'art. 54-bis). La dichiarazione, la data e l\'ora vengono sempre aggiunte automaticamente.', 'wayt-recesso' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="accent_color"><?php esc_html_e( 'Colore principale', 'wayt-recesso' ); ?></label></th>
					<td><input type="color" id="accent_color" name="<?php echo esc_attr( WAYT_RECESSO_OPTION ); ?>[accent_color]" value="<?php echo esc_attr( $o['accent_color'] ); ?>"> <span class="description"><?php esc_html_e( 'Usato per il pulsante di conferma e i dettagli del form.', 'wayt-recesso' ); ?></span></td>
				</tr>
				<tr>
					<th><label for="custom_css"><?php esc_html_e( 'CSS personalizzato', 'wayt-recesso' ); ?></label></th>
					<td>
						<textarea id="custom_css" class="large-text code" rows="4" name="<?php echo esc_attr( WAYT_RECESSO_OPTION ); ?>[custom_css]" placeholder=".wayt-recesso-form{ ... }"><?php echo esc_textarea( $o['custom_css'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'CSS applicato alle pagine che contengono il form di recesso.', 'wayt-recesso' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Disinstallazione', 'wayt-recesso' ); ?></th>
					<td>
						<label style="display:block;"><input type="checkbox" name="<?php echo esc_attr( WAYT_RECESSO_OPTION ); ?>[purge_on_uninstall]" value="yes" <?php checked( $o['purge_on_uninstall'], 'yes' ); ?>> <?php esc_html_e( 'Elimina anche il registro recessi e i dati alla disinstallazione del plugin', 'wayt-recesso' ); ?></label>
						<p class="description"><?php esc_html_e( 'Sconsigliato: il registro dei recessi è una prova di conformità. Se lasciato deselezionato, alla disinstallazione vengono rimosse solo le impostazioni, mentre lo storico resta nel database.', 'wayt-recesso' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Tab richieste + export CSV.
	 */
	private function render_requests_tab(): void {
		global $wpdb;
		$table = $wpdb->prefix . WAYT_RECESSO_TABLE;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sola paginazione in lettura; pagina protetta da manage_woocommerce.
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$per_page = 25;
		$offset   = ( $paged - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome tabella da $wpdb->prefix; nessun input utente.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome tabella da $wpdb->prefix; valori via prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) );

		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=wayt_recesso_export' ), 'wayt_recesso_export' );
		echo '<p style="margin:1em 0;"><a href="' . esc_url( $export_url ) . '" class="button">' . esc_html__( 'Esporta CSV', 'wayt-recesso' ) . '</a></p>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'Nessuna richiesta di recesso registrata.', 'wayt-recesso' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Data/ora', 'wayt-recesso' ) . '</th>';
		echo '<th>' . esc_html__( 'Ordine', 'wayt-recesso' ) . '</th>';
		echo '<th>' . esc_html__( 'Cliente', 'wayt-recesso' ) . '</th>';
		echo '<th>' . esc_html__( 'Oggetto', 'wayt-recesso' ) . '</th>';
		echo '<th>' . esc_html__( 'Stato', 'wayt-recesso' ) . '</th>';
		echo '<th>' . esc_html__( 'Avviso inviato', 'wayt-recesso' ) . '</th>';
		echo '<th>' . esc_html__( 'Azioni', 'wayt-recesso' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $r ) {
			$order_obj = $r->order_id ? wc_get_order( (int) $r->order_id ) : null;
			$edit_url  = $order_obj instanceof WC_Order ? $order_obj->get_edit_order_url() : '';

			echo '<tr>';
			echo '<td>' . esc_html( mysql2date( 'd/m/Y H:i:s', $r->created_at ) ) . '</td>';
			echo '<td>' . ( $edit_url ? '<a href="' . esc_url( $edit_url ) . '">#' . esc_html( $r->order_number ) . '</a>' : '#' . esc_html( $r->order_number ) ) . '</td>';
			echo '<td>' . esc_html( $r->consumer_name ) . '<br><small>' . esc_html( $r->consumer_email ) . '</small></td>';
			echo '<td>' . ( 'partial' === $r->scope ? esc_html__( 'Parziale', 'wayt-recesso' ) : esc_html__( 'Intero ordine', 'wayt-recesso' ) ) . '</td>';
			echo '<td>' . esc_html( ucfirst( $r->status ) ) . '</td>';
			echo '<td>' . ( $r->ack_sent_at ? esc_html( mysql2date( 'd/m/Y H:i', $r->ack_sent_at ) ) : '—' ) . '</td>';
			echo '<td>';
			// Azione: marca come gestito.
			$action_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=wayt_recesso_request_action&do=handled&id=' . (int) $r->id ),
				'wayt_recesso_req_' . (int) $r->id
			);
			echo '<a href="' . esc_url( $action_url ) . '">' . esc_html__( 'Segna gestito', 'wayt-recesso' ) . '</a>';
			if ( 'yes' === $this->opt( 'pdf_enabled', 'yes' ) ) {
				$pdf_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=wayt_recesso_pdf&request=' . (int) $r->id ),
					'wayt_recesso_pdf_' . (int) $r->id
				);
				echo ' | <a href="' . esc_url( $pdf_url ) . '">' . esc_html__( 'Scarica PDF', 'wayt-recesso' ) . '</a>';
			}
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		// Paginazione semplice.
		$pages = (int) ceil( $total / $per_page );
		if ( $pages > 1 ) {
			echo '<p style="margin-top:1em;">';
			for ( $i = 1; $i <= $pages; $i++ ) {
				$url = admin_url( 'admin.php?page=wayt-recesso&tab=requests&paged=' . $i );
				printf(
					'<a href="%1$s" style="margin-right:.4em;%2$s">%3$d</a>',
					esc_url( $url ),
					esc_attr( $i === $paged ? 'font-weight:700;text-decoration:underline;' : '' ),
					(int) $i
				);
			}
			echo '</p>';
		}
	}

	/**
	 * Tab conformita': self-check anti-dark-pattern.
	 */
	private function render_compliance_tab(): void {
		$o       = wp_parse_args( get_option( WAYT_RECESSO_OPTION, [] ), self::default_options() );
		$pid     = (int) $o['recesso_page_id'];
		$page_ok = $pid && has_shortcode( (string) get_post_field( 'post_content', $pid ), 'wayt_recesso' );

		$checks = [
			[
				'label' => __( 'Pagina della funzione di recesso pubblicata con [wayt_recesso]', 'wayt-recesso' ),
				'ok'    => $page_ok,
			],
			[
				'label' => __( 'Etichetta pulsante conforme ("Recedere dal contratto qui" o equivalente)', 'wayt-recesso' ),
				'ok'    => '' !== trim( (string) $o['button_label'] ),
			],
			[
				'label' => __( 'Comando di conferma presente', 'wayt-recesso' ),
				'ok'    => '' !== trim( (string) $o['confirm_label'] ),
			],
			[
				'label' => __( 'Avviso di ricevimento su supporto durevole configurato (email cliente)', 'wayt-recesso' ),
				'ok'    => '' !== trim( (string) $o['email_subject'] ) && '' !== trim( (string) $o['email_heading'] ),
			],
			[
				'label' => __( 'Informativa precontrattuale impostata', 'wayt-recesso' ),
				'ok'    => '' !== trim( (string) $o['precontractual_text'] ),
			],
			[
				'label' => __( 'Link al modulo tipo (Allegato I parte B) impostato', 'wayt-recesso' ),
				'ok'    => '' !== trim( (string) $o['modulo_tipo_url'] ),
			],
			[
				'label' => __( 'Motivo del recesso impostato come facoltativo', 'wayt-recesso' ),
				'ok'    => true, // sempre facoltativo by design.
			],
			[
				'label' => __( 'Finestra di recesso >= 14 giorni', 'wayt-recesso' ),
				'ok'    => (int) $o['withdrawal_days'] >= 14,
			],
			[
				'label' => __( 'PDF attestato del recesso attivo (allegato su supporto durevole)', 'wayt-recesso' ),
				'ok'    => 'yes' === $o['pdf_enabled'],
			],
			[
				'label' => __( 'Eccezioni al recesso (art. 59) configurate o nessun prodotto escluso', 'wayt-recesso' ),
				'ok'    => true, // informativo: la gestione esclusioni è disponibile.
			],
		];

		echo '<p>' . esc_html__( 'Verifica rapida dei requisiti tecnici dell\'art. 54-bis. Questo non sostituisce la consulenza legale sulle condizioni generali di vendita.', 'wayt-recesso' ) . '</p>';
		echo '<table class="wp-list-table widefat striped" style="max-width:760px;"><tbody>';
		foreach ( $checks as $c ) {
			$icon = $c['ok'] ? '✅' : '⚠️';
			echo '<tr><td style="width:40px;font-size:1.2em;">' . esc_html( $icon ) . '</td><td>' . esc_html( $c['label'] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Shortcode disponibili', 'wayt-recesso' ) . '</h3>';
		echo '<ul style="list-style:disc;margin-left:1.4em;">';
		echo '<li><code>[wayt_recesso]</code> — ' . esc_html__( 'la funzione di recesso (pagina dedicata).', 'wayt-recesso' ) . '</li>';
		echo '<li><code>[wayt_recesso_info]</code> — ' . esc_html__( 'nota informativa precontrattuale + link.', 'wayt-recesso' ) . '</li>';
		echo '</ul>';
	}

	/**
	 * Neutralizza l'iniezione di formule nei CSV (Excel/Sheets/LibreOffice):
	 * premette un apice ai valori che iniziano con un carattere "attivo".
	 *
	 * @param mixed $value Valore di cella.
	 * @return string
	 */
	private function csv_safe( $value ): string {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	/**
	 * Export CSV delle richieste.
	 */
	public function handle_export_csv(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'wayt-recesso' ) );
		}
		check_admin_referer( 'wayt_recesso_export' );

		global $wpdb;
		$table = $wpdb->prefix . WAYT_RECESSO_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome tabella da $wpdb->prefix; nessun input utente.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=recessi-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'ID', 'Data/ora', 'Ordine', 'Nome', 'Email', 'Oggetto', 'Articoli', 'Motivo', 'Stato', 'IP', 'Avviso inviato' ] );
		if ( $rows ) {
			foreach ( $rows as $r ) {
				fputcsv(
					$out,
					array_map(
						[ $this, 'csv_safe' ],
						[
							$r['id'],
							$r['created_at'],
							$r['order_number'],
							$r['consumer_name'],
							$r['consumer_email'],
							$r['scope'],
							$r['items'],
							$r['reason'],
							$r['status'],
							$r['ip'],
							$r['ack_sent_at'],
						]
					)
				);
			}
		}
		fclose( $out ); // phpcs:ignore
		exit;
	}

	/**
	 * Azioni admin sulle richieste (es. segna gestito).
	 */
	public function handle_request_action(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'wayt-recesso' ) );
		}
		$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		check_admin_referer( 'wayt_recesso_req_' . $id );

		if ( $id && 'handled' === $do ) {
			global $wpdb;
			$table = $wpdb->prefix . WAYT_RECESSO_TABLE;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, [ 'status' => 'gestito' ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wayt-recesso&tab=requests' ) );
		exit;
	}

	/*
	 * v0.2.0 — HELPER: ESCLUSIONI, MOTIVI, CSS
	 */

	/**
	 * Sanitizza CSS personalizzato (impedisce la chiusura del tag style).
	 *
	 * @param string $css CSS.
	 * @return string
	 */
	private function sanitize_css( string $css ): string {
		$css = preg_replace( '#</?\s*style#i', '', (string) $css );
		$css = str_replace( [ '<', '>' ], '', (string) $css );
		return (string) $css;
	}

	/**
	 * Lista motivi configurati (una per riga). Vuoto = campo libero.
	 *
	 * @return array<int,string>
	 */
	private function get_reasons_list(): array {
		$raw = (string) $this->opt( 'reasons_list', '' );
		if ( '' === trim( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( (string) $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/**
	 * Estrae una lista di ID interi da testo separato da virgole/spazi/righe.
	 *
	 * @param string $raw Testo.
	 * @return array<int,int>
	 */
	private function parse_id_list( string $raw ): array {
		$ids   = [];
		$parts = preg_split( '/[\s,]+/', trim( $raw ) );
		foreach ( (array) $parts as $p ) {
			$p = absint( $p );
			if ( $p ) {
				$ids[] = $p;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Estrae una lista di slug da testo separato da virgole/spazi/righe.
	 *
	 * @param string $raw Testo.
	 * @return array<int,string>
	 */
	private function parse_slug_list( string $raw ): array {
		$out   = [];
		$parts = preg_split( '/[\s,]+/', trim( $raw ) );
		foreach ( (array) $parts as $p ) {
			$p = sanitize_title( (string) $p );
			if ( '' !== $p ) {
				$out[] = $p;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Verifica se un prodotto e' escluso dal recesso (art. 59).
	 *
	 * @param int $product_id ID prodotto.
	 * @return bool
	 */
	public function is_item_excluded( int $product_id ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}
		if ( ! isset( $this->excluded_cache[ $product_id ] ) ) {
			$this->excluded_cache[ $product_id ] = $this->compute_item_excluded( $product_id );
		}
		return $this->excluded_cache[ $product_id ];
	}

	/**
	 * Calcolo (non in cache) dell'esclusione di un prodotto dal recesso.
	 *
	 * @param int $product_id ID prodotto.
	 * @return bool
	 */
	private function compute_item_excluded( int $product_id ): bool {
		if ( 'yes' === get_post_meta( $product_id, '_wayt_recesso_excluded', true ) ) {
			return true;
		}
		$ids = $this->parse_id_list( (string) $this->opt( 'excluded_products', '' ) );
		if ( in_array( $product_id, $ids, true ) ) {
			return true;
		}

		// Variazioni: considera anche il prodotto padre.
		$check_id = $product_id;
		$product  = wc_get_product( $product_id );
		if ( $product && $product->get_parent_id() ) {
			$parent = (int) $product->get_parent_id();
			if ( in_array( $parent, $ids, true ) || 'yes' === get_post_meta( $parent, '_wayt_recesso_excluded', true ) ) {
				return true;
			}
			$check_id = $parent;
		}

		$cats = $this->parse_slug_list( (string) $this->opt( 'excluded_categories', '' ) );
		if ( ! empty( $cats ) && has_term( $cats, 'product_cat', $check_id ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Verifica se TUTTI gli articoli dell'ordine sono esclusi dal recesso.
	 *
	 * @param WC_Order $order Ordine.
	 * @return bool
	 */
	public function order_fully_excluded( WC_Order $order ): bool {
		$items = $order->get_items();
		if ( empty( $items ) ) {
			return false;
		}
		foreach ( $items as $item ) {
			$pid = ( $item instanceof WC_Order_Item_Product ) ? $item->get_product_id() : 0;
			if ( ! $pid ) {
				// Riga non-prodotto: non blocchiamo l'intero ordine.
				return false;
			}
			if ( ! $this->is_item_excluded( $pid ) ) {
				return false;
			}
		}
		return true;
	}

	/*
	 * v0.2.0 — PRODOTTO: CHECKBOX "ESCLUSO DAL RECESSO"
	 */

	/**
	 * Aggiunge la checkbox nella scheda prodotto (tab Generale).
	 */
	public function product_exclusion_field(): void {
		echo '<div class="options_group">';
		woocommerce_wp_checkbox(
			[
				'id'          => '_wayt_recesso_excluded',
				'label'       => __( 'Escluso dal recesso', 'wayt-recesso' ),
				'description' => __( 'Spunta se il prodotto rientra nelle eccezioni al diritto di recesso (art. 59): beni su misura, sigillati aperti, deperibili, ecc.', 'wayt-recesso' ),
				'desc_tip'    => true,
			]
		);
		echo '</div>';
	}

	/**
	 * Salva la checkbox prodotto.
	 *
	 * @param int $post_id ID prodotto.
	 */
	public function product_exclusion_save( $post_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- gestito da WooCommerce.
		$val = isset( $_POST['_wayt_recesso_excluded'] ) ? 'yes' : 'no';
		update_post_meta( (int) $post_id, '_wayt_recesso_excluded', $val );
	}

	/*
	 * v0.2.0 — CHECKOUT A BLOCCHI: NOTA PRECONTRATTUALE (lato server)
	 */

	/**
	 * Inietta la nota precontrattuale nel checkout a blocchi.
	 *
	 * @param string $content Markup del blocco.
	 * @param array  $block   Definizione blocco.
	 * @return string
	 */
	public function inject_blocks_notice( $content, $block ): string {
		// render_block scatta per ogni blocco del sito: una volta iniettata la
		// nota (o fuori contesto) si esce subito, senza ulteriori controlli.
		static $done = false;
		if ( $done || is_admin() || empty( $block['blockName'] ) ) {
			return (string) $content;
		}
		if ( 'woocommerce/checkout-actions-block' !== $block['blockName'] ) {
			return (string) $content;
		}
		if ( 'yes' !== $this->opt( 'checkout_blocks_notice', 'yes' ) ) {
			return (string) $content;
		}
		$text = trim( (string) $this->opt( 'precontractual_text', '' ) );
		if ( '' === $text ) {
			return (string) $content;
		}
		$done   = true;
		$notice = '<div class="wayt-recesso-checkout-notice" style="margin:0 0 1em;font-size:.85em;color:#555;">' . esc_html( $text ) . '</div>';
		return $notice . (string) $content;
	}

	/*
	 * v0.2.0 — PDF ATTESTATO
	 */

	/**
	 * Compone gli argomenti per il PDF attestato.
	 *
	 * @param string            $order_number Numero ordine.
	 * @param string            $order_date   Data ordine (gia' formattata) o ''.
	 * @param array             $data         Dati dichiarazione (name,email,scope).
	 * @param string            $declaration  Testo della dichiarazione.
	 * @param DateTimeImmutable $when         Data/ora trasmissione.
	 * @return array
	 */
	private function build_pdf_args( string $order_number, string $order_date, array $data, string $declaration, DateTimeImmutable $when ): array {
		$rows = [
			[ __( 'Ordine n.', 'wayt-recesso' ), '#' . $order_number ],
		];
		if ( '' !== $order_date ) {
			$rows[] = [ __( 'Data ordine', 'wayt-recesso' ), $order_date ];
		}
		$rows[] = [ __( 'Consumatore', 'wayt-recesso' ), (string) ( $data['name'] ?? '' ) ];
		$rows[] = [ __( 'Email conferma', 'wayt-recesso' ), (string) ( $data['email'] ?? '' ) ];
		$rows[] = [ __( 'Data e ora', 'wayt-recesso' ), $when->format( 'd/m/Y H:i:s' ) ];
		$rows[] = [
			__( 'Oggetto', 'wayt-recesso' ),
			( 'partial' === ( $data['scope'] ?? '' ) ) ? __( 'Recesso parziale', 'wayt-recesso' ) : __( 'Recesso totale', 'wayt-recesso' ),
		];

		return [
			'title'      => __( 'Attestato di recesso', 'wayt-recesso' ),
			'rows'       => $rows,
			'decl_title' => __( 'Dichiarazione resa dal consumatore', 'wayt-recesso' ),
			'decl_text'  => $declaration,
			'footer'     => sprintf(
				/* translators: %s: nome sito */
				__( 'Documento generato da %s ai sensi dell\'art. 54-bis Cod. Consumo - non costituisce consulenza legale.', 'wayt-recesso' ),
				get_bloginfo( 'name' )
			),
		];
	}

	/**
	 * Protegge la cartella temporanea negli uploads da accesso diretto e
	 * directory listing (index.html vuoto + .htaccess Apache). Best-effort e
	 * idempotente; su Nginx la protezione va prevista a livello di server.
	 *
	 * @param string $dir Cartella da proteggere.
	 * @return void
	 */
	private function protect_dir( string $dir ): void {
		$dir = trailingslashit( $dir );
		if ( ! file_exists( $dir . 'index.html' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . 'index.html', '' );
		}
		if ( ! file_exists( $dir . '.htaccess' ) ) {
			$rules = "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '.htaccess', $rules );
		}
	}

	/**
	 * Genera il PDF su file temporaneo e ne ritorna il percorso (o '').
	 *
	 * @param WC_Order          $order       Ordine.
	 * @param array             $data        Dati.
	 * @param string            $declaration Dichiarazione.
	 * @param DateTimeImmutable $when        Data/ora.
	 * @return string
	 */
	private function generate_pdf_file( WC_Order $order, array $data, string $declaration, DateTimeImmutable $when ): string {
		try {
			$args  = $this->build_pdf_args(
				$order->get_order_number(),
				wc_format_datetime( $order->get_date_created() ),
				$data,
				$declaration,
				$when
			);
			$bytes = WAYT_Recesso_PDF::generate( $args );
			if ( '' === $bytes ) {
				return '';
			}

			$upload = wp_upload_dir();
			if ( ! empty( $upload['error'] ) ) {
				return '';
			}
			$dir = trailingslashit( $upload['basedir'] ) . 'wayt-recesso-tmp';
			wp_mkdir_p( $dir );
			$this->protect_dir( $dir );

			$file = trailingslashit( $dir ) . 'attestato-recesso-' . $order->get_id() . '-' . wp_generate_password( 8, false ) . '.pdf';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$ok = file_put_contents( $file, $bytes );
			return ( false === $ok ) ? '' : $file;
		} catch ( Throwable $e ) {
			return '';
		}
	}

	/**
	 * Download PDF attestato dal registro (admin), rigenerato on-demand.
	 */
	public function handle_pdf_download(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'wayt-recesso' ) );
		}
		$id = isset( $_GET['request'] ) ? absint( wp_unslash( $_GET['request'] ) ) : 0;
		check_admin_referer( 'wayt_recesso_pdf_' . $id );

		global $wpdb;
		$table = $wpdb->prefix . WAYT_RECESSO_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			wp_die( esc_html__( 'Richiesta non trovata.', 'wayt-recesso' ) );
		}

		try {
			$when = new DateTimeImmutable( (string) $row['created_at'], wp_timezone() );
		} catch ( Exception $e ) {
			$when = new DateTimeImmutable( 'now', wp_timezone() );
		}

		$order_date = '';
		$order      = wc_get_order( (int) $row['order_id'] );
		if ( $order instanceof WC_Order ) {
			$order_date = wc_format_datetime( $order->get_date_created() );
		}

		$data = [
			'name'  => (string) $row['consumer_name'],
			'email' => (string) $row['consumer_email'],
			'scope' => (string) $row['scope'],
		];

		$args  = $this->build_pdf_args( (string) $row['order_number'], $order_date, $data, (string) $row['declaration'], $when );
		$bytes = WAYT_Recesso_PDF::generate( $args );

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="attestato-recesso-' . sanitize_file_name( (string) $row['order_number'] ) . '.pdf"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binario PDF.
		echo $bytes;
		exit;
	}

	/*
	 * v0.2.0 — RIMBORSO AUTOMATICO (opt-in)
	 */

	/**
	 * Crea un rimborso WooCommerce in base all'impostazione auto_refund.
	 * Non innesca automaticamente il rimborso sul gateway (refund_payment = false).
	 *
	 * @param WC_Order $order Ordine.
	 * @param array    $data  Dati (scope/items).
	 * @return int|null ID rimborso o null.
	 */
	private function maybe_refund( WC_Order $order, array $data ): ?int {
		$mode = (string) $this->opt( 'auto_refund', 'off' );
		if ( 'off' === $mode || ! function_exists( 'wc_create_refund' ) ) {
			return null;
		}

		$reason = __( 'Recesso esercitato (art. 54-bis Cod. Consumo)', 'wayt-recesso' );

		try {
			if ( 'items' === $mode && 'partial' === ( $data['scope'] ?? '' ) && ! empty( $data['items'] ) ) {
				$amount     = 0.0;
				$line_items = [];
				foreach ( (array) $data['items'] as $iid ) {
					$iid  = (int) $iid;
					$item = $order->get_item( $iid );
					if ( ! $item instanceof WC_Order_Item_Product ) {
						continue;
					}
					// Idempotenza importi: rimborsa solo il residuo non ancora rimborsato per l'articolo.
					$line_total = (float) $item->get_total();
					$already    = (float) $order->get_total_refunded_for_item( $iid );
					$refundable = round( $line_total - $already, 2 );
					if ( $refundable <= 0 ) {
						continue;
					}
					$qty_already = abs( (int) $order->get_qty_refunded_for_item( $iid ) );
					$qty_left    = max( 0, (int) $item->get_quantity() - $qty_already );

					$refund_tax = [];
					$tax_total  = 0.0;
					$taxes      = $item->get_taxes();
					if ( isset( $taxes['total'] ) && is_array( $taxes['total'] ) ) {
						foreach ( $taxes['total'] as $rate_id => $t ) {
							$tax_already = (float) $order->get_tax_refunded_for_item( $iid, (int) $rate_id );
							$tax_left    = round( (float) $t - $tax_already, 2 );
							if ( $tax_left <= 0 ) {
								continue;
							}
							$refund_tax[ $rate_id ] = $tax_left;
							$tax_total             += $tax_left;
						}
					}
					$line_items[ $iid ] = [
						'qty'          => $qty_left,
						'refund_total' => $refundable,
						'refund_tax'   => $refund_tax,
					];
					$amount            += $refundable + $tax_total;
				}
				if ( $amount <= 0 ) {
					return null;
				}
				$refund = wc_create_refund(
					[
						'order_id'       => $order->get_id(),
						'amount'         => round( $amount, 2 ),
						'reason'         => $reason,
						'line_items'     => $line_items,
						// Niente restock automatico: la merce non e' ancora rientrata
						// alla dichiarazione di recesso (coerente col rimborso totale).
						'restock_items'  => false,
						'refund_payment' => false,
					]
				);
			} else {
				// Rimborso totale del residuo.
				$amount = (float) $order->get_total() - (float) $order->get_total_refunded();
				if ( $amount <= 0 ) {
					return null;
				}
				$refund = wc_create_refund(
					[
						'order_id'       => $order->get_id(),
						'amount'         => round( $amount, 2 ),
						'reason'         => $reason,
						'refund_payment' => false,
					]
				);
			}

			if ( is_wp_error( $refund ) || ! $refund ) {
				return null;
			}
			return (int) $refund->get_id();
		} catch ( Throwable $e ) {
			return null;
		}
	}
}

// Hook attivazione/disattivazione.
register_activation_hook( __FILE__, [ 'WAYT_Recesso_Online', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'WAYT_Recesso_Online', 'deactivate' ] );

// Avvio.
add_action(
	'plugins_loaded',
	static function () {
		WAYT_Recesso_Online::instance();
	},
	5
);
