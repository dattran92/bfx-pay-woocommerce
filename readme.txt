=== Crypto payment woocomerce ===
Contributors: estable
Tags: bitcoin payments, crypto payments, bitcoin, tether token, cryptocurrency pay, pay with bitcoin, ethereum payments, tether payments
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0

Allows e-commerce customers to pay for goods and services with crypto currencies. It provides a payment gateway that could be used by any e-commerce to sell their products and services.

== Installation ==
- First of all you should activate Bitfinex payment method, to do that open WooCommerce > Settings > Payments and click Set up on Crypto Payment method.
- Enter your Public and Secret keys from the platform in the proper fields and set all ticks to enable.
- Make sure that the webhook address https://your-domain?wc-api=crypto_payment is available to get incoming requests.

= What do the different invoice statuses mean? =

An invoice will have different statuses, starting from when the invoice is created until the time the funds are received in your account:
- Created: An invoice has been created;
- Pending: A deposit is pending confirmation to your account (for information on cryptocurrency deposit times, please view Where is my cryptocurrency deposit or withdrawal?);
- Completed: A deposit has been completed, and the payment amount and invoice amount was exactly matched;
- Expired: An invoice payment time has expired, meaning that the funds were not deposited in the required countdown period provided.

= Why my order status is set to completed/processing after payment completed? =
This is a standard behaviour from WooCommerce.
Most of the time payment completed should mark an order as 'processing' so that admin can process/post the items. If the cart contains only downloadable items then the order is 'completed' since the admin needs to take no action.
Additional information can be found in https://woocommerce.wp-a2z.org/oik_api/wc_orderpayment_complete/

= What are the invoice limitation amounts? =

The minimum invoice amount is $0.1 equivalent.

If your customer is a Bitfinex user who authenticates their Bitfinex account via the Pay with Bitfinex button, they can pay the minimum invoice amount equivalent, which will be deducted from their Bitfinex wallet balance.

However, if your customer is a Bitfinex user who makes the payment through the transaction address provided in the encrypted QR code or from the address box, they will be subject to the minimum withdrawal amount of $5 USD equivalent. For more information, please see Minimum Withdrawals.

Note: The maximum invoice amount is $ 1,000 (equivalent).


== Changelog ==

= 1.0.0 =
* initial implementation
