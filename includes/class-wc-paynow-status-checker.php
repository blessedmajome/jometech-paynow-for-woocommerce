<?php
/**
 * Paynow status checker.
 *
 * One place that asks Paynow for the true status of an order's payment and updates the order.
 * It is used by:
 *   - Paynow's server-to-server notification (the result URL),
 *   - the customer's return to the "order received" page,
 *   - the express checkout status endpoint,
 *   - a background re-check of unpaid Paynow orders (2 and 10 minutes after checkout, then hourly),
 *   - the "Check payment with Paynow" order action in the admin.
 *
 * So an order is completed even when Paynow's notification never reaches the site.
 * The order is only ever changed from Paynow's own signed reply to the poll URL, never from request data.
 *
 * Added in 1.3.5.1 (community patch, not an official Paynow release).
 */

defined('ABSPATH') || exit;

class WC_Paynow_Status_Checker
{
	const META = '_wc_paynow_payment_meta';
	const CRON_HOOK = 'wc_paynow_recheck_pending';
	const SINGLE_HOOK = 'wc_paynow_recheck_order';
	/** Stop re-checking an order in the background after this many attempts. */
	const MAX_BACKGROUND_CHECKS = 60;
	/** How far back the hourly re-check looks. */
	const RECHECK_DAYS = 5;

	public static function init()
	{
		add_action('woocommerce_thankyou', array(__CLASS__, 'on_thankyou'), 5, 1);
		add_action(self::CRON_HOOK, array(__CLASS__, 'recheck_pending'));
		add_action(self::SINGLE_HOOK, array(__CLASS__, 'recheck_order'), 10, 2);
		add_action('init', array(__CLASS__, 'schedule'));
		add_filter('woocommerce_order_actions', array(__CLASS__, 'order_actions'), 10, 2);
		add_action('woocommerce_order_action_wc_paynow_check', array(__CLASS__, 'order_action_check'));
	}

	public static function schedule()
	{
		if (!wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
		}
	}

	public static function unschedule()
	{
		wp_clear_scheduled_hook(self::CRON_HOOK);
	}

	/** Check again shortly after checkout (2 minutes, then 10): mobile payments are often approved a little later. */
	public static function schedule_order($order_id, $step = 0)
	{
		$delays = array(120, 600);
		$step = (int) $step;
		if (!isset($delays[$step])) {
			return; // after that the hourly run takes over
		}
		$args = array((int) $order_id, $step);
		if (!wp_next_scheduled(self::SINGLE_HOOK, $args)) {
			wp_schedule_single_event(time() + $delays[$step], self::SINGLE_HOOK, $args);
		}
	}

	public static function get_meta($order)
	{
		$meta = get_post_meta($order->get_id(), self::META, true);
		if (empty($meta)) {
			$meta = $order->get_meta(self::META);
		}
		return is_array($meta) ? $meta : array();
	}

	private static function save_meta($order, array $meta)
	{
		update_post_meta($order->get_id(), self::META, $meta);
	}

	private static function log($message)
	{
		if (function_exists('wc_get_logger')) {
			wc_get_logger()->info($message, array('source' => 'paynow'));
		}
	}

	/** Only ever call Paynow itself. */
	private static function is_paynow_url($url)
	{
		$host = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
		$scheme = strtolower((string) wp_parse_url((string) $url, PHP_URL_SCHEME));
		return 'https' === $scheme && ('paynow.co.zw' === $host || substr($host, -13) === '.paynow.co.zw');
	}

	/** The integration keys, the one used for this order's currency first. */
	private static function merchant_keys($order)
	{
		$settings = get_option('woocommerce_paynow_settings', array());
		$local = isset($settings['merchant_key']) ? (string) $settings['merchant_key'] : '';
		$forex = isset($settings['forex_merchant_key']) ? (string) $settings['forex_merchant_key'] : '';
		$keys = 'USD' === $order->get_currency() ? array($forex, $local) : array($local, $forex);
		return array_values(array_filter(array_unique($keys)));
	}

	/**
	 * Ask Paynow for the order's payment status and update the order.
	 *
	 * @param WC_Order|int $order
	 * @param bool         $force Skip the short throttle (used by staff and by Paynow's own notification).
	 * @return string paid | cancelled | failed | pending | not_paynow | no_poll_url | throttled | error
	 */
	public static function check($order, $force = false)
	{
		$order = is_object($order) ? $order : wc_get_order($order);
		if (!$order || 'paynow' !== $order->get_payment_method()) {
			return 'not_paynow';
		}
		if ($order->is_paid()) {
			return 'paid';
		}
		$meta = self::get_meta($order);
		if (empty($meta['PollUrl']) || !self::is_paynow_url($meta['PollUrl'])) {
			return 'no_poll_url';
		}
		// At most one request to Paynow per order every 15 seconds, however often this is called.
		$lock = 'wc_paynow_chk_' . $order->get_id();
		if (!$force && get_transient($lock)) {
			return 'throttled';
		}
		set_transient($lock, 1, 15);

		$response = wp_remote_post($meta['PollUrl'], array('timeout' => 30, 'body' => ''));
		if (is_wp_error($response)) {
			self::log('Order ' . $order->get_id() . ': could not reach Paynow (' . $response->get_error_message() . ').');
			return 'error';
		}
		$body = (string) wp_remote_retrieve_body($response);
		if ('' === $body || 200 !== (int) wp_remote_retrieve_response_code($response)) {
			self::log('Order ' . $order->get_id() . ': empty or unexpected reply from Paynow (HTTP ' . wp_remote_retrieve_response_code($response) . ').');
			return 'error';
		}
		$helper = new WC_Paynow_Helper();
		$msg = $helper->parseMsg($body);
		if (empty($msg['status']) || empty($msg['hash'])) {
			self::log('Order ' . $order->get_id() . ': Paynow reply had no status or hash.');
			return 'error';
		}
		$valid = false;
		foreach (self::merchant_keys($order) as $key) {
			if (hash_equals($helper->createHash($msg, $key), strtoupper((string) $msg['hash']))) {
				$valid = true;
				break;
			}
		}
		if (!$valid) {
			self::log('Order ' . $order->get_id() . ': Paynow reply failed the hash check. Check the integration keys.');
			return 'error';
		}

		$status = trim(strtolower((string) $msg['status']));
		if (!empty($msg['pollurl']) && self::is_paynow_url($msg['pollurl'])) {
			$meta['PollUrl'] = $msg['pollurl'];
		}
		if (isset($msg['paynowreference'])) {
			$meta['PaynowReference'] = $msg['paynowreference'];
		}
		if (isset($msg['amount'])) {
			$meta['Amount'] = $msg['amount'];
		}
		$meta['Status'] = $msg['status'];
		self::save_meta($order, $meta);

		if (in_array($status, array(PS_PAID, PS_AWAITING_DELIVERY, PS_DELIVERED), true)) {
			$reference = isset($msg['paynowreference']) ? (string) $msg['paynowreference'] : '';
			$paid = isset($msg['amount']) ? (float) $msg['amount'] : 0.0;
			if ($paid + 0.009 < (float) $order->get_total()) {
				// Paid, but less than the order total: let a person decide.
				$order->update_status('on-hold', sprintf('Paynow reports %s paid (reference %s), but the order total is %s. Please check.', $paid, $reference, $order->get_total()));
				return 'paid';
			}
			$order->payment_complete($reference);
			$order->add_order_note(sprintf('Payment confirmed by Paynow (reference %s).', $reference));
			return 'paid';
		}
		if (PS_CANCELLED === $status) {
			if ($order->has_status('pending')) {
				$order->update_status('cancelled', __('Payment cancelled on Paynow.', 'woothemes'));
			}
			return 'cancelled';
		}
		if (PS_FAILED === $status) {
			if ($order->has_status('pending')) {
				$order->update_status('failed', __('Payment failed on Paynow.', 'woothemes'));
			}
			return 'failed';
		}
		return 'pending';
	}

	/** The customer is back from Paynow: confirm the payment there and then. */
	public static function on_thankyou($order_id)
	{
		$order = wc_get_order($order_id);
		if (!$order || 'paynow' !== $order->get_payment_method() || $order->is_paid()) {
			return;
		}
		$result = self::check($order);
		if (in_array($result, array('pending', 'error', 'throttled'), true)) {
			self::schedule_order($order->get_id(), 0);
		}
	}

	public static function recheck_order($order_id, $step = 0)
	{
		$order = wc_get_order($order_id);
		if (!$order || $order->is_paid()) {
			return;
		}
		$result = self::check($order, true);
		if ('pending' === $result || 'error' === $result) {
			self::schedule_order($order_id, (int) $step + 1);
		}
	}

	/**
	 * Hourly: unpaid Paynow orders from the last few days. Includes orders WooCommerce cancelled
	 * itself for being unpaid ("hold stock"), because the customer may in fact have paid.
	 */
	public static function recheck_pending()
	{
		if (!function_exists('wc_get_orders')) {
			return;
		}
		$orders = wc_get_orders(array(
			'limit' => 40,
			'status' => array('wc-pending', 'wc-cancelled', 'wc-failed'),
			'payment_method' => 'paynow',
			'date_created' => '>' . (time() - self::RECHECK_DAYS * DAY_IN_SECONDS),
			'orderby' => 'date',
			'order' => 'DESC',
		));
		foreach ($orders as $order) {
			$meta = self::get_meta($order);
			$last = isset($meta['Status']) ? trim(strtolower((string) $meta['Status'])) : '';
			// Paynow already gave a final "no" for this one.
			if (in_array($last, array(PS_CANCELLED, PS_FAILED), true)) {
				continue;
			}
			$count = (int) $order->get_meta('_wc_paynow_checks');
			if ($count >= self::MAX_BACKGROUND_CHECKS) {
				continue;
			}
			$order->update_meta_data('_wc_paynow_checks', $count + 1);
			$order->save_meta_data();
			self::check($order, true);
		}
	}

	public static function order_actions($actions, $order = null)
	{
		if (!$order) {
			global $theorder;
			$order = $theorder;
		}
		if ($order && is_object($order) && 'paynow' === $order->get_payment_method() && !$order->is_paid()) {
			$actions['wc_paynow_check'] = __('Check payment with Paynow', 'woothemes');
		}
		return $actions;
	}

	public static function order_action_check($order)
	{
		$order = is_object($order) ? $order : wc_get_order($order);
		if (!$order) {
			return;
		}
		$result = self::check($order, true);
		$text = array(
			'paid' => 'Paynow confirms this order is paid.',
			'cancelled' => 'Paynow reports this payment as cancelled.',
			'failed' => 'Paynow reports this payment as failed.',
			'pending' => 'Paynow has not received payment for this order yet.',
			'no_poll_url' => 'This order has no Paynow transaction to check (the customer never reached Paynow).',
			'error' => 'Paynow could not be reached or its reply could not be verified. See WooCommerce > Status > Logs (paynow).',
		);
		$order->add_order_note('Manual Paynow check: ' . (isset($text[$result]) ? $text[$result] : $result));
	}
}
