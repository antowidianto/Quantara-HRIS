import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../network/api_client.dart';
import 'auth_models.dart';

final authRepositoryProvider = Provider<AuthRepository>(
  (ref) => AuthRepository(ref.read(apiClientProvider)),
);

class AuthRepository {
  const AuthRepository(this._api);
  final ApiClient _api;

  Future<LoginResponse> login(String identifier, String password) async {
    final data = await _api.post<Map<String, dynamic>>(
      '/auth/login',
      data: {'identifier': identifier, 'password': password},
      skipAuth: true,
    );
    if (data['two_factor'] == true && data['challenge_token'] is String) {
      return TwoFactorRequired(data['challenge_token'] as String);
    }
    return _success(data);
  }

  Future<LoginSuccess> verifyTwoFactor(
    String challengeToken,
    String code,
  ) async {
    final data = await _api.post<Map<String, dynamic>>(
      '/auth/verify-2fa',
      data: {'challenge_token': challengeToken, 'code': code},
      skipAuth: true,
    );
    return _success(data);
  }

  Future<CurrentUser> me() async {
    final data = await _api.get<Map<String, dynamic>>('/auth/me');
    return CurrentUser.fromJson(data);
  }

  Future<void> logout() async {
    try {
      await _api.post<dynamic>('/auth/logout');
    } on Object {
      // Best effort: local credentials are always cleared by the controller.
    }
  }

  LoginSuccess _success(Map<String, dynamic> data) {
    final rawUser = data['user'];
    if (rawUser is! Map<String, dynamic>) {
      throw const ApiFailure(
        message: 'Respons login tidak memuat user.',
        code: 'INVALID_LOGIN_RESPONSE',
        statusCode: 500,
      );
    }
    final user = CurrentUser.fromJson(rawUser);
    final accessToken = data['access_token'] as String?;
    final refreshToken = data['refresh_token'] as String?;
    if (accessToken == null || refreshToken == null) {
      throw const ApiFailure(
        message: 'Respons login tidak memuat token.',
        code: 'INVALID_LOGIN_RESPONSE',
        statusCode: 500,
      );
    }
    return LoginSuccess(
      user,
      AuthTokens(
        accessToken: accessToken,
        refreshToken: refreshToken,
        expiresAt: DateTime.now().add(
          Duration(seconds: (data['expires_in'] as num?)?.toInt() ?? 900),
        ),
        userId: user.id,
      ),
    );
  }
}
