<?php

function additionalFeeDefinitions(): array
{
    return [
        'environmental_foreigner' => ['Environmental', 'Foreigner', 100.00, 'per person'],
        'environmental_local' => ['Environmental', 'Local', 50.00, 'per person'],
        'environmental_mercedeno' => ['Environmental', 'Mercedeño', 25.00, 'per person'],
        'environmental_senior' => ['Environmental', 'Senior citizen', 40.00, 'per person'],
        'entrance_apuao_grande' => ['Entrance', 'Apuao Grande Island', 30.00, 'per head'],
        'entrance_caringo' => ['Entrance', 'Caringo Island', 20.00, 'per head'],
        'entrance_canimog_day' => ['Entrance', 'Canimog Island — day tour', 100.00, 'per head'],
        'entrance_canimog_overnight' => ['Entrance', 'Canimog Island — overnight', 250.00, 'per head'],
        'docking_apuao_pequena' => ['Docking', 'Apuao Pequeña Island', 500.00, 'per boat'],
        'docking_malasugui' => ['Docking', 'Malasugui Island', 300.00, 'per boat'],
        'docking_canimog' => ['Docking', 'Canimog Island', 500.00, 'per boat'],
        'equipment_snorkeling' => ['Equipment', 'Snorkeling set', 200.00, 'per day'],
        'equipment_kayak' => ['Equipment', 'Kayak', 500.00, 'per day'],
    ];
}

function additionalFeeTableExists(PDO $pdo): bool
{
    static $exists = null;
    if ($exists !== null) return $exists;
    $stmt = $pdo->query("SHOW TABLES LIKE 'additional_fees'");
    return $exists = (bool)$stmt->fetchColumn();
}

function getAdditionalFees(PDO $pdo): array
{
    $definitions = additionalFeeDefinitions();
    $fees = [];
    foreach ($definitions as $code => $definition) {
        $fees[$code] = [
            'category' => $definition[0],
            'label' => $definition[1],
            'amount' => (float)$definition[2],
            'unit' => $definition[3],
        ];
    }

    if (!additionalFeeTableExists($pdo)) return $fees;

    $rows = $pdo->query("SELECT fee_code, category, fee_label, amount, unit FROM additional_fees WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        if (!isset($fees[$row['fee_code']])) continue;
        $fees[$row['fee_code']] = [
            'category' => $row['category'],
            'label' => $row['fee_label'],
            'amount' => (float)$row['amount'],
            'unit' => $row['unit'],
        ];
    }
    return $fees;
}

function additionalFeesForBooking(array $fees): array
{
    return [
        'environmental' => [
            'foreigner' => $fees['environmental_foreigner']['amount'],
            'local' => $fees['environmental_local']['amount'],
            'mercedeno' => $fees['environmental_mercedeno']['amount'],
            'senior' => $fees['environmental_senior']['amount'],
        ],
        'entrance' => [
            'Apuao Grande Island' => $fees['entrance_apuao_grande']['amount'],
            'Caringo Island' => $fees['entrance_caringo']['amount'],
            'Canimog Island' => [
                'day' => $fees['entrance_canimog_day']['amount'],
                'overnight' => $fees['entrance_canimog_overnight']['amount'],
            ],
        ],
        'docking' => [
            'Apuao Pequeña Island' => $fees['docking_apuao_pequena']['amount'],
            'Malasugui Island' => $fees['docking_malasugui']['amount'],
            'Canimog Island' => $fees['docking_canimog']['amount'],
        ],
        'equipment' => [
            'snorkeling' => $fees['equipment_snorkeling']['amount'],
            'kayak' => $fees['equipment_kayak']['amount'],
        ],
    ];
}

