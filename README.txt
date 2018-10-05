=== WC Peach Payments Gateway ===
Contributors: feedmymedia
Tags: LSX Theme, woocommerce, payments, credit card, payment request
Donate link: https://www.lsdev.biz/
Requires at least: 3.8
Tested up to: 4.3.1
Requires PHP: 7.0
Stable tag: 1.2.0
License: GPLv3

A payment gateway integration between WooCommerce and Peach Payments.

== Description ==
The [Peach Payments](https://www.lsdev.biz/product/woocommerce-peach-payments/) extension for WooCommerce is a South African payment gateway that allows merchants to access various payment methods, including credit/debit cards, bank transfers, mobile wallets, electronic wallets and mobile operator billing to help them succeed in emerging markets.

- Secure card storage
- Fully supports WooCommerce Subscriptions (separate purchase)
- 3DSecure ready
- PCI Compliant

We offer premium support for this plugin. Premium support that can be purchased via [lsdev.biz](https://www.lsdev.biz/).

= Requirements =
A Peach Payments Merchant Account.
A WooCommerce store with “South African Rand ( R )” as the currency.

= Sign up with Peach Payments =
Contact Peach Payments at sales@peachpayments.com to set up a merchant account for your company/website.
Peach Payments will assist you in the application process with the respective banks. Please note that the merchant account application process may take up to 4 weeks depending on the bank. Get in touch as soon as possible to avoid delays going live.

= It's Free, and always will be =
We’re firm believers in open source – that’s why we’re releasing the WooCommerce Peach Payments Gateway plugin for free, forever. 

= Support =
We offer premium support for this plugin. Premium support that can be purchased via [lsdev.biz](https://www.lsdev.biz/).

= Actively Developed =
The WooCommerce Peach Payments Gateway plugin is actively developed with new features and exciting enhancements all the time. Keep track on the WooCommerce Peach Payments Gateway GitHub repository. Report any bugs via github issues.

== Installation ==
1. Log in to your WordPress website (www.yourwebsiteurl.com/wp-admin).
2. Navigate to “Plugins”, and select “Add New”.
3. Upload the .zip file you downloaded and click install now.
4. When the installation is complete, Activate your plugin.

= Setup and Configuration =
Upon setting up your merchant account with Peach Payments you will receive TEST and LIVE access credentials. You will need to insert these details on the Peach Payments gateway settings page under “WooCommerce settings”. Use your TEST credentials for testing prior to going live.

== Testing the payment gateway ==
1. Go to WooCommerce > Settings > Payments > Peach Payments.
2. Change the “Transaction Mode” to “Integrator Test” mode.
3. Enter Peach Payments TEST access credentials into the “Sender ID”, “Channel ID” and “3DSecure Channel ID” fields. You would have received these after registering with Peach.
4. Save changes.

== Sandbox Testing ==
Now test the payment gateway by purchasing a product on your website using the Peach Payment Test Cards (the Test Card numbers provided in this system can be used to test the various components of your integration).

- Peach Payments Test Cards – These cards are to be used when testing on the Peach Payments platform in the INTEGRATOR TEST mode only.
- NedBank Test Cards. Please use these test cards when testing in the CONNECTOR TEST mode.
- Bankserv Test Cards – These cards are used to test your 3DSecure integration workflows.
Some of these cards will work on the Peach Payments platform in the INTEGRATOR_TEST mode and some will return an error (100.100.101). Please ignore the error and continue to test your workflows.

== Peach Payment Test Cards ==
Note: Card associations that are available to you depends on the country you do business in, please contact Peach for more information.

VISA
	Number: 4012888888881881 or 4111111111111111
	Expiry: Any future date
	Verification: 123
VISAELECTRON
	Number: 4012888888881881
	Expiry: Any future date
	Verification: 123
MASTER
	Number: 5105105105105100
	Expiry: Any future date
	Verification: 123
DISCOVER
	Number: 6011587918359498
	Expiry: Any future date
	Verification: 123
AMEX
	Number: 311111111111117
	Expiry: Any future date
	Verification: 123
MAESTRO UK
	Number: 6799851000000032
	Expiry: Any future date
	Verification: 123
SOLO
	Number: 6334580500000000
	Expiry: Any future date
	Verification: 123
CARTEBLEUE
	Number: 4111111111111111
	Expiry: Any future date
	Verification: 123

= Nedbank Test Cards =
VISA
	Number: 4242424242424242
	Expiry: Any future date
	Verification: 123
	Result: Authorised
MASTER
	Number: 5454545454545454
	Expiry: Any future date
	Verification: 123
	Result: “Unable to Process” or timeout
All other card numbers:
	“Invalid card number”

= Bankserv (3DSecure) Test Cards =
MASTER
	Number: 5221266361111726
	Expiry: 12/2014
	CVV: 123
	Password: test123
	Enrolled Status: Y
	Authentication Status: Y
VISA
	Number: 4012080132003002
	Peach System Status: Invalid Card (Error 100.100.101)
	VISA
	Number: 4341793000000034
	Peach System Status: “Authorized”
VISA
	Number: 4501155117901011
	Peach System Status: Invalid Card (Error 100.100.101)
	MASTER
	Number: 5221266361111726
	Peach System Status: “Authorized”
MASTER
	Number: 5221008360178290
	Peach System Status: Invalid Card (Error 100.100.101)
	MASTER
	Number: 5506750000000149
	Peach System Status: “Authorized”

= Live Mode =
1. After testing the gateway with the Peach Payment test cards, go back to “WooCommerce >> Settings” and select the “Checkout” tab.
2. Set the “Transaction Mode” to “Live”.
3. Replace your TEST access credentials in the “Sender ID” and “Channel ID” fields with your LIVE access credentials.
4. Click “Save changes”.

== Frequently Asked Questions ==
= What does this plugin do? =
A payment gateway integration between WooCommerce and Peach Payments.
= I’m getting a message about SSL not being enabled. =
Peach Payments does not require you to have an SSL certificate, because card details are only ever submitted to Peach Payments through SSL. However, some browsers may deliver a warning message when the connection changes between your non-SSL WooCommerce shop and the Peach Payments card entry form. For this reason, we recommend that you get an SSL certificate. You do not, however; need to deal with sorting out PCI compliancy, as this all on handled on the Peach Payments side.
= Test card should be authorized but I get the message: “Unfortunately your order cannot be processed as the originating bank/merchant has declined your transaction.” =
This might be caused by the fact that you are using an older version of the Peach Payments extension. Make sure that your Peach Payments extension has been updated and that you have the latest version. Then try using the test card again to see if it works.
= Visa card, Master card, American Express or other card not working. =
If a particular card brand is not working, make sure that you have enabled it using the “Supported Cards” field in the WooCommerce Checkout Settings for Peach Payments.
= Where can I report bugs or contribute to the project? =
Bugs can be reported either in our support account or preferably on the WooCommerce Peach Payments Gateway [GitHub repository](https://github.com/lightspeeddevelopment/woocommerce-gateway-peach-payments).
= The WooCommerce Peach Payments Gateway plugin is awesome! Can I contribute? =
Yes you can! Join in on our [GitHub repository](https://github.com/lightspeeddevelopment/woocommerce-gateway-peach-payments)  🙂
= What are the server requirements for running the LSX Theme and the WooCommerce Peach Payments Gateway plugin? =
Your WordPress website needs to be running PHP version 7.0 or higher in order to make use of the LSX theme and related plugins.
= I need custom functionality for this plugin. Can you build it? =
Yes. Just send us a message via [contact form](https://www.lsdev.biz/contact/) with precise information about what you require.

== Screenshots ==
1. Admin Settings Area
2. Checkout page
