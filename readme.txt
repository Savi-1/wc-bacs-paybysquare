=== PAY by square pre WooCommerce ===
Contributors: webikon, kravco, johnnypea, martinkrcho, savione
Tags: pay by square, qr platba, qrcode, bacs, woocommerce
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.2.0
WC requires at least: 8.0
WC tested up to: 11.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pridá QR kód k platbe prevodom vo WooCommerce. Do objednávky aj do emailu. Podporuje PAY by square (SK) aj QR Platba (CZ).

== Description ==

Plugin PAY by square uľahčuje platby bankovým prevodom prostredníctvom QR kódov.

Po odoslaní objednávky sa zákazníkovi zobrazí QR kód na ďakovnej stránke a zároveň sa odošle v emaile s potvrdením objednávky. Zákazník následne naskenuje QR kód mobilnou aplikáciou svojej banky, v ktorej sa mu predvyplnia všetky potrebné údaje k platbe — IBAN, suma, variabilný symbol a meno príjemcu.

Podporované formáty:

* PAY by square — slovenský štandard
* QR platba — český štandard
* Automatický výber — podľa meny objednávky (EUR = slovenský, CZK = český)

Kde sa QR kód zobrazí:

* Na ďakovnej stránke po odoslaní objednávky
* V emaile s potvrdením objednávky

Na použitie je potrebné mať účet na stránke app.bysquare.com. Program zadarmo ponúka generovanie 100 QR kódov mesačne.

== Installation ==

1. Pripravte si Váš eshop na platforme WooCommerce.
2. Zaregistrujte sa na stránke app.bysquare.com.
3. Nainštalujte a aktivujte si plugin Pay by Square (Pluginy > Pridať nový > Nahrať plugin).
4. Nastavte si parametre platby na účet (WooCommerce > Platby > Priamy prevod na bankový účet):
    a) Povoľte priamy prevod na bankový účet a prejdite do nastavení (Spravovať).
    b) Vložte údaje minimálne jedného bankového účtu — údaje IBAN a BIC sú povinné.
5. Nastavte si parametre generovania QR kódu (WooCommerce > Nastavenia > Integrácia > PAY by square):
    a) Príjemca platby — meno osoby alebo organizácie prijímajúcej peniaze.
    b) Používateľské meno a heslo — údaje, pod ktorými sa prihlasujete na app.bysquare.com (používateľské meno je v tvare emailu).
    c) Ostatné položky v nastaveniach môžete upraviť podľa Vašich preferencií.
6. Vykonajte testovaciu objednávku a skontrolujte si zobrazenie QR kódu po odoslaní objednávky a v emaile.

== Frequently Asked Questions ==

= QR kód sa mi na ďakovnej stránke / v emaile nezobrazuje =

Skontrolujte si:

* Správnosť prihlasovacích údajov (používateľské meno a heslo na app.bysquare.com).
* Údaje bankového účtu — IBAN aj BIC musia byť vyplnené.
* Počet zostávajúcich generovaní QR kódov v administrácii služby app.bysquare.com.
* V nastaveniach pluginu musí byť vyplnené pole „Príjemca platby".

= Plugin nefunguje s mojím SMTP pluginom =

Plugin používa na vloženie QR kódu do emailu knižnicu PHPMailer. Ak využívate SMTP plugin, overte si, že používa PHPMailer. Podporovaný je napríklad plugin WP Mail SMTP.

= Podporuje plugin blokový checkout? =

Áno, QR kód sa zobrazí na ďakovnej stránke aj pri použití blokového checkoutu (WooCommerce 8.9+). Bloková šablóna „Potvrdenie objednávky" musí obsahovať blok „Doplňujúce informácie" – práve v ňom WooCommerce zobrazuje doplnky platobných metód. Ak ste šablónu upravovali vo Vzhľad → Editor → Šablóny a tento blok odstránili, QR kód sa na ďakovnej stránke nezobrazí.

= Ako zobrazím QR kód na vlastnom mieste? =

Plugin poskytuje verejné metódy, ktoré vrátia adresu alebo cestu k obrázku QR kódu pre danú objednávku. Obrázok sa vygeneruje pri prvom volaní a ďalej sa berie z cache, takže opakované volania pre tú istú objednávku nespotrebúvajú ďalší kredit na app.bysquare.com; každá nová objednávka (alebo zmena sumy) spotrebuje jedno vygenerovanie.

Metódy nekontrolujú spôsob platby ani stav objednávky – QR kód vrátia pre akúkoľvek objednávku. O tom, kedy sa má zobraziť, rozhoduje váš kód:

`
if ( class_exists( '\Webikon\Woocommerce_Plugin\WC_BACS_Paybysquare\Plugin' ) ) {
    $order = wc_get_order( $order_id );
    if ( $order && 'bacs' === $order->get_payment_method() ) {
        $plugin = \Webikon\Woocommerce_Plugin\WC_BACS_Paybysquare\Plugin::get_instance();
        $url    = $plugin->get_qrcode_url( $order );  // adresa obrázka; '' ak QR kód nie je k dispozícii
        $path   = $plugin->get_qrcode_path( $order ); // cesta k súboru, napr. na vloženie do PDF faktúry
    }
}
`

Obe metódy prijímajú objekt WC_Order alebo ID objednávky. Hotový HTML blok s obrázkom vykreslí metóda `thankyou_page_qrcode( $order )` s rovnakým parametrom. Údaje v QR kóde upravíte filtrami `pay_by_square_qr_variable_symbol` (variabilný symbol) a `pay_by_square_qrdata` (všetky polia).

== Screenshots ==

1. Ďakovná stránka objednávky s QR kódom
2. Nastavenia pluginu (Integrácia > PAY by square)
3. Ďakovná stránka - plný náhľad
4. QR kód v potvrdzujúcom emaile

== Changelog ==

= 3.2.0 =
* Pripravené pre WordPress 7.1 + WooCommerce 11.1.
* Pridané filtre `pay_by_square_qr_variable_symbol` a `pay_by_square_qrdata` na úpravu variabilného symbolu a ďalších údajov v QR kóde.
* QR kód sa dá zobraziť aj na vlastnom mieste – vo faktúre, v detaile objednávky či na vlastnej ďakovnej stránke (postup vo FAQ).
* Deklarovaná kompatibilita s blokovým košíkom a pokladňou WooCommerce.
* Opravené: upozornenie o presunutých nastaveniach sa zobrazí len v sekcii Bankový prevod.
* Opravené: poškodený alebo prázdny QR obrázok v cache sa vygeneruje nanovo.
* Opravené: QR obrázok sa už neprikladá k ďalším emailom odoslaným v tej istej požiadavke.
* Opravené: chybná odpoveď služby app.bysquare.com sa zapíše do logu bez PHP varovaní.
* Neúplný bankový účet v nastaveniach Bankového prevodu sa pri tvorbe QR kódu preskočí.

= 3.1.0 =
* Pridaný Live Preview na stránke pluginu na WordPress.org (WordPress Playground blueprint)
* Pridané upozornenie v nastaveniach pluginu ak nie je vyplnené pole „Príjemca platby"
* Pridané upozornenie v zozname pluginov ak nie je vyplnené pole „Príjemca platby"
* Pridaná validácia formátu IBAN a BIC
* Aktualizované info o kompatibilite s WordPress 6.9.4 a WooCommerce 10.6

= 3.0.1 =
* Pridané logovanie presnej chyby v prípade, že nie je možné vytvoriť obrázok s QR kódom
* Opravené zobrazenie odkazu na nastavenia PAY by square v nastaveniach platieb

= 3.0.0 =
* Presunutie nastavení na samostatnú stránku (Integrácia > PAY by square)

For older versions see changelog.txt.
