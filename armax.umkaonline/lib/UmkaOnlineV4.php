<?php

namespace Armax;

use Bitrix\Main;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Localization;

use Bitrix\Catalog;

use Bitrix\Main\ObjectException;
use Bitrix\Main\SystemException;
use Bitrix\Sale\Cashbox\CorrectionCheck;
use Bitrix\Sale\Cashbox\CreditPaymentReturnCashCheck;
use Bitrix\Sale\Cashbox\CreditPaymentReturnCheck;
use Bitrix\Sale\Cashbox\MeasureCodeToTag2108Mapper;
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
use Bitrix\Sale\Cashbox\PrepaymentCheck;
use Bitrix\Sale\Cashbox\PrepaymentReturnCheck;
use Bitrix\Sale\Cashbox\PrepaymentReturnCashCheck;
use Bitrix\Sale\Cashbox\FullPrepaymentCheck;
use Bitrix\Sale\Cashbox\FullPrepaymentReturnCheck;
use Bitrix\Sale\Cashbox\FullPrepaymentReturnCashCheck;
use Bitrix\Sale\Cashbox\CreditCheck;
use Bitrix\Sale\Cashbox\CreditReturnCheck;
use Bitrix\Sale\Cashbox\CreditPaymentCheck;
use Bitrix\Sale\Cashbox\Errors\Warning;
use Bitrix\Sale\Cashbox\Errors\Error;
use Bitrix\Sale\Cashbox\Tools;

Localization\Loc::loadMessages(__FILE__);
class UmkaOnlineV4 extends Cashbox implements IPrintImmediately, ICheckable
{
    const SERVICE_URL = 'https://umka365.ru/kkm-trade/atolpossystem/v4';
    const TOKEN_OPTION_NAME = 'umkaonline_access_token';
    const UUID_DELIMITER = '-';
    const OPERATION_CHECK_REGISTRY = 'registry';
    const OPERATION_CHECK_CHECK = 'check';
    const OPERATION_GET_TOKEN = 'get_token';

    const REQUEST_TYPE_GET = 'get';
    const REQUEST_TYPE_POST = 'post';

    const RESPONSE_HTTP_CODE_401 = 401;
    const RESPONSE_HTTP_CODE_200 = 200;

    const CODE_VAT_0 = 'vat0';
    const CODE_VAT_10 = 'vat10';
    const CODE_VAT_20 = 'vat20';
    const CODE_VAT_5 = 'vat5';
    const CODE_VAT_7 = 'vat7';

    const CODE_CALC_VAT_5 = 'vat105';
    const CODE_CALC_VAT_7 = 'vat107';
    const CODE_CALC_VAT_10 = 'vat110';
    const CODE_CALC_VAT_20 = 'vat120';

    protected const MAX_NAME_LENGTH = 128;

    const ERROR_LOGS_DIR = '/umkaonline';




    /**
     * @param Check $check
     * @return array
     * @throws Main\ArgumentNullException | Main\ArgumentOutOfRangeException
     */
    public function buildCheckQuery(Check $check): array
    {
        $data = $check->getDataForCheck();

        /** @var Main\Type\DateTime $dateTime */
        $dateTime = $data['date_create'];

        $serviceEmail = $this->getValueFromSettings('SERVICE', 'EMAIL');
        if (!$serviceEmail) {
            $serviceEmail = static::getDefaultServiceEmail();
        }

        $uuid = static::buildUuid(static::UUID_TYPE_CHECK, $data['unique_id']);
        // special case for umka api
        $uuidUmka = str_replace('.', '-', $uuid);

        $result = array(
            'timestamp' => $dateTime->format('d.m.Y H:i:s'),
            'external_id' => $uuidUmka,
            'service' => [
                'callback_url' => $this->getCallbackUrl(),
            ],
            'receipt' => [
                'client' => [],
                'company' => [
                    'email' => $serviceEmail,
                    'sno' => $this->getValueFromSettings('TAX', 'SNO'),
                    'inn' => $this->getValueFromSettings('SERVICE', 'INN'),
                    'payment_address' => $this->getValueFromSettings('SERVICE', 'P_ADDRESS'),
                ],
                'payments' => [],
                'items' => [],
                'total' => (float)$data['total_sum']
            ]
        );

        $email = $data['client_email'] ?? '';

        $phone = \NormalizePhone($data['client_phone'] ?? null);
        if (is_string($phone))
        {
            if ($phone[0] !== '7')
            {
                $phone = '7'.$phone;
            }

            $phone = '+'.$phone;
        }
        else
        {
            $phone = '';
        }

        $clientInfo = $this->getValueFromSettings('CLIENT', 'INFO');
        if ($clientInfo === 'PHONE')
        {
            $result['receipt']['client'] = ['phone' => $phone];
        }
        elseif ($clientInfo === 'EMAIL')
        {
            $result['receipt']['client'] = ['email' => $email];
        }
        else
        {
            $result['receipt']['client'] = [];

            if ($email)
            {
                $result['receipt']['client']['email'] = $email;
            }

            if ($phone)
            {
                $result['receipt']['client']['phone'] = $phone;
            }
        }

        if (isset($data['payments']))
        {
            $paymentTypeMap = $this->getPaymentTypeMap();
            foreach ($data['payments'] as $payment)
            {
                $result['receipt']['payments'][] = [
                    'type' => $paymentTypeMap[$payment['type']],
                    'sum' => (float)$payment['sum']
                ];
            }
        }

        foreach ($data['items'] as $item)
        {
            $result['receipt']['items'][] = $this->buildPosition($data, $item);
        }

        return $result;

    }

    /**
     * @param array $checkData
     * @param array $item
     * @return array
     */
    protected function buildPosition(array $checkData, array $item): array
    {
        $result = [
            'name' => $this->buildPositionName($item),
            'price' => $this->buildPositionPrice($item),
            'sum' => $this->buildPositionSum($item),
            'quantity' => $this->buildPositionQuantity($item),
            'payment_method' => $this->buildPositionPaymentMethod($checkData),
            'payment_object' => $this->buildPositionPaymentObject($item),
            'vat' => [
                'type' => $this->buildPositionVatType($checkData, $item)
            ]
        ];

        if (isset($item['nomenclature_code'])) {
            $result['nomenclature_code'] = $this->buildPositionNomenclatureCode($item['nomenclature_code']);
        }

        return $result;
    }

    protected function buildPositionName(array $item): string
    {
        return mb_substr($item['name'], 0, static::MAX_NAME_LENGTH);
    }

    /**
     * @param array $item
     * @return float
     */
    protected function buildPositionPrice(array $item): float
    {
        return (float)$item['price'];
    }

    /**
     * @param array $item
     * @return float
     */
    protected function buildPositionSum(array $item): float
    {
        return (float)$item['sum'];
    }

    /**
     * @param array $item
     * @return mixed
     */
    protected function buildPositionQuantity(array $item): mixed
    {
        return $item['quantity'];
    }

    /**
     * @param array $checkData
     * @return mixed|string
     */
    protected function buildPositionPaymentMethod(array $checkData): mixed
    {
        $checkTypeMap = $this->getCheckTypeMap();

        return $checkTypeMap[$checkData['type']];
    }

    /**
     * @param array $item
     * @return mixed|string
     */
    protected function buildPositionPaymentObject(array $item): mixed
    {
        $paymentObjectMap = $this->getPaymentObjectMap();

        return $paymentObjectMap[$item['payment_object']];
    }

    /**
     * @param array $checkData
     * @param array $item
     * @return mixed|string
     */
    protected function buildPositionVatType(array $checkData, array $item): mixed
    {
        $vat = $this->getValueFromSettings('VAT', $item['vat']);
        if ($vat === null) {
            $vat = $this->getValueFromSettings('VAT', 'NOT_VAT');
        }

        return $this->mapVatValue($checkData['type'], $vat);
    }

    private function mapVatValue($checkType, $vat)
    {
        $mapper = new Tools\Vat2PrepaymentCheckMapper(
            $this->getVatToCalcVatMap()
        );

        $map = $mapper->getMap();

        return $map[$vat][$checkType] ?? $vat;
    }

    protected static function getDefaultVatList(): array
    {
        return [
            0 => self::CODE_VAT_0,
            10 => self::CODE_VAT_10,
            20 => self::CODE_VAT_20,
            5 => self::CODE_VAT_5,
            7 => self::CODE_VAT_7
        ];
    }

    protected function getVatToCalcVatMap(): array
    {
        return [
            self::CODE_VAT_5 => self::CODE_CALC_VAT_5,
            self::CODE_VAT_7 => self::CODE_CALC_VAT_7,
            self::CODE_VAT_10 => self::CODE_CALC_VAT_10,
            self::CODE_VAT_20 => self::CODE_CALC_VAT_20,
        ];
    }

    /**
     * @param $code
     * @return string
     */
    protected function buildPositionNomenclatureCode($code): string
    {
        $hexCode = bin2hex($code);
        $hexCodeArray = str_split($hexCode, 2);
        $hexCodeArray = array_map('ToUpper', $hexCodeArray);

        return join(' ', $hexCodeArray);
    }

    /**
     * @param CorrectionCheck $check
     * @return Result
     * @throws ObjectException
     */
    public function printCorrectionImmediately(CorrectionCheck $check)
    {
        $checkQuery = $this->buildCorrectionCheckQuery($check);

        $operation = 'sell_correction';
        if ($check::getCalculatedSign() === Check::CALCULATED_SIGN_CONSUMPTION) {
            $operation = 'sell_refund';
        }

        return $this->registerCheck($operation, $checkQuery);
    }

    /**
     * @throws ObjectException
     */
    public function buildCorrectionCheckQuery(CorrectionCheck $check): array
    {
        $data = $check->getDataForCheck();

        /** @var Main\Type\DateTime $dateTime */
        $dateTime = $data['date_create'];

        $documentDate = $data['correction_info']['document_date'];
        if (!$documentDate instanceof Main\Type\Date) {
            $documentDate = new Main\Type\Date($documentDate);
        }

        $result = [
            'timestamp' => $dateTime->format('d.m.Y H:i:s'),
            'external_id' => static::buildUuid(static::UUID_TYPE_CHECK, $data['unique_id']),
            'service' => [
                'callback_url' => $this->getCallbackUrl(),
            ],
            'correction' => [
                'company' => [
                    'sno' => $this->getValueFromSettings('TAX', 'SNO'),
                    'inn' => $this->getValueFromSettings('SERVICE', 'INN'),
                    'payment_address' => $this->getValueFromSettings('SERVICE', 'P_ADDRESS'),
                ],
                'correction_info' => [
                    'type' => $data['correction_info']['type'],
                    'base_date' => $documentDate->format('d.m.Y H:i:s'),
                    'base_number' => $data['correction_info']['document_number'],
                    'base_name' => mb_substr(
                        $data['correction_info']['description'],
                        0,
                        255
                    ),
                ],
                'payments' => [],
                'vats' => []
            ]
        ];

        if (isset($data['payments']))
        {
            $paymentTypeMap = $this->getPaymentTypeMap();
            foreach ($data['payments'] as $payment)
            {
                $result['correction']['payments'][] = [
                    'type' => $paymentTypeMap[$payment['type']],
                    'sum' => (float)$payment['sum']
                ];
            }
        }

        if (isset($data['vats']))
        {
            foreach ($data['vats'] as $item)
            {
                $vat = $this->getValueFromSettings('VAT', $item['type']);
                if (is_null($vat) || $vat === '')
                {
                    $vat = $this->getValueFromSettings('VAT', 'NOT_VAT');
                }

                $result['correction']['vats'][] = [
                    'type' => $vat,
                    'sum' => (float)$item['sum']
                ];
            }
        }

        return $result;
    }

    public function checkCorrection(CorrectionCheck $check): Result
    {
        return $this->checkByUuid(
            $check->getField('EXTERNAL_UUID')
        );
    }
    protected function registerCheck($operation, array $check)
    {
        $printResult = new Result();

        $token = $this->getAccessToken();
        if ($token === '')
        {
            $token = $this->requestAccessToken();
            if ($token === '')
            {
                $printResult->addError(new Main\Error(Localization\Loc::getMessage('SALE_CASHBOX_ATOL_REQUEST_TOKEN_ERROR')));
                return $printResult;
            }
        }

        $url = $this->getRequestUrl(static::OPERATION_CHECK_REGISTRY, $token, ['CHECK_TYPE' => $operation]);

        $result = $this->send(static::REQUEST_TYPE_POST, $url, $check);
        if (!$result->isSuccess())
        {
            return $result;
        }

        $response = $result->getData();
        if ($response['http_code'] === static::RESPONSE_HTTP_CODE_401)
        {
            $token = $this->requestAccessToken();
            if ($token === '')
            {
                $printResult->addError(new Main\Error(Localization\Loc::getMessage('SALE_CASHBOX_ATOL_REQUEST_TOKEN_ERROR')));
                return $printResult;
            }

            $url = $this->getRequestUrl(static::OPERATION_CHECK_REGISTRY, $token, array('CHECK_TYPE' => $operation));
            $result = $this->send(static::REQUEST_TYPE_POST, $url, $check);
            if (!$result->isSuccess())
            {
                return $result;
            }

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
     * @return array
     */
    protected function getPaymentObjectMap()
    {
        return [
            Check::PAYMENT_OBJECT_COMMODITY => 'commodity',
            Check::PAYMENT_OBJECT_SERVICE => 'service',
            Check::PAYMENT_OBJECT_JOB => 'job',
            Check::PAYMENT_OBJECT_EXCISE => 'excise',
            Check::PAYMENT_OBJECT_PAYMENT => 'payment',
            Check::PAYMENT_OBJECT_GAMBLING_BET => 'gambling_bet',
            Check::PAYMENT_OBJECT_GAMBLING_PRIZE => 'gambling_prize',
            Check::PAYMENT_OBJECT_LOTTERY => 'lottery',
            Check::PAYMENT_OBJECT_LOTTERY_PRIZE => 'lottery_prize',
            Check::PAYMENT_OBJECT_INTELLECTUAL_ACTIVITY => 'intellectual_activity',
            Check::PAYMENT_OBJECT_AGENT_COMMISSION => 'agent_commission',
            Check::PAYMENT_OBJECT_COMPOSITE => 'composite',
            Check::PAYMENT_OBJECT_ANOTHER => 'another',
            Check::PAYMENT_OBJECT_PROPERTY_RIGHT => 'property_right',
            Check::PAYMENT_OBJECT_NON_OPERATING_GAIN => 'non-operating_gain',
            Check::PAYMENT_OBJECT_SALES_TAX => 'sales_tax',
            Check::PAYMENT_OBJECT_RESORT_FEE => 'resort_fee',
            Check::PAYMENT_OBJECT_DEPOSIT => 'deposit',
            Check::PAYMENT_OBJECT_EXPENSE => 'expense',
            Check::PAYMENT_OBJECT_PENSION_INSURANCE_IP => 'pension_insurance_ip',
            Check::PAYMENT_OBJECT_PENSION_INSURANCE => 'pension_insurance',
            Check::PAYMENT_OBJECT_MEDICAL_INSURANCE_IP => 'medical_insurance_ip',
            Check::PAYMENT_OBJECT_MEDICAL_INSURANCE => 'medical_insurance',
            Check::PAYMENT_OBJECT_SOCIAL_INSURANCE => 'social_insurance',
            Check::PAYMENT_OBJECT_CASINO_PAYMENT => 'casino_payment',
            Check::PAYMENT_OBJECT_COMMODITY_MARKING_NO_MARKING_EXCISE => 'excise',
            Check::PAYMENT_OBJECT_COMMODITY_MARKING_EXCISE => 'excise',
            Check::PAYMENT_OBJECT_COMMODITY_MARKING_NO_MARKING => 'commodity',
            Check::PAYMENT_OBJECT_COMMODITY_MARKING => 'commodity',
        ];
    }

    /**
     * @return array
     */
    private function getPaymentTypeMap()
    {
        return array(
            Check::PAYMENT_TYPE_CASH => 0,
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
            PrepaymentCheck::getType() => 'prepayment',
            PrepaymentReturnCheck::getType() => 'prepayment',
            PrepaymentReturnCashCheck::getType() => 'prepayment',
            FullPrepaymentCheck::getType() => 'full_prepayment',
            FullPrepaymentReturnCheck::getType() => 'full_prepayment',
            FullPrepaymentReturnCashCheck::getType() => 'full_prepayment',
            CreditCheck::getType() => 'credit',
            CreditReturnCheck::getType() => 'credit',
            CreditPaymentCheck::getType() => 'credit_payment',
            CreditPaymentReturnCashCheck::getType() => 'credit_payment',
            CreditPaymentReturnCheck::getType() => 'credit_payment',
        );
    }


    /**
     * @param int $modelId
     * @return array
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
                        'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_AUTH_LOGIN_LABEL')
                    ),
                    'PASS' => array(
                        'TYPE' => 'STRING',
                        'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_AUTH_PASS_LABEL')
                    ),
                )
            ),
            'SERVICE' => array(
                'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_SERVICE'),
                'REQUIRED' => 'Y',
                'ITEMS' => array(
                    'INN' => array(
                        'TYPE' => 'STRING',
                        'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_SERVICE_INN_LABEL')
                    ),
                    'P_ADDRESS' => array(
                        'TYPE' => 'STRING',
                        'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_SERVICE_URL_LABEL')
                    ),
                )
            ),
            'CLIENT' => [
                'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_CLIENT'),
                'ITEMS' => array(
                    'INFO' => array(
                        'TYPE' => 'ENUM',
                        'VALUE' => 'NONE',
                        'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_CLIENT_INFO'),
                        'OPTIONS' => array(
                            'NONE' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_CLIENT_NONE'),
                            'PHONE' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_CLIENT_PHONE'),
                            'EMAIL' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_CLIENT_EMAIL'),
                        )
                    ),
                )
            ]
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
            $dbRes = Catalog\VatTable::getList([
                'filter' => ['=ACTIVE' => 'Y'],
                'cache' => [
                    'ttl' => 86400,
                ]
            ]);
            $vatList = $dbRes->fetchAll();
            if ($vatList)
            {
                $defaultVatList = static::getDefaultVatList();

                foreach ($vatList as $vat)
                {
                    $value = null;
                    if (isset($defaultVatList[(int)$vat['RATE']]))
                        $value = $defaultVatList[(int)$vat['RATE']];

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

        $settings['SERVICE']['ITEMS']['EMAIL'] = [
            'TYPE' => 'STRING',
            'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_SERVICE_EMAIL_LABEL'),
            'VALUE' => static::getDefaultServiceEmail()
        ];

        if (static::hasMeasureSettings())
        {
            $settings['MEASURE'] = static::getMeasureSettings();
        }


        return $settings;
    }

    /**
     * @return bool
     */
    protected static function hasMeasureSettings(): bool
    {
        return false;
    }

    /**
     * @return array
     */
    protected static function getMeasureSettings(): array
    {
        $measureItems = [
            'DEFAULT' => [
                'TYPE' => 'STRING',
                'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_MEASURE_SUPPORT_SETTINGS_DEFAULT_VALUE'),
                'VALUE' => 0,
            ]
        ];
        if (Main\Loader::includeModule('catalog'))
        {
            $measuresList = \CCatalogMeasure::getList();
            while ($measure = $measuresList->fetch())
            {
                $measureItems[$measure['CODE']] = [
                    'TYPE' => 'STRING',
                    'LABEL' => $measure['MEASURE_TITLE'],
                    'VALUE' => MeasureCodeToTag2108Mapper::getTag2108Value($measure['CODE']),
                ];
            }
        }

        return [
            'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_MEASURE_SUPPORT_SETTINGS'),
            'ITEMS' => $measureItems,
        ];
    }

    /**
     * @return string
     */
    protected function getCallbackUrl()
    {
        $context = Main\Application::getInstance()->getContext();
        $scheme = $context->getRequest()->isHttps() ? 'https' : 'http';
        $server = $context->getServer();
        $domain = $server->getServerName();

        if (preg_match('/^(?<domain>.+):(?<port>\d+)$/', $domain, $matches)) {
            $domain = $matches['domain'];
            $port = $matches['port'];
        } else {
            $port = $server->getServerPort();
        }
        $port = in_array($port, array(80, 443)) ? '' : ':' . $port;

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

        if ($data['error']) {
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
     * @throws SystemException|Main\LoaderException
     */
    public function printImmediately(Check $check)
    {
        $checkQuery = $this->buildCheckQuery($check);
        $validateResult = $this->validateCheckQuery($checkQuery);
        if (!$validateResult->isSuccess())
        {
            return $validateResult;
        }

        $operation = 'sell';
        if ($check::getCalculatedSign() === Check::CALCULATED_SIGN_CONSUMPTION)
        {
            $operation = 'sell_refund';
        }

        return $this->registerCheck($operation, $checkQuery);
    }


    /**
     * @param Check $check
     * @return Result
     */
    public function check(Check $check)
    {
        return $this->checkByUuid(
            $check->getField('EXTERNAL_UUID')
        );
    }

    protected function checkByUuid($uuid)
    {
        $url = $this->getRequestUrl(
            static::OPERATION_CHECK_CHECK,
            $this->getAccessToken(),
            ['EXTERNAL_UUID' => $uuid]
        );

        $result = $this->send(static::REQUEST_TYPE_GET, $url);
        if (!$result->isSuccess())
        {
            return $result;
        }

        $response = $result->getData();
        if ($response['http_code'] === static::RESPONSE_HTTP_CODE_401)
        {
            $token = $this->requestAccessToken();
            if ($token === '')
            {
                $result->addError(new Main\Error(Localization\Loc::getMessage('SALE_CASHBOX_ATOL_REQUEST_TOKEN_ERROR')));
                return $result;
            }

            $url = $this->getRequestUrl(
                static::OPERATION_CHECK_CHECK,
                $this->getAccessToken(),
                ['EXTERNAL_UUID' => $uuid]
            );

            $result = $this->send(static::REQUEST_TYPE_GET, $url);
            if (!$result->isSuccess())
            {
                return $result;
            }

            $response = $result->getData();
        }

        $response['uuid'] = $uuid;

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
    protected function validateCheckQuery(array $checkData)
    {
        $result = new Result();

        if (empty($checkData['receipt']['client']['email']) && empty($checkData['receipt']['client']['phone']))
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

        if ($method === static::REQUEST_TYPE_POST) {
            $http->disableSslVerification();
            $data = $this->encode($data);

            \Bitrix\Sale\Cashbox\Logger::addDebugInfo($data);

            $response = $http->post($url, $data);
        } else {
            $response = $http->get($url);
        }

        if ($response !== false) {
            \Bitrix\Sale\Cashbox\Logger::addDebugInfo($response);

            try {
                $response = $this->decode($response);
                if (!is_array($response)) {
                    $response = [];
                }

                $response['http_code'] = $http->getStatus();
                $result->addData($response);


            } catch (Main\ArgumentException $e) {
                $result->addError(new Main\Error($e->getMessage()));
            }
        }
        else
        {
            $error = $http->getError();
            foreach ($error as $code => $message) {
                $result->addError(new Main\Error($message, $code));
            }
        }

        $resultData = $result->getData();

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
        return static::getOptionPrefix() . '_' .mb_strtolower($this->getField('NUMBER_KKM'));
    }

    /**
     * @return string
     */
    protected function getOptionPrefix(): string
    {
        return static::TOKEN_OPTION_NAME;
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
    private function requestAccessToken(): string
    {
        $url = $this->getRequestUrl(static::OPERATION_GET_TOKEN, '');

        $data = array(
            'login' => $this->getValueFromSettings('AUTH', 'LOGIN'),
            'pass' => $this->getValueFromSettings('AUTH', 'PASS')
        );

        $result = $this->send(static::REQUEST_TYPE_POST, $url, $data);
        if ($result->isSuccess())
        {
            $response = $result->getData();
            if (isset($response['token']))
            {
                $this->setAccessToken($response['token']);

                return $response['token'];
            }
        }

        return '';
    }

    protected function getRequestUrl($operation, $token, array $queryData = array())
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
        elseif ($operation === static::OPERATION_GET_TOKEN)
        {
            return static::SERVICE_URL.'/getToken';
        }

        throw new SystemException();
    }

    /**
     * @param $errorCode
     * @throws Main\NotImplementedException
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

    /**
     * @inheritDoc
     */
    public static function getFfdVersion(): ?float
    {
        return 1.05;
    }

}
