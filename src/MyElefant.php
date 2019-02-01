<?php
/**
 * Class MyElefant
 * For send sms with myElefant service
 * https://myelefant.com/
 */


namespace myelefant;

use Dotenv;
use GuzzleHttp\Client;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\FirePHPHandler;
use Symfony\Component\Yaml\Yaml;
use DateTime;
use Exception;

class MyElefant
{

    /**
     * @var logger
     */
    private $error;

    /**
     * @var logger
     */
    private $info;

    /**
     * @var array
     */
    private $dotenv;

    /**
     * @var array
     */
    private $yamlDatas;

    /**
     * @var array
     */
    private $contacts;

    public function __construct(){
        $this->yamlDatas = Yaml::parseFile(__DIR__.'/../MyElefant.yaml');
        if ($this->yamlDatas['APP_ENV'] == 'dev') {
            $this->error = $this->initLogger('error',__DIR__.'/../log/error.log');
            $this->info = $this->initLogger('info',__DIR__.'/../log/info.log');
        }
            $this->dotenv = $this->initDotEnv();
    }
    
    /**
     * Initialize Dotenv
     * @return array
     */
    public function initDotEnv(){
        try {
            $dotenv = Dotenv\Dotenv::create(__DIR__.'/..');
            return $dotenv->load();
        } catch (\Throwable $e) {
            $this->setLog('critical',$e->getMessage());
            throw new Exception($e->getMessage());
        }


    }

    /**
     * Initialize Logger
     *
     * @param string $name
     * @param string $path
     * @return self
     */
    public function initLogger(string $name,string $path){
        $logger = new Logger($name);
        $logger->pushHandler(new StreamHandler($path));
        return $logger;
        
    }

    /**
     * @param string|DateTime format Y-m-d H:i | null
     * @return string
     */

    public function getDate($date = null){

            $currentDate = new DateTime();
            $currentDate = $currentDate->format('Y-m-d H:i');

            if ($date != null ){
                if (DateTime::createFromFormat('Y-m-d H:i', $date) !== false) {
                    try {
                        $date = new DateTime($date);

                    } catch (\Throwable $e) {
                        $this->setLog('critical',$e->getMessage());
                        throw new Exception($e->getMessage());
                    }

                    $date = $date->format('Y-m-d H:i');
                    if ($date > $currentDate){
                        return $date;
    
                    }else {
                        throw new Exception( $this->yamlDatas['CRITICAL_MESSAGE_DATE']);
                        $this->setLog('critical',$this->yamlDatas['CRITICAL_MESSAGE_DATE']);
    
                    }               
                }else{
                    throw new Exception( $this->yamlDatas['CRITICAL_MESSAGE_DATE_FORMAT']);
                    $this->setLog('critical',$this->yamlDatas['CRITICAL_MESSAGE_DATE_FORMAT']);
                }
            }
        return $currentDate;
    }

    /**
     * @param string privateKey MyElefant secret key
     * @return string access_token 
     */

    public function getAuthentification($secretKey){

        $headers = ['Authorization' => 'Basic '.$secretKey];
        $client = new Client();
        $response = $client->request('POST', $this->yamlDatas['URL_MYELEFANT_API'].$this->yamlDatas['URL_MYELEFANT_API_AUTHENTIFICATION'],['headers' => $headers]); 
        if($response->getStatusCode() == 200) {
            $body = $response->getBody();
            $arr_body = json_decode($body);
            return $arr_body->access_token;

        }
    } 

    /**
     * @param array contact [[33612345678]]
     * @param string|null Send date '2019-01-20 12:00' if empty Date = now
     * @param string|null Message to send
     * @param string|null Sender
     * @return void
     */

    public function sendSms(array $contacts, $sendDate=null, $message = null, $sender = null){
        
        if (!$this->checkFields($message,$sender)) {
            $this->setLog('critical',$this->yamlDatas['CRITICAL_MESSAGE_EMPTY_MESSAGE']);
            throw new Exception($this->yamlDatas['CRITICAL_MESSAGE_EMPTY_MESSAGE']);
        }
        try {
            $client = new Client();
            $response = $client->request('POST', $this->yamlDatas['URL_MYELEFANT_API'].$this->yamlDatas['URL_MYELEFANT_API_CREATE_CAMPAIGN'],
                [ 
                'headers' =>['Authorization' =>$this->getAuthentification(getenv('SECRET_KEY')), 'Content-Type'=>'application/json'],
                'json'=>[
                    'logic_param' =>getenv('CAMPAIGN_LEAD_ID'),
                    'name' => $this->yamlDatas['CAMPAIGN_NAME'],
                    'contacts'=> $this->getContact($contacts),
                    'send_date' => $this->getDate($sendDate),
                    'logic' =>$this->yamlDatas['LOGIC'],
                    'message' => $this->getMessage($message),
                    'sender' => $this->getSender($sender, $message)
                ]                    
            ]); 

        } catch (\Throwable $e) {
            $this->setLog('critical',$e->getMessage());
            throw new Exception($e->getMessage());
        }
            if($response->getStatusCode() == 200) {
                $body = $response->getBody();
                $arr_body = json_decode($body);
                if ($arr_body->success == true) {
                    $this->setLog('info',$this->yamlDatas['SUCCESS_MESSAGE'].', '.$this->yamlDatas['CREDIT_REMAINING'].' '.$arr_body->solde);

                }
            }
    } 

    /**
     * @param string
     * @return string
     */

    public function getMessage($message){

        if(strlen($message) > $this->yamlDatas['MAX_LENGTH_MESSAGE'] ){
                $this->setLog('warning',$this->yamlDatas['WARNING_MESSAGE_LENGTH']);
                throw new Exception($this->yamlDatas['WARNING_MESSAGE_LENGTH']);
            } 
        return $message;
    }

    /**
     * @param array
     * @return array|null
     */

    public function getContact(array $contacts){

            if (is_array($contacts) && $this->checkContactsFormat($contacts)) {
                foreach ($contacts as $key) {
                    if(!$this->checkPhoneNumber($key[0])){
                        $this->setLog('critical',$this->yamlDatas['CRITICAL_MESSAGE_PHONE_NUMBER_FORMAT']);
                        throw new Exception($key[0].' '. $this->yamlDatas['CRITICAL_MESSAGE_PHONE_NUMBER_FORMAT']);

                    }
                }                
            }else {
                $this->setLog('critical',$this->yamlDatas['CRITICAL_MESSAGE_CONTACT_FORMAT']);
                throw new Exception($this->yamlDatas['CRITICAL_MESSAGE_CONTACT_FORMAT']);

            }  
        return $contacts;
    }

    /**
     * @param string
     * @return bool 
     */

    public function checkPhoneNumber(string $phoneNumber){
        if(preg_match($this->yamlDatas['REGEX_PHONE_NUMBER'],$phoneNumber)){
            return true;

        }
        return false;
    }

    /**
     * @param string $log level
     * @param string $message
     * @return void
     */

    public function setLog(string $logLevel, string $message){
        if ( isset($this->error) && isset($this->info) ) {
            switch ($logLevel) {
                case 'critical':
                    $this->error->critical($message);
                    break;
                case 'warning':
                    $this->error->warning($message);
                    break;
                default:
                    $this->info->info($message);
                    break;
            }
        }
    }


    /**
     * @param string|null
     * @param string|null
     * @return string|void
     */

    public function getSender($sender, $message){

        if ($sender == null && $message == null) {
            return;

        }elseif ($sender == null && $message != null) {
            return $this->yamlDatas['DEFAULT_SENDER'];

        }
        return $sender;
    }

    /**
     * @param string|null
     * @param string|null
     * @return bool
     */

    public function checkFields($message, $sender){
        if ($sender != null && $message == null) {
            return false;
        }
        return true;
    }

    /**
     * @param array
     * @return bool
     */
    public function checkContactsFormat(array $contacts){
        foreach ($contacts as $key) {
            if (!is_array($key)) {
                return false;
            }
        }
        return true;
    }

}





