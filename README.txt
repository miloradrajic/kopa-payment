=== KOPA PAYMENT ===
Contributors: tehnoloskopartnerstvo, miloradrajic
Tags: WooCommerce, payments, sopping, products, credit card
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.1.11
Author: Tehnološko Partnerstvo
Author URI: kopa.rs
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

KÖPA allows you to use credit cards as payment in WooCommerce shop.

== Description ==

KÖPA - A Customized Credit Card Payment Gateway for WooCommerce. Introducing KOPA Payment, a powerful WordPress plugin designed exclusively for WooCommerce, seamlessly integrating KOPA payment system to elevate your online store's credit card payment capabilities. With KOPA Payment, you can offer your customers a secure, efficient, and tailored payment experience that instills confidence and fosters trust.

KÖPA Payment ensures a seamless user experience across various devices. Whether your customers are shopping on desktops, tablets, or smartphones, they can enjoy a consistent and responsive payment process.

KÖPA Payment supports a wide range of credit card payment methods, making it convenient for customers with various preferences. From Visa and Mastercard to American Express and Dina, provide a comprehensive payment solution for your global audience.

Upgrade your WooCommerce store with the KÖPA payment plugin and redefine your credit card payment process. Elevate security, enhance user experience, and boost customer trust with this tailor-made solution that puts your brand's success at the forefront. Get started today and unlock a new level of payment efficiency!

== Installation ==

1. Go to `WP-Admin->Plugins->Add new`, search term "KÖPA Payment" and click on the "install" button
2. OR, upload **kopa-payment.zip** to `/wp-content/plugins` directory via WordPress admin panel or upload unzipped folder to your plugins folder via FTP
3. Activate the plugin through the "Plugins" menu in WordPress

== Changelog ==

= 1.1.11 =
* Added error codes for unsuccessful transactions

= 1.1.10 =
* Added link to KOPA certificates 

= 1.1.9 =
* Added hook on order save action, if payment method was not kopa-payment, delete kopaOrderId that was generated when payment was attempted but not successfull an payment option was changed.

= 1.1.8 =
* If order not found on server, adding order notes and removing meta data about the order

= 1.1.7 =
* Cancelations notifications and bug fixing

= 1.1.6 =
* Additional string added for successfull transaction, adding translations

= 1.1.5 =
* Displaying additional transaction informations from bank response

= 1.1.4 =
* Added shortcode option for payment details on custom thank-you pages

= 1.1.3 =
* Added refund function also on order status change to cancel
* Additional columns on orders listing for kopa payment status

= 1.0.0 =
* First stable version