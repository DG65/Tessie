<?php

declare(strict_types=1);

class TessieVehicle extends IPSModule
{
    private const GUID_RX = '{018EF6B5-AB94-40C6-AA53-46943E824ACF}';

    // Action variable idents
    private const ACT_LOCKED = 'act_locked';
    private const ACT_CLIMATE = 'act_climate';
    private const ACT_START_CHARGING = 'act_charging';
    private const ACT_CHARGE_LIMIT = 'act_charge_limit';
    private const ACT_CHARGING_AMPS = 'act_charging_amps';
    private const ACT_FLASH = 'act_flash';
    private const ACT_HONK = 'act_honk';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('ApiToken', '');
        $this->RegisterPropertyString('VIN', '');
        $this->RegisterPropertyString('ApiBase', 'https://api.tessie.com');
        $this->RegisterPropertyBoolean('TelemetryEnabled', false);
        $this->RegisterPropertyInteger('UpdateInterval', 300);

        $this->RegisterTimer('UpdateTimer', 0, 'TESSIE_Update($_IPS['TARGET']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $interval = max(0, (int)$this->ReadPropertyInteger('UpdateInterval'));
        $this->SetTimerInterval('UpdateTimer', $interval > 0 ? $interval * 1000 : 0);

        $this->ensureProfiles();
        $this->ensureActionVariables();
    }

    // ---------------- Public API ----------------

    public function Update(): void
    {
        $token = trim($this->ReadPropertyString('ApiToken'));
        $vin   = trim($this->ReadPropertyString('VIN'));
        if ($token === '' || $vin === '') {
            return;
        }

        $data = $this->apiRequest($token, 'GET', '/api/1/vehicles/' . rawurlencode($vin) . '/vehicle_data');
        $payload = $data['response'] ?? $data;
        if (!is_array($payload)) {
            return;
        }

        $flat = $this->flatten($payload, 'rest');
        foreach ($flat as $fullKey => $val) {
            $this->upsertVariable($fullKey, $val);
        }

        // Sync action vars with current state where available
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

        $payload = null;

        // Typical IO frame: {DataID, Buffer(base64)}
        if (($pkt['DataID'] ?? '') === self::GUID_RX && isset($pkt['Buffer']) && is_string($pkt['Buffer']) && $pkt['Buffer'] !== '') {
            $decoded = base64_decode($pkt['Buffer'], true);
            if ($decoded === false) {
                return;
            }
            $try = json_decode($decoded, true);
            if (!is_array($try)) {
                return; // ping/pong
            }
            $payload = $try;
        } elseif (isset($pkt['data']) && is_array($pkt['data'])) {
            $payload = $pkt;
        } elseif (isset($pkt['Payload']) && is_array($pkt['Payload'])) {
            $payload = $pkt['Payload'];
        } elseif (isset($pkt['Data']) && is_array($pkt['Data'])) {
            $payload = $pkt['Data'];
        }

        if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
            return;
        }

        $vin = trim($this->ReadPropertyString('VIN'));
        if ($vin !== '' && isset($payload['vin']) && is_string($payload['vin']) && $payload['vin'] !== $vin) {
            return;
        }

        $telemetry = [];
        foreach ($payload['data'] as $entry) {
            if (!is_array($entry)) continue;
            $key = $entry['key'] ?? null;
            $val = $entry['value'] ?? null;
            if (!is_string($key) || !is_array($val)) continue;
            if (!empty($val['invalid'])) continue;

            $typedValue = null;
            foreach ($val as $vk => $vv) {
                if ($vk === 'invalid') continue;
                $typedValue = $vv;
                break;
            }
            if ($typedValue === null) continue;

            if (is_string($typedValue) && ctype_digit($typedValue)) {
                $typedValue = (int)$typedValue;
            }

            $telemetry[$key] = $typedValue;
        }

        if (isset($payload['createdAt'])) $telemetry['_createdAt'] = (string)$payload['createdAt'];
        if (isset($payload['isResend']))  $telemetry['_isResend']  = (bool)$payload['isResend'];

        $flat = $this->flatten($telemetry, 'telemetry');
        foreach ($flat as $fullKey => $val) {
            $this->upsertVariable($fullKey, $val);
        }

        // Sync some action vars from telemetry if available
        $this->syncActionVarsFromTelemetry($telemetry);
    }

    public function RequestAction($Ident, $Value): void
    {
        switch ($Ident) {
            case self::ACT_LOCKED:
                $this->cmdLock((bool)$Value);
                break;
            case self::ACT_CLIMATE:
                $this->cmdClimate((bool)$Value);
                break;
            case self::ACT_START_CHARGING:
                $this->cmdCharging((bool)$Value);
                break;
            case self::ACT_CHARGE_LIMIT:
                $this->cmdChargeLimit((int)$Value);
                break;
            case self::ACT_CHARGING_AMPS:
                $this->cmdChargingAmps((int)$Value);
                break;
            case self::ACT_FLASH:
                $this->cmdSimple('flash_lights');
                // reset button variable
                $id = $this->GetIDForIdent(self::ACT_FLASH);
                SetValueBoolean($id, false);
                break;
            case self::ACT_HONK:
                $this->cmdSimple('honk');
                $id = $this->GetIDForIdent(self::ACT_HONK);
                SetValueBoolean($id, false);
                break;
            default:
                throw new Exception('Unknown ident: ' . $Ident);
        }
    }

    // ---------------- Commands ----------------

    private function cmdLock(bool $locked): void
    {
        $this->cmdSimple($locked ? 'lock' : 'unlock');
    }

    private function cmdClimate(bool $on): void
    {
        $this->cmdSimple($on ? 'start_climate' : 'stop_climate');
    }

    private function cmdCharging(bool $on): void
    {
        $this->cmdSimple($on ? 'start_charging' : 'stop_charging');
    }

    private function cmdChargeLimit(int $soc): void
    {
        $soc = max(50, min(100, $soc));
        $this->cmdSimple('set_charge_limit', ['percent' => $soc]);
    }

    private function cmdChargingAmps(int $amps): void
    {
        $amps = max(1, min(48, $amps));
        $this->cmdSimple('set_charging_amps', ['amps' => $amps]);
    }

    private function cmdSimple(string $command, array $body = []): void
    {
        $token = trim($this->ReadPropertyString('ApiToken'));
        $vin   = trim($this->ReadPropertyString('VIN'));
        if ($token === '' || $vin === '') {
            $this->SendDebug('Command', 'Missing token or VIN', 0);
            return;
        }

        // Tessie command endpoint: /{vin}/command/<command>
        $path = '/' . rawurlencode($vin) . '/command/' . $command . '?wait_for_completion=true';
        $resp = $this->apiRequest($token, 'POST', $path, $body);
        $ok = (bool)($resp['result'] ?? ($resp['response']['result'] ?? false));
        if (!$ok) {
            $this->SendDebug('Command', 'Failed: ' . $command . ' ' . json_encode($resp), 0);
        }
    }

    // ---------------- HTTP ----------------

    private function apiRequest(string $token, string $method, string $path, array $body = null): array
    {
        $base = rtrim(trim($this->ReadPropertyString('ApiBase')), '/');
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

        if ($body !== null) {
            $jsonBody = json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $resp = curl_exec($ch);
        $err = curl_error($ch);
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

    // ---------------- Action variables ----------------

    private function ensureActionVariables(): void
    {
        $catId = $this->ensureCategory('Aktionen');

        $this->MaintainVariable(self::ACT_LOCKED, 'Verriegelt', VARIABLETYPE_BOOLEAN, '~Lock', 0, true);
        $id = $this->GetIDForIdent(self::ACT_LOCKED);
        IPS_SetVariableCustomAction($id, $this->InstanceID);
        $this->ensureLink($catId, $id, 'action.locked', 'Verriegelt');

        $this->MaintainVariable(self::ACT_CLIMATE, 'Klima', VARIABLETYPE_BOOLEAN, '~Switch', 0, true);
        $id = $this->GetIDForIdent(self::ACT_CLIMATE);
        IPS_SetVariableCustomAction($id, $this->InstanceID);
        $this->ensureLink($catId, $id, 'action.climate', 'Klima');

        $this->MaintainVariable(self::ACT_START_CHARGING, 'Laden', VARIABLETYPE_BOOLEAN, '~Switch', 0, true);
        $id = $this->GetIDForIdent(self::ACT_START_CHARGING);
        IPS_SetVariableCustomAction($id, $this->InstanceID);
        $this->ensureLink($catId, $id, 'action.charging', 'Laden');

        $this->MaintainVariable(self::ACT_CHARGE_LIMIT, 'Ladelimit (%)', VARIABLETYPE_INTEGER, 'Tessie.PercentInt', 0, true);
        $id = $this->GetIDForIdent(self::ACT_CHARGE_LIMIT);
        IPS_SetVariableCustomAction($id, $this->InstanceID);
        $this->ensureLink($catId, $id, 'action.charge_limit', 'Ladelimit (%)');

        $this->MaintainVariable(self::ACT_CHARGING_AMPS, 'Ladestrom (A)', VARIABLETYPE_INTEGER, 'Tessie.Amps', 0, true);
        $id = $this->GetIDForIdent(self::ACT_CHARGING_AMPS);
        IPS_SetVariableCustomAction($id, $this->InstanceID);
        $this->ensureLink($catId, $id, 'action.charging_amps', 'Ladestrom (A)');

        // "Button" as boolean reset
        $this->MaintainVariable(self::ACT_FLASH, 'Licht blinken', VARIABLETYPE_BOOLEAN, '~Switch', 0, true);
        $id = $this->GetIDForIdent(self::ACT_FLASH);
        IPS_SetVariableCustomAction($id, $this->InstanceID);
        $this->ensureLink($catId, $id, 'action.flash', 'Licht blinken');

        $this->MaintainVariable(self::ACT_HONK, 'Hupe', VARIABLETYPE_BOOLEAN, '~Switch', 0, true);
        $id = $this->GetIDForIdent(self::ACT_HONK);
        IPS_SetVariableCustomAction($id, $this->InstanceID);
        $this->ensureLink($catId, $id, 'action.honk', 'Hupe');

        // Profiles
        $this->ensureIntProfiles();
    }

    private function syncActionVarsFromRest(array $payload): void
    {
        // Common Tesla data locations
        $locked = $payload['vehicle_state']['locked'] ?? null;
        if (is_bool($locked) && @IPS_GetObjectIDByIdent(self::ACT_LOCKED, $this->InstanceID) > 0) {
            $id = $this->GetIDForIdent(self::ACT_LOCKED);
            SetValueBoolean($id, $locked);
        }

        $climateOn = $payload['climate_state']['is_climate_on'] ?? null;
        if (is_bool($climateOn) && @IPS_GetObjectIDByIdent(self::ACT_CLIMATE, $this->InstanceID) > 0) {
            $id = $this->GetIDForIdent(self::ACT_CLIMATE);
            SetValueBoolean($id, $climateOn);
        }

        $charging = $payload['charge_state']['charging_state'] ?? null;
        if (is_string($charging) && @IPS_GetObjectIDByIdent(self::ACT_START_CHARGING, $this->InstanceID) > 0) {
            $on = strtolower($charging) === 'charging';
            $id = $this->GetIDForIdent(self::ACT_START_CHARGING);
            SetValueBoolean($id, $on);
        }

        $limit = $payload['charge_state']['charge_limit_soc'] ?? null;
        if (is_numeric($limit) && @IPS_GetObjectIDByIdent(self::ACT_CHARGE_LIMIT, $this->InstanceID) > 0) {
            $id = $this->GetIDForIdent(self::ACT_CHARGE_LIMIT);
            SetValueInteger($id, (int)$limit);
        }

        $amps = $payload['charge_state']['charge_current_request'] ?? null;
        if (is_numeric($amps) && @IPS_GetObjectIDByIdent(self::ACT_CHARGING_AMPS, $this->InstanceID) > 0) {
            $id = $this->GetIDForIdent(self::ACT_CHARGING_AMPS);
            SetValueInteger($id, (int)$amps);
        }
    }

    private function syncActionVarsFromTelemetry(array $telemetry): void
    {
        if (isset($telemetry['Locked']) && is_bool($telemetry['Locked'])) {
            $id = $this->GetIDForIdent(self::ACT_LOCKED);
            SetValueBoolean($id, (bool)$telemetry['Locked']);
        }
        if (isset($telemetry['HvacPower']) && is_string($telemetry['HvacPower'])) {
            $on = stripos($telemetry['HvacPower'], 'On') !== false;
            $id = $this->GetIDForIdent(self::ACT_CLIMATE);
            SetValueBoolean($id, $on);
        }
        if (isset($telemetry['ChargeLimitSoc']) && is_numeric($telemetry['ChargeLimitSoc'])) {
            $id = $this->GetIDForIdent(self::ACT_CHARGE_LIMIT);
            SetValueInteger($id, (int)$telemetry['ChargeLimitSoc']);
        }
        if (isset($telemetry['ChargeCurrentRequest']) && is_numeric($telemetry['ChargeCurrentRequest'])) {
            $id = $this->GetIDForIdent(self::ACT_CHARGING_AMPS);
            SetValueInteger($id, (int)$telemetry['ChargeCurrentRequest']);
        }
    }

    // ---------------- Generic variable creation (REST/Telemetry) ----------------

    private function upsertVariable(string $fullKey, $val): void
    {
        $catName = $this->categoryFor($fullKey);
        $catId = $this->ensureCategory($catName);

        $label = $this->labelFor($fullKey);
        $ident = $this->makeIdent($fullKey);

        $type = $this->chooseType($fullKey, $val);
        $profile = $this->profileForKey($fullKey, $type);

        $this->MaintainVariable($ident, $label, $type, $profile, 0, true);
        $varId = $this->GetIDForIdent($ident);

        $this->ensureLink($catId, $varId, $fullKey, $label);

        $this->writeValue($varId, $type, $val);
    }

    private function chooseType(string $fullKey, $val): int
    {
        if (is_bool($val)) return VARIABLETYPE_BOOLEAN;
        if (preg_match('/\b(rest\.)?(vehicle_id|user_id|id|id_s)\b/i', $fullKey)) return VARIABLETYPE_STRING;
        if (is_int($val)) return VARIABLETYPE_INTEGER;
        if (is_float($val)) return VARIABLETYPE_FLOAT;
        if (is_string($val) && is_numeric($val)) {
            if (preg_match('/(timestamp|time|_at)$/i', $fullKey)) return VARIABLETYPE_INTEGER;
            return VARIABLETYPE_FLOAT;
        }
        return VARIABLETYPE_STRING;
    }

    private function writeValue(int $varId, int $type, $val): void
    {
        switch ($type) {
            case VARIABLETYPE_BOOLEAN: SetValueBoolean($varId, (bool)$val); break;
            case VARIABLETYPE_INTEGER: SetValueInteger($varId, (int)$val); break;
            case VARIABLETYPE_FLOAT:   SetValueFloat($varId, (float)$val); break;
            default:                  SetValueString($varId, (string)$val); break;
        }
    }

    private function makeIdent(string $s): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '_', $s);
        $clean = preg_replace('/_+/', '_', $clean);
        $clean = trim($clean, '_');

        $hash = substr(md5($s), 0, 10);
        $maxBase = 64 - 1 - strlen($hash);
        if ($maxBase < 1) return substr($hash, 0, 64);

        $base = substr($clean, 0, $maxBase);
        return $base . '_' . $hash;
    }

    private function ensureCategory(string $name): int
    {
        $base = preg_replace('/[^A-Za-z0-9]/', '_', $name);
        $base = preg_replace('/_+/', '_', $base);
        $base = trim($base, '_');
        $hash = substr(md5($name), 0, 8);
        $ident = 'CAT_' . substr($base, 0, max(1, 64 - 4 - 1 - 8)) . '_' . $hash;

        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id <= 0) {
            $id = IPS_CreateCategory();
            IPS_SetParent($id, $this->InstanceID);
            IPS_SetIdent($id, $ident);
            IPS_SetName($id, $name);
        }
        return $id;
    }

    private function ensureLink(int $catId, int $targetId, string $fullKey, string $label): void
    {
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

    private function flatten($data, string $prefix = ''): array
    {
        $out = [];
        $this->flattenWalk($data, $prefix, $out);
        return $out;
    }

    private function flattenWalk($data, string $path, array &$out): void
    {
        if (is_array($data)) {
            $isAssoc = array_keys($data) !== range(0, count($data) - 1);
            foreach ($data as $k => $v) {
                $key = $isAssoc ? (string)$k : ('i' . (string)$k);
                $newPath = $path === '' ? $key : ($path . '.' . $key);
                $this->flattenWalk($v, $newPath, $out);
            }
            return;
        }
        $out[$path] = $data;
    }

    private function labelFor(string $fullKey): string
    {
        return $fullKey;
    }

    private function categoryFor(string $fullKey): string
    {
        $k = strtolower($fullKey);

        if (str_starts_with($k, 'telemetry.')) {
            $k2 = substr($k, strlen('telemetry.'));
            if (preg_match('/(soc|charge|battery|energy|range|charger)/', $k2)) return 'Laden';
            if (preg_match('/(temp|hvac|climate|defrost|seat|heater|wiper)/', $k2)) return 'Klima';
            if (preg_match('/(speed|gps|heading|location|odometer|route|gear)/', $k2)) return 'Fahren';
            if (preg_match('/(lock|sentry|valet|pin|door|trunk)/', $k2)) return 'Sicherheit';
            return 'Telemetry';
        }

        if (strpos($k, 'rest.charge_state.') === 0) return 'Laden';
        if (strpos($k, 'rest.climate_state.') === 0) return 'Klima';
        if (strpos($k, 'rest.drive_state.') === 0) return 'Fahren';
        if (strpos($k, 'rest.vehicle_state.') === 0) {
            if (preg_match('/(locked|sentry|valet|pin|door|trunk)/', $k)) return 'Sicherheit';
            return 'Allgemein';
        }
        if (strpos($k, 'rest.vehicle_config.') === 0) return 'Allgemein';
        if (strpos($k, 'rest.gui_settings.') === 0) return 'Allgemein';
        if (strpos($k, 'action.') === 0) return 'Aktionen';

        return 'Allgemein';
    }

    private function profileForKey(string $fullKey, int $type): string
    {
        $k = strtolower($fullKey);

        if ($type === VARIABLETYPE_BOOLEAN) {
            if (preg_match('/(locked)/', $k)) return '~Lock';
            return '~Switch';
        }

        if ($type === VARIABLETYPE_INTEGER || $type === VARIABLETYPE_FLOAT) {
            if (preg_match('/(soc|percent|battery_level|charge_limit)/', $k)) return 'Tessie.Percent';
            if (preg_match('/(odometer|range|distance)/', $k)) return 'Tessie.Kilometer';
            if (preg_match('/(temp)/', $k)) return 'Tessie.Celsius';
            if (preg_match('/(pressure|tpms)/', $k)) return 'Tessie.Bar';
            if (preg_match('/(speed)/', $k)) return 'Tessie.Kmh';
            if (preg_match('/(power)/', $k)) return 'Tessie.kW';
        }

        return '';
    }

    private function ensureProfiles(): void
    {
        $this->ensureFloatProfile('Tessie.Percent', '%', 0, 100, 1);
        $this->ensureFloatProfile('Tessie.Kilometer', ' km', 0, 1000000, 0.1);
        $this->ensureFloatProfile('Tessie.Celsius', ' °C', -50, 100, 0.1);
        $this->ensureFloatProfile('Tessie.Bar', ' bar', 0, 6, 0.01);
        $this->ensureFloatProfile('Tessie.Kmh', ' km/h', 0, 300, 0.1);
        $this->ensureFloatProfile('Tessie.kW', ' kW', -500, 500, 0.1);
    }

    private function ensureIntProfiles(): void
    {
        if (!IPS_VariableProfileExists('Tessie.PercentInt')) {
            IPS_CreateVariableProfile('Tessie.PercentInt', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('Tessie.PercentInt', '', ' %');
            IPS_SetVariableProfileValues('Tessie.PercentInt', 0, 100, 1);
        }
        if (!IPS_VariableProfileExists('Tessie.Amps')) {
            IPS_CreateVariableProfile('Tessie.Amps', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('Tessie.Amps', '', ' A');
            IPS_SetVariableProfileValues('Tessie.Amps', 0, 48, 1);
        }
    }

    private function ensureFloatProfile(string $name, string $suffix, float $min, float $max, float $step): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, VARIABLETYPE_FLOAT);
        }
        IPS_SetVariableProfileText($name, '', $suffix);
        IPS_SetVariableProfileValues($name, $min, $max, $step);
    }
}
