<?php

use Bitrix\Main\Context;
use Bitrix\Main\Loader;

class CUmkaOnline
{
    public static function registerMainClass()
    {
        return new \Bitrix\Main\EventResult(
            \Bitrix\Main\EventResult::SUCCESS,
            array(
                "Armax\\UmkaOnlineV4" => "/bitrix/modules/armax.umkaonline/lib/UmkaOnlineV4.php",
            )
        );
    }

    public static function loadModuleForAjaxAddCashbox()
    {
        $request = Context::getCurrent()->getRequest()->getRequestedPage();

        if ($request == '/bitrix/admin/sale_cashbox_ajax.php') {
            Loader::includeModule('armax.umkaonline');

        }
    }
}
