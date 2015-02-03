<?php
namespace Inteleon\Ipx;

use Inteleon\Soap\Client as InteleonSoapClient;
use Inteleon\Soap\Exception\ClientException as InteleonSoapClientException;
use SoapFault;
use Exception;

class Client
{
	/** @var SoapClient */
	protected $soap_client;

	/** @var string IPX Username */
	protected $username;

	/** @var string IPX password */
	protected $password;

	/** @var int The number of milliseconds to wait while trying to connect. */
	protected $connect_timeout;

	/** @var int The maximum number of milliseconds to allow execution */
	protected $timeout;

	/** @var int Number of connect attempts to be made if connection error occurs */
	protected $connect_attempts;

	/** @var boolean Verify IPX certificate */
	protected $verify_certificate;

	/** @var boolean Cache the WSDL */
	protected $cache_wsdl;

	/** @var string How does IPX send incomming messages/DR to me - POST or GET */
	protected $http_method = 'post';
	
	/** @var string Tariff class, SEK0 is for standard message to Sweden */
	protected $tariffClass = 'SEK0';

	/**
	 * Constructor
	 *
	 * @param string $username IPX username
	 * @param string $password IPX Password
	 * @param integer $connect_timeout Connect timeout in milliseconds
	 * @param integer $timeout Timeout in milliseconds
	 * @param int $connect_attempts Number of connect attempts
	 * @param boolean $verify_certificate Verify IPX certificate
	 * @param boolean $cache_wsdl Cache the WSDL
	 */
	public function __construct(
		$username,
		$password,
		$connect_timeout = 30000,
		$timeout = 30000,
		$connect_attempts = 1,
		$verify_certificate = true,
		$cache_wsdl = true
	) {
		$this->username = $username;
		$this->password = $password;
		$this->connect_timeout = $connect_timeout;
		$this->timeout = $timeout;
		$this->connect_attempts = $connect_attempts;
		$this->verify_certificate = $verify_certificate;
		$this->cache_wsdl = $cache_wsdl ? WSDL_CACHE_BOTH : WSDL_CACHE_NONE;
	}

	/**
	 * Set how IPX delivers SMS/delivery reports to you. (post or get).
	 *
	 * @param string $http_method post or get
	 */
	public function setHttpMethod($http_method)
	{
		$this->http_method = $http_method;
	}

	/**
	 * Set IPX tariff class. SEK0 is for standard message to Sweden
	 *
	 * @param string $tariffClass
	 */
	public function setTariffClass($tariffClass)
	{
		$this->tariffClass = $tariffClass;
	}

	/**
	 * Set Soap Client. If you set a SoapClient then the options passed in the
	 * constructor will be ignored.
	 *
	 * @param SoapClient $soap_client
	 */
	public function setSoapClient(SoapClient $soap_client)
	{
		$this->soap_client = $soap_client;
	}

	public function getSoapClient()
	{	
		if ($this->soap_client) {
			return $this->soap_client;
		}

		try {
			$soap_client = new InteleonSoapClient('https://europe.ipx.com/api/services2/SmsApi52?wsdl', array(
				'exceptions' => true,
				'trace' => false,
				'cache_wsdl' => $this->cache_wsdl,
				'connect_timeout' => ($this->connect_timeout / 1000),			
			));		
			$soap_client->setTimeout($this->timeout);
			$soap_client->setConnectTimeout($this->connect_timeout);
			$soap_client->setConnectAttempts($this->connect_attempts);
			$soap_client->setVerifyCertificate($this->verify_certificate);

			return $this->soap_client = $soap_client;
			
		} catch (SoapFault $sf) {

			$soap_fault_text = "Cannot create SOAP-client" . PHP_EOL . "SOAP-faultcode: " . $sf->faultcode . PHP_EOL . 'SOAP-faultstring: ' . $sf->faultstring;			
			throw new Exception($soap_fault_text);

        } catch (InteleonSoapClientException $e) {

            throw new Exception('Connection error: ' . $e->getMessage());          
        }  					
	}

	/**
	 * Send SMS. This class does handle sending of both message to a single
	 * recipient and to multiple recipients. It does also handle
	 * concatenation of "long" messages  (>160 char). Therefore the result
	 * from this method is an array containg details on all messages sent.
	 *
	 * @param string $message Message (In UTF-8)
	 * @param string $recipient Recipient
	 * @param string $sender Sender
	 * @param array $options Options
	 * @return array Result
	 */
	public function sendSMS($message, $recipient, $sender, array $options = array())
	{
		$result = array();
		
		//originatorTON
		$originatorTON = isset($options['originatorTON']) ? $options['originatorTON'] : "1"; //Default is Alpha numeric (max length 11)
		
		//DCS
		if (isset($options['DCS'])) {
			$DCS = $options['DCS'];	
		} elseif(isset($options['flash']) && $options['flash'] == true) {
			$DCS = 16;	
		} else {
			$DCS = 17; //GSM Default alphabet
		}
		
		$statusReportFlags = (isset($options['delivery_report']) && $options['delivery_report'] ? '1' : '0');
		$relativeValidityTime = (isset($options['validity_time']) && $options['validity_time'] ? $options['validity_time'] : '-1');
		
		$params = array(
			'correlationId' 		=> '#NULL#',
			'originatingAddress'	=> $sender,
			'originatorTON'			=> $originatorTON,
			'destinationAddress'	=> '#NULL#', //Is set later
			'userData'				=> '#NULL#', //Is set later
			'userDataHeader'		=> '#NULL#',
			'DCS'					=> $DCS,
			'PID'					=> '-1',
			'relativeValidityTime'	=> $relativeValidityTime,
			'deliveryTime'			=> '#NULL#',
			'statusReportFlags'		=> $statusReportFlags,
			'accountName'			=> '#NULL#',
			'tariffClass'			=> $this->tariffClass, 
			'VAT'					=> '-1',
			'referenceId'			=> '#NULL#',
			'serviceName'			=> '#NULL#',
			'serviceCategory'		=> '#NULL#',
			'serviceMetaData'		=> '#NULL#',
			'campaignName'			=> '#NULL#',
			'username'				=> $this->username,
			'password'				=> $this->password,
		);
		
		if (!mb_detect_encoding($message, 'UTF-8', true)) {
			$result[] = array('error' => "Cannot send SMS (pre-send error)" . PHP_EOL . "Message does not look like UTF-8");
			return $result;
		}
		
		if (!$this->validateGSM7($message)) {
			$result[] = array('error' => "Cannot send SMS (pre-send error)" . PHP_EOL . "Message contains invalid characters");
			return $result;
		}
				
		//Check for characters in GSM extended alphabhet = counts as 2 characters
		$max_len = 160 - preg_match_all('/[\f\^\{\}\\\[\~\]\|€]/u', $message, $matches);
		
		//Split message if > 160 characters
		$message_len = mb_strlen($message, 'UTF-8');
		if ($message_len > $max_len) {
			
			$max_len -= 7; //Max len is shorter if concatenated messages
			
			$msg_parts = array();
			for ($i = 0; $i < $message_len; $i += $max_len) {
				$msg_parts[] = mb_substr($message, $i, $max_len, "UTF-8");	
			}
					
			$udh = array(
				'UDHL'		=> '05', //User Data Header Length - sms udh lenth 05 for 8bit udh, 06 for 16 bit udh
				'IEI'		=> '00', //Information Element Identifier - use 00 for 8bit udh, use 08 for 16bit udh
				'IEDL'		=> '03', //IE Data Length - length of header UDHL & IEI
				'reference'	=> $this->dechex_str(mt_rand(1, 255)), //Reference number of concatenated messages, use 2bit 00-ff if 8bit udh, use 4bit 0000-ffff if 16bit udh
				'msg_count'	=> $this->dechex_str(count($msg_parts)), //Number of concatenated messages
				'msg_part'	=> $this->dechex_str(1), //Which concatenated message
			);
			
			$params['userDataHeader'] = implode('', $udh);
			
		} else {
			$msg_parts = array($message);
			
			$params['userDataHeader'] = '#NULL#';
		}
	
		//Maximum number of concatinated messages
		if (count($msg_parts) > 5) {
			$result[] = array('error' => "Max 5 concatinated messages");
			return $result;			
		}
		
		foreach ($msg_parts as $key => $msg_part) {

			//Set recipient(s) string or array
			$recipient_string = is_array($recipient) ? implode(";", $recipient) : $recipient;
			$params['destinationAddress'] = $recipient_string;
			
			//Set userDataHeader with current sequence number if sending concatinated messages
			if ($params['userDataHeader'] != '#NULL#') {
				$params['userDataHeader'] = substr_replace($params['userDataHeader'], $this->dechex_str($key+1), 10, 2);
			}
			
			//The message data
			$params['userData'] = $msg_part;

			try {
				$timer_start = microtime(true);
				$soap_response = $this->getSoapClient()->__soapCall('send', array('request' => $params));
				//echo round((microtime(true)-$timer_start), 3) . "\n";
				//print_r($soap_response);
			
			} catch (SoapFault $sf) {

				$soap_fault_text = "Cannot send SMS (SOAP error)" . PHP_EOL . "SOAP-faultcode: " . $sf->faultcode . PHP_EOL . "SOAP-faultstring: " . $sf->faultstring;
				$result[]['error'] = $soap_fault_text;
				break;	

	        } catch (InteleonSoapClientException $e) {

				$result[]['error'] = $e->getMessage();
				break;          
	        }
			
			//Successful send?
			if ($soap_response->responseCode != 0 && $soap_response->responseCode != 50) {
				$result[]['error'] = "Cannot send SMS (IPX error)" . PHP_EOL . "IPX-responseCode: " . $soap_response->responseCode . PHP_EOL . "IPX-responseMessage: " . $soap_response->responseMessage . " (" . $this->getSendResponseCodeDescription($soap_response->responseCode) . ")" . ($soap_response->reasonCode ? PHP_EOL . "IPX-reasonCode: " . $soap_response->reasonCode . " (" . $this->getSendReasonCodeDescription($soap_response->reasonCode) . ")" : "");
				break;
			}
			
			//Remove recipients where reseponseCode is not zero
			if ($soap_response->responseCode == 50) {
				preg_match('/Partial success: \((.*?)\)/', $soap_response->responseMessage, $matches);
				$response_codes = explode(';', $matches[1]);
				foreach ($response_codes as $key => $val) {
					if ($val != "0") {
						unset($recipient[$key]);
					}
				}
			}
			
			$result[] = array(
				//IPX data
				'correlationId'		=> isset($soap_response->correlationId) ? $soap_response->correlationId : '',
				'messageId' 		=> $soap_response->messageId,
				'responseCode'		=> $soap_response->responseCode,
				'reasonCode'		=> isset($soap_response->reasonCode) ? $soap_response->reasonCode : 0,
				'responseMessage'	=> $soap_response->responseMessage,
				'temporaryError'	=> $soap_response->temporaryError,
				'billingStatus'		=> $soap_response->billingStatus,
				'VAT'				=> $soap_response->VAT,
				//Custom data
				'recipient' 		=> $recipient_string, //To be able to match multiple responseCodes with multiple recipients
			);
		}

		return $result;
	}
	
	/**
	 * Get Send Response Code Text
	 *
	 * @param int $code Code
	 * @return string Description
	 */
	public static function getSendResponseCodeDescription($code)
	{
		if (!is_numeric) {
			return '';
		}
		
		$code = (int)$code;
		
		switch($code) {
			case 0:
				return 'Successfully executed.';
				break;
			case 1:
				return 'Incorrect username or password or Service Provider is barred by IPX.';
				break;
			case 2:
				return 'The Consumer is blocked by IPX, i.e. blocked for premium services or this specific service.';
				break;
			case 3:
				return 'The operation is blocked for the Service Provider.';
				break;
			case 4:
				return 'The Consumer is unknown to IPX. Or if alias was used in the request; alias not found.';
				break;
			case 5:
				return 'The Consumer has blocked this service in IPX.';
				break;
			case 6:
				return 'The originating address is not supported by account, e.g. the Short code is not provisioned for the destination operator.';
				break;
			case 7:
				return 'The alpha originating address is not supported by account.';
				break;
			case 8:
				return 'The MSISDN originating address not supported by account.';
				break;
			case 9:
				return 'GSM extended not supported by account.';
				break;
			case 10:
				return 'Unicode not supported by account.';
				break;
			case 11:
				return 'Status report not supported by account.';
				break;
			case 12:
				return 'The required capability (other than the above) for sending the message is not supported.';
				break;
			case 13:
				return 'IPX could not route the SMS message to an Operator.';
				break;
			case 14:
				return 'The Service Provider is sending the SMS messages to IPX too fast.';
				break;
			case 15:
				return 'The Operator is currently receiving too many SMS messages.';
				break;
			case 16:
				return 'Protocol ID not supported by account.';
				break;
			case 17:
				return 'The Service Provider is exceeding the charging frequency limit provided to IPX during the subscription setup. Only applicable for subscription references.';
				break;
			case 50:
				return 'Partial success when sending an SMS message to multiple recipients. The response message field contains a list of response codes where the position of the response code correlates to the position of the MSISDN in the request.';
				break;
			case 99:
				return 'Other IPX error, contact IPX support for more information.';
				break;
			case 100:
				return 'The destination address (MSISDN, or alias) is invalid.';
				break;
			case 101:
				return 'The tariff class is invalid, or not registered by IPX.';
				break;
			case 102:
				return 'The reference ID is invalid, maybe the reference ID is already used, too old or unknown.';
				break;
			case 103:
				return 'The account name is invalid.';
				break;
			case 104:
				return 'The service category is invalid.';
				break;
			case 105:
				return 'The service meta data is invalid.';
				break;
			case 106:
				return 'The originating address (short code) is invalid.';
				break;
			case 107:
				return 'The alphanumeric (including MSISDN) originating address is invalid.';
				break;
			case 108:
				return 'The validity time is invalid.';
				break;
			case 109:
				return 'The delivery time is invalid.';
				break;
			case 110:
				return 'The user data, i.e. the SMS message, is invalid.';
				break;
			case 111:
				return 'The SMS message length is invalid.';
				break;
			case 112:
				return 'The user data header is invalid.';
				break;
			case 113:
				return 'The DCS is invalid.';
				break;
			case 114:
				return 'The PID is invalid.';
				break;
			case 115:
				return 'The status report flags are invalid.';
				break;
			case 116:
				return 'The originator TON is invalid.';
				break;
			case 117:
				return 'The VAT is invalid or not supported by the destination Operator.';
				break;
			case 118:
				return 'The campaign name is invalid.';
				break;
			case 119:
				return 'The service name is invalid.';
				break;
			case 200:
				return 'Operator integration error, reason code may apply.';
				break;
			case 201:
				return 'Operation failed due to communication error with the Operator, The operation failed.';
				break;
			case 202:
				return 'Operation failed due to communication error with the Operator, read timeout during operation request. The operation status unknown.';
				break;
			case 299:
				return 'Other Operator integration error.';
				break;
			default:
				return '[UNKNOWN RESPONSE CODE, CHECK IPX MANUAL]';								
		}
	}
	
	/**
	 * Get send reason code description
	 *
	 * @param int $code Code
	 * @return string Description
	 */
	public static function getSendReasonCodeDescription($code)
	{
		if (!is_numeric) {
			return '';
		}
		
		$code = (int)$code;
		
		switch($code) {
			case 0:
				return 'Reason code is not applicable.';
				break;
			case 1000:
				return 'Response from the Operator, the Operator does not recognize the Consumer.';
				break;
			case 1001:
				return 'Response from the Operator, the Consumer is blocked (for these types of services).';
				break;
			case 1002:
				return 'Response from the Operator, the Consumer cannot fulfil the purchase.';
				break;
			case 1003:
				return 'Response from the Operator, the operation is rejected.';
				break;
			case 1005:
				return 'Response from the Operator, the operation is rejected.';
				break;
			case 1006:
				return 'The requested charging operation cannot be performed by IPX.';
				break;
			case 1007:
				return 'Response from the Operator, the subscriber is blocked for this service.';
				break;
			case 1008:
				return 'Response from the Operator, the subscriber must register at the Operator to enable the service.';
				break;
			case 1009:
				return 'Response from the Operator, the subscription is terminated (applicable in case the reference ID refers to a subscription).';
				break;
			case 1010:
				return 'Response from the Operator, the Consumer has been blocked by a parental block.';
				break;
			case 1011:
				return 'Response from the Operator, the Consumer’s accumulated amount spent has exceeded the Operator limit. .';
				break;
			case 1012:
				return 'Response from the Operator, the Consumer’s accumulated amount spent has exceeded the Consumer specific limit.';
				break;
			case 1013:
				return 'Response from the Operator, in case the Operator provides alternate payment methods for the Consumer (e.g. credit card), this alternate method failed.';
				break;
			case 1014:
				return 'Response from the Operator, the charging event was rejected by the Operator due to too frequent charging events.';
				break;
			case 1015:
				return 'Response from Operator, the charging event violates the operator constraints.';
				break;
			case 1016:
				return 'Response from the Operator, the subscriber is temporary blocked for this service.';
				break;
			default:
				return '[UNKNOWN REASON CODE, CHECK IPX MANUAL]';								
		}
	}	
	
	/**
	 * Get Delivery report (sent as a POST or GET to yout server)
	 *
	 * @return array
	 */
	public function getDeliveryReport()
	{
		if (!isset($_POST['MessageId'])) {
			return false;	
		}
	
		$result = array(
			'MessageId' 			=> $this->getParam('MessageId'),
			'DestinationAddress'	=> $this->getParam('DestinationAddress'),
			'StatusCode' 			=> $this->getParam('StatusCode'),
			'TimeStamp' 			=> $this->getParam('TimeStamp'),
			'Operator'				=> $this->getParam('Operator'),
			'ReasonCode'			=> $this->getParam('ReasonCode', 0),
			'OperatorTimeStamp' 	=> $this->getParam('OperatorTimeStamp', ''),
			'StatusText' 			=> $this->getParam('StatusText', ''),
		);
		
		return $result;
	}
	
	/**
	 * Get Delivery report status code description
	 *
	 * @param int $code Code
	 * @return string Description
	 */
	public static function getDeliveryReportStatusCodeDescription($code)
	{
		if (!is_numeric) {
			return '';
		}
		
		$code = (int)$code;
		
		switch($code) {
			case 0:
				return 'Delivered';
				break;
			case 2:
				return 'Deleted';
				break;								
			default:
				return '[UNKNOWN STATUS CODE, CHECK IPX MANUAL]';			
		}
	}

	/**
	 * Get delivery report reason code description
	 *
	 * @param int $code Code
	 * @return string Description
	 */
	public static function getDeliveryReportReasonCodeDescription($code)
	{
		if (!is_numeric) {
			return '';
		}
		
		$code = (int)$code;
		
		switch($code) {	
			case 100:
				return 'Expired.';
				break;
			case 101:
				return 'Rejected.';
				break;
			case 102:
				return 'Format error.';
				break;
			case 103:
				return 'Other error.';
				break;
			case 110:
				return 'Subscriber unknown.';
				break;
			case 111:
				return 'Subscriber barred.';
				break;
			case 112:
				return 'Subscriber not provisioned.';
				break;
			case 113:
				return 'Subscriber unavailable.';
				break;
			case 120:
				return 'SMSC failure.';
				break;
			case 121:
				return 'SMSC congestion.';
				break;
			case 122:
				return 'SMSC roaming.';
				break;
			case 130:
				return 'Handset error.';
				break;
			case 131:
				return 'Handset memory exceeded .';
				break;
			case 140:
				return 'Charging error.';
				break;
			case 141:
				return 'Charging balance too low.';
				break;								
			default:
				return '[UNKNOWN REASON CODE, CHECK IPX MANUAL]';			
		}
	}
	
	/**
	 * Get SMS (sent as a POST or GET to your server)
	 *
	 * @return array
	 */
	public function getSMS()
	{
		if (!isset($_POST['MessageId'])) {
			return false;	
		}

		$result = array(
			'DestinationAddress' 	=> $this->getParam('DestinationAddress'),
			'OriginatorAddress'		=> $this->getParam('OriginatorAddress'),
			'Message' 				=> $this->getParam('Message'),
			'MessageId' 			=> $this->getParam('MessageId'),
			'TimeStamp'				=> $this->getParam('TimeStamp'), //Time of arrival of the SMS message at the SMSC of the Operator. CET or CEST (with summer time as defined for the EU).
			'Operator'				=> $this->getParam('Operator'),
			//'UserDataHeader' 		=> $this->getParam('UserDataHeader', ''), //Access to this parameter must be provisioned by IPX support
			//'MessageAlphabet' 	=> $this->getParam('MessageAlphabet', ''), //Access to this parameter must be provisioned by IPX support
		);
		
		return $result;
	}
	
	/**
	 * Get Acknowledgement Text (used for generating text response on incoming
	 * SMS or delivery report)
	 *
	 * @param bool $ack
	 * @return string
	 */
	public static function getAcknowledgementText($ack = true)
	{
		$ack = $ack ? 'true' : 'false';
		return '<DeliveryResponse ack="' . $ack . '"/>';
	}
	
	/**
	 * Get a specific param on incoming SMS or delivery report.
	 *
	 * @param [type] $name [description]
	 * @param [type] $default_value [description]
	 * @return [type] [description]
	 */
	protected function getParam($name, $default_value = null)
	{
		if ($this->http_method == 'get') {
			return isset($_GET[$name]) ? $_GET[$name] : $default_value;
		}
		return isset($_POST[$name]) ? $_POST[$name] : $default_value; //Standard is post
	}

	/**
	 * Convert decimal to zerofilled hexadecimal
	 *
	 * @param int $ref decimal
	 * @return string zerofilled hexadecimal
	 * @see http://www.phpclasses.org/browse/file/34647.html
	 */
	public function dechex_str($ref) 
    { 
        return ($ref <= 15 )?'0'.dechex($ref):dechex($ref); 
    }
	
	/**
	 * Validate a string for GSM-7
	 *
	 * @param string $string String
	 * @return bool
	 * @see http://michaelsanford.com/php-regex-for-gsm-7-03-38/
	 */
	public function validateGSM7($string)
	{
		return (preg_match('/^[\x{20}-\x{7E}£¥èéùìòÇ\rØø\nÅåΔ_ΦΓΛΩΠΨΣΘΞ\x{1B}ÆæßÉ ¤¡ÄÖÑÜ§¿äöñüà\x{0C}€]*$/u', $string) === 1);
	}
}