<?php

/*
Plugin Naam: Bitcoin Betalingsgateway (Lightning)
Plugin URI: https://lightningcheckout.eu
Omschrijving: Accepteer Bitcoin via het Lightning-netwerk. Aangeboden door Lightning Checkout.
Versie: 2.0
Auteur: Lightning Checkout
Fork van: https://nl.wordpress.org/plugins/lightning-payment-gateway-lnbits/
*/

require 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/lightningcheckout/wp-lnc-bitcoinlightning/',
	__FILE__,
	'wp-lnc-bitcoinlightning'
);

// Stel de tak in die de stabiele release bevat.
$myUpdateChecker->setBranch('main');

// Optioneel: als je een privérepository gebruikt, specificeer dan de toegangstoken als volgt:
//$myUpdateChecker->setAuthentication('jouw-token-hier');

// Als je release-assets wilt gebruiken, roep dan de enableReleaseAssets() methode aan nadat je de update checker-instantie hebt gemaakt:
$myUpdateChecker->getVcsApi()->enableReleaseAssets();


add_action('plugins_loaded', 'lightningcheckout_init');

define('LIGHTNINGCHECKOUT_PAYMENT_PAGE_SLUG', 'lightning-checkout');
$site_title =  get_option('blogname');
define('SITE_NAME', $site_title);

require_once(__DIR__ . '/includes/init.php');

use LightningCheckoutPlugin\Utils;
use LightningCheckoutPlugin\LNBitsAPI;


function pmpro_lightningcheckout_activate() {
    if (!current_user_can('activate_plugins')) return;

    global $wpdb;

    if ( null === $wpdb->get_row( "SELECT post_name FROM {$wpdb->prefix}posts WHERE post_name = '".LIGHTNINGCHECKOUT_PAYMENT_PAGE_SLUG."'", 'ARRAY_A' ) ) {
        $page = array(
          'post_title'  => __( 'Lightning Checkout' ),
          'post_name' => LIGHTNINGCHECKOUT_PAYMENT_PAGE_SLUG,
          'post_status' => 'publish',
          'post_author' => wp_get_current_user()->ID,
          'post_type'   => 'page',
          'post_content' => render_template('payment_page.php', array())
        );

        // voeg de post toe aan de database
        wp_insert_post( $page );
    }
}

register_activation_hook(__FILE__, 'pmpro_lightningcheckout_activate');


// Helper om sjablonen te renderen onder ./templates.
function render_template($tpl_name, $params) {
    return wc_get_template_html($tpl_name, $params, '', plugin_dir_path(__FILE__).'templates/');
}


add_action( 'wp_enqueue_scripts', 'qr_code_load' );
function qr_code_load(){
  wp_enqueue_script( 'qr-code', plugin_dir_url( __FILE__ ) . 'js/jquery.qrcode.min.js', array( 'jquery' ) );
}

// Genereer lightningcheckout_payment-pagina, met behulp van ./templates/lightningcheckout_payment.php
function lightningcheckout_payment_shortcode() {
    $check_payment_url = trailingslashit(get_bloginfo('wpurl')) . '?wc-api=wc_gateway_lightningcheckout';

    if (isset($_REQUEST['order_id'])) {
        $order_id = absint($_REQUEST['order_id']);
        $order = wc_get_order($order_id);
        $invoice = $order->get_meta("lightningcheckout_invoice");
        $success_url = $order->get_checkout_order_received_url();
    } else {
        // Waarschijnlijk bij bewerken van pagina met deze shortcode, gebruik een dummy-bestelling.
        $order_id = 1;
        $invoice = "lnbc0000";
        $success_url = "/dummy-success";
    }

    $template_params = array(
        "invoice" => $invoice,
        "check_payment_url" => $check_payment_url,
        'order_id' => $order_id,
        'success_url' => $success_url
    );
    
    return render_template('payment_shortcode.php', $template_params);
}

// Dit is het startpunt van de plugin, waar alles in WordPress wordt geregistreerd/gekoppeld.
function lightningcheckout_init() {
    if (!class_exists('PMProGateway')) {
        return;
    };

    // Registreer shortcode voor het weergeven van Lightning-invoice (QR-code)
    add_shortcode('lightningcheckout_payment_shortcode', 'lightningcheckout_payment_shortcode');

    // Registreer de gateway, feitelijk een controller die alle verzoeken afhandelt.
    function add_lightningcheckout_gateway($gateways) {
        $gateways['lightningcheckout'] = 'PMProGateway_LNBits';
        return $gateways;
    }

    add_filter('pmpro_gateways', 'add_lightningcheckout_gateway');

    // Definieer hier, omdat het moet worden gedefinieerd nadat PMProGateway al is geladen.
    class PMProGateway_LNBits extends PMProGateway {
        public function __construct() {
            global $wpdb;

            $this->gateway = 'lightningcheckout';
            $this->gateway_environment = pmpro_getOption('gateway_environment');
            $this->gateway_options = pmpro_getOption('gateway_options');

            $this->liveurl = 'https://pay.lightningcheckout.eu';
            $this->testurl = 'https://pay.lightningcheckout.eu'; // Gebruik dezelfde URL voor tests omdat het een sandbox-omgeving is
            $this->api_key = $this->get_option('lightningcheckout_api_key');

            add_action('init', array($this, 'load_textdomain'));

            if($this->api_key) {
                add_action('pmpro_checkout_preheader', array($this, 'process_payment'));
            }

            add_filter('pmpro_payment_option_fields', array($this, 'payment_option_fields'), 10, 2);
            add_filter('pmpro_payment_option_fields', array($this, 'pmpro_payment_option_fields'), 10, 2);
            add_filter('pmpro_checkout_order', array($this, 'pmpro_checkout_order'));
            add_action('wp_ajax_pmpro_lightningcheckout', array($this, 'pmpro_lightningcheckout'));
            add_action('wp_ajax_nopriv_pmpro_lightningcheckout', array($this, 'pmpro_lightningcheckout'));

            add_action('pmpro_cron_expiration_warning', array($this, 'pmpro_cron_expiration_warning'));
            add_action('pmpro_cron_expire_memberships', array($this, 'pmpro_cron_expire_memberships'));
            add_action('pmpro_cron_credit_card_expiring', array($this, 'pmpro_cron_credit_card_expiring'));
            add_action('pmpro_cron_jobs', array($this, 'pmpro_cron_jobs'));

            // adds the settings to the Payment Settings meta box
            add_filter('pmpro_payment_options', array($this, 'pmpro_payment_options'));
            // updates to our custom
