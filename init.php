// Обработчик события "Создание нового лида"
AddEventHandler('crm', 'OnAfterCrmLeadAdd', array('UpdateDuplicateLead', 'sdkInit'), 100,
    $_SERVER['DOCUMENT_ROOT'] . "/local/include/updateDuplicateLead.php");
