<?php
namespace Payum\Adyen;

use GuzzleHttp\Psr7\Request;
use Payum\Core\Bridge\Guzzle\HttpClientFactory;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\Http\HttpException;
use Payum\Core\Exception\LogicException;
use Payum\Core\HttpClientInterface;

class Api
{
    /**
     * @var array
     */
    protected $requiredFields = [
        'merchantReference' => null,
        'paymentAmount' => null,
        'currencyCode' => null,
        'shipBeforeDate' => null,
        'skinCode' => null,
        'merchantAccount' => null,
        'sessionValidity' => null,
        'shopperEmail' => null,
    ];
    /**
     * @var array
     */
    protected $optionalFields = [
        'merchantReturnData' => null,
        'shopperReference' => null,
        'allowedMethods' => null,
        'blockedMethods' => null,
        'offset' => null,
        'shopperStatement' => null,
        'recurringContract' => null,
        'billingAddressType' => null,
        'deliveryAddressType' => null,

        'resURL' => null,
    ];
    /**
     * @var array
     */
    protected $othersFields = [
        'brandCode' => null,
        'countryCode' => null,
        'shopperLocale' => null,
        'orderData' => null,
        'offerEmail' => null,

        'issuerId' => null,
    ];
    /**
     * @var array
     */
    protected $responseFields = [
        'authResult' => null,
        'pspReference' => null,
        'merchantReference' => null,
        'skinCode' => null,
        'paymentMethod' => null,
        'shopperLocale' => null,
        'merchantReturnData' => null,
    ];
    /**
     * @var array
     */
    protected $notificationFields = [
        'pspReference' => null,
        'originalReference' => null,
        'merchantAccountCode' => null,
        'merchantReference' => null,
        'amount.value' => null,
        'amount.currency' => null,
        'eventCode' => null,
        'success' => null,
    ];

    /**
     * @var HttpClientInterface
     */
    protected $client;

    /**
     * @var array
     */
    protected $options = [
        'skinCode' => null,
        'merchantAccount' => null,
		'username' => null,
        'password' => null,
        'sandbox' => null,
        'notification_method' => null,
        'notification_hmac' => null,
        // List of values getting from conf
        'default_payment_fields' => [],
    ];

    /**
     * @param array               $options
     * @param HttpClientInterface $client
     *
     * @throws \Payum\Core\Exception\InvalidArgumentException if an option is invalid
     * @throws \Payum\Core\Exception\LogicException if a sandbox is not boolean
     */
    public function __construct(array $options, HttpClientInterface $client = null)
    {
        $options = ArrayObject::ensureArrayObject($options);
        $options->defaults($this->options);
        $options->validateNotEmpty([
            'skinCode',
            'merchantAccount',
			'username',
            'password',
        ]);

        if (false == is_bool($options['sandbox'])) {
            throw new LogicException('The boolean sandbox option must be set.');
        }
        $this->options = $options;
        $this->client = new \Adyen\Client();
		$this->client->setUsername($options['username']);
		$this->client->setPassword($options['password']);
		if ($options['sandbox']) {
			$this->client->setEnvironment(\Adyen\Environment::TEST);
		}
		else {
			$this->client->setEnvironment(\Adyen\Environment::LIVE);
		}
    }

    /**
     * @return string
     */
    public function getApiEndpoint()
    {
        return sprintf('https://%s.adyen.com/hpp/select.shtml', $this->options['sandbox'] ? 'test' : 'live');
    }

	public function prepareFields(array $params)
	{
	    if (false != empty($this->options['default_payment_fields'])) {
	        $params = array_merge($params, (array) $this->options['default_payment_fields']);
	    }
	
	    $params['shipBeforeDate'] = date('Y-m-d', strtotime('+1 hour'));
	    $params['sessionValidity'] = date(DATE_ATOM, strtotime('+1 hour'));
	
	    $params['skinCode'] = $this->options['skinCode'];
	    $params['merchantAccount'] = $this->options['merchantAccount'];
	
	    $supportedParams = array_merge($this->requiredFields, $this->optionalFields, $this->othersFields);
	
	    $params = array_filter(array_replace(
	        $supportedParams,
	        array_intersect_key($params, $supportedParams)
	    ));
	
	    $params['merchantSig'] = $this->merchantSig($params);
	
	    return $params;
	}

	public function doCapturePayment($model) {
        $amount = array("value" => $model['paymentAmount'],                              
                        "currency" => $model['currencyCode']                             
                    );
		$card_data = $model['card.encrypted.json'];
		$save_card = false;
		$sepa_iban = $model['sepa.iban'];

		$params = array("amount" => $amount,                                             
                        "reference"=> $model['merchantReference'],                       
                        "merchantAccount"=> $this->options['merchantAccount'],
						"shopperEmail" => $model["shopperEmail"],
						"shopperReference" => $model["shopperReference"]
                        );

		if ($card_data) {
        	$additional_data = array("card.encrypted.json" => $model['card.encrypted.json']);
			$save_card= $model['save_card'];
            $params["additionalData"] = $additional_data;
			if ($save_card) {
				$params["recurring"] = array("contract" => \Adyen\Contract::ONECLICK_RECURRING);
			}
		}
		elseif ($sepa_iban) {
			$params['bankAccount'] = array(
				'iban' => $model['sepa.iban'],
				'ownerName' => $model['sepa.ownerName'],
				'countryCode' => $model['sepa.countryCode']
			);
			$params['selectedBrand'] = "sepadirectdebit";
		}
		else {
			$recurring_detail_ref = $model['card.recurring_detail_ref'];
			if ($recurring_detail_ref) {
				$params["recurring"] = array("contract" => \Adyen\Contract::RECURRING);
				$params["selectedRecurringDetailReference"] = $recurring_detail_ref;
				$params["shopperInteraction"] = "ContAuth";
			}
			else {
				throw new \Error("No card data and no recurring contract found");
			}
		}
		
		$payment_service = new \Adyen\Service\Payment($this->client);
        $result = $payment_service->authorise($params);
		if ($result['resultCode'] == 'Authorised') {
			$model['authResult'] = 'CAPTURE';
			$model['authCode'] = $result['authCode'];
			$model['pspReference'] = $result['pspReference'];
			if (isset($result['additionalData'])) {
				$additionalData = $result['additionalData'];
				$model['additionalData'] = array('aliasType' => $additionalData['aliasType'], 'alias' => $additionalData['alias']);
			}
		}
        return $result;
    }

	public function listRecurringContract($model) {
		$params = array(
                        "merchantAccount"=> $this->options['merchantAccount'],
						"recurring" => array("contract" => \Adyen\Contract::RECURRING),
						"shopperReference" => $model["shopperReference"]
                        );

		$recurring_service = new \Adyen\Service\Recurring($this->client);
        $result = $recurring_service->listRecurringDetails($params);
		return $result['details'];
    }

}
