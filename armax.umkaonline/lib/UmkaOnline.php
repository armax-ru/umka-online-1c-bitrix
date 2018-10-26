<?

namespace Armax;

use Bitrix\Main;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Localization;

use Bitrix\Catalog;

use Bitrix\Sale\Result;

use Bitrix\Sale\Cashbox\Cashbox;
use Bitrix\Sale\Cashbox\Check;
use Bitrix\Sale\Cashbox\CheckManager;
use Bitrix\Sale\Cashbox\Internals\CashboxTable;
use Bitrix\Sale\Cashbox\IPrintImmediately;
use Bitrix\Sale\Cashbox\ICheckable;
use Bitrix\Sale\Cashbox\SellCheck;
use Bitrix\Sale\Cashbox\SellReturnCashCheck;
use Bitrix\Sale\Cashbox\SellReturnCheck;
use Bitrix\Sale\Cashbox\AdvancePaymentCheck;
use Bitrix\Sale\Cashbox\AdvanceReturnCashCheck;
use Bitrix\Sale\Cashbox\AdvanceReturnCheck;
use Bitrix\Sale\Cashbox\CreditCheck;
use Bitrix\Sale\Cashbox\CreditReturnCheck;
use Bitrix\Sale\Cashbox\CreditPaymentCheck;
use Bitrix\Sale\Cashbox\Errors\Warning;
use Bitrix\Sale\Cashbox\Errors\Error;

use \Bitrix\Main\Diag\Debug;



class UmkaOnline extends Cashbox implements IPrintImmediately, ICheckable
{
    const SERVICE_URL = 'https://umka365.ru/kkm-trade/atolpossystem/v4';
    const TOKEN_OPTION_NAME = 'umkaonline_access_token';
    const UUID_DELIMITER = '-';
    const OPERATION_CHECK_REGISTRY = 'registry';
    const OPERATION_CHECK_CHECK = 'check';
    const REQUEST_TYPE_GET = 'get';
    const REQUEST_TYPE_POST = 'post';
    const RESPONSE_HTTP_CODE_401 = 401;
    const RESPONSE_HTTP_CODE_200 = 200;
    const ERROR_LOGS_DIR = '/umkaonline';

    /**
     * @param  string $client_phone
     * @return string
     */
    private function normalizePhone($client_phone)
    {
        $phone = \NormalizePhone($client_phone);
        if (is_string($phone))
        {
            if ($phone[0] === '7')
                $phone = substr($phone, 1);
        }
        else
        {
            $phone = '';
        }
        return $phone;
    }

    /**
     * @param Check $check
     * @return array
     * @throws \Bitrix\Main\SystemException
     * @throws Main\LoaderException
     */
    public function buildCheckQuery(Check $check)
    {

        $data = $check->getDataForCheck();
        $this->sendToErrorLog('Чек', $data);

        /** @var Main\Type\DateTime $dateTime */
        $dateTime = $data['date_create'];
        $phone = $this->normalizePhone($data['client_phone']);

        $serviceEmail = $this->getValueFromSettings('SERVICE', 'EMAIL');
        if (!$serviceEmail)
        {
            $serviceEmail = static::getDefaultServiceEmail();
        }

        $result = array(
            'timestamp' => $dateTime->format('d.m.Y H:i:s'),
            'external_id' => static::buildUuid(static::UUID_TYPE_CHECK, $data['unique_id']),
            'service' => array(
                'callback_url' => $this->getCallbackUrl(),
            ),
            'receipt' => array(
                'client' => array(
                    'email' => $data['client_email'] ?: '',
                    'phone' => $phone,
                ),
                'company' => array(
                    'email' => $serviceEmail,
                    'sno' => $this->getValueFromSettings('TAX', 'SNO'),
                    'inn' => $this->getValueFromSettings('SERVICE', 'INN'),
                    'payment_address' => $this->getValueFromSettings('SERVICE', 'P_ADDRESS'),
                ),
                'payments' => array(),
                'items' => array(),
                'total' => (float)$data['total_sum']
            )
        );

        $paymentTypeMap = $this->getPaymentTypeMap();
        foreach ($data['payments'] as $payment)
        {
            $result['receipt']['payments'][] = array(
                'type' => $paymentTypeMap[$payment['type']],
                'sum' => (float)$payment['sum']
            );
        }

        $checkTypeMap = $this->getCheckTypeMap();
        foreach ($data['items'] as $i => $item)
        {

            $vat = $this->getValueFromSettings('VAT', $item['vat']);
            if ($vat === null)
                $vat = $this->getValueFromSettings('VAT', 'NOT_VAT');

            $result['receipt']['items'][] = array(
                'name' => $item['name'],
                'price' => (float)$item['price'],
                'sum' => (float)$item['sum'],
                'quantity' => $item['quantity'],
                'payment_method' => $checkTypeMap[$check::getType()],
                'payment_object' => 'commodity',
                'vat' => array(
                    'type' => $vat
                ),
            );
        }
        return $result;
    }


    /**
     * @return array
     */
    private function getPaymentTypeMap()
    {
        return array(
            Check::PAYMENT_TYPE_CASH => 4,
            Check::PAYMENT_TYPE_CASHLESS => 1,
            Check::PAYMENT_TYPE_ADVANCE => 2,
            Check::PAYMENT_TYPE_CREDIT => 3,
        );
    }

    /**
     * @return string
     * @throws Main\ArgumentNullException
     * @throws Main\ArgumentOutOfRangeException
     */
    private static function getDefaultServiceEmail()
    {
        return Main\Config\Option::get('main', 'email_from');
    }

    /**
     * @return array
     */
    protected function getCheckTypeMap()
    {
        return array(
            SellCheck::getType() => 'full_payment',
            SellReturnCashCheck::getType() => 'full_payment',
            SellReturnCheck::getType() => 'full_payment',
            AdvancePaymentCheck::getType() => 'advance',
            AdvanceReturnCashCheck::getType() => 'advance',
            AdvanceReturnCheck::getType() => 'advance',
            CreditCheck::getType() => 'credit',
            CreditReturnCheck::getType() => 'credit',
            CreditPaymentCheck::getType() => 'credit_payment',
        );
    }

    /**
     * @param $operation
     * @param $token
     * @param array $queryData
     * @return string
     * @throws Main\SystemException
     */
    protected function getUrl($operation, $token, array $queryData = array())
    {
        $groupCode = $this->getField('NUMBER_KKM');

        if ($operation === static::OPERATION_CHECK_REGISTRY)
        {
            return static::SERVICE_URL.'/'.$groupCode.'/'.$queryData['CHECK_TYPE'].'?token='.$token;
        }
        elseif ($operation === static::OPERATION_CHECK_CHECK)
        {
            return static::SERVICE_URL.'/'.$groupCode.'/report/'.$queryData['EXTERNAL_UUID'].'?token='.$token;
        }

        throw new Main\SystemException();
    }

    /**
     * @param int $modelId
     * @return array
     * @throws ArgumentException
     * @throws Main\ArgumentNullException
     * @throws Main\ArgumentOutOfRangeException
     * @throws Main\LoaderException
     * @throws Main\ObjectPropertyException
     * @throws Main\SystemException
     */
    public static function getSettings($modelId = 0)
    {
        $settings = array(
            'AUTH' => array(
                'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_AUTH'),
                'REQUIRED' => 'Y',
                'ITEMS' => array(
                    'LOGIN' => array(
                        'TYPE' => 'STRING',
                        'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_UMKAONLINE_SETTINGS_AUTH_LOGIN_LABEL')
                    ),
                    'PASS' => array(
                        'TYPE' => 'STRING',
                        'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_UMKAONLINE_SETTINGS_AUTH_PASS_LABEL')
                    ),
                )
            ),
            'SERVICE' => array(
                'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_SERVICE'),
                'REQUIRED' => 'Y',
                'ITEMS' => array(
                    'EMAIL' => array(
                        'TYPE' => 'STRING',
                        'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_SERVICE_EMAIL_LABEL'),
                        'VALUE' => static::getDefaultServiceEmail()
                    ),
                    'INN' => array(
                        'TYPE' => 'STRING',
                        'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_SERVICE_INN_LABEL')
                    ),
                    'P_ADDRESS' => array(
                        'TYPE' => 'STRING',
                        'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_SERVICE_URL_LABEL')
                    ),
                )
            )
        );

        $settings['VAT'] = array(
            'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_BITRIX_SETTINGS_VAT'),
            'REQUIRED' => 'Y',
            'ITEMS' => array(
                'NOT_VAT' => array(
                    'TYPE' => 'STRING',
                    'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_BITRIX_SETTINGS_VAT_LABEL_NOT_VAT'),
                    'VALUE' => 'none'
                )
            )
        );

        if (Main\Loader::includeModule('catalog'))
        {
            $dbRes = Catalog\VatTable::getList(array('filter' => array('ACTIVE' => 'Y')));
            $vatList = $dbRes->fetchAll();
            if ($vatList)
            {
                $defaultVat = array(0 => 'vat0', 10 => 'vat10', 18 => 'vat18');
                foreach ($vatList as $vat)
                {
                    $value = '';
                    if (isset($defaultVat[(int)$vat['RATE']]))
                        $value = $defaultVat[(int)$vat['RATE']];

                    $settings['VAT']['ITEMS'][(int)$vat['ID']] = array(
                        'TYPE' => 'STRING',
                        'LABEL' => $vat['NAME'].' ['.(int)$vat['RATE'].'%]',
                        'VALUE' => $value
                    );
                }
            }
        }

        $settings['TAX'] = array(
            'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_SNO'),
            'REQUIRED' => 'Y',
            'ITEMS' => array(
                'SNO' => array(
                    'TYPE' => 'ENUM',
                    'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_SNO_LABEL'),
                    'VALUE' => 'osn',
                    'OPTIONS' => array(
                        'osn' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SNO_OSN'),
                        'usn_income' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SNO_UI'),
                        'usn_income_outcome' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SNO_UIO'),
                        'envd' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SNO_ENVD'),
                        'esn' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SNO_ESN'),
                        'patent' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SNO_PATENT')
                    )
                )
            )
        );

        return $settings;
    }

    /**
     * @return string
     * @throws Main\SystemException
     */
    protected function getCallbackUrl()
    {
        $context = Application::getInstance()->getContext();
        $scheme = $context->getRequest()->isHttps() ? 'https' : 'http';
        $server = $context->getServer();
        $domain = $server->getServerName();

        if (preg_match('/^(?<domain>.+):(?<port>\d+)$/', $domain, $matches))
        {
            $domain = $matches['domain'];
            $port   = $matches['port'];
        }
        else
        {
            $port = $server->getServerPort();
        }
        $port = in_array($port, array(80, 443)) ? '' : ':'.$port;

        return sprintf('%s://%s%s/bitrix/tools/sale_farm_check_print.php', $scheme, $domain, $port);
    }


    /**
	 * @return string
	 */
	public static function getName()
	{
		return Localization\Loc::getMessage('SALE_UMKAONLINE_TITLE');
	}


    /**
     * @param array $data
     * @return array
     * @throws ArgumentException
     * @throws Main\NotImplementedException
     * @throws Main\ObjectException
     */
    protected static function extractCheckData(array $data)
    {
        $result = array();

        if (!$data['uuid'])
            return $result;

        $checkInfo = CheckManager::getCheckInfoByExternalUuid($data['uuid']);

        if ($data['error'])
        {
            $errorType = static::getErrorType($data['error']['code']);

            $result['ERROR'] = array(
                'CODE' => $data['error']['code'],
                'MESSAGE' => $data['error']['text'],
                'TYPE' => ($errorType === Error::TYPE) ? Error::TYPE : Warning::TYPE
            );
        }

        $result['ID'] = $checkInfo['ID'];
        $result['CHECK_TYPE'] = $checkInfo['TYPE'];

        $check = CheckManager::getObjectById($checkInfo['ID']);
        $dateTime = new Main\Type\DateTime($data['payload']['receipt_datetime']);
        $result['LINK_PARAMS'] = array(
            Check::PARAM_REG_NUMBER_KKT => $data['payload']['ecr_registration_number'],
            Check::PARAM_FISCAL_DOC_ATTR => $data['payload']['fiscal_document_attribute'],
            Check::PARAM_FISCAL_DOC_NUMBER => $data['payload']['fiscal_document_number'],
            Check::PARAM_FISCAL_RECEIPT_NUMBER => $data['payload']['fiscal_receipt_number'],
            Check::PARAM_FN_NUMBER => $data['payload']['fn_number'],
            Check::PARAM_SHIFT_NUMBER => $data['payload']['shift_number'],
            Check::PARAM_DOC_SUM => $data['payload']['total'],
            Check::PARAM_DOC_TIME => $dateTime->getTimestamp(),
            Check::PARAM_CALCULATION_ATTR => $check::getCalculatedSign()
        );

        return $result;
    }

    /**
     * @param $id
     * @return array
     */
    public function buildZReportQuery($id)
    {
        return array();
    }

    /**
     * @param array $data
     * @return array
     */
    protected static function extractZReportData(array $data)
    {
        return array();
    }

    /**
     * @param Check $check
     * @return Result
     * @throws \Bitrix\Main\SystemException
     * @throws Main\LoaderException
     */
    public function printImmediately(Check $check)
    {
        $printResult = new Result();
        $token = $this->getAccessToken();

        if ($token === '')
        {
            $token = $this->requestAccessToken();
            if ($token === '')
            {
                $this->sendToErrorLog('^^^ printImmediately: Пришел пустой токен доступа');
                $this->sendToErrorLog('printImmediately: Данные чека', $check);
                $printResult->addError(new Main\Error(Localization\Loc::getMessage('SALE_CASHBOX_ATOL_REQUEST_TOKEN_ERROR')));
                return $printResult;
            }
        }

        $checkQuery = static::buildCheckQuery($check);
        $validateResult = $this->validate($checkQuery);
        if (!$validateResult->isSuccess())
        {
            return $validateResult;
        }

        $operation = $check::getCalculatedSign() === Check::CALCULATED_SIGN_INCOME ? 'sell' : 'sell_refund';
        $url = $this->getUrl(static::OPERATION_CHECK_REGISTRY, $token, array('CHECK_TYPE' => $operation));
        $result = $this->send(static::REQUEST_TYPE_POST, $url, $checkQuery);

        if (!$result->isSuccess())
            return $result;

        $response = $result->getData();
        if ($response['http_code'] === static::RESPONSE_HTTP_CODE_401)
        {
            $token = $this->requestAccessToken();
            if ($token === '')
            {
                $printResult->addError(new Main\Error(Localization\Loc::getMessage('SALE_CASHBOX_ATOL_REQUEST_TOKEN_ERROR')));
                return $printResult;
            }

            $url = $this->getUrl(static::OPERATION_CHECK_REGISTRY, $token, array('CHECK_TYPE' => $operation));
            $result = $this->send(static::REQUEST_TYPE_POST, $url, $checkQuery);
            if (!$result->isSuccess())
                return $result;

            $response = $result->getData();
        }

        if ($response['http_code'] === static::RESPONSE_HTTP_CODE_200)
        {
            if ($response['uuid'])
            {
                $printResult->setData(array('UUID' => $response['uuid']));
            }
            else
            {
                $printResult->addError(new Main\Error(Localization\Loc::getMessage('SALE_CASHBOX_ATOL_CHECK_REG_ERROR')));
            }
        }
        else
        {
            if (isset($response['error']['text']))
            {
                $printResult->addError(new Main\Error($response['error']['text']));
            }
            else
            {
                $printResult->addError(new Main\Error(Localization\Loc::getMessage('SALE_CASHBOX_ATOL_CHECK_REG_ERROR')));
            }
        }

        return $printResult;
    }

    /**
     * @param $type
     * @param $id
     * @return string
     * @throws \Bitrix\Main\SystemException
     */
    public static function buildUuid($type, $id)
    {
		$context = Application::getInstance()->getContext();
		$server = $context->getServer();
		$domain = $server->getServerName();
		$domain = str_replace(".", "-", $domain);
		
		return $type.static::UUID_DELIMITER.$domain.static::UUID_DELIMITER.$id;
    }

    /**
     * @param Check $check
     * @return Result
     * @throws \Bitrix\Main\SystemException
     */
    public function check(Check $check)
    {
        $EXTERNAL_UUID = $check->getField('EXTERNAL_UUID');

        $url = $this->getUrl(
            static::OPERATION_CHECK_CHECK,
            $this->getAccessToken(),
            array('EXTERNAL_UUID' => $EXTERNAL_UUID)
        );


        $result = $this->send(static::REQUEST_TYPE_GET, $url);

        if (!$result->isSuccess())
            return $result;

        $response = $result->getData();

        if ($response['http_code'] === static::RESPONSE_HTTP_CODE_401)
        {
            $token = $this->requestAccessToken();
            if ($token === '')
            {
                $result->addError(new Main\Error(Localization\Loc::getMessage('SALE_CASHBOX_ATOL_REQUEST_TOKEN_ERROR')));
                return $result;
            }

            $url = $this->getUrl(
                static::OPERATION_CHECK_CHECK,
                $this->getAccessToken(),
                array('EXTERNAL_UUID' => $check->getField('EXTERNAL_UUID'))
            );

            $result = $this->send(static::REQUEST_TYPE_GET, $url);
            if (!$result->isSuccess())
                return $result;

            $response = $result->getData();
        }

        if ($response['status'] === 'wait')
        {
            $result->addError(new Main\Error(Localization\Loc::getMessage('SALE_CASHBOX_ATOL_REQUEST_STATUS_WAIT')));
            return $result;
        }

        return static::applyCheckResult($response);
    }

    /**
     * @param array $checkData
     * @return Result
     */
    protected function validate(array $checkData)
    {
        $result = new Result();

        if ($checkData['receipt']['client']['email'] === '' && $checkData['receipt']['client']['phone'] === '')
        {
            $result->addError(new Main\Error(Localization\Loc::getMessage('SALE_CASHBOX_ATOL_ERR_EMPTY_PHONE_EMAIL')));
        }

        foreach ($checkData['receipt']['items'] as $item)
        {
            if ($item['vat'] === null)
            {
                $result->addError(new Main\Error(Localization\Loc::getMessage('SALE_CASHBOX_ATOL_ERR_EMPTY_TAX')));
                break;
            }
        }

        return $result;
    }

    /**
     * @param $method
     * @param $url
     * @param array $data
     * @return Result
     * @throws ArgumentException
     */
    private function send($method, $url, array $data = array())
    {
        $result = new Result();

        $http = new Main\Web\HttpClient();
        $http->setHeader('Content-Type', 'application/json; charset=utf-8');

        if ($method === static::REQUEST_TYPE_POST)
        {
            $http->disableSslVerification();
            $data = $this->encode($data);
            $response = $http->post($url, $data);
        }
        else
        {
            $response = $http->get($url);
        }

        if ($response !== false)
        {

            try
            {
                $response = $this->decode($response);
                if (!is_array($response))
                    $response = array();
                $response['http_code'] = $http->getStatus();
                $result->addData($response);
            }
            catch (ArgumentException $e)
            {
                $this->sendToErrorLog('^^^ Не удалось разобрать ответ от umka365');
                $this->sendToErrorLog('Объект http', $http);
                $this->sendToErrorLog('Объект $response', $response);
                $this->sendToErrorLog('Метод отправки запроса', $method);
                $this->sendToErrorLog('Адресс запроса', $url);
                $this->sendToErrorLog('Отправляемые данные (если есть)', $data );

                $result->addError(new Main\Error($e->getMessage()));
            }
        }
        else
        {
            $this->sendToErrorLog('^^^ Отсутсвет ответ от umka365');
            $this->sendToErrorLog('Объект http', $http);
            $this->sendToErrorLog('Объект $response', $response);
            $this->sendToErrorLog('Метод отправки запроса', $method);
            $this->sendToErrorLog('Адресс запроса', $url);
            $this->sendToErrorLog('Отправляемые данные (если есть)', $data );

            $error = $http->getError();
            foreach ($error as $code =>$message)
            {
                $result->addError(new Main\Error($message, $code));
            }
        }

        $resultData = $result->getData();

        if ($resultData["status"] === "fail" || $resultData["error"]) {
            $this->sendToErrorLog('^^^ status fail или есть ошибка error');
            $this->sendToErrorLog('Объект http', $http);
            $this->sendToErrorLog('Объект $response', $response);
            $this->sendToErrorLog('Метод отправки запроса', $method);
            $this->sendToErrorLog('Адресс запроса', $url);
            $this->sendToErrorLog('Отправляемые данные (если есть)', $data );
        }

        return $result;
    }

    /**
     * @return array
     */
    public static function getGeneralRequiredFields()
    {
        $generalRequiredFields = parent::getGeneralRequiredFields();

        $map = CashboxTable::getMap();
        $generalRequiredFields['NUMBER_KKM'] = $map['NUMBER_KKM']['title'];
        return $generalRequiredFields;
    }

    /**
     * @return string
     * @throws Main\ArgumentNullException
     * @throws Main\ArgumentOutOfRangeException
     */
    private function getAccessToken()
    {
        return Main\Config\Option::get('sale', $this->getOptionName(), '');
    }

    /**
     * @param $token
     * @throws Main\ArgumentOutOfRangeException
     */
    private function setAccessToken($token)
    {
        Main\Config\Option::set('sale', $this->getOptionName(), $token);
    }

    /**
     * @return string
     */
    private function getOptionName()
    {
        return static::TOKEN_OPTION_NAME."_ID:".$this->getField('ID');
    }


    /**
     * @param array $data
     * @return mixed
     * @throws ArgumentException
     */
    private function encode(array $data)
    {
        return Main\Web\Json::encode($data);
    }

    /**
     * @param string $data
     * @return mixed
     * @throws ArgumentException
     */
    private function decode($data)
    {
        return Main\Web\Json::decode($data);
    }

    /**
     * @return string
     * @throws Main\ArgumentOutOfRangeException
     * @throws ArgumentException
     */
    private function requestAccessToken()
    {
        $url = static::SERVICE_URL.'/getToken';
        $data = array(
            'login' => $this->getValueFromSettings('AUTH', 'LOGIN'),
            'pass' => $this->getValueFromSettings('AUTH', 'PASS')
        );

        $result = $this->send(static::REQUEST_TYPE_POST, $url, $data);
        if ($result->isSuccess())
        {
            $response = $result->getData();
            $this->setAccessToken($response['token']);

            return $response['token'];
        }

        $this->sendToErrorLog('^^^ Не был получент токен в requestAccessToken');
        $this->sendToErrorLog('Объект $result', $result);
        $this->sendToErrorLog('Адресс запроса', $url);
        $this->sendToErrorLog('Отправляемые данные (если есть)', $data );

        return '';
    }

    /**
     * @param $errorCode
     * @return int
     */
    protected static function getErrorType($errorCode)
    {
        return Error::TYPE;
    }

    /**
     * @return bool
     */
    public static function isSupportedFFD105()
    {
        return true;
    }


    public function sendToErrorLog($msg, $data = '') {
        $host = "Host: " . $_SERVER["HTTP_HOST"];
        $date = "Date: " . date('r');

        $computed_msg = $host . "\n" . $date . "\n" . $msg . "\n";
        $error_logs_path = self::ERROR_LOGS_DIR . "/errors-cashbox-id-". $this->getField('ID') . ".log";

        Debug::dumpToFile($data, $computed_msg, $error_logs_path);
    }
}