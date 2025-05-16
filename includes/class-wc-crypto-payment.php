<?php

class WC_Crypto_Payment {
  public static $namespace = 'crypto-payment';
  public static $PAYMENT_METHOD = 'crypto_payment';
  public static $INVOICE_ID_META_KEY = 'crypto_payment_invoice_id';

  public static function debug_log( $message ) {
    $logger = wc_get_logger();
    $logger->info($message, ['source' => self::$namespace]);
  }
}

?>
