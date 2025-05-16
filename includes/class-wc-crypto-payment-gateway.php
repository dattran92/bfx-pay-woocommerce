<?php
require_once __DIR__ . '/class-wc-crypto-payment.php';

/**
 * Class WC_Bfx_Pay_Gateway file.
 */
/**
 * Bitfinex Gateway.
 *
 * Provides a Crypto Payment Gateway.
 *
 * @class       WC_Crypto_Payment_Gateway
 * @extends     WC_Payment_Gateway
 *
 * @version     1.0.0
 */
class WC_Crypto_Payment_Gateway extends WC_Payment_Gateway
{
    /**
     * API Context used for Merchant Authorization.
     *
     * @var null
     */
    public $baseApiUrl = 'https://api.estable.io/';
    public $apiKey;
    public $apiSecret;
    public $isEnabled;
    public $title;
    public $description;
    public $instructions;
    public $complectedInstructions;
    public $buttonType;
    public $checkReqButton;
    public $payCurrencies;
    public $duration;
    public $icon;
    public $client;
    public $id;
    public $method_title;
    public $method_description;
    public $has_fields;
    public $debug;
    public $form_fields;

    /**
     * Constructor for the gateway.
     */
    public function __construct()
    {
        // Setup general properties.
        $this->setup_properties();

        // Load the settings.
        $this->init_settings();
        $this->init_form_fields();

        // Get settings.
        $this->isEnabled = $this->get_option('enabled');
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->baseApiUrl = $this->get_option('base_api_url');
        $this->apiKey = $this->get_option('api_key');
        $this->apiSecret = $this->get_option('api_secret') ?? false;
        $this->instructions = $this->get_option('instructions');
        $this->complectedInstructions = $this->get_option('complected_instruction');
        $this->checkReqButton = $this->get_option('button_req_checkout');
        $this->payCurrencies = $this->get_option('pay_currencies');
        $this->duration = $this->get_option('duration') ?? 86399;
        $this->debug = ('yes' === $this->get_option('debug'));
        $this->icon = $this->get_option('icon_url');

        $baseUrl = $this->baseApiUrl;
        $this->client = new GuzzleHttp\Client([
            'base_uri' => $baseUrl,
            'timeout' => 10.0,
        ]);

        add_action('woocommerce_update_options_payment_gateways_'.$this->id, [$this, 'process_admin_options']);
        // Customer Emails.
        add_action('woocommerce_email_order_details', [$this, 'remove_order_details'], 1, 4);
        add_action('woocommerce_email_order_details', [$this, 'email_template'], 20, 4);
        add_action('woocommerce_api_crypto_payment', [$this, 'webhook']);

        add_filter('woocommerce_add_error', [$this, 'woocommerce_add_error']);
    }


    // Customize settings tab completely
    public function admin_options()
    {
	    $return_url = admin_url('admin.php?page=wc-settings&tab=checkout');

        echo '<h2>' . esc_html( $this->get_method_title() );
        wc_back_link( __( 'Return to payments', 'woocommerce' ), $return_url );
        echo '</h2>';


        $form_fields = $this->get_form_fields();

        // call API for this
        $form_fields['pay_currencies']['options'] = [
            'ETH' => 'Ethereum',
            'USDT-ETH' => 'Tether USDt - Ethereum',
            'USDT-TRX' => 'Tether USDt - Tron',
        ];
        echo '<table class="form-table">';
        echo $this->generate_settings_html( $form_fields, false );
        echo '</table>';
    }

    /**
     * Handle fixing guzzle curl error.
     */
    public function woocommerce_add_error($error)
    {
        if (false !== strpos(strtolower($error), 'curl')) {
            $logger = wc_get_logger();
            $logger->error($error, ['source' => 'crypto-payment']);

            return 'Internal server error, please try again later';
        }

        return $error;
    }

    protected function format_bfx_error($error)
    {
        if ($error instanceof GuzzleHttp\Exception\ServerException) {
            $response = $error->getResponse();
            $responseBodyAsString = $response->getBody()->getContents();
            $errorObj = json_decode($responseBodyAsString);
            $errMsg = $errorObj[2];
            if ($errMsg === 'ERR_CREATE_INVOICE: ERR_PAY_NOT_AVAILABLE_IN_COUNTRY_OR_REGION') {
                return 'Crypto Payment is not available in your country or region';
            }

            if ($errMsg === 'ERR_CREATE_INVOICE: ERR_PAY_CURRENCY_INVALID') {
                return 'Crypto Payment does not support this currency';
            }

            if ($errMsg === 'ERR_CREATE_INVOICE: ERR_PAY_AMOUNT_INVALID') {
                return 'Crypto Payment can not process order with amount less than 0.1 USD equivalent.';
            }

            return 'Invoice creation failed with reason: '.$errMsg;
        }

        return 'Internal server error, please try again later';
    }

    /**
     * Setup general properties for the gateway.
     */
    protected function setup_properties()
    {
        $this->id = WC_Crypto_Payment::$PAYMENT_METHOD;
        $this->method_title = __('Crypto Payment', 'crypto-payment');
        $this->method_description = __('Allow Merchant acceptting crypto payment', 'crypto-payment');
        $this->has_fields = true;
    }
    /**
     * General function to update order status
     *
     * @param object $order WC Order
     * @param string $payment_status Status of the payment
     *
     * @return bool true if known status, false for unknown status
     */
    protected function update_order_status($order, $payment_status)
    {
        if ('COMPLETED' === $payment_status) {
            ob_start();
            $order->payment_complete();
            ob_clean();
            return true;
        }

        if ('PENDING' === $payment_status) {
            $order->update_status('on-hold');
            return true;
        }

        if ('EXPIRED' === $payment_status) {
            $order->update_status('failed');
            return true;
        }

        return false;
    }

    protected function uuidv4()
    {
      $data = random_bytes(16);

      $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
      $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

      return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    protected function bfx_request($path, $body) {
        $apiKey = $this->apiKey;
        $apiSecret = $this->apiSecret;
        $timeStr = (string) (time() * 1000);
        $nonce = $this->uuidv4();
        $bodyJsonin = json_encode($body, JSON_UNESCAPED_SLASHES);
        $pathPrefix = 'pay';
        $signaturePath = preg_replace('/^' . preg_quote($pathPrefix, '/') . '/', '', $path);
        $signatureContent = [
            'apiPath' => $signaturePath,
            'body' => $body
        ];
        $signatureContentStr = json_encode($signatureContent, JSON_UNESCAPED_SLASHES);
        $signaturein = "{$apiKey}:{$nonce}:{$timeStr}:{$signatureContentStr}";
        $sigin = hash_hmac('sha256', $signaturein, $apiSecret);
        $headersin = [
            'Content-Type' => 'application/json;charset=UTF-8',
            'Accept' => 'application/json',
            'x-nonce' => $nonce,
            'x-api-key' => $apiKey,
            'x-signature' => $sigin,
            'x-timestamp' => $timeStr,
        ];
        $rin = $this->client->post($path, [
            'headers' => $headersin,
            'body' => $bodyJsonin,
        ]);
        $responsein = $rin->getBody()->getContents();
        return $responsein;
    }

    protected function bfx_public_request($path) {
        $pathPrefix = 'pay';
        $headersin = [
            'Content-Type' => 'application/json;charset=UTF-8',
            'Accept' => 'application/json',
        ];
        $rin = $this->client->get($path, [
            'headers' => $headersin,
        ]);
        $responsein = $rin->getBody()->getContents();
        return $responsein;
    }

    protected function get_bfx_invoice($id)
    {
        $apiPathin = 'pay/integration/auth/invoice/get';
        $body = [
            'id' => $id
        ];
        $response = $this->bfx_request($apiPathin, $body);
        $data = json_decode($response, true);
        return $data;
    }

    /**
     * Initialise Gateway Settings Form Fields.
     */
    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'crypto-payment'),
                'label' => __('Enable', 'crypto-payment'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no',
                'subtab' => 'general',
            ],
            'title' => [
                'title' => __('Title', 'crypto-payment'),
                'type' => 'text',
                'default' => '',
                'desc_tip' => true,
                'description' => __('This controls the title which the user sees during checkout.', 'crypto-payment'),
                'subtab' => 'general',
            ],
            'icon_url' => [
                'title' => __('Icon URL', 'crypto-payment'),
                'type' => 'media_select',
                'default' => '',
                'desc_tip' => true,
                'description' => __('Icon image is displayed when user choose the option in checkout form', 'crypto-payment'),
                'subtab' => 'general',
            ],
            'description' => [
                'title' => __('Description', 'crypto-payment'),
                'type' => 'text',
                'desc_tip' => true,
                'description' => __('This controls the description which the user sees during checkout.', 'crypto-payment'),
                'subtab' => 'general',
            ],
            'base_api_url' => [
                'title' => __('Api url', 'crypto-payment'),
                'type' => 'text',
                'default' => '',
                'description' => __('API URL for invoice creation and validation"', 'crypto-payment'),
                'desc_tip' => true,
                'subtab' => 'general',
            ],
            'redirect_url' => [
                'title' => __('Redirect url', 'crypto-payment'),
                'type' => 'text',
                'default' => '',
                'description' => __('Payment gateway to process payment after checkout"', 'crypto-payment'),
                'desc_tip' => true,
                'subtab' => 'general',
            ],
            'api_key' => [
                'title' => __('Api key', 'crypto-payment'),
                'type' => 'text',
                'default' => '',
                'description' => __('Enter your crypto payment Api key', 'crypto-payment'),
                'desc_tip' => true,
                'subtab' => 'general',
            ],
            'api_secret' => [
                'title' => __('Api secret', 'crypto-payment'),
                'type' => 'password',
                'default' => '',
                'description' => __('Enter your crypto payment Api secret', 'crypto-payment'),
                'desc_tip' => true,
                'subtab' => 'general',
            ],
            'instructions' => [
                'title' => __('Order received email instructions', 'crypto-payment'),
                'type' => 'textarea',
                'default' => __('', 'crypto-payment'),
                'desc_tip' => true,
                'description' => __('You can add a message to the email the user receives when starting the order', 'crypto-payment'),
                'subtab' => 'customize',
            ],
            'complected_instruction' => [
                'title' => __('Order completed email instructions', 'crypto-payment'),
                'type' => 'textarea',
                'default' => __('', 'crypto-payment'),
                'desc_tip' => true,
                'description' => __('You can add a thank you message to the email the user receives when the payments is received', 'crypto-payment'),
                'subtab' => 'customize',
            ],
            'button_req_checkout' => [
                'title' => __('Enable/Disable one click checkout button for products', 'crypto-payment'),
                'label' => __('Enable', 'crypto-payment'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no',
                'subtab' => 'customize',
            ],
            'debug' => [
                'title' => __('Debug', 'crypto-payment'),
                'label' => __('Enable debugging messages', 'crypto-payment'),
                'type' => 'checkbox',
                'description' => __('Sends debug messages to the WooCommerce System Status log.', 'crypto-payment'),
                'desc_tip' => true,
                'default' => 'yes',
                'subtab' => 'customize',
            ],
            'pay_currencies' => [
                'title' => __('Pay currencies', 'crypto-payment'),
                'description' => __('Select pay currencies that you preffer. It may be several with ctrl/cmd button.', 'crypto-payment'),
                'desc_tip' => true,
                'type' => 'multiselect',
                'default' => 'ETH',
                'options' => [],
                'subtab' => 'customize',
            ],
            'duration' => [
                'title' => __('Duration: sec', 'crypto-payment'),
                'label' => __('sec', 'crypto-payment'),
                'type' => 'number',
                'default' => '86399',
                'custom_attributes' => ['step' => 'any', 'min' => '0'],
                'desc_tip' => true,
                'description' => __('This controls the duration.', 'crypto-payment'),
                'subtab' => 'customize',
            ],
        ];
    }

    /**
     * Getting Gateway icon uri.
     */
    public function get_icon_uri()
    {
        return $this->icon;
    }

    /**
     * Initialise Gateway Settings Form Fields.
     */
    public function payment_fields()
    {
        echo wpautop(wp_kses_post($this->description));
    }

    /**
     * Process the payment and return the result.
     *
     * @param int $order_id order ID
     *
     * @return array
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function process_payment($order_id)
    {
        global $woocommerce;

        $order = new WC_Order($order_id);
        $url = $this->get_return_url($order);
        $hook = get_site_url().'?wc-api=crypto_payment';
        $totalSum = $order->get_total();
        $payCurrencies = $this->get_option('pay_currencies');
        $currency = get_woocommerce_currency();
        $duration = $this->get_option('duration');
        $apiPath = 'pay/integration/auth/invoice/create';

        $body = [
            'amount' => $totalSum,
            'currency' => $currency,
            'payCurrencies' => $payCurrencies,
            'orderId' => "$order_id",
            'duration' => intval($duration),
            'webhook' => $hook,
            'redirectUrl' => $url,
            'customerInfo' => [
                'nationality' => $order->get_billing_country(),
                'residCountry' => $order->get_billing_country(),
                'residCity' => $order->get_billing_city(),
                'residState' => $order->get_billing_state(),
                'residZipCode' => $order->get_billing_postcode(),
                'residStreet' => $order->get_billing_address_1(),
                'fullName' => $order->get_formatted_billing_full_name(),
                'email' => $order->get_billing_email(),
                'type' => 'individual',
            ],
        ];

        try {
            $response = $this->bfx_request($apiPath, $body);
            if ($this->debug) {
                $logger = wc_get_logger();
                $logger->info('CREATE INVOICE CALL >> '.wc_print_r($response, true), ['source' => 'crypto-payment']);
            }
        } catch (\Throwable $ex) {
            $error = $ex->getMessage();
            $userError = $this->format_bfx_error($ex);

            $logger = wc_get_logger();
            $logger->error('CREATE INVOICE CALL >> '.$error, ['source' => 'crypto-payment']);

            wc_add_notice($userError, 'error');
            $order->update_status('failed', $userError);

            return [
                'result' => 'failure',
                'messages' => $userError,
            ];
        }

        $data = json_decode($response);

        $order->update_meta_data(WC_Crypto_Payment::$INVOICE_ID_META_KEY, $data->id);
        $order->save_meta_data();

        // Mark as on-hold (we're awaiting the Crypto Paymentment)
        $order->update_status('on-hold', __('Awaiting Crypto Paymentment', 'woocommerce'));

        // Remove cart
        $woocommerce->cart->empty_cart();

        // Return thankyou redirect
        $redirectUrl = $this->get_option('redirect_url');
        return [
            'result' => 'success',
            'redirect' => $redirectUrl.$data->id,
        ];
    }

    public function cron_invoice_check()
    {
        if ($this->debug) {
            WC_Crypto_Payment::debug_log('BEGIN CRON INVOICE CHECK');
        }
        $args = array(
            'status' => array('wc-on-hold'),
            'payment_method' => WC_Crypto_Payment::$PAYMENT_METHOD
        );

        $orders = wc_get_orders($args);
        foreach ($orders as $order) {
            if ($this->debug) {
                WC_Crypto_Payment::debug_log('CRON_CHECK_ORDER #' .$order->id);
            }
            $payment_invoice_id = $order->get_meta(WC_Crypto_Payment::$INVOICE_ID_META_KEY);
            $invoice = $this->get_bfx_invoice($payment_invoice_id);
            WC_Crypto_Payment::debug_log('CRON_CHECK_ORDER_RESULT #' .$order->id . ' >> ' . wc_print_r($invoice, true));
            $this->update_order_status($order, $invoice['status']);
        }
    }

    /**
     * Webhook.
     */
    public function webhook()
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || 'POST' !== $_SERVER['REQUEST_METHOD']) {
            return;
        }
        $payload = file_get_contents('php://input');
        $data = json_decode($payload, true);
        $order = wc_get_order($data['orderId']);

        if (!$order) {
            exit();
        }

        if ($this->debug) {
            $logger = wc_get_logger();
            $logger->info('WEBHOOK CALL >> '.wc_print_r($payload, true), ['source' => 'crypto-payment']);
        }

        $invoice_id = get_post_meta($order->id, WC_Crypto_Payment::$INVOICE_ID_META_KEY, true);
        $invoice_data = $this->get_bfx_invoice($invoice_id);

        if ($this->debug) {
            $logger = wc_get_logger();
            $logger->info('WEBHOOK INVOICE DATA >> '.wc_print_r($invoice_data, true), ['source' => 'crypto-payment']);
        }

        $is_success = $this->update_order_status($order, $invoice_data['status']);

        if ($is_success) {
            status_header(200);
            echo 'true';
        }

        exit();
    }

    /**
     * Remove email templates.
     */
    public function remove_order_details()
    {
        $mailer = WC()->mailer(); // get the instance of the WC_Emails class
        remove_action('woocommerce_email_order_details', array($mailer, 'order_details'));
    }

    /**
     * New email templates.
     */
    public function email_template($order, $sent_to_admin, $plain_text, $email)
    {
        $order->read_meta_data(true);
        $invoiceId = get_post_meta($order->id, WC_Crypto_Payment::$INVOICE_ID_META_KEY, true);

        if (!$invoiceId) {
            return;
        }

        $data = $this->get_bfx_invoice($invoiceId);

        $payment = null;
        $amount = null;

        if ($email->id == 'customer_completed_order') {
            $payment = $data['payment'];
            $amount = $payment['amount'];
            $amount = preg_replace('/0+$/', '', sprintf('%.10f', $amount));
        }

        $product = $order->get_items();
        $subtotal = $order->get_order_item_totals();
        $total = $order->get_order_item_totals();
        $products = '';

        foreach ($product as $item) {
            $row ='<tr>
            <td style="color:#636363;border:1px solid #e5e5e5;padding:12px;text-align:left;vertical-align:middle;font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;word-wrap:break-word">
            '.$item['name'].'</td>
            <td style="color:#636363;border:1px solid #e5e5e5;padding:12px;text-align:left;vertical-align:middle;font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif">'.$item['quantity'].'</td>
            <td style="color:#636363;border:1px solid #e5e5e5;padding:12px;text-align:left;vertical-align:middle;font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif">
             <span>'.$item['total'].'</span>
             </td>
            </tr>';
            $products .= $row;
        }

        if ($email->id == 'customer_completed_order') {
            echo '<p>' . $this->complectedInstructions . '</p>';
        }

        if ($email->id === 'customer_on_hold_order') {
            echo '<p>' . $this->instructions . '</p>';
        } ?>

        <div style="color:#636363;font-family:&quot;Helvetica Neue&quot;,Helvetica,Robot,Arial,sans-serif;font-size:14px;line-height:150%;text-align:left">


            <h2 style="color:#96588a;display:block;font-family:&quot;Helvetica Neue&quot;,Helvetica,Roboto,Arial,sans-serif;font-size:18px;font-weight:bold;line-height:130%;margin:0 0 18px;text-align:left">
                [Order # <?php echo$order->id; ?>] (<?php echo substr($order->date_created, 0, 10); ?>)</h2>
            <?php
            if ($email->id == 'customer_completed_order') {
                ?>
                <p style="margin:10px 0 16px; font-weight:bold; display: grid;">Transaction id: <span style="color: #03ca9b"><?php echo $payment['txid']?></span></p>
                <?php
            } ?>
            <div style="margin-bottom:40px">
                <table cellspacing="0" cellpadding="6" border="1" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;width:100%;font-family:'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif">
                    <thead>
                    <tr>
                        <th scope="col" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left">Product</th>
                        <th scope="col" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left">Quantity</th>
                        <th scope="col" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left">Price</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php echo $products ?>
                    </tbody>
                    <tfoot>
                    <tr>
                        <th scope="row" colspan="2" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left;border-top-width:4px">Subtotal:</th>
                        <td style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left;border-top-width:4px"><span><?php echo $subtotal['cart_subtotal']['value'] ?></span></td>
                    </tr>
                    <tr>
                        <th scope="row" colspan="2" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left">Crypto Payment:</th>
                        <td style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left"><?php echo $this->title ?></td>
                    </tr>
                    <tr>
                        <th scope="row" colspan="2" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left">Total:</th>
                        <td style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left"><span><?php echo $total['order_total']['value'] ?></span></td>
                    </tr>
                    <?php
                    if ($email->id == 'customer_completed_order') {
                        ?>
                        <tr>
                            <th scope="row" colspan="2" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left">Paid with:</th>
                            <td style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left"><span><?php echo  $amount.' '.$payment['currency']?></span></td>
                        </tr>
                        <?php
                    } ?>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php
        if ($email->id === 'customer_on_hold_order') { ?>
        <span>If you accidentally closed the payment process or want to complete it later, you can access it
using this <a href="<?php echo  $this->get_option('redirect_url').$invoiceId;?>">link</a>. Please keep in mind that this invoice will expire in <?php echo  date("H\h:i\m");?></span>
        <span></span>
        <?php }
        }
}
