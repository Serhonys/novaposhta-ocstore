<?php
require_once DIR_SYSTEM . 'library/novaposhta/service.php';
require_once DIR_SYSTEM . 'library/novaposhta/api.php';

class ControllerExtensionModuleNovaposhta extends Controller {
	public function eventHeader(&$route, &$data) {
		if (!$this->config->get('shipping_novaposhta_status')) {
			return;
		}

		$this->document->addScript('catalog/view/javascript/novaposhta.js');
		$this->document->addStyle('catalog/view/theme/default/stylesheet/novaposhta.css');
	}

	public function eventHeaderAfter(&$route, &$data, &$output) {
		if (!$this->config->get('shipping_novaposhta_status')) {
			return;
		}

		$this->load->language('extension/shipping/novaposhta');
		$service = new NovaposhtaService($this->registry);
		$cookie = isset($this->request->cookie['np_pick']) ? $this->request->cookie['np_pick'] : '';
		$customerId = $this->customer->isLogged() ? (int)$this->customer->getId() : 0;
		$service->hydrateSelection($this->session->data, $customerId, $cookie);
		$saved = isset($this->session->data['novaposhta']) ? $this->session->data['novaposhta'] : array();

		$config = array(
			'countryId'    => (int)$this->config->get('shipping_novaposhta_country_id'),
			'cityUrl'      => $this->url->link('extension/module/novaposhta/cities', '', true),
			'warehouseUrl' => $this->url->link('extension/module/novaposhta/warehouses', '', true),
			'streetUrl'    => $this->url->link('extension/module/novaposhta/streets', '', true),
			'settlementUrl'=> $this->url->link('extension/module/novaposhta/settlements', '', true),
			'saveUrl'      => $this->url->link('extension/module/novaposhta/save', '', true),
			'city'         => (string)$this->config->get('shipping_novaposhta_checkout_city'),
			'point'        => (string)$this->config->get('shipping_novaposhta_checkout_point'),
			'street'       => (string)$this->config->get('shipping_novaposhta_checkout_street'),
			'building'     => (string)$this->config->get('shipping_novaposhta_checkout_building'),
			'flat'         => (string)$this->config->get('shipping_novaposhta_checkout_flat'),
			'method'       => !empty($saved['method']) ? $saved['method'] : 'department'
		);

		$output .= '<script>window.NovaPoshtaConfig=' . json_encode($config, JSON_UNESCAPED_UNICODE) . ';</script>';
	}

	public function eventAddOrder(&$route, &$args, &$order_id) {
		if (!$order_id || empty($args[0]['shipping_code']) || strpos($args[0]['shipping_code'], 'novaposhta.') !== 0) {
			return;
		}

		$selection = isset($this->session->data['novaposhta']) && is_array($this->session->data['novaposhta'])
			? $this->session->data['novaposhta'] : array();

		$selection['method'] = substr($args[0]['shipping_code'], strlen('novaposhta.'));
		$service = new NovaposhtaService($this->registry);
		$fromFields = $service->valuesFromCustomFields($args[0]);
		if (empty($selection['city_name'])) {
			if (!empty($fromFields['city_name'])) {
				$selection['city_name'] = $fromFields['city_name'];
			} elseif (!empty($args[0]['shipping_city'])) {
				$selection['city_name'] = $args[0]['shipping_city'];
			}
		}
		if (empty($selection['division_name'])) {
			if (!empty($fromFields['division_name'])) {
				$selection['division_name'] = $fromFields['division_name'];
			} elseif (!empty($args[0]['shipping_address_1'])) {
				$selection['division_name'] = $args[0]['shipping_address_1'];
			}
		}
		foreach (array('street_name', 'building', 'flat') as $key) {
			if (empty($selection[$key]) && !empty($fromFields[$key])) {
				$selection[$key] = $fromFields[$key];
			}
		}
		if (empty($selection['city_ref']) && !empty($selection['city_name'])) {
			$selection['city_ref'] = $service->findCityRef($selection['city_name']);
		}
		if (empty($selection['division_ref']) && !empty($selection['city_ref']) && !empty($selection['division_name']) && $selection['method'] !== 'doors') {
			$selection['division_ref'] = $service->findWarehouseRef($selection['city_ref'], $selection['division_name']);
		}

		$service->saveSelection((int)$order_id, $selection);
		$service->writeCustomFields((int)$order_id, $selection);
	}

	public function hours() {
		$service = new NovaposhtaService($this->registry);
		$this->json(array('hours' => $service->warehouseHours(isset($this->request->get['ref']) ? $this->request->get['ref'] : '')));
	}

	public function changePoint() {
		if (!$this->customer->isLogged()) {
			$this->json(array('error' => 'login'));
			return;
		}
		$service = new NovaposhtaService($this->registry);
		$this->json($service->changePoint((int)$this->customer->getId(), isset($this->request->post['order_id']) ? (int)$this->request->post['order_id'] : 0, $this->request->post));
	}

	public function nearest() {
		$service = new NovaposhtaService($this->registry);
		$lat = isset($this->request->get['lat']) ? (float)$this->request->get['lat'] : 0;
		$lng = isset($this->request->get['lng']) ? (float)$this->request->get['lng'] : 0;
		if (!$lat || !$lng) {
			$this->json(array());
			return;
		}
		$this->json($service->nearest(
			$lat,
			$lng,
			isset($this->request->get['city_ref']) ? $this->request->get['city_ref'] : '',
			!empty($this->request->get['locker']),
			$this->isRussian()
		));
	}

	public function map() {
		$service = new NovaposhtaService($this->registry);
		$city = isset($this->request->get['city_ref']) ? $this->request->get['city_ref'] : '';
		if ($city === '') {
			$this->json(array());
			return;
		}
		$this->json($service->mapPoints($city, !empty($this->request->get['locker']), $this->isRussian()));
	}

	public function account() {
		if (!$this->customer->isLogged()) {
			$this->response->redirect($this->url->link('account/login', '', true));
			return;
		}

		$this->load->language('extension/shipping/novaposhta');
		$this->document->setTitle($this->language->get('text_my_parcels'));
		$this->document->addStyle('catalog/view/theme/default/stylesheet/novaposhta.css');
		$service = new NovaposhtaService($this->registry);
		$data = $this->language->all();
		$data['rows'] = array();
		foreach ($service->customerShipments((int)$this->customer->getId()) as $row) {
			$data['rows'][] = array(
				'order_id' => $row['order_id'],
				'date'     => date('d.m.Y', strtotime($row['date_added'])),
				'city'     => $row['city_name'],
				'point'    => $row['division_name'] !== '' ? $row['division_name'] : $row['street_name'],
				'cn'       => $row['cn_number'],
				'status'   => $row['status_text'],
				'track'    => $row['cn_number'] !== '' ? 'https://novaposhta.ua/tracking/?cargo_number=' . rawurlencode($row['cn_number']) : '',
				'open'     => $row['cn_number'] === '' || $row['cn_number'] === null
			);
		}
		$data['back'] = $this->url->link('account/account', '', true);
		$data['city_url'] = $this->url->link('extension/module/novaposhta/cities', '', true);
		$data['point_url'] = $this->url->link('extension/module/novaposhta/warehouses', '', true);
		$data['change_url'] = $this->url->link('extension/module/novaposhta/changePoint', '', true);
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['column_right'] = $this->load->controller('common/column_right');
		$data['content_top'] = $this->load->controller('common/content_top');
		$data['content_bottom'] = $this->load->controller('common/content_bottom');
		$data['footer'] = $this->load->controller('common/footer');
		$data['header'] = $this->load->controller('common/header');
		$this->response->setOutput($this->load->view('extension/module/novaposhta_account', $data));
	}

	public function eventAccount(&$route, &$data, &$output) {
		if (!$this->config->get('shipping_novaposhta_status') || !$this->customer->isLogged()) {
			return;
		}
		$this->load->language('extension/shipping/novaposhta');
		$href = $this->url->link('extension/module/novaposhta/account', '', true);
		$link = '<p class="np-account-link"><a href="' . $href . '">' . $this->language->get('text_my_parcels') . '</a></p>';
		$output = preg_replace('/(<div[^>]*id="content"[^>]*>)/', '$1' . $link, $output, 1);
	}

	public function cities() {
		$service = new NovaposhtaService($this->registry);
		$query = isset($this->request->get['q']) ? $this->request->get['q'] : '';
		$this->json($service->searchCities($query, $this->isRussian()));
	}

	public function warehouses() {
		$service = new NovaposhtaService($this->registry);
		$metrics = $service->cartMetrics($this->cart);
		$methods = $this->config->get('shipping_novaposhta_method');
		$locker = !empty($this->request->get['locker']);
		$code = $locker ? 'poshtomat' : 'department';
		$filterWeight = !empty($methods[$code]['filter_weight']);
		$filterSize = !empty($methods[$code]['filter_size']);

		$rows = $service->searchWarehouses(
			isset($this->request->get['city_ref']) ? $this->request->get['city_ref'] : '',
			isset($this->request->get['q']) ? $this->request->get['q'] : '',
			$locker,
			$filterWeight ? $metrics['weight'] : 0,
			$filterSize ? $metrics['length'] : 0,
			$filterSize ? $metrics['width'] : 0,
			$filterSize ? $metrics['height'] : 0,
			$this->isRussian()
		);

		$this->json($rows);
	}

	public function settlements() {
		$api = new NovaposhtaApi($this->config->get('shipping_novaposhta_api_key'));
		$query = isset($this->request->get['q']) ? trim($this->request->get['q']) : '';
		if (utf8_strlen($query) < 2) {
			$this->json(array());
			return;
		}

		$rows = $api->searchSettlements($query);
		$out = array();
		if ($rows) {
			foreach ($rows as $row) {
				$out[] = array(
					'ref'           => isset($row['Ref']) ? $row['Ref'] : '',
					'delivery_city' => isset($row['DeliveryCity']) ? $row['DeliveryCity'] : '',
					'name'          => isset($row['Present']) ? $row['Present'] : (isset($row['MainDescription']) ? $row['MainDescription'] : ''),
					'main'          => isset($row['MainDescription']) ? $row['MainDescription'] : ''
				);
			}
		}

		$this->json($out);
	}

	public function streets() {
		$api = new NovaposhtaApi($this->config->get('shipping_novaposhta_api_key'));
		$settlement = isset($this->request->get['settlement_ref']) ? $this->request->get['settlement_ref'] : '';
		$query = isset($this->request->get['q']) ? trim($this->request->get['q']) : '';
		if ($settlement === '' || utf8_strlen($query) < 2) {
			$this->json(array());
			return;
		}

		$rows = $api->searchStreets($settlement, $query);
		$out = array();
		if ($rows) {
			foreach ($rows as $row) {
				$out[] = array(
					'ref'  => isset($row['SettlementStreetRef']) ? $row['SettlementStreetRef'] : '',
					'name' => isset($row['Present']) ? $row['Present'] : ''
				);
			}
		}

		$this->json($out);
	}

	public function save() {
		$json = json_decode(file_get_contents('php://input'), true);
		if (!is_array($json)) {
			$json = $this->request->post;
		}

		$keep = array(
			'method', 'city_ref', 'city_name', 'division_ref', 'division_id', 'division_name',
			'settlement_ref', 'delivery_city', 'street_ref', 'street_name', 'building', 'flat'
		);
		$selection = array();
		foreach ($keep as $key) {
			if (isset($json[$key])) {
				$selection[$key] = is_scalar($json[$key]) ? (string)$json[$key] : '';
			}
		}

		if (!empty($selection['delivery_city'])) {
			$selection['city_ref'] = $selection['delivery_city'];
		}

		$this->session->data['novaposhta'] = $selection;
		$this->json(array('ok' => true));
	}

	public function cron() {
		$key = isset($this->request->get['key']) ? (string)$this->request->get['key'] : '';
		$expected = (string)$this->config->get('shipping_novaposhta_cron_key');

		if ($expected === '' || !hash_equals($expected, $key)) {
			$this->response->addHeader('HTTP/1.1 403 Forbidden');
			$this->response->setOutput('Forbidden');
			return;
		}

		$service = new NovaposhtaService($this->registry);
		$task = isset($this->request->get['task']) ? $this->request->get['task'] : 'track';

		if ($task === 'track') {
			$this->json($service->trackBatch(100));
			return;
		}

		if ($task === 'queue') {
			$this->json($service->processQueue(10));
			return;
		}

		if (in_array($task, array('areas', 'cities', 'warehouses'), true)) {
			$page = isset($this->request->get['page']) ? (int)$this->request->get['page'] : 1;
			$type = isset($this->request->get['type']) ? (string)$this->request->get['type'] : '';
			$this->json($service->syncPage($task, $page, $type));
			return;
		}

		$this->json(array('error' => 'Unknown task'));
	}

	private function isRussian() {
		$mode = (string)$this->config->get('shipping_novaposhta_lang');
		if ($mode === 'ru') {
			return true;
		}
		if ($mode === 'uk') {
			return false;
		}
		$code = (string)$this->language->get('code');
		return strpos($code, 'ru') === 0;
	}

	private function json($data) {
		$this->response->addHeader('Content-Type: application/json; charset=utf-8');
		$this->response->setOutput(json_encode($data, JSON_UNESCAPED_UNICODE));
	}
}
