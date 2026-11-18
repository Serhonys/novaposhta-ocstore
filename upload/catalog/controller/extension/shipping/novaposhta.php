<?php
class ControllerExtensionShippingNovaposhta extends Controller {
	public function cities() {
		$q = isset($this->request->get['q']) ? trim($this->request->get['q']) : '';
		$this->load->library('novaposhta');
		$res = $this->novaposhta->call('Address', 'searchSettlements', array(
			'CityName' => $q,
			'Limit' => '12'
		), '', 15);
		$out = array();
		if (!empty($res['data'][0]['Addresses'])) {
			foreach ($res['data'][0]['Addresses'] as $row) {
				$out[] = array(
					'ref' => isset($row['DeliveryCity']) ? $row['DeliveryCity'] : '',
					'settlement' => isset($row['Ref']) ? $row['Ref'] : '',
					'label' => isset($row['Present']) ? $row['Present'] : '',
					'name' => isset($row['MainDescription']) ? $row['MainDescription'] : ''
				);
			}
		}
		$this->json($out);
	}

	public function warehouses() {
		$city_ref = isset($this->request->get['city_ref']) ? $this->request->get['city_ref'] : '';
		$city_name = isset($this->request->get['city']) ? $this->request->get['city'] : '';
		$props = array('Limit' => '50', 'Page' => '1');
		if ($city_ref) {
			$props['CityRef'] = $city_ref;
		} else {
			$props['CityName'] = $city_name;
		}
		$this->load->library('novaposhta');
		$res = $this->novaposhta->call('Address', 'getWarehouses', $props, '', 20);
		$this->json(array('success' => !empty($res['success']), 'data' => isset($res['data']) ? $res['data'] : array()));
	}

	public function streets() {
		$settlement = isset($this->request->get['settlement']) ? $this->request->get['settlement'] : '';
		$q = isset($this->request->get['q']) ? $this->request->get['q'] : '';
		$this->load->library('novaposhta');
		$res = $this->novaposhta->call('Address', 'searchSettlementStreets', array(
			'SettlementRef' => $settlement,
			'StreetName' => $q,
			'Limit' => '12'
		), '', 15);
		$rows = array();
		if (!empty($res['data'][0]['Addresses'])) {
			foreach ($res['data'][0]['Addresses'] as $row) {
				$rows[] = array(
					'ref' => isset($row['SettlementStreetRef']) ? $row['SettlementStreetRef'] : '',
					'name' => isset($row['Present']) ? $row['Present'] : (isset($row['SettlementStreetDescription']) ? $row['SettlementStreetDescription'] : '')
				);
			}
		}
		$this->json($rows);
	}

	public function select() {
		$body = json_decode(file_get_contents('php://input'), true);
		if (!is_array($body)) {
			$this->json(array('ok' => false));
			return;
		}
		$this->session->data['novaposhta'] = array(
			'service' => isset($body['service']) ? substr((string)$body['service'], 0, 32) : '',
			'city' => isset($body['city']) ? substr((string)$body['city'], 0, 128) : '',
			'cityRef' => isset($body['cityRef']) ? substr((string)$body['cityRef'], 0, 36) : '',
			'point' => isset($body['point']) ? substr((string)$body['point'], 0, 255) : '',
			'pointRef' => isset($body['pointRef']) ? substr((string)$body['pointRef'], 0, 36) : '',
			'pointKind' => isset($body['pointKind']) ? substr((string)$body['pointKind'], 0, 16) : ''
		);
		$this->json(array('ok' => true));
	}

	public function injectCheckout(&$route, &$data, &$output) {
		if (!$this->config->get('shipping_novaposhta_status')) {
			return;
		}
		$base = $this->config->get('config_ssl') ? $this->config->get('config_ssl') : $this->config->get('config_url');
		$output .= '<script src="' . $base . 'catalog/view/javascript/novaposhta/checkout.js?v=1"></script>';
	}

	public function inject(&$route, &$data, &$output) {
		if (!$this->config->get('shipping_novaposhta_status')) {
			return;
		}
		$this->load->library('novaposhta');
		$state = $this->novaposhta->stateFromConfig();
		if (empty($state['connection']['enabled'])) {
			return;
		}
		$weight = 1;
		if ($this->cart) {
			$weight = (float)$this->cart->getWeight();
		}
		$payload = array(
			'liveSearch' => !empty($state['directories']['liveSearch']),
			'kinds' => isset($state['warehouseKinds']) ? $state['warehouseKinds'] : array(),
			'weight' => $weight,
			'length' => isset($state['waybill']['length']) ? (float)$state['waybill']['length'] : 30,
			'width' => isset($state['waybill']['width']) ? (float)$state['waybill']['width'] : 20,
			'height' => isset($state['waybill']['height']) ? (float)$state['waybill']['height'] : 15,
			'cod' => !empty($state['money']['cod']) || !empty($state['money']['paymentControl']),
			'codNote' => isset($state['money']['codCommissionNote']) ? $state['money']['codCommissionNote'] : '',
			'citiesUrl' => $this->url->link('extension/shipping/novaposhta/cities', '', true),
			'warehousesUrl' => $this->url->link('extension/shipping/novaposhta/warehouses', '', true),
			'streetsUrl' => $this->url->link('extension/shipping/novaposhta/streets', '', true),
			'selectUrl' => $this->url->link('extension/shipping/novaposhta/select', '', true)
		);
		$output .= $this->load->view('extension/shipping/novaposhta_checkout', array(
			'np_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
		));
	}

	public function onAddOrder(&$route, &$args, &$order_id) {
		$order_id = (int)$order_id;
		if ($order_id < 1 || empty($this->session->data['novaposhta']) || !is_array($this->session->data['novaposhta'])) {
			return;
		}
		$np = $this->session->data['novaposhta'];
		$this->db->query("UPDATE `" . DB_PREFIX . "order` SET shipping_city = '" . $this->db->escape($np['city']) . "', shipping_address_1 = '" . $this->db->escape($np['point']) . "', shipping_code = '" . $this->db->escape('novaposhta.' . $np['service']) . "' WHERE order_id = '" . $order_id . "'");
	}

	public function cron() {
		$this->load->library('novaposhta');
		$state = $this->novaposhta->stateFromConfig();
		$key = isset($state['connection']['apiKey']) ? (string)$state['connection']['apiKey'] : '';
		$expect = substr(md5($key . 'np'), 0, 16);
		if ($key === '' || !isset($this->request->get['key']) || !hash_equals($expect, (string)$this->request->get['key'])) {
			$this->response->setOutput('denied');
			return;
		}
		if (empty($state['tracking']['enabled'])) {
			$this->response->setOutput('off');
			return;
		}
		$query = $this->db->query("SELECT documents, scansheets FROM `" . DB_PREFIX . "novaposhta_bundle` WHERE bundle_id = 1");
		if (!$query->num_rows) {
			$this->response->setOutput('empty');
			return;
		}
		$waybills = json_decode($query->row['documents'], true) ?: array();
		$docs = array();
		foreach ($waybills as $wb) {
			$num = preg_replace('/\D/', '', isset($wb['ttn']) ? $wb['ttn'] : '');
			if (strlen($num) === 14) {
				$docs[] = array('DocumentNumber' => $num, 'Phone' => preg_replace('/\D/', '', isset($wb['phone']) ? $wb['phone'] : ''));
			}
		}
		if (!$docs) {
			$this->response->setOutput('no-ttn');
			return;
		}
		$res = $this->novaposhta->call('TrackingDocument', 'getStatusDocuments', array('Documents' => array_slice($docs, 0, 100)), $key, 30);
		if (empty($res['success']) || empty($res['data'])) {
			$this->response->setOutput('api-error');
			return;
		}
		$by_number = array();
		foreach ($res['data'] as $row) {
			if (!empty($row['Number'])) {
				$by_number[$row['Number']] = $row;
			}
		}
		foreach ($waybills as &$wb) {
			$num = preg_replace('/\D/', '', isset($wb['ttn']) ? $wb['ttn'] : '');
			if (empty($by_number[$num]['Status'])) {
				continue;
			}
			$row = $by_number[$num];
			$wb['statusText'] = $row['Status'];
			$wb['statusCode'] = (string)$row['StatusCode'];
			$code = (int)$row['StatusCode'];
			if ($code === 1) $wb['statusCategory'] = 'new';
			elseif (in_array($code, array(4, 5, 6, 41, 101), true)) $wb['statusCategory'] = 'transit';
			elseif ($code === 7 || $code === 8) $wb['statusCategory'] = 'arrived';
			elseif ($code === 9 || $code === 10 || $code === 11) $wb['statusCategory'] = 'delivered';
			elseif ($code >= 102 && $code <= 106) $wb['statusCategory'] = 'returned';
		}
		unset($wb);
		$this->db->query("UPDATE `" . DB_PREFIX . "novaposhta_bundle` SET documents = '" . $this->db->escape(json_encode($waybills, JSON_UNESCAPED_UNICODE)) . "', updated_at = NOW() WHERE bundle_id = 1");
		$this->response->setOutput('ok');
	}

	private function json($data) {
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data, JSON_UNESCAPED_UNICODE));
	}
}
