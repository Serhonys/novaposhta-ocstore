<?php
/**
 * Nova Poshta API 2.0 client for ocStore 3.
 * One POST endpoint. HTTP 200 does not mean success — check the success flag.
 */
class NovaposhtaApi {
	private $key;
	private $endpoint = 'https://api.novaposhta.ua/v2.0/json/';
	private $lastError = '';
	private $logger;

	public function __construct($key) {
		$this->key = (string)$key;
	}

	public function setLogger($logger) {
		$this->logger = $logger;
	}

	public function getLastError() {
		return $this->lastError;
	}

	public function call($model, $method, array $properties = array(), $timeout = 25) {
		$this->lastError = '';

		if ($this->key === '') {
			$this->lastError = 'API key is empty';
			$this->writeLog($model, $method, false, $this->lastError);
			return false;
		}

		if (!function_exists('curl_init')) {
			$this->lastError = 'PHP cURL is not installed';
			return false;
		}

		$properties = $properties ? $properties : new stdClass();

		$body = json_encode(array(
			'apiKey'           => $this->key,
			'modelName'        => $model,
			'calledMethod'     => $method,
			'methodProperties' => $properties
		), JSON_UNESCAPED_UNICODE);

		$ch = curl_init($this->endpoint);
		curl_setopt_array($ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 8,
			CURLOPT_TIMEOUT        => max(8, (int)$timeout),
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/json',
				'Accept: application/json',
				'User-Agent: ocStore-NovaPoshta/1.1'
			)
		));

		$raw = curl_exec($ch);
		$errno = curl_errno($ch);
		$err = curl_error($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($errno) {
			$this->lastError = 'cURL: ' . $err;
			$this->writeLog($model, $method, false, $this->lastError);
			return false;
		}

		$json = json_decode($raw, true);

		if (!is_array($json)) {
			$this->lastError = 'Nova Poshta returned a non-JSON response' . ($code ? ' (HTTP ' . $code . ')' : '');
			$this->writeLog($model, $method, false, $this->lastError);
			return false;
		}

		if (empty($json['success'])) {
			$this->lastError = $this->describeFailure($json, $code, $method);
			$this->writeLog($model, $method, false, $this->lastError);
			return false;
		}

		$this->writeLog($model, $method, true, 'ok');
		return isset($json['data']) ? $json['data'] : array();
	}

	public function areas() {
		$data = $this->call('Address', 'getAreas');
		return $data === false ? false : $data;
	}

	public function cities($page, $limit = 500) {
		return $this->call('Address', 'getCities', array(
			'Page'  => (string)(int)$page,
			'Limit' => (string)(int)$limit
		));
	}

	public function warehouseTypes() {
		$data = $this->call('Address', 'getWarehouseTypes', array(), 40);
		if ($data === false || !$data) {
			$data = $this->call('AddressGeneral', 'getWarehouseTypes', array(), 40);
		}
		return $data === false ? false : $data;
	}

	public function warehouses($page, $limit = 300, $typeRef = '') {
		$properties = array(
			'Page'  => (string)(int)$page,
			'Limit' => (string)(int)$limit
		);
		if ($typeRef !== '') {
			$properties['TypeOfWarehouseRef'] = $typeRef;
		}

		$data = $this->call('Address', 'getWarehouses', $properties, 90);
		if ($data === false) {
			$data = $this->call('AddressGeneral', 'getWarehouses', $properties, 90);
		}
		return $data;
	}

	public function searchSettlements($query) {
		$data = $this->call('Address', 'searchSettlements', array(
			'CityName' => $query,
			'Limit'    => '20',
			'Page'     => '1'
		));

		if ($data === false) {
			return false;
		}

		if (isset($data[0]['Addresses']) && is_array($data[0]['Addresses'])) {
			return $data[0]['Addresses'];
		}

		return array();
	}

	public function searchStreets($settlementRef, $query) {
		$data = $this->call('Address', 'searchSettlementStreets', array(
			'SettlementRef' => $settlementRef,
			'StreetName'    => $query,
			'Limit'         => '20'
		));

		if ($data === false) {
			return false;
		}

		if (isset($data[0]['Addresses']) && is_array($data[0]['Addresses'])) {
			return $data[0]['Addresses'];
		}

		return array();
	}

	public function counterparties() {
		return $this->call('Counterparty', 'getCounterparties', array(
			'CounterpartyProperty' => 'Sender',
			'Page'                 => '1'
		));
	}

	public function contacts($counterpartyRef) {
		return $this->call('Counterparty', 'getCounterpartyContactPersons', array(
			'Ref'  => $counterpartyRef,
			'Page' => '1'
		));
	}

	public function addresses($counterpartyRef) {
		$properties = array(
			'Ref'                  => $counterpartyRef,
			'CounterpartyProperty' => 'Sender'
		);
		$data = $this->call('Counterparty', 'getCounterpartyAddresses', $properties);
		if (!$data) {
			$general = $this->call('CounterpartyGeneral', 'getCounterpartyAddresses', $properties);
			if ($general) {
				return $general;
			}
		}
		return $data;
	}

	public function price(array $properties) {
		return $this->call('InternetDocument', 'getDocumentPrice', $properties);
	}

	public function deliveryDate(array $properties) {
		return $this->call('InternetDocument', 'getDocumentDeliveryDate', $properties);
	}

	public function saveCounterparty(array $properties) {
		return $this->call('Counterparty', 'save', $properties);
	}

	public function saveAddress(array $properties) {
		return $this->call('Address', 'save', $properties);
	}

	public function saveDocument(array $properties) {
		return $this->call('InternetDocument', 'save', $properties);
	}

	public function deleteDocument($ref) {
		return $this->call('InternetDocument', 'delete', array(
			'DocumentRefs' => $ref
		));
	}

	public function track(array $documents) {
		return $this->call('TrackingDocument', 'getStatusDocuments', array(
			'Documents' => $documents
		));
	}

	public static function phone($phone) {
		$digits = preg_replace('/\D+/', '', (string)$phone);

		if (strpos($digits, '380') === 0) {
			return $digits;
		}

		if (strpos($digits, '80') === 0) {
			return '3' . $digits;
		}

		if (strpos($digits, '0') === 0) {
			return '38' . $digits;
		}

		return $digits;
	}

	public static function printUrl($ref, $kind, $key) {
		$refs = is_array($ref) ? $ref : array($ref);
		$clean = array();
		foreach ($refs as $item) {
			$item = trim((string)$item);
			if ($item !== '') {
				$clean[] = $item;
			}
		}
		$joined = implode(',', $clean);
		$key = rawurlencode($key);

		if ($kind === 'marking') {
			return 'https://my.novaposhta.ua/orders/printMarking100x100/orders[]/' . $joined . '/type/pdf/apiKey/' . $key;
		}
		if ($kind === 'scan') {
			return 'https://my.novaposhta.ua/scanSheet/printScanSheet/refs[]/' . $joined . '/type/pdf/apiKey/' . $key;
		}

		return 'https://my.novaposhta.ua/orders/printDocument/orders[]/' . $joined . '/type/pdf/apiKey/' . $key;
	}

	private function writeLog($model, $method, $ok, $message) {
		if (!$this->logger) {
			return;
		}
		$keepSuccess = array('save', 'delete', 'insertDocuments', 'saveCourierCall');
		if ($ok && !in_array($method, $keepSuccess, true)) {
			return;
		}
		call_user_func($this->logger, $model, $method, $ok, substr((string)$message, 0, 400));
	}

	private function describeFailure($json, $code, $method) {
		$parts = array();
		foreach (array('errors', 'errorCodes', 'warnings', 'warningCodes', 'info') as $key) {
			if (empty($json[$key])) {
				continue;
			}
			$flat = $this->flattenErrors($json[$key]);
			if ($flat !== '') {
				$parts[] = $flat;
			}
		}

		$message = implode('; ', $parts);
		if ($message === '') {
			$message = 'Nova Poshta rejected ' . $method;
			if ($code) {
				$message .= ' (HTTP ' . $code . ')';
			}
		}

		return $message;
	}

	private function flattenErrors($errors) {
		$out = array();

		if (is_string($errors)) {
			return $errors;
		}

		if (!is_array($errors)) {
			return '';
		}

		foreach ($errors as $error) {
			if (is_string($error) && $error !== '') {
				$out[] = $error;
			} elseif (is_array($error)) {
				if (!empty($error['error']) && is_string($error['error'])) {
					$out[] = $error['error'];
				} else {
					$flat = $this->flattenErrors($error);
					if ($flat !== '') {
						$out[] = $flat;
					}
				}
			}
		}

		return implode('; ', $out);
	}
}
