# Umka.Online 1C-Bitrix Integration
Модуль CMS 1С-Битрикс для интеграции с сервисом онлайн-касс [Умка.Онлайн](https://umka365.ru/)

## Оглавление

* Установка модуля
  * 1C-Bitrix Marketplace (на модерации)
  * Ручная установка
* Настройка модуля
  * Добавление новой кассы
  * Настройка онлайн-кассы
  * Внешний идентификатор кассы
* Логирование ошибок
* Поддержка

## Установка модуля

Установить модуль в 1С-Битрикс можно вручную, закачав папку с модулем в директорию `/bitrix/modules`, 
либо из магазина модулей 1C-Bitrix Marketplace.  
Установка из магазина модулей предпочтительнее, т.к. позволит в будущем получать автоматические обновления.

### 1C-Bitrix Marketplace

На данный момент, модуль проходит модерацию в каталог 1C-Bitrix Marketplace 
и будет доступен к автоматической установке позднее.

В данный момент, модуль можно установить из Marketplace c помощью ссылки:  
`http://ваш-сайт/bitrix/admin/update_system_partner.php?addmodule=armax.umkaonline`

### Ручная установка

1. Скачать [архив](https://github.com/armax-ru/umka-online-1c-bitrix/archive/master.zip) с папкой модуля.
2. Распаковать архив в `<корень_сайта>/bitrix/modules`. По итогу, модуль должен иметь путь `<корень_сайта>/bitrix/modules/armax.umkaonline`.
3. Через интерфейс администратора, установить модуль в разделе **Marketplace** / **Установленные решения**.
4. В таблице **Доступные решения**, у строки **"Umka365 Онлайн Касса (armax.umkaonline)"** нажать на кнопку меню и выбрать пункт **"Установить"**.


## Настройка модуля

На данном этапе у вас уже должна быть настроена и готова к работе онлайн-касса в сервисе [Умка.Онлайн](https://umka365.ru/).
Как настроить онлайн-кассу в сервисе [Умка.Онлайн](https://umka365.ru/) можно узнать в [документации](http://umki.org/knowledge-base/).

### Добавление новой кассы

В интефейсе администратора перейти в **Магазин** / **Кассы ККМ** / **Список касс**.
В открывшейся странице нажать зеленую кнопку "**Добавить кассу**".
Откроется окно добавление кассы.  
В поле "**Обработчик:**" выбрать пункт **Умка Онлайн ФФД 1.05** 
и нажать кнопку "Применить"

### Настройка онлайн-кассы

В разделе **Магазин** / **Кассы ККМ** / **Список касс** выберите выберите нужную кассу для настроки.
Двойной клик по названию кассы или выбор соответствующего пункта в меню строки откроют страницу с четырьмя вкладками:
* Параметры кассы
* Ограничения
* Настройки ККМ
* Настройки ОФД

#### Параметры кассы

В данной вкладке содержатся следующие поля:

* ID - номер кассы в системе 1С-Битрикс. 
* Активность - Использование кассы системой 1С-Битрикс. Галочка должна быть установлена.
* Обработчик - Название обработчика. Должно быть установлено **Умка Онлайн ФФД 1.05**.
* ОФД - Используемый сервис ОФД.
* Название - Название кассы в системе 1С-Битрикс. Может быть любое.
* Внешний идентификатор кассы - Идентификатор кассы в системе [Умка.Онлайн](https://umka365.ru/). Подробнее в разделе "Внешний идентификатор кассы".
* Используется оффлайн - Не применяется. Галочка должна быть снята.
* Email - Email на которой будут приходить сообщения об ошибках печати чеков.

#### Ограничения для кассы

В данном разделе можно добавить ограничения (условия) для использования кассы, такие как 
использование кассы для определенных компаний в системе 1С-Битрикс или использования определенных платежных систем. 


#### Настройки ККМ

**Настройки авторизации**

* Логин кассира - Логин кассира из сервиса Умка.Онлайн.
* Пароль кассира - Пароль кассира из сервиса Умка.Онлайн.

**Информация об организации**

* Email организации - Указывается в чеке как "Адрес электронной почты отправителя чека".
* ИНН организации - ИНН организации. Должен совпадать с ИНН какой-либо организации присутствующих в сервисе  Умка.Онлайн.
* Адрес интернет-магазина(url) - Адрес интернет-магазина.

**Настройки ставок НДС**

В данном разделе отображаются ставки НДС добавленные в систему 1С-Битрикс.
Значения в полях отображаемых ставок должны соответствовать определенным значениям 
в соотвествии с форматом передачи данных в сервис Умка.Онлайн.

Обратите внимание, если вы не являетесь плательщиком НДС, 
то значение ставки "Без НДС [0%]" должно совпадать со значением "Без НДС [по умолчанию]"

Значения полей для НДС:

* Без НДС [по умолчанию]: **none**
* Без НДС [0%]:	**vat0**
* НДС 10% [10%]: **vat10**
* НДС 20% [20%]: **vat20**


**Система налогообложения**

* Система налогообложения - Выберите вашу систему налогооблажения.

### Внешний идентификатор кассы

Этот параметр отвечает за выбор кассы в сервисе Умка.Онлайн. Может принимать следующие значения:

* **any** - Выбирает случайную кассу и случайный терминал(рабочее место) из доступных на момент запроса к сервису Умка.Онлайн. 
Обеспечивает равномерную нагрузку при наличии нескольких касс.

* **Шестнадцетизначное число** - используется как регистрационный номер кассы. 
Если номер регистрационный номер меньше 16 цифр, добавте нулей по левому краю.

* **Строка меньше 16 символов** - используется как номер терминала(рабочего места).

* **Шестнадцетизначное число_Строка меньше 16 символов**  - чек будет отправлен на на конкретную кассу, с конкретного терминала(рабочего места).

## Логирование ошибок

При установке модуля, в корне сайта создается папка `umkaonline`, в которой, 
в случае возникновении ошибок работы с онлайн-кассой, появляется log-файл вида `errors-cashbox-id-1.log`, 
в названии которого указывается `id` вашей кассы в системе 1С-Битрикс вашего сайта.  

Так же, при возникновении ошибок печати чека,
1С-Битрикс может оправлять сообщения на электронную почту, указанную в настройках кассы.


При обращении в службу поддержки рекомендуем прикладывать log-файлы и сообщения о неуспешной печати чеков из Email.
