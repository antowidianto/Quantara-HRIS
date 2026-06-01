<?php

namespace App\Controllers\Api\V1\System;

use App\Controllers\Api\V1\ApiController;
/**
 * OpenApiController — Mobile Prep / MOB-5
 *
 * Generates a minimal OpenAPI 3.0 spec covering endpoints relevant to the
 * mobile app (auth, devices, attendance, leave, payslip, profile,
 * notifications, approvals). The spec is hand-curated rather than reflected
 * from controllers because:
 *
 *   1. Controllers in this codebase mix REST + RPC-style action endpoints
 *      (`/leaves/{id}/approve`) — auto-discovery would mis-shape them.
 *   2. We want the mobile-facing surface kept narrow & stable; reflecting
 *      every controller would expose admin-only endpoints that mobile
 *      shouldn't call.
 *   3. Hand-curation lets us add `x-mobile-priority` extension tags so
 *      codegen tools (openapi-generator) can split MVP vs Tier-2 clients.
 *
 * Public route: GET /api/v1/openapi.json (no auth — spec itself is non-secret;
 * actual endpoints still require JWT).
 *
 * Use with openapi-generator-cli to produce a TypeScript Axios client for
 * the mobile app:
 *
 *   curl http://localhost:8080/api/v1/openapi.json -o openapi.json
 *   openapi-generator-cli generate -i openapi.json -g typescript-axios \
 *       -o mobile/src/api-client
 */
class OpenApiController extends ApiController
{
    public function spec()
    {
        $baseUrl = (string) (env('app.baseURL') ?: 'http://localhost:8080');

        $spec = [
            'openapi' => '3.0.3',
            'info'    => [
                'title'       => 'HRIS Mobile API',
                'version'     => '1.0.0',
                'description' => 'Mobile-facing endpoint surface. Lihat docs/MOBILE_APP_PLAN.md.',
                'contact'     => ['name' => 'HRIS Engineering'],
            ],
            'servers' => [
                ['url' => rtrim($baseUrl, '/') . '/api/v1', 'description' => 'API v1'],
            ],
            'security' => [
                ['bearerAuth' => []],
            ],
            'tags' => [
                ['name' => 'Auth',          'description' => 'Login, refresh, 2FA, sessions'],
                ['name' => 'Devices',       'description' => 'Mobile push token registration'],
                ['name' => 'Attendance',    'description' => 'Clock-in/out, riwayat presensi'],
                ['name' => 'Leave',         'description' => 'Cuti: saldo, ajukan, riwayat'],
                ['name' => 'Payslip',       'description' => 'Slip gaji per periode'],
                ['name' => 'Profile',       'description' => 'Profil karyawan + settings'],
                ['name' => 'Notifications', 'description' => 'In-app notification list'],
                ['name' => 'Approvals',     'description' => 'Inbox pending approval untuk manager'],
            ],
            'paths' => $this->buildPaths(),
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type'         => 'http',
                        'scheme'       => 'bearer',
                        'bearerFormat' => 'JWT',
                    ],
                ],
                'schemas' => $this->buildSchemas(),
                'parameters' => [
                    'IdempotencyKey' => [
                        'name'        => 'Idempotency-Key',
                        'in'          => 'header',
                        'required'    => false,
                        'description' => 'UUID v4 untuk anti-duplikat. Wajib untuk mobile saat retry POST critical.',
                        'schema'      => ['type' => 'string', 'minLength' => 8, 'maxLength' => 100],
                    ],
                    'CompanyHeader' => [
                        'name'        => 'X-Company-Id',
                        'in'          => 'header',
                        'required'    => false,
                        'description' => 'Override company context (super admin only).',
                        'schema'      => ['type' => 'integer'],
                    ],
                    'FieldsParam' => [
                        'name'        => 'fields',
                        'in'          => 'query',
                        'required'    => false,
                        'description' => 'Comma-separated whitelist field untuk slim response.',
                        'schema'      => ['type' => 'string'],
                    ],
                ],
            ],
        ];

        return $this->response
            ->setHeader('Content-Type', 'application/json')
            ->setHeader('Cache-Control', 'public, max-age=300')
            ->setBody(json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function buildPaths(): array
    {
        return [
            // ─── Auth ───────────────────────────────────────────────
            '/auth/login' => [
                'post' => [
                    'tags' => ['Auth'],
                    'summary' => 'Login dengan email/NIK + password',
                    'security' => [],
                    'requestBody' => $this->jsonBody('LoginRequest'),
                    'responses' => [
                        '200' => $this->jsonResp('LoginResponse'),
                        '401' => $this->errResp('Invalid credentials'),
                        '423' => $this->errResp('Account locked'),
                    ],
                    'x-mobile-priority' => 'tier1',
                ],
            ],
            '/auth/verify-2fa' => [
                'post' => [
                    'tags' => ['Auth'],
                    'summary' => 'Verifikasi 2FA TOTP atau backup code',
                    'security' => [],
                    'requestBody' => $this->jsonBody('TwoFactorVerifyRequest'),
                    'responses' => ['200' => $this->jsonResp('LoginResponse')],
                    'x-mobile-priority' => 'tier1',
                ],
            ],
            '/auth/refresh' => [
                'post' => [
                    'tags' => ['Auth'],
                    'summary' => 'Refresh access token',
                    'security' => [],
                    'requestBody' => $this->jsonBody('RefreshRequest'),
                    'responses' => ['200' => $this->jsonResp('LoginResponse')],
                    'x-mobile-priority' => 'tier1',
                ],
            ],
            '/auth/logout' => [
                'post' => [
                    'tags' => ['Auth'],
                    'summary' => 'Logout (revoke refresh token of current device)',
                    'responses' => ['200' => $this->okResp()],
                    'x-mobile-priority' => 'tier1',
                ],
            ],
            '/auth/me' => [
                'get' => [
                    'tags' => ['Auth'],
                    'summary' => 'Current user info',
                    'responses' => ['200' => $this->jsonResp('User')],
                    'x-mobile-priority' => 'tier1',
                ],
            ],

            // ─── Devices ────────────────────────────────────────────
            '/devices/register' => [
                'post' => [
                    'tags' => ['Devices'],
                    'summary' => 'Register FCM/APNs token',
                    'requestBody' => $this->jsonBody('DeviceRegisterRequest'),
                    'responses' => ['201' => $this->okResp()],
                    'x-mobile-priority' => 'tier1',
                ],
            ],
            '/devices' => [
                'get' => [
                    'tags' => ['Devices'],
                    'summary' => 'List active devices for current user',
                    'responses' => ['200' => $this->jsonResp('DeviceList')],
                    'x-mobile-priority' => 'tier1',
                ],
            ],
            '/devices/{id}' => [
                'delete' => [
                    'tags' => ['Devices'],
                    'summary' => 'Unregister single device',
                    'parameters' => [['name'=>'id','in'=>'path','required'=>true,'schema'=>['type'=>'integer']]],
                    'responses' => ['200' => $this->okResp()],
                    'x-mobile-priority' => 'tier1',
                ],
            ],

            // ─── Attendance ─────────────────────────────────────────
            '/attendance/clock-in' => [
                'post' => [
                    'tags' => ['Attendance'],
                    'summary' => 'Clock-in dengan GPS + selfie (multipart)',
                    'parameters' => [['$ref' => '#/components/parameters/IdempotencyKey']],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'multipart/form-data' => [
                                'schema' => ['$ref' => '#/components/schemas/ClockInForm'],
                            ],
                        ],
                    ],
                    'responses' => ['201' => $this->jsonResp('AttendanceRecord')],
                    'x-mobile-priority' => 'tier1',
                ],
            ],
            '/attendance/clock-out' => [
                'post' => [
                    'tags' => ['Attendance'],
                    'summary' => 'Clock-out dengan GPS + selfie',
                    'parameters' => [['$ref' => '#/components/parameters/IdempotencyKey']],
                    'requestBody' => [
                        'required' => true,
                        'content' => ['multipart/form-data' => ['schema' => ['$ref' => '#/components/schemas/ClockInForm']]],
                    ],
                    'responses' => ['200' => $this->jsonResp('AttendanceRecord')],
                    'x-mobile-priority' => 'tier1',
                ],
            ],
            '/attendance/my' => [
                'get' => [
                    'tags' => ['Attendance'],
                    'summary' => 'Riwayat presensi user (paginated)',
                    'parameters' => [
                        ['name'=>'from','in'=>'query','schema'=>['type'=>'string','format'=>'date']],
                        ['name'=>'to','in'=>'query','schema'=>['type'=>'string','format'=>'date']],
                        ['$ref' => '#/components/parameters/FieldsParam'],
                    ],
                    'responses' => ['200' => $this->jsonResp('AttendanceList')],
                    'x-mobile-priority' => 'tier1',
                ],
            ],

            // ─── Leave ──────────────────────────────────────────────
            '/leaves' => [
                'get' => [
                    'tags' => ['Leave'],
                    'summary' => 'Daftar pengajuan cuti user',
                    'responses' => ['200' => $this->jsonResp('LeaveList')],
                    'x-mobile-priority' => 'tier1',
                ],
                'post' => [
                    'tags' => ['Leave'],
                    'summary' => 'Ajukan cuti baru',
                    'parameters' => [['$ref' => '#/components/parameters/IdempotencyKey']],
                    'requestBody' => $this->jsonBody('LeaveCreateRequest'),
                    'responses' => ['201' => $this->jsonResp('LeaveRecord')],
                    'x-mobile-priority' => 'tier1',
                ],
            ],
            '/leaves/{id}/cancel' => [
                'post' => [
                    'tags' => ['Leave'],
                    'summary' => 'Cancel pengajuan cuti pending/approved',
                    'parameters' => [['name'=>'id','in'=>'path','required'=>true,'schema'=>['type'=>'integer']]],
                    'responses' => ['200' => $this->jsonResp('LeaveRecord')],
                    'x-mobile-priority' => 'tier1',
                ],
            ],
            '/leave-balances/{employeeId}' => [
                'get' => [
                    'tags' => ['Leave'],
                    'summary' => 'Saldo cuti per leave_type',
                    'parameters' => [['name'=>'employeeId','in'=>'path','required'=>true,'schema'=>['type'=>'integer']]],
                    'responses' => ['200' => $this->jsonResp('LeaveBalanceList')],
                    'x-mobile-priority' => 'tier1',
                ],
            ],

            // ─── Payslip ────────────────────────────────────────────
            '/payslips/my' => [
                'get' => [
                    'tags' => ['Payslip'],
                    'summary' => 'Daftar payslip user',
                    'responses' => ['200' => $this->jsonResp('PayslipList')],
                    'x-mobile-priority' => 'tier1',
                ],
            ],
            '/payslips/{id}' => [
                'get' => [
                    'tags' => ['Payslip'],
                    'summary' => 'Detail payslip',
                    'parameters' => [['name'=>'id','in'=>'path','required'=>true,'schema'=>['type'=>'integer']]],
                    'responses' => ['200' => $this->jsonResp('PayslipDetail')],
                    'x-mobile-priority' => 'tier1',
                ],
            ],
            '/payslips/{id}/pdf' => [
                'get' => [
                    'tags' => ['Payslip'],
                    'summary' => 'Download payslip sebagai PDF',
                    'parameters' => [['name'=>'id','in'=>'path','required'=>true,'schema'=>['type'=>'integer']]],
                    'responses' => [
                        '200' => [
                            'description' => 'PDF binary',
                            'content' => ['application/pdf' => ['schema' => ['type' => 'string', 'format' => 'binary']]],
                        ],
                    ],
                    'x-mobile-priority' => 'tier1',
                ],
            ],

            // ─── Notifications ──────────────────────────────────────
            '/notifications' => [
                'get' => [
                    'tags' => ['Notifications'],
                    'summary' => 'In-app notification list',
                    'responses' => ['200' => $this->jsonResp('NotificationList')],
                    'x-mobile-priority' => 'tier1',
                ],
            ],
            '/notifications/unread-count' => [
                'get' => [
                    'tags' => ['Notifications'],
                    'summary' => 'Badge counter — jumlah unread',
                    'responses' => ['200' => $this->jsonResp('UnreadCount')],
                    'x-mobile-priority' => 'tier1',
                ],
            ],

            // ─── Approvals ──────────────────────────────────────────
            '/approval-requests/inbox' => [
                'get' => [
                    'tags' => ['Approvals'],
                    'summary' => 'Inbox pending approval untuk approver',
                    'responses' => ['200' => $this->jsonResp('ApprovalInbox')],
                    'x-mobile-priority' => 'tier1',
                ],
            ],
            '/approval-requests/{id}/approve' => [
                'post' => [
                    'tags' => ['Approvals'],
                    'summary' => 'Approve request',
                    'parameters' => [
                        ['name'=>'id','in'=>'path','required'=>true,'schema'=>['type'=>'integer']],
                        ['$ref' => '#/components/parameters/IdempotencyKey'],
                    ],
                    'requestBody' => $this->jsonBody('ApprovalDecisionRequest'),
                    'responses' => ['200' => $this->okResp()],
                    'x-mobile-priority' => 'tier1',
                ],
            ],
            '/approval-requests/{id}/reject' => [
                'post' => [
                    'tags' => ['Approvals'],
                    'summary' => 'Reject request',
                    'parameters' => [
                        ['name'=>'id','in'=>'path','required'=>true,'schema'=>['type'=>'integer']],
                        ['$ref' => '#/components/parameters/IdempotencyKey'],
                    ],
                    'requestBody' => $this->jsonBody('ApprovalDecisionRequest'),
                    'responses' => ['200' => $this->okResp()],
                    'x-mobile-priority' => 'tier1',
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildSchemas(): array
    {
        return [
            // ─── Common ─────────────────────────────────────────────
            'ApiResponse' => [
                'type' => 'object',
                'properties' => [
                    'status'  => ['type' => 'string', 'enum' => ['success', 'error']],
                    'message' => ['type' => 'string'],
                    'data'    => ['type' => 'object', 'nullable' => true],
                ],
            ],
            'ApiError' => [
                'type' => 'object',
                'properties' => [
                    'status'  => ['type' => 'string', 'example' => 'error'],
                    'code'    => ['type' => 'string'],
                    'message' => ['type' => 'string'],
                    'errors'  => ['type' => 'object', 'nullable' => true],
                ],
            ],

            // ─── Auth ───────────────────────────────────────────────
            'LoginRequest' => [
                'type' => 'object',
                'required' => ['identifier', 'password'],
                'properties' => [
                    'identifier' => ['type' => 'string', 'description' => 'Email atau NIK'],
                    'password'   => ['type' => 'string', 'format' => 'password'],
                ],
            ],
            'TwoFactorVerifyRequest' => [
                'type' => 'object',
                'required' => ['challenge_token', 'code'],
                'properties' => [
                    'challenge_token' => ['type' => 'string'],
                    'code'            => ['type' => 'string', 'description' => '6-digit TOTP atau backup code'],
                ],
            ],
            'RefreshRequest' => [
                'type' => 'object',
                'required' => ['refresh_token'],
                'properties' => ['refresh_token' => ['type' => 'string']],
            ],
            'LoginResponse' => [
                'type' => 'object',
                'properties' => [
                    'status'   => ['type' => 'string'],
                    'data'     => [
                        'type' => 'object',
                        'properties' => [
                            'access_token'  => ['type' => 'string'],
                            'refresh_token' => ['type' => 'string'],
                            'expires_in'    => ['type' => 'integer'],
                            'two_factor'    => ['type' => 'boolean'],
                            'user'          => ['$ref' => '#/components/schemas/User'],
                        ],
                    ],
                ],
            ],
            'User' => [
                'type' => 'object',
                'properties' => [
                    'id'           => ['type' => 'integer'],
                    'email'        => ['type' => 'string', 'format' => 'email'],
                    'role_id'      => ['type' => 'integer'],
                    'employee_id'  => ['type' => 'integer', 'nullable' => true],
                    'company_id'   => ['type' => 'integer', 'nullable' => true],
                    'full_name'    => ['type' => 'string'],
                    'avatar_url'   => ['type' => 'string', 'nullable' => true],
                ],
            ],

            // ─── Devices ────────────────────────────────────────────
            'DeviceRegisterRequest' => [
                'type' => 'object',
                'required' => ['platform', 'token'],
                'properties' => [
                    'platform'     => ['type' => 'string', 'enum' => ['ANDROID', 'IOS', 'WEB']],
                    'token'        => ['type' => 'string'],
                    'device_id'    => ['type' => 'string', 'nullable' => true],
                    'device_model' => ['type' => 'string', 'nullable' => true],
                    'os_version'   => ['type' => 'string', 'nullable' => true],
                    'app_version'  => ['type' => 'string', 'nullable' => true],
                    'locale'       => ['type' => 'string', 'nullable' => true],
                    'timezone'     => ['type' => 'string', 'nullable' => true],
                ],
            ],
            'DeviceList' => [
                'type' => 'object',
                'properties' => [
                    'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Device']],
                ],
            ],
            'Device' => [
                'type' => 'object',
                'properties' => [
                    'id'           => ['type' => 'integer'],
                    'platform'     => ['type' => 'string'],
                    'device_model' => ['type' => 'string', 'nullable' => true],
                    'os_version'   => ['type' => 'string', 'nullable' => true],
                    'app_version'  => ['type' => 'string', 'nullable' => true],
                    'last_seen_at' => ['type' => 'string', 'format' => 'date-time'],
                    'is_active'    => ['type' => 'boolean'],
                ],
            ],

            // ─── Attendance ─────────────────────────────────────────
            'ClockInForm' => [
                'type' => 'object',
                'required' => ['latitude', 'longitude', 'selfie'],
                'properties' => [
                    'latitude'        => ['type' => 'number', 'format' => 'double'],
                    'longitude'       => ['type' => 'number', 'format' => 'double'],
                    'accuracy_meters' => ['type' => 'number'],
                    'selfie'          => ['type' => 'string', 'format' => 'binary'],
                    'work_location_id'=> ['type' => 'integer', 'nullable' => true],
                    'note'            => ['type' => 'string', 'nullable' => true],
                    'client_at'       => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'AttendanceRecord' => [
                'type' => 'object',
                'properties' => [
                    'id'         => ['type' => 'integer'],
                    'work_date'  => ['type' => 'string', 'format' => 'date'],
                    'clock_in_at'=> ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'clock_out_at'=>['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'status'     => ['type' => 'string'],
                ],
            ],
            'AttendanceList' => [
                'type' => 'object',
                'properties' => [
                    'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/AttendanceRecord']],
                    'meta' => ['$ref' => '#/components/schemas/PaginationMeta'],
                ],
            ],

            // ─── Leave ──────────────────────────────────────────────
            'LeaveCreateRequest' => [
                'type' => 'object',
                'required' => ['leave_type_id', 'start_date', 'end_date', 'reason'],
                'properties' => [
                    'leave_type_id' => ['type' => 'integer'],
                    'start_date'    => ['type' => 'string', 'format' => 'date'],
                    'end_date'      => ['type' => 'string', 'format' => 'date'],
                    'reason'        => ['type' => 'string'],
                    'is_half_day'   => ['type' => 'boolean'],
                    'half_day_part' => ['type' => 'string', 'enum' => ['MORNING', 'AFTERNOON'], 'nullable' => true],
                    'attachment_path' => ['type' => 'string', 'nullable' => true],
                ],
            ],
            'LeaveRecord' => [
                'type' => 'object',
                'properties' => [
                    'id'         => ['type' => 'integer'],
                    'leave_type' => ['type' => 'string'],
                    'start_date' => ['type' => 'string', 'format' => 'date'],
                    'end_date'   => ['type' => 'string', 'format' => 'date'],
                    'days'       => ['type' => 'number'],
                    'status'     => ['type' => 'string'],
                    'reason'     => ['type' => 'string'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'LeaveList' => [
                'type' => 'object',
                'properties' => [
                    'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/LeaveRecord']],
                    'meta' => ['$ref' => '#/components/schemas/PaginationMeta'],
                ],
            ],
            'LeaveBalance' => [
                'type' => 'object',
                'properties' => [
                    'leave_type_id' => ['type' => 'integer'],
                    'leave_type'    => ['type' => 'string'],
                    'quota'         => ['type' => 'number'],
                    'used'          => ['type' => 'number'],
                    'pending'       => ['type' => 'number'],
                    'available'     => ['type' => 'number'],
                ],
            ],
            'LeaveBalanceList' => [
                'type' => 'object',
                'properties' => [
                    'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/LeaveBalance']],
                ],
            ],

            // ─── Payslip ────────────────────────────────────────────
            'PayslipSummary' => [
                'type' => 'object',
                'properties' => [
                    'id'             => ['type' => 'integer'],
                    'period_label'   => ['type' => 'string'],
                    'period_year'    => ['type' => 'integer'],
                    'period_month'   => ['type' => 'integer'],
                    'gross_amount'   => ['type' => 'number'],
                    'net_amount'     => ['type' => 'number'],
                    'published_at'   => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'PayslipList' => [
                'type' => 'object',
                'properties' => [
                    'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/PayslipSummary']],
                ],
            ],
            'PayslipDetail' => [
                'allOf' => [
                    ['$ref' => '#/components/schemas/PayslipSummary'],
                    [
                        'type' => 'object',
                        'properties' => [
                            'earnings'   => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/PayslipLine']],
                            'deductions' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/PayslipLine']],
                        ],
                    ],
                ],
            ],
            'PayslipLine' => [
                'type' => 'object',
                'properties' => [
                    'code'   => ['type' => 'string'],
                    'name'   => ['type' => 'string'],
                    'amount' => ['type' => 'number'],
                ],
            ],

            // ─── Notifications ──────────────────────────────────────
            'Notification' => [
                'type' => 'object',
                'properties' => [
                    'id'        => ['type' => 'integer'],
                    'category'  => ['type' => 'string'],
                    'title'     => ['type' => 'string'],
                    'body'      => ['type' => 'string'],
                    'is_read'   => ['type' => 'boolean'],
                    'created_at'=> ['type' => 'string', 'format' => 'date-time'],
                    'data'      => ['type' => 'object', 'additionalProperties' => true],
                ],
            ],
            'NotificationList' => [
                'type' => 'object',
                'properties' => [
                    'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Notification']],
                    'meta' => ['$ref' => '#/components/schemas/PaginationMeta'],
                ],
            ],
            'UnreadCount' => [
                'type' => 'object',
                'properties' => ['data' => ['type' => 'object', 'properties' => ['count' => ['type' => 'integer']]]],
            ],

            // ─── Approvals ──────────────────────────────────────────
            'ApprovalInbox' => [
                'type' => 'object',
                'properties' => [
                    'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ApprovalRequestSummary']],
                ],
            ],
            'ApprovalRequestSummary' => [
                'type' => 'object',
                'properties' => [
                    'id'         => ['type' => 'integer'],
                    'module'     => ['type' => 'string', 'description' => 'leave|overtime|reimbursement|...'],
                    'subject'    => ['type' => 'string'],
                    'requester'  => ['type' => 'string'],
                    'requested_at'=>['type' => 'string', 'format' => 'date-time'],
                    'detail_url' => ['type' => 'string'],
                ],
            ],
            'ApprovalDecisionRequest' => [
                'type' => 'object',
                'properties' => [
                    'note' => ['type' => 'string', 'nullable' => true],
                ],
            ],

            // ─── Common ─────────────────────────────────────────────
            'PaginationMeta' => [
                'type' => 'object',
                'properties' => [
                    'total'    => ['type' => 'integer'],
                    'page'     => ['type' => 'integer'],
                    'per_page' => ['type' => 'integer'],
                    'pages'    => ['type' => 'integer'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(string $schemaRef): array
    {
        return [
            'required' => true,
            'content'  => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/' . $schemaRef],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonResp(string $schemaRef): array
    {
        return [
            'description' => 'OK',
            'content'     => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/' . $schemaRef],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function okResp(): array
    {
        return [
            'description' => 'OK',
            'content'     => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/ApiResponse'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function errResp(string $description): array
    {
        return [
            'description' => $description,
            'content'     => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/ApiError'],
                ],
            ],
        ];
    }
}