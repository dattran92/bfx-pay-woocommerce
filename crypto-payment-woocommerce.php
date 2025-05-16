<?php
/**
 * @wordpress-plugin
 * Plugin Name:       Crypto Payment
 * Plugin URI:        https://pay.estable.io/
 * Description:       Allows e-commerce customers to pay for goods and services with crypto currencies. It provides a payment gateway that could be used by any e-commerce to sell their products and services.
 * Version:           1.0.0
 * Author:            Estable
 * Author URI:        https://estable.io
 * License:           GPL-3.0
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain:       crypto-payment
 * Domain Path:       /languages
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}


add_action('plugins_loaded', 'crypto_payment_woocommerce_init', 11);
function crypto_payment_woocommerce_init()
{
    if (class_exists('WC_Payment_Gateway')) {
        require_once __DIR__.'/vendor/autoload.php';
        require_once __DIR__.'/includes/class-wc-crypto-payment-gateway.php';
    }
}


add_action('woocommerce_after_add_to_cart_form', 'crypto_payment_buy_checkout_on_archive');
function crypto_payment_buy_checkout_on_archive()
{
    if (class_exists('WC_Crypto_Payment_Gateway')) {
        global $product;
        $instance = new WC_Crypto_Payment_Gateway();
        $icon = $instance->get_icon_uri();
        $checkButton = ('yes' === $instance->isEnabled && 'yes' === $instance->checkReqButton) ? true : false;

        if ($checkButton) {
            if ($product->is_type('simple')) {
                $productId = $product->get_id();
                $buttonUrl = '?addtocart='.$productId;
                $buttonClass = 'button loop-checkout-btn';

                echo '<a href="'.$buttonUrl.'" class="'.$buttonClass.'"><img src="'.$icon.'" alt="" width="110" style="margin-bottom: 0;"></a>';
            }
        }
    }
}

add_action('template_redirect', 'crypto_payment_addtocart_on_archives_redirect_checkout');
function crypto_payment_addtocart_on_archives_redirect_checkout()
{
    if (isset($_GET['addtocart']) && $_GET['addtocart'] > 0) {
        WC()->cart->empty_cart();
        WC()->cart->add_to_cart(intval($_GET['addtocart']));
        WC()->session->set('chosen_payment_method', 'crypto_payment');
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'crypto_payment_settings_link', 10);
function crypto_payment_settings_link($links): array
{
    $custom['settings'] = sprintf(
        '<a href="%s" aria-label="%s">%s</a>',
        esc_url(
            add_query_arg(
                array(
                    'page' => 'wc-settings&tab=checkout&section=crypto_payment',
                ),
                admin_url('admin.php')
            )
        ),
        esc_attr__('Go to BFX Settings page', 'crypto-payment-woocommerce'),
        esc_html__('Settings', 'crypto-payment-woocommerce')
    );

    return array_merge($custom, (array)$links);
}

add_filter('woocommerce_payment_gateways', 'crypto_payment_add_payment_gateway_woo');
function crypto_payment_add_payment_gateway_woo($gateways)
{
    $gateways[] = 'WC_Crypto_Payment_Gateway';

    return $gateways;
}

// TODO: Add links to plugin row meta
add_filter('plugin_row_meta', 'crypto_payment_plugin_row_meta', 10, 3);
function crypto_payment_plugin_row_meta($links, $file)
{
    if (strpos($file, basename(__FILE__))) {
        // TODO: add links here
    }

    return $links;
}

// Start block checkout
add_action('before_woocommerce_init', 'crypto_payment_declare_cart_checkout_blocks_compatibility');
/**
 * Custom function to declare compatibility with cart_checkout_blocks feature
*/
function crypto_payment_declare_cart_checkout_blocks_compatibility() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
}

add_action( 'woocommerce_blocks_loaded', 'crypto_payment_register_order_approval_payment_method_type' );
/**
 * Custom function to register a payment method type
 */
function crypto_payment_register_order_approval_payment_method_type() {
    if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        return;
    }
    require_once __DIR__.'/includes/class-wc-block-crypto-payment-gateway.php';
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
            $payment_method_registry->register( new WC_Block_Cryto_Payment_Gateway );
        }
    );
    wp_enqueue_style(
        'WC_crypto_payment_block_checkout',
        plugin_dir_url(__FILE__) . '/includes/checkout.css',
        array(),
    );
}
// End block checkout

// Cron
add_filter('cron_schedules', 'crypto_payment_cron_add_fifteen_min');
function crypto_payment_cron_add_fifteen_min($schedules)
{
    $schedules['crypto_payment_fifteen_min'] = [
        'interval' => 60 * 15,
        'display' => 'Every 15 minute',
    ];

    return $schedules;
}


add_action('crypto_payment_cron_hook', 'crypto_payment_cron_exec');
function crypto_payment_cron_exec() {
    if (class_exists('WC_Crypto_Payment_Gateway')) {
        $instance = new WC_Crypto_Payment_Gateway();
        $instance->cron_invoice_check();
    }
}

add_action('wp', 'crypto_payment_add_cron');
function crypto_payment_add_cron() {
    if ( ! wp_next_scheduled( 'crypto_payment_cron_hook' ) ) {
        wp_schedule_event( time(), 'crypto_payment_fifteen_min', 'crypto_payment_cron_hook' );
    }
}

add_action('admin_enqueue_scripts', 'crypto_payment_admin_scripts');
function crypto_payment_admin_scripts() {
    wp_enqueue_media();
    wp_register_script('crypto_payment_admin_js', plugin_dir_url(__FILE__) . '/includes/admin.js', array('jquery'));
    wp_enqueue_script('crypto_payment_admin_js');
}

add_filter( 'woocommerce_generate_media_select_html', 'crypto_payment_generate_media_select_html_filter', 10, 4 );
function crypto_payment_generate_media_select_html_filter( $field_html, $key, $data, $wc_settings ){
    $field    = $wc_settings->plugin_id . $wc_settings->id . '_' . $key;
    $defaults = array(
        'class'             => 'button-secondary',
        'css'               => '',
        'custom_attributes' => array(),
        'desc_tip'          => false,
        'description'       => '',
        'title'             => '',
    );

    $data = wp_parse_args( $data, $defaults );
    ob_start();
    ?>
    <tr valign="top">
        <th scope="row" class="titledesc">
            <label for="<?php echo esc_attr( $field ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
            <?php echo $wc_settings->get_tooltip_html( $data ); ?>
        </th>
        <td class="forminp">
            <fieldset>
                <?php if (!empty($wc_settings->get_option($key))) { ?>
                    <img src="<?php echo $wc_settings->get_option($key) ?>" width="100px" />
                <?php } ?>
                <br class="clear" />
                <input id="upload_image" type="hidden" name="<?php echo esc_attr( $field ); ?>" value="<?php echo $wc_settings->get_option($key); ?>" />
                <input id="upload_image_button" class="button <?php echo esc_attr( $data['class'] ); ?>" type="button" value="Select an Image" />

            </fieldset>
        </td>
    </tr>
    <?php
    return ob_get_clean();
}
