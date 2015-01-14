<?php
/**
 * 2014-2015 (c) Axalone France - Express-Mailing
 *
 * This file is a commercial module for Prestashop
 * Do not edit or add to this file if you wish to upgrade PrestaShop or
 * customize PrestaShop for your needs please refer to
 * http://www.express-mailing.com for more information.
 *
 * @author    Axalone France <info@express-mailing.com>
 * @copyright 2014-2015 (c) Axalone France
 * @license   http://opensource.org/licenses/GPL-3.0  GNU General Public License, version 3 (GPL-3.0)
 */

/**
 * Static class used for getting data from DB
 */
class DBMarketing
{
	public static function getCustomersEmailRequest($campaign_id,
		$checked_langs, $checked_groups,
		$checked_campaign_optin, $checked_campaign_newsletter, $checked_campaign_active,
		$checked_products, $checked_categories,
		$paying_filters = array(), $extended = false,
		$limit = 0, &$list_total = null)
	{
		$sql_calc_found = is_null($list_total) ? '' : 'SQL_CALC_FOUND_ROWS ';

		$req = new DbQuery();
		$req->select($sql_calc_found.$campaign_id.' as campaign_id, customer.id_customer, customer.id_lang,
						customer.firstname, customer.lastname, customer.email,
						INET_NTOA(connections.ip_address) as ip_address,
						country.iso_code, address.postcode as zip, address.city,
						UNIX_TIMESTAMP(MAX(connections.date_add)) as last_connexion_date, \'prestashop\' as source');
		$req->from('customer', 'customer');
		$req->leftJoin('customer_group', 'customer_group', 'customer_group.id_customer = customer.id_customer');
		$req->leftJoin('guest', 'guest', 'guest.id_customer = customer.id_customer');
		$req->leftJoin('connections', 'connections', 'connections.id_guest = guest.id_guest');
		$req->leftJoin('address', 'address', 'address.id_customer = customer.id_customer');
		$req->leftJoin('country', 'country', 'address.id_country = country.id_country');

		if (!empty($checked_langs))
			$req->where('customer.id_lang IN('.implode(', ', $checked_langs).')');

		if (!empty($checked_groups))
			$req->where('customer_group.id_group IN('.implode(', ', $checked_groups).')');

		if ($checked_campaign_optin)
			$req->where('customer.optin = 1');

		if ($checked_campaign_newsletter)
			$req->where('customer.newsletter = 1');

		if ($checked_campaign_active)
			$req->where('customer.active = 1');

		if (!empty($checked_products) || !empty($checked_categories))
		{
			$where_products_categories = array();

			$req->leftJoin('cart', 'cart', 'cart.id_customer = customer.id_customer');
			$req->leftJoin('cart_product', 'cart_product', 'cart_product.id_cart = cart.id_cart');

			if (!empty($checked_products))
				$where_products_categories[] = 'cart_product.id_product IN('.implode(', ', $checked_products).')';

			if (!empty($checked_categories))
			{
				$req->leftJoin('category_product', 'category_product', 'category_product.id_product = cart_product.id_product');
				$where_products_categories[] = 'category_product.id_category IN('.implode(', ', $checked_categories).')';
			}

			$req->where(implode(' OR ', $where_products_categories));
		}

		if (isset($paying_filters['birthday']))
			if ($birthday_sql = self::generateSQLWhereBirthday($paying_filters['birthday']))
				$req->where($birthday_sql);

		if (isset($paying_filters['civilities']) && !empty($paying_filters['civilities']))
			$req->where('customer.id_gender IN ('.implode(', ', $paying_filters['civilities']).')');

		if (isset($paying_filters['countries']) && !empty($paying_filters['countries']))
			$req->where('country.id_country IN ('.implode(', ', $paying_filters['countries']).')');

		if (isset($paying_filters['postcodes']) && !empty($paying_filters['postcodes']))
		{
			$where_or = array();
			foreach ($paying_filters['postcodes'] as $value)
				$where_or[] = 'address.id_country = '.$value['country_id'].' AND address.postcode LIKE "'.$value['postcode'].'%"';
			$req->where('('.implode(' OR ', $where_or).')');
		}

		if (isset($paying_filters['buyingdates']) && !empty($paying_filters['buyingdates']))
		{
			$req->innerJoin('orders', 'orders', 'orders.id_customer = customer.id_customer AND (orders.date_add BETWEEN \''.
				$paying_filters['buyingdates']['min_buyingdate'].'\' AND \''.
				$paying_filters['buyingdates']['max_buyingdate'].'\' OR orders.date_upd BETWEEN \''.
				$paying_filters['buyingdates']['min_buyingdate'].'\' AND \''.$paying_filters['buyingdates']['max_buyingdate'].'\')');
		}

		if (isset($paying_filters['accountdates']) && !empty($paying_filters['accountdates']))
		{
			$req->where('customer.date_add BETWEEN \''.$paying_filters['accountdates']['min_accountdate'].
				'\' AND \''.$paying_filters['accountdates']['max_accountdate'].'\'');
		}

		if (isset($paying_filters['promocodes']) && !empty($paying_filters['promocodes']))
		{
			$req->leftJoin('orders', 'orders2', 'orders2.id_customer = customer.id_customer');
			$req->leftJoin('cart', 'cart', 'orders2.id_cart = cart.id_cart');
			$req->leftJoin('cart_cart_rule', 'cart_cart_rule', 'cart_cart_rule.id_cart = cart.id_cart');
			$req->leftJoin('cart_rule', 'cart_rule', 'cart_rule.id_cart_rule = cart_cart_rule.id_cart_rule');

			$codes = array();
			foreach ($paying_filters['promocodes'] as $value)
			{
				if ($value['promocode_type'] == 'specific')
					$codes[] = $value['promocode'];
				elseif ($value['promocode_type'] == 'any')
				{
					$req->where('(cart_rule.code IS NOT NULL OR cart_rule.code <> \'\')');
					break;
				}
				elseif ($value['promocode_type'] == 'never')
				{
					$req->orderBy('cart_rule.code');
					$req->select('cart_rule.code');
					$req->having('cart_rule.code IS NULL OR cart_rule.code = \'\'');
					break;
				}
			}

			if (!empty($codes))
				$req->where('cart_rule.code in (\''.implode('\', \'', $codes).'\')');
		}

		if (!$extended)
			$req->where('connections.ip_address IS NOT NULL');

		$req->orderby('customer.id_customer');
		$req->groupby('customer.id_customer');

		$limit = (int)$limit;
		if ($limit)
			$req->limit($limit);

		return $req;
	}

	public static function getCustomersEmail($campaign_id,
		$checked_langs, $checked_groups,
		$checked_campaign_optin, $checked_campaign_newsletter, $checked_campaign_active,
		$checked_products, $checked_categories,
		$paying_filters = array(), $extended = false,
		$limit = 0, &$list_total = null)
	{
		$req = selft::getCustomersEmailRequest($campaign_id,
												$checked_langs, $checked_groups,
												$checked_campaign_optin, $checked_campaign_newsletter, $checked_campaign_active,
												$checked_products, $checked_categories,
												$paying_filters, $extended,
												$limit, $list_total);

		$customers_list = Db::getInstance()->executeS($req, true, false);

		if (!is_null($list_total))
			$list_total = Db::getInstance()->getValue('SELECT FOUND_ROWS()', false);

		return $customers_list;
	}

	public static function getPayingFiltersEmailDB($campaign_id)
	{
		$paying_filters = array();

		// Birthday
		$req = new DbQuery();
		$req->select('birthday_type, birthday_start, birthday_end');
		$req->from('expressmailing_email_birthdays');
		$req->where('campaign_id = '.$campaign_id);
		$birthday = Db::getInstance()->executeS($req, true, false);
		if (!empty($birthday))
			$paying_filters['birthday'] = $birthday[0];

		// Civility
		$req = new DbQuery();
		$req->select('civility_id');
		$req->from('expressmailing_email_civilities');
		$req->where('campaign_id = '.$campaign_id);
		$civilities = Db::getInstance()->executeS($req, true, false);
		$formated_civilites = array();
		foreach ($civilities as $civility)
			$formated_civilites[] = $civility['civility_id'];
		if (!empty($formated_civilites))
			$paying_filters['civilities'] = $formated_civilites;

		// Country
		$req = new DbQuery();
		$req->select('country_id');
		$req->from('expressmailing_email_countries');
		$req->where('campaign_id = '.$campaign_id);
		$countries = Db::getInstance()->executeS($req, true, false);
		$formated_countries = array();
		foreach ($countries as $country)
			$formated_countries[] = $country['country_id'];
		if (!empty($formated_countries))
			$paying_filters['countries'] = $formated_countries;

		// Postcode
		$req = new DbQuery();
		$req->select('country_id, postcode');
		$req->from('expressmailing_email_postcodes');
		$req->where('campaign_id = '.$campaign_id);
		$postcodes = Db::getInstance()->executeS($req, true, false);
		if (!empty($postcodes))
			$paying_filters['postcodes'] = $postcodes;

		// Buying date
		$req = new DbQuery();
		$req->select('min_buyingdate, max_buyingdate');
		$req->from('expressmailing_email_buyingdates');
		$req->where('campaign_id = '.$campaign_id);
		$buyingdates = Db::getInstance()->executeS($req, true, false);
		if (!empty($buyingdates))
			$paying_filters['buyingdates'] = $buyingdates[0];

		// Account creation date
		$req = new DbQuery();
		$req->select('min_accountdate, max_accountdate');
		$req->from('expressmailing_email_accountdates');
		$req->where('campaign_id = '.$campaign_id);
		$accountdates = Db::getInstance()->executeS($req, true, false);
		if (!empty($accountdates))
			$paying_filters['accountdates'] = $accountdates[0];

		// Promotion codes
		$req = new DbQuery();
		$req->select('promocode_type, promocode');
		$req->from('expressmailing_email_promocodes');
		$req->where('campaign_id = '.$campaign_id);
		$promocodes = Db::getInstance()->executeS($req, true, false);
		if (!empty($promocodes))
			$paying_filters['promocodes'] = $promocodes;

		return $paying_filters;
	}

	private static function generateSQLWhereBirthday($filter)
	{
		switch ($filter['birthday_type'])
		{
			case 'age':
				$return = 'customer.birthday BETWEEN DATE_SUB(CURDATE(), INTERVAL '.$filter['birthday_end'].' YEAR)
							AND DATE_SUB(CURDATE(), INTERVAL '.$filter['birthday_start'].' YEAR)';
				break;
			case 'day':
				$day_start = explode('-', $filter['birthday_start'])[0];
				$month_start = explode('-', $filter['birthday_start'])[1];
				$day_end = explode('-', $filter['birthday_end'])[0];
				$month_end = explode('-', $filter['birthday_end'])[1];
				$return = '(MONTH(customer.birthday) BETWEEN '.$month_start.' AND '.$month_end.')
							AND (DAY(customer.birthday) BETWEEN '.$day_start.' AND '.$day_end.')';
				break;
			case 'date':
				$return = 'customer.birthday BETWEEN \''.$filter['birthday_start'].'\' AND \''.$filter['birthday_end'].'\'';
				break;
			default:
				$return = false;
		}

		return $return;
	}

	public static function getCustomersSmsRequest($campaign_id,
		$checked_langs, $checked_groups,
		$checked_campaign_active,
		$checked_products, $checked_categories,
		$limit = 0, &$list_total = null)
	{
		$sql_calc_found = is_null($list_total) ? '' : 'SQL_CALC_FOUND_ROWS ';

		$req = new DbQuery();
		$req->select($sql_calc_found.$campaign_id.' as campaign_id, address.phone_mobile as target,
				address.phone_mobile as col_0, customer.lastname as col_1, customer.firstname as col_2,address.postcode as col_3,
				address.city as col_4, \'prestashop\' as source');
		$req->from('customer', 'customer');
		$req->leftJoin('customer_group', 'customer_group', 'customer_group.id_customer = customer.id_customer');
		$req->leftJoin('guest', 'guest', 'guest.id_customer = customer.id_customer');
		$req->leftJoin('connections', 'connections', 'connections.id_guest = guest.id_guest');
		$req->innerJoin('address', 'address', 'address.id_customer = customer.id_customer AND address.phone_mobile <> \'\'');
		$req->leftJoin('country', 'country', 'address.id_country = country.id_country');

		$where = array();
		$where[] = 'address.phone_mobile IS NOT NULL AND address.phone_mobile <> \'\'';

		if (!empty($checked_langs))
			$where[] = 'customer.id_lang IN('.implode(', ', $checked_langs).')';
		if (!empty($checked_groups))
			$where[] = 'customer_group.id_group IN('.implode(', ', $checked_groups).')';
		if ($checked_campaign_active)
			$where[] = 'customer.active = 1';

		if (!empty($checked_products) || !empty($checked_categories))
		{
			$where_products_categories = array();
			$req->leftJoin('cart', 'cart', 'cart.id_customer = customer.id_customer');
			$req->leftJoin('cart_product', 'cart_product', 'cart_product.id_cart = cart.id_cart');

			if (!empty($checked_products))
				$where_products_categories[] = 'cart_product.id_product IN('.implode(', ', $checked_products).')';

			if (!empty($checked_categories))
			{
				$req->leftJoin('category_product', 'category_product', 'category_product.id_product = cart_product.id_product');
				$where_products_categories[] = 'category_product.id_category IN('.implode(', ', $checked_categories).')';
			}

			$where[] = implode(' OR ', $where_products_categories);
		}

		$req->where(implode(' AND ', $where));
		$req->orderby('customer.id_customer');
		$req->groupby('customer.id_customer');

		$limit = (int)$limit;
		if ($limit)
			$req->limit($limit);

		return $req;
	}

	public static function getCustomersSms($campaign_id,
		$checked_langs, $checked_groups,
		$checked_campaign_active,
		$checked_products, $checked_categories,
		$limit = 0, &$list_total = null)
	{
		$req = self::getCustomersSmsRequest($campaign_id,
											$checked_langs, $checked_groups,
											$checked_campaign_active,
											$checked_products, $checked_categories,
											$limit, $list_total);

		$customers_list = Db::getInstance()->executeS($req, true, false);

		if (!is_null($list_total))
			$list_total = Db::getInstance()->getValue('SELECT FOUND_ROWS()', false);

		return $customers_list;
	}

}
