<?php
class Novaposhta {
	private $registry;

	public function __construct($registry) {
		$this->registry = $registry;
	}

	public function call($model, $method, $properties = array(), $api_key = '', $timeout = 30) {
		$allowed = $this->allowed();
		if (!isset($allowed[$model . '.' . $method])) {
			return array('success' => false, 'data' => array(), 'errors' => array('Метод не входит в каталог API v2.0 модуля'));
		}

		if (!is_array($properties)) {
			$properties = array();
		}

		$heavy = array('getCities' => 1, 'getSettlements' => 1, 'getWarehouses' => 1, 'getStreet' => 1, 'getDocumentList' => 1);
		if (isset($heavy[$method])) {
			if (!isset($properties['Limit']) || $properties['Limit'] === '') {
				$properties['Limit'] = '50';
			}
			if (!isset($properties['Page']) || $properties['Page'] === '') {
				$properties['Page'] = '1';
			}
		}

		$payload = json_encode(array(
			'apiKey' => (string)$api_key,
			'modelName' => $model,
			'calledMethod' => $method,
			'methodProperties' => $properties
		), JSON_UNESCAPED_UNICODE);

		$ch = curl_init('https://api.novaposhta.ua/v2.0/json/');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_TIMEOUT, max(5, min(60, (int)$timeout)));
		$raw = curl_exec($ch);
		curl_close($ch);

		if ($raw === false || $raw === '') {
			return array('success' => false, 'data' => array(), 'errors' => array('Нет соединения с api.novaposhta.ua'));
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return array('success' => false, 'data' => array(), 'errors' => array('Некорректный ответ API Новой Почты'));
		}

		return $decoded;
	}

	public function stateFromConfig() {
		$config = $this->registry->get('config');
		$raw = $config->get('shipping_novaposhta_json');
		$state = $raw ? json_decode($raw, true) : null;
		if (!is_array($state)) {
			$state = $this->defaultState();
		}
		return $state;
	}

	public function defaultState() {
		$method = function ($title, $enabled, $max, $free) {
			return array(
				'enabled' => $enabled,
				'title' => $title,
				'priceMode' => 'tariff',
				'fixedPrice' => '70',
				'freeFrom' => (string)$free,
				'maxWeight' => (string)$max
			);
		};

		return array(
			'connection' => array(
				'enabled' => true,
				'apiKey' => '',
				'format' => 'json',
				'timeoutSec' => '30',
				'debug' => false,
				'addressMode' => 'ref'
			),
			'warehouseKinds' => array('branch' => true, 'postomat' => true, 'cargo' => false, 'store' => false),
			'methods' => array(
				'WarehouseWarehouse' => $method('Нова Пошта (отделение / почтомат)', true, '30', '2000'),
				'WarehouseDoors' => $method('Курьером Новой Почты до двери', true, '30', '0'),
				'DoorsWarehouse' => $method('Забор курьером → отделение', false, '30', '0'),
				'DoorsDoors' => $method('Курьер → курьер', false, '30', '0')
			),
			'waybill' => array(
				'payer' => 'Recipient',
				'payment' => 'Cash',
				'cargoType' => 'Parcel',
				'weight' => '1.5',
				'seats' => '1',
				'length' => '30',
				'width' => '20',
				'height' => '15'
			),
			'money' => array(
				'cod' => true,
				'codPayer' => 'Recipient',
				'paymentControl' => false,
				'codCommissionNote' => 'Комиссия Новой Почты за денежный перевод: 20 грн + 2% от суммы заказа.'
			),
			'sender' => array('city' => 'м. Київ', 'phone' => '', 'name' => ''),
			'directories' => array('liveSearch' => true),
			'tracking' => array('enabled' => true)
		);
	}

	private function allowed() {
		static $map = null;
		if ($map !== null) {
			return $map;
		}
		$map = array();
		$file = DIR_SYSTEM . 'library/novaposhta_methods.json';
		if (is_file($file)) {
			$data = json_decode(file_get_contents($file), true);
			if (!empty($data['methods']) && is_array($data['methods'])) {
				foreach ($data['methods'] as $row) {
					if (!empty($row['model']) && !empty($row['method'])) {
						$map[$row['model'] . '.' . $row['method']] = true;
					}
				}
			}
		}
		return $map;
	}
}
