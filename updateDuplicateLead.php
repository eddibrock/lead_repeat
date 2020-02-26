<?php

include_once "SdkDuplicate.php"; //подключаем наш класс

use crm\duplicate_sdk;

class UpdateDuplicateLead
{
    function sdkInit($arFields)
    {

        /** Блок получения данных полей*/
        $new_lead_id = $arFields['ID']; //получение ид нового лида
        $skdObj = new duplicate_sdk\SdkDuplicate($new_lead_id); //получение полей нового лида
        $entityObj = new duplicate_sdk\LeadFieldsEntity($new_lead_id);
        $leadFieldsEntity = $entityObj::GetLeadFieldsEntity();

        /** Блок поиска дублей*/
        if (!empty($leadFieldsEntity)) {
            $myDuplicateOj = new duplicate_sdk\LeadDuplicates($new_lead_id, $leadFieldsEntity);
            $duplicates = $myDuplicateOj::GetDuplicate();

            /** Блок обновления*/
            if (empty($duplicates)) {
            } else {
                $myUpdateOj = new duplicate_sdk\LeadDuplicatesUpdate($new_lead_id, $duplicates);
                $myUpdateOj::UpdateNewLead();
                $myUpdateOj::UpdateDuplicatesLead();
            }
        }
    }
}
