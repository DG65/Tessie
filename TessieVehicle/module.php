<?php
declare(strict_types=1);

class TessieVehicle extends IPSModule
{
    // ---- Action-Idents (Variables) ----
    private const ACT_LOCKED         = 'act_locked';
    private const ACT_CLIMATE        = 'act_climate';
    private const ACT_START_CHARGING = 'act_charging';
    private const ACT_CHARGE_LIMIT   = 'act_charge_limit';
    private const ACT_CHARGING_AMPS  = 'act_charging_amps';
    private const ACT_FLASH          = 'act_flash';
    private const ACT_HONK           = 'act_honk';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('ApiToken', '');
        $this->RegisterPropertyString('VIN', '');
        $this->RegisterPropertyString('ApiBase', 'https://api.tessie.com');
        $this->RegisterPropertyBoolean('TelemetryEnabled', false);
        $this->RegisterPropertyInteger('UpdateInterval', 300);

        $this->RegisterTimer('UpdateTimer', 0, "TESSIE_Update(\$_IPS['TARGET']);");
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $interval = max(0, (int)$this->ReadPropertyInteger('UpdateInterval'));
        $this->SetTimerInterval('UpdateTimer', $interval > 0 ? $interval * 1000 : 0);

        // Profiles müssen vor MaintainVariable existieren
        $this->ensureProfiles();

        // Action-Setup darf Instanz-Erstellung nicht verhindern (ApplyChanges läuft beim Create/Übernehmen)
        try {
            $this->ensureActionVariables();
        } catch (Throwable $e) {
            $this->LogMessage('ensureActionVariables() übersprungen: ' . $e->getMessage(), KL_WARNING);
        }
    }

    // ---------------- Public API ----------------

    public function Update(): void
    {
        $token = trim($this->ReadPropertyString('ApiToken'));
        $vin   = trim($this->ReadPropertyString('VIN'));
        if ($token === '' || $vin === '') {
            return;
        }

        $path = $this->buildVehiclePath($vin, '/vehicle_data');
        $data = $this->apiRequest($token, 'GET', $path);
        $payload = $data['response'] ?? $data;
        if (!is_array($payload)) {
            return;
        }

        // Optional: Sync action vars from REST payload
        $this->syncActionVarsFromRest($payload);
    }

    public function ReceiveData($JSONString): void
    {
        if (!$this->ReadPropertyBoolean('TelemetryEnabled')) {
            return;
        }

        $pkt = json_decode($JSONString, true);
        if (!is_array($pkt)) {
            return;
        }

        $buffer = utf8_decode((string)($pkt['Buffer'] ?? ''));
        if ($buffer === '') {
            return;
        }

        // Telemetry ist projektspezifisch; hier nur Debug, damit nichts crasht
        $this->SendDebug('Telemetry', $buffer, 0);
    }

    /**
     * Wird von Symcon aufgerufen, wenn in der Visualisierung auf eine EnableAction-Variable geklickt wird. [2](https://github.com/demel42/IPSymconBuderusKM200)
     */
    public function RequestAction($Ident, $Value)
    {
        $token = trim($this->ReadPropertyString('ApiToken'));
        $vin   = trim($this->ReadPropertyString('VIN'));

        if ($token === '' || $vin === '') {
            throw new Exception('ApiToken oder VIN fehlt.');
        }

        switch ($Ident) {
            case self::ACT_LOCKED:
                $this->cmdSimple($vin, $token, ((bool)$Value) ? 'door_lock' : 'door_unlock');
                SetValueBoolean($this->GetIDForIdent($Ident), (bool)$Value);
                break;

            case self::ACT_CLIMATE:
                $this->cmdSimple($vin, $token, ((bool)$Value) ? 'auto_conditioning_start' : 'auto_conditioning_stop');
                SetValueBoolean($this->GetIDForIdent($Ident), (bool)$Value);
                break;

            case self::ACT_START_CHARGING:
                $this->cmdSimple($vin, $token, ((bool)$Value) ? 'charge_start' : 'charge_stop');
                SetValueBoolean($this->GetIDForIdent($Ident), (bool)$Value);
                break;

            case self::ACT_CHARGE_LIMIT:
                $limit = max(0, min(100, (int)$Value));
                $this->cmdSimple($vin, $token, 'set_charge_limit', ['percent' => $limit]);
                SetValueInteger($this->GetIDForIdent($Ident), $limit);
                break;

            case self::ACT_CHARGING_AMPS:
                $amps = max(1, min(48, (int)$Value));
                $this->cmdSimple($vin, $token, 'set_charging_amps', ['amps' => $amps]);
                SetValueInteger($this->GetIDForIdent($Ident), $amps);
                break;

            case self::ACT_FLASH:
                if ((bool)$Value) {
                    $this->cmdSimple($vin, $token, 'flash_lights');
                }
                // Button-Reset
                SetValueBoolean($this->GetIDForIdent($Ident), false);
                break;

            case self::ACT_HONK:
                if ((bool)$Value) {
                    $this->cmdSimple($vin, $token, 'honk_horn');
                }
                // Button-Reset
                SetValueBoolean($this->GetIDForIdent($Ident), false);
                break;

            default:
                throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
    }

    // ---------------- Action variables ----------------

    private function ensureActionVariables(): void
    {
        $catId = $this->ensureCategory('Aktionen');

        $this->MaintainVariable(self::ACT_LOCKED, 'Verriegelt', VARIABLETYPE_BOOLEAN, '~Lock', 0, true);
        $id = $this->GetIDForIdent(self::ACT_LOCKED);
        $this->EnableAction(self::ACT_LOCKED);
        $this->ensureLink($catId, $id, 'action.locked', 'Verriegelt');

        $this->MaintainVariable(self::ACT_CLIMATE, 'Klima', VARIABLETYPE_BOOLEAN, '~Switch', 0, true);
        $id = $this->GetIDForIdent(self::ACT_CLIMATE);
        $this->EnableAction(self::ACT_CLIMATE);
        $this->ensureLink($catId, $id, 'action.climate', 'Klima');

        $this->MaintainVariable(self::ACT_START_CHARGING, 'Laden', VARIABLETYPE_BOOLEAN, '~Switch', 0, true);
        $id = $this->GetIDForIdent(self::ACT_START_CHARGING);
        $this->EnableAction(self::ACT_START_CHARGING);
        $this->ensureLink($catId, $id, 'action.charging', 'Laden');

        $this->MaintainVariable(self::ACT_CHARGE_LIMIT, 'Ladelimit (%)', VARIABLETYPE_INTEGER, 'Tessie.PercentInt', 0, true);
        $id = $this->GetIDForIdent(self::ACT_CHARGE_LIMIT);
        $this->EnableAction(self::ACT_CHARGE_LIMIT);
        $this->ensureLink($catId, $id, 'action.charge_limit', 'Ladelimit (%)');

        $this->MaintainVariable(self::ACT_CHARGING_AMPS, 'Ladestrom (A)', VARIABLETYPE_INTEGER, 'Tessie.Amps', 0, true);
        $id = $this->GetIDForIdent(self::ACT_CHARGING_AMPS);
        $this->EnableAction(self::ACT_CHARGING_AMPS);
        $this->ensureLink($catId, $id, 'action.charging_amps', 'Ladestrom (A)');

        // "Button" als Boolean + Reset
        $this->MaintainVariable(self::ACT_FLASH, 'Licht blinken', VARIABLETYPE_BOOLEAN, '~Switch', 0, true);
        $id = $this->GetIDForIdent(self::ACT_FLASH);
        $this->EnableAction(self::ACT_FLASH);
        $this->ensureLink($catId, $id, 'action.flash', 'Licht blinken');

        $this->MaintainVariable(self::ACT_HONK, 'Hupe', VARIABLETYPE_BOOLEAN, '~Switch', 0, true);
        $id = $this->GetIDForIdent(self::ACT_HONK);
        $this->EnableAction(self::ACT_HONK);
        $this->ensureLink($catId, $id, 'action.honk', 'Hupe');
    }

    private function syncActionVarsFromRest(array $payload): void
    {
        // defensive: set only if fields exist
        try {
            if (isset($payload['response']) && is_array($payload['response'])) {
                $payload = $payload['response'];
            }

            // Vehicle locked state
            $locked = $payload['vehicle_state']['locked'] ?? null;
            if (is_bool($locked) && $this->variableExistsByIdent(self::ACT_LOCKED)) {
                SetValueBoolean($this->GetIDForIdent(self::ACT_LOCKED), $locked);
            }

            // Charging state
            $charging = $payload['charge_state']['charging_state'] ?? null;
            if (is_string($charging) && $this->variableExistsByIdent(self::ACT_START_CHARGING)) {
                $isCharging = in_array(strtolower($charging), ['charging', 'starting'], true);
                SetValueBoolean($this->GetIDForIdent(self::ACT_START_CHARGING), $isCharging);
            }

            // Charge limit
            $limit = $payload['charge_state']['charge_limit_soc'] ?? null;
            if (is_numeric($limit) && $this->variableExistsByIdent(self::ACT_CHARGE_LIMIT)) {
                SetValueInteger($this->GetIDForIdent(self::ACT_CHARGE_LIMIT), (int)$limit);
            }

            // Charging amps
            $amps = $payload['charge_state']['charge_current_request'] ?? null;
            if (is_numeric($amps) && $this->variableExistsByIdent(self::ACT_CHARGING_AMPS)) {
                SetValueInteger($this->GetIDForIdent(self::ACT_CHARGING_AMPS), (int)$amps);
            }
        } catch (Throwable $e) {
            // never break Update
            $this->SendDebug('syncActionVarsFromRest', $e->getMessage(), 0);
        }
    }

    private function variableExistsByIdent(string $ident): bool
    {
        return @IPS_GetObjectIDByIdent($ident, $this->InstanceID) > 0;
    }

    // ---------------- Profiles ----------------

    private function ensureProfiles(): void
    {
        if (!IPS_VariableProfileExists('Tessie.PercentInt')) {
            IPS_CreateVariableProfile('Tessie.PercentInt', 1);
            IPS_SetVariableProfileText('Tessie.PercentInt', '', ' %');
            IPS_SetVariableProfileValues('Tessie.PercentInt', 0, 100, 1);
            IPS_SetVariableProfileDigits('Tessie.PercentInt', 0);
            IPS_SetVariableProfileIcon('Tessie.PercentInt', 'Intensity');
        }

        if (!IPS_VariableProfileExists('Tessie.Amps')) {
            IPS_CreateVariableProfile('Tessie.Amps', 1);
            IPS_SetVariableProfileText('Tessie.Amps', '', ' A');
            IPS_SetVariableProfileValues('Tessie.Amps', 0, 48, 1);
            IPS_SetVariableProfileDigits('Tessie.Amps', 0);
            IPS_SetVariableProfileIcon('Tessie.Amps', 'Electricity');
        }
    }

    // ---------------- Links / Categories ----------------

    private function ensureCategory(string $name): int
    {
        $ident = $this->makeCategoryIdent($name);
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id <= 0) {
            $id = IPS_CreateCategory();
            IPS_SetParent($id, $this->InstanceID);
            IPS_SetIdent($id, $ident);
            IPS_SetName($id, $name);
        }
        return $id;
    }

    private function makeCategoryIdent(string $name): string
    {
        $base = preg_replace('/[^A-Za-z0-9]/', '_', $name);
        $base = preg_replace('/_+/', '_', (string)$base);
        $base = trim((string)$base, '_');
        $hash = substr(md5($name), 0, 8);
        $ident = 'CAT_' . substr($base, 0, max(1, 64 - 4 - 1 - 8)) . '_' . $hash;
        return $ident;
    }

    private function ensureLink(int $catId, $targetId, string $fullKey, string $label): void
    {
        if (!is_int($targetId) || $targetId <= 0 || !IPS_ObjectExists($targetId)) {
            return;
        }

        $linkIdent = 'LNK_' . $this->makeIdent($fullKey);
        $linkId = @IPS_GetObjectIDByIdent($linkIdent, $catId);
        if ($linkId <= 0) {
            $linkId = IPS_CreateLink();
            IPS_SetParent($linkId, $catId);
            IPS_SetIdent($linkId, $linkIdent);
        }
        IPS_SetName($linkId, $label);
        IPS_SetLinkTargetID($linkId, $targetId);
    }

    private function makeIdent(string $s): string
    {
        $s = preg_replace('/[^a-zA-Z0-9_]/', '_', $s);
        $s = preg_replace('/_+/', '_', (string)$s);
        return substr((string)$s, 0, 64);
    }

    // ---------------- Commands / HTTP ----------------

    private function cmdSimple(string $command, ?array $body = null): void
    {
        $path = $this->buildVehiclePath($vin, '/command/' . rawurlencode($command)) . '?wait_for_completion=true';
        if ($body !== null && count($body) === 0) {
        $body = null; // KEIN "[]"-Body senden!
        $resp = $this->apiRequest($token, 'POST', $path, $body);

        $ok = (bool)($resp['result'] ?? ($resp['response']['result'] ?? false));
        if (!$ok) {
            $this->SendDebug('Command', 'Failed: ' . $command . ' ' . json_encode($resp), 0);
        }
    }

    private function buildVehiclePath(string $vin, string $suffix): string
    {
        $base = rtrim(trim($this->ReadPropertyString('ApiBase')), '/');

        // If user set base already to .../api/1/vehicles, don't duplicate
        if (preg_match('#/api/1/vehicles$#', $base)) {
            return '/api/1/vehicles/' . rawurlencode($vin) . $suffix;
        }

        // Normal default
        return '/api/1/vehicles/' . rawurlencode($vin) . $suffix;
    }

    private function apiRequest(string $token, string $method, string $path, array $body = null): array
    {
        $base = rtrim(trim($this->ReadPropertyString('ApiBase')), '/');

        // Ensure leading slash
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        $url = $base . $path;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token
        ];

        
        if ($body !== null && !(is_array($body) && count($body) === 0)) {
            $jsonBody = json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
            $headers[] = 'Content-Type: application/json';

        }

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
}
