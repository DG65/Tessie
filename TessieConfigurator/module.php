<?php
declare(strict_types=1);

class TessieConfigurator extends IPSModule
{
    // Core WS Client module ID (I/O) in Symcon
    private const WS_CLIENT_MODULE_ID = '{D68FD31F-0E90-7019-F16C-1949BD3079EF}';
    private const VEHICLE_MODULE_ID   = '{3F1F7E31-8BA0-4B8F-9B62-47DAD7A0B6C9}';

    // Auth modes
    private const AUTH_HEADER = 0; // Authorization: Bearer <token>
    private const AUTH_QUERY  = 1; // ?access_token=<token>

    public function Create()
    {
        parent::Create();

        // One single token for REST + Telemetry (Tessie supports both with the same token)
        $this->RegisterPropertyString('Token', '');

        $this->RegisterPropertyString('ApiBase', 'https://api.tessie.com');
        $this->RegisterPropertyBoolean('CreateWSClient', true);

        // How to pass the token for REST and Telemetry
        $this->RegisterPropertyInteger('RestAuthMode', self::AUTH_HEADER);
        $this->RegisterPropertyInteger('TelemetryAuthMode', self::AUTH_QUERY);

        $this->RegisterPropertyBoolean('EnableTelemetryInVehicle', true);

        // Backward compatibility (older properties). If set, we read them as fallback.
        $this->RegisterPropertyString('ApiToken', '');
        $this->RegisterPropertyString('TelemetryToken', '');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(102);
    }

    public function GetConfigurationForm()
    {
        $token = $this->getUnifiedToken();

        $elements = [
            ['type' => 'Label', 'label' => 'Tessie Configurator – Fahrzeuge anlegen'],
            ['type' => 'ValidationTextBox', 'name' => 'Token', 'caption' => 'Tessie Token (für REST + Telemetrie)'],
            ['type' => 'ValidationTextBox', 'name' => 'ApiBase', 'caption' => 'API Base URL', 'default' => 'https://api.tessie.com'],

            ['type' => 'Select', 'name' => 'RestAuthMode', 'caption' => 'REST Authentifizierung',
                'options' => [
                    ['caption' => 'Header: Authorization: Bearer <token>', 'value' => self::AUTH_HEADER],
                    ['caption' => 'URL-Query: ?access_token=<token>', 'value' => self::AUTH_QUERY]
                ]
            ],

            ['type' => 'CheckBox', 'name' => 'CreateWSClient', 'caption' => 'WS Client für Telemetrie automatisch anlegen/verbinden'],

            ['type' => 'Select', 'name' => 'TelemetryAuthMode', 'caption' => 'Telemetrie Authentifizierung',
                'options' => [
                    ['caption' => 'URL-Query: wss://.../<VIN>?access_token=<token>', 'value' => self::AUTH_QUERY],
                    ['caption' => 'Header: Authorization: Bearer <token>', 'value' => self::AUTH_HEADER]
                ]
            ],

            ['type' => 'CheckBox', 'name' => 'EnableTelemetryInVehicle', 'caption' => 'TelemetryEnabled in TessieVehicle automatisch aktivieren'],

            ['type' => 'Label', 'label' => 'Hinweis: Alte Felder ApiToken/TelemetryToken wurden durch „Token“ ersetzt und werden nur noch als Fallback gelesen.'],
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

    private function getUnifiedToken(): string
    {
        $token = trim($this->ReadPropertyString('Token'));
        if ($token !== '') {
            return $token;
        }
        // Backward compatibility
        $old = trim($this->ReadPropertyString('ApiToken'));
        if ($old !== '') {
            return $old;
        }
        $oldTel = trim($this->ReadPropertyString('TelemetryToken'));
        if ($oldTel !== '') {
            return $oldTel;
        }
        return '';
    }

    /**
     * Create chain must start with the device (TessieVehicle) and then go upwards to parents/I/O.
     * The WebSocket client is an I/O instance and MUST be last in the chain.
     */
    private function buildCreateChain(string $vin, string $name, string $token): array
    {
        $createWS        = (bool)$this->ReadPropertyBoolean('CreateWSClient');
        $enableTelemetry = (bool)$this->ReadPropertyBoolean('EnableTelemetryInVehicle');

        $vehicleCfg = [
            'VIN'              => $vin,
            'ApiToken'         => $token,
            'ApiBase'          => trim($this->ReadPropertyString('ApiBase')),
            'TelemetryEnabled' => ($enableTelemetry && $createWS)
        ];

        // If we shouldn't create a WS client, create only the vehicle instance
        if (!$createWS) {
            return [
                'moduleID'      => self::VEHICLE_MODULE_ID,
                'configuration' => $vehicleCfg,
                'name'          => $name
            ];
        }

        $telemetryMode = (int)$this->ReadPropertyInteger('TelemetryAuthMode');

        // Build WS URL + headers
        $wsUrl = 'wss://streaming.tessie.com/' . rawurlencode($vin);
        $headersArr = [];

        if ($telemetryMode === self::AUTH_QUERY) {
            $wsUrl .= '?access_token=' . rawurlencode($token);
        } else {
            // Header auth
            $headersArr[] = ['Name' => 'Authorization', 'Value' => 'Bearer ' . $token];
        }

        $wsCfg = [
            // use booleans (avoid true vs 1 differences in "Prüfen")
            'Active'            => true,
            'Headers'           => json_encode($headersArr),
            'Type'              => 0, // Text (JSON)
            'URL'               => $wsUrl,
            'VerifyCertificate' => true
        ];

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

        $authMode = (int)$this->ReadPropertyInteger('RestAuthMode');
        $url = $base . $path;

        $headers = ['Accept: application/json'];

        if ($authMode === self::AUTH_QUERY) {
            // Add token as query parameter
            $sep = (strpos($url, '?') === false) ? '?' : '&';
            $url .= $sep . 'access_token=' . rawurlencode($token);
        } else {
            // Authorization header
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

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
