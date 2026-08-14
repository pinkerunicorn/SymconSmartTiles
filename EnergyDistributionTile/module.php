<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class EnergieverteilungKachel extends IPSModuleStrict
{
    use DeviceAvailability_Trait;
    public function Create(): void
    {
        parent::Create();
        $this->DA_RegisterAvailability(900);

        // Aktiviert das HTML-SDK für native Kachel-Visualisierung
        $this->SetVisualizationType(1);

        // Zentrale Daten (Haus)
        $this->RegisterPropertyString('HouseName', 'Haus');
        $this->RegisterPropertyInteger('HouseConsumptionID', 0);
        $this->RegisterPropertyInteger('HouseCostID', 0);

        // Liste der Verbraucher/Erzeuger
        $this->RegisterPropertyString('Consumers', '[]');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();

        // Alle alten Message-Registrierungen entfernen
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        // --- Auto-generated References ---
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        
        $houseCons = $this->ReadPropertyInteger('HouseConsumptionID');
        if ($houseCons > 1 && @IPS_ObjectExists($houseCons)) {
            $this->RegisterReference($houseCons);
            $this->RegisterMessage($houseCons, VM_UPDATE);
        }
        
        $houseCost = $this->ReadPropertyInteger('HouseCostID');
        if ($houseCost > 1 && @IPS_ObjectExists($houseCost)) {
            $this->RegisterReference($houseCost);
            $this->RegisterMessage($houseCost, VM_UPDATE);
        }

        $consumers = json_decode($this->ReadPropertyString('Consumers'), true);
        if (is_array($consumers)) {
            foreach ($consumers as $c) {
                $cID = (int)($c['ConsumptionID'] ?? 0);
                if ($cID > 1 && @IPS_ObjectExists($cID)) {
                    $this->RegisterReference($cID);
                    $this->RegisterMessage($cID, VM_UPDATE);
                }
                $costID = (int)($c['CostID'] ?? 0);
                if ($costID > 1 && @IPS_ObjectExists($costID)) {
                    $this->RegisterReference($costID);
                    $this->RegisterMessage($costID, VM_UPDATE);
                }
            }
        }
        // ---------------------------------

        $this->SetStatus(102); // Aktiv
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === VM_UPDATE) {
            $this->PushTileUpdate();
        }
    }

    public function GetVisualizationTile(): string
    {
        $htmlPath = __DIR__ . '/module.html';
        if (!file_exists($htmlPath)) {
            return "<html><body>Frontend File Missing</body></html>";
        }
        
        $html = file_get_contents($htmlPath);

        // Initiale Daten direkt ins HTML einbetten
        $initialData = json_encode($this->CollectCurrentData(), JSON_UNESCAPED_UNICODE);
        $html = str_replace('__INITIAL_DATA__', htmlspecialchars($initialData, ENT_QUOTES, 'UTF-8'), $html);

        return $html;
    }

    private function PushTileUpdate(): void
    {
        $data = $this->CollectCurrentData();
        $this->UpdateVisualizationValue(json_encode($data, JSON_UNESCAPED_UNICODE));
        $this->DA_SetAvailable(true);
    }

    private function CollectCurrentData(): array
    {
        $houseConsID = $this->ReadPropertyInteger('HouseConsumptionID');
        $houseCostID = $this->ReadPropertyInteger('HouseCostID');

        $houseCons = $this->SafeGetValue($houseConsID);
        $houseCost = $this->SafeGetValue($houseCostID);
        
        $data = [
            'house' => [
                'name' => $this->ReadPropertyString('HouseName'),
                'consumption' => $houseCons,
                'cost' => $houseCost,
            ],
            'consumers' => []
        ];

        $consumers = json_decode($this->ReadPropertyString('Consumers'), true);
        if (is_array($consumers)) {
            $sumCons = 0.0;
            $sumCost = 0.0;
            
            foreach ($consumers as $index => $c) {
                $cID = (int)($c['ConsumptionID'] ?? 0);
                $costID = (int)($c['CostID'] ?? 0);
                $type = (int)($c['Type'] ?? 0);
                
                $consVal = $this->SafeGetValue($cID);
                $costVal = $this->SafeGetValue($costID);

                // Verbraucher addieren, Erzeuger abziehen (für die Summe)
                if ($type === 0) {
                    $sumCons += $consVal;
                    $sumCost += $costVal;
                } else {
                    $sumCons -= $consVal;
                    $sumCost -= $costVal;
                }

                $data['consumers'][] = [
                    'id' => $index,
                    'name' => $c['Name'] ?? 'Unbekannt',
                    'type' => $type, // 0 = Verbraucher, 1 = Erzeuger
                    'consumption' => $consVal,
                    'cost' => $costVal,
                ];
            }
            
            // Fallback: Wenn keine Haus-Variablen konfiguriert sind, nimm die Summe!
            if ($houseConsID == 0) {
                $data['house']['consumption'] = max(0.0, $sumCons);
            }
            if ($houseCostID == 0) {
                $data['house']['cost'] = max(0.0, $sumCost);
            }
        }

        return $data;
    }

    private function SafeGetValue(int $varID): float
    {
        if ($varID > 0 && IPS_VariableExists($varID)) {
            return (float)GetValue($varID);
        }
        return 0.0;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "Label",
            "caption": "Zentraler Haus-Verbrauch (Mitte)"
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "ValidationTextBox",
                    "name": "HouseName",
                    "caption": "Name (z.B. Haus)"
                },
                {
                    "type": "SelectVariable",
                    "name": "HouseConsumptionID",
                    "caption": "Gesamt-Verbrauch (kWh)"
                },
                {
                    "type": "SelectVariable",
                    "name": "HouseCostID",
                    "caption": "Gesamt-Kosten (€)"
                }
            ]
        },
        {
            "type": "Label",
            "caption": "Geräte & Verbraucher (Nodes)"
        },
        {
            "type": "List",
            "name": "Consumers",
            "caption": "Geräte",
            "rowCount": 8,
            "add": true,
            "delete": true,
            "columns": [
                {
                    "caption": "Typ",
                    "name": "Type",
                    "width": "120px",
                    "add": 0,
                    "edit": {
                        "type": "Select",
                        "options": [
                            {"caption": "Verbraucher", "value": 0},
                            {"caption": "Erzeuger", "value": 1}
                        ]
                    }
                },
                {
                    "caption": "Name",
                    "name": "Name",
                    "width": "200px",
                    "add": "",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Verbrauch (kWh)",
                    "name": "ConsumptionID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Kosten (€)",
                    "name": "CostID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                }
            ]
        }
    ],
    "actions": [
        {
            "type": "Label",
            "caption": "Diese Instanz erzeugt direkt eine HTML-Kachel im WebFront (WebFramework)."
        }
    ]
}
EOT;
    }
}
