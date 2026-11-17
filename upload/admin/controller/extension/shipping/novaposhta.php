<?php
require_once DIR_SYSTEM . 'library/novaposhta/service.php';
require_once DIR_SYSTEM . 'library/novaposhta/api.php';
require_once DIR_SYSTEM . 'library/novaposhta/version.php';

class ControllerExtensionShippingNovaposhta extends Controller {
	private $error = array();

	public function index() {
		$this->load->language('extension/shipping/novaposhta');
		$this->document->setTitle($this->language->get('heading_title'));
		$this->load->model('setting/setting');

		if ($this->request->server['REQUEST_METHOD'] === 'POST' && $this->validate()) {
			$post = $this->request->post;
			$postedKey = isset($post['shipping_novaposhta_api_key']) ? trim($post['shipping_novaposhta_api_key']) : '';
			if ($postedKey === '') {
				$post['shipping_novaposhta_api_key'] = (string)$this->config->get('shipping_novaposhta_api_key');
			} else {
				$post['shipping_novaposhta_api_key'] = $postedKey;
			}
			if (isset($post['shipping_novaposhta_tariff']) && is_array($post['shipping_novaposhta_tariff'])) {
				$clean = array();
				foreach ($post['shipping_novaposhta_tariff'] as $row) {
					if (!is_array($row) || !isset($row['weight']) || $row['weight'] === '') {
						continue;
					}
					$clean[] = $row;
				}
				$post['shipping_novaposhta_tariff'] = $clean;
			}

			$this->load->model('setting/setting');
			$this->model_setting_setting->editSetting('shipping_novaposhta', $post);
			if ($post['shipping_novaposhta_api_key'] !== '') {
				$this->persistApiKey($post['shipping_novaposhta_api_key']);
			}
			$this->session->data['success'] = $this->language->get('text_success');
			$this->response->redirect($this->url->link('extension/shipping/novaposhta', 'user_token=' . $this->session->data['user_token'], true));
		}

		$service = new NovaposhtaService($this->registry);
		$service->ensureMenuEvent();

		$data = $this->language->all();
		$data['np_version'] = NovaposhtaVersion::NUMBER;
		$data['changelog'] = array();
		$log = $this->language->get('changelog');
		if (is_array($log)) {
			foreach ($log as $version => $text) {
				$data['changelog'][] = array(
					'version' => (string)$version,
					'text'    => $text,
					'current' => ((string)$version === NovaposhtaVersion::NUMBER)
				);
			}
		}
		$data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
		$data['success'] = isset($this->session->data['success']) ? $this->session->data['success'] : '';
		unset($this->session->data['success']);

		$data['breadcrumbs'] = array(
			array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)),
			array('text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=shipping', true)),
			array('text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/shipping/novaposhta', 'user_token=' . $this->session->data['user_token'], true))
		);

		$data['action'] = $this->url->link('extension/shipping/novaposhta', 'user_token=' . $this->session->data['user_token'], true);
		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=shipping', true);
		$data['user_token'] = $this->session->data['user_token'];
		$data['cn_link'] = $this->url->link('extension/shipping/novaposhta_cn', 'user_token=' . $this->session->data['user_token'], true);

		$fields = array(
			'shipping_novaposhta_status',
			'shipping_novaposhta_sort_order',
			'shipping_novaposhta_api_key',
			'shipping_novaposhta_country_id',
			'shipping_novaposhta_sender_ref',
			'shipping_novaposhta_sender_contact',
			'shipping_novaposhta_sender_phone',
			'shipping_novaposhta_sender_city',
			'shipping_novaposhta_sender_address',
			'shipping_novaposhta_sender_type',
			'shipping_novaposhta_cargo_description',
			'shipping_novaposhta_default_weight',
			'shipping_novaposhta_payer',
			'shipping_novaposhta_payment',
			'shipping_novaposhta_cron_key',
			'shipping_novaposhta_cod',
			'shipping_novaposhta_method',
			'shipping_novaposhta_tariff',
			'shipping_novaposhta_status_map',
			'shipping_novaposhta_notify',
			'shipping_novaposhta_cod_status',
			'shipping_novaposhta_sms_url',
			'shipping_novaposhta_senders',
			'shipping_novaposhta_alert_email',
			'shipping_novaposhta_color',
			'shipping_novaposhta_radius',
			'shipping_novaposhta_label_department',
			'shipping_novaposhta_label_poshtomat',
			'shipping_novaposhta_label_doors',
			'shipping_novaposhta_field_city',
			'shipping_novaposhta_field_warehouse',
			'shipping_novaposhta_field_street',
			'shipping_novaposhta_field_building',
			'shipping_novaposhta_field_flat',
			'shipping_novaposhta_compact'
		);

		foreach ($fields as $field) {
			if (isset($this->request->post[$field])) {
				$data[$field] = $this->request->post[$field];
			} else {
				$data[$field] = $this->config->get($field);
			}
		}

		if (!is_array($data['shipping_novaposhta_method']) || !$data['shipping_novaposhta_method']) {
			$data['shipping_novaposhta_method'] = array(
				'department' => array('status' => 1, 'api' => 1, 'filter_weight' => 1, 'filter_size' => 1),
				'poshtomat'  => array('status' => 1, 'api' => 1, 'filter_weight' => 1, 'filter_size' => 1),
				'doors'      => array('status' => 0, 'api' => 1)
			);
		}
		if (!is_array($data['shipping_novaposhta_tariff']) || !$data['shipping_novaposhta_tariff']) {
			$data['shipping_novaposhta_tariff'] = array(
				array('weight' => '0.5', 'warehouse' => '60', 'city' => '70', 'region' => '80', 'ukraine' => '90', 'doors' => '40', 'doors_pickup' => '0', 'locker' => '0'),
				array('weight' => '2', 'warehouse' => '70', 'city' => '80', 'region' => '90', 'ukraine' => '110', 'doors' => '40', 'doors_pickup' => '0', 'locker' => '0'),
				array('weight' => '10', 'warehouse' => '100', 'city' => '110', 'region' => '130', 'ukraine' => '150', 'doors' => '50', 'doors_pickup' => '0', 'locker' => '10'),
				array('weight' => '30', 'warehouse' => '160', 'city' => '180', 'region' => '210', 'ukraine' => '250', 'doors' => '60', 'doors_pickup' => '0', 'locker' => '10')
			);
		}
		if (!is_array($data['shipping_novaposhta_cod'])) {
			$data['shipping_novaposhta_cod'] = array();
		}
		if (!is_array($data['shipping_novaposhta_status_map'])) {
			$data['shipping_novaposhta_status_map'] = array();
		}
		if (!is_array($data['shipping_novaposhta_senders'])) {
			$data['shipping_novaposhta_senders'] = array();
		}
		if (!$data['shipping_novaposhta_cron_key']) {
			try {
				$data['shipping_novaposhta_cron_key'] = bin2hex(random_bytes(16));
			} catch (Exception $e) {
				$data['shipping_novaposhta_cron_key'] = md5(uniqid('np', true));
			}
		}
		if (!$data['shipping_novaposhta_default_weight']) {
			$data['shipping_novaposhta_default_weight'] = '0.5';
		}
		if (!$data['shipping_novaposhta_cargo_description']) {
			$data['shipping_novaposhta_cargo_description'] = 'Замовлення {order_id}';
		}
		if (!$data['shipping_novaposhta_payer']) {
			$data['shipping_novaposhta_payer'] = 'Recipient';
		}
		if (!$data['shipping_novaposhta_payment']) {
			$data['shipping_novaposhta_payment'] = 'Cash';
		}
		if (!$data['shipping_novaposhta_sender_type']) {
			$data['shipping_novaposhta_sender_type'] = 'Warehouse';
		}

		$this->load->model('localisation/country');
		$data['countries'] = $this->model_localisation_country->getCountries();

		$this->load->model('localisation/geo_zone');
		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

		$this->load->model('localisation/tax_class');
		$data['tax_classes'] = $this->model_localisation_tax_class->getTaxClasses();

		$this->load->model('localisation/order_status');
		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		$this->load->model('setting/extension');
		$data['payments'] = array();
		foreach ($this->model_setting_extension->getInstalled('payment') as $code) {
			$data['payments'][] = array(
				'code' => $code,
				'name' => $code
			);
		}

		$service = new NovaposhtaService($this->registry);
		$data['counts'] = $service->counts();
		$data['logs'] = $service->recentLogs();
		$data['custom_fields'] = array();
		$this->load->model('customer/custom_field');
		$loaded = array();
		try {
			$loaded = $this->model_customer_custom_field->getCustomFields();
		} catch (Throwable $e) {
			$loaded = array();
		}
		$locMap = array(
			'address'   => $this->language->get('text_loc_address'),
			'account'   => $this->language->get('text_loc_account'),
			'affiliate' => $this->language->get('text_loc_affiliate')
		);
		if (is_array($loaded)) {
			foreach ($loaded as $field) {
				if (empty($field['custom_field_id'])) {
					continue;
				}
				$loc = isset($field['location']) ? $field['location'] : '';
				$suffix = isset($locMap[$loc]) ? (' · ' . $locMap[$loc]) : '';
				$data['custom_fields'][] = array(
					'id'    => $field['custom_field_id'],
					'name'  => $field['name'],
					'label' => $field['name'] . $suffix
				);
			}
		}
		$store = $this->config->get('config_secure') ? HTTPS_CATALOG : HTTP_CATALOG;
		$data['cron_track'] = $store . 'index.php?route=extension/module/novaposhta/cron&task=track&key=' . $data['shipping_novaposhta_cron_key'];
		$data['cron_queue'] = $store . 'index.php?route=extension/module/novaposhta/cron&task=queue&key=' . $data['shipping_novaposhta_cron_key'];
		$data['cron_sync'] = $store . 'index.php?route=extension/module/novaposhta/cron&task=warehouses&page=1&key=' . $data['shipping_novaposhta_cron_key'];

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/shipping/novaposhta', $data));
	}

	public function install() {
		$service = new NovaposhtaService($this->registry);
		$service->install();
		$service->ensureMenuEvent();

		$this->load->model('setting/event');
		$this->model_setting_event->deleteEventByCode('novaposhta_header');
		$this->model_setting_event->deleteEventByCode('novaposhta_header_after');
		$this->model_setting_event->deleteEventByCode('novaposhta_add_order');
		$this->model_setting_event->deleteEventByCode('novaposhta_order_info');

		$this->model_setting_event->addEvent('novaposhta_header', 'catalog/controller/common/header/before', 'extension/module/novaposhta/eventHeader');
		$this->model_setting_event->addEvent('novaposhta_header_after', 'catalog/view/common/header/after', 'extension/module/novaposhta/eventHeaderAfter');
		$this->model_setting_event->addEvent('novaposhta_add_order', 'catalog/model/checkout/order/addOrder/after', 'extension/module/novaposhta/eventAddOrder');
		$this->model_setting_event->addEvent('novaposhta_order_info', 'admin/view/sale/order_info/after', 'extension/shipping/novaposhta/eventOrderInfo');

		$this->load->model('user/user_group');
		$this->model_user_user_group->addPermission($this->user->getGroupId(), 'access', 'extension/shipping/novaposhta');
		$this->model_user_user_group->addPermission($this->user->getGroupId(), 'modify', 'extension/shipping/novaposhta');
	}

	public function uninstall() {
		$service = new NovaposhtaService($this->registry);
		$service->uninstall();

		$this->load->model('setting/event');
		$this->model_setting_event->deleteEventByCode('novaposhta_header');
		$this->model_setting_event->deleteEventByCode('novaposhta_header_after');
		$this->model_setting_event->deleteEventByCode('novaposhta_add_order');
		$this->model_setting_event->deleteEventByCode('novaposhta_order_info');
		$this->model_setting_event->deleteEventByCode('novaposhta_menu');
		$this->model_setting_event->deleteEventByCode('novaposhta_account');
	}

	public function sync() {
		$this->load->language('extension/shipping/novaposhta');
		if (!$this->user->hasPermission('modify', 'extension/shipping/novaposhta')) {
			$this->json(array('error' => $this->language->get('error_permission')));
			return;
		}

		if (isset($this->request->post['key'])) {
			$this->persistApiKey($this->request->post['key']);
		}

		$job = isset($this->request->get['job']) ? $this->request->get['job'] : '';
		$page = isset($this->request->get['page']) ? (int)$this->request->get['page'] : 1;
		$service = new NovaposhtaService($this->registry);
		$type = isset($this->request->get['type']) ? (string)$this->request->get['type'] : '';
		$result = $service->syncPage($job, $page, $type);
		$result['counts'] = $service->counts();
		$this->json($result);
	}

	public function apiCheck() {
		$this->load->language('extension/shipping/novaposhta');
		if (!$this->user->hasPermission('modify', 'extension/shipping/novaposhta')) {
			$this->json(array('error' => $this->language->get('error_permission')));
			return;
		}

		$key = isset($this->request->post['key']) ? trim($this->request->post['key']) : (string)$this->config->get('shipping_novaposhta_api_key');
		if ($key !== '' && empty($this->request->post['probe'])) {
			$this->persistApiKey($key);
		}
		$api = new NovaposhtaApi($key);
		$rows = $api->counterparties();
		if ($rows === false) {
			$this->json(array('error' => $api->getLastError()));
			return;
		}

		$out = array();
		foreach ($rows as $row) {
			if (empty($row['Ref'])) {
				continue;
			}
			$out[] = array(
				'ref'  => $row['Ref'],
				'name' => isset($row['Description']) ? $row['Description'] : $row['Ref']
			);
		}

		$this->json(array('senders' => $out));
	}

	public function senderData() {
		$this->load->language('extension/shipping/novaposhta');
		if (!$this->user->hasPermission('access', 'extension/shipping/novaposhta')) {
			$this->json(array('error' => $this->language->get('error_permission')));
			return;
		}

		$ref = isset($this->request->get['ref']) ? $this->request->get['ref'] : '';
		$key = isset($this->request->post['key']) ? trim($this->request->post['key']) : '';
		if ($key === '' && isset($this->request->get['key'])) {
			$key = trim($this->request->get['key']);
		}
		if ($key === '') {
			$key = (string)$this->config->get('shipping_novaposhta_api_key');
		}
		$api = new NovaposhtaApi($key);
		$contacts = $api->contacts($ref);
		$addresses = $api->addresses($ref);

		if ($contacts === false && $addresses === false) {
			$this->json(array('error' => $api->getLastError()));
			return;
		}

		$contactOut = array();
		if (is_array($contacts)) {
			foreach ($contacts as $row) {
				$phone = '';
				if (!empty($row['Phones'])) {
					$phone = $row['Phones'];
				} elseif (!empty($row['Phone'])) {
					$phone = $row['Phone'];
				}
				$contactOut[] = array(
					'ref'   => isset($row['Ref']) ? $row['Ref'] : '',
					'name'  => isset($row['Description']) ? $row['Description'] : '',
					'phone' => $phone
				);
			}
		}

		$addressOut = array();
		if (is_array($addresses)) {
			foreach ($addresses as $row) {
				$addressOut[] = array(
					'ref'      => isset($row['Ref']) ? $row['Ref'] : '',
					'name'     => isset($row['Description']) ? $row['Description'] : '',
					'city_ref' => isset($row['CityRef']) ? $row['CityRef'] : ''
				);
			}
		}

		$this->json(array('contacts' => $contactOut, 'addresses' => $addressOut));
	}

	public function eventOrderInfo(&$route, &$data, &$output) {
		if (empty($this->request->get['order_id'])) {
			return;
		}

		$this->load->model('sale/order');
		$order = $this->model_sale_order->getOrder((int)$this->request->get['order_id']);
		if (!$order || strpos((string)$order['shipping_code'], 'novaposhta.') !== 0) {
			return;
		}

		$this->load->language('extension/shipping/novaposhta');
		$service = new NovaposhtaService($this->registry);
		$row = $service->getOrderRow((int)$order['order_id']);

		$cargo = $service->orderCargo((int)$order['order_id']);
		$template = (string)$this->config->get('shipping_novaposhta_cargo_description');
		if ($template === '') {
			$template = 'Замовлення {order_id}';
		}
		$description = $service->applyDescription($template, $order, $cargo);
		if (($description === '' || $description === ('Замовлення ' . $order['order_id'])) && $cargo['description'] !== '') {
			$description = $cargo['description'];
		}
		$view = array(
			'order_id'    => (int)$order['order_id'],
			'user_token'  => $this->session->data['user_token'],
			'row'         => $row,
			'telephone'   => $order['telephone'],
			'total'       => round($service->toUah($order['total'])),
			'weight'      => $cargo['weight'],
			'description' => $description,
			'places'      => $cargo['places'],
			'payer'       => $this->config->get('shipping_novaposhta_payer') ? $this->config->get('shipping_novaposhta_payer') : 'Recipient',
			'payment'     => $this->config->get('shipping_novaposhta_payment') ? $this->config->get('shipping_novaposhta_payment') : 'Cash',
			'cod'         => 0,
			'heading'     => $this->language->get('text_cn'),
			'entry_weight'=> $this->language->get('entry_weight'),
			'entry_cost'  => $this->language->get('entry_declared'),
			'entry_seats' => $this->language->get('entry_seats'),
			'entry_description' => $this->language->get('entry_description'),
			'entry_payer' => $this->language->get('entry_payer'),
			'entry_payment' => $this->language->get('entry_payment'),
			'entry_phone' => $this->language->get('entry_phone'),
			'button_create' => $this->language->get('button_create'),
			'button_delete' => $this->language->get('button_delete'),
			'button_pdf'    => $this->language->get('button_pdf'),
			'button_marking'=> $this->language->get('button_marking'),
			'text_cod'      => $this->language->get('text_cod'),
			'text_missing_ref' => $this->language->get('text_missing_ref'),
			'senders'     => $service->senderChoices(),
			'entry_sender'=> $this->language->get('entry_sender'),
			'entry_backward' => $this->language->get('entry_backward'),
			'entry_places'=> $this->language->get('entry_places'),
			'np_version'  => NovaposhtaVersion::NUMBER
		);

		$codPayments = $this->config->get('shipping_novaposhta_cod');
		if (is_array($codPayments) && in_array($order['payment_code'], $codPayments)) {
			$view['cod'] = $view['total'];
		}

		if (!empty($row['cn_ref']) && $this->config->get('shipping_novaposhta_api_key')) {
			$view['pdf'] = $this->url->link('extension/shipping/novaposhta_cn/file', 'user_token=' . $this->session->data['user_token'] . '&order_id=' . (int)$order['order_id'] . '&kind=document', true);
			$view['marking'] = $this->url->link('extension/shipping/novaposhta_cn/file', 'user_token=' . $this->session->data['user_token'] . '&order_id=' . (int)$order['order_id'] . '&kind=marking', true);
		} else {
			$view['pdf'] = '';
			$view['marking'] = '';
		}

		$view = array_merge($this->language->all(), $view);
		$output .= $this->load->view('extension/shipping/novaposhta_order', $view);
	}

	public function createCn() {
		$this->load->language('extension/shipping/novaposhta');
		if (!$this->user->hasPermission('modify', 'extension/shipping/novaposhta')) {
			$this->json(array('error' => $this->language->get('error_permission')));
			return;
		}

		$this->load->model('sale/order');
		$orderId = isset($this->request->post['order_id']) ? (int)$this->request->post['order_id'] : 0;
		$order = $this->model_sale_order->getOrder($orderId);
		if (!$order) {
			$this->json(array('error' => 'Order not found'));
			return;
		}

		$service = new NovaposhtaService($this->registry);
		$row = $service->getOrderRow($orderId);
		if (!$row) {
			$this->json(array('error' => $this->language->get('text_missing_ref')));
			return;
		}

		$result = $service->createDocument($order, $row, array(
			'weight'      => isset($this->request->post['weight']) ? $this->request->post['weight'] : 0.5,
			'cost'        => isset($this->request->post['cost']) ? $this->request->post['cost'] : 0,
			'seats'       => isset($this->request->post['seats']) ? $this->request->post['seats'] : 1,
			'description' => isset($this->request->post['description']) ? $this->request->post['description'] : '',
			'payer'       => isset($this->request->post['payer']) ? $this->request->post['payer'] : 'Recipient',
			'payment'     => isset($this->request->post['payment']) ? $this->request->post['payment'] : 'Cash',
			'phone'       => isset($this->request->post['phone']) ? $this->request->post['phone'] : '',
			'cod'         => !empty($this->request->post['cod']) ? $this->request->post['cod_sum'] : 0,
			'places'      => isset($this->request->post['places']) ? $this->request->post['places'] : array(),
			'backward'    => isset($this->request->post['backward']) ? $this->request->post['backward'] : '',
			'backward_info' => isset($this->request->post['backward_info']) ? $this->request->post['backward_info'] : '',
			'sender_id'   => isset($this->request->post['sender_id']) ? $this->request->post['sender_id'] : '',
			'cargo'       => isset($this->request->post['cargo']) ? $this->request->post['cargo'] : 'Parcel',
			'edrpou'      => isset($this->request->post['edrpou']) ? $this->request->post['edrpou'] : '',
			'company'     => isset($this->request->post['company']) ? $this->request->post['company'] : ''
		));

		if (!empty($result['number'])) {
			$result['pdf'] = $this->url->link('extension/shipping/novaposhta_cn/file', 'user_token=' . $this->session->data['user_token'] . '&order_id=' . $orderId . '&kind=document', true);
			$result['marking'] = $this->url->link('extension/shipping/novaposhta_cn/file', 'user_token=' . $this->session->data['user_token'] . '&order_id=' . $orderId . '&kind=marking', true);
		}

		$this->json($result);
	}

	public function deleteCn() {
		$this->load->language('extension/shipping/novaposhta');
		if (!$this->user->hasPermission('modify', 'extension/shipping/novaposhta')) {
			$this->json(array('error' => $this->language->get('error_permission')));
			return;
		}

		$service = new NovaposhtaService($this->registry);
		$orderId = isset($this->request->post['order_id']) ? (int)$this->request->post['order_id'] : 0;
		$this->json($service->deleteDocument($orderId));
	}

	public function cities() {
		if (!$this->user->hasPermission('access', 'extension/shipping/novaposhta')) {
			$this->json(array());
			return;
		}
		$service = new NovaposhtaService($this->registry);
		$code = $this->language->get('code');
		$this->json($service->searchCities(isset($this->request->get['q']) ? $this->request->get['q'] : '', strpos($code, 'ru') === 0));
	}

	public function warehouses() {
		if (!$this->user->hasPermission('access', 'extension/shipping/novaposhta')) {
			$this->json(array());
			return;
		}
		$service = new NovaposhtaService($this->registry);
		$code = $this->language->get('code');
		$this->json($service->searchWarehouses(
			isset($this->request->get['city_ref']) ? $this->request->get['city_ref'] : '',
			isset($this->request->get['q']) ? $this->request->get['q'] : '',
			!empty($this->request->get['locker']),
			0, 0, 0, 0,
			strpos($code, 'ru') === 0
		));
	}

	public function hours() {
		if (!$this->user->hasPermission('access', 'extension/shipping/novaposhta')) {
			$this->json(array('hours' => ''));
			return;
		}
		$service = new NovaposhtaService($this->registry);
		$this->json(array('hours' => $service->warehouseHours(isset($this->request->get['ref']) ? $this->request->get['ref'] : '')));
	}

	public function savePoint() {
		$this->load->language('extension/shipping/novaposhta');
		if (!$this->user->hasPermission('modify', 'extension/shipping/novaposhta')) {
			$this->json(array('error' => $this->language->get('error_permission')));
			return;
		}
		$service = new NovaposhtaService($this->registry);
		$orderId = isset($this->request->post['order_id']) ? (int)$this->request->post['order_id'] : 0;
		$service->savePoint($orderId, $this->request->post);
		$this->json(array('success' => true));
	}

	public function updateCn() {
		$this->load->language('extension/shipping/novaposhta');
		if (!$this->user->hasPermission('modify', 'extension/shipping/novaposhta')) {
			$this->json(array('error' => $this->language->get('error_permission')));
			return;
		}
		$service = new NovaposhtaService($this->registry);
		$orderId = isset($this->request->post['order_id']) ? (int)$this->request->post['order_id'] : 0;
		$this->json($service->updateWaybill($orderId, $this->request->post));
	}

	public function saveKey() {
		$this->load->language('extension/shipping/novaposhta');
		if (!$this->user->hasPermission('modify', 'extension/shipping/novaposhta')) {
			$this->json(array('error' => $this->language->get('error_permission')));
			return;
		}

		$key = isset($this->request->post['key']) ? trim($this->request->post['key']) : '';
		if ($key === '') {
			$this->json(array('error' => 'API key is empty'));
			return;
		}

		$this->persistApiKey($key);
		$this->json(array('success' => $this->language->get('text_key_saved')));
	}

	public function eventMenu(&$route, &$data) {
		if (!$this->user->hasPermission('access', 'extension/shipping/novaposhta')) {
			return;
		}

		$this->load->language('extension/shipping/novaposhta');
		$token = $this->session->data['user_token'];
		$data['menus'][] = array(
			'id'       => 'menu-novaposhta',
			'icon'     => 'fa-truck',
			'name'     => 'Нова пошта ' . NovaposhtaVersion::NUMBER,
			'href'     => '',
			'children' => array(
				array(
					'name'     => $this->language->get('menu_cn'),
					'href'     => $this->url->link('extension/shipping/novaposhta_cn', 'user_token=' . $token, true),
					'children' => array()
				),
				array(
					'name'     => $this->language->get('menu_settings'),
					'href'     => $this->url->link('extension/shipping/novaposhta', 'user_token=' . $token, true),
					'children' => array()
				)
			)
		);
	}

	private function persistApiKey($key) {
		$key = trim((string)$key);
		if ($key === '') {
			return;
		}

		$this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE store_id = '0' AND `key` = 'shipping_novaposhta_api_key'");
		$this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET store_id = '0', `code` = 'shipping_novaposhta', `key` = 'shipping_novaposhta_api_key', `value` = '" . $this->db->escape($key) . "', `serialized` = '0'");
		$this->config->set('shipping_novaposhta_api_key', $key);
	}

	private function validate() {
		if (!$this->user->hasPermission('modify', 'extension/shipping/novaposhta')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		return !$this->error;
	}

	private function json($data) {
		$this->response->addHeader('Content-Type: application/json; charset=utf-8');
		$this->response->setOutput(json_encode($data, JSON_UNESCAPED_UNICODE));
	}
}
