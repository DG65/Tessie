<?php
declare(strict_types=1);

class TessieConfigurator extends IPSModule
{
    // Core WS Client module ID (I/O) in Symcon
    private const WS_CLIENT_MODULE_ID = '{D68FD31F-0E90-7019-F16C-1949BD3079EF}';
    private const VEHICLE_MODULE_ID   = '{3F1F7E31-8BA0-4B8F-9B62-47DAD7A0B6C9}';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('ApiToken', '');
        $this->RegisterPropertyString('ApiBase', 'https://api.tessie.com');
        $this->RegisterPropertyBoolean('CreateWSClient', true);

        // Can be either:
        //  - token only (access_token value)
        //  - full ws/wss URL (wss://streaming.tessie.com/<VIN>?access_token=...)
        $this->RegisterPropertyString('TelemetryToken', '');

        $this->RegisterPropertyBoolean('EnableTelemetryInVehicle', true);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetStatus(102);
    }

    public function GetConfigurationForm(): string
    {
        $token = trim($this->ReadPropertyString('ApiToken'));

        $elements = [
            ['type' => 'Label', 'label' => 'Tessie Configurator – Fahrzeuge anlegen'],
            ['type' => 'ValidationTextBox', 'name' => 'ApiToken', 'caption' => 'API Token (REST)'],
            ['type' => 'ValidationTextBox', 'name' => 'ApiBase', 'caption' => 'API Base URL'],
            ['type' => 'CheckBox', 'name' => 'CreateWSClient', 'caption' => 'WS Client für Telemetrie automatisch anlegen/verbinden'],
            ['type' => 'ValidationTextBox', 'name' => 'TelemetryToken', 'caption' => 'Telemetry Token ODER komplette wss:// URL'],
            ['type' => 'CheckBox', 'name' => 'EnableTelemetryInVehicle', 'caption' => 'TelemetryEnabled in TessieVehicle automatisch aktivieren'],
        ];

        $values = [];
        if ($token !== '') {
            $vehicles = $this->fetchVehicles($token);
            foreach ($vehicles as $v) {
                $vin = (string)($v['vin'] ?? '');
                if ($vin === '') {
                    continue;
                }

                $name = (string)($v['display_name'] ?? $v['name'] ?? $vin);
                $instanceId = $this->findVehicleInstance($vin);

                $create = $this->buildCreateChain($vin, $name, $token);

                $values[] = [
                    'name'       => $name,
                    'address'    => $vin,
                    'instanceID' => $instanceId,
                    'create'     => $create
                ];
            }
        }

        $form = [
            'elements' => $elements,
            'actions'  => [
                [
                    'type'     => 'Configurator',
                    'name'     => 'Vehicles',
                    'caption'  => 'Fahrzeuge',
                    'rowCount' => 12,
                    'delete'   => true,
                    'values'   => $values
                ]
            ]
        ];

        return json_encode($form);
    }

    /**
     * Create chain must start with the device (TessieVehicle) and then go upwards to parents/I/O.
     * The WebSocket client is an I/O instance and MUST be last in the chain. [1](https://jamesgopsill.github.io/octoprint-client/classes/OctoPrintClient.html)[2](https://help.tessie.com/article/65-developer-api)
     */
    private function buildCreateChain(string $vin, string $name, string $apiToken): array
    {
        $createWS        = (bool)$this->ReadPropertyBoolean('CreateWSClient');
        $telemetryInput  = trim($this->ReadPropertyString('TelemetryToken'));
        $enableTelemetry = (bool)$this->ReadPropertyBoolean('EnableTelemetryInVehicle');

        $vehicleCfg = [
            'VIN'              => $vin,
            'ApiToken'         => $apiToken,
            'ApiBase'          => trim($this->ReadPropertyString('ApiBase')),
            'TelemetryEnabled' => ($enableTelemetry && $createWS && $telemetryInput !== '')
        ];

        // If we shouldn't create a WS client, create only the vehicle instance
        if (!$createWS || $telemetryInput === '') {
            return [
                'moduleID'       => self::VEHICLE_MODULE_ID,
                'configuration'  => $vehicleCfg,
                'name'           => $name
            ];
        }

        // Accept TelemetryToken either as full URL or token only
        $wsUrl = $telemetryInput;
        if (!preg_match('#^wss?://#i', $wsUrl)) {
            $wsUrl = 'wss://streaming.tessie.com/' . rawurlencode($vin) . '?access_token=' . rawurlencode($telemetryInput);
        }

        $wsCfg = [
            'Active'            => 1,
            'Headers'           => '[]',
            'Type'              => 0,
            'URL'               => $wsUrl,
            'VerifyCertificate' => 1
        ];

        // ✅ Correct chain: Device -> I/O (I/O must be last)
        return [
            [
                'moduleID'      => self::VEHICLE_MODULE_ID,
                'configuration' => $vehicleCfg,
                'name'          => $name
            ],
            [
                'moduleID'      => self::WS_CLIENT_MODULE_ID,
                'configuration' => $wsCfg,
                'name'          => 'Tessie Telemetry ' . $vin
            ]
        ];
    }

    private function fetchVehicles(string $token): array
    {
        $data = $this->apiRequest($token, 'GET', '/api/1/vehicles');
        $payload = $data['response'] ?? $data;
        if (!is_array($payload)) {
            return [];
        }

        if (isset($payload['vehicles']) && is_array($payload['vehicles'])) {
            return $payload['vehicles'];
        }

        // If it's a plain list
        if (array_keys($payload) === range(0, count($payload) - 1)) {
            return $payload;
        }

        return [];
    }

    private function apiRequest(string $token, string $method, string $path): array
    {
        $base = rtrim(trim($this->ReadPropertyString('ApiBase')), '/');
        $url = $base . $path;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Authorization: Bearer ' . $token
        ]);

        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            $this->SendDebug('ApiRequest', 'cURL error: ' . $err, 0);
            return [];
        }

        $json = json_decode($resp, true);
        if (!is_array($json)) {
            $this->SendDebug('ApiRequest', 'HTTP ' . $code . ' non-JSON: ' . substr($resp, 0, 500), 0);
            return [];
        }

        return $json;
    }

    private function findVehicleInstance(string $vin): int
    {
        $instances = IPS_GetInstanceListByModuleID(self::VEHICLE_MODULE_ID);
        foreach ($instances as $iid) {
            $cfg = json_decode(IPS_GetConfiguration($iid), true);
            if (is_array($cfg) && ($cfg['VIN'] ?? '') === $vin) {
                return $iid;
            }
        }
        return 0;
    }
}
