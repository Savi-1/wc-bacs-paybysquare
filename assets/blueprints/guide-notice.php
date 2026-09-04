<?php
/**
 * Plugin Name: PAY by square — Live Preview Guide
 * Description: Shows a welcome guide on the PAY by square settings page in the WordPress Playground Live Preview.
 * Version: 1.0.0
 * Author: Webikon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'admin_notices',
	function () {
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		if ( 'wc-settings' !== $page || 'integration' !== $tab || 'paybysquare' !== $section ) {
			return;
		}

		$register_url = 'https://app.bysquare.com/?utm_source=wordpress-playground&utm_medium=live-preview&utm_campaign=wc-bacs-paybysquare';
		$bacs_url     = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=bacs' );

		// Find the test product to build a direct add-to-cart link.
		$products    = wc_get_products( array( 'limit' => 1, 'status' => 'publish' ) );
		$add_to_cart = ! empty( $products ) ? esc_url( home_url( '?add-to-cart=' . $products[0]->get_id() ) ) : esc_url( home_url( '/shop/' ) );
		?>
		<div class="notice notice-info" style="padding: 16px; border-left-color: #2271b1;">
			<h2 style="margin-top: 0;">👋 Vitajte v živom náhľade PAY by square</h2>
			<p><strong>Tento plugin generuje QR kódy pre platby bankovým prevodom</strong> — zákazník naskenuje QR kód bankovou aplikáciou a všetky údaje k platbe sa mu automaticky predvyplnia (IBAN, suma, variabilný symbol, príjemca).</p>

			<h3>Vyskúšajte QR kód</h3>
			<ol>
				<li><strong><a href="<?php echo $add_to_cart; ?>">Pridať produkt do košíka →</a></strong></li>
				<li>V košíku kliknite na <strong>Pokladňa</strong> — údaje zákazníka sú už vyplnené</li>
				<li>Kliknite <strong>Objednať</strong></li>
				<li>Na ďakovnej stránke uvidíte <strong>QR kód</strong> pre platbu</li>
			</ol>
			<p><em>QR kód v tomto náhľade je demo obrázok. Pre skutočné QR kódy nainštalujte plugin na svoj obchod a zaregistrujte sa na app.bysquare.com.</em></p>

			<h3>Inštalácia vo vašom eshope</h3>
			<ol>
				<li><strong>Zaregistrujte sa</strong> na <a href="<?php echo esc_url( $register_url ); ?>" target="_blank" rel="noopener">app.bysquare.com</a> — získate používateľské meno a heslo</li>
				<li><strong>Nastavte bankový účet</strong> — v <a href="<?php echo esc_url( $bacs_url ); ?>">nastaveniach platby prevodom</a> zadajte IBAN a BIC</li>
				<li><strong>Vyplňte nastavenia pluginu</strong> — na tejto stránke zadajte príjemcu platby a prihlasovacie údaje z app.bysquare.com</li>
			</ol>

			<p><a href="<?php echo esc_url( $register_url ); ?>" target="_blank" rel="noopener" class="button button-primary">Registrovať sa na app.bysquare.com →</a></p>
		</div>
		<?php
	}
);
