<?php

require __DIR__ . '/vendor/autoload.php';

class CUmkaOnline
{
    public function registerMainClass()
    {
        return new \Bitrix\Main\EventResult(
            \Bitrix\Main\EventResult::SUCCESS,
            array(
                "Armax\UmkaOnline" => "/bitrix/modules/armax.umkaonline/lib/UmkaOnline.php",
            )
        );
    }
}

