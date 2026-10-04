<?php
/**
 * @package     Phoca Cart POS Discount (system plugin)
 * @copyright   (C) 2026 David Leeman. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Adds a manual discount (flat amount or percentage) to the Phoca Cart POS
 * "Apply Benefits" screen without touching Phoca Cart code or templates.
 *
 * How it works (see FLOW.md in the repository for the full write-up):
 *  1. onBeforeCompileHead  - on the POS page, for permitted users only, loads
 *                            a small script/stylesheet (Joomla WebAssetManager)
 *                            and passes options via Joomla script options.
 *  2. The script adds the discount control to the Apply Benefits form.
 *  3. onAjaxPhocacartposdiscount (com_ajax) - re-checks permission and limits
 *                            on the server, validates against the live ticket
 *                            total and creates ONE single-use POS-only coupon
 *                            through Phoca's own coupon table.
 *  4. The script puts that coupon code into Phoca's own coupon field and clicks
 *                            Phoca's own Apply button, so applying, totals, tax,
 *                            order history and receipts are all core behaviour.
 */

namespace DavidLeeman\Plugin\System\PhocaCartPosDiscount\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Table\Table;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\Event\SubscriberInterface;

final class PhocaCartPosDiscount extends CMSPlugin implements SubscriberInterface
{
	/** Prefix of every coupon code this plugin creates (also used by the script to recognise them). */
	private const CODE_PREFIX = 'MD-';

	/** Coupon "type" value in Phoca Cart: 0 common, 1 online shop, 2 POS. */
	private const COUPON_TYPE_POS = 2;

	protected $autoloadLanguage = true;

	public static function getSubscribedEvents(): array
	{
		return [
			'onBeforeCompileHead'        => 'onBeforeCompileHead',
			'onAjaxPhocacartposdiscount' => 'onAjaxPhocacartposdiscount',
		];
	}

	/**
	 * Load the POS script and options, only on the POS page and only for permitted users.
	 */
	public function onBeforeCompileHead($event = null): void
	{
		$app = $this->getApplication();

		if (!$app->isClient('site') || $app->getDocument()->getType() !== 'html') {
			return;
		}

		$input = $app->getInput();

		if ($input->getCmd('option') !== 'com_phocacart' || $input->getCmd('view') !== 'pos') {
			return;
		}

		$user        = $app->getIdentity();
		$permissions = $this->getPermissions($user);

		if ($permissions === null) {
			return;
		}

		$doc = $app->getDocument();
		$wa  = $doc->getWebAssetManager();
		$wa->getRegistry()->addExtensionRegistryFile('plg_system_phocacartposdiscount');
		$wa->useScript('plg_system_phocacartposdiscount.pos')
			->useStyle('plg_system_phocacartposdiscount.pos');

		$reasons = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $this->params->get('reasons', '')) ?: [])));

		$doc->addScriptOptions('plgPhocaCartPosDiscount', [
			'url'            => Uri::root() . 'index.php?option=com_ajax&plugin=phocacartposdiscount&group=system&format=json',
			'token'          => Session::getFormToken(),
			'prefix'         => self::CODE_PREFIX,
			'allowPercent'   => (bool) $this->params->get('allow_percent', 1),
			'allowFlat'      => (bool) $this->params->get('allow_flat', 1),
			'maxPercent'     => $permissions['percent'],
			'maxAmount'      => $permissions['amount'],
			'symbol'         => $this->getCurrencySymbol(),
			'requireReason'  => (bool) $this->params->get('require_reason', 0),
			'reasons'        => $reasons,
		]);

		foreach ([
			'LABEL', 'PERCENT', 'FLAT', 'APPLY', 'REMOVE', 'ACTIVE', 'REASON', 'REASON_CHOOSE',
			'ERR_VALUE', 'ERR_REASON', 'ERR_NETWORK', 'MAX_PERCENT', 'MAX_AMOUNT', 'WORKING',
		] as $key) {
			Text::script('PLG_SYSTEM_PHOCACARTPOSDISCOUNT_JS_' . $key);
		}
	}

	/**
	 * com_ajax endpoint: validate and create the single-use coupon, return its code.
	 *
	 * @param   \Joomla\Event\Event  $event
	 */
	public function onAjaxPhocacartposdiscount($event): void
	{
		try {
			$result = $this->createDiscountCoupon();
		} catch (\RuntimeException $e) {
			$result = ['ok' => false, 'message' => $e->getMessage()];
		} catch (\Throwable $e) {
			Log::add('POS discount: ' . $e->getMessage(), Log::ERROR, 'phocacartposdiscount');
			$result = ['ok' => false, 'message' => Text::_('PLG_SYSTEM_PHOCACARTPOSDISCOUNT_ERR_GENERIC')];
		}

		$event->addResult($result);
	}

	// ------------------------------------------------------------------
	// Permission
	// ------------------------------------------------------------------

	/**
	 * Whether the user may give discounts, and the effective limits.
	 *
	 * @return  array{percent: float, amount: float}|null  null = not allowed; 0 = no limit
	 */
	private function getPermissions(?User $user): ?array
	{
		if (!$user || $user->guest) {
			return null;
		}

		$userGroups    = array_map('intval', $user->getAuthorisedGroups());
		$allowedGroups = array_map('intval', (array) $this->params->get('allowed_groups', []));
		$allowed       = array_intersect($allowedGroups, $userGroups) !== [];

		if (!$allowed) {
			foreach ((array) $this->params->get('allowed_users', []) as $row) {
				if ((int) (((array) $row)['user'] ?? 0) === (int) $user->id) {
					$allowed = true;
					break;
				}
			}
		}

		if (!$allowed) {
			return null;
		}

		$limits = [
			'percent' => max(0.0, (float) $this->params->get('default_max_percent', 0)),
			'amount'  => max(0.0, (float) $this->params->get('default_max_amount', 0)),
		];

		// Group limits: if the user belongs to one or more listed groups the most generous
		// matching row wins (0 = unlimited). Otherwise the defaults above apply.
		$matched = [];

		foreach ((array) $this->params->get('group_limits', []) as $row) {
			$row = (array) $row;

			if (isset($row['group']) && \in_array((int) $row['group'], $userGroups, true)) {
				$matched[] = $row;
			}
		}

		if ($matched) {
			foreach (['percent' => 'max_percent', 'amount' => 'max_amount'] as $key => $field) {
				$values = array_map(static fn ($r) => max(0.0, (float) ($r[$field] ?? 0)), $matched);
				$limits[$key] = \in_array(0.0, $values, true) ? 0.0 : max($values);
			}
		}

		return $limits;
	}

	// ------------------------------------------------------------------
	// Coupon creation
	// ------------------------------------------------------------------

	private function createDiscountCoupon(): array
	{
		$app = $this->getApplication();

		if (!Session::checkToken('post')) {
			throw new \RuntimeException(Text::_('JINVALID_TOKEN_NOTICE'));
		}

		$user        = $app->getIdentity();
		$permissions = $this->getPermissions($user);

		if ($permissions === null) {
			Log::add('POS discount denied for user ' . (int) $user->id, Log::WARNING, 'phocacartposdiscount');
			throw new \RuntimeException(Text::_('PLG_SYSTEM_PHOCACARTPOSDISCOUNT_ERR_NOT_ALLOWED'));
		}

		if (!$this->loadPhoca()) {
			throw new \RuntimeException(Text::_('PLG_SYSTEM_PHOCACARTPOSDISCOUNT_ERR_NO_PHOCA'));
		}

		// The discount travels as a coupon, and Phoca's POS ignores coupon codes when coupons are disabled.
		if ((int) \PhocacartUtils::getComponentParameters()->get('enable_coupons', 2) < 1) {
			throw new \RuntimeException(Text::_('PLG_SYSTEM_PHOCACARTPOSDISCOUNT_ERR_COUPONS_OFF'));
		}

		$input = $app->getInput();
		$mode  = $input->post->getCmd('mode');
		$value = round((float) str_replace(',', '.', $input->post->getString('value')), 2);
		$reason = trim($input->post->getString('reason'));

		if (!\in_array($mode, ['percent', 'flat'], true)
			|| ($mode === 'percent' && !$this->params->get('allow_percent', 1))
			|| ($mode === 'flat' && !$this->params->get('allow_flat', 1))
			|| $value <= 0) {
			throw new \RuntimeException(Text::_('PLG_SYSTEM_PHOCACARTPOSDISCOUNT_ERR_VALUE'));
		}

		if ($this->params->get('require_reason', 0) && $reason === '') {
			throw new \RuntimeException(Text::_('PLG_SYSTEM_PHOCACARTPOSDISCOUNT_ERR_REASON'));
		}

		$context  = $this->readTicket();
		$vendor   = $context['vendor'];
		$ticket   = $context['ticket'];
		$unit     = $context['unit'];
		$section  = $context['section'];
		$quantity = $context['quantity'];
		$subtotal = $context['subtotal'];

		if (empty($vendor->id) || !$context['exists']) {
			throw new \RuntimeException(Text::_('PLG_SYSTEM_PHOCACARTPOSDISCOUNT_ERR_NO_TICKET'));
		}

		if ($quantity < 1 || $subtotal <= 0) {
			throw new \RuntimeException(Text::_('PLG_SYSTEM_PHOCACARTPOSDISCOUNT_ERR_EMPTY'));
		}

		if ($mode === 'percent') {
			if ($value > 100) {
				throw new \RuntimeException(Text::_('PLG_SYSTEM_PHOCACARTPOSDISCOUNT_ERR_VALUE'));
			}

			if ($permissions['percent'] > 0 && $value > $permissions['percent']) {
				throw new \RuntimeException(Text::sprintf('PLG_SYSTEM_PHOCACARTPOSDISCOUNT_ERR_MAX_PERCENT', $permissions['percent']));
			}
		} else {
			if ($value > $subtotal) {
				throw new \RuntimeException(Text::_('PLG_SYSTEM_PHOCACARTPOSDISCOUNT_ERR_TOO_HIGH'));
			}

			if ($permissions['amount'] > 0 && $value > $permissions['amount']) {
				throw new \RuntimeException(Text::sprintf('PLG_SYSTEM_PHOCACARTPOSDISCOUNT_ERR_MAX_AMOUNT', $this->formatAmount($permissions['amount'])));
			}
		}

		$scope = 'v' . (int) $vendor->id . '-t' . (int) $ticket->id . '-u' . (int) $unit->id . '-s' . (int) $section->id;
		$this->purge($scope);

		$code  = self::CODE_PREFIX . strtoupper(bin2hex(random_bytes(4)));
		$now   = Factory::getDate();
		$until = (clone $now)->modify('+' . max(1, (int) $this->params->get('validity_hours', 24)) . ' hours');
		$title = trim((string) $this->params->get('coupon_title', '')) ?: 'Staff discount';

		Table::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_phocacart/tables');
		/** @var \Joomla\CMS\Table\Table $row */
		$row = Table::getInstance('PhocacartCoupon', 'Table');

		$data = [
			'title'                   => $title,
			'alias'                   => strtolower($code),
			'code'                    => $code,
			'discount'                => $value,
			'calculation_type'        => $mode === 'percent' ? 1 : 0,
			'total_amount'            => 0,
			'quantity_from'           => 0,
			'available_quantity'      => 1,
			'available_quantity_user' => 0,
			'free_shipping'           => 0,
			'free_payment'            => 0,
			'category_filter'         => 0,
			'product_filter'          => 0,
			'type'                    => self::COUPON_TYPE_POS,
			'coupon_type'             => 0,
			'access'                  => 1,
			'published'               => 1,
			'language'                => '*',
			'ordering'                => 0,
			'valid_from'              => (clone $now)->modify('-1 hour')->toSql(),
			'valid_to'                => $until->toSql(),
			'description'             => $reason,
			'params'                  => json_encode([
				'phposdiscount' => 1,
				'scope'         => $scope,
				'mode'          => $mode,
				'value'         => $value,
				'reason'        => $reason,
				'given_by'      => (int) $user->id,
				'given_by_name' => $user->name,
				'created'       => $now->toSql(),
			]),
		];

		if (!$row->bind($data) || !$row->check() || !$row->store()) {
			throw new \RuntimeException($row->getError() ?: Text::_('PLG_SYSTEM_PHOCACARTPOSDISCOUNT_ERR_GENERIC'));
		}

		Log::add(sprintf('POS discount %s %s%s created by %d (%s)', $code, $value, $mode === 'percent' ? '%' : '', $user->id, $scope), Log::INFO, 'phocacartposdiscount');

		return ['ok' => true, 'code' => $code, 'title' => $title];
	}

	/**
	 * Read the current POS ticket (vendor, ticket, unit, section) and its total before any discount.
	 *
	 * Phoca Cart decides "this is POS" from the request (option = com_phocacart, controller = pos),
	 * but this request arrives through com_ajax, so the context is switched for these calls only
	 * and always restored.
	 *
	 * @return  array{vendor: object, ticket: object, unit: object, section: object, exists: bool, quantity: int, subtotal: float}
	 */
	private function readTicket(): array
	{
		$input      = $this->getApplication()->getInput();
		$origOption = $input->get('option');
		$origCtrl   = $input->get('controller');

		$input->set('option', 'com_phocacart');
		$input->set('controller', 'pos');

		try {
			$posUser = $vendor = $ticket = $unit = $section = [];
			\PhocacartUser::defineUser($posUser, $vendor, $ticket, $unit, $section, 1);

			$exists   = !empty($vendor->id)
				&& \PhocacartTicket::existsTicket((int) $vendor->id, (int) $ticket->id, (int) $unit->id, (int) $section->id);
			$quantity = 0;
			$subtotal = 0.0;

			if ($exists) {
				$cart = new \PhocacartCartRendercheckout();
				$cart->setType([0, 2]);
				$cart->setFullItems();
				$cart->roundTotalAmount();
				$total    = $cart->getTotal();
				$quantity = (int) ($total[0]['quantity'] ?? 0);
				$subtotal = max((float) ($total[0]['subtotalbrutto'] ?? 0), (float) ($total[0]['subtotalnetto'] ?? 0));
			}
		} finally {
			$input->set('option', $origOption);
			$input->set('controller', $origCtrl);
		}

		return compact('vendor', 'ticket', 'unit', 'section', 'exists', 'quantity', 'subtotal');
	}

	/**
	 * Delete unused manual-discount coupons: superseded ones on the same ticket and expired ones.
	 * A coupon that was used on an order, or sits in a cart, is never touched.
	 */
	private function purge(string $scope): void
	{
		$db  = Factory::getContainer()->get('DatabaseDriver');
		$now = Factory::getDate()->toSql();

		$flag  = $db->quote('%"phposdiscount":1%');
		$mine  = $db->quote('%"scope":"' . $db->escape($scope, true) . '"%', false);

		$query = $db->getQuery(true)
			->select($db->quoteName('c.id'))
			->from($db->quoteName('#__phocacart_coupons', 'c'))
			->where($db->quoteName('c.params') . ' LIKE ' . $flag)
			->where('(' . $db->quoteName('c.params') . ' LIKE ' . $mine . ' OR ' . $db->quoteName('c.valid_to') . ' < ' . $db->quote($now) . ')')
			->where('NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__phocacart_coupon_count', 'cc') . ' WHERE cc.coupon_id = c.id AND cc.count > 0)')
			->where('NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__phocacart_orders', 'o') . ' WHERE o.coupon_id = c.id)')
			->where('NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__phocacart_cart_multiple', 'm') . ' WHERE m.coupon = c.id AND ' . $db->quoteName('c.params') . ' NOT LIKE ' . $mine . ')');

		$ids = $db->setQuery($query)->loadColumn();

		if ($ids) {
			$db->setQuery(
				$db->getQuery(true)
					->delete($db->quoteName('#__phocacart_coupons'))
					->whereIn($db->quoteName('id'), array_map('intval', $ids))
			)->execute();
		}
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/** Format an amount with Phoca Cart's own price formatter (currency symbol, separators). */
	private function formatAmount(float $amount): string
	{
		try {
			return (string) (new \PhocacartPrice())->getPriceFormat($amount);
		} catch (\Throwable $e) {
			return number_format($amount, 2);
		}
	}

	private function loadPhoca(): bool
	{
		if (class_exists('PhocacartUser', false)) {
			return true;
		}

		$bootstrap = JPATH_ADMINISTRATOR . '/components/com_phocacart/libraries/bootstrap.php';

		if (!is_file($bootstrap)) {
			return false;
		}

		require_once $bootstrap;

		return class_exists('PhocacartUser');
	}

	private function getCurrencySymbol(): string
	{
		try {
			if ($this->loadPhoca()) {
				$currency = \PhocacartCurrency::getCurrency();

				if ($currency && !empty($currency->price_currency_symbol)) {
					return (string) $currency->price_currency_symbol;
				}
			}
		} catch (\Throwable $e) {
			// fall through
		}

		return '';
	}
}
