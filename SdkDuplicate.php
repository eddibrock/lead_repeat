<?php

namespace crm\duplicate_sdk;

use CCrmFieldMulti;
use CCrmContact;
use CCrmLead;
use CCrmDeal;
use CUser;

class SdkDuplicate
{
    public static $lead_id; //ид входящего лида
    public static $contact_id = null; //ид входящего контакта
    public static $leadObj = null; //обьект лида
    const PHONE_FIELD = 'PHONE';
    const EMAIL_FIELD = 'EMAIL';
    public static $search_fields = [self::PHONE_FIELD, self::EMAIL_FIELD]; //по этим полям производится поиск сущностей
    const LEAD = 'LEAD';
    const CONTACT = 'CONTACT';
    public static $entity_types = [self::LEAD, self::CONTACT]; //искомые сущности
//    public static $ignoreLeadStages = ['CONVERTED', "DUPLICATE_LEAD_STAGE", "AGENCY_LEAD_STAGE"]; //Игнорируемые статусы
    public static $ignoreLeadStages = ['CONVERTED', "IN_PROCESS", "1"]; //Игнорируемые статусы

    public function __construct($new_lead_id)
    {
        self::$lead_id = $new_lead_id;
        self::$leadObj = new CCrmLead(false);
        self::GetNewLeadContactId();
    }

    /** Получаем контакт нового лида */
    public static function GetNewLeadContactId()
    {
        $contact_id = self::$leadObj::GetByID(self::$lead_id)['CONTACT_ID'];
        if ($contact_id) {
            self::$contact_id = $contact_id;
        }
    }

    /** Полученные массивы данных обьединяем в один и убираем повторы
     * @param $for_unify array
     * @return array
     */
    public static function UnifyDuplicates($for_unify)
    {
        $unified = array_unique(array_merge(... $for_unify));
        return $unified;
    }

    /**  убрать ид нового лида из результата (иначе будет ссылаться сам на себя)
     * @param $needle
     * @param $for_reduce
     * @return bool
     */
    public static function ReduceDuplicates($needle, $for_reduce)
    {
        if (count($for_reduce) > 1) { // Если елементов больше чем 1, иначе удаляет себя же
            $key = array_search($needle, $for_reduce);
            if ($key === false) {
                return $for_reduce;
            }
            unset($for_reduce[$key]);
            if (empty($for_reduce)) {
                return false;
            } else {
                return $for_reduce;
            }
        }
    }

    /** получить все лиды статуса CONVERTED и "клиент агенства"
     */
    public static function GetAgentLeads()
    {
        $arFilter = ['ACTIVE' => 'Y', 'STATUS_ID' => array('CONVERTED', 1)];
        $result = [];
        $leadOb = self::$leadObj::GetListEx([], $arFilter, false, false, ['ID'], []);
        while ($leadArr = $leadOb->Fetch()) {
            $result[] = $leadArr['ID']; //массив с ИД лидами
        }
        return $result;
    }
}

/** Получение данных полей лида */
class LeadFieldsEntity extends SdkDuplicate
{
    public static $entity_result = [self::PHONE_FIELD => [], self::EMAIL_FIELD => []]; //результат всей работы класса

    public function __construct($new_lead_id)
    {
        parent::__construct($new_lead_id);
        if (parent::$contact_id) { // Если новый лид содержит ИД контакта
            self::GetFieldsFromLeadContact(); // Получить поля этого контакта
        }
        self::GetFieldsFromLead();
    }

    /** Получаем данные из контакта, привязанного к лиду */
    public static function GetFieldsFromLeadContact()
    {
        foreach (self::$search_fields as $searchFieldName) { // ИД контакта из лида можно получить только один
            self::GetFieldEntityByType(parent::CONTACT, $searchFieldName, self::$contact_id);
        }
    }

    /** Получаем поля из лида */
    public static function GetFieldsFromLead()
    {
        foreach (self::$search_fields as $searchFieldName) {
            self::GetFieldEntityByType(parent::LEAD, $searchFieldName, self::$lead_id);
        }
    }

    public static function GetFieldEntityByType($entity_type, $searchFieldName, $entity_id)
    {
        $arFilter = [
            'ENTITY_ID' => $entity_type,
            'ELEMENT_ID' => $entity_id,
            'TYPE_ID' => $searchFieldName
        ];
        $resFieldOj = CCrmFieldMulti::GetListEx([], $arFilter, false, array(), ['VALUE']);
        while ($arField = $resFieldOj->Fetch()) {
            if (!empty($arField['VALUE'])) {
                array_push(self::$entity_result[$searchFieldName], $arField['VALUE']);
            }
        }
    }

    public static function GetLeadFieldsEntity() // функция получения результирующего массива + чистка
    {
        foreach (self::$search_fields as $searchFieldName) {
            self::$entity_result[$searchFieldName] = array_unique(self::$entity_result[$searchFieldName]);
        }
        return self::$entity_result;
    }
}

class LeadDuplicates extends SdkDuplicate
{
    public static $searchFieldsEntity;
    public static $result_lead = [];
    public static $result_contact = [];
    public static $result_deal = [];
    public static $all_entity_result = [];

    public function __construct($new_lead_id, $searchFieldsEntity)
    {
        parent::__construct($new_lead_id);
        self::$searchFieldsEntity = $searchFieldsEntity;
    }

    /** Получить дубликаты  */
    public static function GetDuplicate()
    {
        foreach (self::$entity_types as $entity_type) {
            foreach (self::$searchFieldsEntity as $fieldName => $values) {
                if (!empty($values)) {
                    self::GetDuplicateByEntityType($entity_type, $fieldName, $values);
                }
            }
        }
        if (!empty(self::$result_lead)) {
            self::$all_entity_result['LEAD'] = parent::ReduceDuplicates(self::$lead_id, parent::UnifyDuplicates(self::$result_lead));
        }
        if (!empty(self::$result_contact)) {
            self::$all_entity_result['CONTACT'] = self::UnifyDuplicates(self::$result_contact);
        }
        //TODO case пока будет для 1 контакта. Нужно сделать цикл если много контактов
        if (self::$result_contact) { //Если контакт то поискать его сделки
            $contact_id = self::$result_contact[0]; // Самый старый контакт
            self::GetDealDuplicatesByContact($contact_id);
            if (!empty(self::$result_deal)) {
                self::$all_entity_result['DEAL'] = self::$result_deal;
            }
        }
        return self::$all_entity_result;
    }

    public static function GetDuplicateByEntityType($entity_type, $fieldName, $values)
    {
        $result = [];
        foreach ($values as $value) {
            $rs = CCrmFieldMulti::GetList(
                ['DATE_CREATE' => 'DESC'],
                array(
                    "ENTITY_ID" => $entity_type, //CONTACT LEAD
                    "VALUE" => $value, //"profitlab@mail.ru"
                    "TYPE_ID" => $fieldName, //"EMAIL" or "PHONE"
                    //"!ELEMENT_ID" => array($id), // ID выбираемых лидов //TODO попробывать отфильтровать тут
                    //'COMPLEX_ID' => 'EMAIL_WORK' // тип email: "Рабочий"
                )
            );
            while ($ar = $rs->Fetch()) {
                $result[] = $ar['ELEMENT_ID']; //ID сущности, которая содержит данное значение в данном поле (PHONE или EMAIL)
            }
            if (!empty($result)) {
                switch ($entity_type) {
                    case 'LEAD':
                        array_push(self::$result_lead, $result);
                        break;
                    case 'CONTACT':
                        array_push(self::$result_contact, $result);
                        break;
                }
            }
        }
    }

    /** Получить дубликаты по контакту */
    public static function GetDealDuplicatesByContact($contact_id)
    {
        $arFilter = ['ACTIVE' => 'Y', 'CONTACT_ID' => $contact_id];
        $Ob = CCrmDeal::GetListEx(['DATE_CREATE' => 'DESC'], $arFilter, false, false, ['ID'], []);
        while ($dealArr = $Ob->Fetch()) {
            array_push(self::$result_deal, $dealArr['ID']);
        }
    }
}

class LeadDuplicatesUpdate extends SdkDuplicate
{
    public static $duplicate_lead = [];
    public static $duplicate_deal = [];
    public static $contacts = [];
    public static $newLeadFields = []; // поля для обновления лида
    public static $responsibleId = null;
    const LEAD_REPEAT_LEAD = 'UF_REPEAT_LEAD'; //поле "связанные лиды"
    const LEAD_REPEAT_DEAL = 'UF_LEAD_REPEAT_DEAL'; //поле "связанные сделки"
    const LEAD_REPEAT_CONTACT = 'UF_LEAD_REPEAT_CONTACT'; //поле "связанные контакты"
    const IS_REPEAT_LEAD = 'UF_IS_REPEAT_LEAD';//поле "Дубликат(лид)"

    public function __construct($new_lead_id, $duplicates)
    {
        parent::__construct($new_lead_id);
        self::$duplicate_lead = $duplicates['LEAD'];
        self::$duplicate_deal = $duplicates['DEAL'];
        self::$contacts = $duplicates['CONTACT'];
    }

    /** Обновить новый лид, установить связи и другие поля */
    public static function UpdateNewLead()
    {
        if (!empty(self::$duplicate_lead)) {

            self::$newLeadFields[self::LEAD_REPEAT_LEAD] = self::$duplicate_lead;
        }
        if (!empty(self::$duplicate_deal)) {
            self::$newLeadFields[self::LEAD_REPEAT_DEAL] = self::$duplicate_deal;
        }
        self::$newLeadFields[self::IS_REPEAT_LEAD] = 1;
        self::SetResponsible();
        self::SetContact();
        $res = self::$leadObj->update(self::$lead_id, self::$newLeadFields); //TODO bag похоже дело в том, что он не может обновится так
        return $res;
    }

    /** Обновить лиды, которые более не актуальный и переместить их в стадию дубликтов */
    public static function UpdateDuplicatesLead()
    {
        if (!empty(self::$duplicate_lead)) {
            foreach (self::$duplicate_lead as $duplicate_lead_id) {
                /** Блок фильтрации*/
                $status = self::GetStatusLead($duplicate_lead_id);
                if (!in_array($status, self::$ignoreLeadStages)) {
                    $fields = ['STATUS_ID' => "IN_PROCESS"];
                    $res = self::$leadObj->update($duplicate_lead_id, $fields);
                }
            }
        }
    }


    /** Установить контакт новому лиду */
    public static function SetContact()
    {
        if (empty(parent::$contact_id)) { //Если не был привязан контакт
            if (!empty(self::$contacts)) { // Привязать к дубликатам если они найдены
                $main_contact = self::$contacts[0];
                self::$newLeadFields['CONTACT_ID'] = $main_contact; //Привязать старейший контакт
                $addition_contacts = parent::ReduceDuplicates($main_contact, self::$contacts);
                self::$newLeadFields[self::LEAD_REPEAT_CONTACT] = $addition_contacts;
            }
        } else { //Если контакт уже есть то его и оставляем
            self::$newLeadFields['CONTACT_ID'] = parent::$contact_id;
            if (!empty(self::$contacts)) {  //исключаем текущий контакт из повторов (если такие есть)
                if (in_array(parent::$contact_id, self::$contacts)) {
                    $key = array_search(parent::$contact_id, self::$contacts);
                    unset(self::$contacts[$key]);
                }
                self::$newLeadFields[self::LEAD_REPEAT_CONTACT] = array_keys(self::$contacts);
            }
        }
    }

    /** Установить ответственного новому лиду */
    public static function SetResponsible()
    {
        //TODO case нужно протестировать если у сделки или контакта не окажется пользователя активного
        self::GetResponsible();
        self::CheckUser(self::$responsibleId);
        if (!empty(self::$responsibleId)) {
            self::$newLeadFields['ASSIGNED_BY_ID'] = self::$responsibleId;
        }

    }

    public static function GetResponsible()
    {
        if (!empty(self::$duplicate_deal)) {
            //берётся самая свежая сделка
            self::$responsibleId = CCrmDeal::GetByID(self::$duplicate_deal[0])['ASSIGNED_BY_ID'];
        } elseif (!empty(self::$contact_id)) {
            self::$responsibleId = CCrmContact::GetByID(self::$contact_id)['ASSIGNED_BY_ID'];
        } else {
            self::$responsibleId = CCrmLead::GetByID(self::$duplicate_lead[0])['ASSIGNED_BY_ID'];

        }
    }

    public static function CheckUser($userId)
    {
        //может можно https://bxapi.ru/src/?module_id=tasks&name=User::isActive
        $userActiveStatus = CUser::GetByID($userId)->Fetch()['ACTIVE'];
        if ($userActiveStatus == 'N') { //Если пользователь не активен, вместо него берётся руководитель
            $userDep = \CIntranetUtils::GetUserDepartments($userId);;
            $managersArr = \CIntranetUtils::GetDepartmentManager($userDep, $skipUserId = false, $bRecursive = true);
            //$bRecursive означает, что если у ближайшего подразделения пользователь НЕ активен то вместо него будет старший и далее.
            self::$responsibleId = array_keys($managersArr)[0];
        }
    }

    public static function GetStatusLead($lead_id)
    {
        $status = self::$leadObj::GetByID($lead_id);
        return $status['STATUS_ID'];
    }
}
