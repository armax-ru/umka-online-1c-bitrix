<?
IncludeModuleLangFile(__FILE__);

use Bitrix\Main\Application;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Sale\Cashbox\Internals\CashboxTable;
use Bitrix\Sale\Cashbox\Manager;

Class armax_umkaonline extends CModule
{
	const MODULE_ID = 'armax.umkaonline';
	const CASHBOX_HANDLER_DB = 'Armax\\\UmkaOnline';
	var $MODULE_ID = 'armax.umkaonline';
	var $MODULE_VERSION;
	var $MODULE_VERSION_DATE;
	var $MODULE_NAME;
	var $MODULE_DESCRIPTION;
	var $MODULE_CSS;
	var $strError = '';

	function __construct()
	{
		$arModuleVersion = array();
		include(dirname(__FILE__)."/version.php");
		$this->MODULE_VERSION = $arModuleVersion["VERSION"];
		$this->MODULE_VERSION_DATE = $arModuleVersion["VERSION_DATE"];
		$this->MODULE_NAME = GetMessage("armax.umkaonline_MODULE_NAME");
		$this->MODULE_DESCRIPTION = GetMessage("armax.umkaonline_MODULE_DESC");

		$this->PARTNER_NAME = GetMessage("armax.umkaonline_PARTNER_NAME");
		$this->PARTNER_URI = GetMessage("armax.umkaonline_PARTNER_URI");
	}

    public function InstallEvents()
	{
        EventManager::getInstance()->registerEventHandler("sale", "OnGetCustomCashboxHandlers", self::MODULE_ID, "CUmkaOnline", "registerMainClass");
		return true;
	}

	public function UnInstallEvents()
	{
        EventManager::getInstance()->unRegisterEventHandler("sale", "OnGetCustomCashboxHandlers", self::MODULE_ID, "CUmkaOnline", "registerMainClass");
		return true;
	}

	private function getLogDirPath() {
	    return Application::getDocumentRoot() . '/umkaonline';
    }

	public function InstallFiles()
    {
        CopyDirFiles(
            __DIR__."/files/logs",
            $this->getLogDirPath()
        );

        return true;
    }

    public function UnInstallDB()
    {
        if (Loader::includeModule('sale')) {
        	// ������� ���������� ������ ������ ������ ����,
    		// ���� ����� ������� � ������� �� ����� ����� �� ����������.
    		// ������ ��� ���� ����� � ������������ �� �� ������ ������.

    		// ������ ��� ������ � ������� 'ACTIVE' - ���������� �����
    		$cashbox_db_off = array('ACTIVE' => 'N');

        	// ������ �� ��������� ������ ���� � ������������ ����� ������
            
            $dbRes = CashboxTable::getList(
                array(
                    'select' => array('ID'),
                    'filter' => array('HANDLER' => self::CASHBOX_HANDLER_DB),
                )
            );

            // �������� �����
            while ($cashbox = $dbRes->fetch())
            {
            	// ��������� ������ �����
    			Manager::update($cashbox['ID'], $cashbox_db_off);
            }

            return true;
        }
        return false;
    }


    public function DoInstall()
	{
	    $this->InstallFiles();
		$this->InstallEvents();
		RegisterModule(self::MODULE_ID);
	}

    public function DoUninstall()
	{
		$this->UnInstallDB();
		$this->UnInstallEvents();
		UnRegisterModule(self::MODULE_ID);
	}
}

