<?php
trait NovaposhtaExtra {
	public function ensureExtra() {
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "novaposhta_log` (
			`log_id` int(11) NOT NULL AUTO_INCREMENT,
			`created_at` datetime NOT NULL,
			`model` varchar(64) NOT NULL DEFAULT '',
			`method` varchar(64) NOT NULL DEFAULT '',
			`success` tinyint(1) NOT NULL DEFAULT 0,
			`message` varchar(400) NOT NULL DEFAULT '',
			PRIMARY KEY (`log_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "novaposhta_cache` (
			`cache_key` char(40) NOT NULL,
			`value` varchar(255) NOT NULL DEFAULT '',
			`created_at` datetime NOT NULL,
			PRIMARY KEY (`cache_key`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "novaposhta_recipient` (
			`phone` varchar(20) NOT NULL,
			`ref` char(36) NOT NULL DEFAULT '',
			`contact_ref` char(36) NOT NULL DEFAULT '',
			PRIMARY KEY (`phone`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$query = $this->db->query("SHOW TABLES LIKE '" . DB_PREFIX . "novaposhta_order'");
		if ($query->num_rows) {
			$column = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "novaposhta_order` LIKE 'sender_id'");
			if (!$column->num_rows) {
				$this->db->query("ALTER TABLE `" . DB_PREFIX . "novaposhta_order` ADD `sender_id` varchar(32) NOT NULL DEFAULT ''");
			}
		}

		$this->ensureDesk();
	}

	public function logApi($model, $method, $ok, $message) {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "novaposhta_log` SET
			`created_at` = NOW(),
			`model` = '" . $this->db->escape($model) . "',
			`method` = '" . $this->db->escape($method) . "',
			`success` = '" . ($ok ? 1 : 0) . "',
			`message` = '" . $this->db->escape($message) . "'");
		$max = $this->db->query("SELECT MAX(`log_id`) AS id FROM `" . DB_PREFIX . "novaposhta_log`");
		$cut = (int)$max->row['id'] - 19;
		if ($cut > 1) {
			$this->db->query("DELETE FROM `" . DB_PREFIX . "novaposhta_log` WHERE `log_id` < '" . (int)$cut . "'");
		}
	}

	public function recentLogs($limit = 20) {
		$query = $this->db->query("SHOW TABLES LIKE '" . DB_PREFIX . "novaposhta_log'");
		if (!$query->num_rows) {
			return array();
		}
		return $this->db->query("SELECT * FROM `" . DB_PREFIX . "novaposhta_log` ORDER BY `log_id` DESC LIMIT " . (int)$limit)->rows;
	}

	public function legacySender() {
		return array(
			'id'      => 'default',
			'name'    => 'Основний',
			'api_key' => (string)$this->config->get('shipping_novaposhta_api_key'),
			'ref'     => (string)$this->config->get('shipping_novaposhta_sender_ref'),
			'contact' => (string)$this->config->get('shipping_novaposhta_sender_contact'),
			'phone'   => (string)$this->config->get('shipping_novaposhta_sender_phone'),
			'city'    => (string)$this->config->get('shipping_novaposhta_sender_city'),
			'address' => (string)$this->config->get('shipping_novaposhta_sender_address'),
			'type'    => $this->config->get('shipping_novaposhta_sender_type') === 'Doors' ? 'Doors' : 'Warehouse'
		);
	}

	public function resolveSender($id = '') {
		if ($id === '' || $id === 'default') {
			return $this->legacySender();
		}

		$rows = $this->config->get('shipping_novaposhta_senders');
		if (is_array($rows)) {
			foreach ($rows as $index => $row) {
				if (!is_array($row) || empty($row['ref'])) {
					continue;
				}
				$rowId = isset($row['id']) && $row['id'] !== '' ? (string)$row['id'] : (string)$index;
				if ($rowId !== (string)$id) {
					continue;
				}
				$legacy = $this->legacySender();
				return array(
					'id'      => $rowId,
					'name'    => !empty($row['name']) ? $row['name'] : $legacy['name'],
					'api_key' => !empty($row['api_key']) ? $row['api_key'] : $legacy['api_key'],
					'ref'     => $row['ref'],
					'contact' => isset($row['contact']) ? $row['contact'] : '',
					'phone'   => isset($row['phone']) ? $row['phone'] : '',
					'city'    => isset($row['city']) ? $row['city'] : '',
					'address' => isset($row['address']) ? $row['address'] : '',
					'type'    => (isset($row['type']) && $row['type'] === 'Doors') ? 'Doors' : 'Warehouse'
				);
			}
		}

		return $this->legacySender();
	}

	public function senderChoices() {
		$rows = $this->config->get('shipping_novaposhta_senders');
		$out = array();
		if (!is_array($rows)) {
			return $out;
		}
		foreach ($rows as $index => $row) {
			if (!is_array($row) || empty($row['ref'])) {
				continue;
			}
			$out[] = array(
				'id'   => isset($row['id']) && $row['id'] !== '' ? $row['id'] : (string)$index,
				'name' => !empty($row['name']) ? $row['name'] : ('Відправник ' . ($index + 1))
			);
		}
		return $out;
	}

	public function recipientPair($api, $first, $last, $phone, $email, $edrpou = '', $company = '') {
		$phone = NovaposhtaApi::phone($phone);
		$edrpou = preg_replace('/\D+/', '', (string)$edrpou);
		$key = $edrpou !== '' ? ('e' . $edrpou) : $phone;
		$query = $this->db->query("SELECT `ref`, `contact_ref` FROM `" . DB_PREFIX . "novaposhta_recipient` WHERE `phone` = '" . $this->db->escape($key) . "' LIMIT 1");
		if ($query->num_rows && $query->row['ref'] !== '' && $query->row['contact_ref'] !== '') {
			return array('ref' => $query->row['ref'], 'contact' => $query->row['contact_ref']);
		}

		$found = $api->call('Counterparty', 'getCounterparties', array(
			'CounterpartyProperty' => 'Recipient',
			'FindByString'         => $phone,
			'Page'                 => '1'
		));
		if (is_array($found)) {
			foreach ($found as $row) {
				if (empty($row['Ref'])) {
					continue;
				}
				$contacts = $api->contacts($row['Ref']);
				if (!is_array($contacts)) {
					continue;
				}
				foreach ($contacts as $contact) {
					if (empty($contact['Ref']) || empty($contact['Phones'])) {
						continue;
					}
					if (NovaposhtaApi::phone($contact['Phones']) === $phone) {
						$this->rememberRecipient($key, $row['Ref'], $contact['Ref']);
						return array('ref' => $row['Ref'], 'contact' => $contact['Ref']);
					}
				}
			}
		}

		$props = array(
			'FirstName'            => $company !== '' ? $company : $first,
			'LastName'             => $last,
			'Phone'                => $phone,
			'Email'                => $email,
			'CounterpartyType'     => $edrpou !== '' ? 'Organization' : 'PrivatePerson',
			'CounterpartyProperty' => 'Recipient'
		);
		if ($edrpou !== '') {
			$props['EDRPOU'] = $edrpou;
		}
		$recipient = $api->saveCounterparty($props);
		if ($recipient === false || empty($recipient[0]['Ref'])) {
			return array('error' => $api->getLastError());
		}

		$contactRef = '';
		if (!empty($recipient[0]['ContactPerson']['data'][0]['Ref'])) {
			$contactRef = $recipient[0]['ContactPerson']['data'][0]['Ref'];
		} elseif (!empty($recipient[0]['ContactPerson']['Ref'])) {
			$contactRef = $recipient[0]['ContactPerson']['Ref'];
		}
		if ($contactRef === '') {
			return array('error' => 'Recipient contact was not created');
		}

		$this->rememberRecipient($key, $recipient[0]['Ref'], $contactRef);
		return array('ref' => $recipient[0]['Ref'], 'contact' => $contactRef);
	}

	public function rememberRecipient($phone, $ref, $contact) {
		$this->db->query("REPLACE INTO `" . DB_PREFIX . "novaposhta_recipient` SET
			`phone` = '" . $this->db->escape($phone) . "',
			`ref` = '" . $this->db->escape($ref) . "',
			`contact_ref` = '" . $this->db->escape($contact) . "'");
	}

	public function seatsFromInput(array $input) {
		if (empty($input['places']) || !is_array($input['places'])) {
			return array();
		}
		$seats = array();
		foreach ($input['places'] as $place) {
			if (!is_array($place)) {
				continue;
			}
			$weight = isset($place['weight']) ? (float)$place['weight'] : 0;
			$length = isset($place['length']) ? (int)$place['length'] : 0;
			$width = isset($place['width']) ? (int)$place['width'] : 0;
			$height = isset($place['height']) ? (int)$place['height'] : 0;
			if ($weight <= 0 && $length <= 0 && $width <= 0 && $height <= 0) {
				continue;
			}
			$weight = max(0.1, $weight);
			$length = max(1, $length);
			$width = max(1, $width);
			$height = max(1, $height);
			$seats[] = array(
				'weight'           => (string)$weight,
				'volumetricLength' => (string)$length,
				'volumetricWidth'  => (string)$width,
				'volumetricHeight' => (string)$height,
				'volumetricVolume' => (string)round(max(0.0004, ($length * $width * $height) / 1000000), 4)
			);
		}
		return $seats;
	}

	public function notifyCustomer(array $order, $number) {
		$number = (string)$number;
		$track = 'https://novaposhta.ua/tracking/?cargo_number=' . rawurlencode($number);
		$text = 'ТТН ' . $number . "\n" . $track;

		if (!empty($order['email'])) {
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
				$mail->setTo($order['email']);
				$mail->setFrom($this->config->get('config_email'));
				$mail->setSender(html_entity_decode((string)$this->config->get('config_name'), ENT_QUOTES, 'UTF-8'));
				$mail->setSubject('ТТН ' . $number);
				$mail->setText($text);
				$mail->send();
			} catch (Exception $e) {
			}
		}

		$template = trim((string)$this->config->get('shipping_novaposhta_sms_url'));
		if ($template !== '' && !empty($order['telephone']) && function_exists('curl_init')) {
			$url = str_replace(
				array('{phone}', '{text}', '{number}'),
				array(rawurlencode(NovaposhtaApi::phone($order['telephone'])), rawurlencode($text), rawurlencode($number)),
				$template
			);
			if (preg_match('#^https?://#i', $url)) {
				$ch = curl_init($url);
				curl_setopt_array($ch, array(
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_CONNECTTIMEOUT => 4,
					CURLOPT_TIMEOUT        => 6
				));
				curl_exec($ch);
				curl_close($ch);
			}
		}
	}

	public function deliveryDate($senderCity, $recipientCity, $serviceType) {
		$key = sha1($senderCity . '|' . $recipientCity . '|' . $serviceType . '|' . date('Y-m-d'));
		$query = $this->db->query("SELECT `value`, `created_at` FROM `" . DB_PREFIX . "novaposhta_cache` WHERE `cache_key` = '" . $this->db->escape($key) . "' LIMIT 1");
		if ($query->num_rows && strtotime($query->row['created_at']) > time() - 43200) {
			return $query->row['value'];
		}

		$api = $this->api();
		$data = $api->call('InternetDocument', 'getDocumentDeliveryDate', array(
			'CitySender'    => $senderCity,
			'CityRecipient' => $recipientCity,
			'ServiceType'   => $serviceType,
			'DateTime'      => date('d.m.Y')
		), 6);

		$date = '';
		if (is_array($data) && !empty($data[0]['DeliveryDate'])) {
			$date = substr($data[0]['DeliveryDate'], 0, 10);
		}
		if ($date !== '') {
			$this->db->query("REPLACE INTO `" . DB_PREFIX . "novaposhta_cache` SET
				`cache_key` = '" . $this->db->escape($key) . "',
				`value` = '" . $this->db->escape($date) . "',
				`created_at` = NOW()");
		}
		return $date;
	}

	public function nearest($lat, $lng, $cityRef, $locker, $russian) {
		$col = $russian ? 'w.description_ru' : 'w.description';
		$cityCol = $russian ? 'c.description_ru' : 'c.description';
		$sql = "SELECT w.`ref`, w.`city_ref`, w.`division_id`, " . $col . " AS name, w.`description` AS name_uk,
				w.`latitude`, w.`longitude`, " . $cityCol . " AS city_name, c.`description` AS city_uk,
				(POW(w.latitude - " . (float)$lat . ", 2) + POW(w.longitude - " . (float)$lng . ", 2)) AS dist
			FROM `" . DB_PREFIX . "novaposhta_warehouse` w
			LEFT JOIN `" . DB_PREFIX . "novaposhta_city` c ON c.ref = w.city_ref
			WHERE w.latitude IS NOT NULL AND w.longitude IS NOT NULL
			AND w.status = 'Working' AND w.deny = 0
			AND w.latitude BETWEEN " . ((float)$lat - 0.4) . " AND " . ((float)$lat + 0.4) . "
			AND w.longitude BETWEEN " . ((float)$lng - 0.4) . " AND " . ((float)$lng + 0.4);

		if ($cityRef !== '') {
			$sql .= " AND w.city_ref = '" . $this->db->escape($cityRef) . "'";
		}
		if ($locker) {
			$sql .= " AND LOWER(w.category) = 'postomat'";
		} else {
			$sql .= " AND LOWER(w.category) <> 'postomat'";
		}
		$sql .= " ORDER BY dist LIMIT 8";

		$out = array();
		foreach ($this->db->query($sql)->rows as $row) {
			$out[] = array(
				'ref'         => $row['ref'],
				'division_id' => $row['division_id'],
				'name'        => $row['name'] !== '' ? $row['name'] : $row['name_uk'],
				'city_ref'    => $row['city_ref'],
				'city_name'   => $row['city_name'] !== '' ? $row['city_name'] : $row['city_uk'],
				'lat'         => (float)$row['latitude'],
				'lng'         => (float)$row['longitude']
			);
		}
		return $out;
	}

	public function mapPoints($cityRef, $locker, $russian) {
		$col = $russian ? 'description_ru' : 'description';
		$sql = "SELECT `ref`, `division_id`, `" . $col . "` AS name, `description` AS name_uk, `latitude`, `longitude`
			FROM `" . DB_PREFIX . "novaposhta_warehouse`
			WHERE `city_ref` = '" . $this->db->escape($cityRef) . "'
			AND `status` = 'Working' AND `deny` = 0
			AND `latitude` IS NOT NULL AND `longitude` IS NOT NULL";
		$sql .= $locker ? " AND LOWER(`category`) = 'postomat'" : " AND LOWER(`category`) <> 'postomat'";
		$sql .= " ORDER BY `number` LIMIT 200";

		$out = array();
		foreach ($this->db->query($sql)->rows as $row) {
			$out[] = array(
				'ref'         => $row['ref'],
				'division_id' => $row['division_id'],
				'name'        => $row['name'] !== '' ? $row['name'] : $row['name_uk'],
				'lat'         => (float)$row['latitude'],
				'lng'         => (float)$row['longitude']
			);
		}
		return $out;
	}

	public function lastForCustomer($customerId) {
		if (!(int)$customerId) {
			return array();
		}
		$query = $this->db->query("SELECT n.* FROM `" . DB_PREFIX . "novaposhta_order` n
			INNER JOIN `" . DB_PREFIX . "order` o ON o.order_id = n.order_id
			WHERE o.customer_id = '" . (int)$customerId . "' AND n.city_ref <> ''
			ORDER BY n.order_id DESC LIMIT 1");
		return $query->num_rows ? $query->row : array();
	}

	public function customerShipments($customerId) {
		return $this->db->query("SELECT n.cn_number, n.status_text, n.city_name, n.division_name, n.street_name, o.date_added, o.order_id
			FROM `" . DB_PREFIX . "novaposhta_order` n
			INNER JOIN `" . DB_PREFIX . "order` o ON o.order_id = n.order_id
			WHERE o.customer_id = '" . (int)$customerId . "'
			ORDER BY n.order_id DESC LIMIT 50")->rows;
	}

	public function orderWeight($orderId) {
		$weightClass = $this->unitClass('weight', array('kg', 'кг'));
		$query = $this->db->query("SELECT op.quantity, p.weight, p.weight_class_id
			FROM `" . DB_PREFIX . "order_product` op
			LEFT JOIN `" . DB_PREFIX . "product` p ON p.product_id = op.product_id
			WHERE op.order_id = '" . (int)$orderId . "'");
		$weight = 0;
		foreach ($query->rows as $row) {
			$w = (float)$row['weight'];
			if ($weightClass && $this->weight && $row['weight_class_id']) {
				$w = $this->weight->convert($w, $row['weight_class_id'], $weightClass);
			}
			$weight += $w * (int)$row['quantity'];
		}
		if ($weight <= 0) {
			$weight = (float)$this->config->get('shipping_novaposhta_default_weight');
		}
		if ($weight <= 0) {
			$weight = 0.5;
		}
		return round($weight, 3);
	}

	public function createMany(array $orderIds, $senderId = '') {
		$created = array();
		$errors = array();
		$codPayments = $this->config->get('shipping_novaposhta_cod');
		if (!is_array($codPayments)) {
			$codPayments = array();
		}

		foreach ($orderIds as $orderId) {
			$orderId = (int)$orderId;
			if (!$orderId) {
				continue;
			}
			$orderQuery = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE order_id = '" . $orderId . "' LIMIT 1");
			if (!$orderQuery->num_rows) {
				$errors[] = '#' . $orderId . ': order not found';
				continue;
			}
			$order = $orderQuery->row;
			$row = $this->getOrderRow($orderId);
			if (!$row || $row['city_ref'] === '' || ($row['method'] !== 'doors' && $row['division_ref'] === '')) {
				$errors[] = '#' . $orderId . ': no delivery point';
				continue;
			}
			if ($row['cn_number'] !== '') {
				$errors[] = '#' . $orderId . ': TTN exists';
				continue;
			}
			$cost = round($this->toUah($order['total']));
			$cod = in_array($order['payment_code'], $codPayments, true) ? $cost : 0;
			$result = $this->createDocument($order, $row, array(
				'weight' => $this->orderWeight($orderId),
				'cost'   => $cost,
				'seats'  => 1,
				'phone'  => $order['telephone'],
				'cod'    => $cod,
				'sender_id' => $senderId
			));
			if (!empty($result['error'])) {
				$errors[] = '#' . $orderId . ': ' . $result['error'];
			} else {
				$created[] = '#' . $orderId . ' ' . $result['number'];
			}
		}

		return array('created' => $created, 'errors' => $errors);
	}

	public function makeScanSheet(array $refs, $date) {
		$clean = array();
		foreach ($refs as $ref) {
			$ref = trim((string)$ref);
			if ($ref !== '') {
				$clean[] = $ref;
			}
		}
		if (!$clean) {
			return array('error' => 'No TTN selected');
		}
		$props = array('DocumentRefs' => array_values($clean));
		if ($date !== '') {
			$props['Date'] = $date;
		}
		$api = $this->api();
		$data = $api->call('ScanSheetGeneral', 'insertDocuments', $props);
		if ($data === false) {
			return array('error' => $api->getLastError());
		}
		$row = isset($data[0]) && is_array($data[0]) ? $data[0] : array();
		$ref = isset($row['Ref']) ? $row['Ref'] : '';
		$number = isset($row['Number']) ? $row['Number'] : '';
		if ($ref === '' && isset($row['ScanSheetRef'])) {
			$ref = $row['ScanSheetRef'];
		}
		$this->saveSheet($ref, $number, $date);
		return array(
			'ref'    => $ref,
			'number' => $number,
			'pdf'    => $ref !== '' ? NovaposhtaApi::printUrl($ref, 'scan', $this->config->get('shipping_novaposhta_api_key')) : ''
		);
	}

	public function callCourier($date, $interval, $weight, $senderId) {
		$sender = $this->resolveSender($senderId);
		if ($sender['address'] === '' || $sender['contact'] === '' || $sender['ref'] === '') {
			return array('error' => 'Sender address is empty');
		}
		$api = $this->api($sender['api_key']);
		$data = $api->call('CarCallGeneral', 'saveCourierCall', array(
			'ContactSenderRef'     => $sender['contact'],
			'PreferredDeliveryDate' => $date,
			'PlanedWeight'         => (string)max(0.1, (float)$weight),
			'TimeInterval'         => $interval,
			'CounterpartySender'   => $sender['ref'],
			'AddressSenderRef'     => $sender['address']
		));
		if ($data === false) {
			return array('error' => $api->getLastError());
		}
		$barcode = '';
		if (!empty($data[0]['Barcode'])) {
			$barcode = $data[0]['Barcode'];
		} elseif (!empty($data[0]['Number'])) {
			$barcode = $data[0]['Number'];
		}
		return array('number' => $barcode);
	}

	public function printLinks(array $orderIds, $kind) {
		$ids = array();
		foreach ($orderIds as $id) {
			$id = (int)$id;
			if ($id) {
				$ids[] = $id;
			}
		}
		if (!$ids) {
			return array();
		}
		$query = $this->db->query("SELECT `cn_ref`, `sender_id` FROM `" . DB_PREFIX . "novaposhta_order`
			WHERE `order_id` IN (" . implode(',', $ids) . ") AND `cn_ref` <> ''");
		$groups = array();
		foreach ($query->rows as $row) {
			$sender = $this->resolveSender($row['sender_id']);
			$key = $sender['api_key'] !== '' ? $sender['api_key'] : (string)$this->config->get('shipping_novaposhta_api_key');
			if (!isset($groups[$key])) {
				$groups[$key] = array();
			}
			$groups[$key][] = $row['cn_ref'];
		}
		$links = array();
		foreach ($groups as $key => $refs) {
			$links[] = NovaposhtaApi::printUrl($refs, $kind, $key);
		}
		return $links;
	}

	public function refsByOrders(array $orderIds) {
		$ids = array();
		foreach ($orderIds as $id) {
			$id = (int)$id;
			if ($id) {
				$ids[] = $id;
			}
		}
		if (!$ids) {
			return array();
		}
		$query = $this->db->query("SELECT `cn_ref` FROM `" . DB_PREFIX . "novaposhta_order`
			WHERE `order_id` IN (" . implode(',', $ids) . ") AND `cn_ref` <> ''");
		$refs = array();
		foreach ($query->rows as $row) {
			$refs[] = $row['cn_ref'];
		}
		return $refs;
	}

	public function hydrateSelection(&$session, $customerId, $cookieRaw) {
		if (!empty($session['novaposhta']['city_ref'])) {
			return;
		}
		$saved = array();
		if ($customerId) {
			$saved = $this->lastForCustomer($customerId);
		}
		if (!$saved && $cookieRaw) {
			$json = json_decode(html_entity_decode($cookieRaw), true);
			if (is_array($json)) {
				$saved = $json;
			}
		}
		if (!$saved) {
			return;
		}
		$keep = array('method', 'city_ref', 'city_name', 'division_ref', 'division_id', 'division_name', 'settlement_ref', 'street_ref', 'street_name', 'building', 'flat');
		$selection = array();
		foreach ($keep as $key) {
			if (isset($saved[$key]) && is_scalar($saved[$key])) {
				$selection[$key] = (string)$saved[$key];
			}
		}
		if (!empty($selection['city_ref']) || !empty($selection['city_name'])) {
			$session['novaposhta'] = $selection;
		}
	}
}
