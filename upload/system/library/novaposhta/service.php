<?php
require_once DIR_SYSTEM . 'library/novaposhta/api.php';
require_once DIR_SYSTEM . 'library/novaposhta/features.php';
require_once DIR_SYSTEM . 'library/novaposhta/desk.php';

/**
 * Directory sync, quote math and consignment notes.
 * Checkout reads local tables. The API is used for price, streets and TTN.
 */
class NovaposhtaService {
	use NovaposhtaExtra, NovaposhtaDesk;

	private $registry;
	private $db;
	private $config;
	private $weight;
	private $length;
	private $currency;

	public function __construct($registry) {
		$this->registry = $registry;
		$this->db = $registry->get('db');
		$this->config = $registry->get('config');
		$this->weight = $registry->get('weight');
		$this->length = $registry->get('length');
		$this->currency = $registry->get('currency');
		$this->ensureExtra();
	}

	public function api($key = null) {
		$apiKey = ($key !== null && $key !== '') ? $key : $this->config->get('shipping_novaposhta_api_key');
		$api = new NovaposhtaApi($apiKey);
		$api->setLogger(function ($model, $method, $ok, $message) {
			$this->logApi($model, $method, $ok, $message);
		});
		return $api;
	}

	public function install() {
		$this->ensureExtra();
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "novaposhta_region` (
			`ref` char(36) NOT NULL,
			`description` varchar(100) NOT NULL,
			`description_ru` varchar(100) NOT NULL DEFAULT '',
			PRIMARY KEY (`ref`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "novaposhta_city` (
			`ref` char(36) NOT NULL,
			`area_ref` char(36) NOT NULL DEFAULT '',
			`description` varchar(200) NOT NULL,
			`description_ru` varchar(200) NOT NULL DEFAULT '',
			`settlement_type` varchar(50) NOT NULL DEFAULT '',
			PRIMARY KEY (`ref`),
			KEY `area_desc` (`area_ref`, `description`),
			KEY `desc_uk` (`description`),
			KEY `desc_ru` (`description_ru`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "novaposhta_warehouse` (
			`ref` char(36) NOT NULL,
			`division_id` int(10) unsigned DEFAULT NULL,
			`city_ref` char(36) NOT NULL DEFAULT '',
			`settlement_ref` char(36) NOT NULL DEFAULT '',
			`description` varchar(1000) NOT NULL,
			`description_ru` varchar(1000) NOT NULL DEFAULT '',
			`number` int(11) NOT NULL DEFAULT 0,
			`category` varchar(32) NOT NULL DEFAULT '',
			`type_ref` char(36) NOT NULL DEFAULT '',
			`status` varchar(32) NOT NULL DEFAULT '',
			`total_max_weight` int(11) NOT NULL DEFAULT 0,
			`place_max_weight` int(11) NOT NULL DEFAULT 0,
			`max_length` int(11) NOT NULL DEFAULT 0,
			`max_width` int(11) NOT NULL DEFAULT 0,
			`max_height` int(11) NOT NULL DEFAULT 0,
			`max_declared_cost` int(11) NOT NULL DEFAULT 0,
			`latitude` decimal(10,6) DEFAULT NULL,
			`longitude` decimal(10,6) DEFAULT NULL,
			`post_machine_type` varchar(64) NOT NULL DEFAULT '',
			`deny` tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY (`ref`),
			KEY `city_cat` (`city_ref`, `category`, `status`),
			KEY `division_id` (`division_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "novaposhta_order` (
			`order_id` int(11) NOT NULL,
			`method` varchar(20) NOT NULL DEFAULT '',
			`city_ref` char(36) NOT NULL DEFAULT '',
			`city_name` varchar(200) NOT NULL DEFAULT '',
			`division_ref` char(36) NOT NULL DEFAULT '',
			`division_id` int(10) unsigned DEFAULT NULL,
			`division_name` varchar(500) NOT NULL DEFAULT '',
			`settlement_ref` char(36) NOT NULL DEFAULT '',
			`street_ref` char(36) NOT NULL DEFAULT '',
			`street_name` varchar(255) NOT NULL DEFAULT '',
			`building` varchar(32) NOT NULL DEFAULT '',
			`flat` varchar(32) NOT NULL DEFAULT '',
			`cn_number` varchar(36) NOT NULL DEFAULT '',
			`cn_ref` char(36) NOT NULL DEFAULT '',
			`status_code` int(11) DEFAULT NULL,
			`status_text` varchar(255) NOT NULL DEFAULT '',
			`status_at` datetime DEFAULT NULL,
			PRIMARY KEY (`order_id`),
			KEY `cn_number` (`cn_number`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "novaposhta_price_cache` (
			`cache_key` char(40) NOT NULL,
			`cost` decimal(15,4) NOT NULL,
			`period` int(11) NOT NULL DEFAULT 0,
			`created_at` datetime NOT NULL,
			PRIMARY KEY (`cache_key`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->ensureExtra();
	}

	public function uninstall() {
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "novaposhta_region`");
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "novaposhta_city`");
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "novaposhta_warehouse`");
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "novaposhta_price_cache`");
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "novaposhta_log`");
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "novaposhta_cache`");
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "novaposhta_recipient`");
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "novaposhta_queue`");
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "novaposhta_sheet`");
	}

	public function ensureMenuEvent() {
		$query = $this->db->query("SELECT `event_id` FROM `" . DB_PREFIX . "event` WHERE `code` = 'novaposhta_menu' LIMIT 1");
		if ($query->num_rows) {
			return;
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "event` SET
			`code` = 'novaposhta_menu',
			`trigger` = 'admin/view/common/column_left/before',
			`action` = 'extension/shipping/novaposhta/eventMenu',
			`status` = '1',
			`sort_order` = '0'");

		$account = $this->db->query("SELECT `event_id` FROM `" . DB_PREFIX . "event` WHERE `code` = 'novaposhta_account' LIMIT 1");
		if (!$account->num_rows) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "event` SET
				`code` = 'novaposhta_account',
				`trigger` = 'catalog/view/account/account/after',
				`action` = 'extension/module/novaposhta/eventAccount',
				`status` = '1',
				`sort_order` = '0'");
		}
	}

	public function counts() {
		$out = array('regions' => 0, 'cities' => 0, 'warehouses' => 0, 'lockers' => 0);

		foreach (array('regions' => 'novaposhta_region', 'cities' => 'novaposhta_city', 'warehouses' => 'novaposhta_warehouse') as $key => $table) {
			$query = $this->db->query("SHOW TABLES LIKE '" . DB_PREFIX . $table . "'");
			if ($query->num_rows) {
				$count = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . $table . "`");
				$out[$key] = (int)$count->row['total'];
			}
		}

		$query = $this->db->query("SHOW TABLES LIKE '" . DB_PREFIX . "novaposhta_warehouse'");
		if ($query->num_rows) {
			$count = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "novaposhta_warehouse` WHERE LOWER(`category`) = 'postomat'");
			$out['lockers'] = (int)$count->row['total'];
			$out['warehouses'] = max(0, $out['warehouses'] - $out['lockers']);
		}

		return $out;
	}

	public function waybills($filter, $start, $limit) {
		$where = array('1');
		if (!empty($filter['order_id'])) {
			$where[] = "n.order_id = '" . (int)$filter['order_id'] . "'";
		}
		if (!empty($filter['cn'])) {
			$where[] = "n.cn_number LIKE '%" . $this->db->escape($filter['cn']) . "%'";
		}
		if (isset($filter['has_cn']) && $filter['has_cn'] === '1') {
			$where[] = "n.cn_number <> ''";
		} elseif (isset($filter['has_cn']) && $filter['has_cn'] === '0') {
			$where[] = "n.cn_number = ''";
		}
		if (!empty($filter['group'])) {
			if ($filter['group'] === 'none') {
				$where[] = "n.cn_number = ''";
			} elseif ($filter['group'] === 'transit') {
				$where[] = "n.cn_number <> '' AND (n.status_code IS NULL OR n.status_code NOT IN (7, 8, 9, 10, 11, 102, 103))";
			} elseif ($filter['group'] === 'arrived') {
				$where[] = "n.status_code IN (7, 8)";
			} elseif ($filter['group'] === 'refused') {
				$where[] = "n.status_code IN (102, 103)";
			} elseif ($filter['group'] === 'money') {
				$where[] = "n.status_code IN (10, 11)";
			}
		}

		$sqlWhere = implode(' AND ', $where);
		$count = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "novaposhta_order` n WHERE " . $sqlWhere);
		$query = $this->db->query("SELECT n.*, o.firstname, o.lastname, o.telephone, o.total, o.date_added
			FROM `" . DB_PREFIX . "novaposhta_order` n
			LEFT JOIN `" . DB_PREFIX . "order` o ON (o.order_id = n.order_id)
			WHERE " . $sqlWhere . "
			ORDER BY n.order_id DESC
			LIMIT " . (int)$start . "," . (int)$limit);

		return array(
			'total' => (int)$count->row['total'],
			'rows'  => $query->rows
		);
	}

	public function syncPage($job, $page, $type = '') {
		$page = max(1, (int)$page);
		$this->ensureColumns();
		$api = $this->api();

		if ($job === 'areas') {
			$rows = $api->areas();
			if ($rows === false) {
				return array('error' => 'getAreas: ' . $api->getLastError());
			}

			if ($page === 1) {
				$this->db->query("TRUNCATE TABLE `" . DB_PREFIX . "novaposhta_region`");
			}

			foreach ($rows as $row) {
				if (empty($row['Ref'])) {
					continue;
				}
				$this->db->query("INSERT INTO `" . DB_PREFIX . "novaposhta_region` SET
					`ref` = '" . $this->db->escape($row['Ref']) . "',
					`description` = '" . $this->db->escape(isset($row['Description']) ? $row['Description'] : '') . "',
					`description_ru` = '" . $this->db->escape(isset($row['DescriptionRu']) ? $row['DescriptionRu'] : '') . "'");
			}

			return $this->syncResult(count($rows), 'Області', array('job' => 'cities', 'page' => 1, 'type' => ''));
		}

		if ($job === 'cities') {
			$rows = $api->cities($page, 500);
			if ($rows === false) {
				return array('error' => 'getCities: ' . $api->getLastError());
			}

			if ($page === 1) {
				$this->db->query("TRUNCATE TABLE `" . DB_PREFIX . "novaposhta_city`");
			}

			$before = $this->tableCount('novaposhta_city');

			$this->insertCities($rows);
			$added = $this->tableCount('novaposhta_city') - $before;
			$stop = !$rows || count($rows) < 500 || $added < 1;
			$next = $stop
				? array('job' => 'warehouses', 'page' => 1, 'type' => '')
				: array('job' => 'cities', 'page' => $page + 1, 'type' => '');

			return $this->syncResult(count($rows), 'Міста, сторінка ' . $page, $next);
		}

		if ($job === 'warehouses') {
			$types = $this->warehouseTypeList($api);
			if ($types === false) {
				return array('error' => $api->getLastError());
			}

			$index = 0;
			if ($type !== '') {
				foreach ($types as $i => $item) {
					if ($item['ref'] === $type) {
						$index = $i;
						break;
					}
				}
			}
			$type = $types[$index]['ref'];
			$limit = 300;

			$rows = $api->warehouses($page, $limit, $type);
			if ($rows === false) {
				return array('error' => 'getWarehouses: ' . $api->getLastError());
			}

			if ($page === 1 && $index === 0) {
				$this->db->query("TRUNCATE TABLE `" . DB_PREFIX . "novaposhta_warehouse`");
			}

			$before = $this->tableCount('novaposhta_warehouse');

			$this->insertWarehouses($rows, $types[$index]['locker']);
			$added = $this->tableCount('novaposhta_warehouse') - $before;
			$typeDone = !$rows || count($rows) < $limit || $added < 1;

			if (!$typeDone) {
				$next = array('job' => 'warehouses', 'page' => $page + 1, 'type' => $type);
			} elseif (isset($types[$index + 1])) {
				$next = array('job' => 'warehouses', 'page' => 1, 'type' => $types[$index + 1]['ref']);
			} else {
				$next = null;
			}

			$label = $types[$index]['name'] . ', сторінка ' . $page;
			return $this->syncResult(count($rows), $label, $next);
		}

		return array('error' => 'Unknown sync job');
	}

	private function syncResult($received, $label, $next) {
		return array(
			'done'     => $next === null,
			'next'     => $next,
			'received' => (int)$received,
			'label'    => $label,
			'counts'   => $this->counts()
		);
	}

	private function tableCount($table) {
		$query = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . $table . "`");
		return (int)$query->row['total'];
	}

	private function warehouseTypeList($api) {
		$rows = $api->warehouseTypes();
		$types = array();

		if (is_array($rows)) {
			foreach ($rows as $row) {
				if (empty($row['Ref'])) {
					continue;
				}
				$name = isset($row['Description']) ? $row['Description'] : $row['Ref'];
				$types[] = array(
					'ref'    => $row['Ref'],
					'name'   => $name,
					'locker' => $this->isLockerType($name . ' ' . (isset($row['DescriptionRu']) ? $row['DescriptionRu'] : ''))
				);
			}
		}

		if ($types) {
			return $types;
		}

		return array(array(
			'ref'    => '',
			'name'   => 'Відділення',
			'locker' => false
		));
	}

	private function isLockerType($name) {
		$name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
		return strpos($name, 'поштомат') !== false || strpos($name, 'почтомат') !== false || strpos($name, 'postomat') !== false;
	}

	private function ensureColumns() {
		$warehouse = $this->db->query("SHOW TABLES LIKE '" . DB_PREFIX . "novaposhta_warehouse'");
		if (!$warehouse->num_rows) {
			$this->install();
			return;
		}

		$this->db->query("ALTER TABLE `" . DB_PREFIX . "novaposhta_warehouse`
			MODIFY `description` varchar(1000) NOT NULL,
			MODIFY `description_ru` varchar(1000) NOT NULL DEFAULT ''");
	}

	public function searchCities($query, $russian) {
		$query = trim($query);
		if (utf8_strlen($query) < 2) {
			return array();
		}

		$col = $russian ? 'description_ru' : 'description';
		$area = $russian ? 'description_ru' : 'description';
		$like = $this->db->escape($query) . '%';

		$sql = "SELECT c.`ref`, c.`" . $col . "` AS name, c.`description` AS name_uk, r.`" . $area . "` AS area
			FROM `" . DB_PREFIX . "novaposhta_city` c
			LEFT JOIN `" . DB_PREFIX . "novaposhta_region` r ON r.ref = c.area_ref
			WHERE c.`description` LIKE '" . $like . "' OR c.`description_ru` LIKE '" . $like . "'
			ORDER BY (c.`" . $col . "` LIKE '" . $like . "') DESC, c.`" . $col . "`
			LIMIT 15";

		$rows = $this->db->query($sql)->rows;
		$out = array();

		foreach ($rows as $row) {
			$name = $row['name'] !== '' ? $row['name'] : $row['name_uk'];
			$out[] = array(
				'ref'  => $row['ref'],
				'name' => $name,
				'area' => $row['area']
			);
		}

		return $out;
	}

	public function searchWarehouses($cityRef, $query, $locker, $weight, $length, $width, $height, $russian) {
		$col = $russian ? 'description_ru' : 'description';
		$sql = "SELECT `ref`, `division_id`, `" . $col . "` AS name, `description` AS name_uk, `number`, `category`,
				`total_max_weight`, `place_max_weight`, `latitude`, `longitude`
			FROM `" . DB_PREFIX . "novaposhta_warehouse`
			WHERE `city_ref` = '" . $this->db->escape($cityRef) . "'
			AND `status` = 'Working'
			AND `deny` = 0";

		if ($locker) {
			$sql .= " AND LOWER(`category`) = 'postomat'";
		} else {
			$sql .= " AND LOWER(`category`) <> 'postomat'";
		}

		if ($weight > 0) {
			$w = (float)$weight;
			$sql .= " AND (`total_max_weight` >= '" . $w . "' OR (`total_max_weight` = 0 AND (`place_max_weight` >= '" . $w . "' OR `place_max_weight` = 0)))";
		}

		if ($length > 0 && $width > 0 && $height > 0) {
			$sql .= " AND (`max_length` = 0 OR `max_length` >= '" . (int)$length . "')
				AND (`max_width` = 0 OR `max_width` >= '" . (int)$width . "')
				AND (`max_height` = 0 OR `max_height` >= '" . (int)$height . "')";
		}

		$query = trim($query);
		if ($query !== '') {
			if (ctype_digit($query)) {
				$sql .= " AND `number` = '" . (int)$query . "'";
			} else {
				$like = '%' . $this->db->escape($query) . '%';
				$sql .= " AND (`description` LIKE '" . $like . "' OR `description_ru` LIKE '" . $like . "')";
			}
		}

		$sql .= " ORDER BY `number` LIMIT 30";

		$rows = $this->db->query($sql)->rows;
		$out = array();

		foreach ($rows as $row) {
			$out[] = array(
				'ref'         => $row['ref'],
				'division_id' => $row['division_id'],
				'name'        => $row['name'] !== '' ? $row['name'] : $row['name_uk'],
				'number'      => (int)$row['number'],
				'category'    => $row['category'],
				'lat'         => $row['latitude'] !== null ? (float)$row['latitude'] : null,
				'lng'         => $row['longitude'] !== null ? (float)$row['longitude'] : null
			);
		}

		return $out;
	}

	public function findCityRef($name) {
		$name = trim((string)$name);
		if ($name === '') {
			return '';
		}
		$parts = preg_split('/[,|]/', $name);
		$plain = trim($parts[0]);

		$query = $this->db->query("SELECT `ref` FROM `" . DB_PREFIX . "novaposhta_city`
			WHERE `description` = '" . $this->db->escape($name) . "' OR `description_ru` = '" . $this->db->escape($name) . "'
			OR `description` = '" . $this->db->escape($plain) . "' OR `description_ru` = '" . $this->db->escape($plain) . "'
			LIMIT 1");
		if ($query->num_rows) {
			return $query->row['ref'];
		}
		if (strlen($plain) < 3) {
			return '';
		}
		$query = $this->db->query("SELECT `ref` FROM `" . DB_PREFIX . "novaposhta_city`
			WHERE `description` LIKE '" . $this->db->escape($plain) . "%' OR `description_ru` LIKE '" . $this->db->escape($plain) . "%'
			ORDER BY CHAR_LENGTH(`description`) LIMIT 1");
		return $query->num_rows ? $query->row['ref'] : '';
	}

	public function findWarehouseRef($cityRef, $name) {
		$name = trim((string)$name);
		if ($name === '' || $cityRef === '') {
			return '';
		}

		$query = $this->db->query("SELECT `ref` FROM `" . DB_PREFIX . "novaposhta_warehouse`
			WHERE `city_ref` = '" . $this->db->escape($cityRef) . "'
			AND (`description` = '" . $this->db->escape($name) . "' OR `description_ru` = '" . $this->db->escape($name) . "')
			LIMIT 1");
		if ($query->num_rows) {
			return $query->row['ref'];
		}

		$number = 0;
		if (preg_match('/№\s*(\d+)/u', $name, $match) || preg_match('/#\s*(\d+)/', $name, $match)) {
			$number = (int)$match[1];
		} elseif (preg_match('/^\s*(\d{1,4})\s*$/', $name, $match)) {
			$number = (int)$match[1];
		}
		if ($number) {
			$query = $this->db->query("SELECT `ref` FROM `" . DB_PREFIX . "novaposhta_warehouse`
				WHERE `city_ref` = '" . $this->db->escape($cityRef) . "' AND `number` = '" . $number . "'
				LIMIT 1");
			if ($query->num_rows) {
				return $query->row['ref'];
			}
		}

		if (strlen($name) < 4) {
			return '';
		}
		$query = $this->db->query("SELECT `ref` FROM `" . DB_PREFIX . "novaposhta_warehouse`
			WHERE `city_ref` = '" . $this->db->escape($cityRef) . "'
			AND (`description` LIKE '%" . $this->db->escape($name) . "%' OR `description_ru` LIKE '%" . $this->db->escape($name) . "%')
			LIMIT 1");
		return $query->num_rows ? $query->row['ref'] : '';
	}

	public function cityAreaRef($cityRef) {
		$query = $this->db->query("SELECT `area_ref` FROM `" . DB_PREFIX . "novaposhta_city` WHERE `ref` = '" . $this->db->escape($cityRef) . "' LIMIT 1");
		return $query->num_rows ? $query->row['area_ref'] : '';
	}

	public function tariffCost($weight, $zone, $serviceType, $method) {
		$tariffs = $this->config->get('shipping_novaposhta_tariff');
		if (!is_array($tariffs) || !$tariffs) {
			return 0;
		}

		usort($tariffs, function ($a, $b) {
			$aw = isset($a['weight']) ? (float)$a['weight'] : 0;
			$bw = isset($b['weight']) ? (float)$b['weight'] : 0;
			if ($aw == $bw) {
				return 0;
			}
			return ($aw < $bw) ? -1 : 1;
		});

		$cost = 0;
		$matched = false;

		foreach ($tariffs as $row) {
			if (!isset($row['weight'])) {
				continue;
			}
			if ($weight <= (float)$row['weight']) {
				$zoneKey = isset($row[$zone]) ? $zone : 'ukraine';
				$cost = isset($row[$zoneKey]) ? (float)$row[$zoneKey] : 0;
				if ($serviceType === 'DoorsWarehouse' || $serviceType === 'DoorsDoors') {
					$cost += isset($row['doors_pickup']) ? (float)$row['doors_pickup'] : 0;
				}
				if ($serviceType === 'WarehouseDoors' || $serviceType === 'DoorsDoors') {
					$cost += isset($row['doors']) ? (float)$row['doors'] : 0;
				}
				if ($method === 'poshtomat') {
					$cost += isset($row['locker']) ? (float)$row['locker'] : 0;
				}
				$matched = true;
				break;
			}
		}

		return $matched ? round($cost, 2) : 0;
	}

	public function quoteApi($cityRef, $divisionRef, $serviceType, $metrics, $declared, $cod) {
		$keyData = array(
			$cityRef,
			$divisionRef,
			$serviceType,
			round($metrics['weight'], 1),
			round($metrics['volume_weight'], 1),
			(int)$declared,
			(int)$cod,
			date('Y-m-d'),
			$this->config->get('shipping_novaposhta_sender_city')
		);
		$cacheKey = sha1(implode('|', $keyData));

		$this->db->query("DELETE FROM `" . DB_PREFIX . "novaposhta_price_cache` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL 6 HOUR)");
		$cached = $this->db->query("SELECT * FROM `" . DB_PREFIX . "novaposhta_price_cache` WHERE `cache_key` = '" . $this->db->escape($cacheKey) . "' LIMIT 1");

		if ($cached->num_rows) {
			return array(
				'cost'   => (float)$cached->row['cost'],
				'period' => (int)$cached->row['period']
			);
		}

		$api = $this->api();
		$props = array(
			'CitySender'    => $this->config->get('shipping_novaposhta_sender_city'),
			'CityRecipient' => $cityRef,
			'ServiceType'   => $serviceType,
			'CargoType'     => 'Parcel',
			'Weight'        => (string)max(0.1, $metrics['weight']),
			'VolumeWeight'  => (string)max(0, $metrics['volume_weight']),
			'SeatsAmount'   => '1',
			'Cost'          => (string)max(1, round($declared)),
			'DateTime'      => date('d.m.Y')
		);

		if ($divisionRef) {
			$props['RecipientAddress'] = $divisionRef;
		}

		$senderAddress = $this->config->get('shipping_novaposhta_sender_address');
		if ($senderAddress) {
			$props['SenderAddress'] = $senderAddress;
		}

		if ($cod > 0) {
			$props['RedeliveryCalculate'] = array(
				'CargoType' => 'Money',
				'Amount'    => (string)round($cod)
			);
		}

		$data = $api->price($props);
		if ($data === false || empty($data[0]['Cost'])) {
			return false;
		}

		$cost = (float)$data[0]['Cost'];
		$period = 0;

		$date = $api->deliveryDate(array(
			'CitySender'    => $props['CitySender'],
			'CityRecipient' => $cityRef,
			'ServiceType'   => $serviceType,
			'CargoType'     => 'Parcel',
			'DateTime'      => date('d.m.Y')
		));

		if ($date && !empty($date[0]['DeliveryDate'])) {
			$delivery = DateTime::createFromFormat('d.m.Y', $date[0]['DeliveryDate']);
			if ($delivery) {
				$today = new DateTime('today');
				$period = (int)$today->diff($delivery)->format('%r%a');
				if ($period < 0) {
					$period = 0;
				}
			}
		}

		$this->db->query("REPLACE INTO `" . DB_PREFIX . "novaposhta_price_cache` SET
			`cache_key` = '" . $this->db->escape($cacheKey) . "',
			`cost` = '" . (float)$cost . "',
			`period` = '" . (int)$period . "',
			`created_at` = NOW()");

		return array('cost' => $cost, 'period' => $period);
	}

	public function cartMetrics($cart) {
		$weightClass = $this->unitClass('weight', array('kg', 'кг'));
		$lengthClass = $this->unitClass('length', array('cm', 'см'));

		$weight = 0;
		$volumeCm = 0;
		$maxL = 0;
		$maxW = 0;
		$maxH = 0;

		foreach ($cart->getProducts() as $product) {
			$qty = (int)$product['quantity'];
			$w = (float)$product['weight'];
			if ($weightClass && $this->weight) {
				$w = $this->weight->convert($w, $product['weight_class_id'], $weightClass);
			}
			$weight += $w * $qty;

			$l = (float)$product['length'];
			$wd = (float)$product['width'];
			$h = (float)$product['height'];
			if ($lengthClass && $this->length) {
				$l = $this->length->convert($l, $product['length_class_id'], $lengthClass);
				$wd = $this->length->convert($wd, $product['length_class_id'], $lengthClass);
				$h = $this->length->convert($h, $product['length_class_id'], $lengthClass);
			}

			$maxL = max($maxL, $l);
			$maxW = max($maxW, $wd);
			$maxH = max($maxH, $h);
			if ($l > 0 && $wd > 0 && $h > 0) {
				$volumeCm += $l * $wd * $h * $qty;
			}
		}

		$fallback = (float)$this->config->get('shipping_novaposhta_default_weight');
		if ($fallback <= 0) {
			$fallback = 0.5;
		}
		if ($weight <= 0) {
			$weight = $fallback;
		}

		$volumeM = $volumeCm / 1000000;
		$volumeWeight = $volumeM * 250;

		return array(
			'weight'        => round($weight, 3),
			'volume_weight' => round($volumeWeight, 3),
			'length'        => (int)round($maxL),
			'width'         => (int)round($maxW),
			'height'        => (int)round($maxH)
		);
	}

	public function toUah($amount) {
		$value = $this->currency->getValue('UAH');
		if (!$value) {
			return (float)$amount;
		}
		return (float)$amount * $value;
	}

	public function fromUah($amount) {
		$value = $this->currency->getValue('UAH');
		if (!$value) {
			return (float)$amount;
		}
		return (float)$amount / $value;
	}

	public function saveSelection($orderId, array $selection) {
		$orderId = (int)$orderId;
		$fields = array(
			'method', 'city_ref', 'city_name', 'division_ref', 'division_name',
			'settlement_ref', 'street_ref', 'street_name', 'building', 'flat'
		);

		$sets = array("`order_id` = '" . $orderId . "'");
		foreach ($fields as $field) {
			$value = isset($selection[$field]) ? (string)$selection[$field] : '';
			$sets[] = "`" . $field . "` = '" . $this->db->escape($value) . "'";
		}
		if (!empty($selection['division_id'])) {
			$sets[] = "`division_id` = '" . (int)$selection['division_id'] . "'";
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "novaposhta_order` SET " . implode(', ', $sets) . "
			ON DUPLICATE KEY UPDATE " . implode(', ', array_slice($sets, 1)));
	}

	public function getOrderRow($orderId) {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "novaposhta_order` WHERE `order_id` = '" . (int)$orderId . "' LIMIT 1");
		return $query->num_rows ? $query->row : array();
	}

	public function createDocument(array $order, array $row, array $input) {
		$sender = $this->resolveSender(isset($input['sender_id']) ? $input['sender_id'] : '');
		$api = $this->api($sender['api_key']);
		$phone = NovaposhtaApi::phone(!empty($input['phone']) ? $input['phone'] : $order['telephone']);

		$first = $order['firstname'];
		$last = $order['lastname'] !== '' ? $order['lastname'] : $order['firstname'];
		$pair = $this->recipientPair(
			$api,
			$first,
			$last,
			$phone,
			isset($order['email']) ? $order['email'] : '',
			isset($input['edrpou']) ? $input['edrpou'] : '',
			isset($input['company']) ? $input['company'] : ''
		);
		if (!empty($pair['error'])) {
			return $pair;
		}
		$recipientRef = $pair['ref'];
		$contactRef = $pair['contact'];

		$method = !empty($row['method']) ? $row['method'] : 'department';
		$senderType = $sender['type'] === 'Doors' ? 'Doors' : 'Warehouse';

		if ($method === 'doors') {
			$service = $senderType . 'Doors';
			$address = $api->saveAddress(array(
				'CounterpartyRef' => $recipientRef,
				'StreetRef'       => $row['street_ref'],
				'BuildingNumber'  => $row['building'],
				'Flat'            => $row['flat']
			));
			if ($address === false || empty($address[0]['Ref'])) {
				return array('error' => $api->getLastError() ? $api->getLastError() : 'Address was not created');
			}
			$recipientAddress = $address[0]['Ref'];
		} else {
			$service = $senderType . 'Warehouse';
			$recipientAddress = $row['division_ref'];
		}

		$cargo = $this->orderCargo($order['order_id']);
		$description = !empty($input['description']) ? $input['description'] : $this->config->get('shipping_novaposhta_cargo_description');
		$description = str_replace(
			array('{order_id}', '{firstname}', '{lastname}'),
			array($order['order_id'], $order['firstname'], $order['lastname']),
			(string)$description
		);
		$generic = ($description === '' || $description === ('Замовлення ' . $order['order_id']));
		if ($generic && $cargo['description'] !== '') {
			$description = $cargo['description'];
		}
		if ($description === '') {
			$description = 'Замовлення ' . $order['order_id'];
		}
		if (empty($input['weight']) || (float)$input['weight'] <= 0) {
			$input['weight'] = $cargo['weight'];
		}

		$seats = $this->seatsFromInput($input);
		if (!$seats && !empty($cargo['places'])) {
			$input['places'] = $cargo['places'];
			$seats = $this->seatsFromInput($input);
		}
		$weight = max(0.1, (float)$input['weight']);
		$seatCount = max(1, (int)$input['seats']);
		if ($seats) {
			$weight = 0;
			foreach ($seats as $seat) {
				$weight += (float)$seat['weight'];
			}
			$seatCount = count($seats);
		}

		$props = array(
			'PayerType'        => !empty($input['payer']) ? $input['payer'] : ($this->config->get('shipping_novaposhta_payer') ? $this->config->get('shipping_novaposhta_payer') : 'Recipient'),
			'PaymentMethod'    => !empty($input['payment']) ? $input['payment'] : ($this->config->get('shipping_novaposhta_payment') ? $this->config->get('shipping_novaposhta_payment') : 'Cash'),
			'DateTime'         => date('d.m.Y'),
			'CargoType'        => $this->cargoType($input),
			'Weight'           => (string)max(0.1, $weight),
			'ServiceType'      => $service,
			'SeatsAmount'      => (string)$seatCount,
			'Description'      => $description,
			'Cost'             => (string)max(1, round((float)$input['cost'])),
			'CitySender'       => $sender['city'],
			'Sender'           => $sender['ref'],
			'SenderAddress'    => $sender['address'],
			'ContactSender'    => $sender['contact'],
			'SendersPhone'     => NovaposhtaApi::phone($sender['phone']),
			'CityRecipient'    => $row['city_ref'],
			'Recipient'        => $recipientRef,
			'RecipientAddress' => $recipientAddress,
			'ContactRecipient' => $contactRef,
			'RecipientsPhone'  => $phone
		);
		if ($seats) {
			$props['OptionsSeat'] = $seats;
		}
		if ($props['CargoType'] === 'TiresWheels') {
			$props['CargoDetails'] = array(array(
				'CargoDescription' => $description,
				'Amount'           => (string)$seatCount
			));
		}

		$backward = isset($input['backward']) ? $input['backward'] : '';
		if ($backward === '' && !empty($input['cod'])) {
			$backward = 'Money';
		}
		$backwardMap = array('money' => 'Money', 'Money' => 'Money', 'documents' => 'Documents', 'Documents' => 'Documents', 'trays' => 'Trays', 'Trays' => 'Trays', 'other' => 'Other', 'Other' => 'Other');
		if (isset($backwardMap[$backward])) {
			$cargo = $backwardMap[$backward];
			$value = $cargo === 'Money'
				? (string)round((float)$input['cod'])
				: (isset($input['backward_info']) && $input['backward_info'] !== '' ? $input['backward_info'] : '1');
			if ($cargo !== 'Money' || (float)$value > 0) {
				$props['BackwardDeliveryData'] = array(array(
					'PayerType'        => 'Recipient',
					'CargoType'        => $cargo,
					'RedeliveryString' => $value
				));
			}
		}

		$data = $api->saveDocument($props);
		if ($data === false || empty($data[0]['IntDocNumber'])) {
			$error = $api->getLastError();
			if (empty($input['no_queue'])) {
				$this->enqueue((int)$order['order_id'], $input, $error);
			}
			return array('error' => $error, 'queued' => empty($input['no_queue']));
		}

		$this->db->query("UPDATE `" . DB_PREFIX . "novaposhta_order` SET
			`cn_number` = '" . $this->db->escape($data[0]['IntDocNumber']) . "',
			`cn_ref` = '" . $this->db->escape($data[0]['Ref']) . "',
			`sender_id` = '" . $this->db->escape($sender['id']) . "'
			WHERE `order_id` = '" . (int)$order['order_id'] . "'");

		$this->notifyCustomer($order, $data[0]['IntDocNumber']);

		return array(
			'number' => $data[0]['IntDocNumber'],
			'ref'    => $data[0]['Ref'],
			'cost'   => isset($data[0]['CostOnSite']) ? $data[0]['CostOnSite'] : ''
		);
	}

	public function deleteDocument($orderId) {
		$row = $this->getOrderRow($orderId);
		if (empty($row['cn_ref'])) {
			return array('error' => 'TTN not found');
		}

		$sender = $this->resolveSender(isset($row['sender_id']) ? $row['sender_id'] : '');
		$api = $this->api($sender['api_key']);
		$result = $api->deleteDocument($row['cn_ref']);
		if ($result === false) {
			return array('error' => $api->getLastError());
		}

		$this->db->query("UPDATE `" . DB_PREFIX . "novaposhta_order` SET `cn_number` = '', `cn_ref` = '' WHERE `order_id` = '" . (int)$orderId . "'");
		return array('success' => true);
	}

	public function trackBatch($limit = 100) {
		$api = $this->api();
		$query = $this->db->query("SELECT o.order_id, o.telephone, o.payment_code, o.order_status_id, n.cn_number, n.status_code
			FROM `" . DB_PREFIX . "novaposhta_order` n
			INNER JOIN `" . DB_PREFIX . "order` o ON o.order_id = n.order_id
			WHERE n.cn_number <> ''
			AND (n.status_code IS NULL OR n.status_code NOT IN (9, 10, 11, 2, 102, 103))
			ORDER BY n.status_at IS NULL DESC, n.status_at ASC
			LIMIT " . (int)$limit);

		if (!$query->num_rows) {
			return array('checked' => 0, 'updated' => 0);
		}

		$documents = array();
		$byNumber = array();
		foreach ($query->rows as $row) {
			$documents[] = array(
				'DocumentNumber' => $row['cn_number'],
				'Phone'          => NovaposhtaApi::phone($row['telephone'])
			);
			$byNumber[$row['cn_number']] = $row;
		}

		$data = $api->track($documents);
		if ($data === false) {
			return array('error' => $api->getLastError());
		}

		$map = $this->config->get('shipping_novaposhta_status_map');
		if (!is_array($map)) {
			$map = array();
		}

		$updated = 0;
		foreach ($data as $item) {
			if (empty($item['Number']) || !isset($byNumber[$item['Number']])) {
				continue;
			}

			$code = isset($item['StatusCode']) ? (int)$item['StatusCode'] : 0;
			$text = isset($item['Status']) ? $item['Status'] : '';
			$order = $byNumber[$item['Number']];

			$this->db->query("UPDATE `" . DB_PREFIX . "novaposhta_order` SET
				`status_code` = '" . $code . "',
				`status_text` = '" . $this->db->escape($text) . "',
				`status_at` = NOW()
				WHERE `order_id` = '" . (int)$order['order_id'] . "'");

			if ($code && (int)$order['status_code'] !== $code) {
				$target = !empty($map[$code]) ? (int)$map[$code] : 0;
				$paidStatus = (int)$this->config->get('shipping_novaposhta_cod_status');
				$codPayments = $this->config->get('shipping_novaposhta_cod');
				if (!is_array($codPayments)) {
					$codPayments = array();
				}
				if ($code === 10 && $paidStatus && in_array($order['payment_code'], $codPayments, true)) {
					$target = $paidStatus;
				}
				if ($target && (int)$order['order_status_id'] !== $target) {
					$this->registry->get('load')->model('checkout/order');
					$this->registry->get('model_checkout_order')->addOrderHistory(
						(int)$order['order_id'],
						$target,
						'Нова пошта: ' . $text . ' (' . $order['cn_number'] . ')',
						(bool)$this->config->get('shipping_novaposhta_notify')
					);
					$updated++;
				}
				$this->alertManager($order, $code, $text);
			}
		}

		return array('checked' => count($documents), 'updated' => $updated);
	}

	private function insertCities($rows) {
		$chunk = array();
		foreach ($rows as $row) {
			if (empty($row['Ref']) || empty($row['Description'])) {
				continue;
			}
			$chunk[] = "(
				'" . $this->db->escape($row['Ref']) . "',
				'" . $this->db->escape(isset($row['Area']) ? $row['Area'] : '') . "',
				'" . $this->db->escape($row['Description']) . "',
				'" . $this->db->escape(isset($row['DescriptionRu']) ? $row['DescriptionRu'] : '') . "',
				'" . $this->db->escape(isset($row['SettlementTypeDescription']) ? $row['SettlementTypeDescription'] : '') . "'
			)";
			if (count($chunk) >= 200) {
				$this->flushCities($chunk);
				$chunk = array();
			}
		}
		$this->flushCities($chunk);
	}

	private function flushCities($chunk) {
		if (!$chunk) {
			return;
		}
		$this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "novaposhta_city`
			(`ref`, `area_ref`, `description`, `description_ru`, `settlement_type`) VALUES " . implode(',', $chunk));
	}

	private function insertWarehouses($rows, $locker = false) {
		$chunk = array();
		foreach ($rows as $row) {
			if (empty($row['Ref']) || !empty($row['DenyToSelect'])) {
				continue;
			}

			$limits = array();
			if (isset($row['ReceivingLimitationsOnDimensions']) && is_array($row['ReceivingLimitationsOnDimensions'])) {
				$limits = $row['ReceivingLimitationsOnDimensions'];
			}
			$category = isset($row['CategoryOfWarehouse']) ? $row['CategoryOfWarehouse'] : '';
			if ($locker) {
				$category = 'Postomat';
			} elseif ($category === '') {
				$category = 'Branch';
			}
			$description = $this->clip(isset($row['Description']) ? $row['Description'] : '', 1000);
			$descriptionRu = $this->clip(isset($row['DescriptionRu']) ? $row['DescriptionRu'] : '', 1000);
			$maxL = isset($limits['Length']) ? $limits['Length'] : (isset($row['ReceivingLimitationsOnDimensions_length']) ? $row['ReceivingLimitationsOnDimensions_length'] : 0);
			$maxW = isset($limits['Width']) ? $limits['Width'] : (isset($row['ReceivingLimitationsOnDimensions_width']) ? $row['ReceivingLimitationsOnDimensions_width'] : 0);
			$maxH = isset($limits['Height']) ? $limits['Height'] : (isset($row['ReceivingLimitationsOnDimensions_height']) ? $row['ReceivingLimitationsOnDimensions_height'] : 0);

			$chunk[] = "(
				'" . $this->db->escape($row['Ref']) . "',
				'" . $this->db->escape(isset($row['CityRef']) ? $row['CityRef'] : '') . "',
				'" . $this->db->escape(isset($row['SettlementRef']) ? $row['SettlementRef'] : '') . "',
				'" . $this->db->escape($description) . "',
				'" . $this->db->escape($descriptionRu) . "',
				'" . (int)(isset($row['Number']) ? $row['Number'] : 0) . "',
				'" . $this->db->escape($category) . "',
				'" . $this->db->escape(isset($row['TypeOfWarehouse']) ? $row['TypeOfWarehouse'] : '') . "',
				'" . $this->db->escape(isset($row['WarehouseStatus']) ? $row['WarehouseStatus'] : '') . "',
				'" . (int)(isset($row['TotalMaxWeightAllowed']) ? $row['TotalMaxWeightAllowed'] : 0) . "',
				'" . (int)(isset($row['PlaceMaxWeightAllowed']) ? $row['PlaceMaxWeightAllowed'] : 0) . "',
				'" . (int)$maxL . "',
				'" . (int)$maxW . "',
				'" . (int)$maxH . "',
				'" . (int)(isset($row['MaxDeclaredCost']) ? $row['MaxDeclaredCost'] : 0) . "',
				" . (isset($row['Latitude']) && $row['Latitude'] !== '' ? "'" . (float)$row['Latitude'] . "'" : "NULL") . ",
				" . (isset($row['Longitude']) && $row['Longitude'] !== '' ? "'" . (float)$row['Longitude'] . "'" : "NULL") . ",
				'" . $this->db->escape(isset($row['PostMachineType']) ? $row['PostMachineType'] : '') . "',
				'0'
			)";

			if (count($chunk) >= 100) {
				$this->flushWarehouses($chunk);
				$chunk = array();
			}
		}
		$this->flushWarehouses($chunk);
	}

	private function flushWarehouses($chunk) {
		if (!$chunk) {
			return;
		}
		$this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "novaposhta_warehouse`
			(`ref`, `city_ref`, `settlement_ref`, `description`, `description_ru`, `number`, `category`, `type_ref`, `status`,
			`total_max_weight`, `place_max_weight`, `max_length`, `max_width`, `max_height`, `max_declared_cost`,
			`latitude`, `longitude`, `post_machine_type`, `deny`) VALUES " . implode(',', $chunk));
	}

	private function clip($value, $length) {
		$value = (string)$value;
		if (function_exists('mb_substr')) {
			return mb_substr($value, 0, $length, 'UTF-8');
		}
		return substr($value, 0, $length);
	}

	private function unitClass($type, array $units) {
		$list = array();
		foreach ($units as $unit) {
			$list[] = "'" . $this->db->escape($unit) . "'";
		}
		$query = $this->db->query("SELECT `" . $type . "_class_id` AS id FROM `" . DB_PREFIX . $type . "_class_description`
			WHERE LOWER(`unit`) IN (" . implode(',', $list) . ") LIMIT 1");
		return $query->num_rows ? (int)$query->row['id'] : 0;
	}
}
