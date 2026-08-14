<?php
/**
 * ZKTeco Biometric Device Communication Class
 * الاتصال بأجهزة البصمة ZKTeco عبر بروتوكول TCP أو UDP (port 4370)
 * يدعم الكشف التلقائي عن البروتوكول (TCP أولاً ثم UDP)
 * يستخدم PHP streams (لا يحتاج إضافة sockets)
 */
class ZKTecoDevice
{
    // Protocol command constants
    const CMD_CONNECT       = 1000;
    const CMD_EXIT          = 1001;
    const CMD_ENABLEDEVICE  = 1002;
    const CMD_DISABLEDEVICE = 1003;
    const CMD_ACK_OK        = 2000;
    const CMD_ACK_ERROR     = 2001;
    const CMD_ACK_DATA      = 2002;
    const CMD_ACK_UNAUTH    = 2005;
    const CMD_PREPARE_DATA  = 1500;
    const CMD_DATA          = 1501;
    const CMD_FREE_DATA     = 1502;
    const CMD_ATTLOG_RRQ    = 13;
    const CMD_CLEAR_ATTLOG  = 14;
    const CMD_USERTEMP_RRQ  = 9;
    const CMD_FREE_SIZES    = 50;
    const CMD_GET_TIME      = 201;
    const CMD_VERSION       = 1100;
    const CMD_AUTH          = 76;
    const CMD_OPTIONS_RRQ   = 11;

    // TCP header tokens
    const TCP_HEADER_SIZE   = 8;
    const TCP_TOP           = 0x5050;
    const TCP_DATA_TOKEN    = 0x5052;

    const USHRT_MAX = 65535;

    private $ip;
    private $port;
    private $socket = null;
    private $sessionId = 0;
    private $replyId = 0;
    private $timeout;
    private $connected = false;
    private $lastError = '';
    private $commPassword = 0;
    private $protocol = 'UDP';           // Active protocol: 'TCP' or 'UDP'
    private $requestedProtocol = 'auto'; // Requested: 'auto', 'TCP', 'UDP'
    private $lastTcpBulk = false;        // True when last TCP receive was 0x5052 bulk data

    /**
     * @param string $ip          عنوان IP الجهاز
     * @param int    $port        المنفذ (افتراضي 4370)
     * @param int    $timeout     مهلة الاتصال بالثواني
     * @param int    $commPassword مفتاح الاتصال الرقمي (Communication Key)
     * @param string $protocol    البروتوكول: 'auto' (تلقائي)، 'TCP'، أو 'UDP'
     */
    public function __construct(string $ip, int $port = 4370, int $timeout = 5, int $commPassword = 0, string $protocol = 'auto')
    {
        $this->ip = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
        $this->port = max(1, min(65535, $port));
        $this->timeout = max(1, min(30, $timeout));
        $this->commPassword = $commPassword;
        $this->requestedProtocol = strtoupper(trim($protocol));
        if (!in_array($this->requestedProtocol, ['AUTO', 'TCP', 'UDP'])) {
            $this->requestedProtocol = 'AUTO';
        }
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    /** البروتوكول المستخدم فعلياً (TCP أو UDP) */
    public function getProtocol(): string
    {
        return $this->protocol;
    }

    /**
     * الاتصال بالجهاز — يدعم الكشف التلقائي (TCP ثم UDP)
     */
    public function connect(): bool
    {
        if ($this->ip === '') {
            $this->lastError = 'عنوان IP غير صالح';
            return false;
        }

        if ($this->requestedProtocol === 'AUTO') {
            // محاولة TCP أولاً (الأكثر شيوعاً في الأجهزة الحديثة)
            $this->protocol = 'TCP';
            if ($this->attemptConnect()) {
                return true;
            }
            $tcpError = $this->lastError;

            // محاولة UDP كبديل
            $this->protocol = 'UDP';
            if ($this->attemptConnect()) {
                return true;
            }
            $udpError = $this->lastError;

            $this->lastError = "فشل الاتصال بالجهاز {$this->ip}:{$this->port}\n"
                . "TCP: {$tcpError}\n"
                . "UDP: {$udpError}\n"
                . "تأكد من: 1) عنوان IP صحيح 2) الجهاز متصل بالشبكة 3) المنفذ مفتوح";
            return false;
        }

        $this->protocol = $this->requestedProtocol;
        return $this->attemptConnect();
    }

    /**
     * محاولة اتصال ببروتوكول محدد (TCP أو UDP)
     */
    private function attemptConnect(): bool
    {
        $this->sessionId = 0;
        $this->replyId = 0;
        $this->connected = false;
        $this->lastTcpBulk = false;

        $scheme = ($this->protocol === 'TCP') ? 'tcp' : 'udp';
        $this->socket = @stream_socket_client(
            "{$scheme}://{$this->ip}:{$this->port}",
            $errno,
            $errstr,
            $this->timeout
        );

        if (!$this->socket) {
            $this->lastError = "فشل إنشاء اتصال {$this->protocol}: $errstr ($errno)";
            return false;
        }

        stream_set_timeout($this->socket, $this->timeout);

        $packet = $this->createPacket(self::CMD_CONNECT);
        if (!$this->send($packet)) {
            $this->close();
            return false;
        }

        $response = $this->receive();
        if ($response === null) {
            $this->lastError = "لا استجابة من الجهاز عبر {$this->protocol} ({$this->ip}:{$this->port})";
            $this->close();
            return false;
        }

        $header = $this->parseHeader($response);
        if ($header === null) {
            $this->lastError = "استجابة غير صالحة من الجهاز عبر {$this->protocol}";
            $this->close();
            return false;
        }

        if ($header['command'] === self::CMD_ACK_UNAUTH) {
            // الجهاز يتطلب مصادقة - محاولة المصادقة بمفتاح الاتصال
            $this->sessionId = $header['session_id'];
            $this->replyId = $this->incrementReplyId($header['reply_id']);

            if (!$this->authenticate()) {
                $this->close();
                return false;
            }

            $this->connected = true;
            return true;
        }

        if ($header['command'] !== self::CMD_ACK_OK && $header['command'] !== self::CMD_ACK_DATA) {
            $this->lastError = 'الجهاز رفض الاتصال (كود: ' . $header['command'] . ')';
            $this->close();
            return false;
        }

        $this->sessionId = $header['session_id'];
        $this->replyId = $this->incrementReplyId($this->replyId);
        $this->connected = true;

        return true;
    }

    /**
     * قطع الاتصال بالجهاز
     */
    public function disconnect(): void
    {
        if ($this->connected && $this->socket) {
            $packet = $this->createPacket(self::CMD_EXIT);
            @$this->send($packet);
        }
        $this->close();
        $this->connected = false;
        $this->sessionId = 0;
        $this->replyId = 0;
    }

    /**
     * اختبار الاتصال - يرجع معلومات الجهاز
     */
    public function testConnection(): array
    {
        if (!$this->connect()) {
            return ['success' => false, 'error' => $this->lastError];
        }

        $info = [
            'success' => true,
            'ip' => $this->ip,
            'port' => $this->port,
            'protocol' => $this->protocol,
            'session_id' => $this->sessionId,
            'firmware' => $this->getFirmwareVersion(),
            'serial' => $this->getSerialNumber(),
            'platform' => $this->getDeviceOption('~Platform'),
            'device_name' => $this->getDeviceOption('~DeviceName'),
        ];

        $this->disconnect();
        return $info;
    }

    /**
     * جلب إصدار البرنامج الثابت
     */
    public function getFirmwareVersion(): string
    {
        if (!$this->connected) return '';

        $packet = $this->createPacket(self::CMD_VERSION);
        if (!$this->send($packet)) return '';

        $response = $this->receive();
        if ($response === null) return '';

        $header = $this->parseHeader($response);
        if ($header === null) return '';

        $this->replyId = $this->incrementReplyId($this->replyId);
        return rtrim(substr($response, 8), "\0");
    }

    /**
     * جلب الرقم التسلسلي
     */
    public function getSerialNumber(): string
    {
        return $this->getDeviceOption('~SerialNumber');
    }

    /**
     * جلب قيمة خيار من الجهاز
     */
    public function getDeviceOption(string $option): string
    {
        if (!$this->connected) return '';

        $packet = $this->createPacket(self::CMD_OPTIONS_RRQ, $option . "\0");
        if (!$this->send($packet)) return '';

        $response = $this->receive();
        if ($response === null) return '';

        $header = $this->parseHeader($response);
        if ($header === null) return '';

        $this->replyId = $this->incrementReplyId($this->replyId);
        $data = substr($response, 8);

        if (strpos($data, '=') !== false) {
            $parts = explode('=', $data, 2);
            return rtrim($parts[1] ?? '', "\0 ");
        }
        return rtrim($data, "\0 ");
    }

    /**
     * جلب سجلات الحضور والانصراف من الجهاز
     * @return array قائمة السجلات [uid, datetime, log_type, status, punch]
     */
    public function getAttendanceLogs(): array
    {
        if (!$this->connected) {
            $this->lastError = 'غير متصل بالجهاز';
            return [];
        }

        // تعطيل الجهاز مؤقتاً أثناء السحب
        $this->disableDevice();

        $packet = $this->createPacket(self::CMD_ATTLOG_RRQ);
        if (!$this->send($packet)) {
            $this->enableDevice();
            return [];
        }

        $response = $this->receive();
        if ($response === null) {
            $this->lastError = 'لا استجابة لطلب سجلات الحضور';
            $this->enableDevice();
            return [];
        }

        $header = $this->parseHeader($response);
        if ($header === null) {
            $this->enableDevice();
            return [];
        }

        $this->replyId = $this->incrementReplyId($this->replyId);

        $data = '';
        if ($header['command'] === self::CMD_PREPARE_DATA) {
            // بيانات كبيرة - استقبال على أجزاء
            $dataInfo = substr($response, 8);
            $totalSize = 0;
            if (strlen($dataInfo) >= 4) {
                $totalSize = unpack('V', substr($dataInfo, 0, 4))[1];
            }

            if ($totalSize <= 0) {
                $this->enableDevice();
                return [];
            }

            $data = $this->receiveLargeData($totalSize);

            // تحرير المخزن المؤقت على الجهاز
            $freeCmd = $this->createPacket(self::CMD_FREE_DATA);
            $this->send($freeCmd);
            $freeResp = $this->receive();
            if ($freeResp !== null) {
                $this->replyId = $this->incrementReplyId($this->replyId);
            }
        } elseif ($header['command'] === self::CMD_ACK_DATA) {
            // بيانات صغيرة - كلها في استجابة واحدة
            $data = substr($response, 8);
        } else {
            $this->enableDevice();
            return [];
        }

        $this->enableDevice();

        if (empty($data)) {
            return [];
        }

        return $this->parseAttendanceLogs($data);
    }

    /**
     * مسح سجلات الحضور من الجهاز
     */
    public function clearAttendanceLogs(): bool
    {
        if (!$this->connected) return false;

        $packet = $this->createPacket(self::CMD_CLEAR_ATTLOG);
        if (!$this->send($packet)) return false;

        $response = $this->receive();
        if ($response === null) return false;

        $header = $this->parseHeader($response);
        if ($header === null) return false;

        $this->replyId = $this->incrementReplyId($this->replyId);
        return $header['command'] === self::CMD_ACK_OK;
    }

    /**
     * جلب قائمة المستخدمين المسجلين على الجهاز
     * @return array [uid, name, privilege]
     */
    public function getEnrolledUsers(): array
    {
        if (!$this->connected) return [];

        $packet = $this->createPacket(self::CMD_USERTEMP_RRQ);
        if (!$this->send($packet)) return [];

        $response = $this->receive();
        if ($response === null) return [];

        $header = $this->parseHeader($response);
        if ($header === null) return [];

        $this->replyId = $this->incrementReplyId($this->replyId);

        $data = '';
        if ($header['command'] === self::CMD_PREPARE_DATA) {
            $dataInfo = substr($response, 8);
            $totalSize = 0;
            if (strlen($dataInfo) >= 4) {
                $totalSize = unpack('V', substr($dataInfo, 0, 4))[1];
            }
            if ($totalSize <= 0) return [];

            $data = $this->receiveLargeData($totalSize);

            $freeCmd = $this->createPacket(self::CMD_FREE_DATA);
            $this->send($freeCmd);
            $freeResp = $this->receive();
            if ($freeResp !== null) {
                $this->replyId = $this->incrementReplyId($this->replyId);
            }
        } elseif ($header['command'] === self::CMD_ACK_DATA) {
            $data = substr($response, 8);
        } else {
            return [];
        }

        if (empty($data)) return [];

        return $this->parseUsers($data);
    }

    /**
     * مصادقة بكلمة مرور الاتصال (Communication Key)
     * عند مفتاح=0: يُرسل CMD_AUTH بدون بيانات (payload فارغ)
     * عند مفتاح>0: يُرسل المفتاح المشفّر بخوارزمية makeCommKey
     * بعض الأجهزة القديمة لا تدعم CMD_AUTH فترد بـ 0xFFFF — يُعتبر اتصالاً ناجحاً
     */
    private function authenticate(): bool
    {
        if ($this->commPassword > 0) {
            $authData = $this->makeCommKey($this->commPassword, $this->sessionId);
        } else {
            // مفتاح=0 → payload فارغ (معظم أجهزة ZKTeco)
            $authData = '';
        }

        $packet = $this->createPacket(self::CMD_AUTH, $authData);

        if (!$this->send($packet)) {
            $this->lastError = 'فشل إرسال بيانات المصادقة';
            return false;
        }

        $response = $this->receive();
        if ($response === null) {
            $this->lastError = 'لا استجابة لطلب المصادقة من الجهاز';
            return false;
        }

        $header = $this->parseHeader($response);
        if ($header === null) {
            $this->lastError = 'استجابة مصادقة غير صالحة من الجهاز';
            return false;
        }

        if ($header['command'] === self::CMD_ACK_OK) {
            // مصادقة ناجحة
            $this->replyId = $this->incrementReplyId($this->replyId);
            return true;
        }

        if ($header['command'] === 0xFFFF) {
            // 0xFFFF = الجهاز لا يدعم CMD_AUTH (firmware قديم)
            // الاتصال ناجح بدون مصادقة
            $this->replyId = $this->incrementReplyId($this->replyId);
            return true;
        }

        // CMD_ACK_ERROR أو رد آخر = مفتاح خاطئ
        $this->lastError = 'مفتاح الاتصال (Communication Key) غير صحيح — ادخل على الجهاز: القائمة ← الاتصال ← مفتاح الاتصال وأدخل نفس الرقم (المفتاح الحالي المُرسل: ' . $this->commPassword . ')';
        return false;
    }

    /**
     * تشفير مفتاح الاتصال بخوارزمية ZKTeco (make_commkey)
     * يخلط المفتاح مع session_id لإنتاج مفتاح مشفّر
     */
    private function makeCommKey(int $key, int $sessionId): string
    {
        // عكس ترتيب بتات المفتاح (bit-reverse)
        $k = 0;
        for ($i = 31; $i >= 0; $i--) {
            if ($key & (1 << $i)) {
                $k = ($k << 1) | 1;
            } else {
                $k = $k << 1;
            }
        }

        // إضافة session_id
        $k += $sessionId;
        $k &= 0xFFFFFFFF; // 32-bit wrap

        // XOR مع tick value (50 = 0x32)
        $tick = 0x32;
        $mask = $tick | ($tick << 8) | ($tick << 16) | ($tick << 24);
        $k ^= $mask;
        $k &= 0xFFFFFFFF;

        return pack('V', $k);
    }

    // ====== Protocol Helper Methods ======

    private function createPacket(int $command, string $data = ''): string
    {
        $buf = pack('v', $command)
             . pack('v', 0)                    // checksum placeholder
             . pack('v', $this->sessionId)
             . pack('v', $this->replyId)
             . $data;

        $checksum = $this->createChecksum($buf);

        // تعيين بايتات checksum
        $buf[2] = chr($checksum & 0xFF);
        $buf[3] = chr(($checksum >> 8) & 0xFF);

        return $buf;
    }

    private function createChecksum(string $data): int
    {
        $checksum = 0;
        $len = strlen($data);
        $i = 0;

        while ($i < $len - 1) {
            $val = unpack('v', substr($data, $i, 2))[1];
            $checksum += $val;
            if ($checksum > self::USHRT_MAX) {
                $checksum -= self::USHRT_MAX;
            }
            $i += 2;
        }

        if ($i < $len) {
            $checksum += ord($data[$i]);
        }

        while ($checksum > self::USHRT_MAX) {
            $checksum -= self::USHRT_MAX;
        }

        $checksum = ~$checksum & 0xFFFF;
        return $checksum;
    }

    private function parseHeader(string $response): ?array
    {
        if (strlen($response) < 8) return null;
        $vals = unpack('vcommand/vchecksum/vsession_id/vreply_id', $response);
        return $vals ?: null;
    }

    private function send(string $data): bool
    {
        if ($this->protocol === 'TCP') {
            // TCP: إضافة رأس 8 بايت قبل البيانات
            $tcpHeader = pack('vvV', self::TCP_TOP, strlen($data), 0);
            $data = $tcpHeader . $data;
        }
        $sent = @fwrite($this->socket, $data);
        if ($sent === false || $sent === 0) {
            $this->lastError = 'فشل إرسال البيانات للجهاز';
            return false;
        }
        return true;
    }

    private function receive(int $bufSize = 65535): ?string
    {
        $this->lastTcpBulk = false;

        if ($this->protocol === 'TCP') {
            return $this->receiveTcp();
        }

        // UDP: استقبال مباشر
        $response = @fread($this->socket, $bufSize);
        if ($response === false || $response === '') {
            $meta = @stream_get_meta_data($this->socket);
            if (!empty($meta['timed_out'])) {
                $this->lastError = 'انتهت المهلة - لا استجابة من الجهاز';
            }
            return null;
        }
        return $response;
    }

    /**
     * استقبال بيانات TCP (قراءة الرأس ثم الحمولة)
     */
    private function receiveTcp(): ?string
    {
        // قراءة رأس TCP (8 بايت)
        $tcpHeader = $this->readExactBytes(self::TCP_HEADER_SIZE);
        if ($tcpHeader === null || strlen($tcpHeader) < self::TCP_HEADER_SIZE) {
            $meta = @stream_get_meta_data($this->socket);
            if ($this->socket && !empty($meta['timed_out'])) {
                $this->lastError = 'انتهت المهلة - لا استجابة من الجهاز';
            }
            return null;
        }

        $tcp = unpack('vtoken/vsize/Vflag', $tcpHeader);
        $payloadSize = $tcp['size'];

        // رمز 0x5052 = بيانات كبيرة (حجم في 4 بايت الأخيرة)
        if ($tcp['token'] === self::TCP_DATA_TOKEN && $tcp['flag'] > 0) {
            $payloadSize = $tcp['flag'];
            $this->lastTcpBulk = true;
        }

        if ($payloadSize <= 0) return null;

        return $this->readExactBytes($payloadSize);
    }

    /**
     * قراءة عدد محدد من البايتات (لاتصالات TCP)
     */
    private function readExactBytes(int $length): ?string
    {
        $data = '';
        $remaining = $length;
        $startTime = time();

        while ($remaining > 0) {
            if ((time() - $startTime) > $this->timeout + 2) {
                $this->lastError = 'انتهت المهلة أثناء استقبال البيانات';
                return strlen($data) > 0 ? $data : null;
            }
            $chunk = @fread($this->socket, $remaining);
            if ($chunk === false || $chunk === '') {
                $meta = @stream_get_meta_data($this->socket);
                if (!empty($meta['timed_out'])) {
                    $this->lastError = 'انتهت المهلة أثناء استقبال البيانات';
                    return strlen($data) > 0 ? $data : null;
                }
                return strlen($data) > 0 ? $data : null;
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }
        return $data;
    }

    private function receiveLargeData(int $totalSize): string
    {
        $data = '';
        $received = 0;
        $maxAttempts = max(100, intdiv($totalSize, 1024) + 50);
        $attempts = 0;
        $emptyCount = 0;

        while ($received < $totalSize && $attempts < $maxAttempts) {
            $response = $this->receive();
            if ($response === null) {
                $emptyCount++;
                if ($emptyCount > 5) break;
                $attempts++;
                continue;
            }
            $emptyCount = 0;

            // TCP bulk data (0x5052): بيانات خام بدون ترويسة بروتوكول
            if ($this->protocol === 'TCP' && $this->lastTcpBulk) {
                $data .= $response;
                $received += strlen($response);
                $attempts++;
                continue;
            }

            $header = $this->parseHeader($response);
            if ($header === null) {
                $attempts++;
                continue;
            }

            if ($header['command'] === self::CMD_DATA) {
                $chunk = substr($response, 8);
                $data .= $chunk;
                $received += strlen($chunk);
                $this->replyId = $this->incrementReplyId($this->replyId);
            } elseif ($header['command'] === self::CMD_ACK_OK) {
                $this->replyId = $this->incrementReplyId($this->replyId);
                break;
            }

            $attempts++;
        }

        return $data;
    }

    private function enableDevice(): bool
    {
        if (!$this->connected || !$this->socket) return false;
        $packet = $this->createPacket(self::CMD_ENABLEDEVICE);
        if (!$this->send($packet)) return false;
        $response = $this->receive();
        if ($response !== null) {
            $this->replyId = $this->incrementReplyId($this->replyId);
        }
        return true;
    }

    private function disableDevice(): bool
    {
        if (!$this->connected || !$this->socket) return false;
        $packet = $this->createPacket(self::CMD_DISABLEDEVICE);
        if (!$this->send($packet)) return false;
        $response = $this->receive();
        if ($response !== null) {
            $this->replyId = $this->incrementReplyId($this->replyId);
        }
        return true;
    }

    private function close(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * تحليل بيانات سجلات الحضور
     * يدعم تنسيق 40 بايت (أجهزة حديثة) و16 بايت (أجهزة قديمة)
     */
    private function parseAttendanceLogs(string $data): array
    {
        $logs = [];
        $dataLen = strlen($data);

        if ($dataLen === 0) return [];

        // تحديد حجم السجل بناءً على طول البيانات
        $recordSize = 40;
        if ($dataLen % 40 !== 0) {
            if ($dataLen % 16 === 0) {
                $recordSize = 16;
            }
        }

        $offset = 0;
        while ($offset + $recordSize <= $dataLen) {
            $record = substr($data, $offset, $recordSize);
            $log = ($recordSize === 40)
                ? $this->parseAttRecord40($record)
                : $this->parseAttRecord16($record);

            if ($log !== null) {
                $logs[] = $log;
            }
            $offset += $recordSize;
        }

        return $logs;
    }

    /**
     * تحليل سجل حضور 40 بايت (أجهزة حديثة - Face ID2, Face ID3)
     */
    private function parseAttRecord40(string $record): ?array
    {
        if (strlen($record) < 40) return null;

        $uid = rtrim(substr($record, 0, 9), "\0 ");
        if ($uid === '') return null;

        $timestamp = unpack('V', substr($record, 24, 4))[1];
        $datetime = $this->decodeTime($timestamp);

        $status = ord($record[28]);
        $logType = $this->mapStatus($status);
        $punch = ord($record[29]);

        return [
            'uid' => $uid,
            'datetime' => $datetime,
            'status' => $status,
            'log_type' => $logType,
            'punch' => $punch,
        ];
    }

    /**
     * تحليل سجل حضور 16 بايت (أجهزة قديمة - AC102)
     */
    private function parseAttRecord16(string $record): ?array
    {
        if (strlen($record) < 16) return null;

        $uid = (string)unpack('v', substr($record, 0, 2))[1];
        if ($uid === '0') return null;

        $timestamp = unpack('V', substr($record, 4, 4))[1];
        $datetime = $this->decodeTime($timestamp);

        $status = ord($record[8]);
        $logType = $this->mapStatus($status);

        return [
            'uid' => $uid,
            'datetime' => $datetime,
            'status' => $status,
            'log_type' => $logType,
            'punch' => 0,
        ];
    }

    /**
     * تحليل بيانات المستخدمين
     */
    private function parseUsers(string $data): array
    {
        $users = [];
        $dataLen = strlen($data);
        if ($dataLen === 0) return [];

        // تخطي أول 4 بايت (header حجم البيانات)
        $offset = 0;
        if ($dataLen > 4) {
            $headerSize = unpack('V', substr($data, 0, 4))[1];
            // إذا كانت القيمة قريبة من حجم البيانات الفعلي → هي header
            if (abs($headerSize - ($dataLen - 4)) < 100) {
                $offset = 4;
                $dataLen -= 4;
            }
        }

        $recordSize = 72;
        if ($dataLen % 72 !== 0 && $dataLen % 28 === 0) {
            $recordSize = 28;
        }

        while ($offset + $recordSize <= strlen($data)) {
            $record = substr($data, $offset, $recordSize);

            if ($recordSize === 72) {
                // تنسيق 72 بايت:
                // [0:2] serial, [2] privilege, [3:11] password
                // [11:35] name (24 bytes), [48:57] UID (9 bytes)
                $uid = rtrim(substr($record, 48, 9), "\0 ");
                $name = rtrim(substr($record, 11, 24), "\0 ");
                $privilege = ord($record[2]);
            } else {
                // تنسيق 28 بايت
                $uid = (string)unpack('v', substr($record, 0, 2))[1];
                $name = rtrim(substr($record, 2, 24), "\0 ");
                $privilege = ord($record[26]);
            }

            // معالجة ترميز الأسماء
            // قص النص عند أول null byte (بقايا بيانات سابقة)
            $nullPos = strpos($name, "\0");
            if ($nullPos !== false) {
                $name = substr($name, 0, $nullPos);
            }
            $name = trim($name);

            // إزالة أحرف الاستبدال التالفة (U+FFFD) - الأسماء العربية فُقدت على الجهاز
            // النمط 1: U+FFFD مباشر (EF BF BD)
            $name = str_replace("\xEF\xBF\xBD", '', $name);
            // النمط 2: U+FFFD مزدوج الترميز (ï¿½ = C3 AF C2 BF C2 BD)
            $name = str_replace("\xC3\xAF\xC2\xBF\xC2\xBD", '', $name);
            // النمط 3: بيانات تالفه جزئيا مع علامات استفهام
            $name = preg_replace('/(?:\xC3\x3F\xC2[\xAF\xBF\xBD])+/', '', $name);
            $name = trim($name);

            // تحويل الترميز للنص المتبقي إذا لزم الأمر
            if ($name !== '' && !mb_check_encoding($name, 'UTF-8')) {
                try {
                    $converted = mb_convert_encoding($name, 'UTF-8', 'Windows-1256');
                    if ($converted !== false && $converted !== '') {
                        $name = $converted;
                    }
                } catch (\Throwable $e) {
                    $name = preg_replace('/[\x80-\xFF]/', '', $name);
                }
            }

            if ($uid !== '' && $uid !== '0') {
                $users[] = [
                    'uid' => $uid,
                    'name' => $name,
                    'privilege' => $privilege,
                ];
            }

            $offset += $recordSize;
        }

        return $users;
    }

    /**
     * فك تشفير الوقت من تنسيق ZKTeco
     * الصيغة: t = ((year-2000)*12*31 + (month-1)*31 + day-1) * 86400 + hour*3600 + minute*60 + second
     */
    private function decodeTime(int $t): string
    {
        $second = $t % 60;
        $t = intdiv($t, 60);
        $minute = $t % 60;
        $t = intdiv($t, 60);
        $hour = $t % 24;
        $t = intdiv($t, 24);
        $day = ($t % 31) + 1;
        $t = intdiv($t, 31);
        $month = ($t % 12) + 1;
        $t = intdiv($t, 12);
        $year = $t + 2000;

        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
    }

    /**
     * تحويل رمز الحالة إلى نوع السجل
     * 0=دخول، 1=خروج، 2=استراحة خروج، 3=استراحة دخول، 4=عمل إضافي دخول، 5=عمل إضافي خروج
     */
    private function mapStatus(int $status): string
    {
        if ($status === 0 || $status === 3 || $status === 4) {
            return 'in';
        }
        if ($status === 1 || $status === 2 || $status === 5) {
            return 'out';
        }
        return 'unknown';
    }

    private function incrementReplyId(int $replyId): int
    {
        return ($replyId + 1) & 0xFFFF;
    }
}
