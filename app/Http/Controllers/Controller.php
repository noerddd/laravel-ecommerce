<?php

namespace App\Http\Controllers;

use Midtrans\Config;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Client;

class Controller extends BaseController
{
	use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

	protected $data = [];
	protected $uploadsFolder = 'uploads/';
	protected $rajaOngkirApiKey = null;
	protected $rajaOngkirBaseUrl = null;
	protected $rajaOngkirOrigin = null;
	protected $couriers = [
		'jne' => 'JNE',
		'pos' => 'POS Indonesia',
		'tiki' => 'Titipan Kilat'
	];
	protected $provinces = [];

	/**
	 * Create a new controller instance.
	 *
	 * @return void
	 */
	public function __construct()
	{
		$this->rajaOngkirApiKey = config('rajaongkir.api_key');
		$this->rajaOngkirBaseUrl = config('rajaongkir.base_url');
		$this->rajaOngkirOrigin = config('rajaongkir.origin');
	}

	/**
	 * Raja Ongkir Request (Shipping Cost Calculation)
	 *
	 * @param string $resource resource url
	 * @param array  $params   parameters
	 * @param string $method   request method
	 *
	 * @return json
	 */
	protected function rajaOngkirRequest($resource, $params = [], $method = 'GET')
	{
		$client = new Client();

		$headers = ['key' => $this->rajaOngkirApiKey];
		$requestParams = ['headers' => $headers];

		$url = $this->rajaOngkirBaseUrl . $resource;

		if ($params && $method == 'POST') {
			$requestParams['form_params'] = $params;
		} else if ($params && $method == 'GET') {
			$query = is_array($params) ? '?' . http_build_query($params) : '';
			$url = $this->rajaOngkirBaseUrl . $resource . $query;
		}

		try {
			$response = $client->request($method, $url, $requestParams);
			return json_decode($response->getBody(), true);
		} catch (GuzzleException $e) {
			// Handle exceptions here
			return null;
		}
	}

	/**
	 * Get provinces
	 *
	 * @return array
	 */
	protected function getProvinces()
	{
		$provinceFile = 'provinces.txt';
		$provinceFilePath = 'files/' . $provinceFile;

		// Check if the province file exists in local storage
		$isExistProvinceJson = Storage::disk('local')->exists($provinceFilePath);

		if (!$isExistProvinceJson) {
			// If the file doesn't exist, make an API request to RajaOngkir
			$response = $this->rajaOngkirRequest('/province');

			// Check if the response from RajaOngkir is valid
			if (isset($response['rajaongkir']['results']) && is_array($response['rajaongkir']['results'])) {
				// Serialize the response data and store it in local storage
				Storage::disk('local')->put($provinceFilePath, serialize($response['rajaongkir']['results']));
			} else {
				// Handle invalid response
				return [];
			}
		}

		// Get the serialized province data from the local storage file
		$provinceData = Storage::disk('local')->get($provinceFilePath);

		if ($provinceData) {
			$provinceData = unserialize($provinceData);
		}

		// Check if the data from the file is valid
		if (!is_array($provinceData)) {
			return [];
		}

		// Prepare the provinces array
		$provinces = [];
		foreach ($provinceData as $province) {
			// Ensure the province data structure is correct
			if (isset($province['province_id']) && isset($province['province'])) {
				$provinces[$province['province_id']] = strtoupper($province['province']);
			}
		}

		return $provinces;
	}
	/**
	 * Get cities by province ID
	 *
	 * @param int $provinceId province id
	 *
	 * @return array
	 */
	protected function getCities($provinceId)
	{
		$cityFile = 'cities_at_' . $provinceId . '.txt';
		$cityFilePath = $this->uploadsFolder . 'files/' . $cityFile;

		$isExistCitiesJson = Storage::disk('local')->exists($cityFilePath);

		if (!$isExistCitiesJson) {
			$response = $this->rajaOngkirRequest('/city', ['province' => $provinceId]);
			Storage::disk('local')->put($cityFilePath, serialize($response['rajaongkir']['results']));
		}

		$cityList = unserialize(Storage::get($cityFilePath));

		$cities = [];
		if (!empty($cityList)) {
			foreach ($cityList as $city) {
				$cities[$city['city_id']] = strtoupper($city['type'] . ' ' . $city['city_name']);
			}
		}

		return $cities;
	}

	protected function initPaymentGateway()
	{
		// Set your Merchant Server Key
		Config::$serverKey = config('midtrans.serverKey');
		// Set to Development/Sandbox Environment (default). Set to true for Production Environment (accept real transaction).
		Config::$isProduction = config('midtrans.isProduction');
		// Set sanitization on (default)
		Config::$isSanitized = config('midtrans.isSanitized');
		// Set 3DS transaction for credit card to true
		Config::$is3ds = config('midtrans.is3ds');
	}
}
