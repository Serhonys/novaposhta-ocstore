<?php
trait NovaposhtaDesk {
	public function ensureDesk() {
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "novaposhta_queue` (
			`order_id` int(11) NOT NULL,
			`payload` text NOT NULL,
			`error` varchar(400) NOT NULL DEFAULT '',
			`tries` int(11) NOT NULL DEFAULT 0,
			`created_at` datetime NOT NULL,
			PRIMARY KEY (`order_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "novaposhta_sheet` (
			`sheet_id` int(11) NOT NULL AUTO_INCREMENT,
			`ref` char(36) NOT NULL DEFAULT '',
			`number` varchar(32) NOT NULL DEFAULT '',
			`sheet_date` varchar(16) NOT NULL DEFAULT '',
			`created_at` datetime NOT NULL,
			PRIMARY KEY (`sheet_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
	}

	public function orderCargo($orderId) {
		$weightClass = $this->unitClass('weight', array('kg', 'кг'));
		$lengthClass = $this->unitClass('length', array('cm', 'см'));
		$query = $this->db->query("SELECT op.name, op.model, op.quantity, p.sku, p.weight, p.weight_class_id, p.length, p.width, p.height, p.length_class_id
			FROM `" . DB_PREFIX . "order_product` op
			LEFT JOIN `" . DB_PREFIX . "product` p ON p.product_id = op.product_id
			WHERE op.order_id = '" . (int)$orderId . "'");

		$names = array();
		$models = array();
		$skus = array();
		$qty = 0;
		$weight = 0;
		$places = array();
		foreach ($query->rows as $row) {
			$piece = max(1, (int)$row['quantity']);
			$names[] = $row['name'] . ($piece > 1 ? ' ×' . $piece : '');
			if ($row['model'] !== '') {
				$models[] = $row['model'];
			}
			if (!empty($row['sku'])) {
				$skus[] = $row['sku'];
			}
			$qty += $piece;
			$w = (float)$row['weight'];
			if ($weightClass && $this->weight && $row['weight_class_id']) {
				$w = $this->weight->convert($w, $row['weight_class_id'], $weightClass);
			}
			$weight += $w * $piece;
			$l = (float)$row['length'];
			$wd = (float)$row['width'];
			$h = (float)$row['height'];
			if ($lengthClass && $this->length && $row['length_class_id']) {
				$l = $this->length->convert($l, $row['length_class_id'], $lengthClass);
				$wd = $this->length->convert($wd, $row['length_class_id'], $lengthClass);
				$h = $this->length->convert($h, $row['length_class_id'], $lengthClass);
			}
			if ($w > 0 || $l > 0) {
				$places[] = array(
					'weight' => $w > 0 ? $w * $piece : 0.1,
					'length' => (int)round($l),
					'width'  => (int)round($wd),
					'height' => (int)round($h)
				);
			}
		}

		$products = implode(', ', $names);
		$description = $products;
		if (function_exists('mb_substr')) {
			$description = mb_substr($description, 0, 100, 'UTF-8');
		} else {
			$description = substr($description, 0, 100);
		}
		if ($weight <= 0) {
			$weight = (float)$this->config->get('shipping_novaposhta_default_weight');
		}
		if ($weight <= 0) {
			$weight = 0.5;
		}

		return array(
			'description' => $description,
			'products'    => $products,
			'models'      => implode(', ', $models),
			'skus'        => implode(', ', $skus),
			'qty'         => $qty,
			'weight'      => round($weight, 3),
			'places'      => $places
		);
	}

	public function applyDescription($template, array $order, array $cargo = array()) {
		$phone = isset($order['telephone']) ? preg_replace('/\D+/', '', $order['telephone']) : '';
		$city = '';
		if (!empty($order['shipping_city'])) {
			$city = $order['shipping_city'];
		} elseif (!empty($order['payment_city'])) {
			$city = $order['payment_city'];
		}
		$date = '';
		if (!empty($order['date_added'])) {
			$ts = strtotime($order['date_added']);
			$date = $ts ? date('d.m.Y', $ts) : $order['date_added'];
		}
		$cost = isset($order['total']) ? (string)round($this->toUah($order['total'])) : '';
		$company = '';
		if (!empty($order['shipping_company'])) {
			$company = $order['shipping_company'];
		} elseif (!empty($order['payment_company'])) {
			$company = $order['payment_company'];
		}
		$seats = !empty($cargo['places']) ? count($cargo['places']) : 1;
		$map = array(
			'{order_id}'  => isset($order['order_id']) ? $order['order_id'] : '',
			'{firstname}' => isset($order['firstname']) ? $order['firstname'] : '',
			'{lastname}'  => isset($order['lastname']) ? $order['lastname'] : '',
			'{phone}'     => $phone,
			'{telephone}' => $phone,
			'{email}'     => isset($order['email']) ? $order['email'] : '',
			'{city}'      => $city,
			'{address}'   => isset($order['shipping_address_1']) ? $order['shipping_address_1'] : '',
			'{company}'   => $company,
			'{products}'  => !empty($cargo['products']) ? $cargo['products'] : (isset($cargo['description']) ? $cargo['description'] : ''),
			'{models}'    => isset($cargo['models']) ? $cargo['models'] : '',
			'{sku}'       => isset($cargo['skus']) ? $cargo['skus'] : '',
			'{qty}'       => isset($cargo['qty']) ? $cargo['qty'] : '',
			'{weight}'    => isset($cargo['weight']) ? $cargo['weight'] : '',
			'{seats}'     => $seats,
			'{cost}'      => $cost,
			'{date}'      => $date,
			'{comment}'   => isset($order['comment']) ? trim(preg_replace('/\s+/u', ' ', strip_tags($order['comment']))) : '',
			'{payment}'   => isset($order['payment_method']) ? trim(strip_tags($order['payment_method'])) : '',
			'{shipping}'  => isset($order['shipping_method']) ? trim(strip_tags($order['shipping_method'])) : ''
		);
		$text = strtr((string)$template, $map);
		$text = trim(preg_replace('/\s+/u', ' ', $text));
		if (function_exists('mb_substr')) {
			$text = mb_substr($text, 0, 120, 'UTF-8');
		} else {
			$text = substr($text, 0, 120);
		}
		return $text;
	}

	public function cargoType(array $input) {
		$allowed = array('Parcel', 'Cargo', 'Pallet', 'TiresWheels', 'Documents');
		$type = isset($input['cargo']) ? $input['cargo'] : 'Parcel';
		return in_array($type, $allowed, true) ? $type : 'Parcel';
	}

	public function enqueue($orderId, array $input, $error) {
		$orderId = (int)$orderId;
		if (!$orderId) {
			return;
		}
		$payload = json_encode($input, JSON_UNESCAPED_UNICODE);
		if (!$payload) {
			$payload = '{}';
		}
		$this->db->query("REPLACE INTO `" . DB_PREFIX . "novaposhta_queue` SET
			`order_id` = '" . $orderId . "',
			`payload` = '" . $this->db->escape($payload) . "',
			`error` = '" . $this->db->escape(substr((string)$error, 0, 400)) . "',
			`tries` = '0',
			`created_at` = NOW()");
	}

	public function queueCount() {
		$query = $this->db->query("SHOW TABLES LIKE '" . DB_PREFIX . "novaposhta_queue'");
		if (!$query->num_rows) {
			return 0;
		}
		$count = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "novaposhta_queue`");
		return (int)$count->row['total'];
	}

	public function processQueue($limit = 10) {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "novaposhta_queue` WHERE `tries` < 5 ORDER BY `created_at` LIMIT " . (int)$limit);
		$done = 0;
		$failed = 0;
		foreach ($query->rows as $item) {
			$row = $this->getOrderRow($item['order_id']);
			if ($row && $row['cn_number'] !== '') {
				$this->db->query("DELETE FROM `" . DB_PREFIX . "novaposhta_queue` WHERE `order_id` = '" . (int)$item['order_id'] . "'");
				continue;
			}
			$orderQuery = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE order_id = '" . (int)$item['order_id'] . "' LIMIT 1");
			if (!$orderQuery->num_rows || !$row) {
				$failed++;
				continue;
			}
			$input = json_decode($item['payload'], true);
			if (!is_array($input)) {
				$input = array();
			}
			if (empty($input['weight']) || empty($input['places']) || empty($input['description'])) {
				$cargo = $this->orderCargo($item['order_id']);
				if (empty($input['weight'])) {
					$input['weight'] = $cargo['weight'];
				}
				if (empty($input['places']) && !empty($cargo['places'])) {
					$input['places'] = $cargo['places'];
				}
				if (empty($input['description']) && $cargo['description'] !== '') {
					$input['description'] = $cargo['description'];
				}
			}
			$input['no_queue'] = 1;
			$result = $this->createDocument($orderQuery->row, $row, $input);
			if (!empty($result['number'])) {
				$this->db->query("DELETE FROM `" . DB_PREFIX . "novaposhta_queue` WHERE `order_id` = '" . (int)$item['order_id'] . "'");
				$done++;
			} else {
				$this->db->query("UPDATE `" . DB_PREFIX . "novaposhta_queue` SET
					`tries` = `tries` + 1,
					`error` = '" . $this->db->escape(isset($result['error']) ? substr($result['error'], 0, 400) : '') . "'
					WHERE `order_id` = '" . (int)$item['order_id'] . "'");
				$failed++;
			}
		}
		return array('done' => $done, 'failed' => $failed);
	}

	public function saveSheet($ref, $number, $date) {
		if ($ref === '' && $number === '') {
			return;
		}
		$this->db->query("INSERT INTO `" . DB_PREFIX . "novaposhta_sheet` SET
			`ref` = '" . $this->db->escape($ref) . "',
			`number` = '" . $this->db->escape($number) . "',
			`sheet_date` = '" . $this->db->escape($date) . "',
			`created_at` = NOW()");
	}

	public function sheets($limit = 12) {
		$query = $this->db->query("SHOW TABLES LIKE '" . DB_PREFIX . "novaposhta_sheet'");
		if (!$query->num_rows) {
			return array();
		}
		return $this->db->query("SELECT * FROM `" . DB_PREFIX . "novaposhta_sheet` ORDER BY `sheet_id` DESC LIMIT " . (int)$limit)->rows;
	}

	public function alertManager(array $order, $code, $text) {
		$watch = array(7 => 'Прибуло', 8 => 'Прибуло в поштомат', 10 => 'Гроші відправлені', 102 => 'Відмова', 103 => 'Відмова');
		if (!isset($watch[$code])) {
			return;
		}
		$to = trim((string)$this->config->get('shipping_novaposhta_alert_email'));
		if ($to === '') {
			$to = (string)$this->config->get('config_email');
		}
		if ($to === '') {
			return;
		}
		try {
			$engine = $this->config->get('config_mail_engine');
			if (!$engine) {
				$engine = $this->config->get('config_mail_protocol');
			}
			if (!$engine) {
				$engine = 'mail';
			}
			$mail = new Mail($engine);
			$mail->parameter = $this->config->get('config_mail_parameter');
			$mail->smtp_hostname = $this->config->get('config_mail_smtp_hostname');
			$mail->smtp_username = $this->config->get('config_mail_smtp_username');
			$mail->smtp_password = html_entity_decode((string)$this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8');
			$mail->smtp_port = $this->config->get('config_mail_smtp_port');
			$mail->smtp_timeout = $this->config->get('config_mail_smtp_timeout');
			$mail->setTo($to);
			$mail->setFrom($this->config->get('config_email'));
			$mail->setSender(html_entity_decode((string)$this->config->get('config_name'), ENT_QUOTES, 'UTF-8'));
			$mail->setSubject('НП ' . $watch[$code] . ' #' . $order['order_id']);
			$mail->setText('Замовлення #' . $order['order_id'] . "\n" . $order['cn_number'] . "\n" . $text);
			$mail->send();
		} catch (Exception $e) {
		}
	}

	public function fetchPdf($url) {
		if (strpos($url, 'https://my.novaposhta.ua/') !== 0 || !function_exists('curl_init')) {
			return false;
		}
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_CONNECTTIMEOUT => 8,
			CURLOPT_TIMEOUT        => 40
		));
		$body = curl_exec($ch);
		curl_close($ch);
		if (!$body || strlen($body) < 100 || strpos($body, '%PDF') !== 0) {
			return false;
		}
		return $body;
	}

	public function warehouseHours($ref) {
		$ref = trim((string)$ref);
		if ($ref === '') {
			return '';
		}
		$key = sha1('hours|' . $ref);
		$query = $this->db->query("SELECT `value`, `created_at` FROM `" . DB_PREFIX . "novaposhta_cache` WHERE `cache_key` = '" . $this->db->escape($key) . "' LIMIT 1");
		if ($query->num_rows && strtotime($query->row['created_at']) > time() - 86400) {
			return $query->row['value'];
		}
		$data = $this->api()->call('Address', 'getWarehouses', array('Ref' => $ref), 8);
		$text = '';
		if (is_array($data) && !empty($data[0]['Schedule']) && is_array($data[0]['Schedule'])) {
			$days = $data[0]['Schedule'];
			$first = '';
			foreach ($days as $day => $hours) {
				if ($hours) {
					$first = $day . ' ' . $hours;
					break;
				}
			}
			$text = substr($first, 0, 80);
		}
		if ($text !== '') {
			$this->db->query("REPLACE INTO `" . DB_PREFIX . "novaposhta_cache` SET
				`cache_key` = '" . $this->db->escape($key) . "',
				`value` = '" . $this->db->escape($text) . "',
				`created_at` = NOW()");
		}
		return $text;
	}

	public function timeIntervals() {
		$key = sha1('intervals');
		$query = $this->db->query("SELECT `value`, `created_at` FROM `" . DB_PREFIX . "novaposhta_cache` WHERE `cache_key` = '" . $this->db->escape($key) . "' LIMIT 1");
		if ($query->num_rows && strtotime($query->row['created_at']) > time() - 86400) {
			$cached = json_decode($query->row['value'], true);
			if (is_array($cached) && $cached) {
				return $cached;
			}
		}
		$data = $this->api()->call('Common', 'getTimeIntervals', array(), 8);
		$out = array();
		if (is_array($data)) {
			foreach ($data as $row) {
				if (empty($row['Number']) && empty($row['Ref'])) {
					continue;
				}
				$out[] = array(
					'id'   => !empty($row['Number']) ? $row['Number'] : $row['Ref'],
					'name' => isset($row['Description']) ? $row['Description'] : (!empty($row['Start']) ? $row['Start'] . '–' . $row['End'] : '')
				);
			}
		}
		if (!$out) {
			$out = array(
				array('id' => 'CityPickingTimeInterval1', 'name' => '09:00–12:00'),
				array('id' => 'CityPickingTimeInterval2', 'name' => '12:00–15:00'),
				array('id' => 'CityPickingTimeInterval3', 'name' => '15:00–18:00')
			);
		} else {
			$encoded = json_encode($out, JSON_UNESCAPED_UNICODE);
			if ($encoded && strlen($encoded) <= 250) {
				$this->db->query("REPLACE INTO `" . DB_PREFIX . "novaposhta_cache` SET
					`cache_key` = '" . $this->db->escape($key) . "',
					`value` = '" . $this->db->escape($encoded) . "',
					`created_at` = NOW()");
			}
		}
		return $out;
	}

	public function savePoint($orderId, array $selection) {
		$orderId = (int)$orderId;
		if (!$orderId) {
			return;
		}
		$current = $this->getOrderRow($orderId);
		if (!$current) {
			$this->saveSelection($orderId, $selection);
			return;
		}
		$fields = array('method', 'city_ref', 'city_name', 'division_ref', 'division_name', 'settlement_ref', 'street_ref', 'street_name', 'building', 'flat');
		$sets = array();
		foreach ($fields as $field) {
			if (isset($selection[$field])) {
				$sets[] = "`" . $field . "` = '" . $this->db->escape((string)$selection[$field]) . "'";
			}
		}
		if ($sets) {
			$this->db->query("UPDATE `" . DB_PREFIX . "novaposhta_order` SET " . implode(', ', $sets) . " WHERE `order_id` = '" . $orderId . "'");
		}
	}

	public function updateWaybill($orderId, array $input) {
		$row = $this->getOrderRow($orderId);
		if (!$row || $row['cn_ref'] === '') {
			return array('error' => 'TTN not found');
		}
		if (!empty($input['city_ref']) || !empty($input['division_ref'])) {
			$this->savePoint($orderId, $input);
			$row = $this->getOrderRow($orderId);
		}
		$orderQuery = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE order_id = '" . (int)$orderId . "' LIMIT 1");
		if (!$orderQuery->num_rows) {
			return array('error' => 'Order not found');
		}
		$order = $orderQuery->row;
		$sender = $this->resolveSender($row['sender_id']);
		$api = $this->api($sender['api_key']);
		$phone = NovaposhtaApi::phone(!empty($input['phone']) ? $input['phone'] : $order['telephone']);
		$method = !empty($row['method']) ? $row['method'] : 'department';
		$senderType = $sender['type'] === 'Doors' ? 'Doors' : 'Warehouse';
		$service = $method === 'doors' ? $senderType . 'Doors' : $senderType . 'Warehouse';
		$pair = $this->recipientPair($api, $order['firstname'], $order['lastname'] !== '' ? $order['lastname'] : $order['firstname'], $phone, isset($order['email']) ? $order['email'] : '');
		if (!empty($pair['error'])) {
			return $pair;
		}
		$weight = isset($input['weight']) ? (float)$input['weight'] : 0;
		if ($weight <= 0) {
			$weight = $this->orderWeight($orderId);
		}
		$cost = isset($input['cost']) ? (float)$input['cost'] : 0;
		if ($cost <= 0) {
			$cost = round($this->toUah($order['total']));
		}
		$description = !empty($input['description']) ? $input['description'] : '';
		$cargo = $this->orderCargo($orderId);
		if ($description === '') {
			$template = (string)$this->config->get('shipping_novaposhta_cargo_description');
			$description = $template !== '' ? $template : 'Замовлення {order_id}';
		}
		$description = $this->applyDescription($description, $order, $cargo);
		if ($description === '' || $description === ('Замовлення ' . $orderId)) {
			$description = $cargo['description'] !== '' ? $cargo['description'] : ('Замовлення ' . $orderId);
		}
		$props = array(
			'Ref'              => $row['cn_ref'],
			'PayerType'        => !empty($input['payer']) ? $input['payer'] : 'Recipient',
			'PaymentMethod'    => !empty($input['payment']) ? $input['payment'] : 'Cash',
			'DateTime'         => date('d.m.Y'),
			'CargoType'        => $this->cargoType($input),
			'Weight'           => (string)max(0.1, $weight),
			'ServiceType'      => $service,
			'SeatsAmount'      => (string)max(1, (int)(isset($input['seats']) ? $input['seats'] : 1)),
			'Description'      => $description,
			'Cost'             => (string)max(1, round($cost)),
			'CitySender'       => $sender['city'],
			'Sender'           => $sender['ref'],
			'SenderAddress'    => $sender['address'],
			'ContactSender'    => $sender['contact'],
			'SendersPhone'     => NovaposhtaApi::phone($sender['phone']),
			'CityRecipient'    => $row['city_ref'],
			'Recipient'        => $pair['ref'],
			'RecipientAddress' => $method === 'doors' ? $row['street_ref'] : $row['division_ref'],
			'ContactRecipient' => $pair['contact'],
			'RecipientsPhone'  => $phone
		);
		$data = $api->call('InternetDocument', 'update', $props);
		if ($data === false) {
			return array('error' => $api->getLastError());
		}
		return array('success' => true);
	}

	public function writeCustomFields($orderId, array $selection) {
		$map = array(
			(int)$this->config->get('shipping_novaposhta_field_city') => isset($selection['city_name']) ? $selection['city_name'] : '',
			(int)$this->config->get('shipping_novaposhta_field_warehouse') => isset($selection['division_name']) ? $selection['division_name'] : '',
			(int)$this->config->get('shipping_novaposhta_field_street') => isset($selection['street_name']) ? $selection['street_name'] : '',
			(int)$this->config->get('shipping_novaposhta_field_building') => isset($selection['building']) ? $selection['building'] : '',
			(int)$this->config->get('shipping_novaposhta_field_flat') => isset($selection['flat']) ? $selection['flat'] : ''
		);
		$clean = array();
		foreach ($map as $id => $value) {
			if ($id && $value !== '') {
				$clean[(string)$id] = $value;
			}
		}
		if (!$clean) {
			return;
		}
		$query = $this->db->query("SELECT `shipping_custom_field`, `custom_field` FROM `" . DB_PREFIX . "order` WHERE order_id = '" . (int)$orderId . "' LIMIT 1");
		if (!$query->num_rows) {
			return;
		}
		foreach (array('shipping_custom_field', 'custom_field') as $column) {
			$current = json_decode($query->row[$column], true);
			if (!is_array($current)) {
				$current = array();
			}
			if (isset($current['address']) && is_array($current['address'])) {
				foreach ($clean as $id => $value) {
					$current['address'][$id] = $value;
				}
			}
			foreach ($clean as $id => $value) {
				$current[$id] = $value;
			}
			$this->db->query("UPDATE `" . DB_PREFIX . "order` SET `" . $column . "` = '" . $this->db->escape(json_encode($current, JSON_UNESCAPED_UNICODE)) . "' WHERE order_id = '" . (int)$orderId . "'");
		}
	}

	public function valuesFromCustomFields(array $order) {
		$map = array(
			'city_name'     => (int)$this->config->get('shipping_novaposhta_field_city'),
			'division_name' => (int)$this->config->get('shipping_novaposhta_field_warehouse'),
			'street_name'   => (int)$this->config->get('shipping_novaposhta_field_street'),
			'building'      => (int)$this->config->get('shipping_novaposhta_field_building'),
			'flat'          => (int)$this->config->get('shipping_novaposhta_field_flat')
		);
		$bag = array();
		foreach (array('shipping_custom_field', 'payment_custom_field', 'custom_field') as $column) {
			if (empty($order[$column])) {
				continue;
			}
			$decoded = is_array($order[$column]) ? $order[$column] : json_decode($order[$column], true);
			if (is_array($decoded)) {
				$this->flattenCustom($decoded, $bag);
			}
		}
		$out = array();
		foreach ($map as $key => $id) {
			if ($id && isset($bag[$id]) && trim((string)$bag[$id]) !== '') {
				$out[$key] = trim((string)$bag[$id]);
			}
		}
		return $out;
	}

	private function flattenCustom($node, &$bag) {
		foreach ($node as $key => $value) {
			if (is_array($value)) {
				$this->flattenCustom($value, $bag);
				continue;
			}
			if (ctype_digit((string)$key)) {
				$bag[(int)$key] = $value;
			}
		}
	}

	public function changePoint($customerId, $orderId, array $selection) {
		$orderId = (int)$orderId;
		$query = $this->db->query("SELECT o.customer_id, n.cn_number FROM `" . DB_PREFIX . "order` o
			LEFT JOIN `" . DB_PREFIX . "novaposhta_order` n ON n.order_id = o.order_id
			WHERE o.order_id = '" . $orderId . "' LIMIT 1");
		if (!$query->num_rows || (int)$query->row['customer_id'] !== (int)$customerId) {
			return array('error' => 'Order not found');
		}
		if ($query->row['cn_number'] !== '' && $query->row['cn_number'] !== null) {
			return array('error' => 'TTN already created');
		}
		$this->savePoint($orderId, $selection);
		if (!empty($selection['city_name'])) {
			$this->db->query("UPDATE `" . DB_PREFIX . "order` SET
				`shipping_city` = '" . $this->db->escape($selection['city_name']) . "',
				`shipping_address_1` = '" . $this->db->escape(isset($selection['division_name']) ? $selection['division_name'] : '') . "'
				WHERE order_id = '" . $orderId . "'");
		}
		$this->writeCustomFields($orderId, $selection);
		return array('success' => true);
	}
}
