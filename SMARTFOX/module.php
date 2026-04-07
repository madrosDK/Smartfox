<?php

declare(strict_types=1);

class SMARTFOX extends IPSModule
{
    private const MODULE_ID = '{7A6C8F1C-1E5C-4A39-9B40-8A6C510AF165}';

    public function Create(): void
    {
        parent::Create();

        $defaultRegisters = [
            [
                'Enabled'      => true,
                'Address'      => 41012,
                'Name'         => 'Day Energy from grid',
                'Ident'        => 'DayEnergyFromGrid',
                'Type'         => 'uint32',
                'Length'       => 2,
                'Access'       => 'R',
                'Scale'        => 1,
                'Unit'         => 'Wh',
                'Description'  => ''
            ],
            [
                'Enabled'      => true,
                'Address'      => 41014,
                'Name'         => 'Day Energy into grid',
                'Ident'        => 'DayEnergyIntoGrid',
                'Type'         => 'uint32',
                'Length'       => 2,
                'Access'       => 'R',
                'Scale'        => 1,
                'Unit'         => 'Wh',
                'Description'  => ''
            ],
            [
                'Enabled'      => true,
                'Address'      => 41018,
                'Name'         => 'Power total',
                'Ident'        => 'PowerTotal',
                'Type'         => 'int32',
                'Length'       => 2,
                'Access'       => 'R',
                'Scale'        => 1,
                'Unit'         => 'W',
                'Description'  => ''
            ],
            [
                'Enabled'      => true,
                'Address'      => 40400,
                'Name'         => 'Control via Modbus',
                'Ident'        => 'ControlViaModbus',
                'Type'         => 'uint8',
                'Length'       => 1,
                'Access'       => 'RW',
                'Scale'        => 1,
                'Unit'         => '',
                'Description'  => '0=Automatic Control, 1=Control via Modbus'
            ],
            [
                'Enabled'      => false,
                'Address'      => 40403,
                'Name'         => 'Control Relay 1',
                'Ident'        => 'ControlRelay1',
                'Type'         => 'uint8',
                'Length'       => 1,
                'Access'       => 'RW',
                'Scale'        => 1,
                'Unit'         => '',
                'Description'  => '0/1'
            ]
        ];

        $this->RegisterPropertyString('Host', '192.168.1.100');
        $this->RegisterPropertyInteger('Port', 502);
        $this->RegisterPropertyInteger('UnitID', 1);
        $this->RegisterPropertyInteger('AddressBase', 40000);
        $this->RegisterPropertyInteger('UpdateInterval', 30);
        $this->RegisterPropertyString('RegisterConfig', json_encode($defaultRegisters));

        $this->RegisterTimer('UpdateTimer', 0, 'SMARTFOX_Update($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->MaintainProfiles();
        $this->SyncVariables();

        $interval = max(5, $this->ReadPropertyInteger('UpdateInterval'));
        $this->SetTimerInterval('UpdateTimer', $interval * 1000);
    }

    public function RequestAction($Ident, $Value): void
    {
        if ($Ident === 'UpdateNow') {
            $this->Update();
            return;
        }

        $register = $this->FindRegisterByIdent((string) $Ident);
        if ($register === null) {
            throw new Exception('Unbekannte Aktion: ' . $Ident);
        }

        if (strtoupper((string) $register['Access']) !== 'RW') {
            throw new Exception('Register ist nicht schreibbar: ' . $Ident);
        }

        $normalized = $this->NormalizeIncomingValue($register, $Value);
        $this->WriteRegister($register, $normalized);
        $this->UpdateSingleRegister($register);
    }

    public function Update(): void
    {
        foreach ($this->GetRegisters() as $register) {
            try {
                $this->UpdateSingleRegister($register);
            } catch (Throwable $e) {
                $name = (string) $register['Name'];
                $address = (int) $register['Address'];
                $this->SendDebug('UpdateError', $name . ' [' . $address . ']: ' . $e->getMessage(), 0);
            }
        }
    }

    private function UpdateSingleRegister(array $register): void
    {
        $ident = (string) $register['Ident'];
        $value = $this->ReadRegister($register);

        if (!$this->GetIDForIdent($ident)) {
            return;
        }

        switch ($this->GetVariableTypeFromRegister($register)) {
            case VARIABLETYPE_BOOLEAN:
                $this->SetValueBoolean($ident, (bool) $value);
                break;
            case VARIABLETYPE_INTEGER:
                $this->SetValueInteger($ident, (int) $value);
                break;
            case VARIABLETYPE_FLOAT:
                $this->SetValueFloat($ident, (float) $value);
                break;
            default:
                $this->SetValueString($ident, (string) $value);
                break;
        }

        $this->SendDebug('Update', (string) $register['Name'] . ' [' . (string) $register['Address'] . '] = ' . (string) $value, 0);
    }

    private function SyncVariables(): void
    {
        $validIdents = [];
        foreach ($this->GetRegisters() as $register) {
            $ident = (string) $register['Ident'];
            $validIdents[] = $ident;
            $name = (string) $register['Name'];
            $unit = trim((string) $register['Unit']);

            switch ($this->GetVariableTypeFromRegister($register)) {
                case VARIABLETYPE_BOOLEAN:
                    $this->RegisterVariableBoolean($ident, $name, 'SMARTFOX.Switch');
                    break;
                case VARIABLETYPE_INTEGER:
                    $this->RegisterVariableInteger($ident, $name, $this->GetProfileForUnit($unit, false));
                    break;
                case VARIABLETYPE_FLOAT:
                    $this->RegisterVariableFloat($ident, $name, $this->GetProfileForUnit($unit, true));
                    break;
                default:
                    $this->RegisterVariableString($ident, $name, '');
                    break;
            }

            if (strtoupper((string) $register['Access']) === 'RW' && $this->IsTypeWritable((string) $register['Type'])) {
                $this->EnableAction($ident);
            }
        }

        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childId) {
            $child = IPS_GetObject($childId);
            if ($child['ObjectType'] !== OBJECTTYPE_VARIABLE) {
                continue;
            }

            $childIdent = IPS_GetObject($childId)['ObjectIdent'];
            if ($childIdent !== '' && !in_array($childIdent, $validIdents, true)) {
                $this->UnregisterVariable($childIdent);
            }
        }
    }

    private function GetRegisters(): array
    {
        $json = $this->ReadPropertyString('RegisterConfig');
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }

            $enabled = $row['Enabled'] ?? true;
            if ((bool) $enabled === false) {
                continue;
            }

            $name = trim((string) ($row['Name'] ?? ''));
            $address = (int) ($row['Address'] ?? 0);
            if ($name === '' || $address <= 0) {
                continue;
            }

            $type = strtolower(trim((string) ($row['Type'] ?? 'uint16')));
            $length = (int) ($row['Length'] ?? $this->GetDefaultLengthForType($type));
            if ($length <= 0) {
                $length = $this->GetDefaultLengthForType($type);
            }

            $ident = trim((string) ($row['Ident'] ?? ''));
            if ($ident === '') {
                $ident = $this->MakeIdent($name);
            }

            $result[] = [
                'Enabled'     => true,
                'Address'     => $address,
                'Name'        => $name,
                'Ident'       => $this->MakeIdent($ident),
                'Type'        => $type,
                'Length'      => $length,
                'Access'      => strtoupper(trim((string) ($row['Access'] ?? 'R'))),
                'Scale'       => (float) ($row['Scale'] ?? 1),
                'Unit'        => trim((string) ($row['Unit'] ?? '')),
                'Description' => trim((string) ($row['Description'] ?? ''))
            ];
        }

        return $result;
    }

    private function FindRegisterByIdent(string $ident): ?array
    {
        foreach ($this->GetRegisters() as $register) {
            if ((string) $register['Ident'] === $ident) {
                return $register;
            }
        }

        return null;
    }

    private function ReadRegister(array $register)
    {
        $type = (string) $register['Type'];
        $address = $this->ToModbusAddress((int) $register['Address']);
        $length = (int) $register['Length'];

        $words = $this->ReadHoldingRegisters($address, $length);
        if (count($words) !== $length) {
            throw new Exception('Unerwartete Anzahl Register zurückgegeben');
        }

        $scale = (float) $register['Scale'];
        if ($scale == 0.0) {
            $scale = 1.0;
        }

        switch ($type) {
            case 'bool':
                return ((int) $words[0]) === 1;

            case 'uint8':
                return (int) ($words[0] & 0xFF);

            case 'uint16':
                $value = (int) $words[0];
                break;

            case 'int16':
                $value = $this->ToSigned16((int) $words[0]);
                break;

            case 'uint32':
                $value = $this->CombineUInt32($words);
                break;

            case 'int32':
                $value = $this->ToSigned32($this->CombineUInt32($words));
                break;

            case 'uint64':
                $value = $this->CombineUInt64($words);
                break;

            case 'float32':
                $value = $this->CombineFloat32($words);
                break;

            case 'uint8[6]':
                return $this->DecodeUint8Array($words);

            case 'string':
                return $this->DecodeString($words);

            default:
                throw new Exception('Nicht unterstützter Typ: ' . $type);
        }

        if ($scale !== 1.0) {
            $value = $value * $scale;
        }

        if ($this->GetVariableTypeFromRegister($register) === VARIABLETYPE_FLOAT) {
            return (float) $value;
        }

        return $value;
    }

    private function WriteRegister(array $register, $value): void
    {
        $type = (string) $register['Type'];
        $scale = (float) $register['Scale'];
        if ($scale == 0.0) {
            $scale = 1.0;
        }

        if ($scale !== 1.0) {
            $value = $value / $scale;
        }

        $words = [];
        switch ($type) {
            case 'bool':
            case 'uint8':
            case 'uint16':
            case 'int16':
                $words = [((int) $value) & 0xFFFF];
                break;

            case 'uint32':
            case 'int32':
                $intValue = (int) $value;
                if ($intValue < 0) {
                    $intValue = $intValue & 0xFFFFFFFF;
                }
                $words = [
                    ($intValue >> 16) & 0xFFFF,
                    $intValue & 0xFFFF
                ];
                break;

            case 'uint64':
                $words = $this->SplitUInt64((int) $value);
                break;

            case 'float32':
                $words = $this->SplitFloat32((float) $value);
                break;

            default:
                throw new Exception('Schreiben für Typ nicht unterstützt: ' . $type);
        }

        $this->WriteHoldingRegisters($this->ToModbusAddress((int) $register['Address']), $words);
    }

    private function ReadHoldingRegisters(int $address, int $quantity): array
    {
        $host = $this->ReadPropertyString('Host');
        $port = $this->ReadPropertyInteger('Port');
        $unitId = $this->ReadPropertyInteger('UnitID');

        $transactionId = random_int(1, 65535);
        $functionCode = 3;
        $pdu = pack('Cnn', $functionCode, $address, $quantity);
        $packet = pack('nnnC', $transactionId, 0, strlen($pdu) + 1, $unitId) . $pdu;

        $response = $this->SendModbusPacket($host, $port, $packet);

        $transactionIdResponse = unpack('n', substr($response, 0, 2))[1];
        if ($transactionIdResponse !== $transactionId) {
            throw new Exception('Ungültige Transaktions-ID in Antwort');
        }

        $unit = ord($response[6]);
        $function = ord($response[7]);

        if ($unit !== $unitId) {
            throw new Exception('Antwort von unerwarteter Unit-ID');
        }

        if ($function === ($functionCode | 0x80)) {
            $exceptionCode = ord($response[8]);
            throw new Exception('Modbus Exception Code ' . $exceptionCode);
        }

        if ($function !== $functionCode) {
            throw new Exception('Unerwarteter Funktionscode: ' . $function);
        }

        $byteCount = ord($response[8]);
        $data = substr($response, 9, $byteCount);
        $words = array_values(unpack('n*', $data));

        return $words;
    }

    private function WriteHoldingRegisters(int $address, array $words): void
    {
        $host = $this->ReadPropertyString('Host');
        $port = $this->ReadPropertyInteger('Port');
        $unitId = $this->ReadPropertyInteger('UnitID');

        $transactionId = random_int(1, 65535);
        $functionCode = 16;
        $quantity = count($words);
        $byteCount = $quantity * 2;
        $payload = '';

        foreach ($words as $word) {
            $payload .= pack('n', ((int) $word) & 0xFFFF);
        }

        $pdu = pack('CnnC', $functionCode, $address, $quantity, $byteCount) . $payload;
        $packet = pack('nnnC', $transactionId, 0, strlen($pdu) + 1, $unitId) . $pdu;

        $response = $this->SendModbusPacket($host, $port, $packet);

        $function = ord($response[7]);
        if ($function === ($functionCode | 0x80)) {
            $exceptionCode = ord($response[8]);
            throw new Exception('Modbus Exception Code ' . $exceptionCode);
        }

        if ($function !== $functionCode) {
            throw new Exception('Unerwarteter Funktionscode beim Schreiben: ' . $function);
        }
    }

    private function SendModbusPacket(string $host, int $port, string $packet): string
    {
        $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 3);
        if ($socket === false) {
            throw new Exception('Verbindung fehlgeschlagen: ' . $errstr . ' (' . $errno . ')');
        }

        stream_set_timeout($socket, 3);

        $written = fwrite($socket, $packet);
        if ($written === false || $written !== strlen($packet)) {
            fclose($socket);
            throw new Exception('Modbus Paket konnte nicht vollständig gesendet werden');
        }

        $header = '';
        while (strlen($header) < 6) {
            $chunk = fread($socket, 6 - strlen($header));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($socket);
                fclose($socket);
                if ($meta['timed_out']) {
                    throw new Exception('Zeitüberschreitung beim Lesen des MBAP-Headers');
                }
                throw new Exception('Unvollständiger MBAP-Header empfangen');
            }
            $header .= $chunk;
        }

        $mbap = unpack('ntransaction/nprotocol/nlength', $header);
        $remaining = (int) $mbap['length'];

        $body = '';
        while (strlen($body) < $remaining) {
            $chunk = fread($socket, $remaining - strlen($body));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($socket);
                fclose($socket);
                if ($meta['timed_out']) {
                    throw new Exception('Zeitüberschreitung beim Lesen der Modbus-Nutzdaten');
                }
                throw new Exception('Unvollständige Modbus-Antwort empfangen');
            }
            $body .= $chunk;
        }

        fclose($socket);

        return $header . $body;
    }

    private function ToModbusAddress(int $documentAddress): int
    {
        $base = $this->ReadPropertyInteger('AddressBase');
        return max(0, $documentAddress - $base);
    }

    private function GetVariableTypeFromRegister(array $register): int
    {
        $type = (string) $register['Type'];
        $scale = (float) $register['Scale'];

        if ($type === 'bool') {
            return VARIABLETYPE_BOOLEAN;
        }

        if ($type === 'uint8[6]' || $type === 'string') {
            return VARIABLETYPE_STRING;
        }

        if ($type === 'float32' || abs($scale - 1.0) > 0.000001) {
            return VARIABLETYPE_FLOAT;
        }

        return VARIABLETYPE_INTEGER;
    }

    private function IsTypeWritable(string $type): bool
    {
        return in_array(strtolower($type), ['bool', 'uint8', 'uint16', 'int16', 'uint32', 'int32', 'uint64', 'float32'], true);
    }

    private function NormalizeIncomingValue(array $register, $value)
    {
        switch ($this->GetVariableTypeFromRegister($register)) {
            case VARIABLETYPE_BOOLEAN:
                return (bool) $value;
            case VARIABLETYPE_FLOAT:
                return (float) $value;
            case VARIABLETYPE_INTEGER:
                return (int) $value;
            default:
                return (string) $value;
        }
    }

    private function MakeIdent(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9_]/', '', str_replace(' ', '', $value));
        if ($value === null || $value === '') {
            $value = 'Reg' . mt_rand(1000, 9999);
        }
        if (preg_match('/^[0-9]/', $value)) {
            $value = 'R' . $value;
        }

        return $value;
    }

    private function GetDefaultLengthForType(string $type): int
    {
        switch (strtolower($type)) {
            case 'uint32':
            case 'int32':
            case 'float32':
                return 2;
            case 'uint64':
                return 4;
            case 'uint8[6]':
                return 3;
            default:
                return 1;
        }
    }

    private function CombineUInt32(array $words): int
    {
        return (((int) $words[0] & 0xFFFF) << 16) | ((int) $words[1] & 0xFFFF);
    }

    private function CombineUInt64(array $words): int
    {
        return ((((int) $words[0] & 0xFFFF) << 48)
            | (((int) $words[1] & 0xFFFF) << 32)
            | (((int) $words[2] & 0xFFFF) << 16)
            | ((int) $words[3] & 0xFFFF));
    }

    private function CombineFloat32(array $words): float
    {
        $bin = pack('n*', (int) $words[0], (int) $words[1]);
        return (float) unpack('G', $bin)[1];
    }

    private function SplitUInt64(int $value): array
    {
        return [
            ($value >> 48) & 0xFFFF,
            ($value >> 32) & 0xFFFF,
            ($value >> 16) & 0xFFFF,
            $value & 0xFFFF
        ];
    }

    private function SplitFloat32(float $value): array
    {
        $packed = pack('G', $value);
        $unpacked = unpack('n2', $packed);
        return array_values($unpacked);
    }

    private function DecodeUint8Array(array $words): string
    {
        $bytes = '';
        foreach ($words as $word) {
            $bytes .= sprintf('%02X:%02X:', ($word >> 8) & 0xFF, $word & 0xFF);
        }

        return rtrim($bytes, ':');
    }

    private function DecodeString(array $words): string
    {
        $text = '';
        foreach ($words as $word) {
            $text .= chr(($word >> 8) & 0xFF);
            $text .= chr($word & 0xFF);
        }

        return trim($text, "\x00 \t\r\n");
    }

    private function ToSigned16(int $value): int
    {
        return ($value & 0x8000) ? $value - 0x10000 : $value;
    }

    private function ToSigned32(int $value): int
    {
        return ($value & 0x80000000) ? $value - 0x100000000 : $value;
    }

    private function MaintainProfiles(): void
    {
        if (!IPS_VariableProfileExists('SMARTFOX.Switch')) {
            IPS_CreateVariableProfile('SMARTFOX.Switch', VARIABLETYPE_BOOLEAN);
            IPS_SetVariableProfileAssociation('SMARTFOX.Switch', false, 'Aus', '', -1);
            IPS_SetVariableProfileAssociation('SMARTFOX.Switch', true, 'Ein', '', -1);
        }

        $profiles = [
            'SMARTFOX.W'   => ['~Power', 0, ' W'],
            'SMARTFOX.Wh'  => ['~Electricity', 0, ' Wh'],
            'SMARTFOX.kWh' => ['~Electricity', 3, ' kWh'],
            'SMARTFOX.Percent1' => ['', 1, ' %']
        ];

        foreach ($profiles as $profileName => $config) {
            if (IPS_VariableProfileExists($profileName)) {
                continue;
            }

            IPS_CreateVariableProfile($profileName, VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileDigits($profileName, $config[1]);
            IPS_SetVariableProfileText($profileName, '', $config[2]);
        }
    }

    private function GetProfileForUnit(string $unit, bool $isFloat): string
    {
        $unit = strtolower($unit);

        if ($unit === 'w') {
            return $isFloat ? 'SMARTFOX.W' : '';
        }

        if ($unit === 'wh') {
            return $isFloat ? 'SMARTFOX.Wh' : '';
        }

        if ($unit === 'kwh') {
            return 'SMARTFOX.kWh';
        }

        if ($unit === '%') {
            return 'SMARTFOX.Percent1';
        }

        return '';
    }
}
