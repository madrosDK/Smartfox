<?php

declare(strict_types=1);

class SMARTFOX extends IPSModule
{
    private const DEFAULT_REGISTERS = [
        [
            'Enabled'   => true,
            'Name'      => 'Day Energy From Grid',
            'Ident'     => 'DayEnergyFromGrid',
            'Address'   => 18,
            'Type'      => 'uint32',
            'Access'    => 'R',
            'Factor'    => 1,
            'Profile'   => '~Electricity',
            'WordOrder' => 'AB'
        ],
        [
            'Enabled'   => true,
            'Name'      => 'Car Charge 1 Power',
            'Ident'     => 'CarCharge1Power',
            'Address'   => 68,
            'Type'      => 'uint32',
            'Access'    => 'RW',
            'Factor'    => 1,
            'Profile'   => '~Watt.3680',
            'WordOrder' => 'AB'
        ],
        [
            'Enabled'   => true,
            'Name'      => 'Car Charge 1 Mode',
            'Ident'     => 'CarCharge1Mode',
            'Address'   => 69,
            'Type'      => 'uint16',
            'Access'    => 'RW',
            'Factor'    => 1,
            'Profile'   => '',
            'WordOrder' => 'AB'
        ],
        [
            'Enabled'   => true,
            'Name'      => 'External Meter 1 Power',
            'Ident'     => 'ExtMeter1Power',
            'Address'   => 96,
            'Type'      => 'int32',
            'Access'    => 'R',
            'Factor'    => 1,
            'Profile'   => '~Watt.3680',
            'WordOrder' => 'AB'
        ]
    ];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '192.168.1.100');
        $this->RegisterPropertyInteger('Port', 502);
        $this->RegisterPropertyInteger('UnitID', 1);
        $this->RegisterPropertyInteger('UpdateInterval', 30);
        $this->RegisterPropertyString('Registers', json_encode(self::DEFAULT_REGISTERS));

        $this->RegisterTimer('UpdateTimer', 0, 'SMARTFOX_Update($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetSummary($this->ReadPropertyString('Host') . ':' . $this->ReadPropertyInteger('Port'));
        $this->SetTimerInterval('UpdateTimer', $this->ReadPropertyInteger('UpdateInterval') * 1000);

        try {
            $registers = $this->GetRegisters();
            $this->SyncVariables($registers);
            $this->SetStatus(102);
        } catch (Throwable $e) {
            $this->SendDebug(__FUNCTION__, $e->getMessage(), 0);
            $this->SetStatus(201);
        }
    }

    public function RequestAction($Ident, $Value): void
    {
        if ($Ident === 'UpdateNow') {
            $this->Update();
            return;
        }

        $register = $this->GetRegisterByIdent($Ident);
        if ($register === null) {
            throw new Exception('Unbekannte Aktion: ' . $Ident);
        }

        if (strtoupper((string) $register['Access']) !== 'RW') {
            throw new Exception('Register ist nicht schreibbar: ' . $Ident);
        }

        $this->WriteRegisterValue($register, $Value);
        $readBack = $this->ReadRegisterValue($register);
        $this->SetValue($Ident, $readBack);
    }

    public function Update(): void
    {
        $registers = $this->GetRegisters();

        foreach ($registers as $register) {
            if (!(bool) ($register['Enabled'] ?? false)) {
                continue;
            }

            try {
                $value = $this->ReadRegisterValue($register);
                $this->SetValue((string) $register['Ident'], $value);
                $this->SendDebug('Update', sprintf('%s [%d] = %s', (string) $register['Ident'], (int) $register['Address'], (string) $value), 0);
                $this->SetStatus(102);
            } catch (Throwable $e) {
                $this->SendDebug('UpdateError', sprintf('%s [%d]: %s', (string) $register['Ident'], (int) $register['Address'], $e->getMessage()), 0);
                $this->SetStatus(200);
            }
        }
    }

    private function SyncVariables(array $registers): void
    {
        $activeIdents = [];

        foreach ($registers as $register) {
            if (!(bool) ($register['Enabled'] ?? false)) {
                continue;
            }

            $ident = $this->NormalizeIdent((string) $register['Ident']);
            $name = trim((string) $register['Name']);
            if ($name === '') {
                $name = $ident;
            }

            $type = strtolower((string) $register['Type']);
            $profile = trim((string) ($register['Profile'] ?? ''));

            switch ($type) {
                case 'uint16':
                case 'int16':
                case 'uint32':
                case 'int32':
                    $this->RegisterVariableInteger($ident, $name, $profile);
                    break;
                case 'float32':
                    $this->RegisterVariableFloat($ident, $name, $profile);
                    break;
                default:
                    throw new Exception('Nicht unterstützter Datentyp: ' . $type);
            }

            if (strtoupper((string) $register['Access']) === 'RW') {
                $this->EnableAction($ident);
            }

            $activeIdents[] = $ident;
        }

        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childId) {
            $obj = IPS_GetObject($childId);
            if ($obj['ObjectType'] !== OBJECTTYPE_VARIABLE) {
                continue;
            }

            if (!in_array($obj['ObjectIdent'], $activeIdents, true)) {
                @IPS_SetHidden($childId, true);
            } else {
                @IPS_SetHidden($childId, false);
            }
        }
    }

    private function GetRegisters(): array
    {
        $json = $this->ReadPropertyString('Registers');
        $registers = json_decode($json, true);

        if (!is_array($registers)) {
            throw new Exception('Registers ist kein gültiges JSON');
        }

        $normalized = [];
        foreach ($registers as $index => $register) {
            if (!is_array($register)) {
                throw new Exception('Registereintrag #' . $index . ' ist ungültig');
            }

            $ident = $this->NormalizeIdent((string) ($register['Ident'] ?? ''));
            if ($ident === '') {
                throw new Exception('Leerer Ident in Registereintrag #' . $index);
            }

            $normalized[] = [
                'Enabled'   => (bool) ($register['Enabled'] ?? false),
                'Name'      => (string) ($register['Name'] ?? $ident),
                'Ident'     => $ident,
                'Address'   => (int) ($register['Address'] ?? 0),
                'Type'      => strtolower((string) ($register['Type'] ?? 'uint16')),
                'Access'    => strtoupper((string) ($register['Access'] ?? 'R')),
                'Factor'    => (float) ($register['Factor'] ?? 1),
                'Profile'   => (string) ($register['Profile'] ?? ''),
                'WordOrder' => strtoupper((string) ($register['WordOrder'] ?? 'AB'))
            ];
        }

        return $normalized;
    }

    private function GetRegisterByIdent(string $ident): ?array
    {
        foreach ($this->GetRegisters() as $register) {
            if ((string) $register['Ident'] === $ident) {
                return $register;
            }
        }

        return null;
    }

    private function ReadRegisterValue(array $register)
    {
        $address = (int) $register['Address'];
        $type = strtolower((string) $register['Type']);
        $factor = (float) $register['Factor'];
        $wordOrder = strtoupper((string) ($register['WordOrder'] ?? 'AB'));

        $quantity = $this->GetRegisterWordCount($type);
        $words = $this->ModbusReadHoldingRegisters($address, $quantity);
        $value = $this->WordsToValue($words, $type, $wordOrder);

        if ($factor !== 1.0) {
            $value = $value * $factor;
        }

        if ($type === 'float32') {
            return (float) $value;
        }

        return (int) round((float) $value);
    }

    private function WriteRegisterValue(array $register, $value): void
    {
        $address = (int) $register['Address'];
        $type = strtolower((string) $register['Type']);
        $factor = (float) $register['Factor'];
        $wordOrder = strtoupper((string) ($register['WordOrder'] ?? 'AB'));

        $rawValue = $value;
        if ($factor !== 0.0 && $factor !== 1.0) {
            $rawValue = (float) $value / $factor;
        }

        $words = $this->ValueToWords($rawValue, $type, $wordOrder);
        $this->ModbusWriteHoldingRegisters($address, $words);
    }

    private function GetRegisterWordCount(string $type): int
    {
        switch ($type) {
            case 'uint16':
            case 'int16':
                return 1;
            case 'uint32':
            case 'int32':
            case 'float32':
                return 2;
        }

        throw new Exception('Unbekannter Datentyp: ' . $type);
    }

    private function WordsToValue(array $words, string $type, string $wordOrder)
    {
        if ($wordOrder === 'BA' && count($words) === 2) {
            $words = [$words[1], $words[0]];
        }

        switch ($type) {
            case 'uint16':
                return (int) $words[0];
            case 'int16':
                return $this->ToSigned16((int) $words[0]);
            case 'uint32':
                return (int) ((((int) $words[0]) << 16) | ((int) $words[1]));
            case 'int32':
                return $this->ToSigned32((int) ((((int) $words[0]) << 16) | ((int) $words[1])));
            case 'float32':
                $bin = pack('n*', (int) $words[0], (int) $words[1]);
                return unpack('G', $bin)[1];
        }

        throw new Exception('Nicht unterstützter Datentyp: ' . $type);
    }

    private function ValueToWords($value, string $type, string $wordOrder): array
    {
        switch ($type) {
            case 'uint16':
            case 'int16':
                $words = [((int) $value) & 0xFFFF];
                break;
            case 'uint32':
            case 'int32':
                $intValue = (int) round((float) $value);
                if ($intValue < 0) {
                    $intValue = $intValue & 0xFFFFFFFF;
                }
                $words = [($intValue >> 16) & 0xFFFF, $intValue & 0xFFFF];
                break;
            case 'float32':
                $packed = pack('G', (float) $value);
                $words = array_values(unpack('n*', $packed));
                break;
            default:
                throw new Exception('Nicht unterstützter Datentyp: ' . $type);
        }

        if ($wordOrder === 'BA' && count($words) === 2) {
            $words = [$words[1], $words[0]];
        }

        return $words;
    }

    private function ModbusReadHoldingRegisters(int $address, int $quantity): array
    {
        $host = $this->ReadPropertyString('Host');
        $port = $this->ReadPropertyInteger('Port');
        $unitId = $this->ReadPropertyInteger('UnitID');

        $transactionId = random_int(1, 65535);
        $packet = pack('nnnCCnn', $transactionId, 0, 6, $unitId, 0x03, $address, $quantity);

        $response = $this->SendModbusPacket($host, $port, $packet);
        if (strlen($response) < 9) {
            throw new Exception('Antwort zu kurz');
        }

        $header = unpack('ntransaction/nprotocol/nlength/Cunit/Cfunction/CbyteCount', substr($response, 0, 9));
        if ((int) $header['function'] === 0x83) {
            $exceptionCode = ord(substr($response, 8, 1));
            throw new Exception('Modbus Exception ' . $exceptionCode);
        }
        if ((int) $header['function'] !== 0x03) {
            throw new Exception('Unerwarteter Funktionscode ' . $header['function']);
        }

        $data = substr($response, 9, (int) $header['byteCount']);
        $words = array_values(unpack('n*', $data));
        if (count($words) !== $quantity) {
            throw new Exception('Unerwartete Anzahl Register zurückgegeben');
        }

        return $words;
    }

    private function ModbusWriteHoldingRegisters(int $address, array $words): void
    {
        $host = $this->ReadPropertyString('Host');
        $port = $this->ReadPropertyInteger('Port');
        $unitId = $this->ReadPropertyInteger('UnitID');

        $quantity = count($words);
        $byteCount = $quantity * 2;
        $payload = '';
        foreach ($words as $word) {
            $payload .= pack('n', (int) $word);
        }

        $transactionId = random_int(1, 65535);
        $packet = pack('nnnCCnnC', $transactionId, 0, 7 + $byteCount, $unitId, 0x10, $address, $quantity, $byteCount) . $payload;

        $response = $this->SendModbusPacket($host, $port, $packet);
        if (strlen($response) < 12) {
            throw new Exception('Schreibantwort zu kurz');
        }

        $header = unpack('ntransaction/nprotocol/nlength/Cunit/Cfunction/nstart/nquantity', substr($response, 0, 12));
        if ((int) $header['function'] === 0x90) {
            throw new Exception('Modbus Schreibfehler');
        }
        if ((int) $header['function'] !== 0x10) {
            throw new Exception('Unerwarteter Funktionscode ' . $header['function']);
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
        $remaining = (int) $mbap['length']; // Unit-ID + PDU
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

        $meta = stream_get_meta_data($socket);
        fclose($socket);

        if ($meta['timed_out']) {
            throw new Exception('Zeitüberschreitung beim Lesen der Antwort');
        }

        return $header . $body;
    }

    private function NormalizeIdent(string $ident): string
    {
        $ident = preg_replace('/[^a-zA-Z0-9_]/', '', $ident) ?? '';
        if ($ident === '') {
            return '';
        }

        if (preg_match('/^[0-9]/', $ident) === 1) {
            $ident = 'R' . $ident;
        }

        return $ident;
    }

    private function ToSigned16(int $value): int
    {
        return ($value & 0x8000) ? $value - 0x10000 : $value;
    }

    private function ToSigned32(int $value): int
    {
        return ($value & 0x80000000) ? $value - 0x100000000 : $value;
    }
}
