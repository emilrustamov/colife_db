<?php
// ====================== НАСТРОЙКИ ======================
$entityTypeIdApartments = 144;
$linkField              = 'contactId';
$stageField             = 'stageId';
$typeField              = 'TYPE_ID';
$stageEx                = 'DT144_10:FAIL';
$webhookUrl             = 'https://colifeae.bitrix24.eu/rest/106926/m2626akz7j6yvq9b/';

// Коды типов контактов
$landlordCode    = '1';           // Landlord
$exLandlordCode  = 'UC_AHAW7N';   // ex-Landlord

// Настройки задержек
$delayBetweenRequests = 500000;   // 0.5 секунды (500 000 микросекунд)
$maxRetries = 3;                   // Максимум повторных попыток при ошибке
// ======================================================

function bitrixRequest($method, $params = [], $retryCount = 0) {
    global $webhookUrl, $delayBetweenRequests, $maxRetries;
    
    $url = $webhookUrl . $method;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    // Если превышен лимит - ждём и пробуем снова
    if (isset($result['error']) && $result['error'] === 'QUERY_LIMIT_EXCEEDED' && $retryCount < $maxRetries) {
        $waitTime = ($retryCount + 1) * 2; // 2, 4, 6 секунд
        echo "   ⏳ Лимит запросов, ждем {$waitTime} сек (попытка " . ($retryCount + 1) . "/{$maxRetries})...\n";
        sleep($waitTime);
        return bitrixRequest($method, $params, $retryCount + 1);
    }
    
    return $result;
}

// ============ ФУНКЦИЯ ОБНОВЛЕНИЯ КОНТАКТА С ЗАДЕРЖКОЙ ============
function updateContact($contactId, $newTypeCode, $landlordCode, $exLandlordCode) {
    global $delayBetweenRequests;
    
    // 1. Получаем текущий тип
    $contactResult = bitrixRequest('crm.contact.get', ['id' => $contactId]);
    
    if (isset($contactResult['error'])) {
        return ['success' => false, 'error' => $contactResult['error']];
    }
    
    $currentTypeCode = $contactResult['result']['TYPE_ID'] ?? null;
    
    if ($currentTypeCode === $newTypeCode) {
        return ['success' => true, 'skipped' => true, 'message' => "уже {$newTypeCode}"];
    }
    
    // Небольшая задержка перед обновлением
    usleep($delayBetweenRequests);
    
    // 2. Обновляем
    $updateResult = bitrixRequest('crm.contact.update', [
        'id' => $contactId,
        'fields' => ['TYPE_ID' => $newTypeCode]
    ]);
    
    if (isset($updateResult['error'])) {
        return ['success' => false, 'error' => $updateResult['error']];
    }
    
    $typeName = ($newTypeCode == $exLandlordCode) ? 'ex-Landlord' : 'Landlord';
    return ['success' => true, 'skipped' => false, 'message' => "{$currentTypeCode} → {$newTypeCode} ({$typeName})"];
}

// ============ ФУНКЦИЯ ПОЛУЧЕНИЯ ВСЕХ АПАРТАМЕНТОВ =============
function getAllApartments($entityTypeId) {
    global $webhookUrl, $linkField, $stageField;
    $allItems = [];
    $start = 0;
    $page = 1;
    
    echo "🔄 Загрузка апартаментов...\n";
    
    do {
        $params = [
            'entityTypeId' => $entityTypeId,
            'select' => ['id', $linkField, $stageField],
            'start' => $start
        ];
        
        $url = $webhookUrl . 'crm.item.list';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        
        if (isset($data['error'])) {
            echo "❌ Ошибка: " . ($data['error_description'] ?? $data['error']) . "\n";
            break;
        }
        
        $items = $data['result']['items'] ?? [];
        
        if (!empty($items)) {
            $allItems = array_merge($allItems, $items);
            echo "📦 Страница {$page}: +" . count($items) . " (всего " . count($allItems) . ")\n";
        }
        
        $start = $data['next'] ?? null;
        $page++;
        
        if ($page > 100) break;
        
        // Задержка между страницами
        usleep(200000); // 0.2 секунды
        
    } while ($start !== null);
    
    return $allItems;
}

// ============ ОСНОВНАЯ ЛОГИКА ============

echo "\n📊 ========== НАЧАЛО ОБРАБОТКИ ==========\n\n";
echo "🔍 Коды типов:\n";
echo "   Landlord: {$landlordCode}\n";
echo "   ex-Landlord: {$exLandlordCode}\n";
echo "⏱️  Задержка между запросами: " . ($delayBetweenRequests / 1000000) . " сек\n\n";

// 1. Получаем все апартаменты
$apartments = getAllApartments($entityTypeIdApartments);

echo "\n✅ Всего апартаментов: " . count($apartments) . "\n\n";

if (count($apartments) === 0) {
    echo "⚠️ Нет апартаментов для обработки\n";
    exit;
}

// 2. Группируем по contactId
$contactStages = [];

foreach ($apartments as $apt) {
    $contactId = $apt[$linkField] ?? null;
    $stageId = $apt[$stageField] ?? null;
    
    if (!$contactId) continue;
    
    if (!isset($contactStages[$contactId])) {
        $contactStages[$contactId] = [];
    }
    $contactStages[$contactId][] = $stageId;
}

echo "📋 Уникальных контактов с апартаментами: " . count($contactStages) . "\n\n";

// 3. Определяем, кому менять тип
$contactsToUpdate = [];
$debugCount = 0;

foreach ($contactStages as $contactId => $stages) {
    $allFailed = true;
    $failedCount = 0;
    
    foreach ($stages as $stage) {
        if ($stage === $stageEx) {
            $failedCount++;
        } else {
            $allFailed = false;
        }
    }
    
    $newTypeCode = $allFailed ? $exLandlordCode : $landlordCode;
    $contactsToUpdate[$contactId] = $newTypeCode;
    
    // Выводим первые 10 для отладки
    if ($debugCount++ < 10) {
        $typeName = $allFailed ? '→ ex-Landlord' : '→ Landlord';
        echo "🔍 Контакт {$contactId}: {$failedCount}/" . count($stages) . " в FAIL {$typeName}\n";
    }
}

echo "\n🎯 Контактов к обновлению: " . count($contactsToUpdate) . "\n\n";
echo "🔄 Начинаем обновление (с задержкой " . ($delayBetweenRequests / 1000000) . " сек между запросами)...\n\n";

// 4. Обновляем контакты
$updatedCount = 0;
$skippedCount = 0;
$errorCount = 0;
$currentCount = 0;
$totalContacts = count($contactsToUpdate);

foreach ($contactsToUpdate as $contactId => $newTypeCode) {
    $currentCount++;
    
    // Показываем прогресс
    echo "[{$currentCount}/{$totalContacts}] Контакт {$contactId}: ";
    
    $result = updateContact($contactId, $newTypeCode, $landlordCode, $exLandlordCode);
    
    if ($result['success']) {
        if ($result['skipped']) {
            echo "⏭️ " . $result['message'] . "\n";
            $skippedCount++;
        } else {
            echo "✅ " . $result['message'] . "\n";
            $updatedCount++;
        }
    } else {
        echo "❌ Ошибка: " . ($result['error'] ?? 'неизвестная ошибка') . "\n";
        $errorCount++;
    }
    
    // Основная задержка между запросами
    usleep($delayBetweenRequests);
}

// 5. Итог
echo "\n========================================\n";
echo "📊 ИТОГ:\n";
echo "========================================\n";
echo "- Апартаментов: " . count($apartments) . "\n";
echo "- Уникальных контактов: " . count($contactStages) . "\n";
echo "- Обновлено: {$updatedCount}\n";
echo "- Пропущено (уже верный тип): {$skippedCount}\n";
echo "- Ошибок: {$errorCount}\n";
echo "========================================\n";
?>