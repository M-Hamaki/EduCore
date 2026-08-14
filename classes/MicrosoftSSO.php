<?php
/**
 * Microsoft SSO Class
 * ==================
 * 
 * كلاس للتعامل مع Microsoft Entra ID (Azure AD) للمصادقة
 * يدعم OAuth 2.0 / OpenID Connect
 * 
 * @author School Portal Team
 * @version 1.0
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/utilities.php';
require_once __DIR__ . '/StaffActiveRoleService.php';
require_once __DIR__ . '/../src/Modules/Accounts/StudentLoginAccessPolicy.php';
$microsoftSsoAuditServiceFile = __DIR__ . '/../src/Modules/Operations/Audit/AuditService.php';
if (is_file($microsoftSsoAuditServiceFile)) {
    try {
        require_once $microsoftSsoAuditServiceFile;
    } catch (Throwable $microsoftSsoAuditLoadError) {
        error_log('[Microsoft SSO] Audit dependency could not be loaded: ' . $microsoftSsoAuditLoadError->getMessage());
    }
}
unset($microsoftSsoAuditServiceFile, $microsoftSsoAuditLoadError);

class MicrosoftSSO {
    
    private $clientId;
    private $clientSecret;
    private $tenantId;
    private $redirectUri;
    private $scopes;
    private $db;
    
    /**
     * Constructor
     * 
     * @param PDO $db Database connection
     */
    public function __construct($db = null) {
        $this->db = $db;
        $this->clientId = AZURE_CLIENT_ID;
        $this->clientSecret = AZURE_CLIENT_SECRET;
        $this->tenantId = AZURE_TENANT_ID;
        $this->redirectUri = AZURE_REDIRECT_URI;
        $this->scopes = AZURE_SCOPES;
    }
    
    /**
     * توليد رابط المصادقة
     * Generate Authorization URL
     * 
     * @param string $state State parameter for CSRF protection
     * @param bool $silent Try silent login first (no UI)
     * @return string Authorization URL
     */
    public function getAuthorizationUrl($state = null, $silent = false) {
        if (!$state) {
            $state = bin2hex(random_bytes(16));
        }
        
        // حفظ الـ state في الجلسة للتحقق لاحقاً
        $_SESSION['oauth_state'] = $state;
        
        $params = [
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'response_mode' => 'query',
            'scope' => $this->scopes,
            'state' => $state,
            'nonce' => bin2hex(random_bytes(16))
        ];
        
        // إذا كان silent=true، نحاول تسجيل الدخول الصامت (بدون واجهة)
        // إذا كان المستخدم مسجل دخوله في Microsoft، سيدخل تلقائياً
        if ($silent) {
            $params['prompt'] = 'none';
            $_SESSION['sso_silent_attempt'] = true;
        } else {
            $params['prompt'] = 'select_account';
            $_SESSION['sso_silent_attempt'] = false;
        }
        
        return AZURE_AUTHORIZE_ENDPOINT . '?' . http_build_query($params);
    }
    
    /**
     * توليد رابط المصادقة لـ Teams (بدون prompt)
     * Generate Authorization URL for Teams SSO
     * 
     * @param string $state State parameter
     * @param string $loginHint Email/UPN to pre-fill
     * @return string Authorization URL
     */
    public function getTeamsAuthorizationUrl($state = null, $loginHint = null) {
        if (!$state) {
            $state = bin2hex(random_bytes(16));
        }
        
        $_SESSION['oauth_state'] = $state;
        
        $params = [
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => defined('AZURE_TEAMS_REDIRECT_URI') ? AZURE_TEAMS_REDIRECT_URI : $this->redirectUri,
            'response_mode' => 'query',
            'scope' => $this->scopes,
            'state' => $state,
            'nonce' => bin2hex(random_bytes(16))
        ];
        
        // إضافة login_hint إذا متوفر (يملأ البريد تلقائياً)
        if ($loginHint) {
            $params['login_hint'] = $loginHint;
            // إضافة domain_hint لتسريع تسجيل الدخول
            $domain = explode('@', $loginHint);
            if (count($domain) > 1) {
                $params['domain_hint'] = $domain[1];
            }
            // prompt=none يحاول تسجيل الدخول بدون أي تفاعل
            // إذا فشل، Microsoft سترجع خطأ وسنتعامل معه
            $params['prompt'] = 'none';
        } else {
            $params['prompt'] = 'select_account';
        }
        
        return AZURE_AUTHORIZE_ENDPOINT . '?' . http_build_query($params);
    }
    
    /**
     * استبدال الكود بـ Access Token
     * Exchange authorization code for tokens
     * 
     * @param string $code Authorization code
     * @return array|false Token response or false on failure
     */
    public function exchangeCodeForTokens($code) {
        $params = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
            'scope' => $this->scopes
        ];
        
        $response = $this->makePostRequest(AZURE_TOKEN_ENDPOINT, $params);
        
        if ($response && isset($response['access_token'])) {
            return $response;
        }
        
        $this->logError('Failed to exchange code for tokens', $response);
        return false;
    }
    
    /**
     * استبدال الكود بـ Access Token (لـ Teams)
     * Exchange authorization code for tokens (Teams)
     * 
     * @param string $code Authorization code
     * @return array|false Token response
     */
    public function exchangeCodeForTokensTeams($code) {
        $params = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => defined('AZURE_TEAMS_REDIRECT_URI') ? AZURE_TEAMS_REDIRECT_URI : $this->redirectUri,
            'grant_type' => 'authorization_code',
            'scope' => $this->scopes
        ];
        
        $response = $this->makePostRequest(AZURE_TOKEN_ENDPOINT, $params);
        
        if ($response && isset($response['access_token'])) {
            return $response;
        }
        
        $this->logError('Failed to exchange code for tokens (Teams)', $response);
        return false;
    }
    
    /**
     * الحصول على معلومات المستخدم من Graph API
     * Get user info from Microsoft Graph API
     * 
     * @param string $accessToken Access token
     * @return array|false User info or false on failure
     */
    public function getUserInfo($accessToken) {
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ];
        
        $response = $this->makeGetRequest(GRAPH_USER_ENDPOINT, $headers);
        
        if ($response && isset($response['id'])) {
            return $response;
        }
        
        $this->logError('Failed to get user info', $response);
        return false;
    }
    
    /**
     * التحقق من ID Token وفك تشفيره
     * Decode and verify ID Token
     * 
     * @param string $idToken ID Token (JWT)
     * @param bool $isTeamsToken Whether this is a Teams SSO token
     * @return array|false Decoded token or false on failure
     */
    public function decodeIdToken($idToken, $isTeamsToken = false) {
        try {
            $parts = explode('.', $idToken);
            
            if (count($parts) !== 3) {
                throw new Exception('Invalid JWT format');
            }

            $header = json_decode($this->base64UrlDecode($parts[0]), true);
            if (!$header || empty($header['kid']) || ($header['alg'] ?? '') !== 'RS256') {
                throw new Exception('Invalid JWT header');
            }
            
            $keys = $this->getJwksKeys();
            $decoded = \Firebase\JWT\JWT::decode($idToken, $keys);
            $payload = json_decode(json_encode($decoded), true);
            
            if (!$payload) {
                throw new Exception('Failed to decode JWT payload');
            }
            
            // تسجيل للتشخيص
            if (defined('SSO_DEBUG_MODE') && SSO_DEBUG_MODE) {
                error_log('[SSO Token] Payload: ' . json_encode($payload));
            }
            
            // التحقق من المُصدر (issuer) - يمكن أن يكون بصيغ مختلفة
            $validIssuers = [
                'https://login.microsoftonline.com/' . $this->tenantId . '/v2.0',
                'https://sts.windows.net/' . $this->tenantId . '/',
            ];
            
            if (empty($payload['iss']) || !in_array($payload['iss'], $validIssuers, true)) {
                // للتشخيص
                if (defined('SSO_DEBUG_MODE') && SSO_DEBUG_MODE) {
                    error_log('[SSO Token] Issuer mismatch. Got: ' . ($payload['iss'] ?? 'missing') . ', Expected one of: ' . implode(', ', $validIssuers));
                }
                throw new Exception('Invalid or missing token issuer');
            }
            
            // التحقق من الجمهور (audience)
            // Teams SSO tokens لها audience مختلف (Application ID URI)
            $validAudiences = [
                $this->clientId,
                'api://' . $this->clientId,
                defined('TEAMS_APP_ID_URI') ? TEAMS_APP_ID_URI : null,
            ];
            $validAudiences = array_values(array_unique(array_filter($validAudiences)));
            
            if (empty($payload['aud']) || !in_array($payload['aud'], $validAudiences, true)) {
                // للتشخيص
                if (defined('SSO_DEBUG_MODE') && SSO_DEBUG_MODE) {
                    error_log('[SSO Token] Audience mismatch. Got: ' . ($payload['aud'] ?? 'missing') . ', Expected one of: ' . implode(', ', $validAudiences));
                }
                throw new Exception('Invalid or missing token audience');
            }

            if (empty($payload['tid']) || !hash_equals(strtolower($this->tenantId), strtolower((string)$payload['tid']))) {
                throw new Exception('Invalid or missing token tenant');
            }
            if (!isset($payload['exp']) || !is_numeric($payload['exp'])) {
                throw new Exception('Missing token expiration');
            }
            
            return $payload;
            
        } catch (Exception $e) {
            $this->logError('Failed to decode ID token: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * التحقق من Teams Token
     * Verify Teams SSO Token
     * 
     * @param string $teamsToken Teams SSO token
     * @return array|false Decoded token or false
     */
    public function verifyTeamsToken($teamsToken) {
        return $this->decodeIdToken($teamsToken);
    }

    /**
     * Fetch and cache Microsoft's JWKS keys for JWT signature verification.
     *
     * @return array<string,\Firebase\JWT\Key>
     */
    private function getJwksKeys() {
        $cacheFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'educore_azure_jwks_' . md5(AZURE_JWKS_URI) . '.json';
        $jwks = null;

        if (is_file($cacheFile) && (time() - filemtime($cacheFile) < 21600)) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['keys'])) {
                $jwks = $cached;
            }
        }

        if (!$jwks) {
            $jwks = $this->makeGetRequest(AZURE_JWKS_URI);
            if (!is_array($jwks) || !isset($jwks['keys'])) {
                if (is_file($cacheFile)) {
                    $cached = json_decode((string)file_get_contents($cacheFile), true);
                    if (is_array($cached) && isset($cached['keys'])) {
                        $jwks = $cached;
                    }
                }
            } else {
                @file_put_contents($cacheFile, json_encode($jwks));
            }
        }

        if (!is_array($jwks) || !isset($jwks['keys'])) {
            throw new Exception('Unable to load Microsoft JWKS');
        }

        return \Firebase\JWT\JWK::parseKeySet($jwks);
    }
    
    /**
     * Resolves one Microsoft identity without allowing object-ID or email reassignment.
     * Both the local email and username must equal Microsoft's verified email.
     *
     * @return array<string,mixed>|false
     */
    public function resolveMicrosoftLoginUser($microsoftId, $email) {
        if (!$this->db) {
            return false;
        }
        $normalizedEmail = $this->normalizeMicrosoftEmail($email);
        if ($normalizedEmail === '') {
            return false;
        }

        $microsoftId = trim((string) $microsoftId);
        if ($microsoftId === '') {
            return false;
        }
        $stmt = $this->db->prepare("SELECT id, name, username, email, azure_id, role, is_supervisor, class_id, status
            FROM users WHERE azure_id = ? AND deleted_at IS NULL LIMIT 2");
        $stmt->execute([$microsoftId]);
        $linkedMatches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($linkedMatches) > 1) {
            return false;
        }
        if (count($linkedMatches) === 1) {
            $linkedUser = $linkedMatches[0];
            return $this->microsoftEmailMatchesAccount($linkedUser, $normalizedEmail) ? $linkedUser : false;
        }

        return $this->findUserByEmail($normalizedEmail, $microsoftId);
    }

    /**
     * Resolve a Teams silent-login identity only when it is already linked.
     * Silent login never creates or changes the link; object ID, email and username must all match.
     *
     * @return array<string,mixed>|false
     */
    public function resolveLinkedMicrosoftLoginUser($microsoftId, $email) {
        if (!$this->db) {
            return false;
        }

        $microsoftId = trim((string) $microsoftId);
        $normalizedEmail = $this->normalizeMicrosoftEmail($email);
        if ($microsoftId === '' || $normalizedEmail === '') {
            return false;
        }

        $stmt = $this->db->prepare("SELECT id, name, username, email, azure_id, role, is_supervisor, class_id, status
            FROM users WHERE azure_id = ? AND deleted_at IS NULL LIMIT 2");
        $stmt->execute([$microsoftId]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($matches) !== 1) {
            return false;
        }

        return $this->microsoftEmailMatchesAccount($matches[0], $normalizedEmail) ? $matches[0] : false;
    }

    public function findUserByMicrosoftId($microsoftId, $email = null) {
        return $email === null ? false : $this->resolveMicrosoftLoginUser($microsoftId, $email);
    }

    public function findUserByEmail($email, $microsoftId = null) {
        if (!$this->db) {
            return false;
        }
        $normalizedEmail = $this->normalizeMicrosoftEmail($email);
        if ($normalizedEmail === '') {
            return false;
        }

        $stmt = $this->db->prepare("SELECT id, name, username, email, azure_id, role, is_supervisor, class_id, status
            FROM users
            WHERE LOWER(TRIM(email)) = ? AND LOWER(TRIM(username)) = ? AND deleted_at IS NULL
            LIMIT 2");
        $stmt->execute([$normalizedEmail, $normalizedEmail]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($matches) !== 1) {
            return false;
        }
        $user = $matches[0];
        $storedMicrosoftId = trim((string) ($user['azure_id'] ?? ''));
        $microsoftId = trim((string) $microsoftId);
        if ($storedMicrosoftId !== '' && ($microsoftId === '' || !hash_equals($storedMicrosoftId, $microsoftId))) {
            return false;
        }
        return $user;
    }

    private function microsoftEmailMatchesAccount(array $user, string $normalizedEmail): bool {
        return $this->normalizeMicrosoftEmail($user['email'] ?? '') === $normalizedEmail
            && $this->normalizeMicrosoftEmail($user['username'] ?? '') === $normalizedEmail;
    }

    private function normalizeMicrosoftEmail($email): string {
        return strtolower(trim((string) $email));
    }

    /** @return array{allowed:bool,code:?string,message:?string} */
    public function loginAccessDecision($user): array {
        if (!$user || !$this->db) {
            return ['allowed' => false, 'code' => 'not_found', 'message' => 'تعذر التحقق من صلاحية الحساب.'];
        }
        if (($user['role'] ?? '') === 'student') {
            try {
                return (new \EduCore\Modules\Accounts\StudentLoginAccessPolicy($this->db))
                    ->decisionForUserId((int) $user['id']);
            } catch (Throwable $e) {
                $this->logError('Student login policy failed', ['user_id' => (int) ($user['id'] ?? 0)]);
                return ['allowed' => false, 'code' => 'policy_error', 'message' => 'تعذر التحقق من صلاحية الحساب حالياً. يرجى المحاولة مرة أخرى.'];
            }
        }
        if (($user['status'] ?? '') !== 'active') {
            return ['allowed' => false, 'code' => 'inactive', 'message' => 'حسابك غير نشط. يرجى التواصل مع الإدارة.'];
        }
        return ['allowed' => true, 'code' => null, 'message' => null];
    }

    /**
     * ربط حساب Microsoft بحساب المستخدم
     * Link Microsoft account to user
     * 
     * @param int $userId User ID
     * @param string $microsoftId Microsoft Object ID
     * @param string $email Microsoft email
     * @return bool Success
     */
    public function linkMicrosoftAccount($userId, $microsoftId, $email = null) {
        if (!$this->db) {
            return false;
        }

        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) {
                $this->db->beginTransaction();
            }

            $beforeStmt = $this->db->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
            $beforeStmt->execute([(int) $userId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                if ($ownsTransaction) {
                    $this->db->rollBack();
                }
                return false;
            }

            $query = 'UPDATE users SET azure_id = ?';
            $params = [(string) $microsoftId];
            if ($email !== null && $email !== '') {
                $query .= ', email = ?';
                $params[] = (string) $email;
            }
            $query .= ' WHERE id = ?';
            $params[] = (int) $userId;
            $this->db->prepare($query)->execute($params);

            $afterStmt = $this->db->prepare('SELECT * FROM users WHERE id = ?');
            $afterStmt->execute([(int) $userId]);
            $after = $afterStmt->fetch(PDO::FETCH_ASSOC);
            if (!$after) {
                throw new RuntimeException('Microsoft-linked user could not be reloaded.');
            }

            if ($before != $after) {
                (new \EduCore\Modules\Operations\Audit\AuditService($this->db))->recordUpdate(
                    'user',
                    'users',
                    (int) $userId,
                    (string) ($after['name'] ?? ('User #' . $userId)),
                    $before,
                    $after,
                    'ربط حساب Microsoft بحساب المستخدم'
                );
            }

            if ($ownsTransaction) {
                $this->db->commit();
            }
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logError('Failed to link Microsoft account', ['user_id' => (int) $userId]);
            if (!$ownsTransaction) {
                throw $e;
            }
            return false;
        }
    }
    
    /**
     * تسجيل دخول المستخدم وإنشاء الجلسة
     * Login user and create session
     * 
     * @param array $user User data
     * @param array $microsoftUser Microsoft user info
     * @return bool Success
     */
    public function loginUser($user, $microsoftUser = null, $linkAccount = true) {
        $decision = $this->loginAccessDecision($user);
        if (!$decision['allowed']) {
            return false;
        }

        try {
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['is_supervisor'] = $user['is_supervisor'] ?? 0;
            $_SESSION['class_id'] = $user['class_id'] ?? null;
            $_SESSION['microsoft_login'] = true;

            if (($user['role'] ?? '') !== 'student' && $this->db) {
                (new StaffActiveRoleService($this->db))->startSession($_SESSION, (int)$user['id']);
            }

            if ($microsoftUser) {
                $_SESSION['microsoft_id'] = $microsoftUser['id'] ?? null;
                $_SESSION['microsoft_email'] = $microsoftUser['mail'] ?? $microsoftUser['userPrincipalName'] ?? null;
            }

            // ربط حساب Microsoft إذا لم يكن مربوطاً. فشل الربط/التدقيق يمنع جلسة جزئية.
            if ($linkAccount && $microsoftUser && $this->db) {
                $microsoftId = $microsoftUser['id'] ?? null;
                $email = $microsoftUser['mail'] ?? $microsoftUser['userPrincipalName'] ?? null;
                if ($microsoftId && !$this->linkMicrosoftAccount($user['id'], $microsoftId, $email)) {
                    throw new RuntimeException('Microsoft account link could not be stored.');
                }
            }

            $this->logAction('microsoft_login', 'User logged in via Microsoft SSO', $user['id']);
            return true;
        } catch (Throwable $e) {
            $this->logError('Microsoft session initialization failed', [
                'user_id' => (int) ($user['id'] ?? 0),
                'error' => $e->getMessage(),
            ]);
            $this->clearLoginSession();
            return false;
        }
    }

    private function clearLoginSession(): void {
        unset(
            $_SESSION['user_id'], $_SESSION['name'], $_SESSION['role'], $_SESSION['active_role'],
            $_SESSION['primary_role'], $_SESSION['available_roles'], $_SESSION['role_selection_required'],
            $_SESSION['is_supervisor'], $_SESSION['class_id'], $_SESSION['student_stage'],
            $_SESSION['microsoft_login'], $_SESSION['microsoft_id'], $_SESSION['microsoft_email']
        );
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
    
    /**
     * الحصول على رابط تسجيل الخروج
     * Get logout URL
     * 
     * @param string $postLogoutRedirectUri Where to redirect after logout
     * @return string Logout URL
     */
    public function getLogoutUrl($postLogoutRedirectUri = null) {
        $params = [];
        
        if ($postLogoutRedirectUri) {
            $params['post_logout_redirect_uri'] = $postLogoutRedirectUri;
        }
        
        $url = AZURE_LOGOUT_ENDPOINT;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        return $url;
    }
    
    /**
     * عمل طلب POST
     * Make POST request
     * 
     * @param string $url URL
     * @param array $params POST parameters
     * @return array|false Response or false
     */
    private function makePostRequest($url, $params) {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            $this->logError('CURL error: ' . $error);
            return false;
        }
        
        $decoded = json_decode($response, true);
        
        if ($httpCode >= 400) {
            $this->logError('HTTP error ' . $httpCode, $decoded);
            return false;
        }
        
        return $decoded;
    }
    
    /**
     * عمل طلب GET
     * Make GET request
     * 
     * @param string $url URL
     * @param array $headers Headers
     * @return array|false Response or false
     */
    private function makeGetRequest($url, $headers = []) {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            $this->logError('CURL error: ' . $error);
            return false;
        }
        
        $decoded = json_decode($response, true);
        
        if ($httpCode >= 400) {
            $this->logError('HTTP error ' . $httpCode, $decoded);
            return false;
        }
        
        return $decoded;
    }
    
    /**
     * فك تشفير Base64URL
     * Decode Base64URL
     * 
     * @param string $data Base64URL encoded string
     * @return string Decoded string
     */
    private function base64UrlDecode($data) {
        $data = strtr($data, '-_', '+/');
        $padding = strlen($data) % 4;
        if ($padding) {
            $data .= str_repeat('=', 4 - $padding);
        }
        return base64_decode($data);
    }
    
    /**
     * تسجيل الأخطاء
     * Log errors
     * 
     * @param string $message Error message
     * @param mixed $data Additional data
     */
    private function logError($message, $data = null) {
        error_log('[Microsoft SSO Error] ' . $message);
        if (defined('SSO_DEBUG_MODE') && SSO_DEBUG_MODE && $data) {
            error_log('[Microsoft SSO Error Data] ' . json_encode($data));
        }
    }
    
    /**
     * تسجيل الإجراءات
     * Log actions
     * 
     * @param string $action Action name
     * @param string $description Description
     * @param int $userId User ID
     */
    private function logAction($action, $description, $userId = null) {
        if (!$this->db) {
            return;
        }

        (new \EduCore\Modules\Operations\Audit\AuditService($this->db))->recordEvent(
            (string) $action,
            'authentication',
            $userId !== null ? (int) $userId : null,
            'Microsoft SSO',
            ['description' => (string) $description],
            [
                'actor_id' => $userId !== null ? (int) $userId : null,
                'actor_role' => (string) ($_SESSION['role'] ?? 'unknown'),
                'actor_name' => (string) ($_SESSION['name'] ?? 'Microsoft SSO user'),
            ]
        );
    }
    
    /**
     * الحصول على لوحة التحكم المناسبة للمستخدم
     * Get dashboard URL based on role
     * 
     * @param string $role User role
     * @return string Dashboard URL
     */
    public function getDashboardUrl($role) {
        if (!empty($_SESSION['role_selection_required'])) {
            return '/select_role.php';
        }
        $activeRole = (string)($_SESSION['active_role'] ?? $role ?? '');
        return '/' . ltrim(Utilities::getDashboardUrl($activeRole), '/');
    }
}
