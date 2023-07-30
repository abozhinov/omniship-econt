<?php
/**
 * Created by PhpStorm.
 * User: joro
 * Date: 16.5.2017 г.
 * Time: 15:12 ч.
 */

namespace Omniship\Econt;

use Carbon\Carbon;
use DOMDocument;
use Omniship\Econt\Helper\XmlConstructor;
use Omniship\Econt\Lib\Request\Parcels;
use Omniship\Econt\Lib\Response\CancelParcel;
use Omniship\Econt\Lib\Response\City;
use Omniship\Econt\Lib\Response\Client\ClientAddressBag;
use Omniship\Econt\Lib\Response\Client\ClientInfo;
use Omniship\Econt\Lib\Response\CodPayment;
use Omniship\Econt\Lib\Response\Country;
use Omniship\Econt\Lib\Response\Office\Office;
use Omniship\Econt\Lib\Response\PostBox;
use Omniship\Econt\Lib\Response\Quarter;
use Omniship\Econt\Lib\Response\Region;
use Omniship\Econt\Lib\Response\Shipment;
use Omniship\Econt\Lib\Response\Street;
use Omniship\Econt\Lib\Response\Zone;
use Omniship\Econt\Lib\Response\Client\Client AS ResponseClient;
use Omniship\Econt\Lib\Response\Parcel;
use Omniship\Helper\Collection;
use SimpleXMLElement;
use GuzzleHttp\Client AS HttpClient;

class Client
{

    /**
     * @var string
     */
    protected $username;

    /**
     * @var string
     */
    protected $password;

    protected $test_mode = false;

    protected $error;

    protected $client_info;

    protected $last_request;
    protected $last_response;

    protected $connection_options;

    const SERVICE_TESTING_URL = 'http://demo.econt.com/e-econt/xml_service_tool.php';
    const SERVICE_PRODUCTION_URL = 'http://www.econt.com/e-econt/xml_service_tool.php';

    const PARCEL_TESTING_URL = 'http://demo.econt.com/e-econt/xml_parcel_import2.php';
    const PARCEL_PRODUCTION_URL = 'http://www.econt.com/e-econt/xml_parcel_import2.php';

    const MEDIATOR = 'DREAMSHOP';

    public function __construct($username, $password, array $connection_options = null)
    {
        $this->username = $username;
        $this->password = $password;
        $this->connection_options = $connection_options;
    }

    /**
     * @param boolean $test_mode
     * @return Client
     */
    public function setTestMode($test_mode)
    {
        $this->test_mode = $test_mode;
        return $this;
    }

    /**
     * @return boolean
     */
    public function getTestMode()
    {
        return $this->test_mode;
    }

    /**
     * @param string $username
     * @return Client
     */
    public function setUsername($username)
    {
        $this->username = $username;
        return $this;
    }

    /**
     * @return null|string
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * @param string $password
     * @return Client
     */
    public function setPassword($password)
    {
        $this->password = $password;
        return $this;
    }

    /**
     * @return null|string
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * @return null|string
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * @return bool
     */
    public function validateConnection() {
        if(!$this->username || !$this->password) {
            return false;
        }

        $post = $this->post($this->getServiceEndpoint(), $this->_getRequestData('profile'));
        if(!empty($post->error)) {
            $this->error = (string)$post->error->message;
            return false;
        }

        return isset($post->client_info) && isset($post->addresses);
    }

    /**
     * Get client information
     * @return bool|ClientInfo
     */
    public function getClientInfo()
    {
        $this->client_info = $post = $this->post($this->getServiceEndpoint(), $this->_getRequestData('profile'));
        if (!empty($post->client_info)) {
            return new ClientInfo($post->client_info);
        } elseif (!empty($post->error)) {
            $this->error = (string)$post->error->message;
        }
        return false;
    }

    /**
     * Get client addresses
     * @return bool|ClientAddressBag
     */
    public function getClientAddresses()
    {
        if (is_null($this->client_info)) {
            $this->getClientInfo();
        }
        $bag = new ClientAddressBag();
        if (!empty($this->client_info) && !empty($this->client_info->addresses)) {
            foreach ($this->client_info->addresses->e AS $address) {
                $bag->push($address);
            }
        }
        return $bag;
    }

    /**
     * Get offices
     * @return Office[]|Collection
     */
    public function getOffices()
    {
        $collection = [];
        $post = $this->post($this->getServiceEndpoint(), $this->_getRequestData('offices'));
        if (!empty($post->offices) && !empty($post->offices->e)) {
            foreach ($post->offices->e AS $office) {
                $collection[] = new Office($office);
            }
        }
        return new Collection($collection);
    }

    /**
     * Get zones
     * @return Zone[]|Collection
     */
    public function getCitiesZones()
    {
        $collection = [];
        $post = $this->post($this->getServiceEndpoint(), $this->_getRequestData('cities_zones'));
        if (!empty($post->zones) && !empty($post->zones->e)) {
            foreach ($post->zones->e AS $zone) {
                $collection[] = new Zone($zone);
            }
        }
        return new Collection($collection);
    }

    /**
     * Get cities
     * @param string|integer $zone
     *  all or zone_id
     * @param null $name
     *  search for main language not en
     * @param string $report_type
     * @return City[]|Collection
     */
    public function getCities($zone = null, $name = null, $report_type = null)
    {
        $data = [];
        if ($name) {
            $data['cities']['city_name'] = $name;
        }
        if ($zone) {
            $data['cities']['id_zone'] = $zone;
        }
        if ($report_type) {
            $data['cities']['report_type'] = $report_type; //short
        }

        $collection = [];
        $post = $this->post($this->getServiceEndpoint(), $this->_getRequestData('cities', $data));
        if (!empty($post->cities) && !empty($post->cities->e)) {
            foreach ($post->cities->e AS $city) {
                $collection[] = new City($city);
            }
        }
        return new Collection($collection);
    }

    /**
     * Get clients information for client
     * @return ResponseClient[]|Collection
     */
    public function getClients()
    {
        $collection = [];
        $post = $this->post($this->getServiceEndpoint(), $this->_getRequestData('access_clients'));
        if (!empty($post->clients) && !empty($post->clients->client)) {
            foreach ($post->clients->client AS $client) {
                $collection[] = new ResponseClient($client);
            }
        }
        return new Collection($collection);
    }

    /**
     * Get countries
     * @return Country[]|Collection
     */
    public function getCountries()
    {
        $collection = [];
        $post = $this->post($this->getServiceEndpoint(), $this->_getRequestData('countries'));
        if (!empty($post->e)) {
            foreach ($post->e AS $client) {
                $collection[] = new Country($client);
            }
        }
        return new Collection($collection);
    }

    /**
     * Get regions
     * @return Region[]|Collection
     */
    public function getRegions()
    {
        $collection = [];
        $post = $this->post($this->getServiceEndpoint(), $this->_getRequestData('cities_regions'));
        if (!empty($post->cities_regions) && !empty($post->cities_regions->e)) {
            foreach ($post->cities_regions->e AS $client) {
                $collection[] = new Region($client);
            }
        }
        return new Collection($collection);
    }

    /**
     * Get quarters
     * @return Quarter[]|Collection
     */
    public function getQuarters()
    {
        $collection = [];
        $post = $this->post($this->getServiceEndpoint(), $this->_getRequestData('cities_quarters'));
        if (!empty($post->cities_quarters) && !empty($post->cities_quarters->e)) {
            foreach ($post->cities_quarters->e AS $client) {
                $collection[] = new Quarter($client);
            }
        }
        return new Collection($collection);
    }

    /**
     * Get streets
     * @return Street[]|Collection
     */
    public function getStreets()
    {
        $collection = [];
        $post = $this->post($this->getServiceEndpoint(), $this->_getRequestData('cities_streets'));
        if (!empty($post->cities_street) && !empty($post->cities_street->e)) {
            foreach ($post->cities_street->e AS $client) {
                $collection[] = new Street($client);
            }
        }
        return new Collection($collection);
    }

    /**
     * Get post boxes
     * @param $city
     * @param $quarter
     * @return PostBox[]|Collection
     */
    public function getPostBoxes($city = null, $quarter = null)
    {
        $data = [];
        if ($city) {
            $data['post_boxes']['city_name'] = $city;
        }
        if ($quarter) {
            $data['post_boxes']['quarter_name'] = $quarter;
        }
        $collection = [];
        $post = $this->post($this->getServiceEndpoint(), $this->_getRequestData('post_boxes', $data));
        if (!empty($post->post_boxes) && !empty($post->post_boxes->e)) {
            foreach ($post->post_boxes->e AS $client) {
                $collection[] = new PostBox($client);
            }
        }
        return new Collection($collection);
    }

    /**
     * Get delivery days
     * @return Carbon[]|Collection
     */
    public function getDeliveryDays()
    {
        $request = [
            'delivery_days' => Carbon::now()->format('Y-m-d'),
        ];
        $collection = [];
        $post = $this->post($this->getServiceEndpoint(), $this->_getRequestData('delivery_days', $request));
        if (!empty($post->delivery_days) && !empty($post->delivery_days->e)) {
            foreach ($post->delivery_days->e AS $date) {
                $collection[] = Carbon::createFromFormat('Y-m-d', (string)$date->date, 'Europe/Sofia');
            }
        }
        return new Collection($collection);
    }

    /**
     * Get status for bill of landing
     * @param $parcelId
     * @return bool|Shipment
     */
    public function trackParcel($parcelId)
    {
        $tracking = $this->trackParcels([$parcelId]);
        if (!empty($tracking[$parcelId])) {
            return $tracking[$parcelId];
        }
        return false;
    }

    /**
     * Get status for bill of landing's
     * @param array $parcelIds
     * @return Shipment[]
     */
    public function trackParcels(array $parcelIds)
    {
        $trackRequest = [
            'shipments' => [
                'type' => 'ON',
                'value' => [
                    'num' => array_map('floatval', $parcelIds)
                ]
            ]
        ];

        $parcels = [];
        $post = $this->post($this->getServiceEndpoint(), $this->_getRequestData('shipments', $trackRequest));
        if (!empty($post->shipments) && !empty($post->shipments->e)) {
            foreach ($post->shipments->e as $parcel) {
                $parcel = new Shipment($parcel);
                $parcels[(string)$parcel->getLoadingNum()] = $parcel;
            }
        }
        return $parcels;
    }

    /**
     * Calculate parcel
     * @param array|Parcels $data
     * @return bool|Parcel
     */
    public function calculate($data)
    {
        $parcels = false;
        $data['client'] = $this->_getLoginCredential();
        $data['loadings']['row']['mediator'] = static::MEDIATOR;

        $post = $this->post($this->getParcelEndpoint(), ['parcels' => $data]);
        if (!empty($post->result) && !empty($post->result->e)) {
            $parcels = new Parcel($post->result->e);
        }
        return $parcels;
    }

    /**
     * @param array $data
     * @return bool|Parcel
     */
    public function createBillOfLading(array $data)
    {
        return $this->calculate($data);
    }

    /**
     * @param $bol_id
     * @return CancelParcel[]
     */
    public function cancelBol($bol_id)
    {
        $parcels = [];
        $data = [
            'cancel_shipments' => [
                'num' => array_map('floatval', (array)$bol_id)
            ]
        ];
        $post = $this->post($this->getServiceEndpoint(), $this->_getRequestData('cancel_shipments', $data));
        if (!empty($post->cancel_shipments) && !empty($post->cancel_shipments->e)) {
            foreach ($post->cancel_shipments->e as $parcel) {
                $parcels[(string)$parcel->num] = new CancelParcel($parcel);
            }
        }
        return $parcels;
    }

    /**
     * @param $bol_id
     * @param null|Carbon $date_start
     * @param null|Carbon $date_end
     * @return CancelParcel[]
     */
    public function requestCourier($bol_id, Carbon $date_start = null, Carbon $date_end = null)
    {
        $data = ['system' => [
            'response_type' => 'XML',
        ]];
        $data['client'] = $this->_getLoginCredential();
        $data['loadings']['row']['mediator'] = static::MEDIATOR;
        $data['loadings']['row']['courier_request'] = [
            'only_courier_request' => 1,
        ];
        if ($date_start) {
            $data['loadings']['row']['courier_request']['time_from'] = $date_start->format('H:i');
        }
        if ($date_end) {
            $data['loadings']['row']['courier_request']['time_to'] = $date_end->format('H:i');
        }

        $post = $this->post($this->getParcelEndpoint(), ['parcels' => $data]);

        $bag = new Parcel\ParcelBag();
        if (!empty($post->result) && !empty($post->result->e)) {
            foreach ($post->result->e AS $request) {
                $bag->push($request);
            }
        }

        return $bag->first();
    }

    /**
     * Get status for bill of landing
     * @param $parcelId
     * @return null|CodPayment
     */
    public function codPayment($parcelId)
    {
        $tracking = $this->trackParcel($parcelId);
        if (!$tracking) {
            return null;
        }
        return new CodPayment($tracking->toArray());
    }

    /**
     * Get status for list of bill of landing
     * @param array $parcelIds
     * @return CodPayment[]
     */
    public function codPayments(array $parcelIds)
    {
        $trackings = $this->trackParcels($parcelIds);
        return array_map(function (Shipment $tracking) {
            return new CodPayment($tracking->toArray());
        }, $trackings);
    }

    /**
     * @param $username
     * @param $password
     * @return bool
     */
    public function validateCredentials($username, $password)
    {
        $instance = new static($username, $password);
        $instance->setTestMode($this->getTestMode());
        return (bool)$instance->validateConnection();
    }

    /**
     * @param $url
     * @param array $data
     * @return bool|SimpleXMLElement
     */
    public function post($url, $data = [])
    {
        $document = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . (is_array($data) ? $this->prepareXML($data) : $data);

//        $xml = new XmlConstructor();
//        $document = $xml->fromArray($data)->getDocument();

        if (!$this->isXMLContentValid($document)) {
            return false;
        }

        $connection_timeout = 60;
        $read_timeout = null;
        if($this->connection_options) {
            extract($this->connection_options);
        }

        $this->last_request = $document;

        try {
            $client = new HttpClient([
                'connect_timeout' => $connection_timeout, // Connection timeout
                'timeout'         => $read_timeout, // Response timeout
            ]);

            $httpRequest = $client->post($url, array(
                'form_params' => [
                    'xml' => $document
                ]
            ));
        } catch (\Exception $e) {
            return !($this->error = sprintf('CURL Error: %s', $e->getMessage()));
        }

        if ($httpRequest->getStatusCode() != 200) {
            return !($this->error = sprintf('Return response with status code: %s', $httpRequest->getStatusCode()));
        }

        if (!$body = $httpRequest->getBody()->getContents()) {
            return !($this->error = 'Return empty response');
        }
        
        //@todo econt api fix (<b>Warning</b>:  Invalid argument supplied for foreach() in <b>/www/e-econt/include/classes/DBLoadings.class.php</b> on line <b>982</b><br />)
        if (strpos($body, '<?xml ') > 0) {
            $parts = explode('<?xml ', $body, 2);
            $body = '<?xml ' . end($parts);
        }

        $this->last_response = $body;

        if (!$this->isXMLContentValid($body)) {
            return !($this->error = 'Return invalid XML response');
        }

        $xml = new SimpleXMLElement($body);

        if(!empty($xml->error) && !empty($xml->error->message)) {
            $this->error = (string)$xml->error->message;
        }

        return $xml;
    }

    public function getLastRequest()
    {
        return $this->last_request;
    }

    public function getLastResponse()
    {
        return $this->last_response;
    }

    /**
     * Get url associated to a specific service
     *
     * @return string URL for the service
     */
    public function getServiceEndpoint()
    {
        return $this->getTestMode() ? static::SERVICE_TESTING_URL : static::SERVICE_PRODUCTION_URL;
    }

    /**
     * Get url associated to a specific service
     *
     * @return string URL for the service
     */
    public function getParcelEndpoint()
    {
        return $this->getTestMode() ? static::PARCEL_TESTING_URL : static::PARCEL_PRODUCTION_URL;
    }

    /**
     * @param $language
     * @return string
     */
    protected function languageValidate($language)
    {
        if (!in_array(strtolower($language), ['bg', 'en'])) {
            $language = 'en';
        }
        return strtolower($language);
    }

    /**
     * @param array $data
     * @return string
     */
    protected function prepareXML($data)
    {
        $xml = '';

        foreach ($data as $key => $value) {
            if ($key && $key == 'error') {
                continue;
            }

            if ($key && ($key == 'p' || $key == 'cd')) {
                $xml .= '<' . $key . ' type="' . $value['type'] . '">' . $value['value'] . '</' . $key . '>' . "\r\n";
            } elseif ($key && ($key == 'shipments')) {
                $xml .= '<' . $key . ' full_tracking="' . $value['type'] . '">' . (is_array($value['value']) ? "\r\n" . $this->prepareXML($value['value']) : $value['value']) . '</' . $key . '>' . "\r\n";
            } elseif ($key && $key == 'num') {
                $value = is_array($value) ? $value : [$value];
                foreach ($value AS $num) {
                    $xml .= '<' . $key . '>';
                    $xml .= $num;
                    $xml .= '</' . $key . '>' . "\r\n";
                }
            } else {
                if (!is_numeric($key) && $key != 'to_door' && $key != 'to_office') {
                    $xml .= '<' . $key . '>';
                }

                if (is_array($value)) {
                    $xml .= "\r\n" . $this->prepareXML($value);
                } else {
                    $xml .= $value;
                }

                if (!is_numeric($key) && $key != 'to_door' && $key != 'to_office') {
                    $xml .= '</' . $key . '>' . "\r\n";
                }
            }
        }

        return $xml;
    }

    /**
     * @param string $document
     * @param string $version
     * @param string $encoding
     * @return null|DOMDocument
     */
    protected function xmlToDom($document, $version = '1.0', $encoding = 'utf-8')
    {
        if (is_array($document)) {
            $document = $this->prepareXML($document);
        }
        if (!($document = trim($document))) {
            return null;
        }

        libxml_use_internal_errors(true);
        libxml_disable_entity_loader(true);

        $domDoc = new DOMDocument ($version, $encoding);
        $success = $domDoc->loadXML($document);
        $errors = libxml_get_errors();
        if (!empty ($errors)) {
            $errors = array_map(function ($error) {
                if ($error instanceof \LibXMLError) {
                    if ($error->level > LIBXML_ERR_WARNING) {
                        $error = $error->message;
                    } else {
                        $error = null;
                    }
                }
                return $error;
            }, is_array($errors) ? $errors : [$errors]);
            $errors = array_filter($errors);

            $this->error = implode("\n", is_array($errors) ? $errors : [$errors]);
            libxml_clear_errors();
        }
        libxml_disable_entity_loader(false);
        libxml_use_internal_errors(false);

        if (!$success) {
            return null;
        }

        return $domDoc;
    }

    /**
     * @param string $xmlContent A well-formed XML string
     * @param string $version 1.0
     * @param string $encoding utf-8
     * @return bool
     */
    protected function isXMLContentValid($xmlContent, $version = '1.0', $encoding = 'utf-8')
    {
        $this->error = null;
        $dom = $this->xmlToDom($xmlContent, $version, $encoding);
        return $dom instanceof DOMDocument && empty($this->getError());
    }

    /**
     * @param $request_type
     * @param array $data
     * @return array
     */
    protected function _getRequestData($request_type, array $data = [])
    {
        $trackRequest = [
            'request' => [
                'system' => [
                    'response_type' => 'XML',
                ],
                'client' => $this->_getLoginCredential(),
                'request_type' => $request_type,
                'mediator' => static::MEDIATOR
            ]
        ];

        if ($data) {
            $trackRequest['request'] = array_merge($trackRequest['request'], $data);
        }
        return $trackRequest;
    }

    /**
     * @return array
     */
    protected function _getLoginCredential()
    {
        return [
            'username' => $this->getUsername(),
            'password' => $this->getPassword(),
        ];
    }
}
