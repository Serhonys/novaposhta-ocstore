<?php
class ControllerExtensionShippingNovaposhta extends Controller {
	public function index() {
		$this->screen('settings');
	}

	public function dispatch() {
		$this->screen('dispatch');
	}

	public function install() {
		$this->ensureTable();
		$this->load->model('setting/event');
		$this->model_setting_event->deleteEventByCode('novaposhta_checkout_page');
		$this->model_setting_event->deleteEventByCode('novaposhta_shipping_view');
		$this->model_setting_event->deleteEventByCode('novaposhta_add_order');
		$this->model_setting_event->addEvent('novaposhta_checkout_page', 'catalog/view/checkout/checkout/after', 'extension/shipping/novaposhta/injectCheckout', 1, 0);
		$this->model_setting_event->addEvent('novaposhta_shipping_view', 'catalog/view/checkout/shipping_method/after', 'extension/shipping/novaposhta/inject', 1, 0);
		$this->model_setting_event->addEvent('novaposhta_add_order', 'catalog/model/checkout/order/addOrder/after', 'extension/shipping/novaposhta/onAddOrder', 1, 0);
	}

	public function uninstall() {
		$this->load->model('setting/event');
		$this->model_setting_event->deleteEventByCode('novaposhta_checkout_page');
		$this->model_setting_event->deleteEventByCode('novaposhta_shipping_view');
		$this->model_setting_event->deleteEventByCode('novaposhta_add_order');
	}

	public function save() {
		$this->response->addHeader('Content-Type: application/json');
		if (!$this->user->hasPermission('modify', 'extension/shipping/novaposhta')) {
			$this->response->setOutput(json_encode(array('ok' => false, 'error' => 'permission')));
			return;
		}
		$payload = json_decode(file_get_contents('php://input'), true);
		if (empty($payload['state']) || !is_array($payload['state'])) {
			$this->response->setOutput(json_encode(array('ok' => false)));
			return;
		}
		$state = $payload['state'];
		if (isset($state['connection']['apiKey'])) {
			$state['connection']['apiKey'] = substr((string)$state['connection']['apiKey'], 0, 64);
		}
		$this->load->model('setting/setting');
		$this->model_setting_setting->editSetting('shipping_novaposhta', array(
			'shipping_novaposhta_status' => !empty($state['connection']['enabled']) ? 1 : 0,
			'shipping_novaposhta_sort_order' => 1,
			'shipping_novaposhta_geo_zone_id' => 0,
			'shipping_novaposhta_json' => json_encode($state, JSON_UNESCAPED_UNICODE)
		));
		$this->response->setOutput(json_encode(array('ok' => true)));
	}

	public function docs() {
		$this->response->addHeader('Content-Type: application/json');
		if (!$this->user->hasPermission('modify', 'extension/shipping/novaposhta')) {
			$this->response->setOutput(json_encode(array('ok' => false)));
			return;
		}
		$this->ensureTable();
		$payload = json_decode(file_get_contents('php://input'), true);
		$waybills = (isset($payload['waybills']) && is_array($payload['waybills'])) ? $payload['waybills'] : array();
		$sheets = (isset($payload['scanSheets']) && is_array($payload['scanSheets'])) ? $payload['scanSheets'] : array();
		$documents = json_encode($waybills, JSON_UNESCAPED_UNICODE);
		if (strlen($documents) > 1500000) {
			$this->response->setOutput(json_encode(array('ok' => false, 'error' => 'too_large')));
			return;
		}
		$this->db->query("REPLACE INTO `" . DB_PREFIX . "novaposhta_bundle` SET bundle_id = 1, documents = '" . $this->db->escape($documents) . "', scansheets = '" . $this->db->escape(json_encode($sheets, JSON_UNESCAPED_UNICODE)) . "', updated_at = NOW()");
		$this->response->setOutput(json_encode(array('ok' => true)));
	}

	public function api() {
		$this->response->addHeader('Content-Type: application/json');
		if (!$this->user->hasPermission('access', 'extension/shipping/novaposhta')) {
			$this->response->setOutput(json_encode(array('success' => false, 'data' => array(), 'errors' => array('Немає прав'))));
			return;
		}
		$body = json_decode(file_get_contents('php://input'), true);
		if (!is_array($body)) {
			$this->response->setOutput(json_encode(array('success' => false, 'data' => array(), 'errors' => array('Некорректный JSON'))));
			return;
		}
		$this->load->library('novaposhta');
		$state = $this->novaposhta->stateFromConfig();
		$key = isset($body['apiKey']) ? (string)$body['apiKey'] : '';
		if ($key === '' || strpos($key, '•') !== false) {
			$key = isset($state['connection']['apiKey']) ? (string)$state['connection']['apiKey'] : '';
		}
		$timeout = isset($state['connection']['timeoutSec']) ? (int)$state['connection']['timeoutSec'] : 30;
		$result = $this->novaposhta->call(
			isset($body['modelName']) ? $body['modelName'] : '',
			isset($body['calledMethod']) ? $body['calledMethod'] : '',
			isset($body['methodProperties']) && is_array($body['methodProperties']) ? $body['methodProperties'] : array(),
			$key,
			$timeout
		);
		$this->response->setOutput(json_encode($result, JSON_UNESCAPED_UNICODE));
	}

	private function screen($which) {
		$this->load->language('extension/shipping/novaposhta');
		$this->document->setTitle($this->language->get('heading_title'));

		if (!$this->user->hasPermission('access', 'extension/shipping/novaposhta')) {
			$this->response->redirect($this->url->link('error/permission', 'user_token=' . $this->session->data['user_token'], true));
			return;
		}

		$token = $this->session->data['user_token'];
		$this->load->library('novaposhta');
		$state = $this->novaposhta->stateFromConfig();
		$stored = $this->config->get('shipping_novaposhta_json');
		list($waybills, $sheets, $ready) = $this->documentsForBoot();

		$data['boot'] = json_encode(array(
			'mode' => 'opencart',
			'state' => $stored ? $state : null,
			'apiUrl' => html_entity_decode($this->url->link('extension/shipping/novaposhta/api', 'user_token=' . $token, true)),
			'saveUrl' => html_entity_decode($this->url->link('extension/shipping/novaposhta/save', 'user_token=' . $token, true)),
			'docsUrl' => html_entity_decode($this->url->link('extension/shipping/novaposhta/docs', 'user_token=' . $token, true)),
			'links' => array(
				'/' => html_entity_decode($this->url->link('extension/shipping/novaposhta', 'user_token=' . $token, true)),
				'/dispatch' => html_entity_decode($this->url->link('extension/shipping/novaposhta/dispatch', 'user_token=' . $token, true))
			),
			'waybills' => $waybills,
			'scanSheets' => $sheets,
			'readyOrders' => $ready
		), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

		$data['which'] = $which;
		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');
		$tpl = $which === 'dispatch' ? 'extension/shipping/novaposhta_dispatch' : 'extension/shipping/novaposhta';
		$this->response->setOutput($this->load->view($tpl, $data));
	}

	private function documentsForBoot() {
		$this->ensureTable();
		$waybills = array();
		$sheets = array();
		$query = $this->db->query("SELECT documents, scansheets FROM `" . DB_PREFIX . "novaposhta_bundle` WHERE bundle_id = 1");
		if ($query->num_rows) {
			$waybills = json_decode($query->row['documents'], true) ?: array();
			$sheets = json_decode($query->row['scansheets'], true) ?: array();
		}
		$known = array();
		foreach ($waybills as $wb) {
			if (!empty($wb['orderId'])) {
				$known[$wb['orderId']] = true;
			}
		}
		$ready = array();
		$orders = $this->db->query("SELECT order_id, firstname, lastname, telephone, total, shipping_city, shipping_address_1, date_added, shipping_code FROM `" . DB_PREFIX . "order` WHERE order_status_id > '0' AND shipping_code LIKE 'novaposhta%' ORDER BY order_id DESC LIMIT 80");
		foreach ($orders->rows as $order) {
			$oid = '#' . (int)$order['order_id'];
			$service = 'WarehouseWarehouse';
			$parts = explode('.', (string)$order['shipping_code']);
			if (!empty($parts[1])) {
				$service = $parts[1];
			}
			$phone = preg_replace('/\D/', '', (string)$order['telephone']);
			if (!isset($known[$oid])) {
				$waybills[] = array(
					'id' => 'order-' . (int)$order['order_id'],
					'ttn' => '',
					'ref' => '',
					'createdAt' => date('d.m.Y H:i', strtotime($order['date_added'])),
					'orderId' => $oid,
					'recipient' => trim($order['firstname'] . ' ' . $order['lastname']),
					'phone' => $phone,
					'city' => $order['shipping_city'],
					'point' => $order['shipping_address_1'],
					'pointKind' => ($service === 'WarehouseDoors' || $service === 'DoorsDoors') ? 'courier' : 'branch',
					'serviceType' => $service,
					'warehouseIndex' => '',
					'weight' => 1,
					'dimensions' => '',
					'cost' => (float)$order['total'],
					'cod' => false,
					'codAmount' => 0,
					'payer' => 'Recipient',
					'shippingCost' => 0,
					'statusCategory' => 'new',
					'statusCode' => '1',
					'statusText' => 'Заказ магазина без ТТН',
					'storageNote' => 'Создайте ЭН из этого заказа',
					'scanSheetId' => null,
					'description' => 'Замовлення ' . $oid,
					'timeline' => array(array(
						'time' => date('d.m.Y H:i', strtotime($order['date_added'])),
						'place' => $order['shipping_city'],
						'text' => 'Заказ оформлен в магазине',
						'done' => true
					))
				);
			}
			if (count($ready) < 6) {
				$ready[] = array(
					'orderId' => $oid,
					'recipient' => trim($order['firstname'] . ' ' . $order['lastname']),
					'phone' => $phone,
					'serviceType' => $service,
					'city' => $order['shipping_city'],
					'point' => $order['shipping_address_1'],
					'weight' => 1.5,
					'length' => 30,
					'width' => 20,
					'height' => 15,
					'cost' => (int)round($order['total']),
					'payer' => 'Recipient',
					'cod' => true,
					'description' => 'Замовлення ' . $oid
				);
			}
		}
		return array($waybills, $sheets, $ready);
	}

	private function ensureTable() {
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "novaposhta_bundle` (
			`bundle_id` tinyint(1) NOT NULL,
			`documents` mediumtext NOT NULL,
			`scansheets` mediumtext NOT NULL,
			`updated_at` datetime NOT NULL,
			PRIMARY KEY (`bundle_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
	}
}
