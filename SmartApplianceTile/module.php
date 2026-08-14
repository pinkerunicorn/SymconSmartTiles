<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class HausgeraeteKachel extends IPSModuleStrict
{
    use SmartLog_Trait;
    use DeviceAvailability_Trait;

    public function Create(): void
    {
        parent::Create();
        $this->DA_RegisterAvailability(900);
        $this->SetVisualizationType(1);
        $this->RegisterPropertyString('Appliances', '[]');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();

        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }

        $appliances = json_decode($this->ReadPropertyString('Appliances'), true);
        if (is_array($appliances)) {
            foreach ($appliances as $app) {
                foreach (['StatusID', 'PhaseID', 'ProgressID', 'RemainingTimeID'] as $key) {
                    $id = (int)($app[$key] ?? 0);
                    if ($id > 1 && @IPS_ObjectExists($id)) {
                        $this->RegisterReference($id);
                        $this->RegisterMessage($id, VM_UPDATE);
                    }
                }
            }
        }

        $this->SetStatus(102);
        
        // Push initial data to WebFront clients
        $this->PushTileUpdate();
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
        // JSON_HEX_QUOT and JSON_HEX_APOS ensure safe injection into script tags
        $initialData = json_encode($this->CollectCurrentData(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $html = str_replace("'__INITIAL_DATA__'", $initialData, $html);
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
        $data = ['appliances' => []];
        $appliances = json_decode($this->ReadPropertyString('Appliances'), true);
        if (is_array($appliances)) {
            foreach ($appliances as $index => $app) {
                $statusID = (int)($app['StatusID'] ?? 0);
                $phaseID = (int)($app['PhaseID'] ?? 0);
                $progressID = (int)($app['ProgressID'] ?? 0);
                $remainingID = (int)($app['RemainingTimeID'] ?? 0);

                $statusVal = $this->SafeGetValueFormatted($statusID);
                $phaseVal = $this->SafeGetValueFormatted($phaseID);
                $progressVal = $this->SafeGetValue($progressID);
                
                // For remaining time, we provide both raw and formatted
                $remainingRaw = $this->SafeGetValue($remainingID);
                $remainingFmt = $this->SafeGetValueFormatted($remainingID);

                // If empty or zero, we don't show certain things
                // But JS will handle that

                $data['appliances'][] = [
                    'id' => $index,
                    'name' => $app['Name'] ?? 'Unbekannt',
                    'icon' => $app['Icon'] ?? 'Washer',
                    'status' => $statusVal,
                    'phase' => $phaseVal,
                    'progress' => (int)$progressVal,
                    'remaining_raw' => (int)$remainingRaw,
                    'remaining_fmt' => $remainingFmt
                ];
            }
        }
        return $data;
    }

    private function SafeGetValue(int $varID): mixed
    {
        if ($varID > 0 && IPS_VariableExists($varID)) {
            return GetValue($varID);
        }
        return 0;
    }

    private function SafeGetValueFormatted(int $varID): string
    {
        if ($varID > 0 && IPS_VariableExists($varID)) {
            return (string)GetValueFormatted($varID);
        }
        return '';
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "Label",
            "caption": "Füge deine Hausgeräte (Miele Waschmaschine, Trockner, Geschirrspüler) in diese Liste ein."
        },
        {
            "type": "List",
            "name": "Appliances",
            "caption": "Geräte",
            "rowCount": 5,
            "add": true,
            "delete": true,
            "columns": [
                {
                    "caption": "Name",
                    "name": "Name",
                    "width": "150px",
                    "add": "Waschmaschine",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Icon",
                    "name": "Icon",
                    "width": "120px",
                    "add": "Washer",
                    "edit": {
                        "type": "Select",
                        "options": [
                            {"caption": "Waschmaschine", "value": "Washer"},
                            {"caption": "Trockner", "value": "Dryer"},
                            {"caption": "Geschirrspüler", "value": "Dishwasher"}
                        ]
                    }
                },
                {
                    "caption": "Status",
                    "name": "StatusID",
                    "width": "auto",
                    "add": 0,
                    "edit": { "type": "SelectVariable" }
                },
                {
                    "caption": "Phase",
                    "name": "PhaseID",
                    "width": "auto",
                    "add": 0,
                    "edit": { "type": "SelectVariable" }
                },
                {
                    "caption": "Fortschritt (%)",
                    "name": "ProgressID",
                    "width": "auto",
                    "add": 0,
                    "edit": { "type": "SelectVariable" }
                },
                {
                    "caption": "Restzeit",
                    "name": "RemainingTimeID",
                    "width": "auto",
                    "add": 0,
                    "edit": { "type": "SelectVariable" }
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