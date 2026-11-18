<?php
class ModelExtensionShippingNovaposhta extends Model {
	public function getQuote($address) {
		if (!$this->config->get('shipping_novaposhta_status')) {
			return array();
		}

		$this->load->library('novaposhta');
		$state = $this->novaposhta->stateFromConfig();
		if (empty($state['connection']['enabled']) || empty($state['methods']) || !is_array($state['methods'])) {
			return array();
		}

		$this->load->language('extension/shipping/novaposhta');
		$weight = $this->weightKg();
		$subtotal = (float)$this->cart->getSubTotal();
		$quote_data = array();

		foreach ($state['methods'] as $id => $cfg) {
			if (empty($cfg['enabled'])) {
				continue;
			}
			$max = isset($cfg['maxWeight']) ? (float)$cfg['maxWeight'] : 1000;
			if ($weight > $max) {
				continue;
			}
			$cost = $this->priceFor($state, $id, $cfg, $weight, $subtotal, isset($address['city']) ? $address['city'] : '');
			$free_from = isset($cfg['freeFrom']) ? (float)$cfg['freeFrom'] : 0;
			$shop_pays = (isset($state['waybill']['payer']) && $state['waybill']['payer'] === 'Sender') || ($free_from > 0 && $subtotal >= $free_from);
			if ($shop_pays) {
				$cost = 0;
			}
			$title = !empty($cfg['title']) ? $cfg['title'] : $id;
			$quote_data[$id] = array(
				'code' => 'novaposhta.' . $id,
				'title' => $title,
				'cost' => $cost,
				'tax_class_id' => 0,
				'text' => $cost <= 0 ? $this->language->get('text_free') : $this->currency->format($cost, $this->session->data['currency'])
			);
		}

		if (!$quote_data) {
			return array();
		}

		return array(
			'code' => 'novaposhta',
			'title' => $this->language->get('text_title'),
			'quote' => $quote_data,
			'sort_order' => (int)$this->config->get('shipping_novaposhta_sort_order'),
			'error' => false
		);
	}

	private function weightKg() {
		$weight = (float)$this->cart->getWeight();
		$query = $this->db->query("SELECT weight_class_id FROM " . DB_PREFIX . "weight_class_description WHERE unit IN ('kg','кг','KG','Kg') LIMIT 1");
		if ($query->num_rows) {
			$weight = (float)$this->weight->convert($weight, $this->config->get('config_weight_class_id'), (int)$query->row['weight_class_id']);
		}
		if ($weight <= 0) {
			$this->load->library('novaposhta');
			$state = $this->novaposhta->stateFromConfig();
			$weight = isset($state['waybill']['weight']) ? (float)$state['waybill']['weight'] : 1;
		}
		return $weight;
	}

	private function priceFor($state, $id, $cfg, $weight, $subtotal, $city) {
		if (isset($cfg['priceMode']) && $cfg['priceMode'] === 'fixed') {
			return max(0, (float)$cfg['fixedPrice']);
		}
		$api_key = isset($state['connection']['apiKey']) ? trim((string)$state['connection']['apiKey']) : '';
		if (strlen($api_key) >= 32 && $city) {
			$sender_city = isset($state['sender']['city']) ? $state['sender']['city'] : 'Київ';
			$cache_key = 'np.price.' . md5($sender_city . '|' . $city . '|' . $weight . '|' . $subtotal . '|' . $id);
			$cached = $this->cache->get($cache_key);
			if ($cached !== null && $cached !== false && $cached !== '') {
				return (float)$cached;
			}
			$sender_ref = $this->cityRef($sender_city);
			$recip_ref = $this->cityRef($city);
			if ($sender_ref && $recip_ref) {
				$this->load->library('novaposhta');
				$res = $this->novaposhta->call('InternetDocument', 'getDocumentPrice', array(
					'CitySender' => $sender_ref,
					'CityRecipient' => $recip_ref,
					'Weight' => (string)$weight,
					'ServiceType' => $id,
					'Cost' => (string)max(1, round($subtotal)),
					'CargoType' => isset($state['waybill']['cargoType']) ? $state['waybill']['cargoType'] : 'Parcel',
					'SeatsAmount' => isset($state['waybill']['seats']) ? (string)$state['waybill']['seats'] : '1'
				), $api_key, isset($state['connection']['timeoutSec']) ? (int)$state['connection']['timeoutSec'] : 20);
				if (!empty($res['success']) && isset($res['data'][0]['Cost'])) {
					$cost = (float)$res['data'][0]['Cost'];
					$this->cache->set($cache_key, $cost);
					return $cost;
				}
			}
		}
		$doors = (strpos($id, 'Doors') !== false);
		if ($weight <= 2) {
			return $doors ? 105 : 70;
		}
		if ($weight <= 10) {
			return $doors ? 145 : 95;
		}
		return $doors ? 145 : 135;
	}

	private function cityRef($label) {
		$name = trim(preg_replace('/^м\.\s*/u', '', (string)$label));
		$name = trim(preg_replace('/\(.*\)/u', '', $name));
		if ($name === '') {
			return '';
		}
		$cache_key = 'np.city.' . md5(mb_strtolower($name));
		$cached = $this->cache->get($cache_key);
		if ($cached) {
			return $cached;
		}
		$this->load->library('novaposhta');
		$res = $this->novaposhta->call('Address', 'searchSettlements', array(
			'CityName' => $name,
			'Limit' => '1'
		), '', 15);
		$ref = '';
		if (!empty($res['data'][0]['Addresses'][0]['DeliveryCity'])) {
			$ref = $res['data'][0]['Addresses'][0]['DeliveryCity'];
			$this->cache->set($cache_key, $ref);
		}
		return $ref;
	}
}
