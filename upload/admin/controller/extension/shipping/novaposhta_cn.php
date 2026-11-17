<?php
require_once DIR_SYSTEM . 'library/novaposhta/service.php';
require_once DIR_SYSTEM . 'library/novaposhta/api.php';
require_once DIR_SYSTEM . 'library/novaposhta/version.php';

class ControllerExtensionShippingNovaposhtaCn extends Controller {
	public function index() {
		$this->load->language('extension/shipping/novaposhta');

		if (!$this->user->hasPermission('access', 'extension/shipping/novaposhta')) {
			return $this->response->setOutput($this->language->get('error_permission'));
		}

		$this->document->setTitle($this->language->get('heading_cn') . ' ' . NovaposhtaVersion::NUMBER);
		$service = new NovaposhtaService($this->registry);
		$service->install();
		$service->ensureMenuEvent();

		$filter = array(
			'order_id' => isset($this->request->get['filter_order_id']) ? $this->request->get['filter_order_id'] : '',
			'cn'       => isset($this->request->get['filter_cn']) ? $this->request->get['filter_cn'] : '',
			'has_cn'   => isset($this->request->get['filter_has_cn']) ? (string)$this->request->get['filter_has_cn'] : '',
			'group'    => isset($this->request->get['filter_group']) ? (string)$this->request->get['filter_group'] : ''
		);
		$page = isset($this->request->get['page']) ? max(1, (int)$this->request->get['page']) : 1;
		$limit = 25;
		$result = $service->waybills($filter, ($page - 1) * $limit, $limit);
		$token = $this->session->data['user_token'];
		$key = (string)$this->config->get('shipping_novaposhta_api_key');

		$data = $this->language->all();
		$data['np_version'] = NovaposhtaVersion::NUMBER;
		$data['rows'] = array();
		foreach ($result['rows'] as $row) {
			$data['rows'][] = array(
				'order_id' => $row['order_id'],
				'ref'      => $row['cn_ref'],
				'customer' => trim($row['firstname'] . ' ' . $row['lastname']),
				'phone'    => $row['telephone'],
				'city'     => $row['city_name'],
				'division' => $row['division_name'] !== '' ? $row['division_name'] : trim($row['street_name'] . ' ' . $row['building']),
				'method'   => $row['method'],
				'cn'       => $row['cn_number'],
				'status'   => $row['status_text'],
				'code'     => (int)$row['status_code'],
				'date'     => $row['date_added'] ? date('d.m.Y H:i', strtotime($row['date_added'])) : '',
				'order'    => $this->url->link('sale/order/info', 'user_token=' . $token . '&order_id=' . (int)$row['order_id'], true),
				'pdf'      => $row['cn_ref'] ? $this->url->link('extension/shipping/novaposhta_cn/file', 'user_token=' . $token . '&order_id=' . (int)$row['order_id'] . '&kind=document', true) : '',
				'marking'  => $row['cn_ref'] ? $this->url->link('extension/shipping/novaposhta_cn/file', 'user_token=' . $token . '&order_id=' . (int)$row['order_id'] . '&kind=marking', true) : ''
			);
		}

		$data['senders'] = $service->senderChoices();

		$data['filter_order_id'] = $filter['order_id'];
		$data['filter_cn'] = $filter['cn'];
		$data['filter_has_cn'] = $filter['has_cn'];
		$data['filter_group'] = $filter['group'];
		$data['queue'] = $service->queueCount();
		$data['sheets'] = array();
		foreach ($service->sheets() as $sheet) {
			$data['sheets'][] = array(
				'number' => $sheet['number'],
				'date'   => $sheet['sheet_date'],
				'pdf'    => $sheet['ref'] ? $this->url->link('extension/shipping/novaposhta_cn/file', 'user_token=' . $token . '&sheet=' . $sheet['ref'], true) : ''
			);
		}
		$data['intervals'] = $service->timeIntervals();
		$data['retry'] = $this->url->link('extension/shipping/novaposhta_cn/retry', 'user_token=' . $token, true);
		$data['user_token'] = $token;
		$data['refresh'] = $this->url->link('extension/shipping/novaposhta_cn/refresh', 'user_token=' . $token, true);
		$data['delete'] = $this->url->link('extension/shipping/novaposhta_cn/delete', 'user_token=' . $token, true);
		$data['create'] = $this->url->link('extension/shipping/novaposhta_cn/create', 'user_token=' . $token, true);
		$data['prints'] = $this->url->link('extension/shipping/novaposhta_cn/prints', 'user_token=' . $token, true);
		$data['registry'] = $this->url->link('extension/shipping/novaposhta_cn/registry', 'user_token=' . $token, true);
		$data['courier'] = $this->url->link('extension/shipping/novaposhta_cn/courier', 'user_token=' . $token, true);
		$data['settings'] = $this->url->link('extension/shipping/novaposhta', 'user_token=' . $token, true);
		$data['success'] = isset($this->session->data['success']) ? $this->session->data['success'] : '';
		unset($this->session->data['success']);
		$data['error_warning'] = isset($this->session->data['error']) ? $this->session->data['error'] : '';
		unset($this->session->data['error']);

		$pagination = new Pagination();
		$pagination->total = $result['total'];
		$pagination->page = $page;
		$pagination->limit = $limit;
		$pagination->url = $this->url->link('extension/shipping/novaposhta_cn', 'user_token=' . $token . $this->filterQuery($filter) . '&page={page}', true);
		$data['pagination'] = $pagination->render();
		$data['results'] = $result['total'];

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');
		$this->response->setOutput($this->load->view('extension/shipping/novaposhta_cn', $data));
	}

	public function create() {
		if (!$this->allowed()) {
			return;
		}
		$service = new NovaposhtaService($this->registry);
		$ids = isset($this->request->post['selected']) ? $this->request->post['selected'] : array();
		$result = $service->createMany($ids, isset($this->request->post['sender_id']) ? $this->request->post['sender_id'] : '');
		$text = '';
		if (!empty($result['created'])) {
			$text .= implode(', ', $result['created']);
		}
		if (!empty($result['errors'])) {
			$this->session->data['error'] = implode('; ', $result['errors']);
		}
		if ($text !== '') {
			$this->session->data['success'] = $text;
		}
		$this->back();
	}

	public function prints() {
		if (!$this->allowed()) {
			return;
		}
		$service = new NovaposhtaService($this->registry);
		$ids = isset($this->request->post['selected']) ? $this->request->post['selected'] : array();
		$kind = isset($this->request->post['kind']) && $this->request->post['kind'] === 'marking' ? 'marking' : 'document';
		$links = $service->printLinks($ids, $kind);
		if (!$links) {
			$this->session->data['error'] = 'No TTN';
			$this->back();
			return;
		}
		$this->stream($service->fetchPdf($links[0]), $kind === 'marking' ? 'marking.pdf' : 'ttn.pdf');
	}

	public function registry() {
		if (!$this->allowed()) {
			return;
		}
		$service = new NovaposhtaService($this->registry);
		$ids = isset($this->request->post['selected']) ? $this->request->post['selected'] : array();
		$date = isset($this->request->post['date']) ? $this->request->post['date'] : '';
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			$date = date('d.m.Y', strtotime($date));
		} else {
			$date = date('d.m.Y');
		}
		$result = $service->makeScanSheet($service->refsByOrders($ids), $date);
		if (!empty($result['error'])) {
			$this->session->data['error'] = $result['error'];
			$this->back();
			return;
		}
		if (!empty($result['pdf'])) {
			$this->stream($service->fetchPdf($result['pdf']), 'registry.pdf');
			return;
		}
		$this->session->data['success'] = $result['number'];
		$this->back();
	}

	public function courier() {
		if (!$this->allowed()) {
			return;
		}
		$service = new NovaposhtaService($this->registry);
		$date = isset($this->request->post['date']) ? $this->request->post['date'] : '';
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			$date = date('d.m.Y', strtotime($date));
		} else {
			$date = date('d.m.Y', strtotime('+1 day'));
		}
		$result = $service->callCourier(
			$date,
			isset($this->request->post['interval']) ? $this->request->post['interval'] : 'CityPickingTimeInterval1',
			isset($this->request->post['weight']) ? $this->request->post['weight'] : 1,
			isset($this->request->post['sender_id']) ? $this->request->post['sender_id'] : ''
		);
		if (!empty($result['error'])) {
			$this->session->data['error'] = $result['error'];
		} else {
			$this->session->data['success'] = $result['number'];
		}
		$this->back();
	}

	private function allowed() {
		$this->load->language('extension/shipping/novaposhta');
		if ($this->user->hasPermission('modify', 'extension/shipping/novaposhta')) {
			return true;
		}
		$this->session->data['error'] = $this->language->get('error_permission');
		$this->back();
		return false;
	}

	private function back() {
		$this->response->redirect($this->url->link('extension/shipping/novaposhta_cn', 'user_token=' . $this->session->data['user_token'], true));
	}

	public function retry() {
		if (!$this->allowed()) {
			return;
		}
		$service = new NovaposhtaService($this->registry);
		$result = $service->processQueue(10);
		$this->session->data['success'] = (int)$result['done'] . ' / ' . (int)$result['failed'];
		$this->back();
	}

	public function file() {
		if (!$this->user->hasPermission('access', 'extension/shipping/novaposhta')) {
			return;
		}
		$service = new NovaposhtaService($this->registry);
		$kind = isset($this->request->get['kind']) && $this->request->get['kind'] === 'marking' ? 'marking' : 'document';
		if (!empty($this->request->get['sheet'])) {
			$url = NovaposhtaApi::printUrl($this->request->get['sheet'], 'scan', $this->config->get('shipping_novaposhta_api_key'));
			$this->stream($service->fetchPdf($url), 'registry.pdf');
			return;
		}
		$orderId = isset($this->request->get['order_id']) ? (int)$this->request->get['order_id'] : 0;
		$links = $service->printLinks(array($orderId), $kind);
		if (!$links) {
			return;
		}
		$this->stream($service->fetchPdf($links[0]), $kind . '.pdf');
	}

	private function stream($body, $name) {
		if (!$body) {
			$this->session->data['error'] = 'PDF';
			$this->back();
			return;
		}
		$this->response->addHeader('Content-Type: application/pdf');
		$this->response->addHeader('Content-Disposition: inline; filename="' . $name . '"');
		$this->response->setOutput($body);
	}

	public function refresh() {
		$this->load->language('extension/shipping/novaposhta');
		if (!$this->user->hasPermission('modify', 'extension/shipping/novaposhta')) {
			$this->session->data['error'] = $this->language->get('error_permission');
		} else {
			$service = new NovaposhtaService($this->registry);
			$result = $service->trackBatch(100);
			$this->session->data['success'] = $this->language->get('text_refreshed') . ' ' . (int)$result['updated'];
		}
		$this->response->redirect($this->url->link('extension/shipping/novaposhta_cn', 'user_token=' . $this->session->data['user_token'], true));
	}

	public function delete() {
		$this->load->language('extension/shipping/novaposhta');
		if (!$this->user->hasPermission('modify', 'extension/shipping/novaposhta')) {
			$this->session->data['error'] = $this->language->get('error_permission');
		} else {
			$service = new NovaposhtaService($this->registry);
			$orderId = isset($this->request->post['order_id']) ? (int)$this->request->post['order_id'] : 0;
			$result = $service->deleteDocument($orderId);
			if (!empty($result['error'])) {
				$this->session->data['error'] = $result['error'];
			} else {
				$this->session->data['success'] = $this->language->get('text_cn_deleted');
			}
		}
		$this->response->redirect($this->url->link('extension/shipping/novaposhta_cn', 'user_token=' . $this->session->data['user_token'], true));
	}

	private function filterQuery($filter) {
		$url = '';
		if ($filter['order_id'] !== '') {
			$url .= '&filter_order_id=' . urlencode($filter['order_id']);
		}
		if ($filter['cn'] !== '') {
			$url .= '&filter_cn=' . urlencode($filter['cn']);
		}
		if ($filter['has_cn'] !== '') {
			$url .= '&filter_has_cn=' . urlencode($filter['has_cn']);
		}
		if ($filter['group'] !== '') {
			$url .= '&filter_group=' . urlencode($filter['group']);
		}
		return $url;
	}
}
