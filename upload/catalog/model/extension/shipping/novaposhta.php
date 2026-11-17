<?php
require_once DIR_SYSTEM . 'library/novaposhta/service.php';

class ModelExtensionShippingNovaposhta extends Model {
	public function getQuote($address) {
		$this->load->language('extension/shipping/novaposhta');

		if (!$this->config->get('shipping_novaposhta_status')) {
			return array();
		}

		$methods = $this->config->get('shipping_novaposhta_method');
		if (!is_array($methods)) {
			return array();
		}

		$countryId = (int)$this->config->get('shipping_novaposhta_country_id');
		if ($countryId && (int)$address['country_id'] !== $countryId) {
			return array();
		}

		$service = new NovaposhtaService($this->registry);
		$cookie = isset($this->request->cookie['np_pick']) ? $this->request->cookie['np_pick'] : '';
		$customerId = $this->customer->isLogged() ? (int)$this->customer->getId() : 0;
		$service->hydrateSelection($this->session->data, $customerId, $cookie);
		$metrics = $service->cartMetrics($this->cart);
		$subtotal = $service->toUah($this->cart->getSubTotal());

		$session = isset($this->session->data['novaposhta']) && is_array($this->session->data['novaposhta'])
			? $this->session->data['novaposhta'] : array();

		$cityName = !empty($address['city']) ? trim($address['city']) : '';
		$cityRef = '';
		if (!empty($session['city_ref']) && !empty($session['city_name']) && $this->sameText($session['city_name'], $cityName)) {
			$cityRef = $session['city_ref'];
		} elseif ($cityName) {
			$cityRef = $service->findCityRef($cityName);
		}

		$divisionRef = '';
		if (!empty($session['division_ref']) && $cityRef && !empty($session['city_ref']) && $session['city_ref'] === $cityRef) {
			$divisionRef = $session['division_ref'];
		} elseif ($cityRef && !empty($address['address_1'])) {
			$divisionRef = $service->findWarehouseRef($cityRef, $address['address_1']);
		}

		$senderCity = (string)$this->config->get('shipping_novaposhta_sender_city');
		$senderArea = $senderCity ? $service->cityAreaRef($senderCity) : '';
		$recipientArea = $cityRef ? $service->cityAreaRef($cityRef) : '';

		if ($cityRef && $cityRef === $senderCity) {
			$zone = 'city';
		} elseif ($recipientArea && $senderArea && $recipientArea === $senderArea) {
			$zone = 'region';
		} else {
			$zone = 'ukraine';
		}

		$cod = 0;
		$codPayments = $this->config->get('shipping_novaposhta_cod');
		if (!is_array($codPayments)) {
			$codPayments = array();
		}
		if (!empty($this->session->data['payment_method']['code']) && in_array($this->session->data['payment_method']['code'], $codPayments)) {
			$cod = round($subtotal);
		}

		$senderType = $this->config->get('shipping_novaposhta_sender_type') === 'Doors' ? 'Doors' : 'Warehouse';
		$quoteData = array();
		$sharedArrival = '';

		foreach (array('department', 'poshtomat', 'doors') as $code) {
			if (empty($methods[$code]['status'])) {
				continue;
			}

			$method = $methods[$code];
			$geoZoneId = isset($method['geo_zone_id']) ? (int)$method['geo_zone_id'] : 0;

			if ($geoZoneId) {
				$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "zone_to_geo_zone`
					WHERE geo_zone_id = '" . $geoZoneId . "'
					AND country_id = '" . (int)$address['country_id'] . "'
					AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");
				if (!$query->num_rows) {
					continue;
				}
			}

			$freeFrom = isset($method['free_from']) ? (float)$method['free_from'] : 0;
			$serviceType = ($code === 'doors') ? $senderType . 'Doors' : $senderType . 'Warehouse';

			$costUah = 0;
			$period = 0;
			$free = $freeFrom > 0 && $subtotal >= $freeFrom;

			if (!$free) {
				$apiOn = !empty($method['api']) && $cityRef && $this->config->get('shipping_novaposhta_api_key') && $senderCity;
				if ($apiOn) {
					$apiQuote = $service->quoteApi($cityRef, $code === 'doors' ? '' : $divisionRef, $serviceType, $metrics, $subtotal, $cod);
					if ($apiQuote) {
						$costUah = $apiQuote['cost'];
						$period = $apiQuote['period'];
					}
				}

				if ($costUah <= 0) {
					$tariffWeight = max($metrics['weight'], $metrics['volume_weight']);
					$costUah = $service->tariffCost($tariffWeight, $zone, $serviceType, $code);
				}

				if ($costUah <= 0) {
					continue;
				}
			}

			$cost = $service->fromUah($costUah);
			$taxClassId = isset($method['tax_class_id']) ? (int)$method['tax_class_id'] : 0;
			$title = $this->language->get('text_method_' . $code);

			$text = $free
				? $this->language->get('text_free')
				: $this->currency->format($this->tax->calculate($cost, $taxClassId, $this->config->get('config_tax')), $this->session->data['currency']);

			if ($period > 0) {
				$arrival = date('d.m', strtotime('+' . (int)$period . ' day'));
			} elseif ($sharedArrival !== '') {
				$arrival = $sharedArrival;
			} elseif ($cityRef && $senderCity && $this->config->get('shipping_novaposhta_api_key')) {
				$sharedArrival = $service->deliveryDate($senderCity, $cityRef, $senderType . 'Warehouse');
				$arrival = $sharedArrival;
			} else {
				$arrival = '';
			}

			if ($arrival) {
				$text .= ' · ' . sprintf($this->language->get('text_arrival'), $arrival);
			}

			$quoteData[$code] = array(
				'code'         => 'novaposhta.' . $code,
				'title'        => $title,
				'cost'         => $cost,
				'tax_class_id' => $taxClassId,
				'text'         => $text
			);
		}

		if (!$quoteData) {
			return array();
		}

		return array(
			'code'       => 'novaposhta',
			'title'      => $this->language->get('text_title'),
			'quote'      => $quoteData,
			'sort_order' => (int)$this->config->get('shipping_novaposhta_sort_order'),
			'error'      => false
		);
	}

	private function sameText($a, $b) {
		return utf8_strtolower(trim($a)) === utf8_strtolower(trim($b));
	}
}
