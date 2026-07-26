class AuthTokens {
  const AuthTokens({
    required this.accessToken,
    required this.refreshToken,
    required this.expiresAt,
    required this.userId,
  });

  final String accessToken;
  final String refreshToken;
  final DateTime expiresAt;
  final int userId;

  Map<String, dynamic> toJson() => {
    'access_token': accessToken,
    'refresh_token': refreshToken,
    'expires_at': expiresAt.toIso8601String(),
    'user_id': userId,
  };

  factory AuthTokens.fromJson(Map<String, dynamic> json) => AuthTokens(
    accessToken: json['access_token'] as String,
    refreshToken: json['refresh_token'] as String,
    expiresAt: DateTime.parse(json['expires_at'] as String),
    userId: (json['user_id'] as num).toInt(),
  );
}

class CurrentUser {
  const CurrentUser({
    required this.id,
    required this.email,
    required this.fullName,
    required this.roleId,
    required this.employeeId,
    required this.companyId,
    required this.avatarUrl,
    required this.isSuperAdmin,
  });

  final int id;
  final String email;
  final String fullName;
  final int roleId;
  final int? employeeId;
  final int? companyId;
  final String? avatarUrl;
  final bool isSuperAdmin;

  factory CurrentUser.fromJson(Map<String, dynamic> json) => CurrentUser(
    id: (json['id'] as num?)?.toInt() ?? 0,
    email: json['email'] as String? ?? '',
    fullName: json['full_name'] as String? ?? '',
    roleId: (json['role_id'] as num?)?.toInt() ?? 0,
    employeeId: (json['employee_id'] as num?)?.toInt(),
    companyId: (json['company_id'] as num?)?.toInt(),
    avatarUrl: json['avatar_url'] as String?,
    isSuperAdmin:
        json['is_super_admin'] == true ||
        (json['role_id'] as num?)?.toInt() == 1,
  );

  Map<String, dynamic> toJson() => {
    'id': id,
    'email': email,
    'full_name': fullName,
    'role_id': roleId,
    'employee_id': employeeId,
    'company_id': companyId,
    'avatar_url': avatarUrl,
    'is_super_admin': isSuperAdmin,
  };
}

sealed class LoginResponse {
  const LoginResponse();
}

class LoginSuccess extends LoginResponse {
  const LoginSuccess(this.user, this.tokens);
  final CurrentUser user;
  final AuthTokens tokens;
}

class TwoFactorRequired extends LoginResponse {
  const TwoFactorRequired(this.challengeToken);
  final String challengeToken;
}
